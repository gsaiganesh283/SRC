# YearID Default Value Error - Complete Fix

## Problem Summary

**Error:** `SQLSTATE[HY000]: General error: 1364 Field 'YearID' doesn't have a default value`

This error occurs when trying to insert records into the `member_net_balance` table without providing a `YearID` value. The table was structured with `YearID` as a NOT NULL column that's part of a composite primary key `(MemberID, YearID)`, but some PHP files were attempting to insert records without providing this required field.

## Root Cause Analysis

The `member_net_balance` table has the following structure:
```sql
PRIMARY KEY (MemberID, YearID)
```

This means:
- Both `MemberID` and `YearID` are NOT NULL
- Both are required for every insert
- You cannot insert a record without providing both values

However, several PHP files were trying to insert without `YearID`:

### Files with Issues:

1. **initialize-net-balance-table.php** (Line 39)
   - Was inserting: `INSERT INTO member_net_balance (MemberID, NetBalance, ...)`
   - Missing: `YearID` parameter

2. **superadmin/api-net-balance.php** (Lines 198-202)
   - When `$yearId` was null/not provided, it was inserting without YearID
   - This happened in the `manual_update` action

## Solutions Implemented

### 1. Fixed initialize-net-balance-table.php ✅

**Before:**
```php
// Get all members
$stmt = $pdo->query("SELECT MemberID, MemberName FROM members ...");
// Calculate balance
$details = getMemberNetBalanceDetails($memberId, null);
// Insert WITHOUT YearID - ERROR!
$stmt = $pdo->prepare("
    INSERT INTO member_net_balance (MemberID, NetBalance, LastUpdatedAt, UpdatedBy)
    VALUES (?, ?, NOW(), NULL)
");
$stmt->execute([$memberId, $netBalance]);
```

**After:**
```php
// Get the active academic year FIRST
$stmt = $pdo->query("SELECT YearID, YearName FROM academic_years WHERE Status = 'Active' LIMIT 1");
$activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
$activeYearID = $activeYear['YearID'];

// Get all members
$stmt = $pdo->query("SELECT MemberID, MemberName FROM members ...");
// Calculate balance WITH YearID
$details = getMemberNetBalanceDetails($memberId, $activeYearID);
// Insert WITH YearID - SUCCESS!
$stmt = $pdo->prepare("
    INSERT INTO member_net_balance (MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy)
    VALUES (?, ?, ?, NOW(), NULL)
");
$stmt->execute([$memberId, $activeYearID, $netBalance]);
```

**Changes:**
- Added code to fetch the active academic year at the beginning
- Changed `getMemberNetBalanceDetails($memberId, null)` to `getMemberNetBalanceDetails($memberId, $activeYearID)`
- Added `YearID` to the INSERT statement
- Added `$activeYearID` to the execute parameters

### 2. Fixed superadmin/api-net-balance.php ✅

**Before:**
```php
case 'manual_update':
    $memberId = intval($_POST['member_id'] ?? 0);
    $yearId = intval($_POST['year_id'] ?? 0);
    $yearId = ($yearId > 0) ? $yearId : null;  // Can be null!
    
    // ... validation ...
    
    if ($yearId) {
        // Insert WITH YearID
        $stmt = $pdo->prepare("
            INSERT INTO member_net_balance (MemberID, YearID, NetBalance, ...)
            VALUES (?, ?, ?, ...)
        ");
        $stmt->execute([$memberId, $yearId, $newBalance, ...]);
    } else {
        // Insert WITHOUT YearID - ERROR!
        $stmt = $pdo->prepare("
            INSERT INTO member_net_balance (MemberID, NetBalance, ...)
            VALUES (?, ?, ...)
        ");
        $stmt->execute([$memberId, $newBalance, ...]);
    }
```

**After:**
```php
case 'manual_update':
    $memberId = intval($_POST['member_id'] ?? 0);
    $yearId = intval($_POST['year_id'] ?? 0);
    $yearId = ($yearId > 0) ? $yearId : null;
    
    // GET ACTIVE YEAR IF NOT PROVIDED - NEW!
    if (!$yearId) {
        $stmt = $pdo->query("SELECT YearID FROM academic_years WHERE Status = 'Active' LIMIT 1");
        $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($activeYear) {
            $yearId = $activeYear['YearID'];
        } else {
            echo json_encode(['success' => false, 'message' => 'No active academic year found']);
            break;
        }
    }
    
    // ... validation ...
    
    // Always insert WITH YearID (now guaranteed to exist)
    $stmt = $pdo->prepare("
        INSERT INTO member_net_balance (MemberID, YearID, NetBalance, ...)
        VALUES (?, ?, ?, ...)
    ");
    $stmt->execute([$memberId, $yearId, $newBalance, ...]);
```

**Changes:**
- Added logic to fetch and use the active year if `$yearId` is not provided
- Returns an error if no active year exists
- Removed the conditional branching - now always inserts with YearID
- Simplified the code logic

### 3. Created Database Migration Files ✅

**File 1:** `database/CREATE_MEMBER_NET_BALANCE_TABLE.sql`
- Creates the `member_net_balance` table with proper composite key
- Defines foreign key constraints
- Ensures YearID is NOT NULL

**File 2:** `database/MIGRATE_MEMBER_NET_BALANCE_TABLE.sql`
- Safely migrates existing data
- Backs up old data
- Assigns active year to records without YearID
- Drops old table and creates new one

### 4. Created Verification Script ✅

**File:** `fix-member-net-balance-yearid.php`
- Can be run from browser or CLI
- Verifies table structure
- Tests INSERT operation
- Provides detailed output of changes
- Ensures the fix is working correctly

