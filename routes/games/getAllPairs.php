<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');


try{

$req = $_SERVER['REQUEST_METHOD'] === "GET";

if(!$req){
    throw new Exception("Bad Request, route wasn't found!", 404);
}


$userData = authenticateUser();
$loggedInUserRole = $userData['role'];

if($loggedInUserRole !== 'Admin' && $loggedInUserRole !== "Super_Admin"){
    throw new Exception("Unauthorized access!", 401);
}

$getPairsQuery = "SELECT * FROM pairs ORDER BY createdAt DESC";
$result = $conn->query($getPairsQuery);

if(!$result){
    throw new Exception("Database Fetch Error" . $stmt->error, 500);
}

$results = $result->fetch_all(MYSQLI_ASSOC);

// $getData = mysqli_query($conn, "SELECT * FROM pairs ORDER BY createdAt DESC");
// $gottenData = mysqli_fetch_assoc($getData);

// $results = [];

// foreach($getData as $gottenData){
//     $results[] = $gottenData;
// }

http_response_code(200);
echo json_encode([
    "status" => "Success",
    "message" => "Pairs fetched successfully",
    "data" => $results
]);

}catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        "status" => "Failed",
        "message" => $e->getMessage()
    ]);
}

?>