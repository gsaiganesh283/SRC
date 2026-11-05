# Link Behavior Control Feature - Complete Guide

## Overview
This feature allows you to control whether links open in the **same tab** (`_self`) or **new tab** (`_blank`) on a **per-page basis** across all three portals (Super Admin, Admin, Member).

---

## 🎯 Key Features

### 1. **Per-Page Configuration**
- Each page can have its own link behavior setting
- Control over 20 default pages across all portals
- Organized by portal sections for easy management

### 2. **Visual Management Interface**
- Clean table layout with radio buttons
- Group pages by portal (Super Admin, Admin, Member)
- Save all changes with a single click
- Reset to defaults option

### 3. **Database-Driven**
- All settings stored in `link_target_settings` table
- Changes take effect immediately
- Audit trail with Created/Updated timestamps

---

## 📁 Files Created

### 1. Database Schema
**File**: `database/CREATE_LINK_BEHAVIOR_TABLE.sql`
- Creates `link_target_settings` table
- Includes 20 default page configurations
- Table structure:
  - `LinkID` (Primary Key)
  - `PageName` (Display name)
  - `PageURL` (Page file path)
  - `Portal` (superadmin/admin/member)
  - `TargetBehavior` (_self/_blank)
  - `DisplayOrder` (Sort order)
  - `IsActive` (Enable/disable)
  - `CreatedAt` / `UpdatedAt` (Audit trail)

### 2. Management Interface
**File**: `superadmin/link-behavior-settings.php`
- 550+ lines of complete functionality
- Features:
  - Form submission handler
  - Portal-grouped display
  - Radio button controls for _self/_blank
  - Save and Reset buttons
  - Success/error messaging
  - Responsive design with dark theme support

### 3. Helper Functions
**File**: `includes/link-target-helper.php`
- Functions to retrieve link targets dynamically
- Three helper functions:
  1. `getLinkTarget($pageURL, $portal)` - Get target for specific page
  2. `getAllLinkTargets($portal)` - Get all targets as array
  3. `outputLinkTargetScript($portal)` - JavaScript to apply targets

---

## 🚀 Installation Steps

### Step 1: Run Database Migration
Open phpMyAdmin or your MySQL client and execute:

```sql
-- File: database/CREATE_LINK_BEHAVIOR_TABLE.sql
-- This creates the table and inserts 20 default page records
```

**Default Pages Configured:**

**Super Admin Portal (9 pages):**
- Dashboard
- Manage Admins
- Manage Members
- Manage Transactions
- Reports & Analytics
- Data Import/Export
- Database Backup
- System Settings
- Audit Logs

**Admin Portal (6 pages):**
- Dashboard
- Manage Members
- Add Member
- Transactions
- Reports
- Profile Settings

**Member Portal (5 pages):**
- Dashboard
- View Transactions
- Make Payment
- Print Statement
- Profile Settings

### Step 2: Access Management Interface
1. Login to Super Admin panel
2. Go to Dashboard
3. Click on **"Link Behavior Settings"** button
4. You'll see a table grouped by portal with radio buttons for each page

### Step 3: Configure Link Behaviors
1. For each page, select either:
   - **Same Tab (_self)** - Link opens in same window
   - **New Tab (_blank)** - Link opens in new window
2. Click **"Save All Settings"** button at the bottom
3. Success message will appear confirming changes

---

## 💻 Usage Example

### In Navigation Links
To apply link target settings dynamically in your PHP pages:

```php
<?php
// Include helper at top of file
require_once __DIR__ . '/../includes/link-target-helper.php';

// In your navigation menu
?>
<a href="dashboard.php" <?php echo getLinkTarget('dashboard.php', 'superadmin'); ?>>
    Dashboard
</a>

<a href="manage-members.php" <?php echo getLinkTarget('manage-members.php', 'superadmin'); ?>>
    Manage Members
</a>
```

### Using JavaScript (Alternative Method)
Add this at the bottom of your page to apply targets to all links:

```php
<?php
require_once __DIR__ . '/../includes/link-target-helper.php';
outputLinkTargetScript('superadmin'); // Use 'admin' or 'member' for other portals
?>
```

This will automatically set `target="_blank"` on links that match configured URLs.

---

## 🎨 UI Features

