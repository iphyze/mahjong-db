<?php
include_once('authMiddleware.php'); // Include authentication middleware if needed

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the request body data
    $data = json_decode(file_get_contents("php://input"), true);

    // Retrieve the year from the request body
    $year = $data['year'] ?? date('Y');

    // Validate that the year is provided and is a valid integer
    if (!$year || !is_numeric($year) || $year < 1900 || $year > date('Y')) {
        echo json_encode(["message" => "Please provide a valid year."]);
        http_response_code(400); // Bad Request
        exit;
    }

    // SQL query to get the total figures for pending, paid, unconfirmed, and all statuses for the specified year
    $sql = "SELECT 
                payment_status, 
                SUM(amount) AS total
            FROM supplier_fund_request_table
            WHERE YEAR(purchase_date) = ?
            GROUP BY payment_status";

    // Prepare the statement
    $stmt = mysqli_prepare($conn, $sql);

    // Bind the year parameter to the query
    mysqli_stmt_bind_param($stmt, 'i', $year);

    // Execute the query
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    // Initialize variables to hold totals
    $total_all = 0;
    $total_pending = 0;
    $total_paid = 0;
    $total_unconfirmed = 0;

    // Process the query result
    while ($row = mysqli_fetch_assoc($result)) {
        $total = (float)$row['total']; // Ensure the total is a float
        $status = strtolower($row['payment_status']); // Convert status to lowercase

        // Assign totals based on payment status
        if ($status === 'pending') {
            $total_pending = round($total, 2);  // Round to 2 decimal places
        } elseif ($status === 'paid') {
            $total_paid = round($total, 2);     // Round to 2 decimal places
        } elseif ($status === 'unconfirmed') {
            $total_unconfirmed = round($total, 2); // Round to 2 decimal places
        }

        // Sum of all statuses (pending + paid + unconfirmed)
        $total_all += $total;
    }

    // Build the response, rounding the total_all to 2 decimal places
    $response = [
        "all" => round($total_all, 2),
        "pending" => $total_pending,
        "paid" => $total_paid,
        "unconfirmed" => $total_unconfirmed
    ];

    // Return the response as JSON
    echo json_encode($response);
    http_response_code(200); // OK
    exit;

} else {
    // Return a 404 error for unsupported request methods
    echo json_encode(["message" => "Page not found."]);
    http_response_code(404); // Not Found
    exit;
}
