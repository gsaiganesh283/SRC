<?php
/**
 * Quick Database Status Check
 * Access: http://localhost:3000/test-db-status.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Database Status Check</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #007bff; padding-bottom: 10px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 5px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 5px solid #dc3545; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 5px solid #ffc107; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; border-left: 5px solid #17a2b8; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #007bff; color: white; }
        tr:hover { background-color: #f5f5f5; }
        .code { background: #f4f4f4; padding: 10px; border-radius: 5px; font-family: monospace; margin: 10px 0; }
        .step { background: #e7f3ff; padding: 10px; margin: 10px 0; border-radius: 5px; }
        .step-title { font-weight: bold; color: #0056b3; margin-bottom: 5px; }
    </style>
</head>
<body>
<div class='container'>
<h1>🔍 SR Colony Database Status</h1>
<p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
<hr>";

// Step 1: Check config file
echo "<div class='step'>";
echo "<div class='step-title'>STEP 1: Config File Check</div>";

if (file_exists('config/config.php')) {
    require_once 'config/config.php';
    echo "<div class='success'>✅ Config file found and loaded</div>";
    echo "<div class='info'>";
    echo "<strong>Database Settings:</strong><br>";
    echo "Host: " . DB_HOST . "<br>";
    echo "Database: " . DB_NAME . "<br>";
    echo "User: " . DB_USER . "<br>";
    echo "Password: " . (empty(DB_PASS) ? '(empty)' : '(set)') . "<br>";
    echo "</div>";
} else {
    echo "<div class='error'>❌ Config file NOT found!</div>";
    exit;
}
echo "</div>";

// Step 2: Test database connection
echo "<div class='step'>";
echo "<div class='step-title'>STEP 2: Database Connection</div>";

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "<div class='success'>✅ PDO connection successful!</div>";
    } else {
        throw new Exception("PDO not initialized");
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Database connection FAILED!<br>";
    echo "Error: " . $e->getMessage() . "</div>";
    echo "<div class='warning'><strong>Fix:</strong> Make sure MySQL is running in XAMPP</div>";
    exit;
}
echo "</div>";

// Step 3: Check if database exists
echo "<div class='step'>";
echo "<div class='step-title'>STEP 3: Database Existence</div>";

try {
    $stmt = $pdo->query("SELECT DATABASE() as current_db");
    $result = $stmt->fetch();
    echo "<div class='success'>✅ Connected to database: <strong>" . $result['current_db'] . "</strong></div>";
} catch (PDOException $e) {
    echo "<div class='error'>❌ Cannot access database<br>Error: " . $e->getMessage() . "</div>";
    exit;
}
echo "</div>";

// Step 4: List all tables
echo "<div class='step'>";
echo "<div class='step-title'>STEP 4: Tables in Database</div>";

try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) > 0) {
        echo "<div class='success'>✅ Found " . count($tables) . " table(s)</div>";
        echo "<table>";
        echo "<tr><th>#</th><th>Table Name</th><th>Row Count</th></tr>";
        
        foreach ($tables as $index => $table) {
            $countStmt = $pdo->query("SELECT COUNT(*) as cnt FROM `$table`");
            $count = $countStmt->fetch()['cnt'];
            echo "<tr><td>" . ($index + 1) . "</td><td><strong>$table</strong></td><td>$count rows</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='error'>❌ NO TABLES found in database!</div>";
        echo "<div class='warning'>";
        echo "<strong>ACTION REQUIRED:</strong><br>";
        echo "1. Open phpMyAdmin: <a href='http://localhost/phpmyadmin' target='_blank'>http://localhost/phpmyadmin</a><br>";
        echo "2. Select 'srcolony' database<br>";
        echo "3. Click 'Import' tab<br>";
        echo "4. Import file: <code>database/MASTER_DATABASE_SETUP.sql</code><br>";
        echo "</div>";
    }
} catch (PDOException $e) {
    echo "<div class='error'>❌ Cannot list tables<br>Error: " . $e->getMessage() . "</div>";
}
echo "</div>";

// Step 5: Check admins table specifically
echo "<div class='step'>";
echo "<div class='step-title'>STEP 5: Admins Table Check</div>";

try {
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM admins");
    $count = $stmt->fetch()['cnt'];
    
    if ($count > 0) {
        echo "<div class='success'>✅ Admins table exists with $count user(s)</div>";
        
        // Show admin users
        $stmt = $pdo->query("SELECT AdminID, Username, FullName, Email, IsActive, LEFT(Password, 30) as PassPreview FROM admins");
        $admins = $stmt->fetchAll();
        
        echo "<table>";
        echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Active</th><th>Password Hash</th></tr>";
        
        foreach ($admins as $admin) {
            $activeClass = $admin['IsActive'] ? 'success' : 'error';
            $activeText = $admin['IsActive'] ? '✅ Yes' : '❌ No';
            echo "<tr>";
            echo "<td>{$admin['AdminID']}</td>";
            echo "<td><strong>{$admin['Username']}</strong></td>";
            echo "<td>{$admin['FullName']}</td>";
            echo "<td>{$admin['Email']}</td>";
            echo "<td>$activeText</td>";
            echo "<td><code>{$admin['PassPreview']}...</code></td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Test password verification
        $stmt = $pdo->query("SELECT Username, Password FROM admins WHERE Username = 'admin'");
        $adminUser = $stmt->fetch();
        
        if ($adminUser) {
            echo "<div class='info'><strong>Password Test for 'admin':</strong><br>";
            $testPassword = 'admin123';
            if (password_verify($testPassword, $adminUser['Password'])) {
                echo "✅ Password 'admin123' verification: <strong style='color: green;'>SUCCESS</strong><br>";
                echo "You should be able to login with: admin / admin123";
            } else {
                echo "❌ Password 'admin123' verification: <strong style='color: red;'>FAILED</strong><br>";
                echo "The password hash in database doesn't match 'admin123'<br>";
                echo "<strong>FIX:</strong> Run <code>database/FIX_ADMIN_LOGIN.sql</code> in phpMyAdmin";
            }
            echo "</div>";
        }
        
    } else {
        echo "<div class='error'>❌ Admins table is EMPTY!</div>";
        echo "<div class='warning'>";
        echo "<strong>ACTION REQUIRED:</strong><br>";
        echo "Run this in phpMyAdmin SQL tab:<br>";
        echo "<div class='code'>database/FIX_ADMIN_LOGIN.sql</div>";
        echo "</div>";
    }
    
} catch (PDOException $e) {
    echo "<div class='error'>❌ Admins table does NOT exist!<br>";
    echo "Error: " . $e->getMessage() . "</div>";
    echo "<div class='warning'>";
    echo "<strong>ACTION REQUIRED:</strong><br>";
    echo "1. Open phpMyAdmin: <a href='http://localhost/phpmyadmin' target='_blank'>http://localhost/phpmyadmin</a><br>";
    echo "2. Select 'srcolony' database<br>";
    echo "3. Click 'Import' tab<br>";
    echo "4. Import file: <code>database/MASTER_DATABASE_SETUP.sql</code><br>";
    echo "</div>";
}
echo "</div>";

// Step 6: Final diagnosis
echo "<div class='step'>";
echo "<div class='step-title'>FINAL DIAGNOSIS</div>";

try {
    // Check everything
    $hasDatabase = true;
    
    $stmt = $pdo->query("SHOW TABLES LIKE 'admins'");
    $hasAdminsTable = $stmt->rowCount() > 0;
    
    $hasAdminUser = false;
    $passwordCorrect = false;
    
    if ($hasAdminsTable) {
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM admins WHERE Username = 'admin'");
        $hasAdminUser = $stmt->fetch()['cnt'] > 0;
        
        if ($hasAdminUser) {
            $stmt = $pdo->query("SELECT Password FROM admins WHERE Username = 'admin'");
            $admin = $stmt->fetch();
            $passwordCorrect = password_verify('admin123', $admin['Password']);
        }
    }
    
    if ($hasDatabase && $hasAdminsTable && $hasAdminUser && $passwordCorrect) {
        echo "<div class='success'>";
        echo "<h2>🎉 ALL CHECKS PASSED!</h2>";
        echo "<p>Your database is configured correctly.</p>";
        echo "<p><strong>You should be able to login with:</strong></p>";
        echo "<div class='code'>";
        echo "URL: <a href='http://localhost:3000/auth/admin-login.php' target='_blank'>http://localhost:3000/auth/admin-login.php</a><br>";
        echo "Username: admin<br>";
        echo "Password: admin123";
        echo "</div>";
        echo "</div>";
    } else {
        echo "<div class='error'>";
        echo "<h2>⚠️ ISSUES FOUND</h2>";
        echo "<ul>";
        if (!$hasAdminsTable) {
            echo "<li>❌ Admins table doesn't exist - <strong>Import MASTER_DATABASE_SETUP.sql</strong></li>";
        }
        if (!$hasAdminUser) {
            echo "<li>❌ Admin user doesn't exist - <strong>Run FIX_ADMIN_LOGIN.sql</strong></li>";
        }
        if ($hasAdminUser && !$passwordCorrect) {
            echo "<li>❌ Admin password is wrong - <strong>Run FIX_ADMIN_LOGIN.sql</strong></li>";
        }
        echo "</ul>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>Error during diagnosis: " . $e->getMessage() . "</div>";
}

echo "</div>";

echo "<hr>";
echo "<p style='text-align: center; color: #666;'>Database diagnostic complete. Close this window when done.</p>";
echo "</div></body></html>";
?>
