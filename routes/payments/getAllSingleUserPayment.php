<?php 
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request, route wasn't found!", 404);
    }

    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserRole = $userData['role'];

    // Query parameters
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 10;
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $offset = ($page - 1) * $limit;

    $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'paymentDate';
    $sortOrder = (isset($_GET['sortOrder']) && strtolower($_GET['sortOrder']) === 'asc') ? 'ASC' : 'DESC';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $userIdParam = isset($_GET['userId']) ? (int) $_GET['userId'] : null;

    // Base query and filters
    $whereClause = "WHERE 1=1";
    $params = [];
    $types = "";

    // Apply role-based filtering
    if ($loggedInUserRole === 'Admin' || $loggedInUserRole === 'Super_Admin') {
        if ($userIdParam) {
            $whereClause .= " AND userId = ?";
            $params[] = $userIdParam;
            $types .= "i";
        }
    } else {
        // Non-admins can only view their own data
        $whereClause .= " AND userId = ?";
        $params[] = $loggedInUserId;
        $types .= "i";
    }


    if (!($loggedInUserRole === 'Admin' || $loggedInUserRole === 'Super_Admin') && $userIdParam !== null && $userIdParam !== $loggedInUserId) {
        throw new Exception("Unauthorized access to another user's records", 403);
    }



    // Search filter
    if (!empty($search)) {
        $whereClause .= " AND (fullname LIKE ? OR email LIKE ? OR phoneNumber LIKE ? OR paymentStatus LIKE ? OR paymentMethod LIKE ? OR payment_type LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $types .= "ssssss";
    }

    // Get total count
    $countQuery = "SELECT COUNT(*) AS total FROM user_payment $whereClause";
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) throw new Exception("Count query preparation failed: " . $conn->error);

    if (!empty($params)) $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'] ?? 0;

    // Main data query
    $query = "SELECT * FROM user_payment $whereClause ORDER BY $sortBy $sortOrder LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Main query preparation failed: " . $conn->error);

    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
    $stmt->bind_param($types, ...$params);

    $stmt->execute();
    $result = $stmt->get_result();

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Successfully fetched payments",
        "data" => $payments,
        "meta" => [
            "total" => $total,
            "limit" => $limit,
            "page" => $page,
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder,
            // "search" => $search,
        ]
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
