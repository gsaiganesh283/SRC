# Net Balance Calculation - Implementation Guide

## Quick Start for Developers

This guide explains how to properly handle net balance calculations when updating transactions.

## 1. When Editing a Transaction

### CORRECT WAY (Fixed in admin/edit-transaction.php)

```php
<?php
require_once 'includes/net-balance-helper.php';

// STEP 1: Fetch OLD transaction data BEFORE any changes
$oldTransaction = $pdo->prepare("SELECT MemberID, YearID FROM transactions WHERE TransactionID = ?");
$oldTransaction->execute([$transactionID]);
$oldTxn = $oldTransaction->fetch(PDO::FETCH_ASSOC);

$oldMemberID = $oldTxn['MemberID'];
$oldYearID = $oldTxn['YearID'];

// STEP 2: Update the transaction
$updateStmt = $pdo->prepare("UPDATE transactions SET ... WHERE TransactionID = ?");
$updateStmt->execute([...]);

// STEP 3: Recalculate net balance for ALL affected members and years
$result = updateNetBalanceAfterTransactionChange(
    $oldMemberID,          // Member before change
    $oldYearID,            // Year before change
    $newMemberID,          // Member after change (might be same)
    $newYearID,            // Year after change (might be same)
    $_SESSION['admin_id']   // Who made the change
);

if ($result['success']) {
    echo "✓ Net balance updated for " . $result['combinations_updated'] . " combinations";
} else {
    echo "✗ Error: " . $result['error'];
}
```

### WRONG WAY (What was happening before)

```php
// ❌ WRONG - Only updates new year, ignores old year
$newBalance = calculateMemberNetBalance($newMemberID, $newYearID);
// This leaves old year with incorrect balance!
```

## 2. When Deleting a Transaction

```php
<?php
require_once 'includes/net-balance-helper.php';

// Fetch transaction BEFORE deletion
$txn = $pdo->prepare("SELECT * FROM transactions WHERE TransactionID = ?");
$txn->execute([$transactionID]);
$transaction = $txn->fetch(PDO::FETCH_ASSOC);

// Delete the transaction
$del = $pdo->prepare("DELETE FROM transactions WHERE TransactionID = ?");
$del->execute([$transactionID]);

// Recalculate for that member-year combination
$newBalance = calculateMemberNetBalance($transaction['MemberID'], $transaction['YearID']);

// Update member_net_balance table
$update = $pdo->prepare("
    INSERT INTO member_net_balance (MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy)
    VALUES (?, ?, ?, NOW(), ?)
    ON DUPLICATE KEY UPDATE 
        NetBalance = VALUES(NetBalance),
        LastUpdatedAt = NOW(),
        UpdatedBy = VALUES(UpdatedBy)
");
$update->execute([
    $transaction['MemberID'],
    $transaction['YearID'],
    $newBalance,
    $_SESSION['admin_id']
]);
```

## 3. Net Balance Formula

```
Net Balance = (Distribution + Interest) - Collection
```

**Always use this formula consistently:**

```php
// ✅ CORRECT
SELECT 
    COALESCE(SUM(DistributedAmount), 0) as dist,
    COALESCE(SUM(InterestAmount), 0) as interest,
    COALESCE(SUM(CollectionAmount), 0) as coll
FROM transactions 
WHERE MemberID = ? AND YearID = ?

$netBalance = ($dist + $interest) - $coll;

// ❌ WRONG (missing collection subtraction)
$netBalance = $dist + $interest;  // DON'T DO THIS!

// ❌ WRONG (wrong order)
$netBalance = $dist - $interest - $coll;  // DON'T DO THIS!
```

## 4. Helper Functions Reference

### `calculateMemberNetBalance($memberId, $yearId = null)`
Calculates net balance from transactions (no manual override).

```php
// For specific year
$balance = calculateMemberNetBalance(5, 3);  // Member 5, Year 3

// For all years
$balance = calculateMemberNetBalance(5);  // Member 5, all years

// Returns: float (can be negative)
```

### `getCurrentMemberNetBalance($memberId, $yearId = null)`
Gets net balance respecting manual overrides in member_net_balance table.

```php
// Use this for displaying to users (respects manual overrides)
$userBalance = getCurrentMemberNetBalance(5, 3);
```

### `updateNetBalanceAfterTransactionChange($oldMemberId, $oldYearId, $newMemberId, $newYearId, $userId)`
Updates all affected member-year combinations after transaction change.

```php
$result = updateNetBalanceAfterTransactionChange(
    5,    // Was member 5
    3,    // In year 3
    5,    // Now member 5 (same)
    4,    // In year 4 (changed!)
    1     // Admin user 1
);

// Will update:
// - Member 5, Year 3 (decreased)
// - Member 5, Year 4 (increased)

if ($result['success']) {
    // Handle combinations_updated
    echo "Updated " . $result['combinations_updated'] . " combinations";
}
```

### `recalculateAllTransactionsForMember($memberId, $userId)`
Recalculates a member's balance across ALL their years.

```php
// Use when multiple transactions of same member were modified
$result = recalculateAllTransactionsForMember(5, 1);

// Result:
// {
//     'success' => true,
//     'years_affected' => 3,
//     'details' => [
//         ['YearID' => 1, 'NetBalance' => 5000],
//         ['YearID' => 2, 'NetBalance' => 3500],
//         ['YearID' => 3, 'NetBalance' => 2000]
//     ]
// }
```

