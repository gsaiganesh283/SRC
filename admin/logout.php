<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

session_start();

// Create audit log for admin logout before clearing session
if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_username'])) {
    createAuditLog(
        $_SESSION['admin_id'],
        'Admin',
        'Authentication',
        'Admin Logout',
        "Admin {$_SESSION['admin_username']} logged out",
        'admins',
        $_SESSION['admin_id'],
        null,
        null,
        'Low'
    );
}

// Clear admin session variables
unset($_SESSION['admin_logged_in']);
unset($_SESSION['admin_id']);
unset($_SESSION['admin_username']);
unset($_SESSION['admin_role']);

session_destroy();

// Redirect to admin login with logout message
header('Location: ../auth/admin-login.php?logout=success');
exit();
?>