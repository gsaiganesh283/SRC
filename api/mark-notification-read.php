<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

// Check if user is logged in
session_start();

$response = ['success' => false];

if (!isset($_POST) || empty(file_get_contents('php://input'))) {
    echo json_encode($response);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['notificationId'])) {
    echo json_encode($response);
    exit;
}

$notificationId = (int)$data['notificationId'];

// Mark as read
if (markNotificationRead($notificationId)) {
    $response['success'] = true;
}

header('Content-Type: application/json');
echo json_encode($response);
