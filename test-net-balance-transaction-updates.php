<?php
/**
 * Test Script: Net Balance Calculation on Transaction Updates
 * 
 * This script tests the net balance recalculation logic when transactions are updated
 * It verifies that:
 * 1. Updating a transaction recalculates the correct member's balance
 * 2. Changing a transaction's YearID recalculates both old and new year
 * 3. Changing a transaction's MemberID recalculates both old and new member
 * 4. All affected member-year combinations are updated
 */

require_once 'config/config.php';
require_once 'includes/net-balance-helper.php';

echo "=== NET BALANCE TRANSACTION UPDATE TESTS ===\n\n";

// Test 1: Basic scenario - Transaction amount change
echo "TEST 1: Update Transaction Amount (Same Member, Same Year)\n";
echo "-----------------------------------------------\n";

try {
    // Get a member with transactions
    $stmt = $pdo->query("
        SELECT DISTINCT t.MemberID, t.YearID, m.MemberName
        FROM transactions t
        JOIN members m ON t.MemberID = m.MemberID
        LIMIT 1
    ");
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($trans) {
        $memberId = $trans['MemberID'];
        $yearId = $trans['YearID'];
        
        // Get current balance
        $beforeBalance = calculateMemberNetBalance($memberId, $yearId);
        echo "✓ Member: {$trans['MemberName']} (ID: {$memberId})\n";
        echo "  Year ID: {$yearId}\n";
        echo "  Balance Before: ₹{$beforeBalance}\n";
        
        // Get first transaction for this member
        $txnStmt = $pdo->prepare("
            SELECT * FROM transactions 
            WHERE MemberID = ? AND YearID = ?
            LIMIT 1
        ");
        $txnStmt->execute([$memberId, $yearId]);
        $txn = $txnStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($txn) {
            $txnId = $txn['TransactionID'];
            $oldCollection = $txn['CollectionAmount'];
            $oldDistribution = $txn['DistributedAmount'];
            
            // Simulate an update - add ₹1000 to collection
            $newCollection = $oldCollection + 1000;
            $updateStmt = $pdo->prepare("
                UPDATE transactions 
                SET CollectionAmount = ?
                WHERE TransactionID = ?
            ");
            $updateStmt->execute([$newCollection, $txnId]);
            
            // Recalculate
            $afterBalance = calculateMemberNetBalance($memberId, $yearId);
            echo "  Old Collection: ₹{$oldCollection}, New Collection: ₹{$newCollection}\n";
            echo "  Balance After: ₹{$afterBalance}\n";
            
            // Expected: balance should decrease by 1000 (because collection increased)
            $expectedDifference = -1000;
            $actualDifference = $afterBalance - $beforeBalance;
            
            if (abs($actualDifference - $expectedDifference) < 0.01) {
                echo "  ✅ PASS: Balance changed correctly by ₹{$actualDifference}\n";
            } else {
                echo "  ❌ FAIL: Expected ₹{$expectedDifference}, got ₹{$actualDifference}\n";
            }
            
            // Restore original value
            $restoreStmt = $pdo->prepare("
                UPDATE transactions 
                SET CollectionAmount = ?
                WHERE TransactionID = ?
            ");
            $restoreStmt->execute([$oldCollection, $txnId]);
        }
    } else {
        echo "❌ No transactions found for testing\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: Year change scenario
echo "TEST 2: Helper Function - updateNetBalanceAfterTransactionChange()\n";
echo "-----------------------------------------------\n";

try {
    // Get two different years
    $yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years LIMIT 2");
    $years = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($years) >= 2) {
        $oldYearId = $years[0]['YearID'];
        $newYearId = $years[1]['YearID'];
        
        // Get a member
        $memberStmt = $pdo->query("SELECT MemberID, MemberName FROM members LIMIT 1");
        $member = $memberStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($member) {
            $memberId = $member['MemberID'];
            
            echo "✓ Testing balance update for member: {$member['MemberName']}\n";
            echo "  Scenario: Simulating year change from Year {$oldYearId} to Year {$newYearId}\n";
            echo "  (Using helper function updateNetBalanceAfterTransactionChange)\n";
            
            // Call the helper function
            $result = updateNetBalanceAfterTransactionChange(
                $memberId,        // oldMemberID
                $oldYearId,        // oldYearID
                $memberId,         // newMemberID (same)
                $newYearId,        // newYearID (different)
                1                  // updatedByUserID
            );
            
            if ($result['success']) {
                echo "  ✅ Helper function executed successfully\n";
                echo "  Combinations updated: {$result['combinations_updated']}\n";
                foreach ($result['updates'] as $update) {
                    echo "    - Member {$update['MemberID']}, Year {$update['YearID']}: ₹{$update['NetBalance']}\n";
                }
            } else {
                echo "  ❌ FAIL: " . $result['error'] . "\n";
            }
        }
    } else {
        echo "❌ Need at least 2 academic years for this test\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Member change scenario
echo "TEST 3: Helper Function - Simulate Member Change\n";
echo "-----------------------------------------------\n";

try {
    // Get two different members
    $membersStmt = $pdo->query("SELECT MemberID, MemberName FROM members LIMIT 2");
    $members = $membersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($members) >= 2) {
        $oldMemberId = $members[0]['MemberID'];
        $newMemberId = $members[1]['MemberID'];
        
        // Get a year
        $yearStmt = $pdo->query("SELECT YearID FROM academic_years LIMIT 1");
        $year = $yearStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($year) {
            $yearId = $year['YearID'];
            
            echo "✓ Scenario: Changing transaction from {$members[0]['MemberName']} to {$members[1]['MemberName']}\n";
            echo "  Year: {$yearId}\n";
            
            // Call the helper function
            $result = updateNetBalanceAfterTransactionChange(
                $oldMemberId,      // oldMemberID
                $yearId,           // oldYearID (same)
                $newMemberId,      // newMemberID (different)
                $yearId,           // newYearID (same)
                1                  // updatedByUserID
            );
            
            if ($result['success']) {
                echo "  ✅ Helper function executed successfully\n";
                echo "  Combinations updated: {$result['combinations_updated']}\n";
                foreach ($result['updates'] as $update) {
                    echo "    - Member {$update['MemberID']}, Year {$update['YearID']}: ₹{$update['NetBalance']}\n";
                }
            } else {
                echo "  ❌ FAIL: " . $result['error'] . "\n";
            }
        }
    } else {
        echo "❌ Need at least 2 members for this test\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 4: Verify calculation formula
echo "TEST 4: Verify Net Balance Calculation Formula\n";
echo "-----------------------------------------------\n";

try {
    // Get a random member with transactions
    $stmt = $pdo->query("
        SELECT DISTINCT t.MemberID, t.YearID
        FROM transactions t
        WHERE t.MemberID > 0
        LIMIT 1
    ");
    $trans = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($trans) {
        $memberId = $trans['MemberID'];
        $yearId = $trans['YearID'];
        
        // Get transaction totals
        $totalsStmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CollectionAmount), 0) as total_collection,
                COALESCE(SUM(DistributedAmount), 0) as total_distribution,
                COALESCE(SUM(InterestAmount), 0) as total_interest,
                COUNT(*) as transaction_count
            FROM transactions
            WHERE MemberID = ? AND YearID = ?
        ");
        $totalsStmt->execute([$memberId, $yearId]);
        $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);
        
        $netBalance = calculateMemberNetBalance($memberId, $yearId);
        
        // Verify formula: (Distribution + Interest) - Collection
        $expectedBalance = ($totals['total_distribution'] + $totals['total_interest']) - $totals['total_collection'];
        
        echo "✓ Member ID: {$memberId}, Year ID: {$yearId}\n";
        echo "  Total Distribution: ₹{$totals['total_distribution']}\n";
        echo "  Total Interest: ₹{$totals['total_interest']}\n";
        echo "  Total Collection: ₹{$totals['total_collection']}\n";
        echo "  Total Transactions: {$totals['transaction_count']}\n";
        echo "  Formula: (Distribution + Interest) - Collection\n";
        echo "           ({$totals['total_distribution']} + {$totals['total_interest']}) - {$totals['total_collection']}\n";
        echo "  Calculated Net Balance: ₹{$netBalance}\n";
        echo "  Expected Balance: ₹{$expectedBalance}\n";
        
        if (abs($netBalance - $expectedBalance) < 0.01) {
            echo "  ✅ PASS: Formula verified correctly\n";
        } else {
            echo "  ❌ FAIL: Mismatch in calculation\n";
        }
    } else {
        echo "❌ No transactions found\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 5: Recalculate all transactions for a member
echo "TEST 5: Helper Function - recalculateAllTransactionsForMember()\n";
echo "-----------------------------------------------\n";

try {
    // Get a member with transactions in multiple years
    $stmt = $pdo->query("
        SELECT t.MemberID, m.MemberName, COUNT(DISTINCT t.YearID) as year_count
        FROM transactions t
        JOIN members m ON t.MemberID = m.MemberID
        GROUP BY t.MemberID, m.MemberName
        HAVING year_count > 1
        LIMIT 1
    ");
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($member) {
        echo "✓ Member: {$member['MemberName']} (ID: {$member['MemberID']})\n";
        echo "  Transactions across {$member['year_count']} years\n";
        
        $result = recalculateAllTransactionsForMember($member['MemberID'], 1);
        
        if ($result['success']) {
            echo "  ✅ Successfully recalculated\n";
            echo "  Years affected: {$result['years_affected']}\n";
            foreach ($result['details'] as $detail) {
                echo "    - Year {$detail['YearID']}: ₹{$detail['NetBalance']}\n";
            }
        } else {
            echo "  ❌ FAIL: " . $result['error'] . "\n";
        }
    } else {
        echo "❌ No members with transactions in multiple years\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Summary
echo "=== TEST SUMMARY ===\n";
echo "✓ All net balance recalculation tests completed\n";
echo "✓ Helper functions are available for transaction updates\n";
echo "✓ Calculation formula: (Distribution + Interest) - Collection\n";
echo "\nKey Points:\n";
echo "• Use updateNetBalanceAfterTransactionChange() for transaction updates\n";
echo "• Always recalculate for both affected members and years\n";
echo "• Use recalculateAllTransactionsForMember() for bulk recalculations\n";
echo "• All changes are logged in member_net_balance table\n";
?>