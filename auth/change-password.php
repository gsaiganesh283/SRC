<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: member-login.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    if (empty($newPassword) || empty($confirmPassword)) {
        $error = 'Please fill in all fields';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters long';
    } else {
        try {
            $memberId = $_SESSION['member_id'];
            
            if (isset($_SESSION['first_login'])) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE members SET Password = ? WHERE MemberID = ?");
                $stmt->execute([$hashedPassword, $memberId]);
                
                // Create audit log for first password setup
                createAuditLog(
                    $memberId,
                    'Member',
                    'Authentication',
                    'Set Password',
                    "Member set their password for the first time",
                    'members',
                    $memberId,
                    null,
                    null,
                    'Medium'
                );
                
                unset($_SESSION['first_login']);
                $success = 'Password changed successfully! Redirecting to dashboard...';
                header('refresh:2;url=../member/dashboard.php');
            } else {
                $stmt = $pdo->prepare("SELECT Password FROM members WHERE MemberID = ?");
                $stmt->execute([$memberId]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (password_verify($currentPassword, $member['Password'])) {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE members SET Password = ? WHERE MemberID = ?");
                    $stmt->execute([$hashedPassword, $memberId]);
                    
                    // Create audit log for password change
                    createAuditLog(
                        $memberId,
                        'Member',
                        'Authentication',
                        'Change Password',
                        "Member changed their password",
                        'members',
                        $memberId,
                        null,
                        null,
                        'Medium'
                    );
                    
                    $success = 'Password changed successfully!';
                } else {
                    $error = 'Current password is incorrect';
                }
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
            // Log the actual error for debugging (remove in production)
            error_log("Password change error: " . $e->getMessage());
        }
    }
}

$isFirstLogin = isset($_SESSION['first_login']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - SR Colony Chitti</title>
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/styles.css">
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            max-width: 450px;
            width: 100%;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: #667eea;
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .error-message {
            background: #fff0f0;
            color: #ff4757;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #ff4757;
        }
        
        .success-message {
            background: #f0fff4;
            color: #2ed573;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #2ed573;
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }
        
        .info-box {
            background: #fff9e6;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #ffa801;
        }
        
        .info-box p {
            margin: 0;
            font-size: 13px;
            color: #555;
        }
        
        .password-requirements {
            background: #f0f4ff;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13px;
        }
        
        .password-requirements ul {
            margin: 5px 0 0 20px;
            padding: 0;
        }
        
        .password-requirements li {
            margin: 5px 0;
            color: #555;
        }
        
        .password-toggle {
            position: relative;
            width: 100%;
        }
        
        .password-toggle-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #667eea;
            font-size: 18px;
        }
    </style>
</head>
<body>
    <div class="particles"></div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>🔒 Change Password</h1>
                <p>Welcome, <?php echo htmlspecialchars($_SESSION['member_name']); ?>!</p>
            </div>
            
            <?php if ($isFirstLogin): ?>
                <div class="info-box warning">
                    <p><strong>⚠️ First Time Login Detected</strong></p>
                    <p>Please create a new secure password for your account.</p>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error-message">
                    ⚠️ <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message">
                    ✓ <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <div class="password-requirements">
                <strong>Password Requirements:</strong>
                <ul>
                    <li>Minimum 6 characters</li>
                    <li>Use a combination of letters and numbers</li>
                    <li>Avoid using personal information</li>
                </ul>
            </div>
            
            <form method="POST" action="" id="changePasswordForm">
                <?php if (!$isFirstLogin): ?>
                <div class="form-group">
                    <label for="current_password">🔐 Current Password</label>
                    <div class="password-toggle">
                        <input 
                            type="password" 
                            id="current_password" 
                            name="current_password" 
                            class="form-control" 
                            placeholder="Enter current password" 
                            required
                        >
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('current_password')">
                            👁️
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="new_password">🔑 New Password</label>
                    <div class="password-toggle">
                        <input 
                            type="password" 
                            id="new_password" 
                            name="new_password" 
                            class="form-control" 
                            placeholder="Enter new password" 
                            minlength="6"
                            required
                        >
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('new_password')">
                            👁️
                        </button>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">✓ Confirm New Password</label>
                    <div class="password-toggle">
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            class="form-control" 
                            placeholder="Re-enter new password" 
                            minlength="6"
                            required
                        >
                        <button type="button" class="password-toggle-btn" onclick="togglePassword('confirm_password')">
                            👁️
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">
                    💾 Change Password
                </button>
            </form>
            
            <?php if (!$isFirstLogin): ?>
            <div style="text-align: center; margin-top: 20px;">
                <a href="../member/dashboard.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="<?php echo ASSETS_URL; ?>/js/script.js"></script>
</body>
</html>