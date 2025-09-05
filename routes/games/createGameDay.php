<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

// 📩 SMS sender function (reusable)
function sendVerificationSMS($phoneNumber, $message)
{
    $apiKey = $_ENV['TERMII_API_KEY'];
    $senderId = $_ENV['TERMII_SENDER_ID'];
    $url = $_ENV['TERMII_API_URL'] ?? "https://api.ng.termii.com/api/sms/send";

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

try {
    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserEmail = $userData['email'];
    $loggedInUserRole = $userData['role'];

    // Only Admins or Super_Admins can create game days
    if ($loggedInUserRole !== "Admin" && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Access Denied, unauthorized user!", 401);
    }

    // Ensure request is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Bad Request, route wasn't found!", 404);  
    }

    // Get JSON body
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) {
        throw new Exception("Invalid JSON payload", 400);  
    }

    $name       = $input['name'] ?? null;
    $title      = $input['title'] ?? null;
    $dayToPlay  = $input['day_to_play'] ?? null;
    $timeline   = $input['timeline'] ?? null; // interest deadline
    $notify     = strtolower($input['notify'] ?? "no"); // default No

    if (!$name || !$title || !$dayToPlay || !$timeline) {
        throw new Exception("Missing required fields (name, title, day_to_play, timeline)", 400);
    }


    // 🚫 Prevent duplicate game day name
    $checkStmt = $conn->prepare("SELECT id FROM game_days WHERE name = ?");
    $checkStmt->bind_param("s", $name);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows > 0) {
        throw new Exception("A game day with this name already exists", 400);
    }
    $checkStmt->close();

    // 1️⃣ Insert Game Day
    $stmt = $conn->prepare("INSERT INTO game_days (name, title, day_to_play, timeline, createdBy, createdAt) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("sssss", $name, $title, $dayToPlay, $timeline, $loggedInUserEmail);
    $stmt->execute();
    $gameDayId = $stmt->insert_id;
    $stmt->close();

    // 2️⃣ Fetch eligible users (exclude Admins & Super_Admins)
    $stmt = $conn->prepare("SELECT id, firstName, expoPushToken, country_code, number FROM users WHERE role NOT IN ('Super_Admin', 'Admin') AND membershipPayment = 'successful'");
    $stmt->execute();
    $result = $stmt->get_result();
    $players = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$players) {
        throw new Exception("No eligible players found", 400);
    }

    // 3️⃣ Insert into game_interests & collect tokens + userIds
    $stmt = $conn->prepare("INSERT INTO game_interests (game_id, user_id, interest, responded_at) VALUES (?, ?, NULL, NULL)");
    $tokens = [];
    $userIds = [];
    foreach ($players as $player) {
        $stmt->bind_param("ii", $gameDayId, $player['id']);
        $stmt->execute();
        $userIds[] = $player['id']; // collect all userIds

        if ($notify === "yes" && !empty($player['expoPushToken']) && strpos($player['expoPushToken'], "ExponentPushToken") === 0) {
            $tokens[] = $player['expoPushToken'];
        }
    }
    $stmt->close();


    // --- Notification logic only if notify is Yes ---
    $expoResponse = ["message" => "Notifications skipped (notify=No)"];
    if ($notify === "yes") {
        // 4️⃣ Save Notification for each user
        $stmt = $conn->prepare("INSERT INTO notifications 
            (userId, title, message, status, createdAt, createdBy, updatedAt, updatedBy) 
            VALUES (?, ?, ?, '', NOW(), ?, NOW(), ?)");

        foreach ($players as $player) {
            $uid = $player['id'];
            $firstName = trim($player['firstName'] ?? "");
            $firstName = $firstName !== "" ? $firstName : "Player";

            $notifMessage = "Dear {$firstName}, {$title} has just been scheduled for {$dayToPlay}. Indicate interest before {$timeline}.";
            $stmt->bind_param("issss", $uid, $title, $notifMessage, $loggedInUserEmail, $loggedInUserEmail);
            $stmt->execute();
            $notificationId = $stmt->insert_id;

            // Insert into user_notifications
            $stmt2 = $conn->prepare("INSERT INTO user_notifications (notificationId, userId, isRead, createdAt) VALUES (?, ?, 0, NOW())");
            $stmt2->bind_param("ii", $notificationId, $uid);
            $stmt2->execute();
            $stmt2->close();

            if (!empty($player['number'])) {
                // Normalize phone
                $countryCode = trim($player['country_code']);
                if (strpos($countryCode, '+') !== 0) {
                    $countryCode = '+' . $countryCode;
                }
                $phone = preg_replace('/\s+/', '', $countryCode . $player['number']);

                $smsMessage = "Dear $firstName, New Game Scheduled: $title on $dayToPlay. Indicate interest before $timeline.";
                sendVerificationSMS($phone, $smsMessage);
            }
        }
        $stmt->close();

        // 5️⃣ Send batched Expo push notifications
        if (!empty($tokens)) {
            $messages = [];
            foreach ($tokens as $token) {
                $messages[] = [
                    "to" => $token,
                    "sound" => "default",
                    "title" => "New Game Scheduled 🎉",
                    "body" => $notifMessage,
                    "data" => ["gameDayId" => $gameDayId]
                ];
            }

            $ch = curl_init("https://exp.host/--/api/v2/push/send");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($messages));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Accept: application/json",
                "Accept-Encoding: gzip, deflate",
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $response = curl_exec($ch);
            if ($response === false) {
                throw new Exception("Curl error: " . curl_error($ch), 400);
            }
            curl_close($ch);

            $expoResponse = json_decode($response, true);
        }
    }

    // ✅ Success response
    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Game day created" . ($notify === "yes" ? ", notifications sent" : ", notifications skipped"),
        "gameDayId" => $gameDayId,
        "notifiedPlayers" => count($userIds),
        "expoResponse" => $expoResponse
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
} finally {
    $conn->close();
}
