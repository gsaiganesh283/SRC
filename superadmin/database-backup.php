<?php
// Database Backup and Restore Module
// URL: superadmin/database-backup.php

require_once '../config/config.php';
require_once 'session.php';

// Require super admin authentication
if (!isset($_SESSION['superadmin_id'])) {
    header('Location: login.php');
    exit();
}

$superAdminName = $_SESSION['superadmin_name'] ?? 'Super Admin';
$message = '';
$messageType = '';

require_once '../includes/backup-helper.php';
srcBackupEnsureTable($pdo);

// Handle backup creation
if (isset($_POST['create_backup'])) {
    try {
        $made = srcCreateBackup($pdo, $_SESSION['superadmin_id'], 'manual');
        $message = "Backup created successfully: {$made['name']} (" . formatBytes($made['size']) . ")";
        $messageType = 'success';
        // Keep a private off-site copy too, when Google Drive is set up for backups
        if (srcBackupConfig($pdo)['gdrive']) {
            try {
                if (srcCopyBackupToDrive($pdo, $made)) $message .= ' and copied to Google Drive';
            } catch (Exception $e) {
                $message .= ' (Google Drive copy failed: ' . $e->getMessage() . ')';
            }
        }
    } catch (Exception $e) {
        $message = "Error creating backup: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Save the automatic daily backup settings
if (isset($_POST['save_auto_backup'])) {
    $time = trim($_POST['backup_time'] ?? '02:00');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $message = 'Please choose a valid backup time (HH:MM).';
        $messageType = 'error';
    } else {
        $by = $_SESSION['superadmin_id'];
        srcBackupSaveSetting($pdo, 'auto_backup_enabled', isset($_POST['auto_backup_enabled']) ? '1' : '0', $by);
        srcBackupSaveSetting($pdo, 'backup_time', $time, $by);
        srcBackupSaveSetting($pdo, 'backup_retention_days', (string)max(1, min(365, (int)($_POST['backup_retention_days'] ?? 30))), $by);
        srcBackupSaveSetting($pdo, 'backup_to_gdrive', isset($_POST['backup_to_gdrive']) ? '1' : '0', $by);
        srcResetBackupSchedule();
        $message = isset($_POST['auto_backup_enabled'])
            ? "Automatic daily backup is on: every day at {$time}, kept for " . (int)$_POST['backup_retention_days'] . ' days.'
            : 'Automatic daily backup is switched off.';
        $messageType = 'success';
    }
}

// Run the daily backup job now (backup + Google Drive copy + clean-up)
if (isset($_POST['run_auto_backup'])) {
    $r = srcRunAutoBackup($pdo, true);
    $message = $r['message'];
    $messageType = $r['ran'] ? (stripos($r['message'], 'failed') !== false ? 'warning' : 'success') : 'error';
}

// Copy one backup to Google Drive
if (isset($_POST['copy_to_drive'])) {
    try {
        $stmt = $pdo->prepare('SELECT * FROM database_backups WHERE BackupID = ?');
        $stmt->execute([(int)$_POST['backup_id']]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b || !is_file($b['BackupPath'])) throw new Exception('Backup file not found.');
        $link = srcCopyBackupToDrive($pdo, ['id' => $b['BackupID'], 'name' => $b['BackupName'], 'path' => $b['BackupPath']]);
        if (!$link) throw new Exception('Google Drive is not connected. Connect it in Google Drive settings first.');
        $message = "{$b['BackupName']} copied to Google Drive.";
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Google Drive copy failed: ' . $e->getMessage();
        $messageType = 'error';
    }
}
// Handle backup download
if (isset($_GET['download'])) {
    try {
        $backupId = $_GET['download'];
        $stmt = $pdo->prepare("SELECT * FROM database_backups WHERE BackupID = ?");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($backup && file_exists($backup['BackupPath'])) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $backup['BackupName'] . '"');
            header('Content-Length: ' . filesize($backup['BackupPath']));
            readfile($backup['BackupPath']);
            exit();
        } else {
            $message = "Backup file not found.";
            $messageType = 'error';
        }
    } catch (Exception $e) {
        $message = "Error downloading backup: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Handle backup deletion
if (isset($_POST['delete_backup'])) {
    try {
        $backupId = $_POST['backup_id'];
        
        // Get backup details
        $stmt = $pdo->prepare("SELECT * FROM database_backups WHERE BackupID = ?");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($backup) {
            // Delete file
            if (file_exists($backup['BackupPath'])) {
                unlink($backup['BackupPath']);
            }
            
            // Delete from database
            $stmt = $pdo->prepare("DELETE FROM database_backups WHERE BackupID = ?");
            $stmt->execute([$backupId]);
            
            $message = "Backup deleted successfully.";
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = "Error deleting backup: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Handle restore
if (isset($_POST['restore_backup'])) {
    try {
        $backupId = $_POST['backup_id'];
        
        // Get backup details
        $stmt = $pdo->prepare("SELECT * FROM database_backups WHERE BackupID = ?");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($backup && file_exists($backup['BackupPath'])) {
            // Read SQL file
            $sql = file_get_contents($backup['BackupPath']);
            
            if ($sql === false) {
                throw new Exception("Failed to read backup file.");
            }
            
            // Disable foreign key checks for restore
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            // Remove comments from SQL
            $sql = preg_replace('/^--.*$/m', '', $sql); // Remove -- comments
            $sql = preg_replace('/\/\*.*?\*\//s', '', $sql); // Remove /* */ comments
            
            // Split SQL into individual queries
            // Split by semicolon followed by newline or end of string
            $queries = preg_split('/;\s*[\r\n]+/', $sql);
            
            // Execute each query
            $transactionStarted = false;
            
            try {
                $pdo->beginTransaction();
                $transactionStarted = true;
                
                $executedCount = 0;
                foreach ($queries as $query) {
                    $query = trim($query);
                    // Skip empty queries
                    if (!empty($query)) {
                        $pdo->exec($query);
                        $executedCount++;
                    }
                }
                
                $pdo->commit();
                $transactionStarted = false;
                
                // Re-enable foreign key checks
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                
                $message = "Database restored successfully from: {$backup['BackupName']} ({$executedCount} queries executed)";
                $messageType = 'success';
            } catch (Exception $e) {
                // Only rollback if transaction is active
                if ($transactionStarted) {
                    try {
                        $pdo->rollBack();
                    } catch (Exception $rollbackEx) {
                        // Ignore rollback errors
                    }
                }
                // Re-enable foreign key checks even on error
                try {
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                } catch (Exception $fkEx) {
                    // Ignore foreign key reset errors
                }
                throw new Exception("Restore failed: " . $e->getMessage());
            }
        } else {
            throw new Exception("Backup file not found.");
        }
    } catch (Exception $e) {
        $message = "Error restoring backup: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch all backups
try {
    $stmt = $pdo->query("
        SELECT 
            db.*,
            sa.Username as CreatedByName
        FROM database_backups db
        LEFT JOIN super_admins sa ON db.CreatedBy = sa.SuperAdminID
        ORDER BY db.CreatedAt DESC
    ");
    $backups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $backups = [];
    $message = "Error fetching backups: " . $e->getMessage();
    $messageType = 'error';
}

// Get database statistics
try {
    $stats = [];
    
    // Total database size
    $stmt = $pdo->query("
        SELECT 
            SUM(data_length + index_length) as total_size
        FROM information_schema.TABLES
        WHERE table_schema = '" . DB_NAME . "'
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['db_size'] = $result['total_size'] ?? 0;
    
    // Table counts
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM members");
    $stats['members'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM transactions");
    $stats['transactions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM admins");
    $stats['admins'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM academic_years");
    $stats['years'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Total backups
    $stmt = $pdo->query("SELECT COUNT(*) as count, SUM(FileSize) as total_size FROM database_backups WHERE Status = 'success'");
    $backupStats = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['backup_count'] = $backupStats['count'];
    $stats['backup_size'] = $backupStats['total_size'] ?? 0;
    
} catch (Exception $e) {
    $stats = [
        'db_size' => 0,
        'members' => 0,
        'transactions' => 0,
        'admins' => 0,
        'years' => 0,
        'backup_count' => 0,
        'backup_size' => 0
    ];
}

// Automatic daily backup: settings, status and where the copies go
$autoCfg = srcBackupConfig($pdo);
$autoNow = srcBackupNow($autoCfg['timezone']);
[$autoH, $autoM] = array_map('intval', explode(':', $autoCfg['time']));
$autoNext = (clone $autoNow)->setTime($autoH, $autoM);
if ($autoNext <= $autoNow || substr($autoCfg['last'], 0, 10) === $autoNow->format('Y-m-d')) {
    $autoNext->modify('+1 day');
}
$autoNextText = $autoCfg['enabled'] ? $autoNext->format('D, d M Y \a\t h:i A') : '';
$gdriveConnected = false;
$gdriveAccount = '';
try {
    $acc = $pdo->query("SELECT AccountEmail FROM google_drive_settings WHERE IsActive = 1 AND RefreshToken IS NOT NULL AND RefreshToken <> '' ORDER BY IsDefault DESC LIMIT 1")->fetchColumn();
    $gdriveConnected = $acc !== false;
    $gdriveAccount = (string)$acc;
} catch (Exception $e) { /* Google Drive not set up */ }
$backupDirPath = srcBackupDir();
$backupDirProtected = is_file($backupDirPath . '/.htaccess') && is_file($backupDirPath . '/web.config');
$autoStatusKind = strpos($autoCfg['last_status'], 'success') === 0 ? 'success' : (strpos($autoCfg['last_status'], 'warning') === 0 ? 'warning' : ($autoCfg['last_status'] ? 'failed' : ''));

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Backup & Restore - Super Admin</title>
    <link rel="stylesheet" href="shared-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .backup-container {
            max-width: 1400px;
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
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .page-title i {
            color: var(--primary-color);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary-color);
        }
        
        .stat-card.green { border-left-color: #34a853; }
        .stat-card.blue { border-left-color: #1a73e8; }
        .stat-card.orange { border-left-color: #fbbc04; }
        .stat-card.purple { border-left-color: #9334e6; }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        /* Action Section */
        .action-section {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
        }
        
        .action-header {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .action-card {
            padding: 20px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            text-align: center;
            transition: all 0.3s;
        }
        
        .action-card:hover {
            border-color: var(--primary-color);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .action-icon {
            font-size: 40px;
            margin-bottom: 15px;
        }
        
        .action-card.create .action-icon { color: #34a853; }
        .action-card.auto .action-icon { color: #1a73e8; }
        .action-card.restore .action-icon { color: #fbbc04; }
        
        .action-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--text-primary);
        }
        
        .action-desc {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 15px;
        }
        
        /* Automatic Daily Backup */
        .auto-backup { scroll-margin-top: 80px; }
        .ab-pill { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; letter-spacing: .5px; }
        .ab-pill.on { background: #d4edda; color: #155724; }
        .ab-pill.off { background: #e9ecef; color: #6c757d; }
        .ab-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); gap: 24px; }
        @media (max-width: 900px) { .ab-grid { grid-template-columns: 1fr; } }
        .ab-form { display: flex; flex-direction: column; gap: 16px; }
        .ab-switch { display: flex; gap: 10px; align-items: flex-start; cursor: pointer; color: var(--text-primary); font-weight: 600; }
        .ab-switch input { width: 18px; height: 18px; margin-top: 2px; accent-color: var(--primary-color); flex-shrink: 0; }
        .ab-switch small { display: block; font-weight: 500; margin-top: 3px; }
        .ab-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 600px) { .ab-fields { grid-template-columns: 1fr; } }
        .ab-fields label { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 600; color: var(--text-secondary); }
        .ab-fields input { padding: 10px 12px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--card-bg); color: var(--text-primary); font-size: 15px; }
        .ab-fields small { font-weight: 500; color: var(--text-secondary); font-size: 12px; }
        .ab-days { display: flex; align-items: center; gap: 8px; color: var(--text-primary); }
        .ab-days input { width: 90px; }
        .ab-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .ab-status { border: 1px solid var(--border-color); border-radius: 10px; padding: 16px; display: flex; flex-direction: column; gap: 10px; }
        .ab-row { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; color: var(--text-secondary); }
        .ab-row b { color: var(--text-primary); text-align: right; }
        .ab-result { font-size: 13px; padding: 8px 12px; border-radius: 8px; }
        .ab-result.success { background: #d4edda; color: #155724; }
        .ab-result.warning { background: #fff3cd; color: #856404; }
        .ab-result.failed { background: #f8d7da; color: #721c24; }
        .ab-where { border-top: 1px solid var(--border-color); padding-top: 10px; display: flex; flex-direction: column; gap: 10px; }
        .ab-where-title { font-weight: 700; color: var(--text-primary); font-size: 14px; }
        .ab-store { display: flex; gap: 12px; align-items: flex-start; font-size: 13px; color: var(--text-primary); }
        .ab-store > i { font-size: 20px; width: 24px; text-align: center; color: var(--primary-color); margin-top: 2px; }
        .ab-store .fa-google-drive { color: #34a853; }
        .ab-store small { color: var(--text-secondary); word-break: break-all; }
        .ab-status .ok, .ab-switch .ok { color: #34a853; }
        .ab-status .warn, .ab-switch .warn { color: #d97706; }
        .ab-tip { font-size: 12px; color: var(--text-secondary); margin: 0; line-height: 1.6; }
        .ab-tip code { display: inline-block; margin-top: 4px; padding: 3px 8px; border-radius: 6px; background: var(--hover-bg); color: var(--text-primary); word-break: break-all; }
        .type-badge { padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .type-auto { background: #e8f0fe; color: #1a73e8; }
        .type-manual { background: #f1f3f4; color: #5f6368; }
        .drive-link { color: #34a853; font-weight: 600; text-decoration: none; white-space: nowrap; }
        .drive-copy { border: 1px solid var(--border-color); background: transparent; color: var(--text-primary); border-radius: 6px; padding: 5px 10px; cursor: pointer; font-size: 12px; white-space: nowrap; }
        .drive-copy:hover { border-color: #34a853; color: #34a853; }
        .muted { color: var(--text-secondary); }

        /* Backup Table */
        .table-container {
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        
        .table-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: var(--primary-color);
            color: white;
        }
        
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        
        tbody tr:hover {
            background: var(--hover-bg);
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-success {
            background: #d4edda;
            color: #155724;
        }
        
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* Action Buttons */
        .btn-group {
            display: flex;
            gap: 8px;
        }
        
        .btn-icon {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-download {
            background: #1a73e8;
            color: white;
        }
        
        .btn-download:hover {
            background: #1557b0;
        }
        
        .btn-restore {
            background: #fbbc04;
            color: #fff;
        }
        
        .btn-restore:hover {
            background: #d4a003;
        }
        
        .btn-delete {
            background: #ea4335;
            color: white;
        }
        
        .btn-delete:hover {
            background: #c5392b;
        }
        
        /* Messages */
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .message.warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: var(--card-bg);
            padding: 30px;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            box-shadow: var(--shadow-lg);
        }
        
        .modal-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-primary);
        }
        
        .modal-body {
            margin-bottom: 20px;
            color: var(--text-secondary);
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        
        .empty-state i {
            font-size: 60px;
            color: var(--border-color);
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-grid {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="backup-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-database"></i>
                Database Backup & Restore
            </h1>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Database Statistics -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-label">Database Size</div>
                <div class="stat-value"><?php echo formatBytes($stats['db_size']); ?></div>
            </div>
            <div class="stat-card green">
                <div class="stat-label">Total Records</div>
                <div class="stat-value"><?php echo number_format($stats['members'] + $stats['transactions']); ?></div>
            </div>
            <div class="stat-card orange">
                <div class="stat-label">Total Backups</div>
                <div class="stat-value"><?php echo $stats['backup_count']; ?></div>
            </div>
            <div class="stat-card purple">
                <div class="stat-label">Backup Storage</div>
                <div class="stat-value"><?php echo formatBytes($stats['backup_size']); ?></div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="action-section">
            <h2 class="action-header">
                <i class="fas fa-bolt"></i>
                Quick Actions
            </h2>
            <div class="action-grid">
                <div class="action-card create">
                    <div class="action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="action-title">Create Backup</div>
                    <div class="action-desc">Generate a new backup of the entire database</div>
                    <form method="POST" style="margin: 0;">
                        <button type="submit" name="create_backup" class="btn btn-primary">
                            <i class="fas fa-database"></i> Create Now
                        </button>
                    </form>
                </div>
                
                <div class="action-card auto">
                    <div class="action-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="action-title">Auto Backup</div>
                    <div class="action-desc">
                        <?php echo $autoCfg['enabled'] ? 'Daily at ' . htmlspecialchars($autoCfg['time']) . ' · next ' . $autoNext->format('d M, h:i A') : 'Automatic daily backup is off'; ?>
                    </div>
                    <a href="#autoBackup" class="btn btn-primary">
                        <i class="fas fa-calendar-alt"></i> Configure
                    </a>
                </div>
                
                <div class="action-card restore">
                    <div class="action-icon">
                        <i class="fas fa-undo"></i>
                    </div>
                    <div class="action-title">Quick Restore</div>
                    <div class="action-desc">Restore from most recent backup</div>
                    <button class="btn btn-warning" onclick="showRestoreLatest()" <?php echo empty($backups) ? 'disabled' : ''; ?>>
                        <i class="fas fa-history"></i> Restore Latest
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Automatic Daily Backup -->
        <div class="action-section auto-backup" id="autoBackup">
            <h2 class="action-header">
                <i class="fas fa-clock"></i>
                Automatic Daily Backup
                <span class="ab-pill <?php echo $autoCfg['enabled'] ? 'on' : 'off'; ?>"><?php echo $autoCfg['enabled'] ? 'ON' : 'OFF'; ?></span>
            </h2>
            <div class="ab-grid">
                <form method="POST" class="ab-form">
                    <label class="ab-switch">
                        <input type="checkbox" name="auto_backup_enabled" value="1" <?php echo $autoCfg['enabled'] ? 'checked' : ''; ?>>
                        <span>Make a full SQL backup automatically every day</span>
                    </label>
                    <div class="ab-fields">
                        <label>Backup time
                            <input type="time" name="backup_time" value="<?php echo htmlspecialchars($autoCfg['time']); ?>" required>
                            <small><?php echo htmlspecialchars($autoCfg['timezone']); ?> time</small>
                        </label>
                        <label>Keep automatic backups for
                            <span class="ab-days"><input type="number" name="backup_retention_days" min="1" max="365" value="<?php echo (int)$autoCfg['retention']; ?>" required> days</span>
                            <small>Older automatic backups are removed. Manual backups are never removed.</small>
                        </label>
                    </div>
                    <label class="ab-switch">
                        <input type="checkbox" name="backup_to_gdrive" value="1" <?php echo $autoCfg['gdrive'] ? 'checked' : ''; ?>>
                        <span>Also keep a private copy in Google Drive
                            <?php if ($gdriveConnected): ?>
                                <small class="ok"><i class="fas fa-check-circle"></i> connected (<?php echo htmlspecialchars($gdriveAccount); ?>)</small>
                            <?php else: ?>
                                <small class="warn"><i class="fas fa-exclamation-triangle"></i> not connected – <a href="google-drive-settings.php">connect Google Drive</a></small>
                            <?php endif; ?>
                        </span>
                    </label>
                    <div class="ab-buttons">
                        <button type="submit" name="save_auto_backup" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                        <button type="submit" name="run_auto_backup" class="btn btn-secondary" formnovalidate><i class="fas fa-play"></i> Run Daily Backup Now</button>
                    </div>
                </form>
                <div class="ab-status">
                    <div class="ab-row"><span>Next backup</span><b><?php echo $autoCfg['enabled'] ? $autoNext->format('D, d M Y \a\t h:i A') : '—'; ?></b></div>
                    <div class="ab-row"><span>Last automatic backup</span><b><?php echo $autoCfg['last'] ? date('d M Y, h:i A', strtotime($autoCfg['last'])) : 'Not yet'; ?></b></div>
                    <?php if ($autoCfg['last_status']): ?>
                        <div class="ab-result <?php echo $autoStatusKind; ?>"><?php echo htmlspecialchars(preg_replace('/^(success|warning|failed): /', '', $autoCfg['last_status'])); ?></div>
                    <?php endif; ?>
                    <div class="ab-where">
                        <div class="ab-where-title"><i class="fas fa-hdd"></i> Where backups are stored</div>
                        <div class="ab-store">
                            <i class="fas fa-server"></i>
                            <div><b>On the server</b> – <?php echo $backupDirProtected ? '<span class="ok">locked against web access</span>' : '<span class="warn">folder not protected</span>'; ?><br><small><?php echo htmlspecialchars($backupDirPath); ?></small></div>
                        </div>
                        <div class="ab-store">
                            <i class="fab fa-google-drive"></i>
                            <div><b>Google Drive</b> – <?php echo $autoCfg['gdrive'] ? ($gdriveConnected ? 'private folder “SR Colony - Database Backups”' : '<span class="warn">waiting for a connected account</span>') : 'off'; ?><br><small>Survives even if the server is lost or hacked</small></div>
                        </div>
                    </div>
                    <p class="ab-tip"><i class="fas fa-info-circle"></i> The backup starts on the first visit to the website after the backup time. For an exact time on your hosting, also add this cron job:<br><code>php <?php echo htmlspecialchars(str_replace('\\', '/', dirname(__DIR__))); ?>/cron/daily-backup.php</code></p>
                </div>
            </div>
        </div>

        <!-- Backup History -->
        <div class="table-container">
            <div class="table-header">
                <h2 class="table-title">
                    <i class="fas fa-history"></i>
                    Backup History
                </h2>
            </div>
            
            <?php if (empty($backups)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Backups Yet</h3>
                    <p>Create your first backup to get started</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Backup Name</th>
                            <th>Created Date</th>
                            <th>File Size</th>
                            <th>Created By</th>
                            <th>Type</th>
                            <th>Google Drive</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $backup): ?>
                            <tr>
                                <td>
                                    <i class="fas fa-file-archive"></i>
                                    <?php echo htmlspecialchars($backup['BackupName']); ?>
                                </td>
                                <td><?php echo date('M d, Y h:i A', strtotime($backup['CreatedAt'])); ?></td>
                                <td><?php echo formatBytes($backup['FileSize']); ?></td>
                                <td><?php echo htmlspecialchars($backup['CreatedByName'] ?? (($backup['BackupType'] ?? '') === 'auto' ? 'System' : 'Unknown')); ?></td>
                                <td>
                                    <span class="type-badge type-<?php echo ($backup['BackupType'] ?? 'manual') === 'auto' ? 'auto' : 'manual'; ?>">
                                        <?php echo ($backup['BackupType'] ?? 'manual') === 'auto' ? 'Automatic' : 'Manual'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($backup['DriveLink'])): ?>
                                        <a href="<?php echo htmlspecialchars($backup['DriveLink']); ?>" target="_blank" rel="noopener" class="drive-link"><i class="fab fa-google-drive"></i> Open</a>
                                    <?php elseif ($gdriveConnected): ?>
                                        <form method="POST" style="margin:0">
                                            <input type="hidden" name="backup_id" value="<?php echo (int)$backup['BackupID']; ?>">
                                            <button type="submit" name="copy_to_drive" class="drive-copy" title="<?php echo htmlspecialchars($backup['Notes'] ?? ''); ?>"><i class="fas fa-cloud-upload-alt"></i> Copy</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $backup['Status']; ?>">
                                        <?php echo ucfirst($backup['Status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="?download=<?php echo $backup['BackupID']; ?>" class="btn-icon btn-download" title="Download">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <button onclick="confirmRestore(<?php echo $backup['BackupID']; ?>, '<?php echo htmlspecialchars($backup['BackupName']); ?>')" 
                                                class="btn-icon btn-restore" title="Restore">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                        <button onclick="confirmDelete(<?php echo $backup['BackupID']; ?>, '<?php echo htmlspecialchars($backup['BackupName']); ?>')" 
                                                class="btn-icon btn-delete" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Restore Confirmation Modal -->
    <div id="restoreModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <i class="fas fa-exclamation-triangle" style="color: #fbbc04;"></i>
                Confirm Database Restore
            </div>
            <div class="modal-body">
                <p><strong>Warning:</strong> This will replace the current database with the backup.</p>
                <p>Backup: <strong id="restoreBackupName"></strong></p>
                <p>All current data will be lost. Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('restoreModal')">Cancel</button>
                <form method="POST" style="margin: 0;" id="restoreForm">
                    <input type="hidden" name="backup_id" id="restoreBackupId">
                    <button type="submit" name="restore_backup" class="btn btn-warning">
                        <i class="fas fa-undo"></i> Restore Database
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <i class="fas fa-trash" style="color: #ea4335;"></i>
                Confirm Deletion
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this backup?</p>
                <p>Backup: <strong id="deleteBackupName"></strong></p>
                <p>This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
                <form method="POST" style="margin: 0;" id="deleteForm">
                    <input type="hidden" name="backup_id" id="deleteBackupId">
                    <button type="submit" name="delete_backup" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Delete Backup
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function confirmRestore(backupId, backupName) {
            document.getElementById('restoreBackupId').value = backupId;
            document.getElementById('restoreBackupName').textContent = backupName;
            document.getElementById('restoreModal').classList.add('active');
        }
        
        function confirmDelete(backupId, backupName) {
            document.getElementById('deleteBackupId').value = backupId;
            document.getElementById('deleteBackupName').textContent = backupName;
            document.getElementById('deleteModal').classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        function showRestoreLatest() {
            <?php if (!empty($backups)): ?>
                const latestBackup = <?php echo json_encode($backups[0]); ?>;
                confirmRestore(latestBackup.BackupID, latestBackup.BackupName);
            <?php endif; ?>
        }
        
        // Close modal on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });
    </script>
    <script src="theme-manager.js"></script>
</body>
</html>
