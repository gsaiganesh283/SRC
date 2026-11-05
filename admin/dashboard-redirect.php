<?php
// Admin Dashboard - Redirect to New Modern Dashboard
// This file provides backward compatibility for old links
require_once '../includes/session.php';

// Verify admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: ../auth/admin-login.php');
    exit;
}

// Preserve any query parameters
$queryString = '';
if (!empty($_SERVER['QUERY_STRING'])) {
    $queryString = '?' . $_SERVER['QUERY_STRING'];
}

// Redirect to the new enhanced dashboard
header('Location: dashboard-new.php' . $queryString, true, 302); // 302 Found for temporary redirect
exit;
?>
