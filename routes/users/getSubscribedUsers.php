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
    $limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
    $offset = ($page - 1) * $limit;

    $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'createdAt';
    $sortOrder = isset($_GET['sortOrder']) && strtolower($_GET['sortOrder']) === 'asc' ? 'ASC' : 'DESC';

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    // Whitelist sortable fields
    $allowedSortBy = ['id', 'firstName', 'lastName', 'email', 'createdAt', 'skillLevel', 'role'];
    if (!in_array($sortBy, $allowedSortBy)) {
        $sortBy = 'createdAt';
    }

    // Prepare WHERE clause for search
    $whereConditions = ["role = 'User'", "membershipPayment NOT IN ('Pending', 'Expired')"];
    $params = [];
    $types = "";

    if (!empty($search)) {
    $whereConditions[] = "(firstName LIKE ? OR lastName LIKE ? OR email LIKE ?)";
    $searchTerm = "%" . $search . "%";
    $params = [$searchTerm, $searchTerm, $searchTerm];
    $types = "sss";
}

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Total count query
    $totalQuery = "SELECT COUNT(*) AS total FROM users $whereClause";
    $stmt = $conn->prepare($totalQuery);
    if (!empty($search)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $totalResult = $stmt->get_result();
    $totalRow = $totalResult->fetch_assoc();
    $total = (int)$totalRow['total'];
    $stmt->close();

    // Main query
    $query = "SELECT * FROM users $whereClause ORDER BY $sortBy $sortOrder LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);

    if (!empty($search)) {
        $types .= "ii";
        $params[] = $limit;
        $params[] = $offset;
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param("ii", $limit, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($user = $result->fetch_assoc()) {
        $users[] = [
            "id" => $user["id"],
            "firstName" => $user["firstName"],
            "lastName" => $user["lastName"],
            "email" => $user["email"],
            "userName" => $user["userName"],
            "image" => $user["image"],
            "skillLevel" => $user["skillLevel"],
            "isEmailVerified" => $user["isEmailVerified"],
            "emailVerification" => [
                "emailCode" => $user["emailCode"],
                "expiresAt" => $user["expiresAt"]
            ],
            "payments" => [
                "membership" => [
                    "membershipPayment" => $user["membershipPayment"],
                    "membershipPaymentAmount" => $user["membershipPaymentAmount"],
                    "membershipPaymentDate" => $user["membershipPaymentDate"],
                    "membershipPaymentDuration" => $user["membershipPaymentDuration"],
                ],
                "tutorship" => [
                    "tutorshipPayment" => $user["tutorshipPayment"],
                    "tutorshipPaymentAmount" => $user["tutorshipPaymentAmount"],
                    "tutorshipPaymentDate" => $user["tutorshipPaymentDate"],
                    "tutorshipPaymentDuration" => $user["tutorshipPaymentDuration"],
                ]
            ],
            "role" => $user["role"],
            "country_code" => $user["country_code"],
            "number" => $user["number"],
            "createdAt" => $user["createdAt"],
            "updatedBy" => $user["updatedBy"],
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Users retrieved successfully",
        "data" => $users,
        "meta" => [
            "total" => $total,
            "limit" => $limit,
            "page" => $page,
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder
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
