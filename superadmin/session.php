<?php
// Super Admin Session Protection
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if super admin is logged in
if (!isset($_SESSION['superadmin_id'])) {
    header('Location: login.php');
    exit;
}

// Optional: Check session timeout (30 minutes)
$timeout_duration = 1800; // 30 minutes in seconds
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY']) > $timeout_duration) {
    session_unset();
    session_destroy();
    header('Location: login.php?timeout=1');
    exit;
}

$_SESSION['LAST_ACTIVITY'] = time(); // Update last activity time

// Set super admin info for use in pages
$superadmin_id = $_SESSION['superadmin_id'];
$superadmin_username = $_SESSION['superadmin_username'] ?? 'SuperAdmin';
$superadmin_name = $_SESSION['superadmin_name'] ?? 'Super Admin';
?>
