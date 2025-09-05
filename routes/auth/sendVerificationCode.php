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


header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    echo json_encode(["message" => "Not found"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (!isset($data['email'])) {
    http_response_code(400);
    echo json_encode(["message" => "Email is required"]);
    exit;
}

$email = trim(strtolower($data['email']));

$emailValidator = v::email()->notEmpty();

if (!$emailValidator->validate($email)) {
    http_response_code(400);
    echo json_encode(["message" => "Invalid Email Format!"]);
    exit;
}


// Check if the email exists
$stmt = $conn->prepare("SELECT id, firstName, country_code, number, isEmailVerified FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["message" => "The provided email does not exist. Please create an account first."]);
    exit;
}

$user = $result->fetch_assoc();

if ($user['isEmailVerified'] == 1) {
    http_response_code(400);
    echo json_encode(["message" => "Your email is already verified."]);
    exit;
}

$emailCode = rand(1000, 9999);
$expiresAt = date('Y-m-d H:i:s', strtotime('+2 hours'));

// Update user verification code and expiration time
$updateStmt = $conn->prepare("UPDATE users SET emailCode = ?, expiresAt = ? WHERE email = ?");
$updateStmt->bind_param("sss", $emailCode, $expiresAt, $email);

if (!$updateStmt->execute()) {
    http_response_code(500);
    echo json_encode(["message" => "Database error: Failed to update verification code"]);
    exit;
}

$emailSent = sendEmailVerificationCode($email, $emailCode, $expiresAt, $user['firstName']);
$smsSent = sendVerificationSMS($user['country_code'] . $user['number'], $emailCode);

http_response_code(200);
echo json_encode([
    "message" => "Verification code sent successfully. Please check your email.",
    "emailCode" => $emailCode,
    "expiresAt" => $expiresAt,
    "verificationStatus" => [
        "email" => $emailSent,
        "sms" => $smsSent
    ]
]);

$conn->close();

// Function to send verification email
function sendEmailVerificationCode($to, $emailCode, $expiresAt, $firstName) {
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
        $mail->Subject = 'Welcome / Email Verification';

        $mail->Body = sendEmailVerificationTemplate($firstName, $emailCode, $expiresAt);

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mailer Error: ' . $e->getMessage());
        return false;
    }
}

function sendVerificationSMS($phoneNumber, $emailCode) {
    $apiKey = $_ENV['TERMII_API_KEY'];
    $senderId = $_ENV['TERMII_SENDER_ID'];
    $url = $_ENV['TERMII_API_URL'] ?? "https://api.ng.termii.com/api/sms/send";

    $message = "Your verification code is: $emailCode";
    $payload = json_encode([
        "to" => $phoneNumber,
        "from" => $senderId,
        "sms" => $message,
        "type" => "plain",
        "channel" => "generic",
        "api_key" => $apiKey
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

?>
