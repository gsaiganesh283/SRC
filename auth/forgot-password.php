<?php
require_once '../config/config.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - SR Colony Chitti</title>
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/styles.css">
</head>
<body>
    <div class="particles"></div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>🔑 Forgot Password?</h1>
                <p>Don't worry, we can help!</p>
            </div>
            
            <div class="info-box warning">
                <p><strong>⚠️ Password Reset Process:</strong></p>
                <p>For security reasons, please contact the admin to reset your password. The admin will verify your identity and help you regain access to your account.</p>
            </div>
            
            <div class="contact-info">
                <h3>📞 Contact Admin</h3>
                <div class="contact-item">
                    <span>📱</span>
                    <div>
                        <strong>Phone:</strong><br>
                        +91 XXXXX XXXXX (Admin)
                    </div>
                </div>
                <div class="contact-item">
                    <span>📧</span>
                    <div>
                        <strong>Email:</strong><br>
                        admin@srcolony.com
                    </div>
                </div>
                <div class="contact-item">
                    <span>🏠</span>
                    <div>
                        <strong>Location:</strong><br>
                        SR Colony, Yerraguntlepalli, Pileru
                    </div>
                </div>
            </div>
            
            <div class="info-box" style="background: #f0fff4; border-color: #2ed573;">
                <p><strong>💡 What to Provide:</strong></p>
                <p>• Your full name</p>
                <p>• Your registered mobile number</p>
                <p>• Member ID (if available)</p>
                <p>• Any additional verification details</p>
            </div>
            
            <a href="member-login.php" class="btn-back">
                ← Back to Login
            </a>
        </div>
    </div>

    <script src="<?php echo ASSETS_URL; ?>/js/script.js"></script>
</body>
</html>