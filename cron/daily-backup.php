<?php
/**
 * Daily database backup for a server scheduler (more reliable than waiting for the first visitor).
 *
 *   cPanel / Linux cron (every day at 02:00):
 *       0 2 * * *  php /home/USER/public_html/Website/cron/daily-backup.php
 *   Windows Task Scheduler (XAMPP):
 *       C:\xampp\php\php.exe H:\SRC\Website\cron\daily-backup.php
 *
 * Add --force to make a backup even if today's is already done.
 * Uses the same settings as Super Admin → Database Backup → Automatic Daily Backup.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

// config.php expects a web request; give it what it needs
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? (getenv('SITE_HOST') ?: 'localhost');
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2);
$_SERVER['SCRIPT_NAME'] = '/cron/daily-backup.php';
define('SKIP_AUTO_SESSION', true);
define('SKIP_AUTO_BACKUP', true);

require dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/backup-helper.php';

$force = in_array('--force', $argv ?? [], true);
$result = srcRunAutoBackup($pdo, $force);
echo srcBackupNow(srcBackupSetting($pdo, 'system_timezone', 'Asia/Kolkata'))->format('Y-m-d H:i:s T') . '  ' . $result['message'] . PHP_EOL;
exit(strpos($result['message'], 'failed') !== false ? 1 : 0);
