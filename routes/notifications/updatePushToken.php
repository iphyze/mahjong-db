<?php
require 'vendor/autoload.php'; // Load dependencies
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");

// Authenticate user
$userData = authenticateUser();
$loggedInUserId = intval($userData['id']);
$loggedInUserRole = $userData['role'];

// Only allow PUT requests
if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["status" => "Failed", "message" => "Method not allowed."]);
    exit;
}

// Get request body data
$data = json_decode(file_get_contents("php://input"), true);

// Validate input strictly for integers
if (!isset($data['userId']) || !filter_var($data['userId'], FILTER_VALIDATE_INT)) {
    http_response_code(400);
    echo json_encode(["status" => "Failed", "message" => "User ID must be an integer."]);
    exit;
}

if (!isset($data['expoPushToken']) || empty(trim($data['expoPushToken']))) {
    http_response_code(400);
    echo json_encode(["status" => "Failed", "message" => "expoPushToken is required."]);
    exit;
}

// Convert values
$userId = intval($data['userId']);
$expoPushToken = trim($data['expoPushToken']);

// Prevent unauthorized access
if ($loggedInUserRole !== "Admin" && $userId !== $loggedInUserId) {
    http_response_code(403);
    echo json_encode(["status" => "Failed", "message" => "Access denied. You can only update your own push token."]);
    exit;
}

try {
    // Check if the user exists
    $checkQuery = "SELECT * FROM users WHERE id = ?";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "Failed", "message" => "User wasn't found!"]);
        exit;
    }

    // Update push token
    $updateQuery = "UPDATE users SET expoPushToken = ? WHERE id = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("si", $expoPushToken, $userId); // Change from "ii" to "si" (string, integer)

    if ($stmt->execute()) {
        http_response_code(200);
        echo json_encode(["status" => "Success", "message" => "Push token saved successfully!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "Failed", "message" => "Failed to update push token!"]);
        // throw new Exception("Failed to update push token.");
    }
} catch (Exception $e) {
    error_log($e->getMessage(), 3, "logs/error.log");

    http_response_code(500);
    echo json_encode(["status" => "Failed", "message" => "Database error", "error" => "Internal Server Error!"]);
} finally {
    // Close connection
    if (isset($stmt)) {
        $stmt->close();
    }
    $conn->close();
}
?>
