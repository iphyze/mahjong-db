<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    // Ensure request method is DELETE
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Bad Request, route wasn't found!", 404);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    if ($loggedInUserRole !== 'Admin' && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Unauthorized access", 401);
    }

    // Get the request body
    $input = json_decode(file_get_contents("php://input"), true);

    if (!isset($input['gameIds']) || !is_array($input['gameIds']) || count($input['gameIds']) === 0) {
        throw new Exception("An array of game IDs is required.", 400);
    }

    $gameIds = $input['gameIds'];

    // Validate IDs
    foreach ($gameIds as $id) {
        if (!is_numeric($id)) {
            throw new Exception("All game IDs must be numeric.", 400);
        }
    }

    // Prepare placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($gameIds), '?'));

    // Check if the games exist
    $checkGamesQuery = "SELECT id FROM games WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($checkGamesQuery);

    if (!$stmt) {
        throw new Exception("Database preparation error: " . $conn->error, 500);
    }

    $stmt->bind_param(str_repeat("i", count($gameIds)), ...$gameIds);
    $stmt->execute();
    $result = $stmt->get_result();

    $existingGameIds = [];
    while ($row = $result->fetch_assoc()) {
        $existingGameIds[] = $row['id'];
    }

    if (count($existingGameIds) === 0) {
        throw new Exception("No matching games found.", 404);
    }

    // Delete related pairs first
    $deletePairsQuery = "DELETE FROM pairs WHERE gameId IN ($placeholders)";
    $stmt = $conn->prepare($deletePairsQuery);
    if (!$stmt) {
        throw new Exception("Database preparation error (pairs): " . $conn->error, 500);
    }

    $stmt->bind_param(str_repeat("i", count($gameIds)), ...$gameIds);
    $stmt->execute();

    // Delete the games
    $deleteGamesQuery = "DELETE FROM games WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($deleteGamesQuery);
    if (!$stmt) {
        throw new Exception("Database preparation error (games): " . $conn->error, 500);
    }

    $stmt->bind_param(str_repeat("i", count($gameIds)), ...$gameIds);
    $stmt->execute();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Successfully deleted " . count($existingGameIds) . " game(s) and their related pairs."
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
