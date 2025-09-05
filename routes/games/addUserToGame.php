<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    // Ensure request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request, route wasn't found!", 404);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    if ($loggedInUserRole !== 'Admin' && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Access Denied, unauthorized user!", 401);
    }

    // Get input data
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['gameId'], $data['groupName'], $data['users'])) {
        throw new Exception("Missing required fields!", 400);
    }

    $gameId = $data['gameId'];
    $groupName = trim($data['groupName']);
    $users = $data['users']; // Array of user objects with userId
    $createdBy = $loggedInUserEmail;
    $updatedBy = $loggedInUserEmail;

    if (!is_array($users) || count($users) === 0) {
        throw new Exception("Users list must be a non-empty array.", 400);
    }

    // Check if game exists
    $checkGameQuery = "SELECT id, groupName FROM games WHERE id = ? AND groupName = ?";
    $stmt = $conn->prepare($checkGameQuery);
    $stmt->bind_param("is", $gameId, $groupName);
    $stmt->execute();
    $gameResults = $stmt->get_result();

    if ($gameResults->num_rows === 0) {
        throw new Exception("Game not found!", 404);
    }

    // Count current players in the game
    $countPlayersQuery = "SELECT COUNT(*) AS playerCount FROM pairs WHERE gameId = ?";
    $stmt = $conn->prepare($countPlayersQuery);
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
    $countResults = $stmt->get_result();
    $countRow = $countResults->fetch_assoc();
    $currentPlayers = $countRow['playerCount'];

    if ($currentPlayers + count($users) > 5) {
        throw new Exception("Cannot add more than 5 players to a game.", 400);
    }

    // Check if users are already in the game
    $userIds = array_map(fn($user) => $user['userId'], $users);
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $checkExistingUsersQuery = "SELECT userId FROM pairs WHERE gameId = ? AND userId IN ($placeholders)";

    $stmt = $conn->prepare($checkExistingUsersQuery);
    $stmt->bind_param(str_repeat('i', count($userIds) + 1), $gameId, ...$userIds);
    $stmt->execute();
    $existingUsers = $stmt->get_result();

    $existingUserIds = [];
    while ($row = $existingUsers->fetch_assoc()) {
        $existingUserIds[] = $row['userId'];
    }

    $newUsers = array_filter($users, fn($user) => !in_array($user['userId'], $existingUserIds));

    if (count($newUsers) === 0) {
        throw new Exception("All users are already added to this game.", 400);
    }

    // Fetch user details for new users
    $newUserIds = array_map(fn($user) => $user['userId'], $newUsers);
    $placeholders = implode(',', array_fill(0, count($newUserIds), '?'));
    $fetchUsersQuery = "SELECT id AS userId, userName, image FROM users WHERE id IN ($placeholders)";

    $stmt = $conn->prepare($fetchUsersQuery);
    $stmt->bind_param(str_repeat('i', count($newUserIds)), ...$newUserIds);
    $stmt->execute();
    $userResults = $stmt->get_result();

    if ($userResults->num_rows !== count($newUsers)) {
        throw new Exception("One or more users not found.", 400);
    }

    $userDetails = [];
    while ($row = $userResults->fetch_assoc()) {
        $userDetails[] = $row;
    }

    // Insert new users into the pairs table
    $insertPairsQuery = "INSERT INTO pairs (gameId, groupName, image, userName, userId, createdBy, updatedBy) VALUES ";
    $values = [];
    $params = [];
    $paramTypes = "";

    foreach ($userDetails as $user) {
        $values[] = "(?, ?, ?, ?, ?, ?, ?)";
        $params[] = $gameId;
        $params[] = $groupName;
        $params[] = $user['image'] ?? null; // If image is NULL, keep it NULL
        $params[] = $user['userName'];
        $params[] = $user['userId'];
        $params[] = $createdBy;
        $params[] = $updatedBy;
        $paramTypes .= "isssiss";
    }

    $insertPairsQuery .= implode(',', $values);
    $stmt = $conn->prepare($insertPairsQuery);
    $stmt->bind_param($paramTypes, ...$params);
    $insert = $stmt->execute();

    if (!$insert) {
        throw new Exception("Error adding users to game: " . $stmt->error, 500);
    }

    // Success response
    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Users successfully added to the game!",
        "addedUsers" => $userDetails
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
