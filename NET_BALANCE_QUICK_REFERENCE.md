# Net Balance Calculation - Quick Reference Card

## Formula
```
NET BALANCE = (Distribution + Interest) - Collection
```

## When to Use Which Function

| Scenario | Function | Usage |
|----------|----------|-------|
| Calculate from transactions | `calculateMemberNetBalance($id, $year)` | Backend calculation |
| Display to user | `getCurrentMemberNetBalance($id, $year)` | Respects manual overrides |
| After editing transaction | `updateNetBalanceAfterTransactionChange()` | Multi-year scenarios |
| Bulk member recalc | `recalculateAllTransactionsForMember()` | All years for one member |
| All members | `getAllMembersNetBalance($year)` | Dashboard summary |
| System summary | `getSystemNetBalanceSummary($year)` | Total system balance |

## Code Templates

### Template 1: Edit Transaction (Same Year, Same Member)
```php
$newBalance = calculateMemberNetBalance($memberID, $yearID);
// Update member_net_balance table
```

### Template 2: Edit Transaction (Year Changed)
```php
$result = updateNetBalanceAfterTransactionChange(
    $oldMemberID,           // Save before update
    $oldYearID,             // Save before update
    $newMemberID,           // After update
    $newYearID,             // After update
    $_SESSION['admin_id']
);
```

### Template 3: Delete Transaction
```php
$old = ...fetch transaction...;
...delete transaction...;
$balance = calculateMemberNetBalance($old['MemberID'], $old['YearID']);
...update member_net_balance table...;
```

## Status Indicators

| Balance | Status | Meaning |
|---------|--------|---------|
| > 0 | Due | Member owes money |
| = 0 | Settled | Fully paid |
| < 0 | Payable | Organization owes member |

## Common Issues & Fixes

| Issue | Cause | Fix |
|-------|-------|-----|
| Balance doesn't update | Missing net-balance-helper include | Add: `require_once 'includes/net-balance-helper.php';` |
| Only new year updates | Not handling old year | Use `updateNetBalanceAfterTransactionChange()` |
| Wrong formula | Missing collection subtraction | Use: `(dist + interest) - collection` |
| Balance discrepancy | Manual override not considered | Use `getCurrentMemberNetBalance()` not `calculateMemberNetBalance()` |
| Audit trail missing | Not logging changes | Ensure member_net_balance update is logged |

## Files to Know

| File | Purpose | Last Updated |
|------|---------|--------------|
| `admin/edit-transaction.php` | Edit transaction form (FIXED) | Feb 10, 2026 |
| `includes/net-balance-helper.php` | Core calculation functions (ENHANCED) | Feb 10, 2026 |
| `member_net_balance` (table) | Stores calculated/manual balances | Requires YearID |
| `transactions` (table) | Transaction records | Source of truth |

## Testing Checklist

Quick verification after changes:

```bash
# Run comprehensive test
php test-net-balance-transaction-updates.php

# Check specific member
$balance = calculateMemberNetBalance(5, 3);
echo "Member 5, Year 3: ₹$balance";

# Verify formula
SELECT (SUM(DistributedAmount) + SUM(InterestAmount) - SUM(CollectionAmount)) 
FROM transactions WHERE MemberID = 5 AND YearID = 3;
```

## Helper Function Quick Reference

### 1. calculateMemberNetBalance()
```php
$balance = calculateMemberNetBalance(5);      // All years
$balance = calculateMemberNetBalance(5, 3);   // Specific year
// Returns: float
```

### 2. getCurrentMemberNetBalance()
```php
$balance = getCurrentMemberNetBalance(5, 3);
// Returns: float (includes manual overrides)
```

### 3. updateNetBalanceAfterTransactionChange()
```php
$result = updateNetBalanceAfterTransactionChange(
    5,  // old member
    3,  // old year
    5,  // new member
    4,  // new year
    1   // admin id
);
// Returns: ['success' => bool, 'combinations_updated' => int, 'updates' => []]
```

### 4. recalculateAllTransactionsForMember()
```php
$result = recalculateAllTransactionsForMember(5, 1);
// Returns: ['success' => bool, 'years_affected' => int, 'details' => []]
```

### 5. getAllMembersNetBalance()
```php
$members = getAllMembersNetBalance();      // All years, all members
$members = getAllMembersNetBalance(3);     // Specific year
// Returns: [['MemberID' => ..., 'NetBalance' => ...], ...]
```

## Error Messages & Solutions

| Error | Solution |
|-------|----------|
| "Transaction not found" | Transaction ID is invalid or deleted |
| "Error calculating net balance" | Check database connection, ensure transactions table exists |
| "Class member_net_balance not found" | Ensure table has MemberID and YearID columns |
| "Undefined variable" | Include net-balance-helper.php at top of file |

