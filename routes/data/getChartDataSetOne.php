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

    // SQL query to get the total amount for each month, grouped by payment status
    $sql = "SELECT 
                MONTH(purchase_date) AS month, 
                payment_status, 
                SUM(amount) AS total
            FROM supplier_fund_request_table
            WHERE YEAR(purchase_date) = ?
            GROUP BY MONTH(purchase_date), payment_status
            ORDER BY MONTH(purchase_date)";

    // Prepare the statement
    $stmt = mysqli_prepare($conn, $sql);

    // Bind the year parameter to the query
    mysqli_stmt_bind_param($stmt, 'i', $year);

    // Execute the query
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    // Initialize arrays for pending, paid, and all statuses
    $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $pending = array_fill(0, 12, 0);  // Default values for 12 months
    $paid = array_fill(0, 12, 0);
    $all = array_fill(0, 12, 0);

    // Process the query result
    while ($row = mysqli_fetch_assoc($result)) {
        $month = $row['month'] - 1; // Subtract 1 to map Jan (1) -> array index (0)
        $total = (float)$row['total'];  // Ensure the total is a float
        $status = strtolower($row['payment_status']); // Convert status to lowercase

        // Assign values to the corresponding arrays based on payment status, rounding to 2 decimal places
        if ($status === 'pending') {
            $pending[$month] = round($total, 2);  // Round pending to 2 decimal places
        } elseif ($status === 'paid') {
            $paid[$month] = round($total, 2);     // Round paid to 2 decimal places
        }

        // Sum of both pending and paid for "all", rounding to 2 decimal places
        $all[$month] = round($pending[$month] + $paid[$month], 2);
    }

    // Build the response
    $response = [
        "label" => $labels,
        "pending" => $pending,
        "paid" => $paid,
        "all" => $all
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
