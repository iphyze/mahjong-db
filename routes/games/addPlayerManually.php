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
    $userIds = array_map('intval', $data['userIds']); // sanitize

    // ✅ Check that all userIds belong to interested users for this game
    $in  = str_repeat('?,', count($userIds) - 1) . '?';
    $types = str_repeat('i', count($userIds) + 1); // +1 for gameId
    $params = array_merge([$gameId], $userIds);

    $stmt = $conn->prepare("SELECT user_id FROM game_interests 
                             WHERE game_id = ? AND interest = 'yes' 
                               AND user_id IN ($in)");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($result) !== count($userIds)) {
        throw new Exception("One or more userIds are not interested in this game", 400);
    }

    // ✅ Fetch all groups for this game
    $stmt = $conn->prepare("SELECT id, groupNumber, playerIds 
                              FROM game_pairs 
                              WHERE gameId = ?");
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
    $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Track group IDs for reassignment
    $groupMap = [];
    foreach ($groups as $grp) {
        $groupMap[$grp['groupNumber']] = [
            'id' => $grp['id'],
            'players' => json_decode($grp['playerIds'], true) ?? []
        ];
    }

    if (!isset($groupMap[$groupNumber])) {
        throw new Exception("Target group not found", 404);
    }

    // ✅ Remove users from any other group they belong to
    foreach ($userIds as $uid) {
        foreach ($groupMap as $gNum => &$gData) {
            if ($gNum !== $groupNumber && in_array($uid, $gData['players'])) {
                $gData['players'] = array_values(array_diff($gData['players'], [$uid]));

                // Update DB for the old group
                $stmt = $conn->prepare("UPDATE game_pairs SET playerIds = ? WHERE id = ?");
                $jsonPlayers = json_encode($gData['players']);
                $stmt->bind_param("si", $jsonPlayers, $gData['id']);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // ✅ Handle target group
    $currentPlayers = $groupMap[$groupNumber]['players'];
    $newPlayers = array_diff($userIds, $currentPlayers); // prevent duplicates in same group

    if (empty($newPlayers)) {
        throw new Exception("All selected players are already in this group", 400);
    }

    // Enforce max 5 players
    if (count($currentPlayers) + count($newPlayers) > 5) {
        throw new Exception("Cannot add players. A group cannot have more than 5 players.", 400);
    }

    $updatedPlayers = array_values(array_merge($currentPlayers, $newPlayers));

    // ✅ Update DB for target group
    $stmt = $conn->prepare("UPDATE game_pairs SET playerIds = ? WHERE id = ?");
    $jsonPlayers = json_encode($updatedPlayers);
    $stmt->bind_param("si", $jsonPlayers, $groupMap[$groupNumber]['id']);
    $stmt->execute();
    $stmt->close();

    echo json_encode([
        "status" => "Success",
        "message" => "Players added successfully",
        "groupNumber" => $groupNumber,
        "playerIds" => $updatedPlayers
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "error" => $e->getMessage()
    ]);
}

$conn->close();
