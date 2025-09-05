<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header("Content-Type: application/json");

// Ensure the request method is GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(400);
    echo json_encode(["message" => "Bad Request"]);
    exit;
}

// Authenticate user
$userData = authenticateUser();
$loggedInUserId = intval($userData['id']);
$loggedInUserRole = $userData['role'];

// Params
$providedUserId = isset($_GET['userId']) ? intval($_GET['userId']) : null;
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? intval($_GET['limit']) : 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;
$sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : "n.createdAt";
$sortOrder = isset($_GET['sortOrder']) && strtoupper($_GET['sortOrder']) === "ASC" ? "ASC" : "DESC";
$search = isset($_GET['search']) && trim($_GET['search']) !== '' ? '%' . trim($_GET['search']) . '%' : null;

// Determine effective userId (null means Admin fetching all)
if (!in_array($loggedInUserRole, ['Admin', 'Super_Admin'])) {
    $userId = $loggedInUserId;
} else {
    $userId = $providedUserId; // Can be null for Admin
}

// Get registrationDate if filtering by specific user
$registrationDate = null;
if ($userId !== null) {
    $userCheckQuery = "SELECT createdAt as registrationDate FROM users WHERE id = ?";
    $stmt = $conn->prepare($userCheckQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $userResult = $stmt->get_result();
    if ($userResult->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "Failed", "message" => "User not found"]);
        exit;
    }
    $userData = $userResult->fetch_assoc();
    $registrationDate = $userData['registrationDate'];
    $stmt->close();
}

// Build dynamic WHERE clause
$whereClauses = [];
$params = [];
$types = "";

// Filter by user access
if ($userId !== null) {
    $whereClauses[] = "(n.userId = ? OR (n.userId = 'All' AND n.createdAt >= ?))";
    $types .= "is";
    $params[] = $userId;
    $params[] = $registrationDate;
}

// Filter by search if provided
if ($search !== null) {
    $whereClauses[] = "(n.title LIKE ? OR n.message LIKE ?)";
    $types .= "ss";
    $params[] = $search;
    $params[] = $search;
}

// Combine where clauses
$whereSQL = count($whereClauses) > 0 ? "WHERE " . implode(" AND ", $whereClauses) : "";

// Count query
$countSQL = "SELECT COUNT(*) AS total FROM notifications n";
if ($userId !== null) {
    $countSQL .= " LEFT JOIN user_notifications un ON n.id = un.notificationId AND un.userId = ?";
    array_unshift($params, $userId); // For the LEFT JOIN userId
    $types = "i" . $types;
}
$countSQL .= " $whereSQL";
$stmt = $conn->prepare($countSQL);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// Data query
$dataSQL = "
    SELECT 
        n.id AS notificationId, 
        n.title, 
        n.message, 
        n.createdAt, 
        n.userId AS notificationUserId,
        COALESCE(un.isRead, 0) AS isRead
    FROM notifications n
";

if ($userId !== null) {
    $dataSQL .= " LEFT JOIN user_notifications un ON n.id = un.notificationId AND un.userId = ?";
} else {
    $dataSQL .= " LEFT JOIN user_notifications un ON n.id = un.notificationId";
}
$dataSQL .= " $whereSQL ORDER BY $sortBy $sortOrder LIMIT ? OFFSET ?";

// Append limit/offset
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($dataSQL);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt->close();
$conn->close();

// Return final response
echo json_encode([
    "status" => "success",
    "message" => count($notifications) > 0 ? "Notifications retrieved successfully." : "No notifications available yet.",
    "data" => $notifications,
    "meta" => [
        "total" => $total,
        "limit" => $limit,
        "page" => $page,
        "sortBy" => $sortBy,
        "sortOrder" => $sortOrder,
        "search" => $search ? trim($_GET['search']) : null
    ]
]);
