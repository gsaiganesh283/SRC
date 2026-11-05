<?php
/**
 * Manage Transactions - Redirects to appropriate portal
 */
require_once 'config/config.php';
require_once 'includes/session.php';

// Redirect to admin or member transactions page based on session
if (isset($_SESSION['admin_id'])) {
    header('Location: admin/manage-transactions.php');
    exit();
} elseif (isset($_SESSION['member_id'])) {
    header('Location: member/transactions.php');
    exit();
} else {
    // Not logged in - redirect to login
    header('Location: auth/admin-login.php');
    exit();
}
?>
