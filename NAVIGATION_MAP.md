# 🗺️ SR Colony System - Complete Navigation Map

## 📁 Directory Structure

```
Root (/)
├── index.html                  # Homepage
├── config/
│   └── config.php              # Database configuration
├── includes/
│   ├── session.php             # Session management
│   └── functions.php           # Common functions
├── assets/
│   ├── css/
│   │   ├── styles.css          # Global styles
│   │   ├── admin-styles.css    # Admin-specific styles
│   │   └── login-styles.css    # Login page styles
│   ├── js/
│   │   ├── script.js           # Global scripts
│   │   └── admin-scripts.js    # Admin-specific scripts
│   └── uploads/
│       ├── profiles/           # Profile pictures
│       └── documents/          # Member documents
├── auth/
│   ├── member-login.php        # Member authentication
│   ├── admin-login.php         # Admin authentication
│   ├── change-password.php     # Password change
│   ├── forgot-password.php     # Password recovery
│   └── logout.php              # Logout (all users)
├── member/
│   ├── dashboard.php           # Old dashboard (redirects)
│   ├── dashboard-new.php       # New modern dashboard
│   ├── member.php              # Member profile view
│   ├── my-payments.php         # Payment history
│   ├── documents.php           # Document management
│   ├── export-statement.php    # Export transactions
│   ├── notifications.php       # View notifications (NEW)
│   ├── profile.php             # Edit profile (NEW)
│   └── update-profile.php      # Profile update handler (NEW)
├── admin/
│   ├── dashboard.php           # Old dashboard (redirects)
│   ├── dashboard-new.php       # New modern dashboard
│   ├── manage-members.php      # Member management
│   ├── add-member.php          # Add new member
│   ├── edit-member.php         # Edit member
│   ├── delete-member.php       # Delete member
│   ├── view-member.php         # View member details
│   ├── manage-transactions.php # Transaction management
│   ├── add-transaction.php     # Add transaction
│   ├── manage-years.php        # Academic year management
│   ├── reports.php             # Generate reports (NEW)
│   ├── notifications.php       # Manage notifications (NEW)
│   ├── profile.php             # Admin profile
│   └── logout.php              # Admin logout
└── superadmin/
    ├── login.php               # Super admin login
    ├── dashboard.php           # Super admin dashboard
    ├── session.php             # Session handler
    ├── logout.php              # Super admin logout
    ├── manage-admins.php       # Admin management (NEW)
    ├── manage-transactions.php # Advanced transaction view
    ├── reports.php             # Advanced reports
    ├── settings.php            # System settings (NEW)
    ├── database-backup.php     # Backup module
    ├── audit-logs.php          # Audit log viewer
    └── dbadmin/
        └── dbadmin.php         # Database admin interface
```

---

## 🔐 Portal Entry Points

### Public Access
- **Homepage**: `/index.html`
  - Links to: Member Login, Admin Login

### Member Portal
- **Login**: `/auth/member-login.php`
- **Dashboard**: `/member/dashboard-new.php`

### Admin Portal
- **Login**: `/auth/admin-login.php`
- **Dashboard**: `/admin/dashboard-new.php`

### Super Admin Portal
- **Login**: `/superadmin/login.php`
- **Dashboard**: `/superadmin/dashboard.php`

---

## 🧭 Navigation Paths by Portal

### **Member Portal** (`/member/`)

#### From: dashboard-new.php
| Destination | Relative Path | Full Path |
|-------------|---------------|-----------|
| Dashboard | `dashboard-new.php` | `/member/dashboard-new.php` |
| Profile View | `member.php?id={memberid}` | `/member/member.php?id={memberid}` |
| Payment History | `my-payments.php` | `/member/my-payments.php` |
| Documents | `documents.php` | `/member/documents.php` |
| Notifications | `notifications.php` | `/member/notifications.php` |
| Settings | `profile.php` | `/member/profile.php` |
| Export Statement | `export-statement.php` | `/member/export-statement.php` |
| Logout | `../auth/logout.php` | `/auth/logout.php` |

#### From: member.php
| Destination | Relative Path | Full Path |
|-------------|---------------|-----------|
| Change Password | `../auth/change-password.php` | `/auth/change-password.php` |
| Logout | `../auth/logout.php` | `/auth/logout.php` |

#### From: my-payments.php
| Destination | Relative Path | Full Path |
|-------------|---------------|-----------|
| Dashboard | `dashboard-new.php` | `/member/dashboard-new.php` |
| Logout | `../auth/logout.php` | `/auth/logout.php` |

