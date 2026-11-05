<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../includes/link-target-helper.php';

requireAdmin();

$adminId = $_SESSION['admin_id'];
$success = '';
$error = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $error = 'All password fields are required';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters long';
    } else {
        try {
            // Verify current password
            $stmt = $pdo->prepare("SELECT Password FROM admins WHERE AdminID = ?");
            $stmt->execute([$adminId]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($currentPassword, $admin['Password'])) {
                // Update password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE admins SET Password = ? WHERE AdminID = ?");
                $stmt->execute([$hashedPassword, $adminId]);
                
                // Create audit log
                createAuditLog(
                    $adminId,
                    'Admin',
                    'Admins',
                    'Password Change',
                    "Admin changed their password",
                    'admins',
                    $adminId,
                    null,
                    null,
                    'Medium'
                );
                
                $success = 'Password changed successfully!';
            } else {
                $error = 'Current password is incorrect';
            }
        } catch (Exception $e) {
            $error = 'Error changing password: ' . $e->getMessage();
        }
    }
}

// Fetch admin details
try {
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE AdminID = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$page_title = "Account Settings";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - SR Colony Chitti</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --bg-primary: #f8f9fa;
            --bg-secondary: #ffffff;
            --bg-tertiary: #f1f3f5;
            --text-primary: #2d3748;
            --text-secondary: #718096;
            --border-color: #e2e8f0;
            --success-color: #48bb78;
            --danger-color: #f56565;
        }

        [data-theme="dark"] {
            --bg-primary: #1a202c;
            --bg-secondary: #2d3748;
            --bg-tertiary: #4a5568;
            --text-primary: #f7fafc;
            --text-secondary: #e2e8f0;
            --border-color: #4a5568;
        }

        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }

        .settings-container {
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
        }

        .settings-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 40px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .settings-header h1 {
            margin: 0;
            font-size: 32px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .settings-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
        }

        .card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .card h2 {
            margin: 0 0 20px 0;
            font-size: 20px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }

        .card h2 i {
            color: var(--primary-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 15px;
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: all 0.3s;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .btn {
            padding: 12px 30px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 15px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: white;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }

        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border: 1px solid #fc8181;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-color);
            text-decoration: none;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .setting-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .setting-item:last-child {
            border-bottom: none;
        }

        .setting-info h3 {
            margin: 0 0 5px 0;
            font-size: 16px;
            color: var(--text-primary);
        }

        .setting-info p {
            margin: 0;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .theme-toggle {
            display: flex;
            gap: 10px;
        }

        .theme-btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: 2px solid var(--border-color);
            background: var(--bg-tertiary);
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.3s;
        }

        .theme-btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .password-requirements {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 5px;
            padding-left: 20px;
        }

        .password-requirements li {
            margin: 3px 0;
        }
    </style>
</head>
<body>
    <div class="settings-container">
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="settings-header">
            <h1><i class="fas fa-cog"></i> Account Settings</h1>
            <p>Manage your account preferences and security</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Profile Settings -->
        <div class="card">
            <h2><i class="fas fa-user"></i> Profile Settings</h2>
            <div class="setting-item">
                <div class="setting-info">
                    <h3>View Full Profile</h3>
                    <p>See all your account and activity details</p>
                </div>
                <a href="view-profile.php" class="btn btn-outline">
                    <i class="fas fa-eye"></i> View Profile
                </a>
            </div>
        </div>

        <!-- Theme Settings -->
        <div class="card">
            <h2><i class="fas fa-palette"></i> Appearance</h2>
            <div class="setting-item">
                <div class="setting-info">
                    <h3>Theme Preference</h3>
                    <p>Choose your preferred color theme</p>
                </div>
                <div class="theme-toggle">
                    <button class="theme-btn" id="lightThemeBtn" onclick="setTheme('light')">
                        <i class="fas fa-sun"></i> Light
                    </button>
                    <button class="theme-btn" id="darkThemeBtn" onclick="setTheme('dark')">
                        <i class="fas fa-moon"></i> Dark
                    </button>
                </div>
            </div>
        </div>

        <!-- Security Settings -->
        <div class="card">
            <h2><i class="fas fa-shield-alt"></i> Security & Password</h2>
            <form method="POST" action="">
                <div class="form-group">
                    <label for="current_password">
                        <i class="fas fa-lock"></i> Current Password
                    </label>
                    <input 
                        type="password" 
                        id="current_password" 
                        name="current_password" 
                        class="form-control"
                        required
                        placeholder="Enter current password"
                    >
                </div>

                <div class="form-group">
                    <label for="new_password">
                        <i class="fas fa-key"></i> New Password
                    </label>
                    <input 
                        type="password" 
                        id="new_password" 
                        name="new_password" 
                        class="form-control"
                        required
                        placeholder="Enter new password"
                        minlength="6"
                    >
                    <ul class="password-requirements">
                        <li>At least 6 characters long</li>
                        <li>Mix of letters and numbers recommended</li>
                    </ul>
                </div>

                <div class="form-group">
                    <label for="confirm_password">
                        <i class="fas fa-check"></i> Confirm New Password
                    </label>
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password" 
                        class="form-control"
                        required
                        placeholder="Confirm new password"
                        minlength="6"
                    >
                </div>

                <button type="submit" name="change_password" class="btn btn-primary">
                    <i class="fas fa-save"></i> Change Password
                </button>
            </form>
        </div>

        <!-- Account Info -->
        <div class="card">
            <h2><i class="fas fa-info-circle"></i> Account Information</h2>
            <div class="setting-item">
                <div class="setting-info">
                    <h3>Admin ID</h3>
                    <p>Your unique administrator identification</p>
                </div>
                <strong>#<?php echo str_pad($admin['AdminID'], 4, '0', STR_PAD_LEFT); ?></strong>
            </div>
            <div class="setting-item">
                <div class="setting-info">
                    <h3>Username</h3>
                    <p>Your login username</p>
                </div>
                <strong><?php echo htmlspecialchars($admin['Username']); ?></strong>
            </div>
            <div class="setting-item">
                <div class="setting-info">
                    <h3>Account Status</h3>
                    <p>Current status of your account</p>
                </div>
                <span style="color: <?php echo $admin['Status'] === 'Active' ? 'var(--success-color)' : 'var(--danger-color)'; ?>; font-weight: 600;">
                    <?php echo $admin['Status']; ?>
                </span>
            </div>
            <div class="setting-item">
                <div class="setting-info">
                    <h3>Role</h3>
                    <p>Your administrative role</p>
                </div>
                <strong><?php echo htmlspecialchars($admin['Role'] ?? 'Admin'); ?></strong>
            </div>
        </div>
    </div>

    <script>
        // Theme management
        function setTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('app_theme', theme);
            updateThemeButtons();
        }

        function updateThemeButtons() {
            const currentTheme = localStorage.getItem('app_theme') || 'light';
            document.getElementById('lightThemeBtn').classList.toggle('active', currentTheme === 'light');
            document.getElementById('darkThemeBtn').classList.toggle('active', currentTheme === 'dark');
        }

        // Initialize theme
        const savedTheme = localStorage.getItem('app_theme') || 'light';
        document.documentElement.setAttribute('data-theme', savedTheme);
        updateThemeButtons();
    </script>

    <script src="../assets/js/theme-manager.js"></script>
    <?php outputLinkTargetScript('admin'); ?>
    <?php include '../includes/modal-component.php'; ?>
</body>
</html>
