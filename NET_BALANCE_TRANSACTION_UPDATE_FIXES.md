# Net Balance Calculation - Transaction Update Fixes

## Overview

This document outlines the fixes and improvements made to ensure accurate net balance calculations when transactions are updated in the SRCOLONY system.

## Issues Identified & Fixed

### 1. **Missing Recalculation of Old Year's Balance (CRITICAL)**

**Problem:**
When editing a transaction and changing the `YearID`, the system only recalculated the new year's net balance, leaving the old year's balance incorrect.

**Example Scenario:**
```
Member A - Year 2024-25: Initial Net Balance = ₹5,000
Transaction: Distribution = ₹1,000

User edits transaction and moves it to Year 2025-26
Result BEFORE FIX:
  - Year 2024-25: Still shows ₹5,000 (should be ₹4,000)
  - Year 2025-26: Correctly updated with the transaction
  
Result AFTER FIX:
  - Year 2024-25: Correctly recalculated to ₹4,000
  - Year 2025-26: Includes the moved transaction correctly
```

**Solution Implemented:**
Modified `admin/edit-transaction.php` to:
1. Fetch the OLD transaction data BEFORE updating
2. Extract the old MemberID and YearID
3. After updating the transaction, recalculate BOTH:
   - The new member's balance for the new year
   - The old member's balance for the old year (if member changed)
   - The same member's balance for the old year (if year changed)
4. Update `member_net_balance` table for all affected combinations

### 2. **Incomplete Handling of Member Changes**

**Problem:**
If a transaction is reassigned to a different member (which may happen in correction scenarios), only the new member's balance is updated.

**Solution:**
The improved logic now detects when MemberID changes and recalculates for both members.

### 3. **No Utility Function for Transaction Updates**

**Problem:**
No centralized function existed to handle the complex logic of recalculating multiple member-year combinations.

**Solution:**
Added two new helper functions in `includes/net-balance-helper.php`:
- `updateNetBalanceAfterTransactionChange()`
- `recalculateAllTransactionsForMember()`

## Net Balance Formula

The system uses this formula consistently across all portals:

```
Net Balance = (DistributedAmount + InterestAmount) - CollectionAmount
```

**Legend:**
- **Distribution**: Amount given to member (liability for the organization)
- **Interest**: Additional interest accrued
- **Collection**: Amount received from member
- **Net Balance**: Money that member owes or is owed

**Status Indicators:**
- Positive balance (> 0): Member is due to pay (owes money)
- Negative balance (< 0): Member is payable (organization owes them)
- Zero balance: Fully settled

## Files Modified

### 1. `admin/edit-transaction.php`
**Changes:**
- Lines 25-95: Complete refactor of form submission handler
- **Key features:**
  - Fetches old transaction data before updating
  - Detects if MemberID or YearID changed
  - Recalculates all affected member-year combinations
  - Properly updates `member_net_balance` table

**Code Pattern:**
```php
// BEFORE updating transaction
$oldTransaction = $pdo->prepare("SELECT MemberID, YearID FROM transactions")->fetch();
$oldMemberID = $oldTransaction['MemberID'];
$oldYearID = $oldTransaction['YearID'];

// Update transaction
$updateStmt->execute([...]);

// AFTER updating, recalculate for all affected combinations
foreach ($membersToRecalculate as $mID) {
    foreach ($yearsToRecalculate as $yID) {
        $balance = calculateMemberNetBalance($mID, $yID);
        // Update member_net_balance table
    }
}
```

### 2. `includes/net-balance-helper.php`
**New Functions Added:**

#### `updateNetBalanceAfterTransactionChange()`
Updates net balance when a transaction's member or year changes.

```php
$result = updateNetBalanceAfterTransactionChange(
    $oldMemberID,    // Member ID before change
    $oldYearID,      // Year ID before change
    $newMemberID,    // Member ID after change
    $newYearID,      // Year ID after change
    $adminID         // User making the change
);

// Returns:
// [
//     'success' => true,
//     'updates' => [...],
//     'combinations_updated' => 4
// ]
```

#### `recalculateAllTransactionsForMember()`
Recalculates a member's net balance across all years they have transactions.

```php
$result = recalculateAllTransactionsForMember($memberId, $adminID);

// Returns:
// [
//     'success' => true,
//     'member_id' => 5,
//     'years_affected' => 3,
//     'details' => [...]
// ]
```

## How It Works Now

### Scenario 1: Editing Transaction Amounts
```
User edits collection amount from ₹1,000 to ₹2,000 (same member, same year)

1. ✓ Fetches old transaction data
2. ✓ Updates the transaction with new amount
3. ✓ Recalculates: (Distribution + Interest) - NewCollection
4. ✓ Updates member_net_balance table
5. ✓ Result: Balance decreases by ₹1,000
```

