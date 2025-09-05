<?php

require 'vendor/autoload.php'; // Load dependencies
require_once 'includes/connection.php'; // Database connection
require_once 'includes/authMiddleware.php';
use Respect\Validation\Validator as v;
use Respect\Validation\Exceptions\NestedValidationException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


use Dotenv\Dotenv;
$dotenv = Dotenv::createImmutable('./');
$dotenv->load();

require_once 'utils/email_template.php';


header('Content-Type: application/json');

// Authenticate user
$userData = authenticateUser();
$loggedInUserId = intval($userData['id']);
$loggedInUserRole = $userData['role'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(["message" => "Bad Request: Only POST method is allowed"]);
    exit;
}

function validatePaymentData($data) {
    $validators = [
        'email' => v::notEmpty()->email()->setName('Email'),
        'dollar_amount' => v::notEmpty()->number()->setName('Dollar Amount'), // Fixed
        'rate' => v::notEmpty()->number()->setName('Rate'), // Fixed
        'amount' => v::notEmpty()->number()->setName('Amount'), // Fixed
        'payment_type' => v::notEmpty()->setName('Payment Type'),
        'paymentStatus' => v::notEmpty()->setName('Payment Status'),
        'phoneNumber' => v::notEmpty()->setName('Phone Number'),
        'transactionId' => v::notEmpty()->setName('Transaction ID'),
        'userId' => v::notEmpty()->number()->setName('User ID'), // Fixed
    ];

    $errors = [];

    foreach ($validators as $field => $validator) {
        try {
            $validator->assert($data[$field] ?? null);
        } catch (NestedValidationException $exception) {
            $errors[$field] = $exception->getMessages();
        }
    }

    return $errors;
}


// Assuming you receive JSON data in POST request
$data = json_decode(file_get_contents("php://input"), true);

// $usersId = trim($data['userId']);


if ($loggedInUserRole !== "Admin" && $loggedInUserRole !== "Super_Admin" && intval($data['userId']) !== $loggedInUserId) {
    http_response_code(403);
    echo json_encode(["status" => "Failed", "message" => "Access denied. You can only update your own push token."]);
    exit;
}


function createPayment($conn, $data) {
    $errors = validatePaymentData($data);
    if (!empty($errors)) {
        return jsonResponse(400, ['errors' => $errors]);
    }

    try {
        extract($data);
        $sanitizedEmail = strtolower(trim($email));
        $paymentDate = date("Y-m-d H:i:s");
        $createdBy = $sanitizedEmail;
        $updatedBy = $sanitizedEmail;

        // Check if user exists and get their push token
        $stmt = $conn->prepare("SELECT id, expoPushToken FROM users WHERE id = ? AND email = ?");
        $stmt->bind_param("is", $userId, $sanitizedEmail);
        $stmt->execute();
        $userResult = $stmt->get_result();

        if ($userResult->num_rows === 0) {
            return jsonResponse(400, ['message' => 'User not found']);
        }

        $user = $userResult->fetch_assoc();
        $expoPushToken = $user['expoPushToken'];

        // Insert payment details
        $stmt = $conn->prepare("
            INSERT INTO user_payment (userId, email, dollar_amount, rate, amount, payment_type, paymentStatus, 
            paymentDuration, paymentDate, phoneNumber, transactionId, fullname, createdBy, updatedBy, 
            paymentMethod, transactionReference, currency)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issddssssssssssss", $userId, $sanitizedEmail, $dollar_amount, $rate, $amount, 
                          $payment_type, $paymentStatus, $paymentDuration, $paymentDate, $phoneNumber, 
                          $transactionId, $fullname, $createdBy, $updatedBy, $paymentMethod, $transactionReference, $currency);

        if (!$stmt->execute()) {
            return jsonResponse(500, ['message' => 'Error creating payment', 'error' => $stmt->error]);
        }

        $paymentId = $stmt->insert_id;

        // Update user membership/tutorship details if needed
        if ($payment_type === 'Membership Payment') {
            $updateUserQuery = "UPDATE users SET membershipPayment = ?, membershipPaymentAmount = ?, 
                                membershipPaymentDate = ?, membershipPaymentDuration = ? WHERE id = ?";
        } elseif ($payment_type === 'Tutorship Payment') {
            $updateUserQuery = "UPDATE users SET tutorshipPayment = ?, tutorshipPaymentAmount = ?, 
                                tutorshipPaymentDate = ?, tutorshipPaymentDuration = ? WHERE id = ?";
        } else {
            return processPaymentCompletion($conn, $data, $paymentId, $expoPushToken, $paymentDate);
        }

        $stmt = $conn->prepare($updateUserQuery);
        $stmt->bind_param("ssssi", $paymentStatus, $amount, $paymentDate, $paymentDuration, $userId);

        if (!$stmt->execute()) {
            return jsonResponse(500, ['message' => 'Error updating user details', 'error' => $stmt->error]);
        }

        return processPaymentCompletion($conn, $data, $paymentId, $expoPushToken, $paymentDate);

    } catch (Exception $e) {
        return jsonResponse(500, ['message' => 'Server error', 'error' => $e->getMessage()]);
    }
}

