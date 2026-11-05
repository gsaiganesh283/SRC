<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireMemberLogin();

$memberId = $_SESSION['member_id'];

// Update last login time
try {
    $stmt = $pdo->prepare("UPDATE members SET LastLogin = NOW() WHERE MemberID = ?");
    $stmt->execute([$memberId]);
} catch (Exception $e) {
    // Silent fail
}

// Redirect to new dashboard
header("Location: dashboard-new.php");
exit();
?>