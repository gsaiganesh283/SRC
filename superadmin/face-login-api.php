<?php
/**
 * Face Login API Endpoint
 * Receives a face descriptor from the client and attempts to match it
 * against stored face descriptors in the database.
 * Returns JSON response with authentication result.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

session_start();
require_once '../config/config.php';
require_once '../includes/face-helper.php';

// Ensure face columns exist in database
FaceHelper::ensureColumnsExist($pdo);

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Rate limiting using session
$maxAttempts = 10;
$windowSeconds = 300; // 5 minutes
$now = time();

if (!isset($_SESSION['face_login_attempts'])) {
    $_SESSION['face_login_attempts'] = [];
}

// Clean old attempts
$_SESSION['face_login_attempts'] = array_filter(
    $_SESSION['face_login_attempts'],
    function ($timestamp) use ($now, $windowSeconds) {
        return ($now - $timestamp) < $windowSeconds;
    }
);

if (count($_SESSION['face_login_attempts']) >= $maxAttempts) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Too many face login attempts. Please wait 5 minutes and try again.'
    ]);
    exit;
}

// Record this attempt
$_SESSION['face_login_attempts'][] = $now;

// Get the face descriptor from the request
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data || !isset($data['descriptor'])) {
    echo json_encode(['success' => false, 'error' => 'Missing face descriptor data']);
    exit;
}

// Validate the descriptor
$descriptor = FaceHelper::validateDescriptor($data['descriptor']);
if ($descriptor === false) {
    echo json_encode(['success' => false, 'error' => 'Invalid face descriptor format']);
    exit;
}

try {
    // Check if any superadmin has face auth enabled
    if (!FaceHelper::anyFaceAuthEnabled($pdo)) {
        echo json_encode(['success' => false, 'error' => 'Face Lock is not configured for any administrator']);
        exit;
    }
    
    // Try to find a matching superadmin
    $match = FaceHelper::findMatchingAdmin($pdo, $descriptor);
    
    if ($match) {
        $admin = $match['superadmin'];
        $distance = $match['distance'];
        
        // Fetch full admin record for session data
        $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE SuperAdminID = ? AND Status = 'Active'");
        $stmt->execute([$admin['SuperAdminID']]);
        $superadmin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($superadmin) {
            // Set session variables (same as regular login)
            $_SESSION['superadmin_id'] = $superadmin['SuperAdminID'];
            $_SESSION['superadmin_username'] = $superadmin['Username'];
            $_SESSION['superadmin_name'] = $superadmin['FullName'] ?? 'Super Admin';
            $_SESSION['user_type'] = 'SuperAdmin';
            
            // Update last login
            $stmt = $pdo->prepare("UPDATE super_admins SET LastLogin = NOW() WHERE SuperAdminID = ?");
            $stmt->execute([$superadmin['SuperAdminID']]);
            
            // Log the login
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (UserType, UserID, Username, Action, IPAddress) 
                    VALUES ('SuperAdmin', ?, ?, 'Logged In via Face Lock', ?)
                ");
                $stmt->execute([$superadmin['SuperAdminID'], $superadmin['Username'], $ip]);
            } catch (Exception $e) {
                error_log('Audit log insert failed: ' . $e->getMessage());
            }
            
            // Clear face login attempts on success
            unset($_SESSION['face_login_attempts']);
            
            echo json_encode([
                'success' => true,
                'message' => 'Welcome, ' . htmlspecialchars($admin['face_name'] ?? $superadmin['FullName']),
                'name' => $admin['face_name'] ?? $superadmin['FullName'],
                'redirect' => 'dashboard.php',
                'confidence' => round((1 - $distance / FaceHelper::MATCH_THRESHOLD) * 100, 1)
            ]);
            exit;
        }
    }
    
    // No match found
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (UserType, UserID, Username, Action, IPAddress) 
            VALUES ('SuperAdmin', 0, 'Unknown', 'Failed Face Lock Login Attempt', ?)
        ");
        $stmt->execute([$ip]);
    } catch (Exception $e) {
        error_log('Audit log insert failed: ' . $e->getMessage());
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'Face not recognized. Please try again or use password login.'
    ]);
    
} catch (Exception $e) {
    error_log('Face login error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred during face verification. Please try again.'
    ]);
}
