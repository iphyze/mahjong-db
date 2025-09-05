<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: route not found!", 404);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    if ($loggedInUserRole !== 'Admin' && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Unauthorized access!", 401);
    }

    // Validate 'id' parameter
    if (!isset($_GET['id']) || empty($_GET['id'])) {
        throw new Exception("Missing required parameter: 'id'", 400);
    }

    $gameId = (int) $_GET['id'];

    if ($gameId <= 0) {
        throw new Exception("Invalid game id", 400);
    }

    // Check if game exists
    $gameStmt = $conn->prepare("SELECT * FROM games WHERE id = ?");
    if (!$gameStmt) {
        throw new Exception("Prepare failed: " . $conn->error, 500);
    }

    $gameStmt->bind_param("i", $gameId);
    $gameStmt->execute();
    $gameResult = $gameStmt->get_result();

    if ($gameResult->num_rows === 0) {
        throw new Exception("Game not found", 404);
    }

    $game = $gameResult->fetch_assoc();
    $gameStmt->close();

    // Fetch related pairs
    $pairStmt = $conn->prepare("SELECT * FROM pairs WHERE gameId = ? ORDER BY createdAt DESC");
    if (!$pairStmt) {
        throw new Exception("Prepare failed: " . $conn->error, 500);
    }

    $pairStmt->bind_param("i", $gameId);
    $pairStmt->execute();
    $pairResult = $pairStmt->get_result();
    $pairs = $pairResult->fetch_all(MYSQLI_ASSOC);
    $pairStmt->close();

    // Success response
    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Game and related pairs fetched successfully",
        "data" => [
            "game" => $game,
            "pairs" => $pairs
        ]
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
