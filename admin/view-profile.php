<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../includes/link-target-helper.php';

requireAdmin();

$adminId = $_SESSION['admin_id'];

// Fetch admin details
try {
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE AdminID = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        die("Admin not found");
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Fetch admin statistics
try {
    // Get member count
    $stmt = $pdo->query("SELECT COUNT(*) as total_members FROM members");
    $memberStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get transaction count
    $stmt = $pdo->query("SELECT COUNT(*) as total_transactions FROM transactions");
    $transactionStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get last login
    $lastLogin = $admin['LastLogin'] ?? null;
} catch (Exception $e) {
    $memberStats = ['total_members' => 0];
    $transactionStats = ['total_transactions' => 0];
    $lastLogin = null;
}

$page_title = "My Profile";
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
            --warning-color: #ed8936;
            --danger-color: #f56565;
            --info-color: #4299e1;
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

        .profile-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
        }

        .profile-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 40px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .profile-header-content {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            color: var(--primary-color);
            font-size: 60px;
        }

        .profile-info h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
        }

        .profile-info p {
            margin: 5px 0;
            opacity: 0.9;
            font-size: 16px;
        }

        .profile-actions {
            margin-top: 20px;
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: white;
            color: var(--primary-color);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 255, 255, 0.3);
        }

        .btn-outline {
            background: transparent;
            color: white;
            border: 2px solid white;
        }

        .btn-outline:hover {
            background: white;
            color: var(--primary-color);
        }

        .profile-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
        }

        .card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        }

        .card h2 {
            margin: 0 0 20px 0;
            font-size: 20px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card h2 i {
            color: var(--primary-color);
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-value {
            color: var(--text-primary);
            font-weight: 600;
            text-align: right;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
        }

        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 36px;
            font-weight: 700;
        }

        .stat-card p {
            margin: 0;
            opacity: 0.9;
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: #c6f6d5;
            color: #22543d;
        }

        .badge-warning {
            background: #fed7d7;
            color: #742a2a;
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
    </style>
</head>
<body>
    <div class="profile-container">
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="profile-header">
            <div class="profile-header-content">
                <div class="profile-photo">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div class="profile-info">
                    <h1><?php echo htmlspecialchars($admin['Username']); ?></h1>
                    <p><i class="fas fa-id-card"></i> Admin ID: #<?php echo str_pad($admin['AdminID'], 4, '0', STR_PAD_LEFT); ?></p>
                    <p><i class="fas fa-user-tag"></i> Role: <?php echo htmlspecialchars($admin['Role'] ?? 'Admin'); ?></p>
                    <p>
                        <i class="fas fa-circle" style="font-size: 8px; color: <?php echo $admin['Status'] === 'Active' ? '#48bb78' : '#f56565'; ?>;"></i>
                        Status: <span class="badge <?php echo $admin['Status'] === 'Active' ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo $admin['Status']; ?>
                        </span>
                    </p>
                    <div class="profile-actions">
                        <a href="settings.php" class="btn btn-primary">
                            <i class="fas fa-cog"></i> Account Settings
                        </a>
                        <a href="dashboard.php" class="btn btn-outline">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3><?php echo $memberStats['total_members']; ?></h3>
                <p>Total Members</p>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);">
                <h3><?php echo $transactionStats['total_transactions']; ?></h3>
                <p>Total Transactions</p>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);">
                <h3><?php echo $lastLogin ? date('M d, Y', strtotime($lastLogin)) : 'N/A'; ?></h3>
                <p>Last Login</p>
            </div>
        </div>

        <div class="profile-grid">
            <div class="card">
                <h2><i class="fas fa-user"></i> Account Information</h2>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-user"></i> Username</span>
                    <span class="detail-value"><?php echo htmlspecialchars($admin['Username']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-envelope"></i> Email</span>
                    <span class="detail-value"><?php echo htmlspecialchars($admin['Email'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-user-tag"></i> Role</span>
                    <span class="detail-value"><?php echo htmlspecialchars($admin['Role'] ?? 'Admin'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-hashtag"></i> Admin ID</span>
                    <span class="detail-value">#<?php echo str_pad($admin['AdminID'], 4, '0', STR_PAD_LEFT); ?></span>
                </div>
            </div>

            <div class="card">
                <h2><i class="fas fa-info-circle"></i> Account Status</h2>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-toggle-on"></i> Status</span>
                    <span class="detail-value">
                        <span class="badge <?php echo $admin['Status'] === 'Active' ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo $admin['Status']; ?>
                        </span>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-calendar-plus"></i> Created On</span>
                    <span class="detail-value"><?php echo date('M d, Y', strtotime($admin['CreatedAt'] ?? 'now')); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-clock"></i> Last Login</span>
                    <span class="detail-value"><?php echo $lastLogin ? date('M d, Y H:i', strtotime($lastLogin)) : 'Never'; ?></span>
                </div>
            </div>

            <div class="card">
                <h2><i class="fas fa-chart-line"></i> Activity Summary</h2>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-users"></i> Members Managed</span>
                    <span class="detail-value"><?php echo $memberStats['total_members']; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-receipt"></i> Transactions Recorded</span>
                    <span class="detail-value"><?php echo $transactionStats['total_transactions']; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-shield-alt"></i> Account Type</span>
                    <span class="detail-value">Administrator</span>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/theme-manager.js"></script>
    <?php outputLinkTargetScript('admin'); ?>
    <?php include '../includes/modal-component.php'; ?>
</body>
</html>
