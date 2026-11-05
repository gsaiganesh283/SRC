<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Redirect to view profile page
header('Location: view-profile.php');
exit();
?>

$errors = [];
$success = '';

// Get admin data
try {
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE AdminID = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        header('Location: ../auth/admin-login.php');
        exit;
    }
} catch (Exception $e) {
    die('Error fetching admin data: ' . $e->getMessage());
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    // Validation
    if (empty($username)) {
        $errors[] = 'Username is required';
    }
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    // Check if username is taken by another admin
    if (!empty($username) && $username !== $admin['Username']) {
        $stmt = $pdo->prepare("SELECT AdminID FROM admins WHERE Username = ? AND AdminID != ?");
        $stmt->execute([$username, $_SESSION['admin_id']]);
        if ($stmt->fetch()) {
            $errors[] = 'Username already taken';
        }
    }
    
    if (empty($errors)) {
        try {
            // Get old data for audit log
            $oldUsername = $admin['Username'];
            $oldEmail = $admin['Email'];
            
            $stmt = $pdo->prepare("UPDATE admins SET Username = ?, Email = ? WHERE AdminID = ?");
            $stmt->execute([$username, $email, $_SESSION['admin_id']]);
            
            // Create audit log for profile update
            createAuditLog(
                $_SESSION['admin_id'],
                'Admin',
                'Settings',
                'Update Own Profile',
                "Admin updated their profile",
                'admins',
                $_SESSION['admin_id'],
                json_encode(['Username' => $oldUsername, 'Email' => $oldEmail]),
                json_encode(['Username' => $username, 'Email' => $email]),
                'Low'
            );
            
            $success = 'Profile updated successfully!';
            
            // Update session
            $_SESSION['admin_username'] = $username;
            $_SESSION['admin_name'] = $username;
            
            // Refresh admin data
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE AdminID = ?");
            $stmt->execute([$_SESSION['admin_id']]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $errors[] = 'Error updating profile: ' . $e->getMessage();
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($currentPassword)) {
        $errors[] = 'Current password is required';
    }
    if (empty($newPassword)) {
        $errors[] = 'New password is required';
    } elseif (strlen($newPassword) < 6) {
        $errors[] = 'New password must be at least 6 characters';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }
    
    if (empty($errors)) {
        // Verify current password
        if (password_verify($currentPassword, $admin['Password'])) {
            try {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE admins SET Password = ? WHERE AdminID = ?");
                $stmt->execute([$hashedPassword, $_SESSION['admin_id']]);
                
                // Create audit log for password change
                createAuditLog(
                    $_SESSION['admin_id'],
                    'Admin',
                    'Authentication',
                    'Change Password',
                    "Admin changed their password",
                    'admins',
                    $_SESSION['admin_id'],
                    null,
                    null,
                    'Medium'
                );
                
                $success = 'Password changed successfully!';
            } catch (Exception $e) {
                $errors[] = 'Error changing password: ' . $e->getMessage();
            }
        } else {
            $errors[] = 'Current password is incorrect';
        }
    }
}

// Get display name (use Username)
$displayName = $admin['Username'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - SR Colony</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/admin-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="container" style="margin-left: 280px; padding: 30px;">
        <div class="header">
            <h1><i class="fas fa-user-circle"></i> Admin Profile</h1>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <div>
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Profile Information Card -->
        <div class="form-container">
            <div class="member-info-box">
                <div class="member-avatar" style="font-size: 40px;">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="member-details">
                    <h2><?php echo htmlspecialchars($displayName); ?></h2>
                    <p>Administrator</p>
                    <p class="last-login-text">
                        <i class="fas fa-clock"></i> Last Login: <?php echo isset($admin['LastLogin']) ? date('M d, Y h:i A', strtotime($admin['LastLogin'])) : 'N/A'; ?>
                    </p>
                </div>
            </div>

            <!-- Profile Update Form -->
            <form method="POST" action="">
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-user-edit"></i> Account Information
                    </h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">
                                Username <span class="required">*</span>
                            </label>
                            <input type="text" name="username" class="form-input" value="<?php echo htmlspecialchars($admin['Username']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                Email Address <span class="required">*</span>
                            </label>
                            <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($admin['Email'] ?? ''); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" name="update_profile" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </div>
            </form>
        </div>

        <!-- Change Password Section -->
        <div class="form-container" style="margin-top: 25px;">
            <form method="POST" action="">
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-key"></i> Change Password
                    </h3>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label class="form-label">
                                Current Password <span class="required">*</span>
                            </label>
                            <input type="password" name="current_password" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                New Password <span class="required">*</span>
                            </label>
                            <input type="password" name="new_password" class="form-input" minlength="6" required>
                            <small style="color: #666; font-size: 12px;">Minimum 6 characters</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                Confirm New Password <span class="required">*</span>
                            </label>
                            <input type="password" name="confirm_password" class="form-input" required>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" name="change_password" class="btn btn-primary">
                        <i class="fas fa-lock"></i> Change Password
                    </button>
                </div>
            </form>
        </div>

        <!-- Account Statistics -->
        <div class="stats-grid" style="margin-top: 25px;">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-user"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo htmlspecialchars($admin['Username']); ?></h3>
                    <p>Username</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon active">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="stat-info">
                    <h3>Administrator</h3>
                    <p>Account Role</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon inactive">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo isset($admin['LastLogin']) ? date('M d, Y', strtotime($admin['LastLogin'])) : 'N/A'; ?></h3>
                    <p>Last Login</p>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/admin-scripts.js"></script>
</body>
</html>