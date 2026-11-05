# ✅ CORRECT URLs FOR YOUR APPLICATION

## 📍 Your Setup:
- **Folder Name:** `SRCOLONY-VIEWS`
- **Location:** `C:\xampp\htdocs\SRCOLONY-VIEWS\` (or similar)
- **Web Server:** Apache (via XAMPP/WAMP)

---

## 🌐 Correct URLs to Use:

### Admin Portal:
```
http://localhost/SRCOLONY-VIEWS/auth/admin-login.php
```

**Login Credentials:**
- Username: `admin`
- Password: `admin123`

---

### Member Portal:
```
http://localhost/SRCOLONY-VIEWS/auth/member-login.php
```

---

### Homepage (if exists):
```
http://localhost/SRCOLONY-VIEWS/
```
or
```
http://localhost/SRCOLONY-VIEWS/index.html
```

---

### Admin Dashboard (after login):
```
http://localhost/SRCOLONY-VIEWS/admin/dashboard.php
```

---

### Member Dashboard (after login):
```
http://localhost/SRCOLONY-VIEWS/member/dashboard.php
```

---

## 🔧 Test URLs (for debugging):

### Test Password Hash:
Create file: `test-password.php` in root folder, then access:
```
http://localhost/SRCOLONY-VIEWS/test-password.php
```

### Test Database Connection:
Create file: `test-db.php` in root folder, then access:
```
http://localhost/SRCOLONY-VIEWS/test-db.php
```

---

## ⚙️ Configuration Check:

Your `config/config.php` should have:

```php
<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');           // Default XAMPP username
define('DB_PASS', '');               // Default XAMPP password (empty)
define('DB_NAME', 'srcolony');       // Your database name
define('DB_CHARSET', 'utf8mb4');

// Base URL Configuration
define('BASE_URL', 'http://localhost/SRCOLONY-VIEWS/');

// PDO Connection
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
```

---

## 📂 File Structure:

```
C:\xampp\htdocs\SRCOLONY-VIEWS\
├── index.html
├── config/
│   └── config.php
├── auth/
│   ├── admin-login.php      ← Admin login page
│   ├── member-login.php     ← Member login page
│   └── logout.php
├── admin/
│   ├── dashboard.php        ← Admin dashboard
│   ├── manage-members.php
│   └── ...
├── member/
│   ├── dashboard.php        ← Member dashboard
│   └── ...
└── database/
    ├── MASTER_DATABASE_SETUP.sql
    ├── TEST_DATABASE_CONNECTION.sql
    └── FIX_ADMIN_LOGIN.sql
```

---

## 🚀 Quick Start Steps:

### 1. Start XAMPP:
- Open XAMPP Control Panel
- Start **Apache** (should show green)
- Start **MySQL** (should show green)

### 2. Setup Database:
1. Open: `http://localhost/phpmyadmin`
2. Click **New** to create database
3. Database name: `srcolony`
4. Collation: `utf8mb4_general_ci`
5. Click **Create**
6. Select `srcolony` database
7. Click **Import** tab
8. Choose file: `database/MASTER_DATABASE_SETUP.sql`
9. Click **Go**
10. Wait for success message

### 3. Fix Admin Login (if needed):
1. In phpMyAdmin, select `srcolony` database
2. Click **SQL** tab
3. Run file: `database/FIX_ADMIN_LOGIN.sql`
4. Check for success message

### 4. Access Application:
Open browser and go to:
```
http://localhost/SRCOLONY-VIEWS/auth/admin-login.php
```

Login with:
- Username: `admin`
- Password: `admin123`

---

## ❌ Common Mistakes:

### Wrong URL (will give 404 error):
```
❌ http://localhost/srcolony/auth/admin-login.php
❌ http://localhost/auth/admin-login.php
❌ http://localhost/admin-login.php
```

### Correct URL:
```
✅ http://localhost/SRCOLONY-VIEWS/auth/admin-login.php
```

---

## 🔍 Troubleshooting:

### If you get "Page Not Found" (404):
1. Check folder name is exactly `SRCOLONY-VIEWS` in htdocs
2. Check Apache is running in XAMPP
3. Try: `http://localhost/` (should show XAMPP dashboard)
4. Check file exists: `C:\xampp\htdocs\SRCOLONY-VIEWS\auth\admin-login.php`

### If you get "Can't connect to database":
1. Check MySQL is running in XAMPP
2. Open phpMyAdmin: `http://localhost/phpmyadmin`
3. Check database `srcolony` exists
4. Check `config/config.php` has correct database name

### If you get "Invalid username or password":
1. Run `TEST_DATABASE_CONNECTION.sql` in phpMyAdmin
2. Run `FIX_ADMIN_LOGIN.sql` in phpMyAdmin
3. Try again with: admin / admin123

---

## 📞 Quick Reference:

| Service | URL |
|---------|-----|
| Admin Login | http://localhost/SRCOLONY-VIEWS/auth/admin-login.php |
| Member Login | http://localhost/SRCOLONY-VIEWS/auth/member-login.php |
| phpMyAdmin | http://localhost/phpmyadmin |
| XAMPP Dashboard | http://localhost/ |

---

**Remember:** The folder name in the URL must match the folder name in htdocs!

Folder: `SRCOLONY-VIEWS` → URL: `http://localhost/SRCOLONY-VIEWS/`
