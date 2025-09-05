<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    // Ensure request method is GET
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception("Bad Request, route wasn't found!", 404);
    }


    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];


    if($loggedInUserRole !== 'Admin' && $loggedInUserRole !== "Super_Admin"){
        throw new Exception("Unauthorized access", 401);
    }

    // Get the request body
    $input = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($input['pairIds']) || !is_array($input['pairIds']) || count($input['pairIds']) === 0) {
        throw new Exception("An array of pair IDs is required.", 400);
    }
    
    $pairIds = $input['pairIds'];

    // Convert the array into a comma-separated string for SQL IN clause
    $placeholders = implode(',', array_fill(0, count($pairIds), '?'));

    // Check if the pairs exist before deleting
    $checkPairsQuery = "SELECT dataId FROM pairs WHERE dataId IN ($placeholders)";
    $stmt = $conn->prepare($checkPairsQuery);

    if (!$stmt) {
        throw new Exception("Database preparation error: " . $conn->error, 500);
    }

    // Bind parameters dynamically
    $stmt->bind_param(str_repeat("i", count($pairIds)), ...$pairIds);
    $stmt->execute();
    $result = $stmt->get_result();

    $existingPairs = [];
    while ($row = $result->fetch_assoc()) {
        $existingPairs[] = $row['dataId'];
    }

    if (count($existingPairs) === 0) {
        throw new Exception("No matching pairs found.", 404);
    }

    // Delete pairs
    $deletePairsQuery = "DELETE FROM pairs WHERE dataId IN ($placeholders)";
    $stmt = $conn->prepare($deletePairsQuery);

    if (!$stmt) {
        throw new Exception("Database preparation error: " . $conn->error, 500);
    }

    $stmt->bind_param(str_repeat("i", count($pairIds)), ...$pairIds);
    $stmt->execute();

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Successfully deleted " . count($existingPairs) . " pair(s)."
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}
?>
