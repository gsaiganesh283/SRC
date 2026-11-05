<?php
/**
 * Database backups (SQL format) – shared by superadmin/database-backup.php, the automatic daily backup
 * (srcScheduleAutoBackup, called from config.php) and cron/daily-backup.php.
 *
 * Where backups are kept:
 *   1. On the server, in BACKUP_PATH (constant or environment variable) – default Website/backups,
 *      which is locked against web access (.htaccess for Apache, web.config for IIS / Azure).
 *   2. A private copy in Google Drive ("SR Colony - Database Backups"), when an account is connected
 *      and "Copy to Google Drive" is on – so a copy survives even if the server is lost.
 *
 * Settings (system_settings): auto_backup_enabled, backup_time (HH:MM), backup_retention_days, backup_to_gdrive,
 * plus last_auto_backup / last_auto_backup_status written by the job.
 */

/** Folder that holds the .sql files */
function srcBackupDir() {
    $dir = defined('BACKUP_PATH') ? BACKUP_PATH : (getenv('BACKUP_PATH') ?: dirname(__DIR__) . '/backups');
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    srcProtectBackupDir($dir);
    return $dir;
}

/** Make sure nobody can download backups through the website */
function srcProtectBackupDir($dir) {
    $files = [
        '.htaccess' => "# Database backups – never served to browsers\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
        'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n    <security>\n      <requestFiltering>\n        <fileExtensions allowUnlisted=\"false\" />\n      </requestFiltering>\n    </security>\n  </system.webServer>\n</configuration>\n",
        'index.html' => '',
    ];
    foreach ($files as $name => $content) {
        if (!file_exists("$dir/$name")) {
            @file_put_contents("$dir/$name", $content);
        }
    }
}

function srcBackupSetting(PDO $pdo, $key, $default = '') {
    try {
        $s = $pdo->prepare('SELECT SettingValue FROM system_settings WHERE SettingKey = ? LIMIT 1');
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return ($v === false || $v === null) ? $default : (string)$v;
    } catch (Exception $e) {
        return $default;
    }
}

function srcBackupSaveSetting(PDO $pdo, $key, $value, $by = null) {
    $s = $pdo->prepare('SELECT COUNT(*) FROM system_settings WHERE SettingKey = ?');
    $s->execute([$key]);
    if ($s->fetchColumn()) {
        $pdo->prepare('UPDATE system_settings SET SettingValue = ?, UpdatedBy = COALESCE(?, UpdatedBy), UpdatedAt = NOW() WHERE SettingKey = ?')->execute([(string)$value, $by, $key]);
    } else {
        $pdo->prepare('INSERT INTO system_settings (SettingKey, SettingValue, UpdatedBy, UpdatedAt) VALUES (?, ?, ?, NOW())')->execute([$key, (string)$value, $by]);
    }
}

function srcBackupOn($value) {
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

/** The backup settings with their defaults */
function srcBackupConfig(PDO $pdo) {
    $time = srcBackupSetting($pdo, 'backup_time', '02:00');
    return [
        'enabled' => srcBackupOn(srcBackupSetting($pdo, 'auto_backup_enabled', '1')),
        'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : '02:00',
        'retention' => max(1, min(365, (int)srcBackupSetting($pdo, 'backup_retention_days', '30'))),
        'gdrive' => srcBackupOn(srcBackupSetting($pdo, 'backup_to_gdrive', '1')),
        'last' => srcBackupSetting($pdo, 'last_auto_backup', ''),
        'last_status' => srcBackupSetting($pdo, 'last_auto_backup_status', ''),
        'timezone' => srcBackupSetting($pdo, 'system_timezone', 'Asia/Kolkata') ?: 'Asia/Kolkata',
    ];
}

function srcBackupNow($timezone) {
    try {
        return new DateTime('now', new DateTimeZone($timezone));
    } catch (Exception $e) {
        return new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    }
}

/** Extra columns for automatic backups and their Google Drive copy */
function srcBackupEnsureTable(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $cols = $pdo->query('SHOW COLUMNS FROM database_backups')->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'BackupType' => "ADD COLUMN BackupType VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER Status",
        'DriveFileID' => 'ADD COLUMN DriveFileID VARCHAR(100) DEFAULT NULL AFTER BackupType',
        'DriveLink' => 'ADD COLUMN DriveLink VARCHAR(500) DEFAULT NULL AFTER DriveFileID',
        'Notes' => 'ADD COLUMN Notes VARCHAR(500) DEFAULT NULL AFTER DriveLink',
    ];
    $parts = [];
    foreach ($add as $col => $sql) {
        if (!in_array($col, $cols, true)) $parts[] = $sql;
    }
    if ($parts) {
        $pdo->exec('ALTER TABLE database_backups ' . implode(', ', $parts));
    }
}

