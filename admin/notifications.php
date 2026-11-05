<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Admin Notifications Management Page
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Check if user is logged in as admin
requireAdmin();

$adminId = $_SESSION['admin_id'];

// Check if system_notifications table exists
$notifications = [];
$tableExists = false;

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'system_notifications'");
    $tableExists = $stmt->rowCount() > 0;
    
    if ($tableExists) {
        // Fetch all notifications
        $stmt = $pdo->query("
            SELECT * FROM system_notifications 
            ORDER BY CreatedAt DESC
            LIMIT 100
        ");
        $notifications = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $notifications = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Notifications - Admin Portal</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/admin-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="container" style="margin-left: 280px; padding: 30px;">
        <div class="header-section" style="margin-bottom: 2rem;">
            <h1><i class="fas fa-bell"></i> Manage Notifications</h1>
            <a href="dashboard-new.php" class="btn btn-secondary">← Back to Dashboard</a>
            <a href="create-notification.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Create Notification
            </a>
        </div>

        <?php if (!$tableExists): ?>
            <div class="alert alert-warning" style="background: #fff3cd; color: #856404; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem;">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Database Setup Required</strong><br>
                The notification system tables haven't been created yet. Please run the database setup script:<br>
                <code>database/ENHANCED_FEATURES_SCHEMA.sql</code>
                <br><br>
                <a href="../database/ENHANCED_FEATURES_SCHEMA.sql" class="btn btn-primary" style="margin-top: 10px;">
                    <i class="fas fa-download"></i> Download SQL File
                </a>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Target</th>
                            <th>Type</th>
                            <th>Priority</th>
                            <th>Created</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$tableExists): ?>
                            <tr>
                                <td colspan="7" class="text-center" style="padding: 2rem; color: #6b7280;">
                                    <i class="fas fa-database" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i><br>
                                    Notification system not initialized
                                </td>
                            </tr>
                        <?php elseif (empty($notifications)): ?>
                            <tr>
                                <td colspan="7" class="text-center">No notifications found</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                                <tr>
                                    <td><?php echo $notif['NotificationID']; ?></td>
                                    <td><?php echo htmlspecialchars($notif['Title']); ?></td>
                                    <td><?php echo htmlspecialchars($notif['TargetType']); ?></td>
                                    <td><span class="badge badge-<?php echo strtolower($notif['Type']); ?>"><?php echo $notif['Type']; ?></span></td>
                                    <td><?php echo $notif['Priority']; ?></td>
                                    <td><?php echo date('M j, Y', strtotime($notif['CreatedAt'])); ?></td>
                                    <td>
                                        <?php if ($notif['ExpiresAt'] && strtotime($notif['ExpiresAt']) < time()): ?>
                                            <span class="badge badge-danger">Expired</span>
                                        <?php else: ?>
                                            <span class="badge badge-success">Active</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
