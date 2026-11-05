<?php
/**
 * Google Drive Sync API
 * Handles file sync status, individual/bulk sync, and auto-sync toggle.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../config/config.php';
require_once '../../includes/google-drive-helper.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['superadmin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? ''));
$adminId = (int)$_SESSION['superadmin_id'];
// Files go to Drive as "<Description> - <dd-mm-yyyy>"
$proofNames = new ProofAttachmentManager($pdo);

// Helper: upsert system_setting
function upsertSetting(PDO $pdo, string $key, string $value, int $adminId): void {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE SettingKey=?");
    $chk->execute([$key]);
    if ($chk->fetchColumn() > 0) {
        $pdo->prepare("UPDATE system_settings SET SettingValue=?,UpdatedBy=?,UpdatedAt=NOW() WHERE SettingKey=?")
            ->execute([$value, $adminId, $key]);
    } else {
        $pdo->prepare("INSERT INTO system_settings (SettingKey,SettingValue,UpdatedBy,UpdatedAt) VALUES (?,?,?,NOW())")
            ->execute([$key, $value, $adminId]);
    }
}

function getSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $s = $pdo->prepare("SELECT SettingValue FROM system_settings WHERE SettingKey=? LIMIT 1");
        $s->execute([$key]);
        return $s->fetchColumn() ?: $default;
    } catch (Exception $e) { return $default; }
}

switch ($action) {

    // ── Status & stats ────────────────────────────────────────────────────────
    case 'status':
        try {
            $drive = new GoogleDriveHelper($pdo);
            $connected = $drive->isConfigured();
            $account   = null;
            if ($connected) {
                $stmt = $pdo->query("SELECT AccountName, AccountEmail FROM google_drive_settings WHERE IsActive=1 AND IsDefault=1 LIMIT 1");
                $account = $stmt->fetch(PDO::FETCH_ASSOC);
            }

            // File counts
            $total   = (int)$pdo->query("SELECT COUNT(*) FROM proof_attachments WHERE UploadStatus != 'deleted'")->fetchColumn();
            $synced  = (int)$pdo->query("SELECT COUNT(*) FROM proof_attachments WHERE GoogleDriveFileID IS NOT NULL AND GoogleDriveFileID != '' AND UploadStatus='uploaded'")->fetchColumn();
            $local   = (int)$pdo->query("SELECT COUNT(*) FROM proof_attachments WHERE (GoogleDriveFileID IS NULL OR GoogleDriveFileID='') AND UploadStatus='uploaded'")->fetchColumn();
            $failed  = (int)$pdo->query("SELECT COUNT(*) FROM proof_attachments WHERE UploadStatus='failed'")->fetchColumn();
            $totalSize = (int)$pdo->query("SELECT COALESCE(SUM(FileSize),0) FROM proof_attachments WHERE UploadStatus!='deleted'")->fetchColumn();

            // Auto-sync setting
            $autoSync = getSetting($pdo, 'gdrive_auto_sync', '0') === '1';
            $lastSync = getSetting($pdo, 'gdrive_last_sync', '');

            echo json_encode([
                'success'    => true,
                'connected'  => $connected,
                'account'    => $account,
                'auto_sync'  => $autoSync,
                'last_sync'  => $lastSync,
                'stats'      => compact('total', 'synced', 'local', 'failed', 'totalSize'),
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── List files ────────────────────────────────────────────────────────────
    case 'list_files':
        try {
            $filter = $_GET['filter'] ?? 'all'; // all | local | synced | failed
            $page   = max(1, (int)($_GET['page'] ?? 1));
            $limit  = 20;
            $offset = ($page - 1) * $limit;

            $where = "WHERE UploadStatus != 'deleted'";
            if ($filter === 'local')  $where = "WHERE (GoogleDriveFileID IS NULL OR GoogleDriveFileID='') AND UploadStatus='uploaded'";
            if ($filter === 'synced') $where = "WHERE GoogleDriveFileID IS NOT NULL AND GoogleDriveFileID!='' AND UploadStatus='uploaded'";
            if ($filter === 'failed') $where = "WHERE UploadStatus='failed'";

            $total = (int)$pdo->query("SELECT COUNT(*) FROM proof_attachments $where")->fetchColumn();

            $rows = $pdo->query("
                SELECT AttachmentID, EntityType, EntityID, OriginalName, MimeType, FileSize,
                       GoogleDriveFileID, GoogleDriveURL, GoogleDriveThumbnail,
                       LocalPath, UploadStatus, UploadError, UploadedAt
                FROM proof_attachments $where
                ORDER BY UploadedAt DESC
                LIMIT $limit OFFSET $offset
            ")->fetchAll(PDO::FETCH_ASSOC);

            // Resolve display URL
            foreach ($rows as &$r) {
                $r['display_url'] = !empty($r['GoogleDriveURL'])
                    ? $r['GoogleDriveURL']
                    : (!empty($r['LocalPath']) ? '../../' . $r['LocalPath'] : null);
                $r['size_human'] = $r['FileSize'] > 0
                    ? ($r['FileSize'] >= 1048576
                        ? round($r['FileSize']/1048576, 1).' MB'
                        : round($r['FileSize']/1024, 1).' KB')
                    : '—';
                $r['is_image'] = strpos($r['MimeType'], 'image/') === 0;
            }
            unset($r);

            echo json_encode([
                'success' => true,
                'files'   => $rows,
                'total'   => $total,
                'page'    => $page,
                'pages'   => (int)ceil($total / $limit),
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── Sync single file ──────────────────────────────────────────────────────
    case 'sync_file':
        $input = json_decode(file_get_contents('php://input'), true);
        $id = (int)($input['attachment_id'] ?? 0);
        if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing attachment_id']); exit; }
        try {
            $drive = new GoogleDriveHelper($pdo);
            if (!$drive->isConfigured()) {
                echo json_encode(['success' => false, 'error' => 'Google Drive not connected']);
                exit;
            }

            $att = $pdo->prepare("SELECT * FROM proof_attachments WHERE AttachmentID=? AND UploadStatus!='deleted'");
            $att->execute([$id]);
            $file = $att->fetch(PDO::FETCH_ASSOC);
            if (!$file) { echo json_encode(['success' => false, 'error' => 'File not found']); exit; }

            // Already synced?
            if (!empty($file['GoogleDriveFileID'])) {
                echo json_encode(['success' => true, 'already_synced' => true]);
                exit;
            }

            $localPath = dirname(dirname(dirname(__FILE__))) . '/' . $file['LocalPath'];
            if (!file_exists($localPath)) {
                echo json_encode(['success' => false, 'error' => 'Local file missing from disk']);
                exit;
            }

            $folderType = $file['EntityType'] === 'expense' ? 'expenses' : 'events';
            $result = $drive->uploadFile($localPath, $proofNames->driveFileName($file['EntityType'], $file['EntityID'], $file['OriginalName'], $proofNames->attachmentNumber($file)), $file['MimeType'], $folderType);

            if (isset($result['fileId'])) {
                $pdo->prepare("UPDATE proof_attachments SET GoogleDriveFileID=?,GoogleDriveURL=?,GoogleDriveThumbnail=?,UploadStatus='uploaded',UploadError=NULL WHERE AttachmentID=?")
                    ->execute([$result['fileId'], $result['webViewLink'] ?? '', $result['thumbnailLink'] ?? '', $id]);
                upsertSetting($pdo, 'gdrive_last_sync', date('Y-m-d H:i:s'), $adminId);
                echo json_encode(['success' => true, 'drive_url' => $result['webViewLink'] ?? '']);
            } else {
                $err = $result['error'] ?? 'Upload failed';
                $pdo->prepare("UPDATE proof_attachments SET UploadStatus='failed',UploadError=? WHERE AttachmentID=?")
                    ->execute([$err, $id]);
                echo json_encode(['success' => false, 'error' => $err]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── Sync all unsynced ─────────────────────────────────────────────────────
    case 'sync_all':
        try {
            $drive = new GoogleDriveHelper($pdo);
            if (!$drive->isConfigured()) {
                echo json_encode(['success' => false, 'error' => 'Google Drive not connected. Configure it in Settings → Google Drive.']);
                exit;
            }

            $files = $pdo->query("
                SELECT * FROM proof_attachments
                WHERE (GoogleDriveFileID IS NULL OR GoogleDriveFileID='')
                  AND UploadStatus='uploaded'
                  AND LocalPath IS NOT NULL
                LIMIT 50
            ")->fetchAll(PDO::FETCH_ASSOC);

            $done = 0; $failed = 0; $errors = [];
            foreach ($files as $file) {
                $localPath = dirname(dirname(dirname(__FILE__))) . '/' . $file['LocalPath'];
                if (!file_exists($localPath)) { $failed++; continue; }

                $folderType = $file['EntityType'] === 'expense' ? 'expenses' : 'events';
                $result = $drive->uploadFile($localPath, $proofNames->driveFileName($file['EntityType'], $file['EntityID'], $file['OriginalName'], $proofNames->attachmentNumber($file)), $file['MimeType'], $folderType);

                if (isset($result['fileId'])) {
                    $pdo->prepare("UPDATE proof_attachments SET GoogleDriveFileID=?,GoogleDriveURL=?,GoogleDriveThumbnail=?,UploadStatus='uploaded',UploadError=NULL WHERE AttachmentID=?")
                        ->execute([$result['fileId'], $result['webViewLink'] ?? '', $result['thumbnailLink'] ?? '', $file['AttachmentID']]);
                    $done++;
                } else {
                    $failed++;
                    $errors[] = $file['OriginalName'] . ': ' . ($result['error'] ?? 'unknown error');
                }
            }

            upsertSetting($pdo, 'gdrive_last_sync', date('Y-m-d H:i:s'), $adminId);
            echo json_encode([
                'success' => true,
                'synced'  => $done,
                'failed'  => $failed,
                'total'   => count($files),
                'errors'  => array_slice($errors, 0, 5),
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── Toggle auto-sync ──────────────────────────────────────────────────────
    case 'toggle_auto_sync':
        $input = json_decode(file_get_contents('php://input'), true);
        $enabled = (bool)($input['enabled'] ?? false);
        try {
            upsertSetting($pdo, 'gdrive_auto_sync', $enabled ? '1' : '0', $adminId);
            echo json_encode(['success' => true, 'auto_sync' => $enabled]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    // ── Rename everything already in Drive to "<Description> - <date>" ─────────
    case 'rename_all':
        try {
            if (!(new GoogleDriveHelper($pdo))->isConfigured()) {
                echo json_encode(['success' => false, 'error' => 'Google Drive not connected']);
                exit;
            }
            @set_time_limit(300);
            $proofs = $proofNames->renameDriveFiles();
            require_once '../../includes/backup-helper.php';
            $backups = srcRenameBackupsInDrive($pdo);
            $renamed = $proofs['renamed'] + $backups['renamed'];
            $failed = $proofs['failed'] + $backups['failed'];
            try {
                $pdo->prepare("INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, IPAddress) VALUES ('SuperAdmin', ?, ?, ?, 'proof_attachments', ?)")
                    ->execute([$adminId, $_SESSION['superadmin_username'] ?? 'superadmin', "Renamed {$renamed} Google Drive file(s) to description and date", $_SERVER['REMOTE_ADDR'] ?? '']);
            } catch (Exception $e) { /* logging is optional */ }
            echo json_encode([
                'success' => $failed === 0 || $renamed > 0,
                'renamed' => $renamed,
                'proofs' => $proofs['renamed'],
                'backups' => $backups['renamed'],
                'failed' => $failed,
                'errors' => array_slice(array_merge($proofs['errors'], $backups['errors']), 0, 5),
                'message' => "Renamed {$proofs['renamed']} proof file(s) and {$backups['renamed']} backup(s) in Google Drive" . ($failed ? ", {$failed} failed" : '') . '.',
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
}
