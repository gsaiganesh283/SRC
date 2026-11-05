<?php
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/logo-helper.php';
require_once '../includes/totp-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

$message = '';
$messageType = '';
$showSetup = false;
$tempSecret = '';

// Check current 2FA status
$is2FAEnabled = TOTPHelper::is2FAEnabled($pdo, $superadmin_id);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Action: Start 2FA Setup (generate secret and show QR)
    if (isset($_POST['action']) && $_POST['action'] === 'setup') {
        $tempSecret = TOTPHelper::generateSecret(16);
        $_SESSION['temp_totp_secret'] = $tempSecret;
        $showSetup = true;
    }
    
    // Action: Verify and Enable 2FA
    if (isset($_POST['action']) && $_POST['action'] === 'enable') {
        $code = trim($_POST['totp_code'] ?? '');
        $tempSecret = $_SESSION['temp_totp_secret'] ?? '';
        
        if (empty($code) || empty($tempSecret)) {
            $message = 'Please enter the verification code from your authenticator app.';
            $messageType = 'error';
            $showSetup = true;
        } elseif (strlen($code) !== 6 || !ctype_digit($code)) {
            $message = 'Please enter a valid 6-digit code.';
            $messageType = 'error';
            $showSetup = true;
        } elseif (TOTPHelper::verifyCode($tempSecret, $code)) {
            // Code verified - enable 2FA
            $enableResult = TOTPHelper::enable2FA($pdo, $superadmin_id, $tempSecret);
            if ($enableResult === true) {
                $is2FAEnabled = true;
                unset($_SESSION['temp_totp_secret']);
                $message = 'Two-Factor Authentication has been enabled successfully! Your account is now more secure.';
                $messageType = 'success';
                
                // Log the action
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO audit_logs (UserType, UserID, Username, Action, IPAddress) 
                        VALUES ('SuperAdmin', ?, ?, 'Enabled 2FA (Google Authenticator)', ?)
                    ");
                    $stmt->execute([$superadmin_id, $superadmin_username, $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
                } catch (Exception $e) {
                    // Audit log failure should not block 2FA enable
                    error_log('Audit log insert failed: ' . $e->getMessage());
                }
            } else {
                $errorDetail = is_string($enableResult) ? $enableResult : 'Unknown error';
                $message = 'Failed to enable 2FA: ' . htmlspecialchars($errorDetail);
                $messageType = 'error';
                $showSetup = true;
            }
        } else {
            $message = 'Invalid verification code. Please try again with a new code from your authenticator app.';
            $messageType = 'error';
            $showSetup = true;
        }
    }
    
    // Action: Disable 2FA
    if (isset($_POST['action']) && $_POST['action'] === 'disable') {
        $code = trim($_POST['totp_code'] ?? '');
        $currentSecret = TOTPHelper::getSecret($pdo, $superadmin_id);
        
        if (empty($code)) {
            $message = 'Please enter the verification code to disable 2FA.';
            $messageType = 'error';
        } elseif (!$currentSecret || !TOTPHelper::verifyCode($currentSecret, $code)) {
            $message = 'Invalid verification code. 2FA was not disabled.';
            $messageType = 'error';
        } else {
            if (TOTPHelper::disable2FA($pdo, $superadmin_id)) {
                $is2FAEnabled = false;
                $message = 'Two-Factor Authentication has been disabled.';
                $messageType = 'success';
                
                // Log the action
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (UserType, UserID, Username, Action, IPAddress) 
                    VALUES ('SuperAdmin', ?, ?, 'Disabled 2FA (Google Authenticator)', ?)
                ");
                $stmt->execute([$superadmin_id, $superadmin_username, $_SERVER['REMOTE_ADDR'] ?? 'Unknown']);
            } else {
                $message = 'Failed to disable 2FA. Please try again.';
                $messageType = 'error';
            }
        }
    }
}

