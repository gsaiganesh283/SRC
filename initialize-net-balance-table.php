<?php
/**
 * Initialize member_net_balance table with current calculated values
 * This script populates the member_net_balance table with initial net balance calculations
 * for all existing members based on their transaction history
 */

require_once 'config/config.php';
require_once 'includes/net-balance-helper.php';

try {
    echo "Starting initialization of member_net_balance table...\n";
    
    // Get the active academic year
    $stmt = $pdo->query("SELECT YearID, YearName FROM academic_years WHERE Status = 'Active' LIMIT 1");
    $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$activeYear) {
        echo "Error: No active academic year found in the database.\n";
        echo "Please create an active academic year first.\n";
        exit(1);
    }
    
    $activeYearID = $activeYear['YearID'];
    $activeYearName = $activeYear['YearName'];
    echo "Using Active Year: $activeYearName (ID: $activeYearID)\n\n";
    
    // Get all members
    $stmt = $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberID");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($members)) {
        echo "No members found in the database.\n";
        exit(0);
    }
    
    echo "Found " . count($members) . " members. Calculating net balances...\n\n";
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($members as $member) {
        $memberId = $member['MemberID'];
        $memberName = $member['MemberName'];
        
        try {
            // Calculate net balance using the helper function with active year
            $details = getMemberNetBalanceDetails($memberId, $activeYearID);
            $netBalance = $details['net_balance'] ?? 0;
            
            // Insert or update the balance in member_net_balance table with YearID
            $stmt = $pdo->prepare("
                INSERT INTO member_net_balance (MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy)
                VALUES (?, ?, ?, NOW(), NULL)
                ON DUPLICATE KEY UPDATE 
                    NetBalance = VALUES(NetBalance),
                    LastUpdatedAt = NOW()
            ");
            $stmt->execute([$memberId, $activeYearID, $netBalance]);
            
            echo "✓ Member ID: $memberId | $memberName | Net Balance: ₹" . number_format($netBalance, 2) . "\n";
            $successCount++;
        } catch (Exception $e) {
            echo "✗ Member ID: $memberId | Error: " . $e->getMessage() . "\n";
            $errorCount++;
        }
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "Initialization Complete!\n";
    echo "Successfully initialized: $successCount members\n";
    echo "Errors: $errorCount members\n";
    echo str_repeat("=", 60) . "\n";
    
} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
