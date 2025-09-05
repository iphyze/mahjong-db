<?php
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';
use Respect\Validation\Validator as v;
header('Content-Type: application/json');

try{

$req = $_SERVER['REQUEST_METHOD'] === 'POST';

if(!$req){
    throw new Exception("Bad Request, route wasn't found!", 404);     
}

$userData = authenticateUser();
$loggedInUserRole = $userData['role'];
$loggedInUserEmail = $userData['email'];

if($loggedInUserRole != 'Admin' && $loggedInUserRole !== "Super_Admin"){
    throw new Exception("Access Denied, unauthorized user!", 401);
}

$data = json_decode(file_get_contents("php://input"), true);

$groupName = trim($data['groupName']);
// $userEmail = trim(strtolower($data['userEmail']));
$userEmail = $loggedInUserEmail;
$createdBy = $loggedInUserEmail;
$updatedBy = $loggedInUserEmail;
$gameStatus = "Unplayed";

$emailValidator = v::email();


// if(empty($userEmail)){
//     throw new Exception("User Email is required!", 400);
// }

// if(!$emailValidator->validate($userEmail)){
//     throw new Exception("Invalid email format", 400);
// }

if(empty($groupName)){
    throw new Exception("Group Name is required!", 400);
}


$checkGameQuery = 'SELECT groupName FROM games WHERE groupName = ?';
$stmt = $conn->prepare($checkGameQuery);

if(!$stmt){
    throw new Exception("Database Preparation Error " . $conn->error);
}

$stmt->bind_param("s", $groupName);
$stmt->execute();
$result = $stmt->get_result();
$num = $result->num_rows;

if(!$result){
    throw new Exception("Database Execution error: " . $stmt->error, 500);
}

if($num > 0){
    throw new Exception($groupName . " already exists on the game lists!", 400);
}


$insertGameQuery = "INSERT INTO games (groupName, createdBy, updatedBy, gameStatus) VALUES (?, ?, ?, ?)";
$insertstmt = $conn->prepare($insertGameQuery);

if(!$insertstmt){
    throw new Exception("Database Preparation Error" . $conn->error, 500);
}

$insertstmt->bind_param("ssss", $groupName, $createdBy, $updatedBy, $gameStatus);
$insert = $insertstmt->execute();


if($insert){
    $gameData = [
            "gameId" => $insertstmt->insert_id, 
            "groupName" => $groupName, 
            "gameStatus" => $gameStatus, 
            "createdBy" => $createdBy,
            "updatedBy" => $updatedBy, 
        ];

    http_response_code(200);
    echo json_encode([
        "status" => "Success",
        "message" => "Game successfully created!",
        "data" => $gameData
    ]);
}else{
    throw new Exception('Database insertion error ' . $insertstmt->error, 500);
}

}catch(Exception $e){
 
http_response_code($e->getCode() ?: 500);
echo json_encode([
    "status" => "Failed",
    "message" => $e->getMessage()
]);    

}



?>