### `getAllMembersNetBalance($yearId = null)`
Gets all members' net balances (respects manual overrides).

```php
// All members, all years (respects member_net_balance table)
$members = getAllMembersNetBalance();

// Specific year only
$members = getAllMembersNetBalance(3);

// Returns array of [
//     ['MemberID' => 1, 'MemberName' => 'John', 'NetBalance' => 5000],
//     ['MemberID' => 2, 'MemberName' => 'Jane', 'NetBalance' => 3000],
//     ...
// ]
```

## 5. Database Structure

### member_net_balance Table
```sql
CREATE TABLE member_net_balance (
    MemberID INT(11),
    YearID INT(11),
    NetBalance DECIMAL(15,2),
    LastUpdatedAt TIMESTAMP,
    UpdatedBy INT(11),
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_member_year (MemberID, YearID)
);
```

**Key Points:**
- Stores calculated OR manually overridden balances
- `UNIQUE(MemberID, YearID)`: One entry per member per year
- `UpdatedBy`: Tracks which admin last updated it
- `LastUpdatedAt`: When the balance was last changed

## 6. Common Scenarios

### Scenario A: User edits collection amount

```php
// Collection changed from ₹1,000 to ₹2,000 (same member, same year)
// Impact: Balance decreases by ₹1,000

$oldTransaction = ...;  // Fetch before update
$memberID = $oldTransaction['MemberID'];  // Same
$yearID = $oldTransaction['YearID'];      // Same

// Just recalculate for this member-year
$newBalance = calculateMemberNetBalance($memberID, $yearID);
// No need for updateNetBalanceAfterTransactionChange here
// because member and year didn't change
```

### Scenario B: User moves transaction to different year

```php
// Transaction date changed: 2024-25 → 2025-26
// Member stayed same

$oldYearID = 3;  // Was in year 3
$newYearID = 4;  // Now in year 4
$memberID = 5;   // Same member

// Use helper function
$result = updateNetBalanceAfterTransactionChange(
    $memberID,    // Same
    $oldYearID,   // 3
    $memberID,    // Same
    $newYearID,   // 4
    $adminID
);

// Will recalculate:
// - Member 5, Year 3 (transaction removed)
// - Member 5, Year 4 (transaction added)
```

### Scenario C: User reassigns transaction to different member (error correction)

```php
// Transaction reassigned: Member 5 → Member 10
// Year stayed same

$oldMemberID = 5;   // Was member 5
$newMemberID = 10;  // Now member 10
$yearID = 3;        // Same year

// Use helper function
$result = updateNetBalanceAfterTransactionChange(
    $oldMemberID,  // 5
    $yearID,       // 3
    $newMemberID,  // 10
    $yearID,       // 3
    $adminID
);

// Will recalculate:
// - Member 5, Year 3 (transaction removed)
// - Member 10, Year 3 (transaction added)
```

## 7. Verification

### Check if calculation is correct
```php
// Get transaction totals
$stmt = $pdo->prepare("
    SELECT 
        SUM(DistributedAmount) as dist,
        SUM(InterestAmount) as interest,
        SUM(CollectionAmount) as coll
    FROM transactions
    WHERE MemberID = ? AND YearID = ?
");
$stmt->execute([$memberId, $yearId]);
$totals = $stmt->fetch(PDO::FETCH_ASSOC);

$calculatedBalance = ($totals['dist'] + $totals['interest']) - $totals['coll'];

// Get stored balance
$storedBalance = getCurrentMemberNetBalance($memberId, $yearId);

// Should be approximately equal (within rounding error)
if (abs($calculatedBalance - $storedBalance) < 0.01) {
    echo "✓ Balances match";
} else {
    echo "✗ Balance mismatch: calculated=$calculatedBalance, stored=$storedBalance";
}
```

## 8. Error Handling Best Practices

```php
try {
    // Update transaction
    $updateStmt->execute([...]);
    
    // Recalculate
    $result = updateNetBalanceAfterTransactionChange(...);
    
    if (!$result['success']) {
        throw new Exception($result['error']);
    }
    
    // Success feedback
    $_SESSION['success'] = "Transaction updated and balance recalculated";
    
} catch (Exception $e) {
    error_log("Transaction update error: " . $e->getMessage());
    $_SESSION['error'] = "Error updating transaction: " . $e->getMessage();
}

// Always have user-friendly error messages
```

## Summary

✅ **DO:**
- Fetch old transaction data BEFORE updating
- Use `updateNetBalanceAfterTransactionChange()` when member or year changes
- Use formula: (Distribution + Interest) - Collection
- Update `member_net_balance` table after changes
- Log changes for audit trail

❌ **DON'T:**
- Forget to recalculate old year's balance when year changes
- Only update new member, ignore old member when reassigning
- Use incomplete formulas (missing collection)
- Forget to handle exceptions
- Update `member_net_balance` table before checking if calculation is correct

For examples, refer to:
- `admin/edit-transaction.php` - Transaction editing
- `admin/add-transaction.php` - Transaction adding
- `superadmin/manage-transactions.php` - SuperAdmin operations
- `includes/net-balance-helper.php` - Helper functions
