<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request", 400);
    }

    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    if ($loggedInUserRole !== "Admin" && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Unauthorized, Access denied!", 403);
    }

    $query = "SELECT * FROM users WHERE role = 'User' ORDER BY createdAt DESC";
    $result = $conn->query($query);

    $users = [];
    while ($user = $result->fetch_assoc()) {
        $users[] = $user;
    }

    if (empty($users)) {
        echo json_encode([
            "status" => "Success",
            "message" => "No users found",
            "report" => []
        ]);
        exit;
    }

    // Reporting stats
    $totalUsers = count($users);
    $verifiedEmails = 0;
    $membershipStatus = ["Active" => 0, "Pending" => 0, "Expired" => 0];
    $tutorshipStatus = ["Active" => 0, "Pending" => 0, "Expired" => 0];
    $skillLevelBreakdown = [];
    $countryDistribution = [];

    foreach ($users as $user) {
        if ((int)$user["isEmailVerified"] === 1) $verifiedEmails++;

        // Membership
        $membership = $user["membershipPayment"] ?? "Pending";
        $membershipStatus[$membership] = ($membershipStatus[$membership] ?? 0) + 1;

        // Tutorship
        $tutorship = $user["tutorshipPayment"] ?? "Pending";
        $tutorshipStatus[$tutorship] = ($tutorshipStatus[$tutorship] ?? 0) + 1;

        // Skill Level
        $skill = $user["skillLevel"] ?? "Unknown";
        $skillLevelBreakdown[$skill] = ($skillLevelBreakdown[$skill] ?? 0) + 1;

        // Country code
        $code = $user["country_code"] ?? "Unknown";
        $countryDistribution[$code] = ($countryDistribution[$code] ?? 0) + 1;
    }

    $latestUser = $users[0];

    echo json_encode([
        "status" => "Success",
        "message" => "User report generated successfully",
        "report" => [
            "totalUsers" => $totalUsers,
            "verifiedEmails" => $verifiedEmails,
            "unverifiedEmails" => $totalUsers - $verifiedEmails,
            "membershipStatus" => $membershipStatus,
            "tutorshipStatus" => $tutorshipStatus,
            "skillLevelBreakdown" => $skillLevelBreakdown,
            "countryDistribution" => $countryDistribution,
            "latestUserRegistered" => [
                "name" => $latestUser["firstName"] . " " . $latestUser["lastName"],
                "email" => $latestUser["email"],
                "joinedAt" => $latestUser["createdAt"]
                // "joinedAt" => formatDate($latestUser["createdAt"])
            ]
        ]
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
