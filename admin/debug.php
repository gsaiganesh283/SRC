<?php
// Debug page to check admin session and permissions
require_once '../config/config.php';
require_once '../includes/session.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Admin Debug Information</h1>";
echo "<pre>";

echo "=== SESSION DATA ===\n";
print_r($_SESSION);

echo "\n=== SERVER INFO ===\n";
echo "Current Page: " . basename($_SERVER['PHP_SELF']) . "\n";
echo "Request URI: " . $_SERVER['REQUEST_URI'] . "\n";

if (isset($_SESSION['admin_id'])) {
    echo "\n=== ADMIN DATA ===\n";
    try {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE AdminID = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        print_r($admin);
        
        echo "\n=== ADMIN PERMISSIONS ===\n";
        $stmt = $pdo->prepare("SELECT * FROM admin_permissions WHERE AdminID = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $permissions = $stmt->fetch(PDO::FETCH_ASSOC);
        print_r($permissions);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "\n=== NO ADMIN SESSION ===\n";
}

echo "\n=== AVAILABLE FUNCTIONS ===\n";
echo "requireAdmin exists: " . (function_exists('requireAdmin') ? 'YES' : 'NO') . "\n";
echo "requireAdminPermission exists: " . (function_exists('requireAdminPermission') ? 'YES' : 'NO') . "\n";
echo "hasAdminPermission exists: " . (function_exists('hasAdminPermission') ? 'YES' : 'NO') . "\n";

echo "</pre>";
?>
