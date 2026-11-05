# SR Colony - Advanced Chitti Management System

![Status](https://img.shields.io/badge/Status-Active-brightgreen)
![PHP Version](https://img.shields.io/badge/PHP-7.4+-blue)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-orange)
![License](https://img.shields.io/badge/License-Proprietary-red)

A comprehensive multi-portal chitti (rotating savings scheme) management system designed for residential colonies. Manage members, transactions, contributions, and generate detailed financial reports with ease.

---

## 📋 Table of Contents

- [Features](#features)
- [System Architecture](#system-architecture)
- [Portals Overview](#portals-overview)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage Guide](#usage-guide)
- [Database Setup](#database-setup)
- [API Documentation](#api-documentation)
- [Troubleshooting](#troubleshooting)
- [Support & Documentation](#support--documentation)

---

## ✨ Features

### Core Functionality
- **Multi-Portal Architecture** - Separate portals for Members, Admins, and Super Admins
- **Transaction Management** - Track contributions, withdrawals, and financial flows
- **Member Management** - Add, edit, delete members with detailed profiles
- **Financial Reports** - Generate comprehensive financial reports with custom charts
- **Audit Logging** - Complete audit trail for all system activities
- **Permission System** - Role-based access control and permissions management
- **Custom Chitti Years** - Manage multiple chitti cycles/years
- **Profile Management** - Member profiles with document uploads

### Advanced Features
- **PDF Export** - Export reports and statements as PDF
- **Excel Integration** - Import/export data via Excel spreadsheets
- **Custom Charts** - Build custom financial analysis charts
- **Notification System** - In-app notifications for transactions and updates
- **Theme Management** - Customizable dashboard themes
- **Logo & Branding** - Configure site name and logo
- **Link Behavior Settings** - Control external link behavior
- **Template System** - Create and manage custom templates
- **Data Import/Export** - Full database backup and restore capabilities

---

## 🏗️ System Architecture

### Three-Portal System

```
SR Colony Management System
├── 👤 Member Portal      (member/)
├── 👨‍💼 Admin Portal       (admin/)
├── 🔐 Super Admin Portal (superadmin/)
└── 🔓 Public Portal      (auth/ + index.php)
```

### Directory Structure

```
/workspaces/SRCOLONY-VIEWS/
├── admin/                  # Admin Dashboard & Management
├── member/                 # Member Portal & Dashboard
├── superadmin/             # Super Admin & System Settings
├── auth/                   # Authentication Pages
├── api/                    # REST API Endpoints
├── config/                 # Configuration Files
├── database/               # SQL Schema & Migrations
├── includes/               # Shared PHP Functions & Helpers
├── assets/                 # CSS, JavaScript, Uploads
│   ├── css/
│   ├── js/
│   └── uploads/            # User profiles and documents
├── documentation/          # System Documentation
└── backups/                # Database Backups
```

---

## 🌐 Portals Overview

### 1. **Public Portal** (`index.php`)
**Access**: Open to everyone  
**Purpose**: Introduction and login portal

**Key Pages**:
- Home page with site information
- Navigation to member/admin login

---

### 2. **Member Portal** (`member/`)
**Access**: Logged-in members only  
**Purpose**: Personal financial management and information

**Key Features**:
- 📊 **Dashboard** - Personal financial overview
- 💰 **My Payments** - View contribution history
- 📄 **Documents** - Upload and manage personal documents
- 📈 **Reports** - Personal financial reports and statements
- 👤 **Profile** - Update personal information and profile picture
- 🔔 **Notifications** - Transaction alerts and system notifications
- ⚙️ **Settings** - Personal preferences and password management

**Main Files**:
- `dashboard.php` / `dashboard-new.php` - Member dashboard
- `my-payments.php` - Payment history
- `documents.php` - Document management
- `reports.php` - Personal reports
- `profile.php` - Profile management
- `notifications.php` - Notification center
- `settings.php` - User preferences

---

### 3. **Admin Portal** (`admin/`)
**Access**: Colony administrators  
**Purpose**: Manage members, transactions, and generate reports

**Key Features**:
- 📊 **Dashboard** - System overview and key metrics
- 👥 **Member Management** - Add, edit, delete, view members
- 💳 **Transaction Management** - Record contributions and withdrawals
- 📈 **Reports** - Comprehensive financial and transaction reports
- 📅 **Year Management** - Create and manage chitti cycles
- 🔍 **Verification** - Verify transactions and payments
- 📄 **Export** - Export reports to PDF and Excel
- 🔔 **Notifications** - System alerts and member notifications
- 👤 **Profile** - Admin profile and password management
- ⚙️ **Settings** - System and display preferences

**Main Files**:
- `dashboard.php` / `dashboard-new.php` - Admin dashboard
- `manage-members.php` - Member administration
- `add-member.php` / `edit-member.php` - Member operations
- `manage-transactions.php` - Transaction management
- `add-transaction.php` / `edit-transaction.php` - Transaction operations
- `verify-transaction.php` - Transaction verification
- `manage-years.php` - Chitti year management
- `reports.php` - Report generation
- `export-report-pdf.php` - PDF export functionality

---

### 4. **Super Admin Portal** (`superadmin/`)
**Access**: System administrators only  
**Password Protected**: Additional security layer

**Purpose**: System-wide administration, settings, and monitoring

**Key Features**:
- 🎛️ **Dashboard** - System overview with all key metrics
- 👥 **Admin Management** - Manage admin user accounts
- 🗄️ **Data Management** - Import/export all data
- 📋 **Audit Logs** - Complete audit trail of all activities
- 🔐 **Permissions** - Role-based permission management
- 🎨 **Theme Settings** - Configure application themes
- 🏢 **Logo Settings** - Customize branding and site name
- 🔗 **Link Behavior** - Configure external link behavior
- 📚 **Template Management** - Create and manage system templates
- 🔄 **Database Tools** - Backup, restore, and database utilities
- 📊 **Analytics** - System-wide analytics and statistics
- ⚙️ **System Settings** - Global configuration management

**Main Files**:
- `dashboard.php` - Super Admin dashboard
- `manage-admins.php` - Admin user management
- `manage-members.php` - Member management (system-wide)
- `manage-transactions.php` - Transaction management (system-wide)
- `permissions-management.php` - Role and permission setup
- `settings.php` - Global system settings
- `theme-manager.js` - Theme configuration
- `audit-logs.php` - Audit log viewer
- `data-import-export.php` - Data backup/restore
- `database-backup.php` - Database backup utilities
- `link-behavior-settings.php` - Link behavior configuration

---

## 🚀 Installation

### Prerequisites

- **PHP**: 7.4 or higher
- **MySQL/MariaDB**: 5.7 or higher
- **Web Server**: Apache or Nginx
- **Composer**: For dependency management
- **Modern Browser**: Chrome, Firefox, Safari, or Edge

### Step 1: Clone Repository

```bash
git clone <repository-url> SRCOLONY-VIEWS
cd SRCOLONY-VIEWS
```

### Step 2: Install Dependencies

```bash
composer install
```

This installs:
- PHPOffice/PHPSpreadsheet - Excel file handling
- Other required libraries

### Step 3: Database Setup

#### Local Development:
```bash
# Create database
mysql -u root -e "CREATE DATABASE srcolony;"

# Import schema
mysql -u root srcolony < database/schema.sql
```

#### Using Sync Task:
```bash
# Pull database from remote (if configured)
php sync-database.php pull
```

Or use VS Code task: `Ctrl+Shift+P` → "Sync Database - Pull from Remote"

### Step 4: Configure Permissions

```bash
# Linux/Mac
chmod 755 assets/uploads/
chmod 755 assets/uploads/documents/
chmod 755 assets/uploads/profiles/
chmod 755 backups/

# Windows - Set folder permissions via Properties
```

### Step 5: Verify Installation

Visit these URLs:
- **Public Portal**: `http://localhost/SRCOLONY-VIEWS/`
- **Member Portal**: `http://localhost/SRCOLONY-VIEWS/member/`
- **Admin Portal**: `http://localhost/SRCOLONY-VIEWS/admin/`
- **Super Admin Portal**: `http://localhost/SRCOLONY-VIEWS/superadmin/`

---

## ⚙️ Configuration

### Main Configuration File: `config/config.php`

```php
// Database Configuration (Auto-detects local/remote)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'srcolony');

// Base paths and URLs (Auto-configured)
define('BASE_URL', 'http://localhost/SRCOLONY-VIEWS');
define('ASSETS_URL', BASE_URL . '/assets');
```

### Configuration Features:
- ✅ **Automatic Environment Detection** - Detects local vs. remote server
- ✅ **Dynamic Base URL** - Works with any server path structure
- ✅ **Session Management** - Per-portal session configuration
- ✅ **Error Handling** - PDO exception management

### Environment-Specific Settings:

**Local Development** (Localhost):
```php
DB_HOST: localhost
DB_USER: root
DB_PASS: (empty)
DB_NAME: srcolony
```

**Remote Server** (EzyRo):
```php
DB_HOST: sql100.ezyro.com
DB_USER: ezyro_40509380
DB_PASS: (configured)
DB_NAME: ezyro_40509380_srcolonymanage2_db
```

---

## 📖 Usage Guide

### For Members

1. **Login** - Go to `index.php` and click "Member Login"
2. **View Dashboard** - See your financial overview
3. **Check Payments** - Visit "My Payments" to view history
4. **Upload Documents** - Use "Documents" section
5. **Generate Report** - Access "Reports" for personal statements
6. **Update Profile** - Keep your information current

### For Admins

1. **Login** - Navigate to `admin/` login page
2. **Manage Members** - Add/edit/delete colony members
3. **Record Transactions** - Add contributions and withdrawals
4. **Generate Reports** - Create financial and transaction reports
5. **Verify Payments** - Check and verify transactions
6. **Manage Years** - Create and configure chitti cycles
7. **Export Data** - Generate PDF and Excel reports

### For Super Admins

1. **System Access** - Login to super admin portal with credentials
2. **Manage Admins** - Create and manage admin accounts
3. **Configure Settings** - Set themes, logos, and system options
4. **Review Audit Logs** - Monitor all system activities
5. **Backup Data** - Create and restore database backups
6. **Manage Permissions** - Configure role-based access control

---

## 🗄️ Database Setup

### Database Structure

The system uses a relational database with the following main tables:

**Core Tables**:
- `members` - Member information and profiles
- `transactions` - Financial transactions (deposits/withdrawals)
- `admins` - Admin user accounts
- `chitti_years` - Chitti cycle configurations
- `payments` - Payment records and history
- `notifications` - System notifications

**System Tables**:
- `system_settings` - Global configuration
- `audit_logs` - Activity audit trail
- `templates` - Custom template definitions
- `permissions` - Role-based permissions
- `documents` - Member document records

### Database Schema Files

Located in `database/`:
- `schema.sql` - Main database schema
- `ADD_*` files - Feature-specific schema updates
- Migration files for incremental updates

### Database Backup/Restore

**Create Backup**:
```bash
php superadmin/database-backup.php
```

**Restore from Backup**:
```bash
php import-backup-non-destructive.php
```

---

## 🔌 API Documentation

### REST API Endpoints

Located in `api/`:

**Notification APIs**:
- `GET /api/get-notification-count.php` - Get unread notification count
- `POST /api/mark-notification-read.php` - Mark notification as read
- `POST /api/mark-all-notifications-read.php` - Mark all as read

**Profile APIs**:
- `POST /api/update-profile-api.php` - Update user profile
- `POST /api/upload-logo.php` - Upload site logo

### API Usage Examples

**Get Notification Count**:
```javascript
fetch('/api/get-notification-count.php')
  .then(res => res.json())
  .then(data => console.log(data.count));
```

**Mark Notification Read**:
```javascript
fetch('/api/mark-notification-read.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ notification_id: 123 })
})
```

---

## 🐛 Troubleshooting

### Common Issues

**1. Database Connection Error**
```
Error: Connection failed: SQLSTATE[HY000]
```
**Solution**:
- Verify MySQL is running
- Check `config/config.php` credentials
- Ensure database exists: `mysql -u root -e "SHOW DATABASES;"`

**2. Permission Denied on Uploads**
```
Warning: move_uploaded_file(): failed to open stream
```
**Solution**:
```bash
chmod 755 assets/uploads/
chmod 755 assets/uploads/documents/
chmod 755 assets/uploads/profiles/
```

**3. Session Not Working**
```
Warning: session_start(): Cannot send session cookie
```
**Solution**:
- Check for output before session start
- Verify PHP headers not already sent
- Clear any BOM characters in files

**4. PDF Export Not Working**
**Solution**:
- Verify FPDF library is present: `admin/fpdf.php`
- Check write permissions on temp directory
- Ensure PHP fonts directory is configured

**5. Excel Import Error**
**Solution**:
- Verify PHPSpreadsheet is installed: `composer install`
- Check Excel file format (XLSX preferred)
- Ensure file permissions are correct

### Debug Mode

Enable debugging in `config/config.php`:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

Check logs at: `debug-session.php`, `check-php.php`

---

## 📚 Support & Documentation

### Documentation Files

- **[QUICK_START_GUIDE.md](QUICK_START_GUIDE.md)** - Get started in 5 minutes
- **[DATABASE_SETUP.md](DATABASE_SETUP.md)** - Database configuration guide
- **[PERMISSION_SYSTEM_IMPLEMENTATION.md](PERMISSION_SYSTEM_IMPLEMENTATION.md)** - Permissions setup
- **[AUDIT_LOGGING_COMPLETE.md](AUDIT_LOGGING_COMPLETE.md)** - Audit system documentation
- **[COMPREHENSIVE_SYSTEM_UPDATE_GUIDE.md](COMPREHENSIVE_SYSTEM_UPDATE_GUIDE.md)** - System updates
- **[IMPLEMENTATION_SUMMARY.md](IMPLEMENTATION_SUMMARY.md)** - Feature implementation details
- **[NAVIGATION_MAP.md](NAVIGATION_MAP.md)** - System navigation guide

### Helper Files

- `check-site-status.php` - Verify system health
- `check-members-table.php` - Validate member data
- `test-db-status.php` - Test database connectivity
- `debug-test.php` - Run debug tests

### Additional Resources

- `/superadmin/FAQ.htm` - Frequently asked questions
- `/superadmin/changelog.htm` - Change history
- `/superadmin/tutorial/` - Video/image tutorials
- `/documentation/` - Detailed feature documentation

---

## 🔐 Security Features

- **Role-Based Access Control (RBAC)** - Different permissions per role
- **Audit Logging** - Complete activity trail
- **Session Management** - Secure session handling per portal
- **Password Hashing** - Secure password storage
- **Input Validation** - SQL injection protection
- **CSRF Protection** - Cross-site request forgery prevention
- **Prepared Statements** - SQL prepared statements

---

## 📞 Support

For issues and questions:

1. Check the troubleshooting section above
2. Review relevant documentation files
3. Check system logs: `check-site-status.php`
4. Review database status: `test-db-status.php`
5. Contact system administrator

---

## 📄 License

This project is proprietary software. Unauthorized copying or distribution is prohibited.

---

## 🎯 Roadmap

- [ ] Mobile app integration
- [ ] Advanced analytics dashboard
- [ ] SMS notifications
- [ ] Email integration
- [ ] Automated backup scheduling
- [ ] Multi-language support
- [ ] API token authentication

---

## 👥 Portal Summary Table

| Feature | Member | Admin | Super Admin |
|---------|--------|-------|-------------|
| Dashboard | ✅ | ✅ | ✅ |
| Profile Management | ✅ | ✅ | ✅ |
| View Members | ✅ | ✅ | ✅ |
| Manage Members | ❌ | ✅ | ✅ |
| View Transactions | ✅ | ✅ | ✅ |
| Create Transactions | ❌ | ✅ | ✅ |
| Reports | ✅ | ✅ | ✅ |
| Export PDF | ✅ | ✅ | ✅ |
| Audit Logs | ❌ | ❌ | ✅ |
| System Settings | ❌ | ❌ | ✅ |
| Manage Admins | ❌ | ❌ | ✅ |
| Permissions | ❌ | ❌ | ✅ |

---

**Last Updated**: January 2026  
**Version**: 2.0+  
**Status**: Active Development
