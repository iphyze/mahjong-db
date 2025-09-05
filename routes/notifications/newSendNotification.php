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
} else {
    http_response_code(404);
    echo json_encode(["message" => "Page not found!"]);
    exit;
}

/**
 * Send notification to one, multiple, or all users.
 * @param mixed $userId (int|string|array)
 * @param string $title
 * @param string $message
 * @param string $createdBy
 * @param string $updatedBy
 */
function sendNotification($userId, $title, $message, $createdBy = 'Admin', $updatedBy = 'Admin') {
    global $conn;

    // Handle "all" case (string, case-insensitive)
    $isForAllUsers = (is_string($userId) && strtolower($userId) === 'all');

    if ($isForAllUsers) {
        insertNotificationForAllUsers($title, $message, $createdBy, $updatedBy);
        return;
    }

    // If userId is a single int, convert to array for uniformity
    if (is_int($userId) || (is_string($userId) && ctype_digit($userId))) {
        $userIds = [(int)$userId];
    } elseif (is_array($userId)) {
        // Filter and sanitize array of IDs
        $userIds = array_filter(array_map('intval', $userId), function($id) { return $id > 0; });
        if (empty($userIds)) {
            http_response_code(400);
            echo json_encode(["message" => "No valid user IDs provided"]);
            return;
        }
    } else {
        http_response_code(400);
        echo json_encode(["message" => "Invalid userId format"]);
        return;
    }

    // Check if all users exist
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $types = str_repeat('i', count($userIds));
    $stmt = $conn->prepare("SELECT id, expoPushToken FROM users WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$userIds);
    $stmt->execute();
    $result = $stmt->get_result();

    $foundUsers = [];
    while ($row = $result->fetch_assoc()) {
        $foundUsers[$row['id']] = $row;
    }

    $notFound = array_diff($userIds, array_keys($foundUsers));
    if (!empty($notFound)) {
        http_response_code(404);
        echo json_encode(["message" => "Some users not found", "notFound" => array_values($notFound)]);
        return;
    }

    // Insert notification for each user
    $responses = [];
    foreach ($userIds as $uid) {
        $notificationId = insertNotification($uid, $title, $message, $createdBy, $updatedBy);
        insertUserNotification($notificationId, $uid);

        $expoPushToken = $foundUsers[$uid]['expoPushToken'] ?? null;
        if (!empty($expoPushToken)) {
            sendPushNotification($expoPushToken, $title, $message);
        }

        $responses[] = [
            "id" => $notificationId,
            "userId" => $uid,
            "title" => $title,
            "message" => $message
        ];
    }

    http_response_code(200);
    echo json_encode([
        "message" => "Notification sent",
        "data" => $responses
    ]);
}

/**
 * Insert notification for all users.
 */
function insertNotificationForAllUsers($title, $message, $createdBy, $updatedBy) {
    global $conn;
    $users = $conn->query("SELECT id, expoPushToken FROM users");

    $responses = [];
    while ($user = $users->fetch_assoc()) {
        $notificationId = insertNotification($user['id'], $title, $message, $createdBy, $updatedBy);
        insertUserNotification($notificationId, $user['id']);

        if (!empty($user['expoPushToken'])) {
            sendPushNotification($user['expoPushToken'], $title, $message);
        }

        $responses[] = [
            "id" => $notificationId,
            "userId" => $user['id'],
            "title" => $title,
            "message" => $message
        ];
    }

    http_response_code(200);
    echo json_encode([
        "message" => "Notification sent to all users",
        "data" => $responses
    ]);
}

/**
 * Insert a notification record.
 * @return int Inserted notification ID
 */
function insertNotification($userId, $title, $message, $createdBy, $updatedBy) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO notifications (userId, title, message, createdBy, updatedBy) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $userId, $title, $message, $createdBy, $updatedBy);

    if ($stmt->execute()) {
        return $stmt->insert_id;
    } else {
        error_log("Database error: " . $stmt->error);
        return null;
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