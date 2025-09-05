<?php
include_once('authMiddleware.php');

if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true);

    // Retrieve the payment data
    $payment = $data['payment'] ?? null;

    if (!$payment) {
        echo json_encode(["message" => "Please provide valid payment data!"]);
        http_response_code(400); // Bad Request
        exit;
    }

    // Extract fields from the payment object
    $id = $payment['id'] ?? null; // ID of the record to update
    $suppliers_name = $payment['suppliers_name'] ?? null;
    $suppliers_id = $payment['suppliers_id'] ?? null;
    $payment_amount = $payment['payment_amount'] ?? null;
    $payment_date = $payment['payment_date'] ?? null;
    $invoice_numbers = $payment['invoice_numbers'] ?? null;
    $po_numbers = $payment['po_numbers'] ?? null;
    $account_number = $payment['account_number'] ?? null;
    $account_name = $payment['account_name'] ?? null;
    $bank_name = $payment['bank_name'] ?? null;
    $sort_code = $payment['sort_code'] ?? null;
    $remark = "Payment against Inv No.: " . $invoice_numbers . ", Po No.: " . $po_numbers;

    // Validate required fields (without user ID)
    if (!$id || !$suppliers_name || !$payment_amount || !$payment_date || !$invoice_numbers || !$po_numbers || !$account_number || !$account_name || !$bank_name || !$sort_code) {
        echo json_encode(["message" => "Please provide all required fields including ID!"]);
        http_response_code(400); // Bad Request
        exit;
    }

    // Check if the record exists in the database
    $stmtCheck = mysqli_prepare($conn, "SELECT * FROM payment_schedule_tab WHERE id = ?");
    mysqli_stmt_bind_param($stmtCheck, 'i', $id); // Bind the ID as an integer
    mysqli_stmt_execute($stmtCheck);
    $resultCheck = mysqli_stmt_get_result($stmtCheck);

    if (mysqli_num_rows($resultCheck) === 0) {
        echo json_encode(["message" => "ID does not exist."]);
        http_response_code(404); // Not Found
        exit;
    }

    // Update the existing record
    $stmtUpdate = mysqli_prepare($conn, "UPDATE payment_schedule_tab SET 
        payment_amount = ?, 
        payment_date = ?, 
        invoice_numbers = ?, 
        po_numbers = ?, 
        remark = ?, 
        suppliers_name = ?, 
        account_number = ?, 
        sort_code = ?, 
        account_name = ?, 
        bank_name = ?, 
        supplier_id = ? 
        WHERE id = ?");

    mysqli_stmt_bind_param($stmtUpdate, 'sssssssssssi', 
        $payment_amount, 
        $payment_date, 
        $invoice_numbers, 
        $po_numbers, 
        $remark, 
        $suppliers_name,
        $account_number, 
        $sort_code, 
        $account_name, 
        $bank_name,
        $suppliers_id,
        $id // Bind ID for the WHERE clause
    );

    if (!mysqli_stmt_execute($stmtUpdate)) {
        echo json_encode(["message" => "Error updating payment data for supplier: $suppliers_name."]);
        http_response_code(500); // Internal Server Error
        exit;
    }

    echo json_encode(["message" => "Payment has been updated successfully!"]);
    http_response_code(200); // OK
    exit;
} else {
    echo json_encode(["message" => "Page not found."]);
    http_response_code(404); // Not Found
    exit;
}
