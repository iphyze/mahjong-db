<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Bad Request: Only GET method is allowed", 405);
    }

    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];

    if ($loggedInUserRole !== 'Admin' && $loggedInUserRole !== "Super_Admin") {
        throw new Exception("Access Denied, unauthorized user!", 401);
    }

    $query = "SELECT * FROM user_payment ORDER BY paymentDate DESC";
    $stmt = $conn->prepare($query);
    if (!$stmt) throw new Exception("Database error: " . $conn->error, 500);

    $stmt->execute();
    $result = $stmt->get_result();

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }

    if (empty($payments)) {
        echo json_encode([
            "status" => "Success",
            "message" => "No payments found",
            "report" => []
        ]);
        exit;
    }

    // Reporting logic
    $totalTransactions = count($payments);
    $totalAmountPaid = 0;
    $totalDollarAmount = 0;
    $paymentMethods = [];
    $currencyBreakdown = [];
    $users = [];
    $latestDate = $payments[0]['paymentDate'];

    foreach ($payments as $payment) {
        $amount = (float)$payment['amount'];
        $dollar = (float)$payment['dollar_amount'];
        $email = $payment['email'];
        $name = $payment['fullname'];
        $userId = $payment['userId'];
        $method = $payment['paymentMethod'];
        $currency = $payment['currency'];

        $totalAmountPaid += $amount;
        $totalDollarAmount += $dollar;

        $paymentMethods[$method] = ($paymentMethods[$method] ?? 0) + 1;
        $currencyBreakdown[$currency] = ($currencyBreakdown[$currency] ?? 0) + 1;

        $key = $userId . "_" . $email;
        if (!isset($users[$key])) {
            $users[$key] = [
                "email" => $email,
                "fullname" => $name,
                "totalAmount" => 0,
                "transactions" => 0
            ];
        }
        $users[$key]["totalAmount"] += $amount;
        $users[$key]["transactions"] += 1;

        if ($payment['paymentDate'] > $latestDate) {
            $latestDate = $payment['paymentDate'];
        }
    }

    // Format top payers
    usort($users, fn($a, $b) => $b['totalAmount'] <=> $a['totalAmount']);
    $topPayers = array_values($users);

    // Respond
    echo json_encode([
        "status" => "Success",
        "message" => "Payment report generated successfully",
        "report" => [
            "totalTransactions" => $totalTransactions,
            "totalAmountPaid" => $totalAmountPaid,
            "totalDollarAmount" => $totalDollarAmount,
            "totalUniqueUsers" => count($users),
            "paymentMethods" => $paymentMethods,
            "currencyBreakdown" => $currencyBreakdown,
            // "latestPaymentDate" => formatDate($latestDate),
            "latestPaymentDate" => $latestDate,
            "topPayers" => $topPayers
        ]
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
} finally {
    $stmt?->close();
    $conn->close();
}
?>
