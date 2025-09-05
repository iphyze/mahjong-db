<?php
include_once('authMiddleware.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Prepare the SQL statement to get supplier details with supplier_number between 40000000 and 50000000
    $stmt = mysqli_prepare($conn, "SELECT id, supplier_name, supplier_number FROM suppliers_table WHERE supplier_number BETWEEN 40000000 AND 50000000");

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    // Fetch all suppliers
    $suppliers = [];
    while ($supplier = mysqli_fetch_assoc($result)) {
        $suppliers[] = $supplier;
    }

    if (!empty($suppliers)) {
        echo json_encode([
            "data" => $suppliers
        ]);
        http_response_code(200); // OK
    } else {
        echo json_encode(["message" => "No suppliers found."]);
        http_response_code(404); // Not Found
    }
    exit;
} else {
    echo json_encode(["message" => "Page not found."]);
    http_response_code(404); // Not Found
    exit;
}
