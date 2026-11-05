<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../includes/link-target-helper.php';

requireMemberLogin();

$memberId = $_SESSION['member_id'];

// Fetch member details
try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE MemberID = ?");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        die("Member not found");
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Fetch transaction statistics
try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_transactions,
            COALESCE(SUM(Amount), 0) as total_paid,
            MAX(TransactionDate) as last_payment
        FROM transactions 
        WHERE MemberID = ?
    ");
    $stmt->execute([$memberId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $stats = ['total_transactions' => 0, 'total_paid' => 0, 'last_payment' => null];
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
            object-fit: cover;
            background: white;
        }

        .profile-photo i {
            font-size: 60px;
            color: var(--primary-color);
            line-height: 112px;
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
        <a href="dashboard-new.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="profile-header">
            <div class="profile-header-content">
                <div class="profile-photo">
                    <?php if (!empty($member['Photo']) && file_exists(BASE_PATH . '/' . $member['Photo'])): ?>
                        <img src="<?php echo SITE_URL . '/' . $member['Photo']; ?>" alt="Profile Photo">
                    <?php else: ?>
                        <i class="fas fa-user-circle"></i>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <h1><?php echo htmlspecialchars($member['MemberName']); ?></h1>
                    <p><i class="fas fa-id-card"></i> Member ID: #<?php echo str_pad($member['MemberID'], 4, '0', STR_PAD_LEFT); ?></p>
                    <p><i class="fas fa-calendar-alt"></i> Member Since: <?php echo date('F Y', strtotime($member['CreatedAt'] ?? 'now')); ?></p>
                    <p>
                        <i class="fas fa-circle" style="font-size: 8px; color: <?php echo $member['Status'] === 'Active' ? '#48bb78' : '#f56565'; ?>;"></i>
                        Status: <span class="badge <?php echo $member['Status'] === 'Active' ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo $member['Status']; ?>
                        </span>
                    </p>
                    <div class="profile-actions">
                        <a href="update-profile.php" class="btn btn-primary">
                            <i class="fas fa-edit"></i> Edit Profile
                        </a>
                        <a href="dashboard-new.php" class="btn btn-outline">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <h3><?php echo $stats['total_transactions']; ?></h3>
                <p>Total Transactions</p>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);">
                <h3>₹<?php echo number_format($stats['total_paid'], 2); ?></h3>
                <p>Total Amount Paid</p>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);">
                <h3><?php echo $stats['last_payment'] ? date('M d, Y', strtotime($stats['last_payment'])) : 'N/A'; ?></h3>
                <p>Last Payment</p>
            </div>
        </div>

        <div class="profile-grid">
            <div class="card">
                <h2><i class="fas fa-user"></i> Personal Information</h2>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-user"></i> Full Name</span>
                    <span class="detail-value"><?php echo htmlspecialchars($member['MemberName']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-male"></i> Father's Name</span>
                    <span class="detail-value"><?php echo htmlspecialchars($member['FatherName'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-id-badge"></i> Aadhar Number</span>
                    <span class="detail-value"><?php echo isset($member['Aadhar']) && $member['Aadhar'] ? '****-****-' . substr($member['Aadhar'], -4) : 'N/A'; ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-birthday-cake"></i> Date of Birth</span>
                    <span class="detail-value"><?php echo isset($member['DOB']) && $member['DOB'] ? date('M d, Y', strtotime($member['DOB'])) : 'N/A'; ?></span>
                </div>
            </div>

            <div class="card">
                <h2><i class="fas fa-address-book"></i> Contact Information</h2>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-phone"></i> Phone Number</span>
                    <span class="detail-value"><?php echo htmlspecialchars($member['Phone']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-envelope"></i> Email Address</span>
                    <span class="detail-value"><?php echo htmlspecialchars($member['Email'] ?? 'N/A'); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-home"></i> Address</span>
                    <span class="detail-value"><?php echo htmlspecialchars($member['Address'] ?? 'N/A'); ?></span>
                </div>
            </div>

            <div class="card">
                <h2><i class="fas fa-info-circle"></i> Account Details</h2>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-hashtag"></i> Member ID</span>
                    <span class="detail-value">#<?php echo str_pad($member['MemberID'], 4, '0', STR_PAD_LEFT); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-toggle-on"></i> Account Status</span>
                    <span class="detail-value">
                        <span class="badge <?php echo $member['Status'] === 'Active' ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo $member['Status']; ?>
                        </span>
                    </span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-calendar-plus"></i> Joined On</span>
                    <span class="detail-value"><?php echo date('M d, Y', strtotime($member['CreatedAt'] ?? 'now')); ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label"><i class="fas fa-clock"></i> Last Updated</span>
                    <span class="detail-value"><?php echo $member['UpdatedAt'] ? date('M d, Y H:i', strtotime($member['UpdatedAt'])) : 'Never'; ?></span>
                </div>
            </div>

            <?php if (!empty($member['Notes'])): ?>
            <div class="card">
                <h2><i class="fas fa-sticky-note"></i> Additional Notes</h2>
                <div style="padding: 15px 0; color: var(--text-secondary); line-height: 1.6;">
                    <?php echo nl2br(htmlspecialchars($member['Notes'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="../assets/js/theme-manager.js"></script>
    <?php outputLinkTargetScript('member'); ?>
    <?php include '../includes/modal-component.php'; ?>
</body>
</html>
