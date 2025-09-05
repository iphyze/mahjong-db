<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    // Authenticate the user
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    if ($loggedInUserRole !== 'Admin' && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Unauthorized: Only Admins can access games", 401);
    }

    // Validate pagination parameters
    if (!isset($_GET['limit']) || !isset($_GET['page'])) {
        throw new Exception("Missing required parameters: 'limit' and 'page' are required.", 400);
    }

    $limit = (int) $_GET['limit'];
    $page = (int) $_GET['page'];
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    if ($limit <= 0 || $page <= 0) {
        throw new Exception("Invalid values: 'limit' and 'page' must be positive integers.", 400);
    }

    $offset = ($page - 1) * $limit;

    // Sorting
    $sortableFields = ['id', 'createdAt', 'groupName', 'gameStatus'];
    $sortBy = isset($_GET['sortBy']) && in_array($_GET['sortBy'], $sortableFields) ? $_GET['sortBy'] : 'createdAt';
    $sortOrder = (isset($_GET['sortOrder']) && strtolower($_GET['sortOrder']) === 'asc') ? 'ASC' : 'DESC';

    // Base SQL
    $whereClause = '';
    $params = [];
    $types = '';

    // Search condition
    if (!empty($search)) {
        $whereClause = "WHERE groupName LIKE ? OR gameStatus LIKE ?";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $types .= 'ss';
    }

    // Total count query
    $countQuery = "SELECT COUNT(*) AS total FROM games $whereClause";
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        throw new Exception("Failed to prepare count statement: " . $conn->error, 500);
    }

    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }

    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total = $countResult->fetch_assoc()['total'];
    $countStmt->close();

    // Fetch paginated data
    $dataQuery = "SELECT * FROM games $whereClause ORDER BY $sortBy $sortOrder LIMIT ? OFFSET ?";
    $dataStmt = $conn->prepare($dataQuery);
    if (!$dataStmt) {
        throw new Exception("Failed to prepare data query: " . $conn->error, 500);
    }

    // Add pagination params
    $types .= 'ii';
    $params[] = $limit;
    $params[] = $offset;

    $dataStmt->bind_param($types, ...$params);
    $dataStmt->execute();
    $result = $dataStmt->get_result();
    $games = $result->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    // Success response
    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Games fetched successfully",
        "data" => $games,
        "meta" => [
            "total" => (int) $total,
            "limit" => $limit,
            "page" => $page,
            // Optional: include sort metadata for frontend use
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder,
            "search" => $search
        ]
    ]);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
