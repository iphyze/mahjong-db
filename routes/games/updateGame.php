<?php
require_once 'includes/connection.php'; // Database connection
require_once 'includes/authMiddleware.php'; // User authentication

header('Content-Type: application/json');

try {
    // Ensure request method is PUT
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request, route wasn't found!", 404);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Only Admin can update games
    if ($loggedInUserRole !== 'Admin' && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Unauthorized access", 401);
    }

    // Retrieve JSON input data
    $inputData = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($inputData['gameId'])) {
        throw new Exception("Missing required parameter: gameId", 400);
    }

    if(!is_numeric($inputData['gameId'])){
        throw new Exception("Game Id must be an integer", 400);
    }

    // Extract input values
    $gameId = $inputData['gameId'];
    $gameStatus = $inputData['gameStatus'] ?? null;
    $scheduledDate = $inputData['scheduledDate'] ?? null;
    $groupName = $inputData['groupName'] ?? null;
    $sendNotification = $inputData['sendNotification'] ?? 'No';
    $updatedAt = date("c");

    // Ensure at least one field is being updated (like in the JS version)
    if (!$gameStatus && !$scheduledDate && !$groupName) {
        throw new Exception("At least one field must be updated.", 400);
    }

    // Call the update function
    updateGame($gameId, $gameStatus, $scheduledDate, $groupName, $sendNotification, $loggedInUserEmail, $updatedAt);
    
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

/**
 * Function to update a game in the database
 */
function updateGame($gameId, $gameStatus, $scheduledDate, $groupName, $sendNotification, $loggedInUserEmail, $updatedAt) {
    global $conn;

    // Check if the game exists
    $checkGameQuery = "SELECT * FROM games WHERE id = ?";
    $stmt = $conn->prepare($checkGameQuery);
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Game not found.", 404);
    }
    
    $game = $result->fetch_assoc();

    // If updating groupName, check if it already exists
    if ($groupName && $groupName !== $game['groupName']) {
        $checkNameQuery = "SELECT id FROM games WHERE groupName = ? AND id <> ?";
        $stmt = $conn->prepare($checkNameQuery);
        $stmt->bind_param("si", $groupName, $gameId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            throw new Exception("This group name already exists. Please choose a different name.", 400);
        }
    }

    updateGameDetails($gameId, $gameStatus, $scheduledDate, $groupName, $sendNotification, $loggedInUserEmail, $updatedAt);
}

/**
 * Function to update game details
 */
function updateGameDetails($gameId, $gameStatus, $scheduledDate, $groupName, $sendNotification, $loggedInUserEmail, $updatedAt) {
    global $conn;

    $updateFields = [];
    $values = [];

    if ($gameStatus) {
        $updateFields[] = "gameStatus = ?";
        $values[] = $gameStatus;
    }
    if ($scheduledDate) {
        $updateFields[] = "scheduleDate = ?";
        $values[] = $scheduledDate;
    }
    if ($groupName) {
        $updateFields[] = "groupName = ?";
        $values[] = $groupName;
    }
    if($loggedInUserEmail){
        $updateFields[] = "updatedBy = ?";
        $values[] = $loggedInUserEmail;
    }

    if($updatedAt){
        $updateFields[] = "updatedAt = ?";
        $values[] = $updatedAt;
    }


    if (empty($updateFields)) {
        throw new Exception("No valid fields provided for update.", 400);
    }

    $values[] = $gameId;
    $query = "UPDATE games SET " . implode(", ", $updateFields) . " WHERE id = ?";
    
    $stmt = $conn->prepare($query);
    
    // Create the appropriate types string for bind_param
    $types = str_repeat("s", count($values) - 1) . "i";
    $stmt->bind_param($types, ...$values);
    $stmt->execute();

    if ($stmt->affected_rows >= 0) { // Use >= 0 instead of > 0 to match JS behavior
        // If updating groupName, update in pairs table
        if ($groupName) {
            $updatePairsQuery = "UPDATE pairs SET groupName = ? WHERE gameId = ?";
            $stmt = $conn->prepare($updatePairsQuery);
            $stmt->bind_param("si", $groupName, $gameId);
            $stmt->execute();
            
            if ($stmt->error) {
                throw new Exception("Error updating pairs table: " . $stmt->error, 500);
            }
        }

        // Send notifications if needed
        if ($sendNotification === "Yes") {
            sendGameNotifications($gameId, $loggedInUserEmail);
        }

        echo json_encode(["status" => "Success", "message" => "Game updated successfully!"]);
    } else {
        throw new Exception("Error updating the game: " . $stmt->error, 500);
    }
}

