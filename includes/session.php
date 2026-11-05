<?php
/**
 * Session Management for SR Colony Chitti Management System
 */

// Check if there's a custom session name in cookie (for admin/member impersonation)
if (session_status() === PHP_SESSION_NONE) {
    // Determine which session to use based on the current script path
    $currentScript = $_SERVER['SCRIPT_NAME'] ?? '';
    $isMemberPortal = (strpos($currentScript, '/member/') !== false);
    $isAdminPortal = (strpos($currentScript, '/admin/') !== false);
    
    if ($isMemberPortal && isset($_COOKIE['member_session_name'])) {
        session_name($_COOKIE['member_session_name']);
    } elseif ($isAdminPortal && isset($_COOKIE['admin_session_name'])) {
        session_name($_COOKIE['admin_session_name']);
    } elseif (isset($_COOKIE['admin_session_name']) && !$isMemberPortal) {
        session_name($_COOKIE['admin_session_name']);
    } elseif (isset($_COOKIE['member_session_name']) && !$isAdminPortal) {
        session_name($_COOKIE['member_session_name']);
    }
    session_start();
}

// Set session timeout (30 minutes)
$session_timeout = 1800;

if (isset($_SESSION['last_activity'])) {
    $elapsed_time = time() - $_SESSION['last_activity'];
    
    if ($elapsed_time > $session_timeout) {
        session_unset();
        session_destroy();
        
        // Check if BASE_URL is defined
        $redirectUrl = defined('BASE_URL') ? BASE_URL . '/auth/member-login.php?timeout=1' : '../auth/member-login.php?timeout=1';
        header('Location: ' . $redirectUrl);
        exit();
    }
}

$_SESSION['last_activity'] = time();

// Regenerate session ID periodically for security
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} else if (time() - $_SESSION['created'] > 600) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}
?>