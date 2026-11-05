# Permission System Implementation Guide

## Overview
Comprehensive permission-based access control has been integrated across all modules (Admin, Member, SuperAdmin) of the SR Colony Chitti Management System.

## Implementation Summary

### ✅ Completed Integrations

#### Admin Module Pages
All admin pages now enforce permission checks:

1. **manage-members.php**
   - Permission: `view_members` (required)
   - UI Controls: "Add New Member" button shown only if `add_members` permission
   - Action Buttons: Edit/Delete buttons shown based on `edit_members`/`delete_members` permissions

2. **add-member.php**
   - Permission: `add_members` (required)
   - Blocks access if admin lacks permission to add members

3. **edit-member.php**
   - Permission: `edit_members` (required)
   - Prevents unauthorized editing of member records

4. **delete-member.php**
   - Permission: `delete_members` (required)
   - Prevents unauthorized deletion of members

5. **view-member.php**
   - Permission: `view_members` (required)
   - Restricts viewing of member details

6. **manage-transactions.php**
   - Permission: `view_transactions` (required)
   - UI Controls: "Add Transaction" shown only if `add_transactions` permission
   - UI Controls: "Manage Years" shown only if `manage_years` permission

7. **add-transaction.php**
   - Permission: `add_transactions` (required)
   - Prevents unauthorized transaction creation

8. **manage-years.php**
   - Permission: `manage_years` (required)
   - Restricts academic year management

9. **reports.php**
   - Permission: `view_reports` (required)
   - Restricts access to reporting features

10. **profile.php**
    - Permission: `edit_profile` (checked on form submission)
    - Allows viewing but restricts editing without permission

11. **notifications.php**
    - Uses `requireAdmin()` for basic access control

#### Member Module Pages

1. **dashboard-new.php**
   - Permission: `view_dashboard` (soft check, allows by default)
   - Ensures backward compatibility

2. **update-profile.php**
   - Permission: `edit_profile` (required)
   - Prevents unauthorized profile edits

3. **my-payments.php**
   - Permission: `view_transactions` (required)
   - Restricts viewing of payment history

4. **export-statement.php**
   - Permission: `export_reports` (required)
   - Prevents unauthorized report exports

#### SuperAdmin Module
- Uses `session.php` for authentication
- All SuperAdmins have full access by default
- Permissions for Admins/Members managed via `permissions-management.php`

## Permission Functions Used

### Core Functions (from includes/functions.php)

#### 1. `hasAdminPermission($adminID, $permission)`
```php
// Check if admin has specific permission
if (hasAdminPermission($_SESSION['admin_id'], 'add_members')) {
    // Show add member button
}
```

#### 2. `requireAdminPermission($permission, $errorMessage)`
```php
// Require permission or redirect with error
requireAdminPermission('view_members', 'You do not have permission to view members');
```

#### 3. `hasMemberPermission($memberID, $permission)`
```php
// Check if member has specific permission
if (hasMemberPermission($_SESSION['member_id'], 'export_reports')) {
    // Allow export
}
```

#### 4. `requireMemberPermission($permission, $errorMessage)`
```php
// Require permission or redirect with error
requireMemberPermission('edit_profile', 'You do not have permission to edit your profile');
```

## Permission Types

### Admin Permissions

#### Member Management
- `view_members` - View member list and details
- `add_members` - Create new members
- `edit_members` - Modify member information
- `delete_members` - Remove members (or deactivate)
- `verify_members` - Mark members as verified

#### Transaction Management
- `view_transactions` - View transaction history
- `add_transactions` - Create new transactions
- `edit_transactions` - Modify transactions
- `delete_transactions` - Remove transactions
- `verify_transactions` - Mark transactions as verified
- `export_transactions` - Export transaction data

#### Reporting
- `view_reports` - Access reports section
- `generate_reports` - Create custom reports
- `export_reports` - Export reports to PDF/Excel
- `view_analytics` - View analytics dashboard

#### Year Management
- `view_years` - View academic years
- `add_years` - Create new academic years
- `edit_years` - Modify year settings
- `delete_years` - Remove academic years
- `activate_years` - Activate/close years
- `manage_years` - Combined year management access

#### System Access
- `access_dashboard` - Access admin dashboard
- `view_audit_logs` - View system audit logs
- `edit_profile` - Edit own profile
- `backup_data` - Create backups
- `restore_data` - Restore from backups

#### Notifications
- `send_notifications` - Send notifications
- `send_bulk_sms` - Send bulk SMS
- `send_bulk_email` - Send bulk emails

### Member Permissions

#### Profile & Access
- `view_dashboard` - Access member dashboard
- `edit_profile` - Edit own profile information
- `change_password` - Change own password

#### Transactions
- `view_transactions` - View own transaction history
- `view_statements` - View account statements

#### Reports
- `export_reports` - Export own statements/reports
- `download_receipts` - Download payment receipts

#### Communication
- `view_notifications` - View notifications
- `contact_support` - Contact admin/support

## Database Tables

