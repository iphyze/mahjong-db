<?php
include_once('authMiddleware.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    // Assume $data['payments'] contains an array of payment objects
    $payments = $data['payments'] ?? null;

    if (!$payments || !is_array($payments)) {
        echo json_encode(["message" => "Please provide valid payment data!"]);
        http_response_code(400); // Bad Request
        exit;
    }

    foreach ($payments as $payment) {
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

        // Validate required fields
        if (!$suppliers_name || !$payment_amount || !$payment_date || !$invoice_numbers || !$po_numbers || !$account_number || !$account_name || !$bank_name || !$sort_code) {
            echo json_encode(["message" => "Please provide all required fields!"]);
            http_response_code(400); // Bad Request
            exit;
        }

        // Insert the new record
        $stmtInsert = mysqli_prepare($conn, "INSERT INTO payment_schedule_tab 
            (payment_amount, payment_date, invoice_numbers, po_numbers, remark, suppliers_name, 
            account_number, sort_code, account_name, bank_name, supplier_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        mysqli_stmt_bind_param($stmtInsert, 'sssssssssss', 
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
            $suppliers_id    
        );

        if (!mysqli_stmt_execute($stmtInsert)) {
            echo json_encode(["message" => "Error inserting payment data for supplier: $suppliers_name."]);
            http_response_code(500); // Internal Server Error
            exit;
        }
    }

    echo json_encode(["message" => "All payments have been registered successfully!"]);
    http_response_code(201); // Created
    exit;
} else {
    echo json_encode(["message" => "Page not found."]);
    http_response_code(404); // Not Found
    exit;
}

// Close connection
// mysqli_close($conn);
?>
