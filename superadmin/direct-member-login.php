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

$member_id = $_GET['id'] ?? null;

if (!$member_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid member ID']);
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
    
    // Fetch member details
    $stmt = $pdo->prepare("SELECT * FROM members WHERE MemberID = ?");
    $stmt->execute([$member_id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        http_response_code(404);
        echo json_encode(['error' => 'Member not found']);
        exit();
    }
    
    // Check if member is active
    if ($member['Status'] !== 'Active') {
        http_response_code(400);
        echo json_encode(['error' => 'Cannot login as inactive member']);
        exit();
    }
    
    // Log the superadmin action
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress) 
        VALUES ('SuperAdmin', ?, ?, 'Direct Member Login', 'members', ?, ?)
    ");
    $stmt->execute([
        $superadmin_id, 
        $superadmin_username, 
        $member_id, 
        $ip
    ]);
    
    // Generate a secure token for member login
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 300); // 5 minutes from now
    
    // Store token in database
    $stmt = $pdo->prepare("
        INSERT INTO member_login_tokens (MemberID, Token, ExpiresAt, CreatedBy) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE Token = ?, ExpiresAt = ?, CreatedBy = ?
    ");
    $stmt->execute([$member_id, $token, $expires, $superadmin_id, $token, $expires, $superadmin_id]);
    
    // Return the token URL
    echo json_encode([
        'success' => true,
        'url' => 'member-auto-login.php?token=' . $token,
        'member_name' => $member['MemberName'] ?? 'Member'
    ]);
    exit();
    
} catch (Exception $e) {
    error_log("Direct member login error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'Server error. Please try again.']);
    exit();
}

