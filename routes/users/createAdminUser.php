<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use Respect\Validation\Validator as v;
use Firebase\JWT\JWT;
use Dotenv\Dotenv;

header('Content-Type: application/json');

// Load environment variables
$dotenv = Dotenv::createImmutable('./');
$dotenv->load();

try {
    // Only allow POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception("Method Not Allowed: Use POST");
    }

    // Authenticate
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];
    $loggedInUserEmail = $userData['email'];

    // Restrict to Super_Admin
    if ($loggedInUserRole !== "Super_Admin") {
        http_response_code(403);
        throw new Exception("Unauthorized, access denied.");
    }

    // Decode input
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        http_response_code(400);
        throw new Exception("Invalid JSON payload.");
    }

    // Required fields
    $requiredFields = ['firstName', 'lastName', 'email', 'password', 'country_code', 'number'];
    foreach ($requiredFields as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            throw new Exception("Missing field: $field");
        }
    }

    // Extract fields
    $firstName = trim($data['firstName']);
    $lastName = trim($data['lastName']);
    $email = strtolower(trim($data['email']));
    $password = trim($data['password']);
    $country_code = trim($data['country_code']);
    $number = trim($data['number']);
    $role = trim($data['role'] ?? "User");
    $userName = trim($data['userName'] ?? '');
    $skillLevel = trim($data['skillLevel'] ?? 'Beginner');

    // Validate email and password
    if (!v::email()->notEmpty()->validate($email)) {
        http_response_code(400);
        throw new Exception("Invalid email format.");
    }

    if (!v::stringType()->length(6)->validate($password)) {
        http_response_code(400);
        throw new Exception("Password must be at least 6 characters long.");
    }

    // Check for existing user
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR number = ?");
    $checkStmt->bind_param("ss", $email, $number);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    if ($checkResult->num_rows > 0) {
        http_response_code(409);
        throw new Exception("$email already exists with this email or number.");
    }

    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $timestamp = date('Y-m-d H:i:s');

    // Insert user
    $insertStmt = $conn->prepare("
        INSERT INTO users (
            firstName, lastName, email, password, country_code, number,
            role, userName, skillLevel, createdBy, updatedBy, createdAt, updatedAt
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$insertStmt) {
        http_response_code(500);
        throw new Exception("Failed to prepare database statement.");
    }

    $insertStmt->bind_param(
        "sssssssssssss",
        $firstName, $lastName, $email, $hashedPassword, $country_code,
        $number, $role, $userName, $skillLevel, $loggedInUserEmail,
        $loggedInUserEmail, $timestamp, $timestamp
    );

    if (!$insertStmt->execute()) {
        http_response_code(500);
        throw new Exception("Failed to create user.");
    }

    // Generate JWT
    $userId = $insertStmt->insert_id;
    $secretKey = $_ENV["JWT_SECRET"] ?: "default_secret_key";
    $expiresIn = $_ENV["JWT_EXPIRES_IN"] ?: (5 * 24 * 60 * 60); // 5 days
    $payload = [
        "userId" => $userId,
        "email" => $email,
        "role" => $role,
        "exp" => time() + $expiresIn
    ];
    $token = JWT::encode($payload, $secretKey, 'HS256');

    // Success response
    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "User was created successfully.",
        "data" => [
            "id" => $userId,
            "firstName" => $firstName,
            "lastName" => $lastName,
            "email" => $email,
            "userName" => $userName,
            "skillLevel" => $skillLevel,
            "role" => $role,
            "token" => $token,
            "country_code" => $country_code,
            "number" => $number,
            "createdAt" => $timestamp,
            "updatedBy" => $loggedInUserEmail,
        ],
    ]);
} catch (Exception $e) {
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
} finally {
    if (isset($checkStmt)) $checkStmt->close();
    if (isset($insertStmt)) $insertStmt->close();
    $conn->close();
}

?>
