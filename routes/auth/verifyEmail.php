<?php

require 'vendor/autoload.php'; // Load dependencies
require_once 'includes/connection.php'; // Database connection
use Respect\Validation\Validator as v;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable('./');
$dotenv->load();


require_once 'utils/email_template.php';


header("Content-Type: application/json");


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(["message" => "Bad Request"]);
    exit;
}

// Get the request body
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['email']) || !isset($data['emailCode'])) {
    http_response_code(400);
    echo json_encode(["message" => "Email and verification code are required."]);
    exit;
}

$email = $data['email'];
$emailCode = intval($data['emailCode']); // Convert to integer

// Validation
$emailValidator = v::email()->notEmpty();
if (!$emailValidator->validate($email)) {
    http_response_code(405);
    echo json_encode(["message" => "Invalid email format"]);
    exit;
}

// Find user by email
$query = "SELECT * FROM users WHERE email = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["message" => "The provided email does not exist."]);
    exit;
}

$user = $result->fetch_assoc();

// Check if the email is already verified
if ($user['isEmailVerified']) {
    http_response_code(400);
    echo json_encode(["message" => "This email is already verified."]);
    exit;
}

// Check if the email code matches and is not expired
if ($user['emailCode'] != $emailCode || strtotime($user['expiresAt']) < time()) {
    http_response_code(400);
    echo json_encode(["message" => "Invalid or expired verification code."]);
    exit;
}

// Update user email verification status
$updateQuery = "UPDATE users SET isEmailVerified = 1, emailCode = NULL, expiresAt = NULL WHERE email = ?";
$updateStmt = $conn->prepare($updateQuery);
$updateStmt->bind_param("s", $email);

if ($updateStmt->execute()) {
    // Send verification email
    if (!sendVerificationEmail($email, $user['firstName'])) {
        error_log("Error sending verification email to $email");
    }

    http_response_code(200);
    echo json_encode(["message" => "Email verified successfully!"]);
} else {
    http_response_code(500);
    echo json_encode(["message" => "Database error", "error" => $updateStmt->error]);
}

$stmt->close();
$updateStmt->close();
$conn->close();



function sendVerificationEmail($to, $firstName) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'];
        $mail->Password = $_ENV['SMTP_PASS'];
        // $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPSecure = 'ssl';
        $mail->Port = $_ENV['SMTP_PORT'];
        $mail->setFrom($_ENV['SMTP_USER'], 'Mahjong Nigeria Clinic');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = 'Email Verified Successfully';
        $mail->Body = emailVerifyTemplate($firstName);

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mailer Error: ' . $e->getMessage());
        return false;
    }
}

?>