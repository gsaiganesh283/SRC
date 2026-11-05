<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../includes/logo-helper.php';

// Get logo settings
$websiteLogoHtml = renderWebsiteLogo('', '', 70);
$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony Chitti');

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    header('Location: ../admin/dashboard.php');
    exit;
}

$error = '';
$logout = isset($_GET['logout']) ? true : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            // Check admin credentials from database
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE Username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin && password_verify($password, $admin['Password'])) {
                // Set session variables
                $_SESSION['admin_id'] = $admin['AdminID'];
                $_SESSION['admin_username'] = $admin['Username'];
                $_SESSION['admin_name'] = $admin['Username'];
                $_SESSION['admin_logged_in'] = true;
                
                // Update last login time
                try {
                    $stmt = $pdo->prepare("UPDATE admins SET LastLogin = NOW() WHERE AdminID = ?");
                    $stmt->execute([$admin['AdminID']]);
                } catch (Exception $e) {
                    // Ignore if LastLogin column doesn't exist
                }
                
                // Create audit log for successful login
                createAuditLog(
                    $admin['AdminID'],
                    'Admin',
                    'Authentication',
                    'login',
                    'Admin ' . $admin['Username'] . ' logged in successfully',
                    null,
                    null,
                    null,
                    null,
                    'Info'
                );
                
                // Create welcome notification
                createNotification(
                    'Admin',
                    $admin['AdminID'],
                    'System',
                    'Welcome Back!',
                    'You have successfully logged in to SR Colony Chitti Management System.',
                    ['priority' => 'Low', 'expiresAt' => date('Y-m-d H:i:s', strtotime('+1 day'))]
                );
                
                header('Location: ../admin/dashboard.php');
                exit;
            } else {
                // Log failed login attempt
                if ($admin) {
                    createAuditLog(
                        $admin['AdminID'],
                        'Admin',
                        'Authentication',
                        'login_failed',
                        'Failed login attempt for admin ' . $username . ' - Invalid password',
                        null,
                        null,
                        null,
                        null,
                        'Warning'
                    );
                }
                $error = 'Invalid username or password';
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/login-styles.css">
    <style>
        .login-logo { text-align: center; margin-bottom: 15px; }
        .login-logo img { max-height: 70px; width: auto; }
    </style>
</head>
<body>
    <div class="particles"></div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <?php if (hasLogo('website')): ?>
                    <div class="login-logo"><?php echo $websiteLogoHtml; ?></div>
                <?php endif; ?>
                <h1>🔐 Admin Login</h1>
                <p><?php echo htmlspecialchars($siteName); ?> Management System</p>
            </div>
            
            <?php if ($logout): ?>
                <div class="success-message">
                    ✓ You have been successfully logged out!
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message">
                    ⚠️ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <div class="info-box" style="background: #fff9e6; border-left-color: #ffa801;">
                <p><strong>🔒 Admin Access Only</strong></p>
                <p>This area is restricted to authorized administrators only. Unauthorized access attempts will be logged.</p>
            </div>
            
            <form method="POST" action="" id="adminLoginForm">
                <div class="form-group">
                    <label for="username">👤 Username</label>
                    <input 
                        type="text" 
                        id="username" 
                        name="username" 
                        class="form-control" 
                        placeholder="Enter admin username" 
                        required
                        autocomplete="username"
                        value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label for="password">🔒 Password</label>
                    <div class="password-toggle">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-control" 
                            placeholder="Enter admin password" 
                            required
                            autocomplete="current-password"
                        >
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('password')">
                            👁️
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-login" style="background: linear-gradient(135deg, #ffa801 0%, #ff6b6b 100%);">
                    🚀 Login to Dashboard
                </button>
            </form>
            
            <div class="login-footer">
                <a href="../index.html">← Back to Home</a>
            </div>
            
            <div class="security-notice">
                <strong>⚠️ Security Notice:</strong><br>
                For security reasons, admin password reset must be done through database access.
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
    <script src="../assets/js/admin-scripts.js"></script>
</body>
</html>