/**
 * Write a full SQL backup (structure + data of every table, then views) and record it.
 * Returns ['id', 'name', 'path', 'size'].
 */
function srcCreateBackup(PDO $pdo, $createdBy = null, $type = 'manual') {
    srcBackupEnsureTable($pdo);
    @set_time_limit(600);
    $dir = srcBackupDir();
    // File names and the header use the system's time zone (Settings → system_timezone), not the server's
    $stamp = srcBackupNow(srcBackupSetting($pdo, 'system_timezone', 'Asia/Kolkata') ?: 'Asia/Kolkata');
    $name = ($type === 'auto' ? 'auto_backup_' : 'backup_') . $stamp->format('Y-m-d_H-i-s') . '.sql';
    $path = "$dir/$name";
    $fh = @fopen($path, 'w');
    if (!$fh) {
        throw new Exception('Cannot write to the backup folder (' . $dir . '). Check its permissions.');
    }
    try {
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        fwrite($fh, "-- SR Colony Database Backup\n-- Generated: " . $stamp->format('Y-m-d H:i:s T') . "\n-- Database: {$dbName}\n-- Type: " . ($type === 'auto' ? 'Automatic daily backup' : 'Manual backup') . "\n\n");
        fwrite($fh, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\nSET time_zone = \"+00:00\";\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        $views = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            fwrite($fh, "\n\n-- --------------------------------------------------------\n-- Table structure for table `{$table}`\n-- --------------------------------------------------------\n\n");
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
            $create = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            fwrite($fh, $create[1] . ";\n\n");

            $result = $pdo->query("SELECT * FROM `{$table}`");
            $count = $result->rowCount();
            if ($count > 0) {
                fwrite($fh, "-- Dumping data for table `{$table}`\n-- {$count} rows\n\n");
                $columns = null;
                $n = 0;
                while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                    if ($columns === null) {
                        $columns = '`' . implode('`, `', array_keys($row)) . '`';
                    }
                    $values = '(' . implode(', ', array_map(function ($v) use ($pdo) {
                        return $v === null ? 'NULL' : $pdo->quote((string)$v);
                    }, array_values($row))) . ')';
                    // 500 rows per INSERT keeps each statement a sensible size for restoring
                    if ($n % 500 === 0) {
                        fwrite($fh, ($n ? ";\n" : '') . "INSERT INTO `{$table}` ({$columns}) VALUES\n{$values}");
                    } else {
                        fwrite($fh, ",\n{$values}");
                    }
                    $n++;
                }
                fwrite($fh, ";\n\n");
            }
        }
        foreach ($views as $view) {
            fwrite($fh, "\n\n-- --------------------------------------------------------\n-- View structure for view `{$view}`\n-- --------------------------------------------------------\n\n");
            fwrite($fh, "DROP VIEW IF EXISTS `{$view}`;\n");
            $create = $pdo->query("SHOW CREATE VIEW `{$view}`")->fetch(PDO::FETCH_NUM);
            fwrite($fh, $create[1] . ";\n\n");
        }
        fwrite($fh, "\nSET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n");
    } catch (Exception $e) {
        fclose($fh);
        @unlink($path);
        throw $e;
    }
    fclose($fh);
    $size = filesize($path);
    $pdo->prepare("INSERT INTO database_backups (BackupName, BackupPath, FileSize, CreatedBy, Status, BackupType) VALUES (?, ?, ?, ?, 'success', ?)")
        ->execute([$name, $path, $size, $createdBy, $type === 'auto' ? 'auto' : 'manual']);
    return ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'path' => $path, 'size' => $size];
}

/** Name in Google Drive: description and date, e.g. "SR Colony Database Backup (Automatic) - 09-10-2026 10-10-09.sql" */
function srcBackupDriveName($fileName, $createdAt = null) {
    $kind = strpos($fileName, 'auto_backup_') === 0 ? 'Automatic' : 'Manual';
    $ts = $createdAt ? strtotime($createdAt) : false; // the record's time is in the system time zone
    if ($ts) {
        return "SR Colony Database Backup ({$kind}) - " . date('d-m-Y H-i-s', $ts) . '.sql';
    }
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})_(\d{2})-(\d{2})-(\d{2})/', $fileName, $m)) {
        return "SR Colony Database Backup ({$kind}) - {$m[3]}-{$m[2]}-{$m[1]} {$m[4]}-{$m[5]}-{$m[6]}.sql";
    }
    return "SR Colony Database Backup ({$kind}) - " . pathinfo($fileName, PATHINFO_FILENAME) . '.sql';
}

