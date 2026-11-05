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
if (isset($_SESSION['member_id'])) {
    header('Location: ../member/dashboard.php');
    exit();
}

$error = '';
$timeout = isset($_GET['timeout']) ? true : false;
$logout = isset($_GET['logout']) ? true : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = sanitizeInput($_POST['phone']);
    $password = $_POST['password'];
    
    if (empty($phone) || empty($password)) {
        $error = 'Please enter both mobile number and password';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM members WHERE Phone = ?");
            $stmt->execute([$phone]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($member) {
                if ($member['Password'] === null || $member['Password'] === '') {
                    if ($password === $phone) {
                        $_SESSION['member_id'] = $member['MemberID'];
                        $_SESSION['member_name'] = $member['MemberName'] ?? 'Member';
                        $_SESSION['member_phone'] = $member['Phone'];
                        $_SESSION['first_login'] = true;
                        
                        // Create audit log for first-time login
                        createAuditLog(
                            $member['MemberID'],
                            'Member',
                            'Authentication',
                            'First Time Login',
                            "Member {$member['MemberName']} logged in for the first time",
                            'members',
                            $member['MemberID'],
                            null,
                            null,
                            'Medium'
                        );
                        
                        header('Location: change-password.php');
                        exit();
                    } else {
                        $error = 'Invalid credentials. For first login, use your mobile number as password.';
                    }
                } else {
                    if (password_verify($password, $member['Password'])) {
                        $_SESSION['member_id'] = $member['MemberID'];
                        $_SESSION['member_name'] = $member['MemberName'] ?? 'Member';
                        $_SESSION['member_phone'] = $member['Phone'];
                        
                        // Create audit log for member login
                        createAuditLog(
                            $member['MemberID'],
                            'Member',
                            'Authentication',
                            'Member Login',
                            "Member {$member['MemberName']} logged in successfully",
                            'members',
                            $member['MemberID'],
                            null,
                            null,
                            'Low'
                        );
                        
                        header('Location: ../member/dashboard.php');
                        exit();
                    } else {
                        // Create audit log for failed login
                        createAuditLog(
                            0,
                            'Member',
                            'Authentication',
                            'Failed Login Attempt',
                            "Failed login attempt for phone: $phone",
                            'members',
                            0,
                            null,
                            null,
                            'Medium'
                        );
                        
                        $error = 'Invalid mobile number or password';
                    }
                }
            } else {
                $error = 'Invalid mobile number or password';
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
    <title>Member Login - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="../assets/css/styles.css">
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
                <h1>👤 Member Login</h1>
                <p><?php echo htmlspecialchars($siteName); ?> Management System</p>
            </div>
            
            <?php if ($logout): ?>
                <div class="success-message">
                    ✓ You have been successfully logged out!
                </div>
            <?php endif; ?>
            
            <?php if ($timeout): ?>
                <div class="error-message">
                    ⚠️ Your session has expired due to inactivity. Please login again.
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message">
                    ⚠️ <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                <p><strong>📱 First Time Login?</strong></p>
                <p>Use your 10-digit mobile number as both username and password. You'll be prompted to change your password after first login.</p>
            </div>
            
            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label for="phone">📱 Mobile Number</label>
                    <input 
                        type="tel" 
                        id="phone" 
                        name="phone" 
                        class="form-control" 
                        placeholder="Enter 10-digit mobile number" 
                        pattern="[0-9]{10}" 
                        maxlength="10"
                        required
                        value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
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
                            placeholder="Enter your password" 
                            required
                        >
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('password')">
                            👁️
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">
                    🚀 Login to Dashboard
                </button>
            </form>
            
            <div class="login-footer">
                <a href="forgot-password.php">🔑 Forgot Password?</a>
                <br>
                <a href="../index.html" style="margin-top: 10px; display: inline-block;">← Back to Home</a>
            </div>
        </div>
    </div>

    <script src="../assets/js/script.js"></script>
</body>
</html>