/**
 * Function to send notifications to game users
 */
function sendGameNotifications($gameId, $loggedInUserEmail) {
    global $conn;

    // Changed to match the JS implementation - using games instead of user_notifications
    $query = "SELECT u.id, u.expoPushToken, u.email 
              FROM users u
              JOIN games ug ON u.id = ug.id
              WHERE ug.id = ?";
              
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        error_log("No users found for this game.");
        return;
    }

    $title = "Mahjong Clinic Game Update";
    $message = "The game details have been updated. Check for new changes.";

    while ($user = $result->fetch_assoc()) {
        $userId = $user['id'];
        $expoPushToken = $user['expoPushToken'];
        // $email = $user['email'];
        $email = $loggedInUserEmail;

        // Store notification in database
        storeNotification($userId, $title, $message, $email);

        // Send push notification if token exists
        if ($expoPushToken) {
            sendPushNotification($expoPushToken, $title, $message);
        }
    }
}

/**
 * Function to store notifications in the database
 */
function storeNotification($userId, $title, $message, $email) {
    global $conn;

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Insert notification into the notifications table
        $insertQuery = "INSERT INTO notifications (userId, title, message, createdBy, updatedBy) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insertQuery);
        $stmt->bind_param("issss", $userId, $title, $message, $email, $email);
        
        if (!$stmt->execute()) {
            throw new Exception("Database error inserting notification: " . $stmt->error);
        }

        // Get the last inserted notification ID
        $notificationId = $stmt->insert_id;

        // Insert into user_notifications table
        $userNotificationQuery = "INSERT INTO user_notifications (notificationId, userId, isRead) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($userNotificationQuery);
        $isRead = false;
        $stmt->bind_param("iis", $notificationId, $userId, $isRead);
        
        if (!$stmt->execute()) {
            throw new Exception("Error inserting user notification: " . $stmt->error);
        }

        // Commit transaction
        $conn->commit();
        
        return $notificationId;
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        error_log("Error storing notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Function to send push notifications using Expo
 */
function sendPushNotification($expoPushToken, $title, $message) {
    if (!$expoPushToken) {
        error_log('No push token provided, skipping notification');
        return false;
    }

    // Validate token format (matching JS implementation)
    if (!str_starts_with($expoPushToken, 'ExponentPushToken[') && !str_starts_with($expoPushToken, 'ExpoPushToken[')) {
        error_log('Invalid token format: ' . $expoPushToken);
        return false;
    }

    $notificationPayload = [
        "to" => $expoPushToken,
        "sound" => "default",
        "title" => $title,
        "body" => $message,
        "data" => [
            "title" => $title,
            "message" => $message,
            "timestamp" => date("c")
        ],
        "priority" => "high",
        "channelId" => "default",
        "badge" => 1,
        "_displayInForeground" => true
    ];

    error_log('Sending push notification: ' . json_encode($notificationPayload));

    $ch = curl_init("https://exp.host/--/api/v2/push/send");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Accept-Encoding: gzip, deflate",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($notificationPayload));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        error_log('Error sending push notification: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }
    
    curl_close($ch);
    
    if ($httpCode >= 400) {
        error_log('Server responded with error: ' . $httpCode . ', Response: ' . $response);
        return false;
    }
    
    error_log('Push notification response: ' . $response);
    return true;
}


// Polyfill for str_starts_with if running on PHP < 8.0
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}