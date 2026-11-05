<?php
/**
 * Unlock Super Admin Account
 * This script clears the login lockout
 * DELETE THIS FILE AFTER USE
 */

session_start();

echo "<!DOCTYPE html>
<html>
<head>
    <title>Unlock Super Admin</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        h1 {
            color: #7e22ce;
            margin-bottom: 20px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #28a745;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #17a2b8;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 4px solid #ffc107;
        }
        a {
            display: inline-block;
            background: linear-gradient(135deg, #7e22ce 0%, #9333ea 100%);
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 20px;
            font-weight: 600;
        }
        a:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(126, 34, 206, 0.4);
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class='container'>";

echo "<h1>🔓 Unlock Super Admin Account</h1>";

// Clear session variables
unset($_SESSION['super_admin_login_attempts']);
unset($_SESSION['super_admin_lockout_time']);

echo "<div class='success'>";
echo "<strong>✓ Account Unlocked!</strong><br>";
echo "Login lockout has been cleared. You can now try logging in again.";
echo "</div>";

echo "<div class='info'>";
echo "<strong>📝 Correct Login Credentials:</strong><br><br>";
echo "Username: <code>superadmin</code> (not 'admin')<br>";
echo "Password: <code>SuperAdmin@2025</code><br>";
echo "</div>";

echo "<div class='warning'>";
echo "<strong>⚠️ Important Notes:</strong><br>";
echo "• Make sure to use username <strong>superadmin</strong> (not admin)<br>";
echo "• Password is case-sensitive<br>";
echo "• After successful login, change the default password<br>";
echo "• Delete this unlock file after use for security<br>";
echo "</div>";

echo "<a href='auth/super-admin-login.php'>→ Go to Login Page</a>";

echo "<div style='margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 8px;'>";
echo "<strong>Troubleshooting:</strong><br>";
echo "If you still can't login after unlocking:<br>";
echo "1. Check if super_admins table exists in database<br>";
echo "2. Verify the super admin account was created<br>";
echo "3. Run the setup script: <code>setup-super-admin.php</code><br>";
echo "</div>";

echo "</div></body></html>";
?>
