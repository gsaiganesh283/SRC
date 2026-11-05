<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

session_start();

$response = ['count' => 0];

$userType = null;
$userID = null;

if (isset($_SESSION['superadmin_id'])) {
    $userType = 'SuperAdmin';
    $userID = $_SESSION['superadmin_id'];
} elseif (isset($_SESSION['admin_id'])) {
    $userType = 'Admin';
    $userID = $_SESSION['admin_id'];
} elseif (isset($_SESSION['member_id'])) {
    $userType = 'Member';
    $userID = $_SESSION['member_id'];
}

if ($userType && $userID) {
    $response['count'] = getUnreadNotificationCount($userType, $userID);
}

header('Content-Type: application/json');
echo json_encode($response);