### Scenario 2: Moving Transaction to Different Year
```
User changes transaction date from 2024-25 to 2025-26 year

1. ✓ Fetches old YearID (2024-25)
2. ✓ Updates transaction with new YearID (2025-26)
3. ✓ Recalculates for Member-Year 2024-25 (removes transaction)
4. ✓ Recalculates for Member-Year 2025-26 (adds transaction)
5. ✓ Both years now have correct balances
```

### Scenario 3: Reassigning Transaction to Different Member
```
User changes transaction from Member 5 to Member 10 (correction)

1. ✓ Fetches old MemberID (5)
2. ✓ Updates transaction with new MemberID (10)
3. ✓ Recalculates for Member 5 (removes transaction)
4. ✓ Recalculates for Member 10 (adds transaction)
5. ✓ Both members now have correct balances
```

## Testing

### Run the Verification Script
```bash
php test-net-balance-transaction-updates.php
```

This tests:
1. ✅ Simple transaction amount updates
2. ✅ Year change scenarios
3. ✅ Member change scenarios
4. ✅ Net balance formula accuracy
5. ✅ Multi-year recalculation

### Manual Testing Checklist

**Test 1: Edit Transaction (Same Member, Same Year)**
- [ ] Go to Admin → Manage Transactions
- [ ] Edit a transaction
- [ ] Change Collection/Distribution amount
- [ ] Click Update
- [ ] Verify member's net balance in Net Balance Manager

**Test 2: Verify Multi-Year Calculation**
- [ ] Find a member with transactions in multiple years
- [ ] Edit a transaction and move it to different year
- [ ] Check both year's balances are correct
- [ ] Formula: (Distribution + Interest) - Collection

**Test 3: Check Audit Log**
- [ ] Go to SuperAdmin → Audit Logs
- [ ] Filter for "transactions" table updates
- [ ] Verify member_net_balance updates are logged

**Test 4: Dashboard Verification**
- [ ] Check Admin Dashboard shows correct system total
- [ ] Check Member Portal shows correct personal balance
- [ ] Verify both match the helper function calculations

## Key Implementation Details

### Member-Year Combinations
When a transaction is updated, the system determines all affected member-year combinations:

```
Affected combinations = 
  {old_member, old_year} +
  {new_member, new_year} +
  {old_member, new_year} if year changed +
  {new_member, old_year} if member changed
```

For each combination, the net balance is recalculated and updated.

### Database Operations
```sql
-- Before transaction update
SELECT * FROM transactions WHERE TransactionID = ?;

-- UPDATE transaction
UPDATE transactions SET ... WHERE TransactionID = ?;

-- After transaction update (for each affected member-year combination)
INSERT INTO member_net_balance (MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy)
VALUES (?, ?, ?, NOW(), ?)
ON DUPLICATE KEY UPDATE 
    NetBalance = VALUES(NetBalance),
    LastUpdatedAt = NOW(),
    UpdatedBy = VALUES(UpdatedBy);
```

### Consistency Across Portals
All portals now use the same calculation logic:
- **Admin Portal** → Uses `calculateMemberNetBalance()`
- **Member Portal** → Uses `getCurrentMemberNetBalance()`
- **SuperAdmin Portal** → Uses helper functions
- **Reports** → Displays calculated balances from helper functions
- **PDF Exports** → Uses same calculation engine

## Error Handling

All operations include try-catch blocks:
```php
try {
    // Recalculate logic
    $balance = calculateMemberNetBalance($memberId, $yearId);
    // Update database
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    // Display user-friendly error
}
```

## Future Improvements

1. **Batch Updates**: Add function to update multiple transactions efficiently
2. **Transaction Rollback**: Implement rollback for failed updates
3. **Balance History**: Track balance changes over time for audit trails
4. **Automated Validation**: Warn users if recalculation seems unusual
5. **API Endpoints**: Create REST API for batch recalculations

## Troubleshooting

### Issue: Balance still shows old value after edit
**Solution:**
1. Check if `member_net_balance` table has the updated record
2. Run: `php test-net-balance-transaction-updates.php`
3. Check error logs for exceptions

### Issue: Different balances across portals
**Solution:**
1. Go to SuperAdmin → Net Balance Manager
2. Click "Auto Recalculate" for affected members
3. This will sync all calculated balances

### Issue: Transaction edit not recalculating
**Solution:**
1. Verify `includes/net-balance-helper.php` is included
2. Check that `member_net_balance` table exists
3. Verify admin/superadmin permissions are set correctly

## Summary

The net balance system now correctly handles all transaction update scenarios:
- ✅ Amount changes (same member, same year)
- ✅ Year changes (moving transactions between academic years)
- ✅ Member reassignments (correction scenarios)
- ✅ Multiple affected member-year combinations
- ✅ Consistent formula: (Distribution + Interest) - Collection
- ✅ Proper logging and audit trail
- ✅ Works across all portals (Admin, Member, SuperAdmin)

The system is now robust and reliable for transaction management with accurate net balance calculations.
