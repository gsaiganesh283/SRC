# Net Balance Management System

## Overview
The Net Balance Management System provides a centralized way to calculate, track, and manage member net balances across all portals (Member, Admin, and Super Admin).

## Calculation Method

**Net Balance = (Distribution + Interest) - Collection**

Each member's net balance is calculated by:
1. Summing all Distribution amounts
2. Adding all Interest amounts
3. Subtracting total Collection amount

## Accessing the Net Balance Manager

1. Log in as **Super Admin**
2. Go to Dashboard
3. Click on **"Net Balance Manager"** card in Quick Actions section
4. You will see:
   - System-wide summary (Total Collection, Distribution, Interest, Net Balance)
   - All members with their individual net balances
   - Status badges (Due, Payable, or Neutral)

## Features

### 1. **Auto-Recalculate All Members**
- Recalculates net balance for all members based on transaction data
- Useful when transactions are added/modified externally
- Logs all recalculations in audit logs
- Can filter by Academic Year

**How to Use:**
1. Click **"Auto-Recalculate"** button
2. Confirm the action
3. System will recalculate all members' net balances
4. Results are logged in audit trail

### 2. **View Member Details**
- Click **"Details"** button next to any member
- Shows breakdown:
  - Transaction count
  - Total Collection
  - Total Distribution
  - Total Interest
  - Total Fixed Amount
  - First and Last transaction dates
  - Calculated Net Balance

### 3. **Filter by Academic Year**
- Use the Year dropdown to view net balances for specific year
- "All Years" shows combined balance across all years

### 4. **Export Report**
- Export net balance data as CSV file
- Includes all members and their calculated balances
- Useful for analysis or sharing with management

## Implementation Across Portals

### Centralized Functions (includes/net-balance-helper.php)

#### calculateMemberNetBalance($memberId, $yearId = null)
Calculates and returns the net balance for a specific member.

**Usage:**
```php
$balance = calculateMemberNetBalance(5); // Member ID 5, all years
$balance = calculateMemberNetBalance(5, 3); // Member ID 5, Year ID 3
```

#### getAllMembersNetBalance($yearId = null)
Returns array of all members with their calculated net balances.

**Usage:**
```php
$members = getAllMembersNetBalance(); // All years
$members = getAllMembersNetBalance(3); // Year ID 3 only
```

#### getMemberNetBalanceDetails($memberId, $yearId = null)
Returns detailed breakdown showing:
- Transaction count
- Total collection, distribution, interest, fixed
- Date range of transactions
- Calculated net balance

**Usage:**
```php
$details = getMemberNetBalanceDetails(5);
echo "Total Collection: " . $details['total_collection'];
echo "Net Balance: " . $details['net_balance'];
```

#### getSystemNetBalanceSummary($yearId = null)
Returns system-wide summary of all transactions.

**Usage:**
```php
$summary = getSystemNetBalanceSummary();
echo "System Net Balance: " . $summary['net_balance'];
```

#### autoRecalculateAllNetBalances($yearId = null)
Automatically recalculates all members' net balances and logs changes.

**Usage:**
```php
$result = autoRecalculateAllNetBalances();
if ($result['success']) {
    echo "Recalculated " . $result['changes'] . " members";
}
```

## API Endpoints (superadmin/api-net-balance.php)

### Available Actions

1. **auto_recalculate** - Recalculate all members' net balance
   ```
   POST api-net-balance.php
   action=auto_recalculate&year_id=3
   ```

2. **get_member_details** - Get breakdown for specific member
   ```
   POST api-net-balance.php
   action=get_member_details&member_id=5&year_id=3
   ```

3. **get_all_balances** - Get all members' net balances
   ```
   POST api-net-balance.php
   action=get_all_balances&year_id=3
   ```

4. **get_system_summary** - Get system-wide summary
   ```
   POST api-net-balance.php
   action=get_system_summary&year_id=3
   ```

5. **export_report** - Export report data
   ```
   POST api-net-balance.php
   action=export_report&year_id=3
   ```

## Integration with Dashboards

### Admin Portal (admin/dashboard-new.php)
- Now includes net-balance-helper.php
- Can use centralized functions for accurate balance display
- Update balance calculations to use `calculateMemberNetBalance()`

### Member Portal
- Shows accurate net balance from centralized calculation
- Updated whenever accessed

### Super Admin Dashboard
- Quick access to Net Balance Manager
- Includes net-balance-helper.php for all calculations
- Full audit trail of all recalculations

## Audit Logging

All net balance operations are logged in `audit_logs` table:
- **Auto-recalculation attempts** - User, timestamp, members affected
- **Manual balance updates** - Old and new values with reason
- **Exports** - When reports are generated

View logs: Super Admin > Audit Logs

## Best Practices

1. **Auto-recalculate regularly**
   - After bulk transaction imports
   - When investigating balance discrepancies
   - Monthly review recommended

2. **Export reports**
   - Keep monthly snapshots for audit
   - Share with management/stakeholders
   - Document any manual adjustments

3. **Monitor audit logs**
   - Track all balance changes
   - Identify anomalies
   - Maintain compliance records

4. **Year-specific calculations**
   - Calculate per academic year for accurate reporting
   - Use "All Years" for complete system overview

## Troubleshooting

### "Invalid member ID" error
- Ensure member ID exists in the system
- Check member status (should be Active)

### Unexpected balance values
- Check for duplicate transactions
- Review all transaction amounts
- Run auto-recalculate to refresh

### Missing calculations
- Ensure all required tables exist (transactions, members, etc.)
- Check database connection
- Review error logs

## Database Requirements

The system requires these tables:
- `transactions` - With columns: MemberID, YearID, CollectionAmount, DistributedAmount, InterestAmount, FixedAmount, TransactionDate
- `members` - With columns: MemberID, MemberName, Status
- `academic_years` - With columns: YearID, YearName, FixedAmount
- `year_members` - With columns: MemberID, YearID, Status
- `audit_logs` - For logging all operations

## Version
**Current Version:** 1.0.0
**Last Updated:** February 2026