/** Give backups already in Drive the description-and-date name */
function srcRenameBackupsInDrive(PDO $pdo) {
    srcBackupEnsureTable($pdo);
    require_once __DIR__ . '/google-drive-helper.php';
    $drive = new GoogleDriveHelper($pdo);
    $out = ['renamed' => 0, 'failed' => 0, 'errors' => []];
    if (!$drive->isConfigured()) return $out;
    foreach ($pdo->query("SELECT BackupID, BackupName, DriveFileID, CreatedAt FROM database_backups WHERE DriveFileID IS NOT NULL AND DriveFileID <> ''")->fetchAll(PDO::FETCH_ASSOC) as $b) {
        try {
            $drive->renameFile($b['DriveFileID'], srcBackupDriveName($b['BackupName'], $b['CreatedAt']));
            $out['renamed']++;
        } catch (Exception $e) {
            $out['failed']++;
            $out['errors'][] = $b['BackupName'] . ': ' . $e->getMessage();
        }
    }
    return $out;
}

/** Private copy in Google Drive, folder "SR Colony - Database Backups". Returns the Drive link or null. */
function srcCopyBackupToDrive(PDO $pdo, array $backup) {
    require_once __DIR__ . '/google-drive-helper.php';
    $drive = new GoogleDriveHelper($pdo);
    if (!$drive->isConfigured()) {
        return null;
    }
    $folderId = srcBackupSetting($pdo, 'gdrive_backup_folder_id', '');
    if ($folderId === '') {
        $parent = null;
        try {
            $parent = $pdo->query('SELECT FolderID FROM google_drive_settings WHERE IsActive = 1 ORDER BY IsDefault DESC LIMIT 1')->fetchColumn() ?: null;
        } catch (Exception $e) { /* use My Drive */ }
        $folder = $drive->createFolder('SR Colony - Database Backups', $parent);
        $folderId = $folder['id'] ?? '';
        if ($folderId === '') {
            throw new Exception('Could not create the Google Drive backup folder.');
        }
        srcBackupSaveSetting($pdo, 'gdrive_backup_folder_id', $folderId);
    }
    try {
        $r = $drive->uploadFile($backup['path'], srcBackupDriveName($backup['name']), 'application/sql', 'backups', $folderId, false);
    } catch (Exception $e) {
        // The saved folder may have been deleted in Drive: make a new one next time
        if (strpos($e->getMessage(), '404') !== false) {
            srcBackupSaveSetting($pdo, 'gdrive_backup_folder_id', '');
        }
        throw $e;
    }
    $pdo->prepare('UPDATE database_backups SET DriveFileID = ?, DriveLink = ? WHERE BackupID = ?')->execute([$r['fileId'], $r['webViewLink'], $backup['id']]);
    return $r['webViewLink'];
}

/** Remove automatic backups older than the retention period (server file, record and Drive copy). Manual backups are kept. */
function srcApplyBackupRetention(PDO $pdo, $days) {
    srcBackupEnsureTable($pdo);
    $old = $pdo->prepare("SELECT * FROM database_backups WHERE BackupType = 'auto' AND CreatedAt < DATE_SUB(NOW(), INTERVAL ? DAY)");
    $old->execute([(int)$days]);
    $removed = 0;
    $drive = null;
    foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $b) {
        if ($b['BackupPath'] && is_file($b['BackupPath'])) {
            @unlink($b['BackupPath']);
        }
        if (!empty($b['DriveFileID'])) {
            try {
                if ($drive === null) {
                    require_once __DIR__ . '/google-drive-helper.php';
                    $drive = new GoogleDriveHelper($pdo);
                }
                if ($drive->isConfigured()) $drive->deleteFile($b['DriveFileID']);
            } catch (Exception $e) { /* the Drive copy may already be gone */ }
        }
        $pdo->prepare('DELETE FROM database_backups WHERE BackupID = ?')->execute([$b['BackupID']]);
        $removed++;
    }
    return $removed;
}

/**
 * The daily job: backup + Google Drive copy + clean-up. Runs once per day (or always with $force).
 * Returns ['ran' => bool, 'message' => string].
 */