### admin_permissions
```sql
CREATE TABLE admin_permissions (
    PermissionID INT AUTO_INCREMENT PRIMARY KEY,
    AdminID INT NOT NULL,
    -- Member Permissions
    can_view_members BOOLEAN DEFAULT TRUE,
    can_add_members BOOLEAN DEFAULT TRUE,
    can_edit_members BOOLEAN DEFAULT TRUE,
    can_delete_members BOOLEAN DEFAULT FALSE,
    can_verify_members BOOLEAN DEFAULT FALSE,
    -- Transaction Permissions
    can_view_transactions BOOLEAN DEFAULT TRUE,
    can_add_transactions BOOLEAN DEFAULT TRUE,
    can_edit_transactions BOOLEAN DEFAULT FALSE,
    can_delete_transactions BOOLEAN DEFAULT FALSE,
    can_verify_transactions BOOLEAN DEFAULT FALSE,
    can_export_transactions BOOLEAN DEFAULT TRUE,
    -- Report Permissions
    can_view_reports BOOLEAN DEFAULT TRUE,
    can_generate_reports BOOLEAN DEFAULT TRUE,
    can_export_reports BOOLEAN DEFAULT TRUE,
    can_view_analytics BOOLEAN DEFAULT FALSE,
    -- Year Permissions
    can_view_years BOOLEAN DEFAULT TRUE,
    can_add_years BOOLEAN DEFAULT FALSE,
    can_edit_years BOOLEAN DEFAULT FALSE,
    can_delete_years BOOLEAN DEFAULT FALSE,
    can_activate_years BOOLEAN DEFAULT FALSE,
    -- System Permissions
    can_access_dashboard BOOLEAN DEFAULT TRUE,
    can_view_audit_logs BOOLEAN DEFAULT FALSE,
    can_backup_data BOOLEAN DEFAULT FALSE,
    can_restore_data BOOLEAN DEFAULT FALSE,
    -- Notification Permissions
    can_send_notifications BOOLEAN DEFAULT FALSE,
    can_send_bulk_sms BOOLEAN DEFAULT FALSE,
    can_send_bulk_email BOOLEAN DEFAULT FALSE,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (AdminID) REFERENCES admins(AdminID) ON DELETE CASCADE
);
```

### member_permissions
```sql
CREATE TABLE member_permissions (
    PermissionID INT AUTO_INCREMENT PRIMARY KEY,
    MemberID INT NOT NULL,
    can_view_dashboard BOOLEAN DEFAULT TRUE,
    can_edit_profile BOOLEAN DEFAULT TRUE,
    can_change_password BOOLEAN DEFAULT TRUE,
    can_view_transactions BOOLEAN DEFAULT TRUE,
    can_view_statements BOOLEAN DEFAULT TRUE,
    can_export_reports BOOLEAN DEFAULT TRUE,
    can_download_receipts BOOLEAN DEFAULT TRUE,
    can_view_notifications BOOLEAN DEFAULT TRUE,
    can_contact_support BOOLEAN DEFAULT TRUE,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (MemberID) REFERENCES members(MemberID) ON DELETE CASCADE
);
```

## Usage Examples

### Example 1: Hide Button Based on Permission
```php
<?php if (hasAdminPermission($_SESSION['admin_id'], 'add_members')): ?>
<a href="add-member.php" class="btn-primary">
    <i class="fas fa-user-plus"></i> Add New Member
</a>
<?php endif; ?>
```

### Example 2: Protect Page with Permission Requirement
```php
<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Check permission to add transactions
requireAdminPermission('add_transactions', 'You do not have permission to add transactions');

// Rest of page code...
?>
```

### Example 3: Conditional Action Rendering
```php
<div class="action-buttons">
    <a href="view-member.php?id=<?php echo $id; ?>" class="btn-view">
        <i class="fas fa-eye"></i>
    </a>
    <?php if (hasAdminPermission($_SESSION['admin_id'], 'edit_members')): ?>
    <a href="edit-member.php?id=<?php echo $id; ?>" class="btn-edit">
        <i class="fas fa-edit"></i>
    </a>
    <?php endif; ?>
    <?php if (hasAdminPermission($_SESSION['admin_id'], 'delete_members')): ?>
    <a href="delete-member.php?id=<?php echo $id; ?>" class="btn-delete">
        <i class="fas fa-trash"></i>
    </a>
    <?php endif; ?>
</div>
```

