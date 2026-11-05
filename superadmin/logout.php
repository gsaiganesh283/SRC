<?php
session_start();
require_once '../config/config.php';

// Log the logout activity
if (isset($_SESSION['superadmin_id'])) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (UserType, UserID, Username, Action, IPAddress) 
            VALUES ('SuperAdmin', ?, ?, 'Logged Out', ?)
        ");
        $stmt->execute([
            $_SESSION['superadmin_id'], 
            $_SESSION['superadmin_username'] ?? 'Unknown',
            $ip
        ]);
    } catch (Exception $e) {
        // Silently fail
    }
}

// Destroy session
session_unset();
session_destroy();

header('Location: login.php');
exit;
?>