#### From: documents.php
| Destination | Relative Path | Full Path |
|-------------|---------------|-----------|
| Dashboard | `dashboard-new.php` | `/member/dashboard-new.php` |
| Download File | `../assets/uploads/documents/{filename}` | `/assets/uploads/documents/{filename}` |

#### From: export-statement.php
| Destination | Relative Path | Full Path |
|-------------|---------------|-----------|
| Dashboard | `dashboard-new.php` | `/member/dashboard-new.php` |

---

### **Admin Portal** (`/admin/`)

#### From: dashboard-new.php
| Destination | Relative Path | Full Path |
|-------------|---------------|-----------|
| Dashboard | `dashboard-new.php` | `/admin/dashboard-new.php` |
| Manage Members | `manage-members.php` | `/admin/manage-members.php` |
| Transactions | `manage-transactions.php` | `/admin/manage-transactions.php` |
| Academic Years | `manage-years.php` | `/admin/manage-years.php` |
| Reports | `reports.php` | `/admin/reports.php` |
| Notifications | `notifications.php` | `/admin/notifications.php` |
| My Profile | `profile.php` | `/admin/profile.php` |
| Add Member | `add-member.php` | `/admin/add-member.php` |
| Add Transaction | `add-transaction.php` | `/admin/add-transaction.php` |
| Logout | `logout.php` | `/admin/logout.php` |

#### From: manage-members.php
| Destination | Relative Path | Full Path |
|-------------|---------------|-----------|
| Dashboard | `dashboard-new.php` | `/admin/dashboard-new.php` |
| Add Member | `add-member.php` | `/admin/add-member.php` |
| Edit Member | `edit-member.php?id={memberid}` | `/admin/edit-member.php?id={memberid}` |
| View Member | `view-member.php?id={memberid}` | `/admin/view-member.php?id={memberid}` |
| Delete Member | `delete-member.php?id={memberid}` | `/admin/delete-member.php?id={memberid}` |

#### From: add-member.php, edit-member.php
| Destination | Relative Path | Full Path |
|-------------|---------------|-----------|
| Manage Members | `manage-members.php` | `/admin/manage-members.php` |
| Dashboard | `dashboard-new.php` | `/admin/dashboard-new.php` |

#### From: manage-transactions.php
| Destination | Relative Path | Full Path |
|-------------|---------------|-----------|
| Dashboard | `dashboard-new.php` | `/admin/dashboard-new.php` |
| Add Transaction | `add-transaction.php` | `/admin/add-transaction.php` |

---

### **Super Admin Portal** (`/superadmin/`)

#### From: dashboard.php
| Destination | Relative Path | Full Path |
|-------------|---------------|-----------|
| Dashboard | `dashboard.php` | `/superadmin/dashboard.php` |
| Manage Admins | `manage-admins.php` | `/superadmin/manage-admins.php` |
| Transactions | `manage-transactions.php` | `/superadmin/manage-transactions.php` |
| Reports | `reports.php` | `/superadmin/reports.php` |
| Settings | `settings.php` | `/superadmin/settings.php` |
| Database Backup | `database-backup.php` | `/superadmin/database-backup.php` |
| Audit Logs | `audit-logs.php` | `/superadmin/audit-logs.php` |
| DB Admin | `dbadmin/dbadmin.php` | `/superadmin/dbadmin/dbadmin.php` |
| Logout | `logout.php` | `/superadmin/logout.php` |

#### From: dbadmin/dbadmin.php
| Destination | Relative Path | Full Path |
|-------------|---------------|-----------|
| Back to Dashboard | `../dashboard.php` | `/superadmin/dashboard.php` |

---

## 🎨 Asset Paths

### CSS Files

#### From: `/member/*.php`
```html
<link rel="stylesheet" href="../assets/css/styles.css">
<link rel="stylesheet" href="../assets/css/admin-styles.css">
```

#### From: `/admin/*.php`
```html
<link rel="stylesheet" href="../assets/css/styles.css">
<link rel="stylesheet" href="../assets/css/admin-styles.css">
```

#### From: `/auth/*.php`
```html
<link rel="stylesheet" href="../assets/css/styles.css">
<link rel="stylesheet" href="../assets/css/login-styles.css">
```

#### From: `/superadmin/*.php`
```html
<link rel="stylesheet" href="../assets/css/admin-styles.css">
<link rel="stylesheet" href="shared-styles.css">
```

