# Comprehensive Audit Logging System

## Overview
All user actions across Admin and Member modules are now logged in the `audit_logs` table, providing SuperAdmin with complete visibility into who did what, when, and where.

## What Gets Logged

### ✅ Authentication Events

#### Admin Authentication
- **Admin Login** - Successful admin login with timestamp and IP
- **Admin Logout** - When admin logs out of the system
- **Failed Login Attempt** - Failed admin login attempts (security monitoring)
- **Change Password** - When admin changes their password

#### Member Authentication
- **Member Login** - Successful member login with timestamp and IP
- **First Time Login** - Member's first login to the system
- **Failed Login Attempt** - Failed member login attempts
- **Set Password** - When member sets password for first time
- **Change Password** - When member changes their password
- **Member Logout** - When member logs out of the system

### ✅ Member Management (Admin Actions)

#### Create Operations
- **Create Member** - Admin adds new member
  - Logs: Member name, phone, email, status
  - Severity: Medium
  - Module: Members

#### Update Operations
- **Update Member** - Admin edits member information
  - Logs: Old values → New values (name, phone, email, status)
  - Severity: Medium
  - Module: Members

#### Delete Operations
- **Delete Member** - Admin permanently deletes member
  - Logs: Member name and ID
  - Severity: Critical
  - Module: Members

- **Deactivate Member** - Admin marks member inactive (has transactions)
  - Logs: Member name, transaction count
  - Severity: High
  - Module: Members

### ✅ Transaction Management (Admin Actions)

#### Create Operations
- **Create Transaction** - Admin adds new transaction
  - Logs: Member name, collection amount, distribution amount, month, payment mode
  - Severity: Medium
  - Module: Transactions

### ✅ Academic Year Management (Admin Actions)

#### Create Operations
- **Create Academic Year** - Admin creates new year
  - Logs: Year name, start date, end date, status
  - Severity: High
  - Module: Settings

#### Update Operations
- **Update Academic Year** - Admin modifies year settings
  - Logs: Old values → New values (year name, status, interest rate)
  - Severity: High
  - Module: Settings

#### Delete Operations
- **Delete Academic Year** - Admin deletes academic year
  - Logs: Year name and ID
  - Severity: Critical
  - Module: Settings

### ✅ Profile Management

#### Admin Profile
- **Update Own Profile** - Admin updates username/email
  - Logs: Old username/email → New username/email
  - Severity: Low
  - Module: Settings

#### Member Profile
- **Update Own Profile** - Member updates their information
  - Logs: Old name/phone/email → New name/phone/email
  - Severity: Low
  - Module: Members

## Audit Log Data Structure

### Database Table: `audit_logs`

```sql
CREATE TABLE audit_logs (
    LogID INT AUTO_INCREMENT PRIMARY KEY,
    UserType ENUM('SuperAdmin', 'Admin', 'Member', 'System') NOT NULL,
    UserID INT NOT NULL,
    Username VARCHAR(100),
    Action VARCHAR(100) NOT NULL,
    TableName VARCHAR(100),
    RecordID INT,
    OldData TEXT,              -- JSON of old values
    NewData TEXT,              -- JSON of new values
    IPAddress VARCHAR(45),
    UserAgent TEXT,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Module VARCHAR(50),        -- Authentication, Members, Transactions, Settings, Reports, System
    Severity ENUM('Low', 'Medium', 'High', 'Critical')
);
```

### Log Entry Example

#### Member Creation
```json
{
    "LogID": 145,
    "UserType": "Admin",
    "UserID": 3,
    "Username": "admin_user",
    "Action": "Create Member",
    "TableName": "members",
    "RecordID": 89,
    "OldData": null,
    "NewData": {
        "MemberName": "John Doe",
        "Phone": "9876543210",
        "Email": "john@example.com",
        "Status": "Active"
    },
    "IPAddress": "192.168.1.100",
    "UserAgent": "Mozilla/5.0...",
    "CreatedAt": "2025-11-21 14:30:25",
    "Module": "Members",
    "Severity": "Medium"
}
```

#### Member Login
```json
{
    "LogID": 146,
    "UserType": "Member",
    "UserID": 45,
    "Username": "Rajesh Kumar",
    "Action": "Member Login",
    "TableName": "members",
    "RecordID": 45,
    "OldData": null,
    "NewData": null,
    "IPAddress": "192.168.1.105",
    "UserAgent": "Mozilla/5.0...",
    "CreatedAt": "2025-11-21 09:15:43",
    "Module": "Authentication",
    "Severity": "Low"
}
```

## Viewing Audit Logs (SuperAdmin)

### Access Audit Logs Page
1. Login as SuperAdmin
2. Navigate to **Audit Logs** from dashboard
3. URL: `superadmin/audit-logs.php`

