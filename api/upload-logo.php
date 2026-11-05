<?php
/**
 * Logo Upload API
 * Handles uploading and saving logo files for system settings
 */

session_start();
require_once '../config/config.php';

// Check if superadmin is logged in
if (!isset($_SESSION['superadmin_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

// Handle logo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    try {
        // Get the logo type (website_logo, system_logo, favicon)
        $logoType = $_POST['logo_type'] ?? '';
        $validTypes = ['website_logo', 'system_logo', 'favicon'];
        
        if (!in_array($logoType, $validTypes)) {
            throw new Exception('Invalid logo type specified');
        }
        
        // Check if file was uploaded
        if (!isset($_FILES['logo_file']) || $_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];
            $errorCode = $_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new Exception($uploadErrors[$errorCode] ?? 'File upload failed');
        }
        
        $file = $_FILES['logo_file'];
        
        // Validate file type
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon'];
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'ico'];
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($mimeType, $allowedMimes) && !in_array($ext, $allowedExts)) {
            throw new Exception('Invalid file type. Allowed: JPG, PNG, GIF, SVG, ICO');
        }
        
        // Validate file size (max 2MB)
        $maxSize = 2 * 1024 * 1024; // 2MB
        if ($file['size'] > $maxSize) {
            throw new Exception('File size exceeds 2MB limit');
        }
        
        // Create upload directory if it doesn't exist
        $uploadDir = dirname(__DIR__) . '/assets/uploads/logos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate unique filename
        $newFilename = $logoType . '_' . time() . '.' . $ext;
        $uploadPath = $uploadDir . $newFilename;
        
        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            throw new Exception('Failed to save uploaded file');
        }
        
        // Generate URL for the logo
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        
        // Calculate base path
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = dirname($scriptDir); // Go up from /api to root
        $logoUrl = $basePath . '/assets/uploads/logos/' . $newFilename;
        $logoUrl = str_replace('//', '/', $logoUrl);
        
        // Get old logo path to delete
        $stmt = $pdo->prepare("SELECT SettingValue FROM system_settings WHERE SettingKey = ?");
        $stmt->execute([$logoType]);
        $oldLogo = $stmt->fetchColumn();
        
        // Update database
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (SettingKey, SettingValue, UpdatedAt, UpdatedBy)
            VALUES (?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE SettingValue = VALUES(SettingValue), UpdatedAt = NOW(), UpdatedBy = VALUES(UpdatedBy)
        ");
        $stmt->execute([$logoType, $logoUrl, $_SESSION['superadmin_id']]);
        
        // Delete old logo file if exists
        if (!empty($oldLogo)) {
            $oldPath = dirname(__DIR__) . parse_url($oldLogo, PHP_URL_PATH);
            // Normalize path
            $oldPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $oldPath);
            if (file_exists($oldPath) && strpos($oldPath, 'logos') !== false) {
                @unlink($oldPath);
            }
        }
        
        // Log the change
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (UserID, UserType, Action, TableName, IPAddress)
            VALUES (?, 'SuperAdmin', 'update', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['superadmin_id'],
            "Updated {$logoType} logo",
            $_SERVER['REMOTE_ADDR']
        ]);
        
        $response['success'] = true;
        $response['message'] = 'Logo uploaded successfully';
        $response['logo_url'] = $logoUrl;
        
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// Handle logo removal
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || 
    ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove')) {
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        $logoType = $_POST['logo_type'] ?? $_GET['logo_type'] ?? '';
        $validTypes = ['website_logo', 'system_logo', 'favicon'];
        
        if (!in_array($logoType, $validTypes)) {
            throw new Exception('Invalid logo type specified');
        }
        
        // Get current logo path
        $stmt = $pdo->prepare("SELECT SettingValue FROM system_settings WHERE SettingKey = ?");
        $stmt->execute([$logoType]);
        $currentLogo = $stmt->fetchColumn();
        
        // Delete file if exists
        if (!empty($currentLogo)) {
            $filePath = dirname(__DIR__) . parse_url($currentLogo, PHP_URL_PATH);
            $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
            if (file_exists($filePath) && strpos($filePath, 'logos') !== false) {
                @unlink($filePath);
            }
        }
        
        // Clear database entry
        $stmt = $pdo->prepare("
            UPDATE system_settings 
            SET SettingValue = '', UpdatedAt = NOW(), UpdatedBy = ?
            WHERE SettingKey = ?
        ");
        $stmt->execute([$_SESSION['superadmin_id'], $logoType]);
        
        // Log the change
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (UserID, UserType, Action, TableName, IPAddress)
            VALUES (?, 'SuperAdmin', 'delete', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['superadmin_id'],
            "Removed {$logoType} logo",
            $_SERVER['REMOTE_ADDR']
        ]);
        
        $response['success'] = true;
        $response['message'] = 'Logo removed successfully';
        
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// Invalid request method
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