## Performance Tips

- ✅ Use indexed queries on MemberID + YearID
- ✅ Batch similar recalculations together
- ✅ Avoid recalculating all members if only one changed
- ✅ Cache system summary if calculated frequently

## Debugging Steps

1. **Enable error logging**
   ```php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```

2. **Check member_net_balance table**
   ```sql
   SELECT * FROM member_net_balance WHERE MemberID = 5 AND YearID = 3;
   ```

3. **Verify transaction totals**
   ```sql
   SELECT SUM(DistributedAmount), SUM(InterestAmount), SUM(CollectionAmount)
   FROM transactions WHERE MemberID = 5 AND YearID = 3;
   ```

4. **Run test script**
   ```bash
   php test-net-balance-transaction-updates.php
   ```

## Important Notes

⚠️ **ALWAYS:**
- Fetch old transaction data BEFORE updating
- Include net-balance-helper.php at top of file
- Update member_net_balance table after any recalculation
- Handle exceptions with try-catch
- Log changes in audit_logs

🚫 **NEVER:**
- Skip recalculating old year when year changes
- Forget to include affected members
- Use incomplete formula
- Update database without error handling
- Assume member and year didn't change

## Most Important: The updateNetBalanceAfterTransactionChange() Function

This function handles ALL the complex logic for you:

```php
$result = updateNetBalanceAfterTransactionChange(
    $oldMemberID,        // Member before the change
    $oldYearID,          // Year before the change
    $newMemberID,        // Member after the change (might be same)
    $newYearID,          // Year after the change (might be same)
    $_SESSION['admin_id'] // Who made the change
);

// Automatically recalculates:
// - Old member, old year (removes transaction from there)
// - Old member, new year (if year changed)
// - New member, old year (if member changed)  
// - New member, new year (adds transaction here)
```

**RESULT**: All affected balances are automatically recalculated!

## Common Implementation Pattern

```php
<?php
require_once 'includes/net-balance-helper.php';

try {
    // 1. Fetch old transaction data FIRST
    $oldTxn = $pdo->prepare("SELECT MemberID, YearID FROM transactions WHERE ID = ?");
    $oldTxn->execute([$id]);
    $oldData = $oldTxn->fetch(PDO::FETCH_ASSOC);
    
    // 2. Update the transaction
    $stmt = $pdo->prepare("UPDATE transactions SET ... WHERE ID = ?");
    $stmt->execute([...]);
    
    // 3. Recalculate using helper function
    $result = updateNetBalanceAfterTransactionChange(
        $oldData['MemberID'],    // Old member
        $oldData['YearID'],      // Old year
        $newMemberID,            // New member (might be same)
        $newYearID,              // New year (might be same)
        $_SESSION['admin_id']
    );
    
    if (!$result['success']) {
        throw new Exception($result['error']);
    }
    
    echo "✓ Updated " . $result['combinations_updated'] . " member-year combinations";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage();
}
?>
```

## Test Your Implementation

```bash
# Run the comprehensive test suite
php test-net-balance-transaction-updates.php

# Expected: All 5 tests should PASS ✅
```

## Verification Queries

```sql
-- Check calculated balance vs stored balance
SELECT 
    t.MemberID,
    t.YearID,
    SUM(DistributedAmount + InterestAmount - CollectionAmount) as calculated,
    mnb.NetBalance as stored,
    ABS(SUM(DistributedAmount + InterestAmount - CollectionAmount) - mnb.NetBalance) as difference
FROM transactions t
LEFT JOIN member_net_balance mnb ON t.MemberID = mnb.MemberID AND t.YearID = mnb.YearID
GROUP BY t.MemberID, t.YearID
HAVING difference > 0.01;
-- Should return 0 rows if all balances are correct
```

## Quick Links

- 📖 Full Documentation: `NET_BALANCE_TRANSACTION_UPDATE_FIXES.md`
- 👨‍💻 Implementation Guide: `NET_BALANCE_IMPLEMENTATION_GUIDE.md`
- ✅ Summary: `NET_BALANCE_FIXES_SUMMARY.md`
- 📋 Test Results: `TEST_RESULTS_NET_BALANCE_FIXES.md`
- 🧪 Test Script: `test-net-balance-transaction-updates.php`
- 📖 Original Guide: `NET_BALANCE_MANAGEMENT_GUIDE.md`
- 📊 Resolution Report: `NET_BALANCE_RESOLUTION_REPORT.md`

---
**Quick Ref v2.0** | Last Updated: Feb 10, 2026 | Status: ✅ Verified - All Tests Pass