### Filter Options

#### By User Type
- SuperAdmin
- Admin
- Member
- System

#### By Module
- Authentication (logins, logouts, password changes)
- Members (create, update, delete members)
- Transactions (payment operations)
- Settings (year management, profile updates)
- Reports (report generation, exports)
- System (system-level operations)

#### By Action
Filter by specific actions like:
- Login, Logout
- Create Member, Update Member, Delete Member
- Create Transaction
- Create Academic Year, Update Academic Year
- Change Password

#### By Date Range
- From Date
- To Date
- View specific time periods

#### By Search
Search across:
- Action names
- Usernames
- IP addresses
- Table names

### Audit Log Display

Each log entry shows:
- **Log ID** - Unique identifier
- **User** - Who performed the action (username + email/type)
- **Action** - What was done
- **Details** - Additional context (from OldData/NewData)
- **IP Address** - Where it came from
- **Date & Time** - When it happened

### Export Audit Logs
- **CSV Export** - Download filtered logs as CSV
- Includes all visible columns
- Preserves filters and search criteria

## Security Features

### Automatic Logging
- **No manual intervention** - All operations logged automatically
- **Cannot be bypassed** - Integrated at code level
- **Tamper-resistant** - Logs created immediately after action

### IP Address Tracking
- Captures user's IP address for security
- Helps identify unauthorized access
- Geographic tracking possible

### User Agent Logging
- Records browser and device information
- Helps identify suspicious patterns
- Useful for security audits

### Severity Levels

#### Low (Information)
- Regular logins/logouts
- Profile views
- Report generation

#### Medium (Standard Operations)
- Create/Update operations
- Password changes
- First-time logins
- Failed login attempts

#### High (Important Changes)
- Member deactivation
- Year creation/updates
- Bulk operations

#### Critical (High-Risk Actions)
- Member deletion
- Year deletion
- Data exports
- Permission changes

## Use Cases

### 1. Track Member Changes
**Question:** "Who updated Rajesh Kumar's phone number?"

**Solution:**
1. Go to Audit Logs
2. Filter by Module: "Members"
3. Filter by Action: "Update Member"
4. Search: "Rajesh Kumar"
5. View results showing admin who made change, old phone, new phone, timestamp

### 2. Monitor Login Activity
**Question:** "When did Admin John last login?"

**Solution:**
1. Go to Audit Logs
2. Filter by Module: "Authentication"
3. Filter by Action: "Admin Login"
4. Search: "John"
5. View most recent login with timestamp and IP

### 3. Investigate Failed Logins
**Question:** "Are there repeated failed login attempts?"

**Solution:**
1. Go to Audit Logs
2. Filter by Module: "Authentication"
3. Filter by Action: "Failed Login Attempt"
4. Sort by Date (newest first)
5. Check for patterns (same IP, multiple attempts)

### 4. Track Transaction Creation
**Question:** "Who added the ₹5000 transaction for Member #45?"

**Solution:**
1. Go to Audit Logs
2. Filter by Module: "Transactions"
3. Filter by Action: "Create Transaction"
4. Search: "5000"
5. View admin who created it and all transaction details

### 5. Monitor Year Management
**Question:** "Who closed Academic Year 2024-25?"

**Solution:**
1. Go to Audit Logs
2. Filter by Module: "Settings"
3. Filter by Action: "Update Academic Year"
4. Search: "2024-25"
5. View status changes (Active → Closed) with admin details

### 6. Profile Update History
**Question:** "When did member change their email?"

**Solution:**
1. Go to Audit Logs
2. Filter by Module: "Members"
3. Filter by Action: "Update Own Profile"
4. Search: member name
5. View old email → new email with timestamp

## Audit Log Retention

### Current Setup
- **No automatic deletion** - All logs retained indefinitely
- **Manual cleanup** - SuperAdmin can delete old logs if needed

### Recommended Retention
- **Active logs**: Last 6 months (quick access)
- **Archived logs**: 7 years (compliance requirement)
- **Critical logs**: Never delete (security incidents)

### Storage Optimization
Use the cleanup stored procedure (created in notification system):
```sql
CALL CleanupOldAuditLogs(180); -- Delete logs older than 180 days
```

## Reports Available

### Login Activity Report
- Total logins per user
- Failed login attempts
- Login times (peak hours)
- IP address distribution

### Member Changes Report
- Members created (by admin)
- Members updated (by admin)
- Members deleted/deactivated
- Profile updates (by members)

### Transaction Activity Report
- Transactions created (by admin)
- Total amounts recorded
- Payment mode distribution
- Monthly transaction trends

### Year Management Report
- Years created/updated
- Year status changes
- Interest rate modifications

## Integration Points

