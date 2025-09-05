<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
use Firebase\JWT\JWT;
use Respect\Validation\Validator as v;
use Dotenv\Dotenv;

header('Content-Type: application/json');

try {
    $dotenv = Dotenv::createImmutable('./');
    $dotenv->load();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request: Only POST method is allowed", 400);
    }

    $data = json_decode(file_get_contents("php://input"));

    if (!isset($data->identifier) || !isset($data->password)) {
        throw new Exception("Email/Number and password are required", 400);
    }

    $identifier = trim($data->identifier);
    $password = trim($data->password);

    // Validators
    $emailValidator = v::email();
    $numberValidator = v::digit()->length(9, 15);
    $passwordValidator = v::stringType()->length(6, null);

    if (!$passwordValidator->validate($password)) {
        throw new Exception("Password must be at least 6 characters long", 400);
    }

    // Decide whether identifier is email or number
    $isEmail = $emailValidator->validate($identifier);
    $isNumber = $numberValidator->validate(ltrim($identifier, '0'));

    if (!$isEmail && !$isNumber) {
        throw new Exception("Invalid email/number format", 400);
    }

    if ($isEmail) {
        $sql = "SELECT * FROM users WHERE email = ?";
        $param = $identifier;
    } else {
        // Remove leading 0 for numbers
        if (substr($identifier, 0, 1) === '0') {
            $identifier = substr($identifier, 1);
        }
        $sql = "SELECT * FROM users WHERE number = ?";
        $param = $identifier;
    }

    // Prepare and execute query
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . $conn->error, 500);
    }

    $stmt->bind_param("s", $param);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        throw new Exception("Database execution error: " . $stmt->error, 500);
    }

    if ($result->num_rows === 0) {
        throw new Exception("Invalid login details", 401);
    }

    $user = $result->fetch_assoc();

    // Verify password
    if (!password_verify($password, $user['password'])) {
        throw new Exception("Invalid login details", 401);
    }

    // Generate JWT
    $secretKey = $_ENV["JWT_SECRET"] ?: "your_default_secret";
    $tokenPayload = [
        "id" => $user['id'],
        "email" => $user['email'],
        "role" => $user['role'],
        "exp" => time() + ($_ENV["JWT_EXPIRES_IN"] ?: 5 * 24 * 60 * 60)
    ];
    $token = JWT::encode($tokenPayload, $secretKey, 'HS256');

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Login successful",
        "data" => [
            "id" => $user['id'],
            "firstName" => $user['firstName'],
            "lastName" => $user['lastName'],
            "email" => $user['email'],
            "userName" => $user['userName'],
            "image" => $user['image'],
            "skillLevel" => $user['skillLevel'],
            "role" => $user['role'],
            "isEmailVerified" => $user['isEmailVerified'] == 1,
            "payments" => [
                "membership" => [
                    "membershipPayment" => $user['membershipPayment'],
                    "membershipPaymentAmount" => $user['membershipPaymentAmount'],
                    "membershipPaymentDate" => $user['membershipPaymentDate'],
                    "membershipPaymentDuration" => $user['membershipPaymentDuration'],
                ],
                "tutorship" => [
                    "tutorshipPayment" => $user['tutorshipPayment'],
                    "tutorshipPaymentAmount" => $user['tutorshipPaymentAmount'],
                    "tutorshipPaymentDate" => $user['tutorshipPaymentDate'],
                    "tutorshipPaymentDuration" => $user['tutorshipPaymentDuration'],
                ]
            ],
            "emailVerification" => [
                "emailCode" => $user['emailCode'],
                "expiresAt" => $user['expiresAt']
            ],
            "token" => $token,
            "country_code" => $user['country_code'],
            "number" => $user['number'],
            "createdAt" => $user['createdAt'],
            "updatedBy" => $user['updatedBy'],
        ]
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

?>
