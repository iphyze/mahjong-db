<?php
require 'vendor/autoload.php'; // Load dependencies
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");


// Authenticate user
$userData = authenticateUser();
$loggedInUserRole = $userData['role'];

// Prevent unauthorized access
if ($loggedInUserRole !== "Admin" && $loggedInUserRole !== "Super_Admin") {
    http_response_code(403);
    echo json_encode(["message" => "Unauthorized. Only an admin can perform this action!"]);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($data['userId'], $data['title'], $data['message'])) {
        http_response_code(400);
        echo json_encode(["message" => "Missing required fields"]);
        exit;
    }
    
    sendNotification($data['userId'], $data['title'], $data['message']);
}else{
        http_response_code(404);
        echo json_encode(["message" => "Page not found!"]);
        exit;
}


function sendNotification($userId, $title, $message, $createdBy = 'Admin', $updatedBy = 'Admin') {
    global $conn;
    
    $isForAllUsers = strtolower($userId) === 'all';
    
    if (!$isForAllUsers) {
        // Check if user exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            http_response_code(404);
            echo json_encode(["message" => "User not found"]);
            return;
        }
    }
    
    insertNotification($userId, $title, $message, $createdBy, $updatedBy);
}


function insertNotification($userId, $title, $message, $createdBy, $updatedBy) {
    global $conn;
    
    if ($userId === 'All' || $userId === 'all') {
        $users = $conn->query("SELECT id, expoPushToken FROM users");

        while ($user = $users->fetch_assoc()) {
            $stmt = $conn->prepare("INSERT INTO notifications (userId, title, message, createdBy, updatedBy) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $user['id'], $title, $message, $createdBy, $updatedBy);

            if ($stmt->execute()) {
                $notificationId = $stmt->insert_id;
                insertUserNotification($notificationId, $user['id']);

                if (!empty($user['expoPushToken'])) {
                    sendPushNotification($user['expoPushToken'], $title, $message);
                }
            } else {
                error_log("Database error: " . $stmt->error);
            }
        }

        http_response_code(200);
        echo json_encode(["message" => "Notification sent to all users"]);
    } else {
        $stmt = $conn->prepare("INSERT INTO notifications (userId, title, message, createdBy, updatedBy) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $userId, $title, $message, $createdBy, $updatedBy);

        if ($stmt->execute()) {
            $notificationId = $stmt->insert_id;
            insertUserNotification($notificationId, $userId);

            $stmt = $conn->prepare("SELECT expoPushToken FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($row = $result->fetch_assoc()) {
                if (!empty($row['expoPushToken'])) {
                    sendPushNotification($row['expoPushToken'], $title, $message);
                }
            }

            http_response_code(200);
            echo json_encode(["message" => "Notification sent", "data" => ["id" => $notificationId, "userId" => $userId, "title" => $title, "message" => $message]]);
        } else {
            http_response_code(500);
            echo json_encode(["message" => "Database error", "error" => $stmt->error]);
        }
    }
}



function insertUserNotification($notificationId, $userId) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO user_notifications (notificationId, userId, isRead) VALUES (?, ?, 0)");
    $stmt->bind_param("ii", $notificationId, $userId);
    $stmt->execute();
}

function sendPushNotification($expoPushToken, $title, $message) {
    if (!$expoPushToken || !preg_match('/^ExponentPushToken\[.*\]$/', $expoPushToken)) {
        return false;
    }

    $payload = json_encode([
        "to" => $expoPushToken,
        "sound" => "default",
        "title" => $title,
        "body" => $message,
        "data" => ["title" => $title, "message" => $message, "timestamp" => date('c')],
        "priority" => "high",
        "channelId" => "default",
        "badge" => 1,
        "_displayInForeground" => true
    ]);

    $ch = curl_init("https://exp.host/--/api/v2/push/send");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json",
        "Content-Type: application/json",
        "Accept-Encoding: gzip, deflate"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}


$conn->close();
?>
