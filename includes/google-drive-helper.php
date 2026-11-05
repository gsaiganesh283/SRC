<?php
/**
 * Google Drive Helper - Desktop App OAuth (Client ID + Secret Only)
 * 
 * Supports:
 * - Desktop App OAuth2 flow (no refresh token needed from user)
 * - Automatic token management (access + refresh stored in DB)
 * - File upload, delete, folder create, folder list, folder browse
 * - Multiple Google Drive accounts
 */

class GoogleDriveHelper {
    private $pdo;
    private $settings;
    private $accessToken;
    
    const UPLOAD_URL  = 'https://www.googleapis.com/upload/drive/v3/files';
    const FILES_URL   = 'https://www.googleapis.com/drive/v3/files';
    const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';
    const REVOKE_URL  = 'https://oauth2.googleapis.com/revoke';
    
    // Desktop app redirect URI
    const DESKTOP_REDIRECT = 'urn:ietf:wg:oauth:2.0:oob';
    
    // Scopes needed
    const SCOPES = 'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile';
    
    public function __construct($pdo, $accountSettings = null) {
        $this->pdo = $pdo;
        if ($accountSettings) {
            $this->settings = $accountSettings;
        } else {
            $this->loadDefaultSettings();
        }
    }
    
    private function loadDefaultSettings() {
        try {
            $stmt = $this->pdo->query("SELECT * FROM google_drive_settings WHERE IsActive = 1 AND IsDefault = 1 ORDER BY SettingID DESC LIMIT 1");
            $this->settings = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$this->settings) {
                $stmt = $this->pdo->query("SELECT * FROM google_drive_settings WHERE IsActive = 1 ORDER BY SettingID DESC LIMIT 1");
                $this->settings = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            $this->settings = null;
        }
    }
    
    public function isConfigured() {
        if (!$this->settings || !$this->settings['IsActive']) return false;
        return !empty($this->settings['ClientID']) && !empty($this->settings['ClientSecret']) && !empty($this->settings['RefreshToken']);
    }
    
    // ========================
    // OAUTH2 DESKTOP APP FLOW
    // ========================
    
    public static function getAuthUrl($clientId) {
        $params = [
            'client_id'     => $clientId,
            'redirect_uri'  => self::DESKTOP_REDIRECT,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }
    
    public static function exchangeCode($clientId, $clientSecret, $code) {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code'          => $code,
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri'  => self::DESKTOP_REDIRECT,
                'grant_type'    => 'authorization_code',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) throw new Exception('cURL error: ' . $curlError);
        
        $data = json_decode($response, true);
        if ($httpCode !== 200 || isset($data['error'])) {
            throw new Exception('Token exchange failed: ' . ($data['error_description'] ?? $data['error'] ?? 'Unknown error'));
        }
        if (empty($data['access_token'])) throw new Exception('No access token received');
        
        return $data;
    }
    
    public static function getUserInfo($accessToken) {
        $ch = curl_init(self::USERINFO_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}"],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) throw new Exception('Failed to get user info');
        return json_decode($response, true);
    }
    
    public static function revokeToken($token) {
        $ch = curl_init(self::REVOKE_URL . '?token=' . urlencode($token));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    
    // ========================
    // ACCESS TOKEN MANAGEMENT
    // ========================
    
    private function getAccessToken() {
        if ($this->accessToken) return $this->accessToken;
        if (!$this->settings) throw new Exception('Google Drive not configured');
        
        if (!empty($this->settings['AccessToken']) && !empty($this->settings['TokenExpiresAt'])) {
            if (strtotime($this->settings['TokenExpiresAt']) > time() + 60) {
                $this->accessToken = $this->settings['AccessToken'];
                return $this->accessToken;
            }
        }
        return $this->refreshAccessToken();
    }
    
    private function refreshAccessToken() {
        if (empty($this->settings['RefreshToken'])) {
            throw new Exception('No refresh token. Please re-authorize the account.');
        }
        
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id'     => $this->settings['ClientID'],
                'client_secret' => $this->settings['ClientSecret'],
                'refresh_token' => $this->settings['RefreshToken'],
                'grant_type'    => 'refresh_token',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $data = json_decode($response, true);
            throw new Exception('Token refresh failed: ' . ($data['error_description'] ?? $data['error'] ?? 'Unknown'));
        }
        
        $data = json_decode($response, true);
        $this->accessToken = $data['access_token'];
        $this->cacheToken($this->accessToken, $data['expires_in'] ?? 3600);
        return $this->accessToken;
    }
    
