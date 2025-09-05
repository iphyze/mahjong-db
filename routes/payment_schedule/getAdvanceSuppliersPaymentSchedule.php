<?php
include_once('authMiddleware.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Prepare the SQL statement to get all supplier details
    $stmt = mysqli_prepare($conn, "SELECT * FROM advance_payment_schedule_tab");

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    // Fetch all suppliers
    $suppliers = [];
    while ($supplier = mysqli_fetch_assoc($result)) {
        $suppliers[] = $supplier;
    }

    if (!empty($suppliers)) {
        http_response_code(200); // OK
        echo json_encode([
            "data" => $suppliers
        ]);
    } else {
        http_response_code(404); // Not Found
        echo json_encode(["message" => "No payment found."]);
    }
    exit;
} else {
    http_response_code(404); // Not Found
    echo json_encode(["message" => "Page not found."]);
    exit;
}
