<?php
// Simple status check - no dependencies
echo "<!DOCTYPE html>";
echo "<html><head><title>Site Status Check</title></head><body>";
echo "<h1>✓ PHP is working</h1>";
echo "<p>PHP Version: " . phpversion() . "</p>";
echo "<p>Current Time: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>Server: " . $_SERVER['SERVER_SOFTWARE'] . "</p>";

// Check if config file exists
if (file_exists(__DIR__ . '/config/config.php')) {
    echo "<p>✓ config.php exists</p>";
    
    // Try to include it
    try {
        require_once __DIR__ . '/config/config.php';
        echo "<p>✓ config.php loaded</p>";
        echo "<p>DB_HOST: " . DB_HOST . "</p>";
        echo "<p>DB_NAME: " . DB_NAME . "</p>";
        
        // Try database connection
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS
            );
            echo "<p>✓ Database connected successfully!</p>";
        } catch (PDOException $e) {
            echo "<p>✗ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } catch (Exception $e) {
        echo "<p>✗ Error loading config: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p>✗ config.php NOT FOUND</p>";
}

echo "<hr>";
echo "<h2>Recent Files Modified:</h2>";
$files = [
    'config/config.php',
    'includes/functions.php',
    'member/dashboard-new.php',
    'admin/dashboard-new.php',
    'superadmin/dashboard.php'
];

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        echo "<p>✓ $file - Last modified: " . date('Y-m-d H:i:s', filemtime($path)) . "</p>";
    } else {
        echo "<p>✗ $file - NOT FOUND</p>";
    }
}

echo "</body></html>";
?>
