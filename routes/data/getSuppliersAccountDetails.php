<?php
include_once('authMiddleware.php');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Prepare the SQL statement to get supplier account details along with the sort code from bank_sortcode_table
    $stmt = mysqli_prepare($conn, "
        SELECT 
            suppliers_account_details.id, 
            suppliers_account_details.account_name, 
            suppliers_account_details.account_number, 
            suppliers_account_details.bank_name, 
            bank_sortcode_tab.sort_code
        FROM suppliers_account_details
        LEFT JOIN bank_sortcode_tab
        ON suppliers_account_details.bank_name = bank_sortcode_tab.bank_name
    ");

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    // Fetch all supplier account details along with their sort codes
    $suppliersAccounts = [];
    while ($account = mysqli_fetch_assoc($result)) {
        $suppliersAccounts[] = $account;
    }

    if (!empty($suppliersAccounts)) {
        echo json_encode([
            "data" => $suppliersAccounts
        ]);
        http_response_code(200); // OK
    } else {
        echo json_encode(["message" => "No supplier account details found."]);
        http_response_code(404); // Not Found
    }
    exit;
} else {
    echo json_encode(["message" => "Page not found."]);
    http_response_code(404); // Not Found
    exit;
}
