<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    // Ensure request method is GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request, route wasn't found!", 404);
    }


    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];


    if($loggedInUserRole !== 'Admin' && $loggedInUserRole !== "Super_Admin"){
        throw new Exception("Unauthorized access", 401);
    }

    // Fetch all games
    $gamesQuery = "SELECT id, groupName, scheduleDate, gameStatus FROM games";
    $gamesResult = $conn->query($gamesQuery);

    if (!$gamesResult) {
        throw new Exception("Database error: " . $conn->error, 500);
    }

    if ($gamesResult->num_rows === 0) {
        throw new Exception("No games found.", 404);
    }

    $games = [];
    while ($row = $gamesResult->fetch_assoc()) {
        $games[] = $row;
    }

    // Extract game IDs
    $gameIds = array_column($games, 'id');

    // Fetch player details for these games
    if (empty($gameIds)) {
        throw new Exception("No player data found for any game.", 404);
    }

    $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
    $playersQuery = "
        SELECT p.gameId, p.dataId, u.id AS userId, u.firstName, u.lastName, u.email, u.userName, u.image, u.skillLevel
        FROM pairs p
        JOIN users u ON p.userId = u.id
        WHERE p.gameId IN ($placeholders)
    ";

    $stmt = $conn->prepare($playersQuery);
    $stmt->bind_param(str_repeat('i', count($gameIds)), ...$gameIds);
    $stmt->execute();
    $playersResult = $stmt->get_result();

    if (!$playersResult) {
        throw new Exception("Database error: " . $stmt->error, 500);
    }

    $players = [];
    while ($row = $playersResult->fetch_assoc()) {
        $players[] = $row;
    }

    // Format response
    $formattedData = array_map(function ($game) use ($players) {
        return [
            "id" => $game['id'],
            "groupName" => $game['groupName'],
            "scheduledDate" => $game['scheduleDate'],
            "gameStatus" => $game['gameStatus'],
            "players" => array_values(array_map(function ($player) {
                return [
                    "userId" => $player['userId'],
                    "dataId" => $player['dataId'],
                    "userName" => $player['userName'],
                    "fullName" => $player['firstName'] . " " . $player['lastName'],
                    "email" => $player['email'],
                    "userName" => $player['userName'],
                    "image" => $player['image'] ?? null,
                    "skillLevel" => $player['skillLevel'] ?? "Not specified"
                ];
            }, array_filter($players, function ($player) use ($game) {
                return intval($player['gameId']) === intval($game['id']); // Type-safe comparison
            })))
        ];
    }, $games);

    // Success response
    http_response_code(200);
    echo json_encode($formattedData);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