// Generate QR code data if in setup mode
$qrCodeUrl = '';
$provisioningUri = '';
if ($showSetup && !empty($tempSecret)) {
    $provisioningUri = TOTPHelper::getProvisioningUri($tempSecret, $superadmin_username, $siteName);
    $qrCodeUrl = TOTPHelper::getQRCodeUrl($provisioningUri);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="shared-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .twofa-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary, #1a1a2e);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-title i {
            color: #7e22ce;
            font-size: 32px;
        }
        
        .page-subtitle {
            color: var(--text-secondary, #666);
            font-size: 15px;
            margin-top: 5px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--card-bg, white);
            color: var(--text-primary, #333);
            border: 2px solid var(--border-color, #e0e0e0);
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: #7e22ce;
            color: white;
            border-color: #7e22ce;
        }

        /* Alert messages */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 15px;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .alert i {
            font-size: 20px;
        }

        /* Status Card */
        .status-card {
            background: var(--card-bg, white);
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            text-align: center;
        }
        
        .status-icon {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 48px;
        }
        
        .status-icon.enabled {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #28a745;
        }
        
        .status-icon.disabled {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            color: #f59e0b;
        }
        
        .status-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--text-primary, #1a1a2e);
        }
        
        .status-description {
            color: var(--text-secondary, #666);
            font-size: 15px;
            max-width: 500px;
            margin: 0 auto 25px;
            line-height: 1.6;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 25px;
        }
        
        .status-badge.enabled {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge.disabled {
            background: #fff3cd;
            color: #856404;
        }

        /* Action buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 28px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #7e22ce, #1e3c72);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #dc3545, #c0392b);
            color: white;
        }
        
        .btn-secondary {
            background: var(--card-bg, white);
            color: var(--text-primary, #333);
            border: 2px solid var(--border-color, #e0e0e0);
        }

        /* Setup Card */
        .setup-card {
            background: var(--card-bg, white);
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .setup-steps {
            display: grid;
            gap: 30px;
        }
        
        .setup-step {
            display: flex;
            gap: 20px;
            align-items: flex-start;
        }
        
        .step-number {
            width: 45px;
            height: 45px;
            min-width: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7e22ce, #1e3c72);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            font-weight: 700;
        }
        
        .step-content h3 {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary, #1a1a2e);
            margin-bottom: 8px;
        }
        
        .step-content p {
            color: var(--text-secondary, #666);
            line-height: 1.6;
            font-size: 14px;
        }
        
        .qr-container {
            text-align: center;
            padding: 25px;
            background: #f8f9fa;
            border-radius: 12px;
            margin: 15px 0;
        }
        
        .qr-container img {
            max-width: 250px;
            border-radius: 8px;
            border: 4px solid white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .secret-key-display {
            display: inline-block;
            background: #1a1a2e;
            color: #22d3ee;
            padding: 12px 24px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 18px;
            letter-spacing: 3px;
            font-weight: 700;
            margin: 10px 0;
            word-break: break-all;
            user-select: all;
        }
        
        .copy-hint {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }
        
        .verify-form {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .totp-input {
            padding: 14px 20px;
            font-size: 24px;
            font-family: 'Courier New', monospace;
            letter-spacing: 8px;
            text-align: center;
            width: 220px;
            border: 2px solid var(--border-color, #e0e0e0);
            border-radius: 10px;
            background: var(--card-bg, white);
            color: var(--text-primary, #333);
            transition: border-color 0.3s ease;
        }
        
        .totp-input:focus {
            outline: none;
            border-color: #7e22ce;
            box-shadow: 0 0 0 4px rgba(126, 34, 206, 0.1);
        }
        
        .totp-input::placeholder {
            letter-spacing: 2px;
            font-size: 16px;
        }

        /* Disable section */
        .disable-card {
            background: var(--card-bg, white);
            border-radius: 16px;
            padding: 30px 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-left: 4px solid #dc3545;
        }
        
        .disable-card h3 {
            color: #dc3545;
            font-size: 18px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .disable-card p {
            color: var(--text-secondary, #666);
            margin-bottom: 20px;
            font-size: 14px;
        }

        /* Info section */
        .info-section {
            background: var(--card-bg, white);
            border-radius: 16px;
            padding: 30px 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .info-section h3 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-primary, #1a1a2e);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            display: flex;
            gap: 15px;
            align-items: flex-start;
        }
        
        .info-item i {
            font-size: 24px;
            color: #7e22ce;
            margin-top: 4px;
        }
        
        .info-item h4 {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary, #1a1a2e);
            margin-bottom: 5px;
        }
        
        .info-item p {
            font-size: 13px;
            color: var(--text-secondary, #666);
            line-height: 1.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .twofa-container {
                padding: 15px;
            }
            .setup-card, .status-card, .info-section, .disable-card {
                padding: 25px 20px;
            }
            .page-title {
                font-size: 22px;
            }
            .verify-form {
                flex-direction: column;
                align-items: stretch;
            }
            .totp-input {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="twofa-container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <div class="page-title">
                    <i class="fas fa-shield-alt"></i>
                    Two-Factor Authentication
                </div>
                <p class="page-subtitle">Secure your account with Google Authenticator</p>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <a href="face-auth.php" class="back-btn">
                    <i class="fas fa-face-smile"></i> Face Lock
                </a>
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Alert Message -->
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
        <?php endif; ?>

        <?php if ($showSetup && !$is2FAEnabled): ?>
        <!-- ======== SETUP MODE ======== -->
        <div class="setup-card">
            <div class="setup-steps">
                <!-- Step 1: Install App -->
                <div class="setup-step">
                    <div class="step-number">1</div>
                    <div class="step-content">
                        <h3>Install Google Authenticator</h3>
                        <p>Download and install <strong>Google Authenticator</strong> from the 
                            <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank">Google Play Store</a> or 
                            <a href="https://apps.apple.com/app/google-authenticator/id388497605" target="_blank">Apple App Store</a>. 
                            You can also use <strong>Microsoft Authenticator</strong>, <strong>Authy</strong>, or any TOTP-compatible app.
                        </p>
                    </div>
                </div>
                
                <!-- Step 2: Scan QR Code -->
                <div class="setup-step">
                    <div class="step-number">2</div>
                    <div class="step-content">
                        <h3>Scan the QR Code</h3>
                        <p>Open your authenticator app and scan the QR code below. If you can't scan it, manually enter the secret key shown below the QR code.</p>
                        
                        <div class="qr-container">
                            <img src="<?php echo htmlspecialchars($qrCodeUrl); ?>" alt="QR Code for Google Authenticator" id="qrCodeImg">
                            <br><br>
                            <div>
                                <strong style="font-size:13px; color:#666;">Can't scan? Enter this key manually:</strong><br>
                                <div class="secret-key-display" id="secretKey"><?php echo htmlspecialchars($tempSecret); ?></div>
                                <div class="copy-hint">Click the key to select it, then copy</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Step 3: Verify -->
                <div class="setup-step">
                    <div class="step-number">3</div>
                    <div class="step-content">
                        <h3>Verify & Activate</h3>
                        <p>Enter the 6-digit code shown in your authenticator app to verify the setup and activate 2FA.</p>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="enable">
                            <div class="verify-form">
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
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check-circle"></i> Verify & Enable 2FA
                                </button>
                                <a href="two-factor-auth.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <?php elseif ($is2FAEnabled): ?>
        <!-- ======== 2FA ENABLED STATUS ======== -->
        <div class="status-card">
            <div class="status-icon enabled">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div class="status-badge enabled">
                <i class="fas fa-check-circle"></i> Two-Factor Authentication is ENABLED
            </div>
            <h2 class="status-title">Your Account is Protected</h2>
            <p class="status-description">
                Two-Factor Authentication is active on your account. You will be asked for a verification code from your authenticator app every time you log in.
            </p>
        </div>
        
        <!-- Disable 2FA Section -->
        <div class="disable-card">
            <h3><i class="fas fa-exclamation-triangle"></i> Disable Two-Factor Authentication</h3>
            <p>Disabling 2FA will make your account less secure. You will only need your username and password to log in. Enter your current authenticator code to confirm.</p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="disable">
                <div class="verify-form">
                    <input type="text" 
                           name="totp_code" 
                           class="totp-input" 
                           placeholder="000000" 
                           maxlength="6" 
                           pattern="[0-9]{6}" 
                           inputmode="numeric"
                           autocomplete="one-time-code"
                           required>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to disable Two-Factor Authentication?');">
                        <i class="fas fa-lock-open"></i> Disable 2FA
                    </button>
                </div>
            </form>
        </div>
        
        <?php else: ?>
        <!-- ======== 2FA NOT ENABLED ======== -->
        <div class="status-card">
            <div class="status-icon disabled">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="status-badge disabled">
                <i class="fas fa-times-circle"></i> Two-Factor Authentication is DISABLED
            </div>
            <h2 class="status-title">Enhance Your Security</h2>
            <p class="status-description">
                Two-Factor Authentication (2FA) adds an extra layer of security to your account. When enabled, you'll need to enter a code from your authenticator app in addition to your password when logging in.
            </p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="setup">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-shield-alt"></i> Setup Two-Factor Authentication
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Info Section -->
        <div class="info-section">
            <h3><i class="fas fa-info-circle"></i> About Two-Factor Authentication</h3>
            <div class="info-grid">
                <div class="info-item">
                    <i class="fas fa-lock"></i>
                    <div>
                        <h4>Enhanced Security</h4>
                        <p>Even if your password is compromised, attackers won't be able to access your account without the authenticator code.</p>
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-mobile-alt"></i>
                    <div>
                        <h4>Authenticator Apps</h4>
                        <p>Works with Google Authenticator, Microsoft Authenticator, Authy, and any TOTP-compatible app.</p>
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-clock"></i>
                    <div>
                        <h4>Time-Based Codes</h4>
                        <p>Codes refresh every 30 seconds, ensuring each code can only be used once within its time window.</p>
                    </div>
                </div>
                <div class="info-item">
                    <i class="fas fa-key"></i>
                    <div>
                        <h4>Backup Your Secret</h4>
                        <p>Save your secret key in a secure location during setup. You'll need it if you lose access to your phone.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto-focus and auto-submit for TOTP input
        document.querySelectorAll('.totp-input').forEach(input => {
            // Only allow numeric input
            input.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        });
    </script>
<?php include '../includes/gemini-widget.php'; ?>
</body>
</html>
