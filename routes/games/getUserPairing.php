<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    // Ensure request method is GET
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request, route wasn't found!", 404);
    }

    // Authenticate user
    $userData = authenticateUser();
    $loggedInUserId = $userData['id'];
    $loggedInUserRole = $userData['role'];

    // Extract query params
    $userId = isset($_GET['userId']) ? intval($_GET['userId']) : $loggedInUserId;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'scheduleDate';
    $sortOrder = (isset($_GET['sortOrder']) && strtolower($_GET['sortOrder']) === 'desc') ? 'DESC' : 'ASC';

    $offset = ($page - 1) * $limit;

    // Restrict access for non-admins
    if ($loggedInUserRole !== 'Admin' && $loggedInUserRole !== 'Super_Admin' && $userId !== $loggedInUserId) {
        throw new Exception("Access denied. You can only view your own pairings.", 403);
    }

    // Build the base query
    $searchClause = '';
    $params = [$userId];
    $paramTypes = 'i';

    if (!empty($search)) {
        $searchClause = "AND (g.groupName LIKE ? OR g.scheduleDate LIKE ? OR g.gameStatus LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $paramTypes .= 'sss';
    }


    // Count total results
    $countQuery = "
        SELECT COUNT(*) as total
        FROM games g
        JOIN pairs p ON g.id = p.gameId
        WHERE p.userId = ? $searchClause
    ";

    $stmt = $conn->prepare($countQuery);
    $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();
    $countResult = $stmt->get_result();
    $total = $countResult->fetch_assoc()['total'];

    // Main query to fetch paginated results
    $mainQuery = "
        SELECT g.id, g.groupName, g.scheduleDate, g.gameStatus, u.image AS userImage, u.skillLevel
        FROM games g
        JOIN pairs p ON g.id = p.gameId
        JOIN users u ON p.userId = u.id
        WHERE p.userId = ? $searchClause
        ORDER BY $sortBy $sortOrder
        LIMIT ? OFFSET ?
    ";

    $params[] = $limit;
    $params[] = $offset;
    $paramTypes .= 'ii';

    $stmt = $conn->prepare($mainQuery);
    $stmt->bind_param($paramTypes, ...$params);
    $stmt->execute();
    $gamesResult = $stmt->get_result();

    if ($gamesResult->num_rows === 0) {
        throw new Exception("No games found.", 404);
    }

    $games = [];
    while ($row = $gamesResult->fetch_assoc()) {
        $games[] = $row;
    }

    $gameIds = array_column($games, 'id');
    if (empty($gameIds)) {
        throw new Exception("No paired members found for this user.", 404);
    }

    $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
    $pairQuery = "
        SELECT p.gameId, p.dataId, u.image, u.userName, u.firstName, u.lastName, u.email, u.id AS userId
        FROM pairs p
        JOIN users u ON p.userId = u.id
        WHERE p.gameId IN ($placeholders)
    ";
    $stmt = $conn->prepare($pairQuery);
    $stmt->bind_param(str_repeat('i', count($gameIds)), ...$gameIds);
    $stmt->execute();
    $pairMembersResult = $stmt->get_result();

    $pairMembers = [];
    while ($row = $pairMembersResult->fetch_assoc()) {
        $pairMembers[] = $row;
    }

    $formattedData = array_map(function ($game) use ($pairMembers, $userId) {
        return [
            "id" => $game['id'],
            "groupName" => $game['groupName'],
            "userImage" => $game['userImage'] ?? null,
            "skillLevel" => $game['skillLevel'] ?? "Not specified",
            "scheduledDate" => $game['scheduleDate'],
            "gameStatus" => $game['gameStatus'],
            "pairMembersData" => array_values(array_filter($pairMembers, function ($member) use ($game, $userId) {
                return $member['gameId'] === $game['id'] && $member['userId'] !== $userId;
            }))
        ];
    }, $games);

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Record fetched successfully!",
        "data" => $formattedData,
        "meta" => [
            "total" => $total,
            "limit" => $limit,
            "page" => $page,
            "sortBy" => $sortBy,
            "sortOrder" => $sortOrder
        ],
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
