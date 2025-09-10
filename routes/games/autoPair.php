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
    $pairingType = strtolower($data['pairingType']); // like, different, strategic

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

    $totalPlayers = count($players);
    if ($totalPlayers < 4) {
        throw new Exception("Not enough players to form a group.", 400);
    }

    // --- Organize into skill pools & shuffle ---
    $beginners = array_values(array_filter($players, fn($p) => $p['skillLevel'] === "Beginner"));
    $intermediates = array_values(array_filter($players, fn($p) => $p['skillLevel'] === "Intermediate"));
    $advanced = array_values(array_filter($players, fn($p) => $p['skillLevel'] === "Advanced"));

    shuffle($beginners);
    shuffle($intermediates);
    shuffle($advanced);

    // helpers
    $skillOf = fn($p) => $p['skillLevel'];
    $total = $totalPlayers;

    // Determine the base number of groups (prefer groups of 4)
    $numGroups = intdiv($total, 4); // floor(total/4)
    if ($numGroups < 1) $numGroups = 1;

    // initialize groups
    $groups = []; // each group is array of player arrays

    // Helper: create groups of size 4 from a single pool (same-skill groups)
    $makeSameSkillGroups = function(array &$pool, array &$groups, $skillName) {
        while (count($pool) >= 4) {
            $groups[] = array_splice($pool, 0, 4);
        }
    };

    // Helper: push player into group if room and optional predicate (callable)
    $placeIntoGroups = function($player, array &$groups, callable $predicate = null) {
        // prefer groups where predicate returns true, then any with room
        for ($pass = 0; $pass < 2; $pass++) {
            for ($i = 0; $i < count($groups); $i++) {
                if (count($groups[$i]) >= 5) continue;
                if ($pass === 0 && $predicate !== null) {
                    if ($predicate($groups[$i]) === true) {
                        $groups[$i][] = $player;
                        return true;
                    }
                } elseif ($pass === 1) {
                    $groups[$i][] = $player;
                    return true;
                }
            }
        }
        return false;
    };

    // Helper: count skill in a group
    $groupSkills = function(array $group) use ($skillOf) {
        return array_values(array_unique(array_map($skillOf, $group)));
    };

    // Helper: create empty groups up to $numGroups
    $createEmptyGroups = function(int $n) use (&$groups) {
        for ($i = 0; $i < $n; $i++) {
            $groups[] = [];
        }
    };

    if ($pairingType === 'like') {
    // Step 1: Sort players into skill-based groups first
    $groups = [];
    
    // Helper to create groups of 4 from a pool
    $createGroups = function(&$pool) {
        $groups = [];
        while (count($pool) >= 4) {
            $groups[] = array_splice($pool, 0, 4);
        }
        return $groups;
    };
    
    // Create initial groups of 4 for each skill level
    $advancedGroups = $createGroups($advanced);
    $intermediateGroups = $createGroups($intermediates);
    $beginnerGroups = $createGroups($beginners);
    
    // Combine all complete groups
    $groups = array_merge($advancedGroups, $intermediateGroups, $beginnerGroups);
    
    // Step 2: Handle remaining players (less than 4 from each skill level)
    $remainingPlayers = array_merge($advanced, $intermediates, $beginners);
    
    if (!empty($remainingPlayers)) {
        // If we have 4 or more remaining players total, create new groups
        while (count($remainingPlayers) >= 4) {
            $groups[] = array_splice($remainingPlayers, 0, 4);
        }
        
        // For the final remaining players (less than 4)
        if (!empty($remainingPlayers)) {
            foreach ($remainingPlayers as $player) {
                $playerSkill = $player['skillLevel'];
                $placed = false;
                
                // First try: Place with same skill level group that has exactly 4 players
                foreach ($groups as &$group) {
                    if (count($group) === 4) {
                        $groupSkill = $group[0]['skillLevel'];
                        if ($groupSkill === $playerSkill) {
                            $group[] = $player;
                            $placed = true;
                            break;
                        }
                    }
                }
                
                // Second try: Place with any same skill level group that has less than 5 players
                if (!$placed) {
                    foreach ($groups as &$group) {
                        if (count($group) < 5) {
                            $groupSkill = $group[0]['skillLevel'];
                            if ($groupSkill === $playerSkill) {
                                $group[] = $player;
                                $placed = true;
                                break;
                            }
                        }
                    }
                }
                
                // Last resort: Place in any group with less than 5 players
                if (!$placed) {
                    foreach ($groups as &$group) {
                        if (count($group) < 5) {
                            $group[] = $player;
                            $placed = true;
                            break;
                        }
                    }
                }
                
                // If still not placed (shouldn't happen but just in case)
                if (!$placed) {
                    throw new Exception("Unable to place all players in groups", 500);
                }
            }
        }
    }
    
    // Verify all players are placed and no duplicates exist
    $placedPlayers = [];
    foreach ($groups as $group) {
        foreach ($group as $player) {
            $playerId = $player['user_id'];
            if (in_array($playerId, $placedPlayers)) {
                throw new Exception("Duplicate player detected in groups", 500);
            }
            $placedPlayers[] = $playerId;
        }
    }
    
    // Verify total count matches
    if (count($placedPlayers) !== $totalPlayers) {
        throw new Exception("Not all players were placed in groups", 500);
    }
}

 elseif ($pairingType === 'different') {
        // Aim: maximize diversity (A, I, B) per group of 4.
        // Create numGroups empty groups
        $createEmptyGroups($numGroups);

        $pools = [
            'Advanced' => $advanced,
            'Intermediate' => $intermediates,
            'Beginner' => $beginners
        ];

        // Step 1: try to fill groups to 4 with distinct skills when possible
        $madeProgress = true;
        while ($madeProgress) {
            $madeProgress = false;
            for ($i = 0; $i < count($groups); $i++) {
                if (count($groups[$i]) >= 4) continue;

                // determine what skills are missing in this group
                $skillsPresent = array_map($skillOf, $groups[$i]);
                $needed = ['Advanced','Intermediate','Beginner'];
                // pick a skill that is not present in this group and that has available players
                $selectedSkill = null;
                foreach ($needed as $s) {
                    if (!in_array($s, $skillsPresent, true) && !empty($pools[$s])) {
                        $selectedSkill = $s;
                        break;
                    }
                }
                // if no distinct skill available, pick the largest pool (to avoid starving)
                if ($selectedSkill === null) {
                    // choose pool with most players that has >0
                    $maxCount = -1;
                    foreach ($pools as $s => $pool) {
                        $cnt = count($pool);
                        if ($cnt > $maxCount && $cnt > 0) {
                            $maxCount = $cnt;
                            $selectedSkill = $s;
                        }
                    }
                }

                if ($selectedSkill !== null && !empty($pools[$selectedSkill])) {
                    $groups[$i][] = array_shift($pools[$selectedSkill]);
                    $madeProgress = true;
                }
            }
        }

        // Step 2: collect leftovers from pools
        $leftovers = [];
        foreach (['Advanced','Intermediate','Beginner'] as $s) {
            while (!empty($pools[$s])) {
                $leftovers[] = array_shift($pools[$s]);
            }
        }

        // Step 3: distribute leftovers preferring groups that don't have that skill and have room (<5)
        foreach ($leftovers as $p) {
            $skill = $skillOf($p);
            $placed = false;

            // Prefer groups that don't have that skill and have <5
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

            // Next, any group with room (<5)
            for ($i = 0; $i < count($groups); $i++) {
                if (count($groups[$i]) < 5) {
                    $groups[$i][] = $p;
                    $placed = true;
                    break;
                }
            }

            // If still not placed, create a new group (should be rare)
            if (!$placed) {
                $groups[] = [$p];
            }
        }

        // After distribution ensure no group < 4: if a group ended up <4 (possible if we created new group), try to merge
        for ($i = 0; $i < count($groups); $i++) {
            if (count($groups[$i]) > 0 && count($groups[$i]) < 4) {
                // move its members to other groups with room
                $members = $groups[$i];
                $groups[$i] = []; // empty
                foreach ($members as $m) {
                    $mPlaced = false;
                    // try to place into groups with room <5
                    for ($j = 0; $j < count($groups); $j++) {
                        if ($j === $i) continue;
                        if (count($groups[$j]) < 5) {
                            $groups[$j][] = $m;
                            $mPlaced = true;
                            break;
                        }
                    }
                    if (!$mPlaced) {
                        // as last resort create/restore group
                        $groups[$i][] = $m;
                    }
                }
            }
        }
        // remove any truly empty groups that may have been left behind
        $groups = array_values(array_filter($groups, fn($g) => count($g) > 0));

    } elseif ($pairingType === 'strategic') {
        // Strategic: ensure fairness -> each group should have at least one Intermediate (while they last)
        // and prefer pairing Intermediate with Advanced and Beginner with Intermediate.
        // Steps:
        // 1) Decide number of groups based on available Intermediates and total players.
        //    We want groups of 4 primarily. numGroups = floor(total/4).
        // 2) Seed each group with 1 Intermediate if available.
        // 3) Try to add 1 Advanced to groups, then 1 Beginner, to create a balanced mix.
        // 4) Fill remaining slots trying to keep the intermediate presence and balance.
        $createEmptyGroups($numGroups);

        // Seed groups with one Intermediate each where possible
        for ($i = 0; $i < count($groups); $i++) {
            if (!empty($intermediates)) {
                $groups[$i][] = array_shift($intermediates);
            }
        }

        // Next pass: try to add one Advanced to each group
        for ($i = 0; $i < count($groups); $i++) {
            if (count($groups[$i]) >= 4) continue;
            if (!empty($advanced)) {
                $groups[$i][] = array_shift($advanced);
            }
        }

        // Next pass: try to add one Beginner to each group
        for ($i = 0; $i < count($groups); $i++) {
            if (count($groups[$i]) >= 4) continue;
            if (!empty($beginners)) {
                $groups[$i][] = array_shift($beginners);
            }
        }

        // Now fill remaining slots up to 4 using any pools, preferring to keep at least one Intermediate
        $pools = array_merge($advanced, $beginners, $intermediates); // leftover players
        // continue filling groups to 4 round-robin
        $g = 0;
        while (!empty($pools)) {
            if (count($groups[$g]) < 4) {
                $groups[$g][] = array_shift($pools);
            }
            $g = ($g + 1) % count($groups);
            // if we've looped and all groups have >=4 then break
            $allHave4 = true;
            foreach ($groups as $gg) {
                if (count($gg) < 4) { $allHave4 = false; break; }
            }
            if ($allHave4) break;
        }

        // collect any leftovers
        $leftovers = $pools; // remaining players (if any)

        // Distribute leftovers to groups with room (<5), preferring groups that maintain the "strategic" balance:
        // prefer groups missing the player's best complementary skill (for fairness: Advanced -> group with Intermediate, Beginner -> group with Intermediate)
        foreach ($leftovers as $p) {
            $skill = $skillOf($p);
            $placed = false;

            // predicate: group has <5 and contains at least one Intermediate (priority to pair Advanced/Beginner with Intermediates)
            $placed = $placeIntoGroups($p, $groups, function($g) use ($skill, $skillOf) {
                if (count($g) >= 5) return false;
                $skills = array_map($skillOf, $g);
                // prefer groups that have an Intermediate (to pair Advanced/Beginner with Intermediate)
                return in_array("Intermediate", $skills, true);
            });

            // If still not placed, prefer groups that do not already have that skill (to balance)
            if (!$placed) {
                $placed = $placeIntoGroups($p, $groups, function($g) use ($skill, $skillOf) {
                    if (count($g) >= 5) return false;
                    $skills = array_map($skillOf, $g);
                    return !in_array($skill, $skills, true);
                });
            }

            // else any group with room
            if (!$placed) {
                $placed = $placeIntoGroups($p, $groups, null);
            }

            // else create a new group (rare)
            if (!$placed) {
                $groups[] = [$p];
            }
        }

        // final cleanup: ensure no group <4 by merging if needed
        for ($i = 0; $i < count($groups); $i++) {
            if (count($groups[$i]) > 0 && count($groups[$i]) < 4) {
                $members = $groups[$i];
                $groups[$i] = [];
                foreach ($members as $m) {
                    $mPlaced = false;
                    for ($j = 0; $j < count($groups); $j++) {
                        if ($j === $i) continue;
                        if (count($groups[$j]) < 5) {
                            $groups[$j][] = $m;
                            $mPlaced = true;
                            break;
                        }
                    }
                    if (!$mPlaced) {
                        $groups[$i][] = $m;
                    }
                }
            }
        }
        $groups = array_values(array_filter($groups, fn($g) => count($g) > 0));

    } else {
        throw new Exception("Invalid pairingType. Use: like, different, or strategic.", 400);
    }

    // At this point we have $groups. Enforce max 5 and min 4 again and try to fix small violations
    // Merge any groups < 4 into others with space (<5)
    for ($i = 0; $i < count($groups); $i++) {
        if (count($groups[$i]) > 0 && count($groups[$i]) < 4) {
            $members = $groups[$i];
            $groups[$i] = [];
            foreach ($members as $m) {
                $placed = false;
                for ($j = 0; $j < count($groups); $j++) {
                    if ($j === $i) continue;
                    if (count($groups[$j]) < 5) {
                        $groups[$j][] = $m;
                        $placed = true;
                        break;
                    }
                }
                if (!$placed) {
                    // If we couldn't place anywhere (rare), put back into group i
                    $groups[$i][] = $m;
                }
            }
        }
    }
    // Remove empty groups
    $groups = array_values(array_filter($groups, fn($g) => count($g) > 0));

    // Final safety check: if any group >5, move extras to any groups with <5, otherwise error (shouldn't happen)
    $extras = [];
    for ($i = 0; $i < count($groups); $i++) {
        while (count($groups[$i]) > 5) {
            $extras[] = array_pop($groups[$i]);
        }
    }
    foreach ($extras as $ex) {
        $placed = false;
        for ($i = 0; $i < count($groups); $i++) {
            if (count($groups[$i]) < 5) {
                $groups[$i][] = $ex;
                $placed = true;
                break;
            }
        }
        if (!$placed) {
            // As last resort, create a new group (but must ensure min=4 for new group — so attempt to gather up to 4 extras)
            $groups[] = [$ex];
        }
    }

    // If any group <4 now, that's a problem; try to merge small groups together
    $smallGroupsIdx = [];
    foreach ($groups as $i => $g) {
        if (count($g) < 4) $smallGroupsIdx[] = $i;
    }
    if (!empty($smallGroupsIdx)) {
        // Merge all small groups into first available group with room
        foreach ($smallGroupsIdx as $idx) {
            if (!isset($groups[$idx]) || count($groups[$idx]) === 0) continue;
            $members = $groups[$idx];
            $groups[$idx] = [];
            foreach ($members as $m) {
                $placed = false;
                for ($j = 0; $j < count($groups); $j++) {
                    if ($j === $idx) continue;
                    if (count($groups[$j]) < 5) {
                        $groups[$j][] = $m;
                        $placed = true;
                        break;
                    }
                }
                if (!$placed) {
                    $groups[$idx][] = $m; // nothing we can do
                }
            }
        }
        $groups = array_values(array_filter($groups, fn($g) => count($g) > 0));
    }

    // Final validation: ensure groups are between 4 and 5
    foreach ($groups as $g) {
        if (count($g) < 4 || count($g) > 5) {
            // graceful fallback: we won't save invalid grouping
            throw new Exception("Could not form valid groups with current constraints. Try adjusting number of players.", 500);
        }
    }

    // --- Save groups to DB within transaction (clear old pairs first) ---
    $conn->begin_transaction();

    $del = $conn->prepare("DELETE FROM game_pairs WHERE gameId = ?");
    $del->bind_param("i", $gameId);
    $del->execute();
    $del->close();

    $insert = $conn->prepare("INSERT INTO game_pairs (gameId, groupNumber, playerIds) VALUES (?, ?, ?)");
    foreach ($groups as $i => $group) {
        $playerIds = array_column($group, 'user_id');
        $jsonIds = json_encode($playerIds);
        $groupNumber = $i + 1;
        $insert->bind_param("iis", $gameId, $groupNumber, $jsonIds);
        $insert->execute();
    }
    $insert->close();

    $conn->commit();

    echo json_encode([
        "status" => "Success",
        "message" => "Players paired successfully",
        "pairingType" => $pairingType,
        "groups" => $groups
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->connect_errno === 0) {
        // if transaction started, roll back
        @$conn->rollback();
    }
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

$conn->close();
