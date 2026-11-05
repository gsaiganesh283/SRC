# VS Code MySQL Database Setup Guide

## Extensions Installed ✅

### 1. **MySQL Connector** (`gautamsagarmallela.mysqlconnector`)
   - Connect to MySQL databases directly from VS Code
   - View database structure
   - Run SQL queries

### 2. **Database Client** (`cweijan.dbclient-jdbc`)
   - Advanced database management
   - Visual schema explorer
   - Query builder

## Quick Start

### Step 1: Configure Local MySQL
```bash
# Start your local MySQL server (XAMPP, WAMP, or MAMP)
# Create database:
mysql -u root -e "CREATE DATABASE srcolony;"
```

### Step 2: Sync Database from Remote

**Option A: Using VS Code Tasks**
1. Press `Ctrl+Shift+P` → Search "Run Task"
2. Select "Sync Database - Pull from Remote"
3. Wait for completion

**Option B: Using Terminal**
```powershell
cd c:\Users\RDP\Documents\GitHub\SRCOLONY-VIEWS
php sync-database.php pull
```

### Step 3: Connect in VS Code

#### Using MySQL Connector:
1. Open Command Palette (`Ctrl+Shift+P`)
2. Search "MySQL: New Connection"
3. Enter details:
   - **Host**: localhost
   - **User**: root
   - **Password**: (leave empty for XAMPP default)
   - **Database**: srcolony
   - **Port**: 3306

#### Using Database Client:
1. Click the Database icon in sidebar
2. Click "New Connection"
3. Select MySQL
4. Fill in connection details
5. Connect and explore tables

### Step 4: Automatic Updates

**Manual Sync:**
```powershell
# Pull latest from remote
php sync-database.php pull

# Push to remote (use carefully!)
php sync-database.php push
```

**Scheduled Sync (Windows Task Scheduler):**
1. Create a batch file `sync.bat`:
```batch
@echo off
cd C:\Users\RDP\Documents\GitHub\SRCOLONY-VIEWS
php sync-database.php pull
```

2. Open Task Scheduler
3. Create Basic Task → Set trigger (daily/hourly)
4. Action: Run `sync.bat`

## Features

✅ View all tables and schemas in VS Code
✅ Run SQL queries directly
✅ One-click database backup
✅ Manual or scheduled sync from remote
✅ Full database management interface

## Troubleshooting

**Connection failed?**
- Ensure MySQL is running
- Check host/user/password in config.php
- Verify firewall settings

**Tables not showing?**
- Run sync again with `php sync-database.php pull`
- Check database name spelling

**Remote connection issues?**
- Verify credentials in sync-database.php
- Check internet connection
- Verify firewall allows remote MySQL

## Security Notes

⚠️ Store credentials securely
⚠️ Don't commit credentials to Git
⚠️ Use .gitignore to exclude sensitive files
⚠️ Test push operations carefully before running

---
**Created**: 2025-12-09
**For**: SRCOLONY-VIEWS Project
