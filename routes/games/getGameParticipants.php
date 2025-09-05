<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");

try {
    // Allow only GET requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request", 400);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    // Check for Admin role
    if ($loggedInUserRole !== "Admin" && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Unauthorized, Access denied!", 403);
    }

    // Get query parameters
    $gameId = isset($_GET['gameId']) && is_numeric($_GET['gameId']) ? (int)$_GET['gameId'] : null;
    if (!$gameId) {
        throw new Exception("Missing or invalid gameId", 400);
    }

    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($page - 1) * $limit;

    $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'createdAt';
    $sortOrder = isset($_GET['sortOrder']) && strtolower($_GET['sortOrder']) === 'asc' ? 'ASC' : 'DESC';

    // Whitelist sortable fields
    $allowedSortBy = [
        'id',
        'game_id',
        'user_id',
        'firstName',
        'lastName',
        'email',
        'userName',
        'createdAt',
        'skillLevel',
        'role',
        'pairNumber'
    ];
    if (!in_array($sortBy, $allowedSortBy)) {
        $sortBy = 'createdAt';
    }

    // WHERE clause
    $whereClause = "WHERE game_id = ?";
    $params = [$gameId];
    $types = "i";

    // ✅ Add search filter
    if (!empty($_GET['search'])) {
        $search = "%" . $_GET['search'] . "%";
        $whereClause .= " AND (CONCAT(firstName, ' ', lastName) LIKE ? OR email LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $types .= "ss";
    }

    // Count query
    $totalQuery = "SELECT COUNT(*) AS total FROM game_participants $whereClause";
    $stmt = $conn->prepare($totalQuery);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $totalResult = $stmt->get_result();
    $totalRow = $totalResult->fetch_assoc();
    $total = (int)$totalRow['total'];
    $stmt->close();

    // Main query
    $query = "SELECT * FROM game_participants $whereClause ORDER BY $sortBy $sortOrder LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $participants = [];
    while ($row = $result->fetch_assoc()) {
        $participants[] = [
            "id" => $row["id"],
            "game_id" => $row["game_id"],
            "user_id" => $row["user_id"],
            "firstName" => $row["firstName"],
            "lastName" => $row["lastName"],
            "email" => $row["email"],
            "userName" => $row["userName"],
            "image" => $row["image"],
            "skillLevel" => $row["skillLevel"],
            "country_code" => $row["country_code"],
            "number" => $row["number"],
            "membershipPayment" => $row["membershipPayment"],
            "membershipPaymentDate" => $row["membershipPaymentDate"],
            "membershipPaymentAmount" => $row["membershipPaymentAmount"],
            "membershipPaymentDuration" => $row["membershipPaymentDuration"],
            "tutorshipPayment" => $row["tutorshipPayment"],
            "tutorshipPaymentDate" => $row["tutorshipPaymentDate"],
            "tutorshipPaymentAmount" => $row["tutorshipPaymentAmount"],
            "tutorshipPaymentDuration" => $row["tutorshipPaymentDuration"],
            "isEmailVerified" => $row["isEmailVerified"],
            "role" => $row["role"],
            "createdAt" => $row["createdAt"],
            "pairNumber" => $row["pairNumber"],
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Participants retrieved successfully",
        "data" => $participants,
        "meta" => [
            "total" => $total,
            "limit" => $limit,
            "page" => $page,
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder,
            "search" => $_GET['search'] ?? null
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