    private function cacheToken($token, $expiresIn) {
        try {
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn - 60);
            $stmt = $this->pdo->prepare("UPDATE google_drive_settings SET AccessToken = ?, TokenExpiresAt = ? WHERE SettingID = ?");
            $stmt->execute([$token, $expiresAt, $this->settings['SettingID']]);
        } catch (Exception $e) {
            error_log('GoogleDrive: cache token failed - ' . $e->getMessage());
        }
    }
    
    // ========================
    // FILE OPERATIONS
    // ========================
    
    /**
     * @param string|null $parentOverride upload into this Drive folder instead of the configured ones
     * @param bool        $public         false keeps the file private (used for database backups)
     */
    public function uploadFile($filePath, $fileName, $mimeType, $folderType = 'expenses', $parentOverride = null, $public = true) {
        $token = $this->getAccessToken();

        $parentFolderId = null;
        if ($parentOverride) {
            $parentFolderId = $parentOverride;
        } elseif ($folderType === 'expenses' && !empty($this->settings['ExpensesFolderID'])) {
            $parentFolderId = $this->settings['ExpensesFolderID'];
        } elseif ($folderType === 'events' && !empty($this->settings['EventsFolderID'])) {
            $parentFolderId = $this->settings['EventsFolderID'];
        } elseif (!empty($this->settings['FolderID'])) {
            $parentFolderId = $this->settings['FolderID'];
        }
        
        $metadata = ['name' => $fileName, 'description' => 'Uploaded from SR Colony Management System'];
        if ($parentFolderId) $metadata['parents'] = [$parentFolderId];
        
        $boundary = 'upload_boundary_' . uniqid();
        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) throw new Exception('Cannot read file: ' . $filePath);
        
        $body = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n" . json_encode($metadata) . "\r\n--{$boundary}\r\nContent-Type: {$mimeType}\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode($fileContent) . "\r\n--{$boundary}--";
        
        $ch = curl_init(self::UPLOAD_URL . '?uploadType=multipart&fields=id,name,webViewLink,thumbnailLink,webContentLink,size');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$token}",
                "Content-Type: multipart/related; boundary={$boundary}",
                'Content-Length: ' . strlen($body),
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) throw new Exception('cURL error: ' . $curlError);
        if ($httpCode !== 200) throw new Exception('Upload failed (HTTP ' . $httpCode . '): ' . $response);
        
        $data = json_decode($response, true);
        if ($public) {
            $this->setFilePermission($data['id'], $token);
        }
        
        return [
            'fileId' => $data['id'],
            'webViewLink' => $data['webViewLink'] ?? "https://drive.google.com/file/d/{$data['id']}/view",
            'thumbnailLink' => $data['thumbnailLink'] ?? null,
            'webContentLink' => $data['webContentLink'] ?? null,
            'size' => $data['size'] ?? filesize($filePath),
        ];
    }
    
    private function setFilePermission($fileId, $token) {
        $ch = curl_init(self::FILES_URL . "/{$fileId}/permissions");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['role' => 'reader', 'type' => 'anyone']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
    
    /** Give a file in Drive a new name */
    public function renameFile($fileId, $newName) {
        $token = $this->getAccessToken();
        $ch = curl_init(self::FILES_URL . '/' . rawurlencode($fileId) . '?fields=id,name');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(['name' => $newName]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($curlError) throw new Exception('cURL error: ' . $curlError);
        if ($httpCode !== 200) throw new Exception('Rename failed (HTTP ' . $httpCode . '): ' . $response);
        return json_decode($response, true);
    }

    public function deleteFile($fileId) {
        $token = $this->getAccessToken();
        $ch = curl_init(self::FILES_URL . "/{$fileId}");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode === 204 || $httpCode === 200;
    }
    
    // ========================
    // FOLDER OPERATIONS
    // ========================
    
    public function createFolder($name, $parentFolderId = null) {
        $token = $this->getAccessToken();
        $metadata = ['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'];
        if ($parentFolderId && $parentFolderId !== 'root') $metadata['parents'] = [$parentFolderId];
        
        $ch = curl_init(self::FILES_URL . '?fields=id,name,webViewLink,createdTime');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($metadata),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) throw new Exception('Failed to create folder: ' . $response);
        return json_decode($response, true);
    }
    
    public function listFolders($parentFolderId = 'root') {
        $token = $this->getAccessToken();
        $q = "mimeType = 'application/vnd.google-apps.folder' AND trashed = false";
        $q .= ($parentFolderId && $parentFolderId !== 'root') ? " AND '{$parentFolderId}' in parents" : " AND 'root' in parents";
        
        $params = ['q' => $q, 'fields' => 'files(id,name,createdTime,modifiedTime,webViewLink)', 'orderBy' => 'name', 'pageSize' => 100];
        $ch = curl_init(self::FILES_URL . '?' . http_build_query($params));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) throw new Exception('Failed to list folders: ' . $response);
        return json_decode($response, true);
    }
    
    public function listFiles($folderId = 'root') {
        $token = $this->getAccessToken();
        $q = "trashed = false";
        $q .= ($folderId && $folderId !== 'root') ? " AND '{$folderId}' in parents" : " AND 'root' in parents";
        
        $params = ['q' => $q, 'fields' => 'files(id,name,mimeType,size,createdTime,modifiedTime,webViewLink,iconLink)', 'orderBy' => 'folder,name', 'pageSize' => 100];
        $ch = curl_init(self::FILES_URL . '?' . http_build_query($params));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) throw new Exception('Failed to list files: ' . $response);
        return json_decode($response, true);
    }
    
    public function getFolderInfo($folderId) {
        $token = $this->getAccessToken();
        $ch = curl_init(self::FILES_URL . "/{$folderId}?fields=id,name,webViewLink,createdTime,modifiedTime");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($httpCode === 200) ? json_decode($response, true) : null;
    }
    
    public function getStorageQuota() {
        $token = $this->getAccessToken();
        $ch = curl_init('https://www.googleapis.com/drive/v3/about?fields=storageQuota,user');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($httpCode === 200) ? json_decode($response, true) : null;
    }
    
    public function testConnection() {
        try {
            $token = $this->getAccessToken();
            $ch = curl_init(self::FILES_URL . '?pageSize=1&fields=files(id,name)');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"]]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($httpCode === 200) ? ['success' => true, 'message' => 'Connected to Google Drive successfully'] : ['success' => false, 'message' => 'HTTP ' . $httpCode];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public function getSettings() { return $this->settings; }
    
    public function countDriveFiles() {
        try {
            return (int) $this->pdo->query("SELECT COUNT(*) FROM proof_attachments WHERE GoogleDriveFileID IS NOT NULL AND UploadStatus = 'uploaded'")->fetchColumn();
        } catch (Exception $e) { return 0; }
    }
    
    public function countLocalOnly() {
        try {
            return (int) $this->pdo->query("SELECT COUNT(*) FROM proof_attachments WHERE (GoogleDriveFileID IS NULL OR GoogleDriveFileID = '') AND UploadStatus != 'deleted'")->fetchColumn();
        } catch (Exception $e) { return 0; }
    }
}


class ProofAttachmentManager {
    private $pdo;
    private $gdrive;
    private $localUploadDir;
    
    const MAX_FILE_SIZE = 10 * 1024 * 1024;
    const MAX_FILES_PER_ENTITY = 5;
    const ALLOWED_TYPES = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'application/pdf' => 'pdf', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'text/csv' => 'csv', 'text/plain' => 'txt',
    ];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->gdrive = new GoogleDriveHelper($pdo);
        $this->localUploadDir = dirname(dirname(__FILE__)) . '/uploads/proofs';
    }
    
    public function isGDriveEnabled() { return $this->gdrive->isConfigured(); }

    // ── Names in Google Drive: "<Description> - <dd-mm-yyyy>.<ext>", then " (2)", " (3)" … for more files of the same record ──

    /** Description and date of the record a proof belongs to */
    public function entityLabel($entityType, $entityId) {
        $desc = ''; $date = '';
        try {
            if ($entityType === 'expense') {
                $s = $this->pdo->prepare('SELECT Description, ExpenseDate AS d FROM expenses WHERE ExpenseID = ?');
                $s->execute([$entityId]);
                $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
                $desc = $r['Description'] ?? ''; $date = $r['d'] ?? '';
            } elseif ($entityType === 'event_transaction') {
                $s = $this->pdo->prepare('SELECT Description, TransactionDate AS d FROM event_transactions WHERE TransactionID = ?');
                $s->execute([$entityId]);
                $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
                $desc = $r['Description'] ?? ''; $date = $r['d'] ?? '';
            } elseif ($entityType === 'transaction') {
                // Member transactions have no description: use the remarks, or member and month
                $s = $this->pdo->prepare('SELECT t.Remarks, t.MonthName, t.TransactionDate AS d, m.MemberName FROM transactions t LEFT JOIN members m ON m.MemberID = t.MemberID WHERE t.TransactionID = ?');
                $s->execute([$entityId]);
                $r = $s->fetch(PDO::FETCH_ASSOC) ?: [];
                $desc = trim($r['Remarks'] ?? '') !== '' ? $r['Remarks'] : trim(($r['MemberName'] ?? '') . ' ' . ($r['MonthName'] ?? ''));
                $date = $r['d'] ?? '';
            }
        } catch (Exception $e) { /* fall back below */ }
        $desc = self::cleanFileName($desc);
        if ($desc === '' && $date === '') return null; // the record no longer exists
        if ($desc === '') $desc = ucwords(str_replace('_', ' ', $entityType)) . ' ' . $entityId;
        $ts = $date ? strtotime($date) : false;
        return $ts ? $desc . ' - ' . date('d-m-Y', $ts) : $desc;
    }

    /** Characters Drive / Windows don't like in names, and a sensible length */
    public static function cleanFileName($s) {
        $s = preg_replace('/[\\\\\/:*?"<>|\r\n\t]+/u', ' ', (string)$s);
        $s = trim(preg_replace('/\s+/u', ' ', $s), " .-");
        return mb_substr($s, 0, 100);
    }

    /** Name for the $number-th proof file of a record */
    public function driveFileName($entityType, $entityId, $originalName, $number = 1) {
        $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
        $label = $this->entityLabel($entityType, $entityId);
        if ($label === null) {
            // Record deleted: keep the file's own name
            $label = self::cleanFileName(pathinfo((string)$originalName, PATHINFO_FILENAME)) ?: 'Proof ' . $entityId;
            $number = 1;
        }
        return $label . ($number > 1 ? " ({$number})" : '') . ($ext !== '' ? '.' . $ext : '');
    }

    /** Position of an attachment among its record's files (1, 2, 3 …) */
    public function attachmentNumber(array $att) {
        $s = $this->pdo->prepare("SELECT COUNT(*) FROM proof_attachments WHERE EntityType = ? AND EntityID = ? AND UploadStatus != 'deleted' AND AttachmentID < ?");
        $s->execute([$att['EntityType'], $att['EntityID'], $att['AttachmentID']]);
        return (int)$s->fetchColumn() + 1;
    }

    /**
     * Rename proof files already in Drive to "<Description> - <date>" – all of them, or one record's
     * (called after an expense / event transaction is edited, so the names follow the new description or date).
     */
    public function renameDriveFiles($entityType = null, $entityId = null) {
        $out = ['renamed' => 0, 'failed' => 0, 'errors' => []];
        if (!$this->isGDriveEnabled()) return $out;
        $sql = "SELECT * FROM proof_attachments WHERE UploadStatus != 'deleted'";
        $params = [];
        if ($entityType !== null) { $sql .= ' AND EntityType = ? AND EntityID = ?'; $params = [$entityType, $entityId]; }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY EntityType, EntityID, AttachmentID');
        $stmt->execute($params);
        $position = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $att) {
            $key = $att['EntityType'] . '#' . $att['EntityID'];
            $position[$key] = ($position[$key] ?? 0) + 1;
            if (empty($att['GoogleDriveFileID'])) continue;
            $name = $this->driveFileName($att['EntityType'], $att['EntityID'], $att['OriginalName'] ?: $att['FileName'], $position[$key]);
            try {
                $this->gdrive->renameFile($att['GoogleDriveFileID'], $name);
                $out['renamed']++;
            } catch (Exception $e) {
                $out['failed']++;
                $out['errors'][] = '#' . $att['AttachmentID'] . ': ' . $e->getMessage();
            }
        }
        return $out;
    }
    
    public function uploadProofs($files, $entityType, $entityId, $uploadedBy) {
        $results = ['success' => [], 'errors' => []];
        $currentCount = $this->getAttachmentCount($entityType, $entityId);
        $normalizedFiles = $this->normalizeFiles($files);
        if (empty($normalizedFiles)) return $results;
        
        if ($currentCount + count($normalizedFiles) > self::MAX_FILES_PER_ENTITY) {
            $remaining = self::MAX_FILES_PER_ENTITY - $currentCount;
            $results['errors'][] = "Max " . self::MAX_FILES_PER_ENTITY . " files. You can add {$remaining} more.";
            $normalizedFiles = array_slice($normalizedFiles, 0, $remaining);
        }
        
        $folderType = ($entityType === 'expense') ? 'expenses' : 'events';
        foreach ($normalizedFiles as $file) {
            try {
                $this->validateFile($file);
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $uniqueName = $entityType . '_' . $entityId . '_' . time() . '_' . uniqid() . '.' . strtolower($ext);
                $localDir = $this->localUploadDir . '/' . $folderType;
                if (!is_dir($localDir)) mkdir($localDir, 0755, true);
                $localPath = $localDir . '/' . $uniqueName;
                if (!move_uploaded_file($file['tmp_name'], $localPath)) throw new Exception('Failed to save file locally');
                
                $gdriveFileId = null; $gdriveUrl = null; $gdriveThumbnail = null;
                $uploadStatus = 'uploaded'; $uploadError = null;

                // Auto-sync: upload to Drive if configured AND auto-sync is enabled
                $autoSync = '0';
                try {
                    $as = $this->pdo->prepare("SELECT SettingValue FROM system_settings WHERE SettingKey='gdrive_auto_sync' LIMIT 1");
                    $as->execute();
                    $autoSync = $as->fetchColumn() ?: '0';
                } catch (Exception $e) {}

                if ($this->isGDriveEnabled() && $autoSync === '1') {
                    try {
                        $driveName = $this->driveFileName($entityType, $entityId, $file['name'], $this->getAttachmentCount($entityType, $entityId) + 1);
                        $r = $this->gdrive->uploadFile($localPath, $driveName, $file['type'], $folderType);
                        $gdriveFileId = $r['fileId']; $gdriveUrl = $r['webViewLink']; $gdriveThumbnail = $r['thumbnailLink'];
                    } catch (Exception $e) {
                        $uploadError = $e->getMessage(); $uploadStatus = 'failed';
                    }
                }
                
                $stmt = $this->pdo->prepare("INSERT INTO proof_attachments (EntityType, EntityID, FileName, OriginalName, MimeType, FileSize, GoogleDriveFileID, GoogleDriveURL, GoogleDriveThumbnail, LocalPath, UploadStatus, UploadError, UploadedBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$entityType, $entityId, $uniqueName, $file['name'], $file['type'], $file['size'], $gdriveFileId, $gdriveUrl, $gdriveThumbnail, 'uploads/proofs/' . $folderType . '/' . $uniqueName, $uploadStatus, $uploadError, $uploadedBy]);
                
                $results['success'][] = ['attachmentId' => $this->pdo->lastInsertId(), 'originalName' => $file['name'], 'fileName' => $uniqueName, 'gdriveUrl' => $gdriveUrl, 'localPath' => 'uploads/proofs/' . $folderType . '/' . $uniqueName, 'uploadStatus' => $uploadStatus, 'size' => $file['size']];
                $this->updateProofCount($entityType, $entityId);
            } catch (Exception $e) {
                $results['errors'][] = $file['name'] . ': ' . $e->getMessage();
            }
        }
        return $results;
    }
    
    private function validateFile($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload error code: ' . $file['error']);
        if ($file['size'] > self::MAX_FILE_SIZE) throw new Exception('File too large (max 10MB)');
        if (!isset(self::ALLOWED_TYPES[$file['type']])) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, array_values(self::ALLOWED_TYPES))) throw new Exception('File type not allowed');
        }
    }
    
    private function normalizeFiles($files) {
        $n = [];
        if (!isset($files['name'])) return $n;
        if (is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                $n[] = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
            }
        } else { if ($files['error'] !== UPLOAD_ERR_NO_FILE) $n[] = $files; }
        return $n;
    }
    
    public function getAttachments($entityType, $entityId) {
        $stmt = $this->pdo->prepare("SELECT * FROM proof_attachments WHERE EntityType = ? AND EntityID = ? AND UploadStatus != 'deleted' ORDER BY UploadedAt ASC");
        $stmt->execute([$entityType, $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getAttachmentCount($entityType, $entityId) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM proof_attachments WHERE EntityType = ? AND EntityID = ? AND UploadStatus != 'deleted'");
        $stmt->execute([$entityType, $entityId]);
        return (int) $stmt->fetchColumn();
    }
    
    public function deleteAttachment($attachmentId, $deletedBy = null) {
        $stmt = $this->pdo->prepare("SELECT * FROM proof_attachments WHERE AttachmentID = ?");
        $stmt->execute([$attachmentId]);
        $att = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$att) throw new Exception('Attachment not found');
        
        if (!empty($att['GoogleDriveFileID']) && $this->isGDriveEnabled()) {
            try { $this->gdrive->deleteFile($att['GoogleDriveFileID']); } catch (Exception $e) {}
        }
        if (!empty($att['LocalPath'])) {
            $fp = dirname(dirname(__FILE__)) . '/' . $att['LocalPath'];
            if (file_exists($fp)) unlink($fp);
        }
        $this->pdo->prepare("UPDATE proof_attachments SET UploadStatus = 'deleted', DeletedAt = NOW() WHERE AttachmentID = ?")->execute([$attachmentId]);
        $this->updateProofCount($att['EntityType'], $att['EntityID']);
        return true;
    }
    
    /** Stored proof count, only where the table has that column (the pages count attachments themselves) */
    private function updateProofCount($entityType, $entityId) {
        static $hasColumn = [];
        $map = ['expense' => ['expenses', 'ExpenseID'], 'event_transaction' => ['event_transactions', 'TransactionID']];
        if (!isset($map[$entityType])) return;
        [$table, $key] = $map[$entityType];
        if (!isset($hasColumn[$table])) {
            try {
                $hasColumn[$table] = (bool)$this->pdo->query("SHOW COLUMNS FROM `$table` LIKE 'ProofCount'")->fetch();
            } catch (Exception $e) {
                $hasColumn[$table] = false;
            }
        }
        if (!$hasColumn[$table]) return;
        $count = $this->getAttachmentCount($entityType, $entityId);
        $this->pdo->prepare("UPDATE `$table` SET ProofCount = ? WHERE `$key` = ?")->execute([$count, $entityId]);
    }
    
    public function retryFailedUploads() {
        $failed = $this->pdo->query("SELECT * FROM proof_attachments WHERE UploadStatus = 'failed' AND LocalPath IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $retried = 0;
        foreach ($failed as $att) {
            $fp = dirname(dirname(__FILE__)) . '/' . $att['LocalPath'];
            if (!file_exists($fp)) continue;
            try {
                $name = $this->driveFileName($att['EntityType'], $att['EntityID'], $att['OriginalName'] ?: $att['FileName'], $this->attachmentNumber($att));
                $r = $this->gdrive->uploadFile($fp, $name, $att['MimeType'], ($att['EntityType'] === 'expense') ? 'expenses' : 'events');
                $this->pdo->prepare("UPDATE proof_attachments SET GoogleDriveFileID=?, GoogleDriveURL=?, GoogleDriveThumbnail=?, UploadStatus='uploaded', UploadError=NULL WHERE AttachmentID=?")->execute([$r['fileId'], $r['webViewLink'], $r['thumbnailLink'], $att['AttachmentID']]);
                $retried++;
            } catch (Exception $e) {}
        }
        return $retried;
    }
    
    public function getFileUrl($att) { return !empty($att['GoogleDriveURL']) ? $att['GoogleDriveURL'] : (!empty($att['LocalPath']) ? '../../' . $att['LocalPath'] : null); }
    public function getThumbnailUrl($att) { if (!empty($att['GoogleDriveThumbnail'])) return $att['GoogleDriveThumbnail']; if (strpos($att['MimeType'], 'image/') === 0 && !empty($att['LocalPath'])) return '../../' . $att['LocalPath']; return null; }
    public static function getFileIcon($mimeType) {
        $i = ['image/jpeg'=>'fa-file-image','image/png'=>'fa-file-image','image/gif'=>'fa-file-image','image/webp'=>'fa-file-image','application/pdf'=>'fa-file-pdf','application/msword'=>'fa-file-word','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'fa-file-word','application/vnd.ms-excel'=>'fa-file-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'fa-file-excel','text/csv'=>'fa-file-csv','text/plain'=>'fa-file-alt'];
        return $i[$mimeType] ?? 'fa-file';
    }
    public static function formatSize($b) { if ($b >= 1048576) return round($b/1048576,1).' MB'; if ($b >= 1024) return round($b/1024,1).' KB'; return $b.' bytes'; }
}
