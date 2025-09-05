<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    $userData = authenticateUser();
    if ($userData['role'] !== "Admin" && $userData['role'] !== "Super_Admin") {
        throw new Exception("Unauthorized", 403);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Invalid request method. Use PUT", 405);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data || empty($data['gameId']) || empty($data['groupNumber']) || empty($data['userIds'])) {
        throw new Exception("gameId, groupNumber, and userIds are required", 400);
    }

    $gameId = intval($data['gameId']);
    $groupNumber = intval($data['groupNumber']);
    $userIds = $data['userIds'];

    // ✅ Fetch group
    $stmt = $conn->prepare("SELECT id, playerIds FROM game_pairs WHERE gameId = ? AND groupNumber = ?");
    $stmt->bind_param("ii", $gameId, $groupNumber);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$group) {
        throw new Exception("Group not found", 404);
    }

    $currentPlayers = json_decode($group['playerIds'], true) ?? [];
    $updatedPlayers = array_values(array_diff($currentPlayers, $userIds));

    // ✅ Update DB
    $stmt = $conn->prepare("UPDATE game_pairs SET playerIds = ? WHERE id = ?");
    $jsonPlayers = json_encode($updatedPlayers);
    $stmt->bind_param("si", $jsonPlayers, $group['id']);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Players removed successfully",
        "groupNumber" => $groupNumber,
        "playerIds" => $updatedPlayers
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}
$conn->close();
