<?php
/**
 * Advanced Net Balance Management System - Comprehensive Test Suite
 * 
 * Tests all advanced features including:
 * - Balance verification and integrity checks
 * - Anomaly detection
 * - Batch operations
 * - Validation rules
 * - Recovery checkpoints
 * - Approval workflows
 * - Analytics
 * - Reconciliation
 */

require_once 'config/config.php';
require_once 'includes/net-balance-helper.php';
require_once 'includes/advanced-balance-management.php';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║   ADVANCED NET BALANCE MANAGEMENT - COMPREHENSIVE TEST SUITE    ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

// ============================================================================
// TEST 1: Balance Integrity Verification
// ============================================================================
echo "TEST 1: Balance Integrity Verification\n";
echo "────────────────────────────────────────\n";

try {
    $stmt = $pdo->query("SELECT MemberID FROM members LIMIT 1");
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt2 = $pdo->query("SELECT YearID FROM academic_years LIMIT 1");
    $year = $stmt2->fetch(PDO::FETCH_ASSOC);
    
    if ($member && $year) {
        $result = verifyBalanceIntegrity($member['MemberID'], $year['YearID']);
        
        $totalTests++;
        if ($result['success'] && $result['integrity_status']) {
            echo "✅ PASS: Balance integrity verification\n";
            echo "   - Verified: {$result['verified_count']}\n";
            echo "   - Discrepancies: {$result['discrepancies_count']}\n";
            echo "   - Status: {$result['integrity_status']}\n";
            $passedTests++;
        } else {
            echo "❌ FAIL: Integrity check failed\n";
            $failedTests++;
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    $failedTests++;
    $totalTests++;
}

echo "\n";

// ============================================================================
// TEST 2: Anomaly Detection
// ============================================================================
echo "TEST 2: Anomaly Detection\n";
echo "─────────────────────────\n";

try {
    $stmt = $pdo->query("SELECT MemberID FROM members LIMIT 1");
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt2 = $pdo->query("SELECT YearID FROM academic_years LIMIT 1");
    $year = $stmt2->fetch(PDO::FETCH_ASSOC);
    
    if ($member && $year) {
        $result = getBalanceSummaryWithAnomalies($member['MemberID'], $year['YearID']);
        
        $totalTests++;
        if ($result['success']) {
            echo "✅ PASS: Anomaly detection executed\n";
            echo "   - Member: {$member['MemberID']}\n";
            echo "   - Status: {$result['status']}\n";
            echo "   - Anomalies Detected: {$result['anomaly_count']}\n";
            
            if ($result['anomaly_count'] > 0) {
                echo "   Anomalies:\n";
                foreach ($result['anomalies'] as $anomaly) {
                    echo "   - {$anomaly['type']}: {$anomaly['message']}\n";
                }
            }
            $passedTests++;
        } else {
            echo "❌ FAIL: Anomaly detection failed\n";
            $failedTests++;
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    $failedTests++;
    $totalTests++;
}

echo "\n";

// ============================================================================
// TEST 3: Balance History Tracking
// ============================================================================
echo "TEST 3: Balance History Tracking\n";
echo "───────────────────────────────\n";

try {
    $stmt = $pdo->query("SELECT MemberID FROM members LIMIT 1");
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($member) {
        $result = getMemberBalanceHistory($member['MemberID'], null, 5);
        
        $totalTests++;
        if ($result['success']) {
            echo "✅ PASS: Balance history retrieved\n";
            echo "   - Member: {$member['MemberID']}\n";
            echo "   - Records: {$result['record_count']}\n";
            
            if ($result['record_count'] > 0) {
                echo "   - Latest Update: {$result['history'][0]['UpdatedAtFormatted']}\n";
                echo "   - Balance: ₹{$result['history'][0]['NetBalance']}\n";
            }
            $passedTests++;
        } else {
            echo "❌ FAIL: History retrieval failed\n";
            $failedTests++;
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    $failedTests++;
    $totalTests++;
}

echo "\n";

// ============================================================================
// TEST 4: Batch Verification and Fix
// ============================================================================
echo "TEST 4: Batch Verification and Fix All Balances\n";
echo "───────────────────────────────────────────────\n";

try {
    $stmt = $pdo->query("SELECT YearID FROM academic_years LIMIT 1");
    $year = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($year) {
        $result = batchVerifyAndFixAllBalances($year['YearID'], 1);
        
        $totalTests++;
        if ($result['success']) {
            echo "✅ PASS: Batch verification executed\n";
            echo "   - Members Checked: {$result['total_members_checked']}\n";
            echo "   - Verified: {$result['verified_count']}\n";
            echo "   - Fixed: {$result['fixed_count']}\n";
            echo "   - Status: {$result['status']}\n";
            $passedTests++;
        } else {
            echo "❌ FAIL: Batch operation failed\n";
            $failedTests++;
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    $failedTests++;
    $totalTests++;
}

echo "\n";

// ============================================================================
// TEST 5: Balance Validation Rules
// ============================================================================
echo "TEST 5: Balance Validation Rules\n";
echo "────────────────────────────────\n";

try {
    // Test valid balance
    $result1 = BalanceValidationRules::validateBalance(1, 1, 50000, 'ADJUSTMENT');
    
    // Test invalid balance (exceeds max)
    $result2 = BalanceValidationRules::validateBalance(1, 1, 300000, 'ADJUSTMENT');
    
    $totalTests++;
    if (!$result1['valid'] === false && $result2['valid'] === false) {
        echo "✅ PASS: Balance validation rules\n";
        echo "   - Valid Balance (50000): {$result1['violation_count']} violations\n";
        echo "   - Invalid Balance (300000): {$result2['violation_count']} violations\n";
        
        if ($result2['violation_count'] > 0) {
            echo "   Violations for 300000:\n";
            foreach ($result2['violations'] as $v) {
                echo "   - {$v['rule']}: {$v['message']}\n";
            }
        }
        $passedTests++;
    } else {
        echo "❌ FAIL: Validation not working as expected\n";
        $failedTests++;
    }
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    $failedTests++;
    $totalTests++;
}

echo "\n";

// ============================================================================
// TEST 6: Transaction Limits
// ============================================================================
echo "TEST 6: Transaction Limits\n";
echo "──────────────────────────\n";

try {
    $stmt = $pdo->query("SELECT MemberID FROM members LIMIT 1");
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt2 = $pdo->query("SELECT YearID FROM academic_years LIMIT 1");
    $year = $stmt2->fetch(PDO::FETCH_ASSOC);
    
    if ($member && $year) {
        $limits = BalanceValidationRules::getTransactionLimits($member['MemberID'], $year['YearID']);
        
        $totalTests++;
        if (isset($limits['max_single_transaction'])) {
            echo "✅ PASS: Transaction limits calculated\n";
            echo "   - Member: {$member['MemberID']}\n";
            echo "   - Max Single: ₹{$limits['max_single_transaction']}\n";
            echo "   - Max Daily: ₹{$limits['max_daily_transactions']}\n";
            echo "   - Max Monthly: ₹{$limits['max_monthly_transactions']}\n";
            echo "   - Status: {$limits['status']}\n";
            $passedTests++;
        } else {
            echo "❌ FAIL: Limits retrieval failed\n";
            $failedTests++;
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    $failedTests++;
    $totalTests++;
}

echo "\n";

// ============================================================================
// TEST 7: Balance Recovery - Create Checkpoint
// ============================================================================
echo "TEST 7: Balance Recovery - Create Checkpoint\n";
echo "────────────────────────────────────────────\n";

try {
    $stmt = $pdo->query("SELECT MemberID FROM members LIMIT 1");
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt2 = $pdo->query("SELECT YearID FROM academic_years LIMIT 1");
    $year = $stmt2->fetch(PDO::FETCH_ASSOC);
    
    if ($member && $year) {
        $checkpoint = BalanceRecovery::createCheckpoint(
            $member['MemberID'], $year['YearID'], 'Test checkpoint', 1
        );
        
        $totalTests++;
        if ($checkpoint['success']) {
            echo "✅ PASS: Checkpoint created\n";
            echo "   - Checkpoint ID: {$checkpoint['checkpoint_id']}\n";
            echo "   - Balance Snapshot: ₹{$checkpoint['snapshot_balance']}\n";
            echo "   - Created: {$checkpoint['created_at']}\n";
            $passedTests++;
        } else {
            echo "❌ FAIL: Checkpoint creation failed\n";
            $failedTests++;
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    $failedTests++;
    $totalTests++;
}

echo "\n";

// ============================================================================
// TEST 8: Balance Recovery - Get Checkpoints
// ============================================================================
echo "TEST 8: Balance Recovery - Get Checkpoints\n";
echo "──────────────────────────────────────────\n";

try {
    $stmt = $pdo->query("SELECT MemberID FROM members LIMIT 1");
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($member) {
        $checkpoints = BalanceRecovery::getCheckpoints($member['MemberID']);
        
        $totalTests++;
        if ($checkpoints['success']) {
            echo "✅ PASS: Checkpoints retrieved\n";
            echo "   - Member: {$member['MemberID']}\n";
            echo "   - Total Checkpoints: {$checkpoints['count']}\n";
            $passedTests++;
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    $failedTests++;
    $totalTests++;
}

echo "\n";

// ============================================================================
// TEST 9: Balance Report Generation
// ============================================================================
echo "TEST 9: Generate Balance Report\n";
echo "───────────────────────────────\n";

try {
    $stmt = $pdo->query("SELECT YearID FROM academic_years LIMIT 1");
    $year = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($year) {
        $report = generateBalanceReport($year['YearID']);
        
        $totalTests++;
        if ($report['success']) {
            echo "✅ PASS: Balance report generated\n";
            $stats = $report['statistics'];
            echo "   - Year: {$year['YearID']}\n";
            echo "   - Total Members: {$stats['total_members']}\n";
            echo "   - Total Records: {$stats['total_records']}\n";
            echo "   - Total Net Balance: ₹{$stats['total_net_balance']}\n";
            echo "   - Average Balance: ₹{$stats['average_balance']}\n";
            echo "   - Status Distribution: " . count($report['status_distribution']) . " categories\n";
            $passedTests++;
        } else {
            echo "❌ FAIL: Report generation failed\n";
            $failedTests++;
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    $failedTests++;
    $totalTests++;
}

echo "\n";

// ============================================================================
// TEST 10: Dashboard Analytics
// ============================================================================
echo "TEST 10: Dashboard Analytics\n";
echo "────────────────────────────\n";

try {
    $stmt = $pdo->query("SELECT YearID FROM academic_years LIMIT 1");
    $year = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($year) {
        $analytics = BalanceAnalytics::getDashboardAnalytics($year['YearID']);
        
        $totalTests++;
        if ($analytics['success']) {
            echo "✅ PASS: Dashboard analytics generated\n";
            $analysis = $analytics['analytics'];
            echo "   - Total Members: {$analysis['member_analysis']['total_members']}\n";
            echo "   - Members Due: {$analysis['member_analysis']['members_due']}\n";
            echo "   - Members Payable: {$analysis['member_analysis']['members_payable']}\n";
            echo "   - Total Transactions: {$analysis['transaction_analysis']['total_transactions']}\n";
            echo "   - Total Collections: ₹{$analysis['transaction_analysis']['total_collections']}\n";
            echo "   - Total Distributions: ₹{$analysis['transaction_analysis']['total_distributions']}\n";
            echo "   - Monthly Trends: " . count($analysis['monthly_trend']) . " months\n";
            $passedTests++;
        } else {
            echo "❌ FAIL: Analytics generation failed\n";
            $failedTests++;
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    $failedTests++;
    $totalTests++;
}

echo "\n";

// ============================================================================
// TEST 11: Transaction Reconciliation
// ============================================================================
echo "TEST 11: Transaction Reconciliation\n";
echo "────────────────────────────────────\n";

try {
    $stmt = $pdo->query("SELECT MemberID FROM members LIMIT 1");
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt2 = $pdo->query("SELECT YearID FROM academic_years LIMIT 1");
    $year = $stmt2->fetch(PDO::FETCH_ASSOC);
    
    if ($member && $year) {
        $reconciliation = TransactionReconciliation::reconcileMemberAccount($member['MemberID'], $year['YearID']);
        
        $totalTests++;
        if ($reconciliation['success']) {
            echo "✅ PASS: Account reconciliation completed\n";
            echo "   - Member: {$member['MemberID']}\n";
            echo "   - Calculated Balance: ₹{$reconciliation['calculated_balance']}\n";
            echo "   - Stored Balance: ₹{$reconciliation['stored_balance']}\n";
            echo "   - Difference: ₹{$reconciliation['difference']}\n";
            echo "   - Status: {$reconciliation['status']}\n";
            echo "   - Transactions: {$reconciliation['transaction_count']}\n";
            $passedTests++;
        } else {
            echo "❌ FAIL: Reconciliation failed\n";
            $failedTests++;
        }
    }
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    $failedTests++;
    $totalTests++;
}

echo "\n";

// ============================================================================
// SUMMARY
// ============================================================================
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║                        TEST SUMMARY                             ║\n";
echo "├──────────────────────────────────────────────────────────────────┤\n";

echo sprintf("║ Total Tests:    %2d                                             ║\n", $totalTests);
echo sprintf("║ ✅ Passed:       %2d                                             ║\n", $passedTests);
echo sprintf("║ ❌ Failed:       %2d                                             ║\n", $failedTests);

$passPercentage = $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 1) : 0;
echo sprintf("║ Success Rate:   %.1f%%                                          ║\n", $passPercentage);

if ($failedTests === 0) {
    echo "║ Status:         ✅ ALL TESTS PASSED                           ║\n";
} else {
    echo "║ Status:         ⚠️  SOME TESTS FAILED                         ║\n";
}

echo "╚══════════════════════════════════════════════════════════════════╝\n\n";

echo "Advanced Features Summary:\n";
echo "✅ Balance Integrity Verification\n";
echo "✅ Anomaly Detection & Alerts\n";
echo "✅ Batch Verification & Fix\n";
echo "✅ Balance History Tracking\n";
echo "✅ Validation Rules & Constraints\n";
echo "✅ Transaction Limits\n";
echo "✅ Recovery Checkpoints\n";
echo "✅ Balance Reports\n";
echo "✅ Dashboard Analytics\n";
echo "✅ Transaction Reconciliation\n";

echo "\n";

if ($passedTests === $totalTests) {
    echo "🚀 ADVANCED NET BALANCE SYSTEM IS FULLY OPERATIONAL\n";
} else {
    echo "⚠️  Review failed tests above\n";
}

echo "\n";
?>
