<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    // --- Auth check ---
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    if ($loggedInUserRole !== "Admin" && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Access Denied, unauthorized user!", 401);
    }

    // --- Input validation ---
    if (!isset($_GET['gameId'])) {
        throw new Exception("gameId is required", 400);
    }

    $gameId = intval($_GET['gameId']);

    // --- Fetch all groups for the game ---
    $stmt = $conn->prepare("SELECT groupNumber, playerIds FROM game_pairs WHERE gameId = ? ORDER BY groupNumber ASC");
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    $groupsRaw = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // if (empty($groupsRaw)) {
    //     http_response_code(200);
    //     throw new Exception("No groups found for this game.", 200);
    // }

    $groups = [];

    foreach ($groupsRaw as $group) {
        $playerIds = json_decode($group['playerIds'], true);

        if (empty($playerIds)) continue;

        // Fetch full player info
        $placeholders = implode(",", array_fill(0, count($playerIds), "?"));
        $types = str_repeat("i", count($playerIds));

        $query = "SELECT id, firstName, lastName, email, country_code, number, skillLevel, image 
                  FROM users WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$playerIds);
        $stmt->execute();
        $playersResult = $stmt->get_result();
        $players = $playersResult->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $groups[] = [
            "groupNumber" => $group['groupNumber'],
            "players" => $players
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Successfully fetched game pairs!",
        "data" => [
            "gameId" => $gameId,
            "groups" => $groups
        ]
        
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

$conn->close();

