<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
use Respect\Validation\Validator as v;
use Firebase\JWT\JWT;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable('./');
$dotenv->load();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(["message" => "Bad Request: Only POST method is allowed"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (!isset($data['firstName'], $data['lastName'], $data['password'], $data['country_code'], $data['number'])) {
    http_response_code(400);
    echo json_encode(["message" => "All fields are required"]);
    exit;
}

$firstName = trim($data['firstName']);
$lastName = trim($data['lastName']);
$password = trim($data['password']);
$country_code = trim($data['country_code']);
$number = trim($data['number']);
$skillLevel = trim($data['skillLevel'] ?? "Beginner");
$role = trim($data['role'] ?? "User");
$email = !empty($data['email']) ? trim(strtolower($data['email'])) : null;

// Password validation
$passwordValidator = v::stringType()->length(6, null);
if (!$passwordValidator->validate($password)) {
    http_response_code(400);
    echo json_encode(["message" => "Password must be at least 6 characters long"]);
    exit;
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$timestamp = date('Y-m-d H:i:s');
$createdBy = $lastName;
$updatedBy = $lastName;

// Check if firstName + lastName already exists
$checkNameQuery = "SELECT id FROM users WHERE firstName = ? AND lastName = ?";
$checkNameStmt = $conn->prepare($checkNameQuery);
$checkNameStmt->bind_param("ss", $firstName, $lastName);
$checkNameStmt->execute();
$nameResult = $checkNameStmt->get_result();

if ($nameResult->num_rows > 0) {
    http_response_code(409);
    echo json_encode(["message" => "User with the same first and last name already exists"]);
    exit;
}

// Check if phone number already exists (country_code + number)
$checkPhoneQuery = "SELECT id FROM users WHERE country_code = ? AND number = ?";
$checkPhoneStmt = $conn->prepare($checkPhoneQuery);
$checkPhoneStmt->bind_param("ss", $country_code, $number);
$checkPhoneStmt->execute();
$phoneResult = $checkPhoneStmt->get_result();

if ($phoneResult->num_rows > 0) {
    http_response_code(409);
    echo json_encode(["message" => "User with the same phone number already exists"]);
    exit;
}


// Generate unique username
$userName = strtolower($firstName . '.' . $lastName) . rand(1000, 9999);

// Insert user
$stmt = $conn->prepare("INSERT INTO users 
    (firstName, lastName, email, userName, password, country_code, number, role, isEmailVerified, 
     createdBy, updatedBy, createdAt, updatedAt, skillLevel) 
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["message" => "Database error: Failed to prepare statement"]);
    exit;
}

$stmt->bind_param(
    "sssssssssssss",
    $firstName,
    $lastName,
    $email,
    $userName,
    $hashedPassword,
    $country_code,
    $number,
    $role,
    $createdBy,
    $updatedBy,
    $timestamp,
    $timestamp,
    $skillLevel
);

if ($stmt->execute()) {
    $userId = $stmt->insert_id;

    // Default payment details
    $membershipPayment = false;
    $membershipPaymentAmount = 0;
    $membershipPaymentDate = null;
    $membershipPaymentDuration = null;

    $tutorshipPayment = false;
    $tutorshipPaymentAmount = 0;
    $tutorshipPaymentDate = null;
    $tutorshipPaymentDuration = null;

    // JWT token
    $secretKey = $_ENV["JWT_SECRET"] ?: "your_default_secret";
    $payload = [
        "userId" => $userId,
        "lastName" => $lastName,
        "role" => $role,
        "exp" => time() + ($_ENV["JWT_EXPIRES_IN"] ?: 5 * 24 * 60 * 60)
    ];
    $token = JWT::encode($payload, $secretKey, 'HS256');

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "User registration successful",
        "data" => [
            "id" => $userId,
            "firstName" => $firstName,
            "lastName" => $lastName,
            "email" => $email,
            "userName" => $userName,
            "skillLevel" => $skillLevel,
            "isEmailVerified" => false,
            "payments" => [
                "membership" => [
                    "membershipPayment" => $membershipPayment,
                    "membershipPaymentAmount" => $membershipPaymentAmount,
                    "membershipPaymentDate" => $membershipPaymentDate,
                    "membershipPaymentDuration" => $membershipPaymentDuration,
                ],
                "tutorship" => [
                    "tutorshipPayment" => $tutorshipPayment,
                    "tutorshipPaymentAmount" => $tutorshipPaymentAmount,
                    "tutorshipPaymentDate" => $tutorshipPaymentDate,
                    "tutorshipPaymentDuration" => $tutorshipPaymentDuration,
                ]
            ],
            "role" => $role,
            "token" => $token,
            "country_code" => $country_code,
            "number" => $number,
            "createdAt" => $timestamp,
            "updatedBy" => $updatedBy,
        ]
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Error creating user",
        "details" => $stmt->error
    ]);
}

$conn->close();
?>
