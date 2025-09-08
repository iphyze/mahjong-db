<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    // --- Auth check ---
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole = $userData['role'];

    if ($loggedInUserRole !== "Admin" && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Unauthorized Access", 403);
    }

    // --- Input check ---
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data || empty($data['gameId']) || empty($data['pairingType'])) {
        throw new Exception("gameId and pairingType required", 400);
    }

    $gameId = intval($data['gameId']);
    $pairingType = $data['pairingType'];

    // --- Fetch interested users ---
    $stmt = $conn->prepare("SELECT gi.user_id, u.firstName, u.lastName, u.skillLevel 
                            FROM game_interests gi 
                            JOIN users u ON gi.user_id = u.id 
                            WHERE gi.game_id = ? AND gi.interest = 'yes'");
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    $players = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($players) < 4) {
        throw new Exception("Not enough players to form a group.", 400);
    }

    // --- Split by skill & shuffle ---
    $beginners     = array_values(array_filter($players, fn($p) => $p['skillLevel'] === "Beginner"));
    $intermediates = array_values(array_filter($players, fn($p) => $p['skillLevel'] === "Intermediate"));
    $advanced      = array_values(array_filter($players, fn($p) => $p['skillLevel'] === "Advanced"));

    shuffle($beginners);
    shuffle($intermediates);
    shuffle($advanced);

    // Helpers
    $skillOf = fn($p) => $p['skillLevel'];

    $pickMaxAvailable = function(array &$pools, array $exclude = []) {
        // pools: ['Beginner'=>[...], 'Intermediate'=>[...], 'Advanced'=>[...]]
        $order = ['Advanced','Intermediate','Beginner']; // mild bias to spread stronger players when mixing
        // randomize tie-break slightly
        shuffle($order);
        // try excluding skills first
        $bestSkill = null;
        $bestCount = -1;
        foreach (['Advanced','Intermediate','Beginner'] as $skill) {
            if (in_array($skill, $exclude, true)) continue;
            $cnt = isset($pools[$skill]) ? count($pools[$skill]) : 0;
            if ($cnt > $bestCount) {
                $bestCount = $cnt;
                $bestSkill = $skill;
            }
        }
        if ($bestCount > 0) return $bestSkill;

        // fallback: ignore exclusion if nothing available outside exclude
        $bestSkill = null;
        $bestCount = -1;
        foreach (['Advanced','Intermediate','Beginner'] as $skill) {
            $cnt = isset($pools[$skill]) ? count($pools[$skill]) : 0;
            if ($cnt > $bestCount) {
                $bestCount = $cnt;
                $bestSkill = $skill;
            }
        }
        return ($bestCount > 0) ? $bestSkill : null;
    };

    $groups = [];
    $preGrouped = false; // when true, skip the generic "chunk to 4 then distribute" step

    if ($pairingType === 'like') {
        // Simple: place by bands so like meets like
        $finalPlayers = array_merge($beginners, $intermediates, $advanced);

    } elseif ($pairingType === 'strategic') {
        // Ensure each group has at least 1 Intermediate (while they last), then mix A/B/I round-robin
        $total = count($players);
        $numGroups = max(1, min(count($intermediates), intdiv($total, 4)));
        if ($numGroups === 0) $numGroups = 1;

        // Seed with Intermediates
        for ($i = 0; $i < $numGroups; $i++) {
            $groups[$i] = [];
            if (!empty($intermediates)) {
                $groups[$i][] = array_shift($intermediates);
            }
        }

        // Distribute remaining players round-robin
        $remaining = array_merge($advanced, $beginners, $intermediates);
        $g = 0;
        while (!empty($remaining)) {
            $groups[$g][] = array_shift($remaining);
            $g = ($g + 1) % $numGroups;
        }

        $preGrouped = true;

    } elseif ($pairingType === 'different') {
        // Build groups of 4 maximizing diversity: avoid same-skill in the same group UNTIL unavoidable.
        $total = count($players);
        $numGroups = max(1, intdiv($total, 4)); // base groups of 4
        if ($numGroups === 0) $numGroups = 1;

        // Initialize groups
        for ($i = 0; $i < $numGroups; $i++) { $groups[$i] = []; }

        $pools = [
            'Beginner'     => $beginners,
            'Intermediate' => $intermediates,
            'Advanced'     => $advanced,
        ];

        // Fill each group up to 4 with distinct skills whenever possible
        $stillPlayers = (count($pools['Beginner']) + count($pools['Intermediate']) + count($pools['Advanced'])) > 0;

        while ($stillPlayers) {
            $stillPlayers = false;
            for ($i = 0; $i < $numGroups; $i++) {
                if (count($groups[$i]) >= 4) continue; // base cap 4; leftovers handled later

                $usedSkills = array_unique(array_map($skillOf, $groups[$i]));
                $skill = $pickMaxAvailable($pools, $usedSkills);
                if ($skill !== null && !empty($pools[$skill])) {
                    $groups[$i][] = array_shift($pools[$skill]);
                }
            }
            // check if any pool still has players and any group isn't yet at 4
            $remainingCount = count($pools['Beginner']) + count($pools['Intermediate']) + count($pools['Advanced']);
            if ($remainingCount > 0) {
                // see if any group has space
                foreach ($groups as $g) {
                    if (count($g) < 4) { $stillPlayers = true; break; }
                }
            }
        }

        // Gather leftovers (couldn't place due to all groups at 4)
        $leftovers = [];
        foreach (['Beginner','Intermediate','Advanced'] as $s) {
            while (!empty($pools[$s])) {
                $leftovers[] = array_shift($pools[$s]);
            }
        }

        // Distribute leftovers: prefer groups that do NOT already have that skill and have <5
        foreach ($leftovers as $p) {
            $skill = $skillOf($p);
            $placed = false;

            // try to place where skill not present and room <5
            for ($i = 0; $i < count($groups); $i++) {
                if (count($groups[$i]) >= 5) continue;
                $skillsInGroup = array_unique(array_map($skillOf, $groups[$i]));
                if (!in_array($skill, $skillsInGroup, true)) {
                    $groups[$i][] = $p;
                    $placed = true;
                    break;
                }
            }
            if ($placed) continue;

            // else any group with room <5
            for ($i = 0; $i < count($groups); $i++) {
                if (count($groups[$i]) < 5) {
                    $groups[$i][] = $p;
                    $placed = true;
                    break;
                }
            }

            // else start a new group (will be <5 since it's new)
            if (!$placed) {
                $groups[] = [$p];
            }
        }

        $preGrouped = true;

    } else {
        throw new Exception("Invalid pairingType. Use: like, different, or strategic.", 400);
    }

    // --- Shape groups according to global rule: groups of 4 normally, up to 5 max ---
    if (!$preGrouped) {
        $groups = [];

        // Step 1: Form groups of 4
        $finalPlayers = $finalPlayers ?? $players; // safety
        while (count($finalPlayers) >= 4) {
            $groups[] = array_splice($finalPlayers, 0, 4);
        }

        // Step 2: Distribute leftovers safely (max 5 per group)
        if (count($finalPlayers) > 0) {
            foreach ($finalPlayers as $leftover) {
                $placed = false;

                foreach ($groups as &$g) {
                    if (count($g) < 5) {
                        $g[] = $leftover;
                        $placed = true;
                        break;
                    }
                }

                if (!$placed) {
                    $groups[] = [$leftover];
                }
            }
        }
    }

    // --- Save groups (reshuffle allowed: clear old first) ---
    $conn->query("DELETE FROM game_pairs WHERE gameId = $gameId");

    $insert = $conn->prepare("INSERT INTO game_pairs (gameId, groupNumber, playerIds) VALUES (?, ?, ?)");
    foreach ($groups as $i => $group) {
        $playerIds = array_column($group, 'user_id');
        $jsonIds = json_encode($playerIds);
        $groupNumber = $i + 1;
        $insert->bind_param("iis", $gameId, $groupNumber, $jsonIds);
        $insert->execute();
    }
    $insert->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Players paired successfully",
        "pairingType" => $pairingType,
        "groups" => $groups
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

$conn->close();
