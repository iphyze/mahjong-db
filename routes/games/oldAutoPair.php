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

    // --- Pairing logic ---
    if ($pairingType === 'like') {
        $grouped = [];
        foreach ($players as $p) {
            $grouped[$p['skillLevel']][] = $p;
        }
        $players = [];
        foreach ($grouped as $skillGroup) {
            shuffle($skillGroup);
            $players = array_merge($players, $skillGroup);
        }
    } else {
        shuffle($players); // "different"
    }

    // --- Create groups (4 normally, 5 max if leftover) ---
    $groups = [];
    $groupNum = 1;
    while (count($players) > 0) {
        if (count($players) >= 4) {
            $group = array_splice($players, 0, 4);
        } else {
            if (!empty($groups) && count($groups[$groupNum - 2]) < 5) {
                $groups[$groupNum - 2] = array_merge($groups[$groupNum - 2], $players);
                $players = [];
                break;
            } else {
                $group = $players;
                $players = [];
            }
        }
        $groups[] = $group;
        $groupNum++;
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
