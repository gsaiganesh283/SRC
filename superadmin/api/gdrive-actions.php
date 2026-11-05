<?php
/**
 * Google Drive AJAX Actions API
 * Handles: OAuth Desktop flow, folder browse/create, account management
 */
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['superadmin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once '../../config/config.php';
require_once '../../includes/google-drive-helper.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // =====================
        // OAUTH DESKTOP FLOW
        // =====================
        
        case 'get_auth_url':
            $clientId = trim($_POST['client_id'] ?? '');
            if (empty($clientId)) throw new Exception('Client ID is required');
            $authUrl = GoogleDriveHelper::getAuthUrl($clientId);
            echo json_encode(['success' => true, 'auth_url' => $authUrl]);
            break;

        case 'exchange_code':
            $clientId     = trim($_POST['client_id'] ?? '');
            $clientSecret = trim($_POST['client_secret'] ?? '');
            $code         = trim($_POST['code'] ?? '');
            $accountName  = trim($_POST['account_name'] ?? '');
            
            if (empty($clientId) || empty($clientSecret) || empty($code)) {
                throw new Exception('Client ID, Client Secret, and Authorization Code are all required');
            }
            
            // Exchange code for tokens
            $tokenData = GoogleDriveHelper::exchangeCode($clientId, $clientSecret, $code);
            $accessToken  = $tokenData['access_token'];
            $refreshToken = $tokenData['refresh_token'] ?? '';
            $expiresIn    = $tokenData['expires_in'] ?? 3600;
            
            // Get user info
            $userInfo = GoogleDriveHelper::getUserInfo($accessToken);
            $email = $userInfo['email'] ?? '';
            $name  = $accountName ?: ($userInfo['name'] ?? $email);
            
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn - 60);
            
            // Check if account already exists with this email
            $stmt = $pdo->prepare("SELECT SettingID FROM google_drive_settings WHERE AccountEmail = ? OR ClientID = ?");
            $stmt->execute([$email, $clientId]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                // Update existing
                $stmt = $pdo->prepare("UPDATE google_drive_settings SET 
                    AccountName = ?, AccountEmail = ?, ClientID = ?, ClientSecret = ?,
                    RefreshToken = ?, AccessToken = ?, TokenExpiresAt = ?, ConnectedAt = NOW(),
                    AuthMethod = 'oauth2', IsActive = 1, UpdatedAt = NOW()
                    WHERE SettingID = ?");
                $stmt->execute([$name, $email, $clientId, $clientSecret, $refreshToken, $accessToken, $expiresAt, $existing['SettingID']]);
                $settingId = $existing['SettingID'];
            } else {
                // Insert new account
                $stmt = $pdo->prepare("INSERT INTO google_drive_settings 
                    (AccountName, AccountEmail, ClientID, ClientSecret, RefreshToken, AccessToken, TokenExpiresAt,
                     ConnectedAt, AuthMethod, IsActive, IsDefault, CreatedAt, UpdatedAt)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'oauth2', 1, 0, NOW(), NOW())");
                $stmt->execute([$name, $email, $clientId, $clientSecret, $refreshToken, $accessToken, $expiresAt]);
                $settingId = $pdo->lastInsertId();
                
                // If first account, make it default
                $count = $pdo->query("SELECT COUNT(*) FROM google_drive_settings")->fetchColumn();
                if ($count == 1) {
                    $pdo->prepare("UPDATE google_drive_settings SET IsDefault = 1 WHERE SettingID = ?")->execute([$settingId]);
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Account connected successfully!',
                'account' => [
                    'id'    => $settingId,
                    'name'  => $name,
                    'email' => $email,
                ]
            ]);
            break;

        // =====================
        // ACCOUNT MANAGEMENT
        // =====================
        
        case 'disconnect_account':
            $accountId = intval($_POST['account_id'] ?? 0);
            if (!$accountId) throw new Exception('Account ID required');
            
            $stmt = $pdo->prepare("SELECT * FROM google_drive_settings WHERE SettingID = ?");
            $stmt->execute([$accountId]);
            $acct = $stmt->fetch();
            if (!$acct) throw new Exception('Account not found');
            
            // Revoke token
            if (!empty($acct['RefreshToken'])) {
                try { GoogleDriveHelper::revokeToken($acct['RefreshToken']); } catch (Exception $e) {}
            }
            
            // Clear tokens but keep credentials
            $stmt = $pdo->prepare("UPDATE google_drive_settings SET 
                RefreshToken = NULL, AccessToken = NULL, TokenExpiresAt = NULL, 
                ConnectedAt = NULL, IsActive = 0, UpdatedAt = NOW()
                WHERE SettingID = ?");
            $stmt->execute([$accountId]);
            
            echo json_encode(['success' => true, 'message' => 'Account disconnected']);
            break;

        case 'remove_account':
            $accountId = intval($_POST['account_id'] ?? 0);
            if (!$accountId) throw new Exception('Account ID required');
            
            $stmt = $pdo->prepare("SELECT * FROM google_drive_settings WHERE SettingID = ?");
            $stmt->execute([$accountId]);
            $acct = $stmt->fetch();
            if (!$acct) throw new Exception('Account not found');
            
            // Revoke token
            if (!empty($acct['RefreshToken'])) {
                try { GoogleDriveHelper::revokeToken($acct['RefreshToken']); } catch (Exception $e) {}
            }
            
            $pdo->prepare("DELETE FROM google_drive_settings WHERE SettingID = ?")->execute([$accountId]);
            
            // If was default, set another as default
            if ($acct['IsDefault']) {
                $pdo->exec("UPDATE google_drive_settings SET IsDefault = 1 ORDER BY SettingID ASC LIMIT 1");
            }
            
            echo json_encode(['success' => true, 'message' => 'Account removed']);
            break;

        case 'set_default_account':
            $accountId = intval($_POST['account_id'] ?? 0);
            if (!$accountId) throw new Exception('Account ID required');
            $pdo->exec("UPDATE google_drive_settings SET IsDefault = 0");
            $pdo->prepare("UPDATE google_drive_settings SET IsDefault = 1 WHERE SettingID = ?")->execute([$accountId]);
            echo json_encode(['success' => true, 'message' => 'Default account updated']);
            break;

        case 'set_upload_folder':
            $accountId = intval($_POST['account_id'] ?? 0);
            $folderId  = trim($_POST['folder_id'] ?? '');
            if (!$accountId) throw new Exception('Account ID required');
            $pdo->prepare("UPDATE google_drive_settings SET FolderID = ?, UpdatedAt = NOW() WHERE SettingID = ?")
                ->execute([$folderId ?: null, $accountId]);
            echo json_encode(['success' => true, 'message' => 'Upload folder set']);
            break;

        // =====================
        // FOLDER OPERATIONS
        // =====================
        
        case 'list_folders':
            $accountId = intval($_POST['account_id'] ?? 0);
            $parentId  = trim($_POST['parent_id'] ?? 'root');
            if (!$accountId) throw new Exception('Account ID required');
            
            $stmt = $pdo->prepare("SELECT * FROM google_drive_settings WHERE SettingID = ?");
            $stmt->execute([$accountId]);
            $acct = $stmt->fetch();
            if (!$acct) throw new Exception('Account not found');
            
            $helper = new GoogleDriveHelper($pdo, $acct);
            $result = $helper->listFolders($parentId ?: 'root');
            
            echo json_encode(['success' => true, 'folders' => $result['files'] ?? [], 'parent_id' => $parentId]);
            break;

        case 'create_folder':
            $accountId  = intval($_POST['account_id'] ?? 0);
            $folderName = trim($_POST['folder_name'] ?? '');
            $parentId   = trim($_POST['parent_id'] ?? 'root');
            if (!$accountId) throw new Exception('Account ID required');
            if (empty($folderName)) throw new Exception('Folder name is required');
            
            $stmt = $pdo->prepare("SELECT * FROM google_drive_settings WHERE SettingID = ?");
            $stmt->execute([$accountId]);
            $acct = $stmt->fetch();
            if (!$acct) throw new Exception('Account not found');
            
            $helper = new GoogleDriveHelper($pdo, $acct);
            $folder = $helper->createFolder($folderName, $parentId ?: 'root');
            
            echo json_encode(['success' => true, 'folder' => $folder, 'message' => "Folder '{$folderName}' created"]);
            break;

        case 'auto_create_folders':
            $accountId = intval($_POST['account_id'] ?? 0);
            if (!$accountId) throw new Exception('Account ID required');
            
            $stmt = $pdo->prepare("SELECT * FROM google_drive_settings WHERE SettingID = ?");
            $stmt->execute([$accountId]);
            $acct = $stmt->fetch();
            if (!$acct) throw new Exception('Account not found');
            
            $helper = new GoogleDriveHelper($pdo, $acct);
            $parentId = $acct['FolderID'] ?: 'root';
            
            $expFolder = $helper->createFolder('SR Colony - Expenses', $parentId);
            $evtFolder = $helper->createFolder('SR Colony - Event Proofs', $parentId);
            
            $expId = $expFolder['id'] ?? null;
            $evtId = $evtFolder['id'] ?? null;
            
            if ($expId && $evtId) {
                $pdo->prepare("UPDATE google_drive_settings SET ExpensesFolderID = ?, EventsFolderID = ?, UpdatedAt = NOW() WHERE SettingID = ?")
                    ->execute([$expId, $evtId, $accountId]);
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Folders created successfully',
                'expenses_folder_id' => $expId,
                'events_folder_id'   => $evtId,
            ]);
            break;

        case 'get_storage_quota':
            $accountId = intval($_POST['account_id'] ?? 0);
            if (!$accountId) throw new Exception('Account ID required');
            
            $stmt = $pdo->prepare("SELECT * FROM google_drive_settings WHERE SettingID = ?");
            $stmt->execute([$accountId]);
            $acct = $stmt->fetch();
            if (!$acct) throw new Exception('Account not found');
            
            $helper = new GoogleDriveHelper($pdo, $acct);
            $quota = $helper->getStorageQuota();
            
            echo json_encode(['success' => true, 'quota' => $quota]);
            break;

        case 'test_connection':
            $accountId = intval($_POST['account_id'] ?? 0);
            if (!$accountId) throw new Exception('Account ID required');
            
            $stmt = $pdo->prepare("SELECT * FROM google_drive_settings WHERE SettingID = ?");
            $stmt->execute([$accountId]);
            $acct = $stmt->fetch();
            if (!$acct) throw new Exception('Account not found');
            
            $helper = new GoogleDriveHelper($pdo, $acct);
            $result = $helper->testConnection();
            
            echo json_encode(['success' => $result['success'], 'message' => $result['message']]);
            break;

        case 'get_stats':
            $totalAccounts   = (int) $pdo->query("SELECT COUNT(*) FROM google_drive_settings")->fetchColumn();
            $connectedAccounts = (int) $pdo->query("SELECT COUNT(*) FROM google_drive_settings WHERE IsActive = 1 AND RefreshToken IS NOT NULL AND RefreshToken != ''")->fetchColumn();
            
            $onGDrive = 0;
            $localOnly = 0;
            try {
                $onGDrive  = (int) $pdo->query("SELECT COUNT(*) FROM proof_attachments WHERE GoogleDriveFileID IS NOT NULL AND GoogleDriveFileID != '' AND UploadStatus = 'uploaded'")->fetchColumn();
                $localOnly = (int) $pdo->query("SELECT COUNT(*) FROM proof_attachments WHERE (GoogleDriveFileID IS NULL OR GoogleDriveFileID = '') AND UploadStatus != 'deleted'")->fetchColumn();
            } catch (Exception $e) {}
            
            echo json_encode([
                'success'   => true,
                'accounts'  => $totalAccounts,
                'connected' => $connectedAccounts,
                'on_gdrive' => $onGDrive,
                'local_only'=> $localOnly,
            ]);
            break;

        default:
            throw new Exception('Unknown action: ' . htmlspecialchars($action));
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