function processPaymentCompletion($conn, $data, $paymentId, $expoPushToken, $paymentDate) {
    extract($data);
    $notificationSent = false;
    $notificationTitle = $notificationMessage = null;

    if (strtolower($paymentStatus) === 'successful') {
        if ($payment_type === 'Membership Payment') {
            $notificationTitle = 'Membership Payment Completed!';
            $notificationMessage = "🎉 Congratulations $fullname! You are now an official member of Mahjong Clinic Nigeria.";
        } elseif ($payment_type === 'Tutorship Payment') {
            $notificationTitle = 'Tutorship Payment Completed!';
            $notificationMessage = "🎉 Congratulations $fullname! You are now a student at Mahjong Clinic Nigeria.";
        } else {
            $notificationTitle = 'Payment Completed!';
            $notificationMessage = "🎉 Thank you $fullname! Your payment of $currency $amount has been successfully processed.";
        }

        storeNotification($conn, $userId, $notificationTitle, $notificationMessage, $email);

        if (!empty($expoPushToken)) {
            $notificationSent = sendPushNotification($expoPushToken, $notificationTitle, $notificationMessage);
        }
    }

    $emailSent = sendPaymentEmail($email, $dollar_amount, $rate, $amount, $payment_type, $paymentStatus, 
                                  $paymentDuration, $paymentDate, $phoneNumber, $transactionId, $fullname);

    return jsonResponse(200, [
        'message' => $emailSent ? 'Payment recorded successfully, email sent!' 
                                : 'Payment recorded successfully, but email failed to send.',
        'data' => [
            'paymentId' => $paymentId,
            'notification' => strtolower($paymentStatus) === 'successful' ? [
                'sent' => $notificationSent,
                'title' => $notificationTitle,
                'message' => $notificationMessage
            ] : null
        ]
    ]);
}


function storeNotification($conn, $userId, $title, $message, $email) {
    $stmt = $conn->prepare("INSERT INTO notifications (userId, title, message, createdBy, updatedBy) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $userId, $title, $message, $email, $email);
    $stmt->execute();
}

function sendPushNotification($expoPushToken, $title, $message) {
    $data = [
        'to' => $expoPushToken,
        'sound' => 'default',
        'title' => $title,
        'body' => $message
    ];

    $ch = curl_init('https://exp.host/--/api/v2/push/send');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Accept-Encoding: gzip, deflate',
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    return $response ? true : false;
}

function sendPaymentEmail($to, $dollar_amount, $rate, $amount, $payment_type, $paymentStatus, $paymentDuration, $paymentDate, $phoneNumber, $transactionId, $fullname) {
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
        $mail->Subject = 'Payment Confirmation';
        $mail->Body = paymentConfirmationTemplate($dollar_amount, $rate, $amount, $payment_type, $paymentStatus, $paymentDuration, $paymentDate, $phoneNumber, $transactionId, $fullname);

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function jsonResponse($status, $data) {
    http_response_code($status);
    echo json_encode($data);
}


createPayment($conn, $data);


?>
