<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    // --- Auth check ---
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserRole = $userData['role'];

    // --- Input validation ---
    if (!isset($_GET['userId'])) {
        throw new Exception("userId is required", 400);
    }
    $userId = intval($_GET['userId']);

    // --- Permission check ---
    if ($loggedInUserId !== $userId && $loggedInUserRole !== "Admin" && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Unauthorized", 403);
    }

    // --- Get all game_pairs where playerIds contain this user ---
    $stmt = $conn->prepare("
        SELECT gp.gameId, gp.playerIds
        FROM game_pairs gp
        WHERE JSON_CONTAINS(gp.playerIds, ?, '$')
        ORDER BY gp.gameId DESC
    ");
    $userIdJson = json_encode($userId); // e.g. 2 → "2"
    $stmt->bind_param("s", $userIdJson);
    $stmt->execute();
    $result = $stmt->get_result();
    $pairs = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (empty($pairs)) {
        echo json_encode([
            "success" => true,
            "userId" => $userId,
            "games" => []
        ]);
        exit;
    }

    $gamesOutput = [];

    foreach ($pairs as $pair) {
        $playerIds = json_decode($pair['playerIds'], true);

        if (empty($playerIds)) continue;

        // --- Fetch user details for all players in this pair ---
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

        // --- Exclude the user whose ID was passed ---
        $players = array_filter($players, fn($p) => $p['id'] != $userId);

        // --- Fetch game details from game_days ---
        $stmt = $conn->prepare("SELECT id, title, day_to_play, timeline FROM game_days WHERE id = ?");
        $stmt->bind_param("i", $pair['gameId']);
        $stmt->execute();
        $gameResult = $stmt->get_result();
        $gameDetails = $gameResult->fetch_assoc();
        $stmt->close();

        if ($gameDetails) {
            $gamesOutput[] = [
                "gameId"   => $pair['gameId'],
                "title"    => $gameDetails['title'],
                "dayToPlay"=> $gameDetails['day_to_play'],
                "timeline" => $gameDetails['timeline'],
                "players"  => array_values($players) // re-index array
            ];
        }
    }

    echo json_encode([
        "success" => true,
        "userId" => $userId,
        "games" => $gamesOutput
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}

$conn->close();
