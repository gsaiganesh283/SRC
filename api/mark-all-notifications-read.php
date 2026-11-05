<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

session_start();

$response = ['success' => false];

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
    if (markAllNotificationsRead($userType, $userID)) {
        $response['success'] = true;
    }
}

header('Content-Type: application/json');
echo json_encode($response);
