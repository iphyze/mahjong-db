<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");

try {
    // Authenticate
    $userData = authenticateUser();
    $loggedInRole = $userData['role'];

    if ($loggedInRole !== "Admin" && $loggedInRole !== "Super_Admin") {
        throw new Exception("Unauthorized", 403);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. Use POST", 405);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['game_id'], $data['user_id'], $data['interest'])) {
        throw new Exception("Missing required fields: game_id, user_id, interest", 400);
    }

    if (!is_array($data['user_id']) || empty($data['user_id'])) {
        throw new Exception("user_id must be a non-empty array", 400);
    }

    if (!in_array($data['interest'], ['yes', 'no'])) {
        throw new Exception("Invalid interest value. Use 'yes' or 'no'", 400);
    }

    $gameId = intval($data['game_id']);
    $interest = $data['interest'];
    $userIds = $data['user_id'];

    // Fetch game
    $stmt = $conn->prepare("SELECT * FROM game_days WHERE id = ?");
    $stmt->bind_param("i", $gameId);
    $stmt->execute();
    $game = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$game) {
        throw new Exception("Game not found", 404);
    }

    // Loop through each user
    foreach ($userIds as $uid) {
        $uid = intval($uid);

        // Check if interest record exists
        $stmt = $conn->prepare("SELECT id FROM game_interests WHERE game_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $gameId, $uid);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $stmt = $conn->prepare("UPDATE game_interests SET interest = ?, responded_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $interest, $existing['id']);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO game_interests (game_id, user_id, interest, responded_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iis", $gameId, $uid, $interest);
            $stmt->execute();
            $stmt->close();
        }

        if ($interest === 'yes') {
            // Check participant
            $stmt = $conn->prepare("SELECT id FROM game_participants WHERE game_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $gameId, $uid);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$exists) {
                // Fetch user
                $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->bind_param("i", $uid);
                $stmt->execute();
                $userRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($userRow) {
                    $stmt = $conn->prepare("
                        INSERT INTO game_participants 
                        (game_id, user_id, firstName, lastName, email, userName, image, skillLevel,
                         country_code, number, membershipPayment, membershipPaymentDate, membershipPaymentAmount, membershipPaymentDuration,
                         tutorshipPayment, tutorshipPaymentDate, tutorshipPaymentAmount, tutorshipPaymentDuration,
                         isEmailVerified, role, createdAt, pairNumber) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NULL)
                    ");

                    $stmt->bind_param(
                        "iissssssssssssssssis",
                        $gameId,
                        $userRow['id'],
                        $userRow['firstName'],
                        $userRow['lastName'],
                        $userRow['email'],
                        $userRow['userName'],
                        $userRow['image'],
                        $userRow['skillLevel'],
                        $userRow['country_code'],
                        $userRow['number'],
                        $userRow['membershipPayment'],
                        $userRow['membershipPaymentDate'],
                        $userRow['membershipPaymentAmount'],
                        $userRow['membershipPaymentDuration'],
                        $userRow['tutorshipPayment'],
                        $userRow['tutorshipPaymentDate'],
                        $userRow['tutorshipPaymentAmount'],
                        $userRow['tutorshipPaymentDuration'],
                        $userRow['isEmailVerified'],
                        $userRow['role']
                    );
                    $stmt->execute();
                    $stmt->close();
                }
            }
        } else {
            // Remove participant if exists
            $stmt = $conn->prepare("DELETE FROM game_participants WHERE game_id = ? AND user_id = ?");
            $stmt->bind_param("ii", $gameId, $uid);
            $stmt->execute();
            $stmt->close();
        }
    }

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Interest updated for selected users"
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

$conn->close();
