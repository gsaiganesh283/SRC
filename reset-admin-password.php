<?php
/**
 * ONE-CLICK PASSWORD RESET
 * Access: http://localhost:3000/reset-admin-password.php
 * 
 * This will directly reset the admin password to admin123
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html>
<html>
<head>
    <title>Reset Admin Password</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        h1 { color: #333; text-align: center; margin-bottom: 30px; }
        .btn { display: block; width: 100%; padding: 15px; background: #4CAF50; color: white; border: none; border-radius: 8px; font-size: 18px; cursor: pointer; margin: 20px 0; transition: all 0.3s; }
        .btn:hover { background: #45a049; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3); }
        .success { background: #d4edda; color: #155724; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 5px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 5px solid #dc3545; }
        .info { background: #d1ecf1; color: #0c5460; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 5px solid #17a2b8; }
        .code { background: #f4f4f4; padding: 15px; border-radius: 5px; font-family: monospace; margin: 10px 0; }
        .big-icon { font-size: 60px; text-align: center; margin: 20px 0; }
    </style>
</head>
<body>
<div class='container'>";

// Check if reset button was clicked
if (isset($_POST['reset_password'])) {
    
    // Load config
    if (file_exists('config/config.php')) {
        require_once 'config/config.php';
    } else {
        echo "<div class='error'>❌ Config file not found!</div>";
        echo "</div></body></html>";
        exit;
    }
    
    try {
        // The CORRECT bcrypt hash for 'admin123'
        $correctHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        
        // Update password
        $stmt = $pdo->prepare("UPDATE admins SET Password = ?, IsActive = 1 WHERE Username = 'admin'");
        $result = $stmt->execute([$correctHash]);
        
        if ($result) {
            // Verify the update
            $stmt = $pdo->prepare("SELECT Username, Password, IsActive FROM admins WHERE Username = 'admin'");
            $stmt->execute();
            $admin = $stmt->fetch();
            
            if ($admin && password_verify('admin123', $admin['Password'])) {
                echo "<div class='big-icon'>🎉</div>";
                echo "<div class='success'>";
                echo "<h2 style='text-align: center; margin-top: 0;'>✅ PASSWORD RESET SUCCESSFUL!</h2>";
                echo "<p style='text-align: center;'><strong>You can now login with:</strong></p>";
                echo "<div class='code'>";
                echo "Username: <strong>admin</strong><br>";
                echo "Password: <strong>admin123</strong>";
                echo "</div>";
                echo "<p style='text-align: center; margin-top: 20px;'>";
                echo "<a href='http://localhost:3000/auth/admin-login.php' style='display: inline-block; padding: 15px 40px; background: #007bff; color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>🚀 Go to Login Page</a>";
                echo "</p>";
                echo "</div>";
                
                echo "<div class='info'>";
                echo "<strong>Verification Details:</strong><br>";
                echo "• Username: " . htmlspecialchars($admin['Username']) . "<br>";
                echo "• Password Hash: " . substr($admin['Password'], 0, 30) . "...<br>";
                echo "• Password Test: ✅ PASSED<br>";
                echo "• Account Status: " . ($admin['IsActive'] ? '✅ Active' : '❌ Inactive') . "<br>";
                echo "</div>";
                
            } else {
                echo "<div class='error'>";
                echo "❌ Password was updated but verification FAILED!<br>";
                echo "Something is wrong with the password hash.<br><br>";
                echo "Current hash: " . substr($admin['Password'], 0, 40) . "...<br>";
                echo "</div>";
            }
        } else {
            echo "<div class='error'>❌ Failed to update password. No rows affected.</div>";
        }
        
    } catch (PDOException $e) {
        echo "<div class='error'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    
} else {
    // Show reset form
    echo "<h1>🔐 Reset Admin Password</h1>";
    
    echo "<div class='info'>";
    echo "<p><strong>This tool will:</strong></p>";
    echo "<ul>";
    echo "<li>✅ Reset admin password to: <strong>admin123</strong></li>";
    echo "<li>✅ Activate the admin account</li>";
    echo "<li>✅ Verify the password works correctly</li>";
    echo "</ul>";
    echo "</div>";
    
    // Check if database is accessible
    if (file_exists('config/config.php')) {
        require_once 'config/config.php';
        
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM admins WHERE Username = 'admin'");
            $result = $stmt->fetch();
            
            if ($result['cnt'] > 0) {
                echo "<div class='success'>✅ Admin user found in database</div>";
                
                // Show current password status
                $stmt = $pdo->query("SELECT Password FROM admins WHERE Username = 'admin'");
                $admin = $stmt->fetch();
                
                echo "<div class='info'>";
                echo "<strong>Current Status:</strong><br>";
                echo "Current Hash: <code>" . substr($admin['Password'], 0, 30) . "...</code><br>";
                
                if (password_verify('admin123', $admin['Password'])) {
                    echo "<span style='color: green;'>✅ Password 'admin123' already works!</span><br>";
                    echo "<br><strong>You can login now:</strong><br>";
                    echo "<a href='http://localhost:3000/auth/admin-login.php' style='color: #007bff; font-weight: bold;'>Go to Login Page →</a>";
                } else {
                    echo "<span style='color: red;'>❌ Password 'admin123' does NOT work</span><br>";
                    echo "<br><strong>Click the button below to fix it:</strong>";
                }
                echo "</div>";
                
            } else {
                echo "<div class='error'>❌ Admin user NOT found in database!<br>";
                echo "Please import MASTER_DATABASE_SETUP.sql first.</div>";
            }
            
        } catch (PDOException $e) {
            echo "<div class='error'>❌ Cannot access database: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        
    } else {
        echo "<div class='error'>❌ Config file not found!</div>";
    }
    
    echo "<form method='POST'>";
    echo "<button type='submit' name='reset_password' class='btn'>🔧 RESET PASSWORD NOW</button>";
    echo "</form>";
    
    echo "<p style='text-align: center; color: #666; font-size: 14px;'>";
    echo "This will update the database directly and activate the admin account.";
    echo "</p>";
}

echo "</div></body></html>";
?>
