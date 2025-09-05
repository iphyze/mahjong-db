<?php
require 'vendor/autoload.php'; 
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use Respect\Validation\Validator as v;

header("Content-Type: application/json");

try {
    $userData = authenticateUser();  
    $loggedInUserRole = $userData['role'];

    // Role check
    if ($loggedInUserRole !== "Admin" && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Unauthorized, Access denied!", 403);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
        throw new Exception("Bad Request, only PUT is allowed", 400);
    }

    $input = json_decode(file_get_contents("php://input"), true);

    $id = $input['id'] ?? null;
    $firstName = $input['firstName'] ?? null;
    $lastName = $input['lastName'] ?? null;
    $email = $input['email'] ?? null;
    $country_code = $input['country_code'] ?? null;
    $number = $input['number'] ?? null;
    $skillLevel = $input['skillLevel'] ?? null;

    // Membership payment object
    $membershipPayment = $input['membershipPayment']['status'] ?? null; // true/false
    $membershipPaymentAmount = $input['membershipPayment']['amount'] ?? null;
    $membershipPaymentDate = $input['membershipPayment']['date'] ?? null;
    $membershipPaymentDuration = $input['membershipPayment']['duration'] ?? null;

    if (!$id) {
        throw new Exception("User ID is required", 400);
    }

    if (!v::intVal()->validate($id)) {
        throw new Exception("Invalid user ID", 400);
    }

    if ($email && !v::email()->validate($email)) {
        throw new Exception("Invalid email format", 400);
    }

    // Check uniqueness of email/phone
    if ($email || ($number && $country_code)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE (email = ? OR (number = ? AND country_code = ?)) AND id != ?");
        $stmt->bind_param("sssi", $email, $number, $country_code, $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            throw new Exception("Email/Number already in use by another user", 400);
        }
    }

    // Build update query dynamically
    $updateFields = [];
    $updateValues = [];
    $types = "";

    if ($firstName) { $updateFields[] = "firstName = ?"; $updateValues[] = $firstName; $types .= "s"; }
    if ($lastName) { $updateFields[] = "lastName = ?"; $updateValues[] = $lastName; $types .= "s"; }
    if ($email) { $updateFields[] = "email = ?"; $updateValues[] = $email; $types .= "s"; }
    if ($country_code) { $updateFields[] = "country_code = ?"; $updateValues[] = $country_code; $types .= "s"; }
    if ($number) { $updateFields[] = "number = ?"; $updateValues[] = $number; $types .= "s"; }
    if ($skillLevel) { $updateFields[] = "skillLevel = ?"; $updateValues[] = $skillLevel; $types .= "s"; }

    // Membership payment fields
    if (!is_null($membershipPayment)) { $updateFields[] = "membershipPayment = ?"; $updateValues[] = (int)$membershipPayment; $types .= "i"; }
    if (!is_null($membershipPaymentAmount)) { $updateFields[] = "membershipPaymentAmount = ?"; $updateValues[] = $membershipPaymentAmount; $types .= "d"; }
    if (!is_null($membershipPaymentDate)) { $updateFields[] = "membershipPaymentDate = ?"; $updateValues[] = $membershipPaymentDate; $types .= "s"; }
    if (!is_null($membershipPaymentDuration)) { $updateFields[] = "membershipPaymentDuration = ?"; $updateValues[] = $membershipPaymentDuration; $types .= "s"; }

    if (empty($updateFields)) {
        throw new Exception("No fields provided for update", 400);
    }

    $updateQuery = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = ?";
    $updateValues[] = $id;
    $types .= "i";

    $stmt = $conn->prepare($updateQuery);
    if (!$stmt) {
        throw new Exception("Database preparation error: " . $conn->error, 500);
    }

    $stmt->bind_param($types, ...$updateValues);
    $success = $stmt->execute();

    if (!$success) {
        throw new Exception("Database execution error: " . $stmt->error, 500);
    }

    // Fetch updated user
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "User details updated successfully",
        "data" => $user
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

$conn->close();
