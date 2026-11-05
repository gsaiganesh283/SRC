<?php
// API for Member Lookup
// URL: superadmin/api-member-lookup.php

require_once 'session.php';
require_once '../config/config.php';

header('Content-Type: application/json');

if (!isset($_GET['member_id'])) {
    echo json_encode(['success' => false, 'message' => 'Member ID required']);
    exit;
}

$memberId = intval($_GET['member_id']);

try {
    $stmt = $pdo->prepare("
        SELECT 
            MemberID,
            MemberName,
            AadharNumber,
            Phone,
            Email,
            Address,
            CreatedAt
        FROM members 
        WHERE MemberID = ?
    ");
    
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($member) {
        // Get transaction count for this member
        $transStmt = $pdo->prepare("SELECT COUNT(*) as count FROM transactions WHERE MemberID = ?");
        $transStmt->execute([$memberId]);
        $transCount = $transStmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Get total collection amount
        $collStmt = $pdo->prepare("SELECT SUM(CollectionAmount) as total FROM transactions WHERE MemberID = ?");
        $collStmt->execute([$memberId]);
        $totalCollection = $collStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
        echo json_encode([
            'success' => true,
            'member' => $member,
            'stats' => [
                'transaction_count' => $transCount,
                'total_collection' => number_format($totalCollection, 2)
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Member not found'
        ]);
    }
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
