<?php
require 'vendor/autoload.php'; // Load dependencies
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");

// Authenticate user
$userData = authenticateUser();
$loggedInUserId = intval($userData['id']);
$loggedInUserRole = $userData['role'];

// Only allow PATCH requests
if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(["status" => "Failed", "message" => "Method not allowed."]);
    exit;
}


// Get request body data
$data = json_decode(file_get_contents("php://input"), true);

// Validate input
if (!isset($data['userId']) || !is_numeric($data['userId']) || intval($data['userId']) <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "Failed", "message" => "Invalid user ID."]);
    exit;
}

if (!isset($data['notificationId']) || !is_numeric($data['notificationId']) || intval($data['notificationId']) <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "Failed", "message" => "Invalid notification ID."]);
    exit;
}

// Convert to integer
// $userId = intval($data['userId']);

$userId = intval(trim($data['userId']));
$notificationId = intval($data['notificationId']);
// $expoPushToken = intval(trim($data['expoPushToken']));

// Prevent unauthorized access
if ($loggedInUserRole !== "Admin" && $loggedInUserRole !== "Super_Admin" && $userId !== $loggedInUserId) {
    http_response_code(403);
    echo json_encode(["message" => "Access denied. You can only view your own details."]);
    exit;
}

// Check if the notification exists for the user
$checkQuery = "SELECT * FROM user_notifications WHERE userId = ? AND notificationId = ?";
$stmt = $conn->prepare($checkQuery);
$stmt->bind_param("ii", $userId, $notificationId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["status" => "Failed", "message" => "No matching notification found."]);
    exit;
}

// Update notification status
$updateQuery = "UPDATE user_notifications SET isRead = 1 WHERE userId = ? AND notificationId = ?";
$stmt = $conn->prepare($updateQuery);
$stmt->bind_param("ii", $userId, $notificationId);

if ($stmt->execute()) {
    http_response_code(200);
    echo json_encode(["status" => "Successful", "message" => "Notification marked as read"]);
} else {
    http_response_code(500);
    echo json_encode(["status" => "Failed", "message" => "Database error", "error" => $stmt->error]);
}

// Close connection
$stmt->close();
$conn->close();
?>
