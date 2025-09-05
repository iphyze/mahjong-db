<?php
include_once('authMiddleware.php');

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    // Assume $data['ids'] contains an array of IDs to delete
    $ids = $data['ids'] ?? null;

    if (!$ids || !is_array($ids) || count($ids) === 0) {
        http_response_code(400); // Bad Request
        echo json_encode(["message" => "Please provide valid IDs for deletion!"]);
        exit;
    }

    // Prepare a statement to delete multiple records
    $placeholders = rtrim(str_repeat('?,', count($ids)), ','); // Create placeholders for the query
    $stmtDelete = mysqli_prepare($conn, "DELETE FROM advance_payment_schedule_tab WHERE id IN ($placeholders)");

    // Bind parameters dynamically based on the number of IDs
    mysqli_stmt_bind_param($stmtDelete, str_repeat('i', count($ids)), ...$ids); // 'i' for integer IDs

    if (mysqli_stmt_execute($stmtDelete)) {
        http_response_code(200); // OK
        echo json_encode(["message" => "Payments have been deleted successfully!"]);
    } else {
        http_response_code(500); // Internal Server Error
        echo json_encode(["message" => "Error deleting payment data."]);
    }
    
    exit;
} else {
    http_response_code(404); // Not Found
    echo json_encode(["message" => "Page not found."]);
    exit;
}

// Close connection
// mysqli_close($conn);
?>
