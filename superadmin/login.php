<?php
session_start();
require_once '../config/config.php';
require_once '../includes/logo-helper.php';
require_once '../includes/totp-helper.php';
require_once '../includes/face-helper.php';

// Ensure face columns exist in database
FaceHelper::ensureColumnsExist($pdo);

$error = '';
$show2FA = false; // Whether to show the 2FA verification form
$showFaceLogin = false; // Whether to show face login camera
$websiteLogoHtml = renderWebsiteLogo('', '', 80);
$faviconHtml = renderFavicon();

// Check if face login is available (any admin has it enabled)
$faceLoginAvailable = FaceHelper::anyFaceAuthEnabled($pdo);
$siteName = getSiteName('SR Colony');

// Check if already logged in
if (isset($_SESSION['superadmin_id'])) {
    header('Location: dashboard.php');
    exit;
}

// Handle cancel 2FA (back to login)
if (isset($_GET['cancel2fa'])) {
    unset($_SESSION['pending_2fa_superadmin_id']);
    unset($_SESSION['pending_2fa_username']);
    header('Location: login.php');
    exit;
}

// Handle 2FA verification step
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp_verify'])) {
    $totpCode = trim($_POST['totp_code'] ?? '');
    $pendingId = $_SESSION['pending_2fa_superadmin_id'] ?? null;
    
    if (!$pendingId) {
        $error = 'Session expired. Please log in again.';
    } elseif (empty($totpCode) || strlen($totpCode) !== 6 || !ctype_digit($totpCode)) {
        $error = 'Please enter a valid 6-digit verification code.';
        $show2FA = true;
    } else {
        $secret = TOTPHelper::getSecret($pdo, $pendingId);
        if ($secret && TOTPHelper::verifyCode($secret, $totpCode)) {
            // 2FA verified - complete login
            $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE SuperAdminID = ? AND Status = 'Active'");
            $stmt->execute([$pendingId]);
            $superadmin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($superadmin) {
                $_SESSION['superadmin_id'] = $superadmin['SuperAdminID'];
                $_SESSION['superadmin_username'] = $superadmin['Username'];
                $_SESSION['superadmin_name'] = $superadmin['FullName'] ?? $superadmin['Name'] ?? 'Super Admin';
                $_SESSION['user_type'] = 'SuperAdmin';
                
                // Clear pending 2FA session data
                unset($_SESSION['pending_2fa_superadmin_id']);
                unset($_SESSION['pending_2fa_username']);
                
                // Update last login
                $stmt = $pdo->prepare("UPDATE super_admins SET LastLogin = NOW() WHERE SuperAdminID = ?");
                $stmt->execute([$superadmin['SuperAdminID']]);
                
                // Log activity
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (UserType, UserID, Username, Action, IPAddress) 
                    VALUES ('SuperAdmin', ?, ?, 'Logged In (2FA Verified)', ?)
                ");
                $stmt->execute([$superadmin['SuperAdminID'], $superadmin['Username'], $ip]);
                
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Account not found. Please log in again.';
                unset($_SESSION['pending_2fa_superadmin_id']);
                unset($_SESSION['pending_2fa_username']);
            }
        } else {
            $error = 'Invalid verification code. Please try again.';
            $show2FA = true;
            
            // Log failed 2FA attempt
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $pendingUsername = $_SESSION['pending_2fa_username'] ?? 'Unknown';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, IPAddress) 
                VALUES ('SuperAdmin', ?, ?, 'Failed 2FA Verification', ?)
            ");
            $stmt->execute([$pendingId, $pendingUsername, $ip]);
        }
    }
}

// Handle initial login (username + password)
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE Username = ? AND Status = 'Active'");
            $stmt->execute([$username]);
            $superadmin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($superadmin && password_verify($password, $superadmin['Password'])) {
                
                // Check if 2FA is enabled for this user
                if (!empty($superadmin['totp_enabled']) && !empty($superadmin['totp_secret'])) {
                    // Store pending login in session and show 2FA form
                    $_SESSION['pending_2fa_superadmin_id'] = $superadmin['SuperAdminID'];
                    $_SESSION['pending_2fa_username'] = $superadmin['Username'];
                    $show2FA = true;
                } else {
                    // No 2FA - complete login directly
                    $_SESSION['superadmin_id'] = $superadmin['SuperAdminID'];
                    $_SESSION['superadmin_username'] = $superadmin['Username'];
                    $_SESSION['superadmin_name'] = $superadmin['FullName'] ?? $superadmin['Name'] ?? 'Super Admin';
                    $_SESSION['user_type'] = 'SuperAdmin';
                    
                    // Update last login
                    $stmt = $pdo->prepare("UPDATE super_admins SET LastLogin = NOW() WHERE SuperAdminID = ?");
                    $stmt->execute([$superadmin['SuperAdminID']]);
                    
                    // Log activity
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                    $stmt = $pdo->prepare("
                        INSERT INTO audit_logs (UserType, UserID, Username, Action, IPAddress) 
                        VALUES ('SuperAdmin', ?, ?, 'Logged In', ?)
                    ");
                    $stmt->execute([$superadmin['SuperAdminID'], $username, $ip]);
                    
                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = 'Invalid username or password';
                
                // Log failed attempt
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (UserType, UserID, Username, Action, IPAddress) 
                    VALUES ('SuperAdmin', 0, ?, 'Failed Login Attempt', ?)
                ");
                $stmt->execute([$username, $ip]);
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
        }
    }
}

