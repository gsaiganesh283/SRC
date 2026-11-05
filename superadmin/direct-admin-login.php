<?php
// Prevent any output before JSON
ob_start();

// Start session before any includes
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include required files
require_once '../config/config.php';

// Check superadmin session
if (!isset($_SESSION['superadmin_id'])) {
    // Get superadmin session from superadmin's session file
    require_once 'session.php';
}

// Clear any buffered output from includes
ob_end_clean();

// Now start clean output with JSON header
header('Content-Type: application/json; charset=utf-8');

// Only superadmin can access this
if (!isset($_SESSION['superadmin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

$admin_id = $_GET['id'] ?? null;

if (!$admin_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid admin ID']);
    exit();
}

try {
    // Get superadmin info from session
    $superadmin_id = $_SESSION['superadmin_id'] ?? null;
    $superadmin_username = $_SESSION['superadmin_username'] ?? 'Unknown';
    
    if (!$superadmin_id) {
        http_response_code(403);
        echo json_encode(['error' => 'Superadmin session not found']);
        exit();
    }
    
    // Fetch admin details
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE AdminID = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        http_response_code(404);
        echo json_encode(['error' => 'Admin not found']);
        exit();
    }
    
    // Check if admin is active
    if ($admin['Status'] !== 'Active') {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot login as inactive admin']);
        exit();
    }
    
    // Log the superadmin action
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress) 
        VALUES ('SuperAdmin', ?, ?, 'Direct Admin Login', 'admins', ?, ?)
    ");
    $stmt->execute([
        $superadmin_id, 
        $superadmin_username, 
        $admin_id, 
        $ip
    ]);
    
    // Generate a secure token for admin login
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 300); // 5 minutes from now
    
    // Create login tokens table if not exists (reuse member table structure)
    $stmt = $pdo->prepare("
        CREATE TABLE IF NOT EXISTS admin_login_tokens (
            TokenID INT AUTO_INCREMENT PRIMARY KEY,
            AdminID INT NOT NULL,
            Token VARCHAR(64) NOT NULL UNIQUE,
            ExpiresAt DATETIME NOT NULL,
            CreatedBy INT NOT NULL,
            CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (AdminID) REFERENCES admins(AdminID) ON DELETE CASCADE,
            INDEX idx_token (Token),
            INDEX idx_expires (ExpiresAt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $stmt->execute();
    
    // Store token in database
    $stmt = $pdo->prepare("
        INSERT INTO admin_login_tokens (AdminID, Token, ExpiresAt, CreatedBy) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE Token = ?, ExpiresAt = ?, CreatedBy = ?
    ");
    $stmt->execute([$admin_id, $token, $expires, $superadmin_id, $token, $expires, $superadmin_id]);
    
    // Return the token URL
    echo json_encode([
        'success' => true,
        'url' => 'admin-auto-login.php?token=' . $token,
        'admin_name' => $admin['FullName'] ?? 'Admin'
    ]);
    exit();
    
} catch (Exception $e) {
    error_log("Direct admin login error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Server error. Please try again.']);
    exit();
}
