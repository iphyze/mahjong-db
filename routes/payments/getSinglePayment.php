<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try{

$req = $_SERVER['REQUEST_METHOD'];

if($req !== 'GET'){
    throw new Exception("Bad Request, route wasn't found!", 404);
}

$userData =  authenticateUser();
$loggedInUserId = $userData['id'];
$loggedInUserRole = $userData['role'];
$paymentId = $_GET['params'];


if(!$paymentId){
    throw new Exception("PaymentId is required!", 400);
}

if (!is_numeric($paymentId)) {
    throw new Exception("Please enter a valid payment ID", 400);
}
    
if($loggedInUserRole !== 'Admin' && $loggedInUserRole !== "Super_Admin"){
    throw new Exception("Unathourized user!", 401);    
}

$query = 'SELECT * FROM user_payment WHERE id = ? ORDER BY paymentDate DESC';
$stmt = $conn->prepare($query);

if (!$stmt) {
    throw new Exception("Database query preparation failed: " . $conn->error, 500);
}

$stmt->bind_param("i", $paymentId);
$stmt->execute();
$result = $stmt->get_result();
$num = $result->num_rows;


if(!$result){
    throw new Exception("Database execution error: " . $stmt->error, 500);
}

if($num === 0){
    throw new Exception("Payment not found!", 404);
}

$payment = $result->fetch_assoc();

http_response_code(200);
echo json_encode([
    "status" => "Success",
    "message" => "Successfully fetched user payments!",
    "data" => $payment
]);


}catch(Exception $e){
    http_response_code($e->getCode() ?: 500);
    // error_log('Database: ' . $e->getMessage());
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}




?>