// Check if we have a pending 2FA session (e.g., page refresh)
if (isset($_SESSION['pending_2fa_superadmin_id']) && !$show2FA && empty($error)) {
    $show2FA = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Login - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #7e22ce 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
            animation: slideIn 0.5s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .login-header {
            background: linear-gradient(135deg, #7e22ce, #1e3c72);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .login-header .logo-container {
            margin-bottom: 15px;
        }
        
        .login-header .logo-container img {
            max-height: 80px;
            width: auto;
        }
        
        .login-header i.default-icon {
            font-size: 64px;
            margin-bottom: 15px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .login-header h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .login-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .login-body {
            padding: 40px 30px;
        }
        
        .alert {
            background: #fee;
            border-left: 4px solid #dc3545;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert i {
            font-size: 20px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }
        
        .input-group {
            position: relative;
        }
        
        .input-group i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }
        
        .form-control {
            width: 100%;
            padding: 15px 15px 15px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #7e22ce;
            box-shadow: 0 0 0 4px rgba(126, 34, 206, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #7e22ce, #1e3c72);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(126, 34, 206, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn i {
            margin-right: 8px;
        }
        
        .login-footer {
            padding: 20px 30px;
            background: #f8f9fa;
            text-align: center;
            font-size: 13px;
            color: #666;
        }
        
        .login-footer a {
            color: #7e22ce;
            text-decoration: none;
            font-weight: 600;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
            color: #28a745;
            font-size: 13px;
        }
        
        .security-badge i {
            font-size: 16px;
        }
        
        /* 2FA Verification Styles */
        .totp-section {
            text-align: center;
        }
        
        .totp-icon {
            font-size: 48px;
            color: #7e22ce;
            margin-bottom: 15px;
        }
        
        .totp-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        
        .totp-subtitle {
            font-size: 13px;
            color: #666;
            margin-bottom: 25px;
            line-height: 1.5;
        }
        
        .totp-input {
            width: 100%;
            padding: 18px;
            font-size: 28px;
            font-family: 'Courier New', monospace;
            letter-spacing: 10px;
            text-align: center;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        
        .totp-input:focus {
            outline: none;
            border-color: #7e22ce;
            box-shadow: 0 0 0 4px rgba(126, 34, 206, 0.1);
        }
        
        .totp-input::placeholder {
            letter-spacing: 4px;
            font-size: 18px;
        }
        
        .back-to-login {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 15px;
            color: #7e22ce;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        
        .back-to-login:hover {
            text-decoration: underline;
        }

        /* ========== Face Login Styles ========== */
        .login-divider {
            display: flex;
            align-items: center;
            margin: 25px 0;
            gap: 15px;
        }

        .login-divider::before,
        .login-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e0e0e0;
        }

        .login-divider span {
            color: #999;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-face-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-face-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(16, 185, 129, 0.3);
        }

        .btn-face-login:active {
            transform: translateY(0);
        }

        /* Face Login Section */
        .face-login-section {
            text-align: center;
        }

        .face-login-icon {
            font-size: 48px;
            color: #10b981;
            margin-bottom: 15px;
        }

        .face-login-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .face-login-subtitle {
            font-size: 13px;
            color: #666;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .face-camera-wrapper {
            position: relative;
            width: 260px;
            height: 330px;
            margin: 0 auto 20px;
            border-radius: 50%;
            overflow: hidden;
            background: #0f172a;
        }

        .face-camera-wrapper video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transform: scaleX(-1);
        }

        .face-camera-wrapper canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            display: none;
        }

        .face-oval-guide {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 4;
        }
        .face-oval-guide svg { width: 100%; height: 100%; }

        .face-pulse-ring {
            position: absolute;
            inset: 6px;
            border-radius: 50%;
            border: 3px solid rgba(16,185,129,.4);
            z-index: 5;
            pointer-events: none;
            opacity: 0;
        }
        .face-camera-wrapper.scanning .face-pulse-ring {
            animation: facePulseRing 1.8s ease-in-out infinite;
            opacity: 1;
        }
        .face-camera-wrapper.matched .face-pulse-ring {
            animation: none;
            opacity: 1;
            border-color: #10b981;
            border-width: 4px;
        }
        .face-camera-wrapper.failed .face-pulse-ring {
            animation: none;
            opacity: 1;
            border-color: #ef4444;
        }
        @keyframes facePulseRing {
            0%   { opacity: .5; transform: scale(.97); border-color: rgba(16,185,129,.5); }
            50%  { opacity: 1;  transform: scale(1.02); border-color: rgba(59,130,246,.7); }
            100% { opacity: .5; transform: scale(.97); border-color: rgba(16,185,129,.5); }
        }

        .face-camera-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15,23,42,0.6);
            color: white;
            flex-direction: column;
            gap: 10px;
            z-index: 6;
        }

        .face-camera-overlay.hidden {
            opacity: 0;
            pointer-events: none;
            transition: opacity .3s;
        }

        .face-status-bar {
            padding: 10px 16px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }

        .face-status-bar.loading {
            background: #eff6ff;
            color: #1e40af;
        }

        .face-status-bar.scanning {
            background: #fef3c7;
            color: #92400e;
        }

        .face-status-bar.verifying {
            background: #eff6ff;
            color: #1e40af;
        }

        .face-status-bar.success {
            background: #d1fae5;
            color: #065f46;
        }

        .face-status-bar.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .face-progress {
            background: #e0e0e0;
            border-radius: 10px;
            height: 6px;
            margin-bottom: 15px;
            overflow: hidden;
        }

        .face-progress-bar {
            height: 100%;
            border-radius: 10px;
            background: linear-gradient(90deg, #10b981, #3b82f6);
            transition: width 0.4s ease;
            width: 0%;
        }

        .face-spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid currentColor;
            border-radius: 50%;
            border-top-color: transparent;
            animation: faceSpin 0.8s linear infinite;
        }

        @keyframes faceSpin {
            to { transform: rotate(360deg); }
        }

        /* Welcome name flash */
        .face-welcome-name {
            font-size: 18px;
            font-weight: 700;
            color: #10b981;
            animation: fadeWelcome .6s ease;
        }
        @keyframes fadeWelcome { from { opacity: 0; transform: scale(.8); } to { opacity: 1; transform: scale(1); } }

        /* ========== SPLASH SCREEN STYLES ========== */
        .splash-screen {
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: linear-gradient(135deg, #0f0c29 0%, #1e3c72 40%, #2a5298 70%, #7e22ce 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            transition: opacity 0.8s cubic-bezier(0.4, 0, 0.2, 1), transform 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .splash-screen.fade-out {
            opacity: 0;
            transform: scale(1.1);
            pointer-events: none;
        }
        .splash-canvas {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
        }
        .splash-content {
            position: relative;
            z-index: 2;
            text-align: center;
            color: #fff;
        }

        /* Animated icon with rings */
        .splash-icon-wrap {
            position: relative;
            width: 140px;
            height: 140px;
            margin: 0 auto 30px;
        }
        .splash-icon {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 52px;
            color: #fff;
            z-index: 3;
            opacity: 0;
            transform: scale(0);
            animation: splashIconIn 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) 0.3s forwards;
        }
        @keyframes splashIconIn {
            to { opacity: 1; transform: scale(1); }
        }
        .splash-ring {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 2px solid transparent;
        }
        .splash-ring-1 {
            border-top-color: rgba(255,255,255,0.6);
            border-right-color: rgba(255,255,255,0.2);
            animation: splashSpin 2.5s linear infinite, splashRingIn 0.6s ease 0.1s both;
        }
        .splash-ring-2 {
            inset: 10px;
            border-bottom-color: rgba(126,34,206,0.8);
            border-left-color: rgba(126,34,206,0.3);
            animation: splashSpinReverse 2s linear infinite, splashRingIn 0.6s ease 0.3s both;
        }
        .splash-ring-3 {
            inset: 20px;
            border-top-color: rgba(52,152,219,0.7);
            border-right-color: rgba(52,152,219,0.2);
            animation: splashSpin 1.5s linear infinite, splashRingIn 0.6s ease 0.5s both;
        }
        @keyframes splashSpin { to { transform: rotate(360deg); } }
        @keyframes splashSpinReverse { to { transform: rotate(-360deg); } }
        @keyframes splashRingIn {
            from { opacity: 0; transform: scale(0.3) rotate(0deg); }
            to { opacity: 1; transform: scale(1) rotate(0deg); }
        }

        /* Title with letter animation */
        .splash-title {
            font-size: 48px;
            font-weight: 800;
            letter-spacing: 4px;
            margin-bottom: 8px;
            text-shadow: 0 4px 30px rgba(0,0,0,0.3);
        }
        .splash-title span {
            display: inline-block;
            opacity: 0;
            transform: translateY(30px) rotateX(90deg);
            animation: splashLetterIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        }
        .splash-title span:nth-child(1) { animation-delay: 0.6s; }
        .splash-title span:nth-child(2) { animation-delay: 0.7s; }
        .splash-title span:nth-child(3) { animation-delay: 0.75s; width: 12px; }
        .splash-title span:nth-child(4) { animation-delay: 0.85s; }
        .splash-title span:nth-child(5) { animation-delay: 0.95s; }
        .splash-title span:nth-child(6) { animation-delay: 1.05s; }
        .splash-title span:nth-child(7) { animation-delay: 1.15s; }
        .splash-title span:nth-child(8) { animation-delay: 1.25s; }
        .splash-title span:nth-child(9) { animation-delay: 1.35s; }
        @keyframes splashLetterIn {
            to { opacity: 1; transform: translateY(0) rotateX(0deg); }
        }

        .splash-subtitle {
            font-size: 16px;
            font-weight: 300;
            opacity: 0;
            letter-spacing: 6px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.7);
            animation: splashFadeUp 0.8s ease 1.5s forwards;
            margin-bottom: 40px;
        }
        @keyframes splashFadeUp {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Progress bar */
        .splash-progress-wrap {
            width: 280px;
            height: 4px;
            background: rgba(255,255,255,0.1);
            border-radius: 4px;
            margin: 0 auto 12px;
            position: relative;
            overflow: hidden;
            opacity: 0;
            animation: splashFadeUp 0.5s ease 1.8s forwards;
        }
        .splash-progress-bar {
            width: 0%;
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, #3498db, #9b59b6, #e74c3c, #f39c12, #2ecc71);
            background-size: 300% 100%;
            animation: splashProgressFill 3s cubic-bezier(0.4, 0, 0.2, 1) 2s forwards, splashGradientShift 2s linear infinite;
            transition: width 0.3s;
        }
        @keyframes splashProgressFill {
            0% { width: 0%; }
            15% { width: 20%; }
            35% { width: 45%; }
            55% { width: 65%; }
            75% { width: 85%; }
            100% { width: 100%; }
        }
        @keyframes splashGradientShift {
            0% { background-position: 0% 50%; }
            100% { background-position: 300% 50%; }
        }
        .splash-progress-glow {
            position: absolute;
            top: -3px;
            left: 0;
            width: 0%;
            height: 10px;
            background: radial-gradient(ellipse, rgba(255,255,255,0.4), transparent);
            border-radius: 4px;
            filter: blur(3px);
            animation: splashProgressFill 3s cubic-bezier(0.4, 0, 0.2, 1) 2s forwards;
        }

        .splash-loading-text {
            font-size: 12px;
            color: rgba(255,255,255,0.5);
            letter-spacing: 2px;
            text-transform: uppercase;
            opacity: 0;
            animation: splashFadeUp 0.5s ease 2s forwards;
            margin-bottom: 30px;
            min-height: 18px;
        }

        /* Security stat badges */
        .splash-stats {
            display: flex;
            justify-content: center;
            gap: 40px;
            opacity: 0;
            animation: splashFadeUp 0.6s ease 2.5s forwards;
        }
        .splash-stat {
            text-align: center;
        }
        .splash-stat-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 6px;
            font-size: 18px;
            color: rgba(255,255,255,0.8);
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255,255,255,0.15);
            animation: splashStatPop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1) both;
        }
        .splash-stat:nth-child(1) .splash-stat-icon { animation-delay: 2.6s; }
        .splash-stat:nth-child(2) .splash-stat-icon { animation-delay: 2.8s; }
        .splash-stat:nth-child(3) .splash-stat-icon { animation-delay: 3.0s; }
        @keyframes splashStatPop {
            from { opacity: 0; transform: scale(0); }
            to { opacity: 1; transform: scale(1); }
        }
        .splash-stat-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: rgba(255,255,255,0.5);
        }

        /* Mobile responsive */
        @media (max-width: 480px) {
            .splash-title { font-size: 36px; letter-spacing: 2px; }
            .splash-subtitle { font-size: 12px; letter-spacing: 4px; }
            .splash-icon-wrap { width: 110px; height: 110px; }
            .splash-icon { font-size: 40px; }
            .splash-stats { gap: 25px; }
            .splash-progress-wrap { width: 220px; }
        }
    </style>
