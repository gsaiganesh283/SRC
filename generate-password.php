<?php
/**
 * GENERATE FRESH PASSWORD HASH
 * This will create a NEW bcrypt hash for 'admin123'
 * Access: http://localhost:3000/generate-password.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Generate Fresh Password</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); min-height: 100vh; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        h1 { color: #333; text-align: center; }
        .success { background: #d4edda; color: #155724; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 5px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 5px solid #dc3545; }
        .info { background: #d1ecf1; color: #0c5460; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 5px solid #17a2b8; }
        .code { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 5px; font-family: 'Courier New', monospace; margin: 10px 0; overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
        .btn { display: inline-block; padding: 15px 30px; background: #007bff; color: white; text-decoration: none; border-radius: 8px; font-weight: bold; border: none; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #007bff; color: white; }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>🔐 Fresh Password Hash Generator</h1>";

// Generate a NEW hash
$password = 'admin123';
$newHash = password_hash($password, PASSWORD_BCRYPT);

echo "<div class='info'>";
echo "<h3>✅ Fresh Password Hash Generated!</h3>";
echo "<p>Password: <strong>admin123</strong></p>";
echo "<p>New Hash:</p>";
echo "<div class='code'>" . htmlspecialchars($newHash) . "</div>";
echo "</div>";

// Verify it works
if (password_verify($password, $newHash)) {
    echo "<div class='success'>✅ Verification TEST: PASSED! This hash works correctly.</div>";
} else {
    echo "<div class='error'>❌ Verification TEST: FAILED! Something is wrong with PHP.</div>";
}

// Now update database
if (isset($_POST['update_database'])) {
    
    if (file_exists('config/config.php')) {
        require_once 'config/config.php';
    } else {
        echo "<div class='error'>❌ Config file not found!</div>";
        echo "</div></body></html>";
        exit;
    }
    
    try {
        // Use the NEW hash
        $stmt = $pdo->prepare("UPDATE admins SET Password = ?, IsActive = 1 WHERE Username = 'admin'");
        $result = $stmt->execute([$newHash]);
        
        if ($result) {
            echo "<div class='success'>";
            echo "<h2>🎉 DATABASE UPDATED SUCCESSFULLY!</h2>";
            echo "<p>The password has been updated in the database.</p>";
            echo "</div>";
            
            // Verify from database
            $stmt = $pdo->prepare("SELECT Username, Password, IsActive FROM admins WHERE Username = 'admin'");
            $stmt->execute();
            $admin = $stmt->fetch();
            
            echo "<div class='info'>";
            echo "<h3>Database Verification:</h3>";
            echo "<table>";
            echo "<tr><th>Field</th><th>Value</th></tr>";
            echo "<tr><td>Username</td><td>" . htmlspecialchars($admin['Username']) . "</td></tr>";
            echo "<tr><td>Password Hash</td><td><code>" . substr($admin['Password'], 0, 40) . "...</code></td></tr>";
            echo "<tr><td>Active Status</td><td>" . ($admin['IsActive'] ? '✅ Active' : '❌ Inactive') . "</td></tr>";
            echo "</table>";
            echo "</div>";
            
            // Test the password from database
            if (password_verify('admin123', $admin['Password'])) {
                echo "<div class='success'>";
                echo "<h2>✅ PASSWORD VERIFICATION: SUCCESS!</h2>";
                echo "<p><strong>You can now login with:</strong></p>";
                echo "<div class='code'>";
                echo "Username: admin\n";
                echo "Password: admin123";
                echo "</div>";
                echo "<p style='text-align: center; margin-top: 20px;'>";
                echo "<a href='http://localhost:3000/auth/admin-login.php' class='btn btn-success'>🚀 GO TO LOGIN PAGE</a>";
                echo "</p>";
                echo "</div>";
            } else {
                echo "<div class='error'>";
                echo "<h2>❌ PASSWORD VERIFICATION: FAILED!</h2>";
                echo "<p>The hash was saved but doesn't verify correctly.</p>";
                echo "<p><strong>Debugging Information:</strong></p>";
                echo "<table>";
                echo "<tr><th>Test</th><th>Result</th></tr>";
                echo "<tr><td>Password to test</td><td>admin123</td></tr>";
                echo "<tr><td>Hash in database</td><td><code>" . htmlspecialchars($admin['Password']) . "</code></td></tr>";
                echo "<tr><td>Hash length</td><td>" . strlen($admin['Password']) . " characters</td></tr>";
                echo "<tr><td>Hash starts with</td><td>" . substr($admin['Password'], 0, 7) . "</td></tr>";
                echo "<tr><td>Expected start</td><td>\$2y\$10\$</td></tr>";
                echo "</table>";
                
                // Check for encoding issues
                echo "<p><strong>Possible Issues:</strong></p>";
                echo "<ul>";
                echo "<li>Database column might be too short (needs VARCHAR(255))</li>";
                echo "<li>Character encoding issue in database</li>";
                echo "<li>MySQL strict mode might be truncating data</li>";
                echo "</ul>";
                
                // Check column details
                try {
                    $stmt = $pdo->query("SHOW COLUMNS FROM admins WHERE Field = 'Password'");
                    $column = $stmt->fetch();
                    echo "<p><strong>Password Column Details:</strong></p>";
                    echo "<div class='code'>";
                    echo "Type: " . $column['Type'] . "\n";
                    echo "Null: " . $column['Null'] . "\n";
                    echo "Default: " . $column['Default'] . "\n";
                    echo "</div>";
                    
                    // Check if column is too short
                    if (strpos($column['Type'], 'varchar') !== false) {
                        preg_match('/varchar\((\d+)\)/', $column['Type'], $matches);
                        if (isset($matches[1]) && $matches[1] < 255) {
                            echo "<div class='error'>";
                            echo "⚠️ WARNING: Password column is only {$matches[1]} characters!<br>";
                            echo "It needs to be at least 255 characters for bcrypt hashes.<br><br>";
                            echo "<strong>Run this in phpMyAdmin:</strong>";
                            echo "<div class='code'>ALTER TABLE admins MODIFY Password VARCHAR(255) NOT NULL;</div>";
                            echo "</div>";
                        }
                    }
                } catch (Exception $e) {
                    echo "<div class='error'>Could not check column details: " . $e->getMessage() . "</div>";
                }
                
                echo "</div>";
            }
            
        } else {
            echo "<div class='error'>❌ Failed to update password. No rows affected.</div>";
        }
        
    } catch (PDOException $e) {
        echo "<div class='error'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
} else {
    // Show update button
    echo "<form method='POST'>";
    echo "<p style='text-align: center;'>";
    echo "<button type='submit' name='update_database' class='btn btn-success'>📝 UPDATE DATABASE WITH THIS HASH</button>";
    echo "</p>";
    echo "</form>";
    
    echo "<hr>";
    
    // Show database column info
    if (file_exists('config/config.php')) {
        require_once 'config/config.php';
        
        try {
            echo "<div class='info'>";
            echo "<h3>Current Database Status:</h3>";
            
            // Check column structure
            $stmt = $pdo->query("SHOW COLUMNS FROM admins WHERE Field = 'Password'");
            $column = $stmt->fetch();
            
            echo "<table>";
            echo "<tr><th>Property</th><th>Value</th></tr>";
            echo "<tr><td>Column Type</td><td><strong>{$column['Type']}</strong></td></tr>";
            echo "<tr><td>Nullable</td><td>{$column['Null']}</td></tr>";
            echo "<tr><td>Default</td><td>" . ($column['Default'] ?: 'None') . "</td></tr>";
            echo "</table>";
            
            // Check if column is large enough
            if (strpos($column['Type'], 'varchar') !== false) {
                preg_match('/varchar\((\d+)\)/', $column['Type'], $matches);
                if (isset($matches[1])) {
                    $columnSize = $matches[1];
                    $hashLength = strlen($newHash);
                    
                    if ($columnSize < 255) {
                        echo "<div class='error'>";
                        echo "<h3>⚠️ PROBLEM FOUND!</h3>";
                        echo "<p>Your Password column is only <strong>$columnSize characters</strong>.</p>";
                        echo "<p>Bcrypt hashes need <strong>60 characters</strong>, recommended <strong>255 characters</strong>.</p>";
                        echo "<p><strong>FIX THIS FIRST:</strong></p>";
                        echo "<ol>";
                        echo "<li>Open phpMyAdmin: <a href='http://localhost/phpmyadmin' target='_blank'>http://localhost/phpmyadmin</a></li>";
                        echo "<li>Select 'srcolony' database</li>";
                        echo "<li>Click SQL tab</li>";
                        echo "<li>Run this command:</li>";
                        echo "</ol>";
                        echo "<div class='code'>ALTER TABLE admins MODIFY Password VARCHAR(255) NOT NULL;</div>";
                        echo "<p>Then come back here and click 'UPDATE DATABASE'</p>";
                        echo "</div>";
                    } else {
                        echo "<div class='success'>";
                        echo "✅ Column size is adequate ($columnSize characters)";
                        echo "</div>";
                    }
                }
            }
            
            // Show current password
            $stmt = $pdo->query("SELECT Password FROM admins WHERE Username = 'admin'");
            $admin = $stmt->fetch();
            
            if ($admin) {
                echo "<p><strong>Current Password Hash in Database:</strong></p>";
                echo "<div class='code'>" . htmlspecialchars($admin['Password']) . "</div>";
                echo "<p>Length: " . strlen($admin['Password']) . " characters</p>";
            }
            
            echo "</div>";
            
        } catch (PDOException $e) {
            echo "<div class='error'>Cannot check database: " . $e->getMessage() . "</div>";
        }
    }
}

echo "</div></body></html>";
?>
