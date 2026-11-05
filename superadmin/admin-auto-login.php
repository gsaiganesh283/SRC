<?php
// Prevent config.php from auto-starting session
define('SKIP_AUTO_SESSION', true);

// Don't start session yet - we need to validate token first and get admin ID
require_once '../config/config.php';

$token = $_GET['token'] ?? null;

if (!$token) {
    echo '<h3>Invalid Access</h3>';
    echo '<p>No token provided. Please try again from the superadmin portal.</p>';
    echo '<p><a href="manage-admins.php">Return to Manage Admins</a></p>';
    exit();
}

try {
    // Check token validity
    $stmt = $pdo->prepare("
        SELECT alt.*, a.* 
        FROM admin_login_tokens alt
        JOIN admins a ON alt.AdminID = a.AdminID
        WHERE alt.Token = ?
    ");
    $stmt->execute([$token]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        echo '<h3>Invalid or Expired Token</h3>';
        echo '<p>The login token is invalid or has expired. Please try again from the superadmin portal.</p>';
        echo '<p><a href="manage-admins.php">Return to Manage Admins</a></p>';
        exit();
    }
    
    // Check if token has expired
    $expiresAt = strtotime($result['ExpiresAt']);
    $now = time();
    
    if ($expiresAt < $now) {
        echo '<h3>Token Expired</h3>';
        echo '<p>The login token has expired. Please try again from the superadmin portal.</p>';
        echo '<p>Token expired at: ' . date('Y-m-d H:i:s', $expiresAt) . '</p>';
        echo '<p>Current time: ' . date('Y-m-d H:i:s', $now) . '</p>';
        echo '<p><a href="manage-admins.php">Return to Manage Admins</a></p>';
        exit();
    }
    
    // Delete used token
    $stmt = $pdo->prepare("DELETE FROM admin_login_tokens WHERE Token = ?");
    $stmt->execute([$token]);
    
    // NOW start session with unique name for this admin
    $sessionName = 'ADMIN_SESSION_' . $result['AdminID'];
    session_name($sessionName);
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Clear any existing session data
    session_unset();
    
    // Set admin session variables (matching what admin-login.php sets)
    $_SESSION['admin_id'] = $result['AdminID'];
    $_SESSION['admin_username'] = $result['Username'];
    $_SESSION['admin_name'] = $result['Username'];
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_fullname'] = $result['FullName'] ?? 'Admin';
    $_SESSION['superadmin_impersonation'] = true;
    $_SESSION['last_activity'] = time();
    $_SESSION['created'] = time();
    
    // Set a cookie to remember the session name for this admin
    // This allows admin pages to use the correct session
    setcookie('admin_session_name', $sessionName, 0, '/', '', false, true);
    
    // Create output to show session is set and redirect
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Logging in...</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
            }
            .container {
                text-align: center;
                padding: 40px;
                background: rgba(255,255,255,0.1);
                border-radius: 10px;
                backdrop-filter: blur(10px);
            }
            .spinner {
                border: 4px solid rgba(255,255,255,0.3);
                border-top: 4px solid white;
                border-radius: 50%;
                width: 50px;
                height: 50px;
                animation: spin 1s linear infinite;
                margin: 20px auto;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
        <script>
            // Use JavaScript to redirect after a short delay to ensure cookie is set
            setTimeout(function() {
                window.location.href = '../admin/dashboard.php';
            }, 500);
        </script>
    </head>
    <body>
        <div class="container">
            <div class="spinner"></div>
            <h2>Logging in as <?php echo htmlspecialchars($result['FullName']); ?></h2>
            <p>Redirecting to admin dashboard...</p>
            <p><small>If you are not redirected, <a href="../admin/dashboard.php" style="color: white;">click here</a></small></p>
        </div>
    </body>
    </html>
    <?php
    exit();
    
} catch (Exception $e) {
    echo '<h3>Error</h3>';
    echo '<p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="manage-admins.php">Return to Manage Admins</a></p>';
    exit();
}