</head>
<body>
    <!-- ========== ADVANCED WELCOME SPLASH SCREEN ========== -->
    <div id="splashScreen" class="splash-screen">
        <!-- Animated background particles -->
        <canvas id="splashCanvas" class="splash-canvas"></canvas>

        <!-- Central content -->
        <div class="splash-content">
            <!-- Animated shield icon -->
            <div class="splash-icon-wrap">
                <div class="splash-ring splash-ring-1"></div>
                <div class="splash-ring splash-ring-2"></div>
                <div class="splash-ring splash-ring-3"></div>
                <div class="splash-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
            </div>

            <!-- Title with letter-by-letter animation -->
            <h1 class="splash-title" id="splashTitle">
                <span>S</span><span>R</span><span> </span><span>C</span><span>o</span><span>l</span><span>o</span><span>n</span><span>y</span>
            </h1>
            <div class="splash-subtitle" id="splashSubtitle">Super Admin Control Panel</div>

            <!-- Animated progress bar -->
            <div class="splash-progress-wrap">
                <div class="splash-progress-bar" id="splashProgressBar"></div>
                <div class="splash-progress-glow" id="splashProgressGlow"></div>
            </div>
            <div class="splash-loading-text" id="splashLoadingText">Initializing secure environment...</div>

            <!-- Animated stats counter -->
            <div class="splash-stats" id="splashStats">
                <div class="splash-stat">
                    <div class="splash-stat-icon"><i class="fas fa-shield-alt"></i></div>
                    <div class="splash-stat-label">Encrypted</div>
                </div>
                <div class="splash-stat">
                    <div class="splash-stat-icon"><i class="fas fa-lock"></i></div>
                    <div class="splash-stat-label">Secured</div>
                </div>
                <div class="splash-stat">
                    <div class="splash-stat-icon"><i class="fas fa-fingerprint"></i></div>
                    <div class="splash-stat-label">Verified</div>
                </div>
            </div>
        </div>
    </div>

    <div class="login-container" id="loginContainer" style="display:none;">
        <div class="login-header">
            <?php if (hasLogo('website')): ?>
                <div class="logo-container"><?php echo $websiteLogoHtml; ?></div>
            <?php else: ?>
                <i class="fas fa-user-shield default-icon"></i>
            <?php endif; ?>
            <h1>Super Admin Portal</h1>
            <p>Complete System Control & Management</p>
        </div>
        
        <div class="login-body">
            <?php if ($error): ?>
            <div class="alert">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($show2FA): ?>
            <!-- 2FA Verification Form -->
            <div class="totp-section">
                <i class="fas fa-shield-alt totp-icon"></i>
                <div class="totp-title">Two-Factor Authentication</div>
                <div class="totp-subtitle">Enter the 6-digit code from your<br>Google Authenticator app</div>
                
                <form method="POST" action="">
                    <input type="hidden" name="totp_verify" value="1">
                    <input type="text" 
                           name="totp_code" 
                           class="totp-input" 
                           placeholder="000000" 
                           maxlength="6" 
                           pattern="[0-9]{6}" 
                           inputmode="numeric"
                           autocomplete="one-time-code"
                           required 
                           autofocus>
                    
                    <button type="submit" class="btn">
                        <i class="fas fa-check-circle"></i> Verify Code
                    </button>
                    
                    <div class="security-badge">
                        <i class="fas fa-clock"></i>
                        <span>Code refreshes every 30 seconds</span>
                    </div>
                </form>
                
                <a href="login.php?cancel2fa=1" class="back-to-login">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
            <?php else: ?>
            <!-- Normal Login Form (default view) -->
            <div id="passwordLoginSection">
                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <div class="input-group">
                            <i class="fas fa-user"></i>
                            <input type="text" name="username" class="form-control" placeholder="Enter username" required autofocus>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn">
                        <i class="fas fa-sign-in-alt"></i> Login to Super Admin
                    </button>
                    
                    <div class="security-badge">
                        <i class="fas fa-shield-alt"></i>
                        <span>Secure & Encrypted Connection</span>
                    </div>
                </form>

                <?php if ($faceLoginAvailable): ?>
                <!-- Face Login Divider & Button -->
                <div class="login-divider">
                    <span>or</span>
                </div>
                <button type="button" class="btn-face-login" onclick="showFaceLogin()">
                    <i class="fas fa-face-smile"></i> Login with Face Lock
                </button>
                <?php endif; ?>
            </div>

            <?php if ($faceLoginAvailable): ?>
            <!-- Face Login Section (hidden by default) -->
            <div id="faceLoginSection" style="display: none;">
                <div class="face-login-section">
                    <i class="fas fa-face-smile face-login-icon"></i>
                    <div class="face-login-title">Face Lock Login</div>
                    <div class="face-login-subtitle">Position your face in the camera.<br>You'll be verified automatically.</div>

                    <!-- Status Bar -->
                    <div class="face-status-bar loading" id="faceStatusBar">
                        <div class="face-spinner"></div>
                        <span id="faceStatusText">Loading face recognition...</span>
                    </div>

                    <!-- Progress Bar -->
                    <div class="face-progress" id="faceProgressWrap">
                        <div class="face-progress-bar" id="faceProgressBar"></div>
                    </div>

                    <!-- Camera (oval) -->
                    <div class="face-camera-wrapper" id="faceCameraWrapper">
                        <div class="face-pulse-ring"></div>
                        <div class="face-oval-guide">
                            <svg viewBox="0 0 260 330" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <defs><mask id="loginOvalMask"><rect width="260" height="330" fill="white"/><ellipse cx="130" cy="160" rx="95" ry="125" fill="black"/></mask></defs>
                                <rect width="260" height="330" fill="rgba(0,0,0,0.45)" mask="url(#loginOvalMask)"/>
                                <ellipse cx="130" cy="160" rx="95" ry="125" stroke="rgba(255,255,255,0.3)" stroke-width="2" fill="none" stroke-dasharray="7 3"/>
                            </svg>
                        </div>
                        <div class="face-camera-overlay" id="faceCameraOverlay">
                            <i class="fas fa-face-smile" style="font-size: 36px; opacity: 0.7;"></i>
                            <p style="font-size: 13px; opacity: 0.8;">Initializing camera...</p>
                        </div>
                        <video id="faceVideo" autoplay muted playsinline></video>
                        <canvas id="faceOverlayCanvas"></canvas>
                    </div>

                    <a href="#" class="back-to-login" onclick="showPasswordLogin(); return false;">
                        <i class="fas fa-arrow-left"></i> Back to Password Login
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
        
        <div class="login-footer">
            <p>
                <i class="fas fa-arrow-left"></i> 
                <a href="../auth/admin-login.php">Regular Admin Login</a> | 
                <a href="../index.html">Home</a>
            </p>
            <p style="margin-top: 10px; font-size: 12px; color: #999;">
                &copy; <?php echo date('Y'); ?> SR Colony Management System
            </p>
        </div>
    </div>
    <!-- ========== SPLASH SCREEN SCRIPT ========== -->
    <script>
    (function() {
        'use strict';
        var canvas = document.getElementById('splashCanvas');
        var ctx = canvas.getContext('2d');
        var particles = [];
        var connections = [];
        var animFrame;
        var startTime = Date.now();
        var SPLASH_DURATION = 5000; // 5 seconds

        // Loading text sequence
        var loadingTexts = [
            'Initializing secure environment...',
            'Loading encryption modules...',
            'Establishing secure connection...',
            'Verifying system integrity...',
            'Preparing admin console...',
            'Almost ready...'
        ];
        var currentTextIndex = 0;
        var textEl = document.getElementById('splashLoadingText');

        // Cycle loading text
        var textInterval = setInterval(function() {
            currentTextIndex++;
            if (currentTextIndex < loadingTexts.length && textEl) {
                textEl.style.opacity = '0';
                textEl.style.transform = 'translateY(5px)';
                setTimeout(function() {
                    textEl.textContent = loadingTexts[currentTextIndex];
                    textEl.style.opacity = '1';
                    textEl.style.transform = 'translateY(0)';
                }, 200);
            }
        }, 700);

        // Resize canvas
        function resizeCanvas() {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        }
        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);

        // Particle class
        function Particle() {
            this.x = Math.random() * canvas.width;
            this.y = Math.random() * canvas.height;
            this.vx = (Math.random() - 0.5) * 0.8;
            this.vy = (Math.random() - 0.5) * 0.8;
            this.radius = Math.random() * 2 + 0.5;
            this.opacity = Math.random() * 0.5 + 0.2;
            this.color = ['rgba(255,255,255,', 'rgba(147,130,220,', 'rgba(52,152,219,', 'rgba(155,89,182,'][Math.floor(Math.random() * 4)];
        }

        // Create particles
        var particleCount = Math.min(80, Math.floor(window.innerWidth / 15));
        for (var i = 0; i < particleCount; i++) {
            particles.push(new Particle());
        }

        // Animate particles
        function animate() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            // Update & draw particles
            for (var i = 0; i < particles.length; i++) {
                var p = particles[i];
                p.x += p.vx;
                p.y += p.vy;

                // Bounce off edges
                if (p.x < 0 || p.x > canvas.width) p.vx *= -1;
                if (p.y < 0 || p.y > canvas.height) p.vy *= -1;

                // Draw particle
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
                ctx.fillStyle = p.color + p.opacity + ')';
                ctx.fill();

                // Draw connections
                for (var j = i + 1; j < particles.length; j++) {
                    var p2 = particles[j];
                    var dx = p.x - p2.x;
                    var dy = p.y - p2.y;
                    var dist = Math.sqrt(dx * dx + dy * dy);

                    if (dist < 120) {
                        var lineOpacity = (1 - dist / 120) * 0.15;
                        ctx.beginPath();
                        ctx.moveTo(p.x, p.y);
                        ctx.lineTo(p2.x, p2.y);
                        ctx.strokeStyle = 'rgba(255,255,255,' + lineOpacity + ')';
                        ctx.lineWidth = 0.5;
                        ctx.stroke();
                    }
                }
            }

            // Floating hexagons effect
            var elapsed = Date.now() - startTime;
            var hexCount = 3;
            for (var h = 0; h < hexCount; h++) {
                var t = (elapsed / (3000 + h * 1000)) + h * 2;
                var hx = canvas.width * (0.2 + h * 0.3) + Math.sin(t) * 40;
                var hy = canvas.height * (0.3 + h * 0.2) + Math.cos(t * 0.7) * 30;
                var size = 25 + h * 10;
                var hOpacity = 0.04 + Math.sin(t * 2) * 0.02;

                ctx.beginPath();
                for (var s = 0; s < 6; s++) {
                    var angle = (Math.PI / 3) * s + elapsed / (5000 + h * 1000);
                    var px = hx + size * Math.cos(angle);
                    var py = hy + size * Math.sin(angle);
                    if (s === 0) ctx.moveTo(px, py);
                    else ctx.lineTo(px, py);
                }
                ctx.closePath();
                ctx.strokeStyle = 'rgba(255,255,255,' + hOpacity + ')';
                ctx.lineWidth = 1;
                ctx.stroke();
            }

            animFrame = requestAnimationFrame(animate);
        }
        animate();

        // End splash after 5 seconds
        function endSplash() {
            clearInterval(textInterval);
            var splash = document.getElementById('splashScreen');
            var login = document.getElementById('loginContainer');

            splash.classList.add('fade-out');

            setTimeout(function() {
                splash.style.display = 'none';
                cancelAnimationFrame(animFrame);
                login.style.display = '';
                login.style.animation = 'loginReveal 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) forwards';
            }, 800);
        }

        setTimeout(endSplash, SPLASH_DURATION);

        // Allow click/tap to skip
        document.getElementById('splashScreen').addEventListener('click', function() {
            if (Date.now() - startTime > 1500) { // Only skip after 1.5s to see some animation
                endSplash();
            }
        });
    })();
    </script>
    <style>
    @keyframes loginReveal {
        from { opacity: 0; transform: translateY(30px) scale(0.95); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    </style>

    <!-- face-api.js CDN (only loaded if face login is available) -->
    <?php if ($faceLoginAvailable): ?>
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <?php endif; ?>

    <script>
        // Only allow numeric input in TOTP field
        var totpInput = document.querySelector('.totp-input');
        if (totpInput) {
            totpInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }

        // ========== Face Login Logic ==========
        <?php if ($faceLoginAvailable): ?>
        
        const FACE_MODEL_URL = 'https://cdn.jsdelivr.net/gh/justadudewhohacks/face-api.js@master/weights';
        let faceModelsLoaded = false;
        let faceStream = null;
        let faceDetectionLoop = null;
        let faceLoginAttempts = 0;
        const MAX_AUTO_ATTEMPTS = 20;
        
        // Switch to face login view
        function showFaceLogin() {
            document.getElementById('passwordLoginSection').style.display = 'none';
            document.getElementById('faceLoginSection').style.display = 'block';
            initFaceLogin();
        }
        
        // Switch back to password login
        function showPasswordLogin() {
            document.getElementById('faceLoginSection').style.display = 'none';
            document.getElementById('passwordLoginSection').style.display = 'block';
            stopFaceLogin();
        }
        
        // Initialize face login (load models + start camera)
        async function initFaceLogin() {
            const statusBar = document.getElementById('faceStatusBar');
            const statusText = document.getElementById('faceStatusText');
            const progressBar = document.getElementById('faceProgressBar');
            const progressWrap = document.getElementById('faceProgressWrap');
            
            faceLoginAttempts = 0;
            
            // Load models
            if (!faceModelsLoaded) {
                statusBar.className = 'face-status-bar loading';
                statusText.textContent = 'Loading face recognition models...';
                progressBar.style.width = '5%';
                
                try {
                    await faceapi.nets.tinyFaceDetector.loadFromUri(FACE_MODEL_URL);
                    progressBar.style.width = '35%';
                    
                    await faceapi.nets.faceLandmark68TinyNet.loadFromUri(FACE_MODEL_URL);
                    progressBar.style.width = '65%';
                    
                    await faceapi.nets.faceRecognitionNet.loadFromUri(FACE_MODEL_URL);
                    progressBar.style.width = '100%';
                    
                    faceModelsLoaded = true;
                    
                    setTimeout(() => { progressWrap.style.display = 'none'; }, 600);
                } catch (err) {
                    console.error('Model load error:', err);
                    statusBar.className = 'face-status-bar error';
                    statusText.textContent = 'Failed to load AI models. Check your internet connection.';
                    return;
                }
            } else {
                progressWrap.style.display = 'none';
            }
            
            // Start camera
            statusBar.className = 'face-status-bar loading';
            statusText.textContent = 'Starting camera...';
            
            try {
                faceStream = await navigator.mediaDevices.getUserMedia({
                    video: { width: { ideal: 480 }, height: { ideal: 360 }, facingMode: 'user' }
                });
                
                const video = document.getElementById('faceVideo');
                video.srcObject = faceStream;
                
                await new Promise(r => { video.onloadedmetadata = () => { video.play(); r(); }; });
                
                // Hide overlay
                document.getElementById('faceCameraOverlay').classList.add('hidden');
                document.getElementById('faceCameraWrapper').classList.add('scanning');
                
                statusBar.className = 'face-status-bar scanning';
                statusText.textContent = 'Looking for your face...';
                
                // Start detection & verification loop
                startFaceVerificationLoop();
                
            } catch (err) {
                console.error('Camera error:', err);
                statusBar.className = 'face-status-bar error';
                statusText.textContent = 'Camera access denied. Please allow camera access.';
                const overlay = document.getElementById('faceCameraOverlay');
                overlay.classList.remove('hidden');
                overlay.innerHTML = '<i class="fas fa-video-slash" style="font-size:36px;opacity:0.7"></i><p style="font-size:13px">Camera access denied</p>';
            }
        }
        
        // Continuous face detection + auto-verify loop (FAST — no drawing, direct descriptor)
        function startFaceVerificationLoop() {
            const video = document.getElementById('faceVideo');
            const statusBar = document.getElementById('faceStatusBar');
            const statusText = document.getElementById('faceStatusText');
            const wrapper = document.getElementById('faceCameraWrapper');
            
            let isVerifying = false;
            
            faceDetectionLoop = setInterval(async () => {
                if (isVerifying) return;
                
                try {
                    // Single-pass: detect + landmarks + descriptor in one call (fast)
                    isVerifying = true;
                    
                    // Try multiple sizes — smaller is faster but less accurate
                    let detection = null;
                    const sizes = [256, 224, 320];
                    for (const size of sizes) {
                        try {
                            detection = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({
                                inputSize: size, scoreThreshold: 0.4
                            })).withFaceLandmarks(true).withFaceDescriptor();
                            if (detection) break;
                        } catch (e) { /* try next size */ }
                    }
                    
                    if (detection) {
                        const descriptor = Array.from(detection.descriptor);
                        
                        statusBar.className = 'face-status-bar verifying';
                        statusText.innerHTML = '<div class="face-spinner"></div> Verifying...';
                        
                        // Send descriptor to server immediately
                        const result = await verifyFaceWithServer(descriptor);
                        
                        if (result.success) {
                            // SUCCESS — stop everything and redirect
                            clearInterval(faceDetectionLoop);
                            faceDetectionLoop = null;
                            
                            wrapper.classList.remove('scanning');
                            wrapper.classList.add('matched');
                            statusBar.className = 'face-status-bar success';
                            
                            const displayName = result.name || 'Admin';
                            statusText.innerHTML = '<span class="face-welcome-name">Welcome, ' + displayName + '!</span>';
                            
                            setTimeout(() => {
                                window.location.href = result.redirect || 'dashboard.php';
                            }, 900);
                            return;
                        } else {
                            faceLoginAttempts++;
                            if (faceLoginAttempts >= MAX_AUTO_ATTEMPTS) {
                                clearInterval(faceDetectionLoop);
                                faceDetectionLoop = null;
                                wrapper.classList.remove('scanning');
                                wrapper.classList.add('failed');
                                statusBar.className = 'face-status-bar error';
                                statusText.textContent = 'Face not recognized. Please use password login.';
                                return;
                            }
                            statusBar.className = 'face-status-bar scanning';
                            statusText.textContent = 'Verifying... (' + faceLoginAttempts + '/' + MAX_AUTO_ATTEMPTS + ')';
                        }
                    } else {
                        statusBar.className = 'face-status-bar scanning';
                        statusText.textContent = 'Position your face in the oval...';
                    }
                    isVerifying = false;
                } catch (err) {
                    isVerifying = false;
                    console.error('Detection error:', err);
                }
            }, 200);
        }
        
        // Send face descriptor to server for verification
        async function verifyFaceWithServer(descriptor) {
            try {
                const response = await fetch('face-login-api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ descriptor: descriptor })
                });
                return await response.json();
            } catch (err) {
                console.error('Server verification error:', err);
                return { success: false, error: 'Network error' };
            }
        }
        
        // Stop face login (cleanup)
        function stopFaceLogin() {
            if (faceDetectionLoop) {
                clearInterval(faceDetectionLoop);
                faceDetectionLoop = null;
            }
            if (faceStream) {
                faceStream.getTracks().forEach(t => t.stop());
                faceStream = null;
            }
            // Reset UI
            const wrapper = document.getElementById('faceCameraWrapper');
            const overlay = document.getElementById('faceCameraOverlay');
            const progressWrap = document.getElementById('faceProgressWrap');
            if (wrapper) wrapper.className = 'face-camera-wrapper';
            if (overlay) {
                overlay.classList.remove('hidden');
                overlay.innerHTML = '<i class="fas fa-video" style="font-size:36px;opacity:0.7"></i><p style="font-size:13px;opacity:0.8">Initializing camera...</p>';
            }
            if (progressWrap) progressWrap.style.display = 'block';
        }
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', stopFaceLogin);
        
        <?php endif; ?>
    </script>
</body>
</html>