function srcRunAutoBackup(PDO $pdo, $force = false) {
    $cfg = srcBackupConfig($pdo);
    $now = srcBackupNow($cfg['timezone']);
    if (!$force) {
        if (!$cfg['enabled']) return ['ran' => false, 'message' => 'Automatic backup is switched off.'];
        if ($cfg['last'] !== '' && substr($cfg['last'], 0, 10) === $now->format('Y-m-d')) {
            return ['ran' => false, 'message' => 'Today\'s backup is already done.'];
        }
    }
    // One backup at a time, even if two visitors arrive together
    $lock = @fopen(srcBackupDir() . '/.backup.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['ran' => false, 'message' => 'A backup is already running.'];
    }
    try {
        $backup = srcCreateBackup($pdo, null, 'auto');
        $msg = 'Backup ' . $backup['name'] . ' (' . round($backup['size'] / 1024, 1) . ' KB) saved on the server';
        $notes = [];
        if ($cfg['gdrive']) {
            try {
                $link = srcCopyBackupToDrive($pdo, $backup);
                $msg .= $link ? ' and copied to Google Drive' : ' (Google Drive is not connected)';
            } catch (Exception $e) {
                $notes[] = 'Google Drive copy failed: ' . $e->getMessage();
                $msg .= ' – Google Drive copy failed';
            }
        }
        $removed = srcApplyBackupRetention($pdo, $cfg['retention']);
        if ($removed) {
            $msg .= "; removed $removed backup(s) older than {$cfg['retention']} days";
        }
        if ($notes) {
            $pdo->prepare('UPDATE database_backups SET Notes = ? WHERE BackupID = ?')->execute([mb_substr(implode(' | ', $notes), 0, 500), $backup['id']]);
        }
        srcBackupSaveSetting($pdo, 'last_auto_backup', $now->format('Y-m-d H:i:s'));
        srcBackupSaveSetting($pdo, 'last_auto_backup_status', ($notes ? 'warning: ' : 'success: ') . mb_substr($msg . ($notes ? ' (' . implode('; ', $notes) . ')' : ''), 0, 480));
        try {
            $pdo->prepare("INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress) VALUES ('System', 0, 'Auto Backup', ?, 'database_backups', ?, ?)")
                ->execute(['Automatic daily backup: ' . mb_substr($msg, 0, 200), $backup['id'], $_SERVER['REMOTE_ADDR'] ?? 'cron']);
        } catch (Exception $e) { /* logging must not stop the backup */ }
        return ['ran' => true, 'message' => $msg . '.'];
    } catch (Exception $e) {
        srcBackupSaveSetting($pdo, 'last_auto_backup_status', 'failed: ' . mb_substr($e->getMessage(), 0, 480));
        error_log('Automatic backup failed: ' . $e->getMessage());
        return ['ran' => false, 'message' => 'Backup failed: ' . $e->getMessage()];
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/**
 * Called on every web request (from config.php). Costs one small file read on most requests;
 * once a day, after the chosen time, it runs the backup after the page has been sent.
 */
function srcScheduleAutoBackup(PDO $pdo) {
    // The marker holds the Unix time of the next check, so most requests stop after one tiny file read
    $marker = dirname(__DIR__) . '/backups/.auto-backup-next';
    $next = (int)@file_get_contents($marker);
    if ($next > time()) {
        return;
    }
    try {
        $cfg = srcBackupConfig($pdo);
    } catch (Exception $e) {
        return;
    }
    $now = srcBackupNow($cfg['timezone']);
    [$h, $m] = array_map('intval', explode(':', $cfg['time']));
    $todayRun = (clone $now)->setTime($h, $m);
    $tomorrowRun = (clone $todayRun)->modify('+1 day');
    if (!is_dir(dirname($marker))) {
        @mkdir(dirname($marker), 0750, true);
    }
    if (!$cfg['enabled']) {
        @file_put_contents($marker, (string)(time() + 3600)); // look again in an hour (it may be switched on)
        return;
    }
    if ($cfg['last'] !== '' && substr($cfg['last'], 0, 10) === $now->format('Y-m-d')) {
        @file_put_contents($marker, (string)$tomorrowRun->getTimestamp());
        return;
    }
    if ($now < $todayRun) {
        @file_put_contents($marker, (string)$todayRun->getTimestamp()); // not yet time today
        return;
    }
    // Due now. If the run fails, try again in an hour rather than on every request.
    @file_put_contents($marker, (string)(time() + 3600));
    register_shutdown_function(function () use ($pdo, $marker, $tomorrowRun) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request(); // the visitor gets the page now; the backup runs afterwards
        }
        ignore_user_abort(true);
        try {
            $r = srcRunAutoBackup($pdo);
            if ($r['ran'] || strpos($r['message'], 'already done') !== false) {
                @file_put_contents($marker, (string)$tomorrowRun->getTimestamp());
            }
        } catch (Throwable $e) {
            error_log('Automatic backup failed: ' . $e->getMessage());
        }
    });
}

/** Check again on the next request (after the settings were changed) */
function srcResetBackupSchedule() {
    @unlink(dirname(__DIR__) . '/backups/.auto-backup-next');
}