### Files with Audit Logging

#### Admin Module
✅ `admin/add-member.php` - Member creation
✅ `admin/edit-member.php` - Member updates
✅ `admin/delete-member.php` - Member deletion/deactivation
✅ `admin/add-transaction.php` - Transaction creation
✅ `admin/manage-years.php` - Year create/update/delete
✅ `admin/profile.php` - Admin profile updates and password changes
✅ `admin/logout.php` - Admin logout

#### Authentication
✅ `auth/admin-login.php` - Admin login (success/failure)
✅ `auth/member-login.php` - Member login (success/failure/first-time)
✅ `auth/change-password.php` - Password changes (first-time/regular)
✅ `auth/logout.php` - Member logout

#### Member Module
✅ `member/update-profile.php` - Member profile updates

### SuperAdmin Pages
✅ `superadmin/audit-logs.php` - View and filter all audit logs

## Troubleshooting

### Issue: Audit logs not appearing
**Check:**
1. Is `createAuditLog()` function called in the code?
2. Is `functions.php` included in the file?
3. Check database connection
4. Verify `audit_logs` table exists

**Solution:**
```php
// Ensure these are at top of file
require_once '../config/config.php';
require_once '../includes/functions.php';
```

### Issue: Username shows as NULL
**Cause:** `Username` field not populated from session

**Solution:** Update `createAuditLog()` to fetch from session:
```php
$username = $_SESSION['admin_username'] ?? $_SESSION['member_name'] ?? 'Unknown';
```

### Issue: OldData/NewData shows as empty
**Cause:** Not passing data to `createAuditLog()`

**Solution:** Always pass data:
```php
createAuditLog(
    $userID,
    'Admin',
    'Members',
    'Update Member',
    "Description",
    'members',
    $memberID,
    json_encode($oldData),    // Must be JSON
    json_encode($newData),    // Must be JSON
    'Medium'
);
```

## Best Practices

### 1. Log Before and After
Always capture state before and after changes:
```php
// Get old data
$oldData = $pdo->prepare("SELECT * FROM members WHERE MemberID = ?")->fetch();

// Make change
$pdo->prepare("UPDATE members SET...")->execute();

// Log with both old and new
createAuditLog(..., json_encode($oldData), json_encode($newData), ...);
```

### 2. Use Descriptive Actions
Make actions clear and searchable:
- ✅ "Create Member"
- ✅ "Update Member Profile"
- ✅ "Delete Academic Year"
- ❌ "Update"
- ❌ "Change"

### 3. Set Appropriate Severity
- **Low**: Regular operations, views
- **Medium**: Standard CRUD operations
- **High**: Important changes, status changes
- **Critical**: Deletions, security events

### 4. Include Context in Description
Provide enough detail to understand the action:
```php
"Created new member: John Doe (ID: 123)"  // ✅ Good
"Member created"  // ❌ Too vague
```

### 5. Always Log User Actions
Never skip logging for:
- Authentication events
- Data modifications
- Permission changes
- Exports/downloads

## Privacy & Compliance

### Data Retention
- Audit logs may contain personal information
- Follow data protection regulations (GDPR, etc.)
- Define retention policy based on legal requirements

### Access Control
- Only SuperAdmin can view audit logs
- Regular admins cannot see logs
- Members cannot access any audit information

### Sensitive Data
- Passwords are never logged (only fact of change)
- Financial amounts are logged for accountability
- Personal info logged for change tracking

## Future Enhancements

### Planned Features
- [ ] Real-time audit log alerts (email on critical actions)
- [ ] Advanced analytics dashboard
- [ ] Anomaly detection (unusual patterns)
- [ ] Automated compliance reports
- [ ] Log archival system
- [ ] Detailed activity timeline per user
- [ ] IP geolocation on logs
- [ ] Failed login lockout mechanism

## Summary

### Complete Audit Trail
✅ **16 different action types** logged across all modules
✅ **Authentication events** - All logins, logouts, password changes
✅ **Member operations** - Create, update, delete, deactivate
✅ **Transaction operations** - All payment activities
✅ **Year management** - All academic year changes
✅ **Profile updates** - Admin and member profile changes

### SuperAdmin Benefits
- **Complete visibility** into all system activities
- **Security monitoring** through failed login tracking
- **Accountability** - Know exactly who did what
- **Audit compliance** - Complete paper trail
- **Troubleshooting** - Track down issues quickly
- **User activity** - Monitor admin and member behavior

### Data Captured
- **Who**: User ID, Username, User Type
- **What**: Action performed, Old/New values
- **When**: Timestamp down to the second
- **Where**: IP Address, User Agent
- **Why**: Module context, Severity level

The SuperAdmin now has complete oversight of all system activities through the comprehensive audit logging system!