**Usage:**
```bash
# From web browser:
http://localhost/SRCOLONY-VIEWS/fix-member-net-balance-yearid.php

# From command line:
php fix-member-net-balance-yearid.php
```

## Database Table Structure (Fixed)

```sql
CREATE TABLE `member_net_balance` (
  `MemberID` int(11) NOT NULL,           -- NOT NULL, PRIMARY KEY part 1
  `YearID` int(11) NOT NULL,             -- NOT NULL, PRIMARY KEY part 2 (FIX!)
  `NetBalance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `LastUpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `UpdatedBy` int(11) DEFAULT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`MemberID`, `YearID`),    -- Composite key ensures both values required
  KEY `fk_mnb_year` (`YearID`),
  KEY `fk_mnb_updated_by` (`UpdatedBy`),
  
  CONSTRAINT `fk_mnb_member` FOREIGN KEY (`MemberID`) REFERENCES `members` (`MemberID`) ON DELETE CASCADE,
  CONSTRAINT `fk_mnb_year` FOREIGN KEY (`YearID`) REFERENCES `academic_years` (`YearID`) ON DELETE CASCADE,
  CONSTRAINT `fk_mnb_updated_by` FOREIGN KEY (`UpdatedBy`) REFERENCES `admins` (`AdminID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Files Modified

### PHP Files Updated:
1. ✅ [initialize-net-balance-table.php](initialize-net-balance-table.php) - Added active year fetching
2. ✅ [superadmin/api-net-balance.php](superadmin/api-net-balance.php#L155) - Added fallback to active year

### PHP Files Already Correct:
1. ✅ [admin/add-transaction.php](admin/add-transaction.php#L250) - Already includes YearID
2. ✅ [admin/edit-transaction.php](admin/edit-transaction.php#L82) - Already includes YearID
3. ✅ [superadmin/manage-transactions.php](superadmin/manage-transactions.php#L376) - Already includes YearID

### New Files Created:
1. 📄 [database/CREATE_MEMBER_NET_BALANCE_TABLE.sql](database/CREATE_MEMBER_NET_BALANCE_TABLE.sql)
2. 📄 [database/MIGRATE_MEMBER_NET_BALANCE_TABLE.sql](database/MIGRATE_MEMBER_NET_BALANCE_TABLE.sql)
3. 🔧 [fix-member-net-balance-yearid.php](fix-member-net-balance-yearid.php)

## How to Apply the Fix

### Option 1: Automatic Fix (Recommended)

1. Run the verification/fix script:
   ```
   http://localhost/SRCOLONY-VIEWS/fix-member-net-balance-yearid.php
   ```

2. Or from CLI:
   ```bash
   cd /workspaces/SRCOLONY-VIEWS
   php fix-member-net-balance-yearid.php
   ```

This will:
- Create the table with the correct structure
- Migrate any existing data
- Verify the fix is working
- Provide detailed output

### Option 2: Manual SQL Migration

1. Execute the migration SQL:
   ```bash
   mysql -u root -p srcolony < database/MIGRATE_MEMBER_NET_BALANCE_TABLE.sql
   ```

2. Verify the structure:
   ```sql
   DESCRIBE member_net_balance;
   ```

3. Test an INSERT:
   ```sql
   INSERT INTO member_net_balance (MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy)
   VALUES (1, 1, 5000.00, NOW(), 1)
   ON DUPLICATE KEY UPDATE NetBalance = VALUES(NetBalance);
   ```

## Testing the Fix

After applying the fix, the following operations should work without the error:

### Test 1: Insert without YearID error
```php
$stmt = $pdo->prepare("
    INSERT INTO member_net_balance (MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy)
    VALUES (?, ?, ?, NOW(), ?)
    ON DUPLICATE KEY UPDATE NetBalance = VALUES(NetBalance)
");
$stmt->execute([1, 1, 1000.00, 1]); // Should work!
```

### Test 2: Update from initialize script
```bash
php initialize-net-balance-table.php
```
Should complete without YearID errors.

### Test 3: Manual balance update
Navigate to SuperAdmin > Net Balance Manager and manually update a member's balance.
Should work without errors.

## Impact on Other Systems

This fix has minimal impact on other systems:

1. **Member Dashboard** - No changes needed, uses `getCurrentMemberNetBalance($memberId, $yearId)`
2. **Admin Dashboard** - No changes needed, uses helper functions
3. **Transaction Management** - Already correct, was using YearID
4. **Reports** - No changes needed, uses aggregated calculations

## Prevention of Future Issues

To prevent this issue in the future:

1. **Always include YearID** when inserting into `member_net_balance`
2. **Use helper functions** defined in [includes/net-balance-helper.php](includes/net-balance-helper.php)
3. **Fallback to active year** if YearID is not provided (as implemented in api-net-balance.php)
4. **Add validation** in INSERT statements to ensure both MemberID and YearID are provided

## Verification Checklist

- [x] Table structure corrected
- [x] YearID is NOT NULL
- [x] YearID is part of composite PRIMARY KEY
- [x] initialize-net-balance-table.php fixed
- [x] api-net-balance.php fixed
- [x] Migration script created
- [x] Verification script created
- [x] This documentation file created
- [ ] Run fix-member-net-balance-yearid.php to verify

## Support

If you still encounter the YearID error after applying this fix:

1. Run the verification script: `fix-member-net-balance-yearid.php`
2. Check the PHP error logs for the exact query that's failing
3. Ensure the `academic_years` table has at least one active year
4. Verify that both involved tables (`members` and `academic_years`) exist

---

**Last Updated:** February 6, 2026
**Status:** ✅ Complete
