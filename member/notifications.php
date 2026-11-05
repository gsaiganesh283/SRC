<?php
// Member Notifications Page
require_once '../includes/session.php';
require_once '../config/config.php';

// Check if user is logged in as member
if (!isset($_SESSION['member_id'])) {
    header('Location: ../auth/member-login.php');
    exit;
}

$memberId = $_SESSION['member_id'];

// Fetch member details
$stmt = $pdo->prepare("SELECT * FROM members WHERE MemberID = ?");
$stmt->execute([$memberId]);
$member = $stmt->fetch();

// Check if system_notifications table exists
$notifications = [];
$unreadCount = 0;

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'system_notifications'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        // Fetch notifications
        $stmt = $pdo->prepare("
            SELECT * FROM system_notifications 
            WHERE (TargetType = 'Member' AND TargetID = ?) 
               OR (TargetType = 'All')
            ORDER BY CreatedAt DESC
            LIMIT 50
        ");
        $stmt->execute([$memberId]);
        $notifications = $stmt->fetchAll();

        // Count unread notifications
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM system_notifications 
            WHERE (TargetType = 'Member' AND TargetID = ?) 
               OR (TargetType = 'All')
            AND IsRead = 0
        ");
        $stmt->execute([$memberId]);
        $unreadCount = $stmt->fetchColumn();
    }
} catch (PDOException $e) {
    // Table doesn't exist yet - use empty arrays
    $notifications = [];
    $unreadCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Member Portal</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .notification-item {
            padding: 1rem;
            border-bottom: 1px solid #e5e7eb;
            background: #fff;
            transition: background 0.2s;
        }
        .notification-item:hover {
            background: #f9fafb;
        }
        .notification-item.unread {
            background: #eff6ff;
            border-left: 3px solid #3b82f6;
        }
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.5rem;
        }
        .notification-title {
            font-weight: 600;
            color: #1f2937;
        }
        .notification-time {
            font-size: 0.875rem;
            color: #6b7280;
        }
        .notification-message {
            color: #4b5563;
            margin-bottom: 0.5rem;
        }
        .notification-type {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .type-info { background: #dbeafe; color: #1e40af; }
        .type-success { background: #d1fae5; color: #065f46; }
        .type-warning { background: #fef3c7; color: #92400e; }
        .type-danger { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-section" style="margin-bottom: 2rem;">
            <h1><i class="fas fa-bell"></i> Notifications</h1>
            <p><?php echo $unreadCount; ?> unread notification<?php echo $unreadCount != 1 ? 's' : ''; ?></p>
            <a href="dashboard-new.php" class="btn btn-secondary">← Back to Dashboard</a>
        </div>

        <div class="notifications-list">
            <?php if (!isset($tableExists) || !$tableExists): ?>
                <div class="alert alert-warning" style="background: #fff3cd; color: #856404; padding: 1.5rem; border-radius: 8px; border-left: 4px solid #ffc107;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Notification System Not Ready</strong><br>
                    The notification system tables haven't been created yet. Please ask your administrator to run the database setup script: <code>ENHANCED_FEATURES_SCHEMA.sql</code>
                </div>
            <?php elseif (empty($notifications)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    No notifications yet.
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item <?php echo $notif['IsRead'] ? '' : 'unread'; ?>">
                        <div class="notification-header">
                            <div class="notification-title">
                                <?php if ($notif['Icon']): ?>
                                    <i class="fas fa-<?php echo htmlspecialchars($notif['Icon']); ?>"></i>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($notif['Title']); ?>
                            </div>
                            <div class="notification-time">
                                <?php echo date('M j, Y g:i A', strtotime($notif['CreatedAt'])); ?>
                            </div>
                        </div>
                        <div class="notification-message">
                            <?php echo nl2br(htmlspecialchars($notif['Message'])); ?>
                        </div>
                        <span class="notification-type type-<?php echo strtolower($notif['Type']); ?>">
                            <?php echo htmlspecialchars($notif['Type']); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
