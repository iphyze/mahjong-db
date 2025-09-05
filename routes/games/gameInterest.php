<?php
require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");

try {
    $userData = authenticateUser();
    $userId = $userData['id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method. Use POST", 405);
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data['game_id'], $data['interest'])) {
        throw new Exception("Missing required fields: game_id, interest", 400);
    }

    if (!in_array($data['interest'], ['yes', 'no'])) {
        throw new Exception("Invalid interest value. Use 'yes' or 'no'", 400);
    }

    // Fetch game
    $stmt = $conn->prepare("SELECT * FROM game_days WHERE id = ?");
    $stmt->bind_param("i", $data['game_id']);
    $stmt->execute();
    $game = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$game) {
        throw new Exception("Game not found", 404);
    }

    // Check deadline
    if (strtotime($game['timeline']) < time()) {
        throw new Exception("Interest deadline has passed", 403);
    }

    // Check if already responded in game_interests
    $stmt = $conn->prepare("SELECT id FROM game_interests WHERE game_id = ? AND user_id = ?");
    $stmt->bind_param("ii", $data['game_id'], $userId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare("UPDATE game_interests SET interest = ?, responded_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $data['interest'], $existing['id']);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO game_interests (game_id, user_id, interest, responded_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $data['game_id'], $userId, $data['interest']);
        $stmt->execute();
        $stmt->close();
    }

    // ✅ If interest is YES → add user to participants
    if ($data['interest'] === 'yes') {
        $stmt = $conn->prepare("SELECT id FROM game_participants WHERE game_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $data['game_id'], $userId);
        $stmt->execute();
        $participantExists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$participantExists) {
            // Fetch user full data
            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
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
                    $data['game_id'],
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
        // ✅ If interest is NO → remove user from participants (if exists)
        $stmt = $conn->prepare("DELETE FROM game_participants WHERE game_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $data['game_id'], $userId);
        $stmt->execute();
        $stmt->close();
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Interest recorded successfully"
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}

$conn->close();