#### From: `/superadmin/dbadmin/dbadmin.php`
```html
<link rel="stylesheet" href="../../assets/css/admin-styles.css">
```

### JavaScript Files

#### From: `/member/*.php`, `/admin/*.php`
```html
<script src="../assets/js/script.js"></script>
<script src="../assets/js/admin-scripts.js"></script>
```

#### From: `/auth/*.php`
```html
<script src="../assets/js/script.js"></script>
```

---

## 📤 Redirect Paths

### After Login

#### Member Login Success
```php
// From: /auth/member-login.php
header('Location: ../member/dashboard.php');  // Redirects to dashboard-new.php
```

#### Admin Login Success
```php
// From: /auth/admin-login.php
header('Location: ../admin/dashboard.php');  // Should redirect to dashboard-new.php
```

#### Super Admin Login Success
```php
// From: /superadmin/login.php
header('Location: dashboard.php');
```

### After Logout

#### Member Logout
```php
// From: /auth/logout.php
header('Location: member-login.php?logout=success');
```

#### Admin Logout
```php
// From: /admin/logout.php
header('Location: ../auth/admin-login.php?logout=success');
```

#### Super Admin Logout
```php
// From: /superadmin/logout.php
header('Location: login.php');
```

### After Actions

#### Member Actions
```php
// Password change required
header('Location: change-password.php');

// After password change
header('Location: ../member/dashboard.php');
```

#### Admin Actions
```php
// After member edit/add
header('Location: manage-members.php');

// After delete
header('Location: manage-members.php?success=member_deleted');
```

---

## 🔧 Required Path Fixes

### Issues Found:

1. **Admin Dashboard Old Redirect**
   - File: `/admin/dashboard.php`
   - Current: May not redirect properly
   - Fix: Should redirect to `dashboard-new.php`

2. **Admin Navigation Links**
   - Some files may reference old `dashboard.php` instead of `dashboard-new.php`

3. **Missing Files Referenced**
   - `/member/notifications.php` - Referenced but not created yet
   - `/member/profile.php` - Referenced but not created yet
   - `/admin/notifications.php` - Referenced but not created yet
   - `/admin/reports.php` - Referenced but not created yet

4. **Document Upload Path**
   - Current: `../assets/uploads/documents/`
   - Verify directory exists and has write permissions

5. **Profile Picture Path**
   - Referenced: `../assets/uploads/profiles/`
   - Verify directory exists

---

## ✅ Path Conventions

### Best Practices:

1. **Relative Paths from Portal Files:**
   - To assets: `../assets/`
   - To auth: `../auth/`
   - Within portal: `filename.php` (same directory)
   - To other portal: `../portal-name/`

2. **Asset Loading:**
   - Always use relative paths from file location
   - Member/Admin: `../assets/css/styles.css`
   - Super Admin: `../assets/css/styles.css` or local `shared-styles.css`

3. **Redirects:**
   - Use relative paths for same portal
   - Use `../portal/` for cross-portal
   - Always use `header('Location: ');` with `exit;`

4. **File References:**
   - Document uploads: Store in `/assets/uploads/documents/`
   - Profile pictures: Store in `/assets/uploads/profiles/`
   - Database: Store relative path only (e.g., `member123_doc.pdf`)

---

## 🚀 Implementation Priority

### Phase 1: Critical Fixes (Immediate)
- [ ] Create missing placeholder files (notifications.php, profile.php, reports.php)
- [ ] Update admin/dashboard.php redirect
- [ ] Verify all auth/* redirects
- [ ] Create missing upload directories

### Phase 2: Navigation Updates (High Priority)
- [ ] Update all dashboard references to dashboard-new.php
- [ ] Standardize logout paths
- [ ] Fix asset paths in all files

### Phase 3: Testing (Medium Priority)
- [ ] Test all member portal navigation
- [ ] Test all admin portal navigation
- [ ] Test all super admin portal navigation
- [ ] Test cross-portal navigation

### Phase 4: Documentation (Low Priority)
- [ ] Update user guides with correct paths
- [ ] Create navigation flow diagrams
- [ ] Document API endpoints (if any)

---

## 📝 Notes

- All paths are case-sensitive on Linux servers
- Ensure `.htaccess` is configured for clean URLs (if needed)
- Consider implementing URL constants in `config.php` for easier maintenance
- Use `BASE_URL` constant for absolute paths when needed

---

**Last Updated:** October 21, 2025  
**Version:** 2.0  
**Status:** Navigation audit complete, implementing fixes