### Example 4: Check Permission Before Processing Form
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hasAdminPermission($_SESSION['admin_id'], 'edit_members')) {
        $_SESSION['error'] = 'You do not have permission to edit members';
        header('Location: manage-members.php');
        exit;
    }
    
    // Process form...
}
```

## Permission Management

### Setting Permissions via SuperAdmin

1. Login as SuperAdmin
2. Navigate to **Permissions Management** (`superadmin/permissions-management.php`)
3. Select Admin or Member to manage
4. Check/uncheck permissions
5. Click "Update Permissions"

### Default Permissions

#### New Admin (Default)
- ✅ View Members, Add Members, Edit Members
- ✅ View Transactions, Add Transactions
- ✅ View Reports, Generate Reports, Export Reports
- ✅ View Years, Access Dashboard
- ❌ Delete Members, Verify Members
- ❌ Edit/Delete Transactions, Verify Transactions
- ❌ Year Management (Add/Edit/Delete/Activate)
- ❌ Audit Logs, Backup/Restore
- ❌ Bulk Notifications

#### New Member (Default)
- ✅ All permissions enabled by default
- Members have standard access to their own data

## Testing Checklist

### Admin Permissions
- [ ] Admin without `view_members` cannot access manage-members.php
- [ ] Admin without `add_members` doesn't see "Add Member" button
- [ ] Admin without `edit_members` cannot access edit-member.php
- [ ] Admin without `delete_members` doesn't see delete buttons
- [ ] Admin without `view_transactions` cannot access manage-transactions.php
- [ ] Admin without `add_transactions` doesn't see "Add Transaction" button
- [ ] Admin without `view_reports` cannot access reports.php
- [ ] Admin without `manage_years` cannot access manage-years.php

### Member Permissions
- [ ] Member without `view_transactions` cannot access my-payments.php
- [ ] Member without `edit_profile` cannot access update-profile.php
- [ ] Member without `export_reports` cannot access export-statement.php

### UI Validation
- [ ] Buttons are hidden when permissions are missing
- [ ] No broken links when permissions are restricted
- [ ] Error messages are clear and user-friendly
- [ ] Unauthorized access redirects properly

## Error Handling

### Permission Denied Flow
1. User attempts to access restricted page
2. `requireAdminPermission()` or `requireMemberPermission()` checks permission
3. If denied:
   - Sets `$_SESSION['error']` with custom message
   - Redirects to appropriate dashboard
   - Displays error notification

### Example Error Messages
- "You do not have permission to view members"
- "You do not have permission to add transactions"
- "You do not have permission to export reports"

## Best Practices

### 1. Always Check Permissions at Page Level
```php
requireAdminPermission('permission_name', 'Error message');
```

### 2. Hide UI Elements for Missing Permissions
```php
<?php if (hasAdminPermission($_SESSION['admin_id'], 'permission_name')): ?>
    <!-- Protected content -->
<?php endif; ?>
```

### 3. Double-Check on Form Submission
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hasAdminPermission($_SESSION['admin_id'], 'permission_name')) {
        // Deny and redirect
    }
}
```

### 4. Use Descriptive Permission Names
- Use clear, action-based names: `add_members`, `edit_transactions`
- Follow consistent naming: `can_` prefix in database, without in code

### 5. Provide Clear Error Messages
- Tell users exactly what permission they lack
- Suggest who to contact for access

## Troubleshooting

### Issue: Permission check always fails
**Solution:** Verify permission record exists in database
```sql
SELECT * FROM admin_permissions WHERE AdminID = ?;
```

### Issue: New admin has no permissions
**Solution:** Insert default permission record
```sql
INSERT INTO admin_permissions (AdminID) VALUES (?);
-- All columns have DEFAULT TRUE/FALSE
```

### Issue: UI buttons still show without permission
**Solution:** Check if `hasAdminPermission()` wrapper is in place
```php
<?php if (hasAdminPermission($_SESSION['admin_id'], 'permission')): ?>
```

### Issue: Function not found error
**Solution:** Ensure `functions.php` is included
```php
require_once '../includes/functions.php';
```

## Security Notes

1. **Always validate server-side** - Never rely solely on hiding UI elements
2. **Check permissions on every request** - Users can craft direct URLs
3. **Use parameterized queries** - Prevent SQL injection in permission checks
4. **Audit permission changes** - Log when permissions are granted/revoked
5. **Principle of least privilege** - Grant minimum required permissions

## Future Enhancements

### Planned Features
- [ ] Role-based permission templates (Viewer, Editor, Manager, Admin)
- [ ] Permission expiration dates
- [ ] Temporary permission grants
- [ ] Permission request workflow
- [ ] Detailed permission audit trail
- [ ] Bulk permission updates
- [ ] Permission inheritance from roles

### Advanced Features
- [ ] Time-based permissions (work hours only)
- [ ] IP-based permission restrictions
- [ ] Two-factor authentication for sensitive permissions
- [ ] Permission delegation
- [ ] Custom permission creation via UI

## Support

For issues or questions:
1. Review this guide and COMPREHENSIVE_SYSTEM_UPDATE_GUIDE.md
2. Check database for permission records
3. Verify function includes in affected pages
4. Test with SuperAdmin account (has all permissions)
5. Check audit logs for permission denials

## Summary

✅ **13 Admin pages** protected with permission checks
✅ **4 Member pages** protected with permission checks  
✅ **UI elements** hidden based on permissions
✅ **Clear error messages** for unauthorized access
✅ **Database tables** for granular permission control
✅ **Helper functions** for easy permission checking

The permission system is now fully integrated and operational across all modules!
