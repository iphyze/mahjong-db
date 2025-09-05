<?php

require_once __DIR__ . '/vendor/autoload.php';

// Set CORS headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

include_once('includes/connection.php');

// Normalize request URI
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$basePath = '/mahjong-db/api';
$relativePath = str_replace($basePath, '', $requestUri);



$uploadPath = '/utils/imageUploads/mahjong-uploads/';

// Check if the request is for an uploaded image
if (strpos($relativePath, '/utils/imageUploads/mahjong-uploads/') === 0) {
    // Extract the file name from the request
    $filename = basename($relativePath);
    $filePath = $uploadPath . $filename;

    // Check if the file exists
    if (file_exists($filePath)) {
        // Determine MIME type
        $mimeType = mime_content_type($filePath);
        header("Content-Type: $mimeType");
        readfile($filePath); // Output the image
        exit;
    } else {
        http_response_code(404);
        echo json_encode(["message" => "Image not found!"]);
        exit;
    }
}


$routes = [
    '/' => function () {
        echo json_encode(["message" => "Welcome to Mahjong API 😊"]);
    },
    '/welcome' => 'routes/welcome.php',
    '/utils/imageUploads/mahjong-uploads/' => 'routes/games/deletePairs.php',
    '/auth/login' => 'routes/auth/login.php',
    '/auth/register' => 'routes/auth/register.php',
    '/auth/sendVerificationCode' => 'routes/auth/sendVerificationCode.php',
    '/auth/forgotPassword' => 'routes/auth/forgotPassword.php',
    '/auth/verifyEmail' => 'routes/auth/verifyEmail.php',
    '/users/createAdminUser' => 'routes/users/createAdminUser.php',
    '/users/getAllUsers' => 'routes/users/getAllUsers.php',

    '/users/getAllMembers' => 'routes/users/getAllMembers.php',

    '/users/getSubscribedUsers' => 'routes/users/getSubscribedUsers.php',
    '/users/getAllAdminUsers' => 'routes/users/getAllAdminUsers.php',
    '/users/deleteUsers' => 'routes/users/deleteUsers.php',
    '/users/updateUserData' => 'routes/users/updateUserData.php',
    '/users/updateUser' => 'routes/users/updateUser.php',
    '/users/updatePassword' => 'routes/users/updatePassword.php',
    '/notifications/sendNotification' => 'routes/notifications/sendNotification.php',
    '/notifications/getNotifications' => 'routes/notifications/getNotifications.php',
    '/notifications/notificationStatus' => 'routes/notifications/updateNotificationStatus.php',
    '/notifications/updatePushToken' => 'routes/notifications/updatePushToken.php',
    '/payment/createPayment' => 'routes/payments/createPayment.php',
    '/payment/getAllPayments' => 'routes/payments/getAllPayments.php',
    '/payment/getAllSingleUserPayments' => 'routes/payments/getAllSingleUserPayment.php',
    '/game/createGame' => 'routes/games/createGame.php',


    // New Game Routes

    '/game/fetchAllGames' => 'routes/games/fetchAllGames.php',
    '/game/createGameDay' => 'routes/games/createGameDay.php',
    '/game/editGameDay' => 'routes/games/editGameDay.php',
    '/game/deleteGamesDay' => 'routes/games/deleteGamesDay.php',
    '/game/getGameParticipants' => 'routes/games/getGameParticipants.php',
    
    '/game/gameInterest' => 'routes/games/gameInterest.php',
    '/game/updateGameInterest' => 'routes/games/updateGameInterest.php',
    '/game/autoPair' => 'routes/games/autoPair.php',
    '/game/getGamePairs' => 'routes/games/getGamePairs.php',
    '/game/addPlayerManually' => 'routes/games/addPlayerManually.php',
    '/game/removePlayerManually' => 'routes/games/removePlayerManually.php',
    '/game/getUsersGamesAndPairs' => 'routes/games/getUsersGamesAndPairs.php',

    '/game/addUserToGame' => 'routes/games/addUserToGame.php',
    '/game/getAllGamesWithPlayers' => 'routes/games/getAllGamesWithPlayers.php',
    '/game/getAllGames' => 'routes/games/getAllGames.php',
    '/game/getSingleGameUsers' => 'routes/games/getSingleGameUsers.php',
    '/game/getUserPairing' => 'routes/games/getUserPairing.php',
    '/game/updateGame' => 'routes/games/updateGame.php',
    '/game/getAllPairs' => 'routes/games/getAllPairs.php',
    '/game/deletePairs' => 'routes/games/deletePairs.php',
    '/game/deleteGames' => 'routes/games/deleteGames.php',
    '/reports/paymentReport' => 'routes/reports/paymentReport.php',
    '/reports/usersReport' => 'routes/reports/usersReport.php',
];


if (array_key_exists($relativePath, $routes)) {
    if (is_callable($routes[$relativePath])) {
        $routes[$relativePath](); // Execute function
    } else {
        include_once($routes[$relativePath]);
    }
    exit;
}

$dynamicRoutes = [
    '/users/getSingleUser/(.+)' => 'routes/users/getSingleUser.php',
    // '/notifications/getNotifications/(.+)' => 'routes/notifications/getNotifications.php',
    '/payment/getSinglePayment/(.+)' => 'routes/payments/getSinglePayment.php',
    // '/payment/getAllSingleUserPayments/(.+)' => 'routes/payments/getAllSingleUserPayment.php',
    // '/game/getUserPairing/(.+)' => 'routes/games/getUserPairing.php',
];


foreach ($dynamicRoutes as $pattern => $file) {
    if (preg_match('#^' . $pattern . '$#', $relativePath, $matches)) {
        $params = explode('/', $matches[1]);

        // If there's only one parameter, store it as a string, else store as an array
        $_GET['params'] = count($params) === 1 ? $params[0] : $params;
        include_once($file);
        exit;
    }
}

http_response_code(404);
echo json_encode(["message" => "Page not found!"]);
exit;

// Close connection
mysqli_close($conn);

?>