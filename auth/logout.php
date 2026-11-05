<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

// Start session
session_start();

// Create audit log for member logout before clearing session
if (isset($_SESSION['member_id']) && isset($_SESSION['member_name'])) {
    createAuditLog(
        $_SESSION['member_id'],
        'Member',
        'Authentication',
        'Member Logout',
        "Member {$_SESSION['member_name']} logged out",
        'members',
        $_SESSION['member_id'],
        null,
        null,
        'Low'
    );
}

// Unset all session variables
session_unset();

// Destroy the session
session_destroy();

// Redirect to member login with logout message
header('Location: member-login.php?logout=success');
exit();
?>