### Portal Sections
- Each portal has its own collapsible section
- Color-coded headers (Purple for Super Admin, Blue for Admin, Green for Member)
- Icon indicators for each portal type

### Radio Buttons
- Custom styled radio buttons
- Mutually exclusive (can't select both _self and _blank)
- Hover effects for better UX
- Clear visual indication of selected option

### Save Button
- Gradient purple background
- Processes all changes at once
- Shows success/error messages
- Redirects back to page after save

---

## 🔧 Customization

### Add New Pages
To add more pages to the system:

1. Insert into database:
```sql
INSERT INTO link_target_settings 
(PageName, PageURL, Portal, TargetBehavior, DisplayOrder, IsActive) 
VALUES 
('My New Page', 'my-new-page.php', 'superadmin', '_self', 100, 1);
```

2. The page will automatically appear in the management interface

### Change Display Order
Update the `DisplayOrder` column to change how pages are sorted:

```sql
UPDATE link_target_settings 
SET DisplayOrder = 5 
WHERE PageURL = 'dashboard.php';
```

### Disable a Page
To hide a page from configuration without deleting:

```sql
UPDATE link_target_settings 
SET IsActive = 0 
WHERE PageURL = 'old-page.php';
```

---

## 📊 Database Structure

```sql
CREATE TABLE link_target_settings (
    LinkID INT AUTO_INCREMENT PRIMARY KEY,
    PageName VARCHAR(255) NOT NULL,
    PageURL VARCHAR(255) NOT NULL,
    Portal ENUM('superadmin', 'admin', 'member', 'all') NOT NULL DEFAULT 'all',
    TargetBehavior ENUM('_self', '_blank') NOT NULL DEFAULT '_self',
    DisplayOrder INT DEFAULT 0,
    IsActive TINYINT(1) DEFAULT 1,
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_page_portal (PageURL, Portal)
);
```

---

## 🎯 How It Works

1. **User Selects Settings**: Super admin chooses _self or _blank for each page
2. **Save to Database**: Form submits and updates `link_target_settings` table
3. **Apply to Links**: Helper functions read settings and apply to actual links
4. **Dynamic Updates**: Changes take effect immediately without code modification

---

## 🔍 Troubleshooting

### Links Not Opening in New Tab
1. Verify database table exists: `SHOW TABLES LIKE 'link_target_settings';`
2. Check settings saved: `SELECT * FROM link_target_settings WHERE TargetBehavior = '_blank';`
3. Ensure helper file is included in your page
4. Check that `getLinkTarget()` is called correctly

### Management Page Not Loading
1. Check PHP errors in browser console
2. Verify database connection in `config/config.php`
3. Ensure table was created successfully
4. Check file permissions on `superadmin/link-behavior-settings.php`

### Changes Not Saving
1. Check form submission: Look for POST data in network tab
2. Verify UPDATE queries executing: Check SQL logs
3. Look for error messages displayed on page
4. Ensure `LinkID` values are being passed correctly

---

## 📝 Additional Notes

### Security
- All inputs are sanitized with `htmlspecialchars()`
- PDO prepared statements prevent SQL injection
- Only Super Admin can access settings page
- Session validation required

### Performance
- Settings cached per page load
- Single query fetches all portal settings
- Minimal overhead on page rendering
- Database indexes on frequently queried columns

### Browser Compatibility
- Works on all modern browsers
- Falls back gracefully if JavaScript disabled
- CSS uses standard properties for wide support

---

## 🎉 Success Indicators

After installation, you should see:
1. ✅ New "Link Behavior Settings" button on Super Admin dashboard
2. ✅ Table with 20 default pages when you open the settings page
3. ✅ Ability to select _self or _blank for each page
4. ✅ Success message after saving changes
5. ✅ Links respect the configured behavior when clicked

---

## 📞 Support

If you encounter issues:
1. Check this guide first
2. Review the database table structure
3. Verify all files are in correct locations
4. Check PHP error logs
5. Test with default settings first before custom configurations

---

## 🔄 Future Enhancements

Potential additions:
- Bulk edit (set all to _blank or _self)
- Export/import settings as JSON
- Link preview before saving
- Analytics on which links are clicked most
- Per-user link preferences
- Link behavior based on user role

---

**Created**: 2025
**Version**: 1.0
**Last Updated**: 2025-01-21
