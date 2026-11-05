<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Check permission to delete members
requireAdminPermission('delete_members', 'You do not have permission to delete members');

// Check if ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: manage-members.php?error=invalid_id');
    exit;
}

$memberID = intval($_GET['id']);

try {
    // Check if member exists
    $stmt = $pdo->prepare("SELECT MemberID, MemberName FROM members WHERE MemberID = ?");
    $stmt->execute([$memberID]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        header('Location: manage-members.php?error=member_not_found');
        exit;
    }
    
    // Check if member has any transactions
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM transactions WHERE MemberID = ?");
    $stmt->execute([$memberID]);
    $transactionCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($transactionCount > 0) {
        // Don't delete, just mark as inactive
        $stmt = $pdo->prepare("UPDATE members SET Status = 'Inactive' WHERE MemberID = ?");
        $stmt->execute([$memberID]);
        
        // Create audit log for member deactivation
        createAuditLog(
            $_SESSION['admin_id'],
            'Admin',
            'Members',
            'Deactivate Member',
            "Deactivated member: {$member['MemberName']} (ID: $memberID) - Has $transactionCount transaction(s)",
            'members',
            $memberID,
            json_encode(['Status' => 'Active']),
            json_encode(['Status' => 'Inactive']),
            'High'
        );
        
        header('Location: manage-members.php?success=member_deactivated&transactions=' . $transactionCount);
        exit;
    } else {
        // Safe to delete - no transactions
        $stmt = $pdo->prepare("DELETE FROM members WHERE MemberID = ?");
        $stmt->execute([$memberID]);
        
        // Create audit log for member deletion
        createAuditLog(
            $_SESSION['admin_id'],
            'Admin',
            'Members',
            'Delete Member',
            "Deleted member: {$member['MemberName']} (ID: $memberID)",
            'members',
            $memberID,
            json_encode(['MemberName' => $member['MemberName']]),
            null,
            'Critical'
        );
        
        header('Location: manage-members.php?success=member_deleted');
        exit;
    }
    
} catch (Exception $e) {
    error_log("Error deleting member: " . $e->getMessage());
    header('Location: manage-members.php?error=delete_failed');
    exit;
}
?>
