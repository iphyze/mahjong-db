<?php 
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

try{

    if($_SERVER['REQUEST_METHOD'] !== 'GET'){
        throw new Exception("Bad Request, route wasn't found!", 404);
    }
    
    
    $userData = authenticateUser();
    $loggedInUserRole = $userData['role'];
    
    if($loggedInUserRole != 'Admin' && $loggedInUserRole !== "Super_Admin"){
        throw new Exception("Access Denied, unauthorized user!", 401);
    }

    $query = "SELECT * FROM user_payment ORDER BY paymentDate DESC";
    $stmt = $conn->prepare($query);

    if (!$stmt) {
        throw new Exception("Database query preparation failed: " . $conn->error);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result) {
        throw new Exception("Database execution error: " . $stmt->error);
    }

    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Successfully fetched all payments",
        "data" => $payments
    ]);


}catch(Exception $e){
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}



?>
