<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<h1>Debug Test</h1>";
echo "<p>PHP is working!</p>";

echo "<h2>Session Test</h2>";
session_start();
echo "<p>Session started. ID: " . session_id() . "</p>";

echo "<h2>Database Test</h2>";
try {
    require_once 'config/config.php';
    echo "<p style='color:green;'>Database connection: OK</p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM system_settings");
    $row = $stmt->fetch();
    echo "<p>Settings count: " . $row['cnt'] . "</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Database error: " . $e->getMessage() . "</p>";
}

echo "<h2>Logo Helper Test</h2>";
try {
    require_once 'includes/logo-helper.php';
    echo "<p style='color:green;'>Logo helper loaded: OK</p>";
    
    $siteName = getSiteName('Default');
    echo "<p>Site name: " . htmlspecialchars($siteName) . "</p>";
    
    $favicon = renderFavicon();
    echo "<p>Favicon: " . (empty($favicon) ? "(not set)" : htmlspecialchars($favicon)) . "</p>";
} catch (Exception $e) {
    echo "<p style='color:red;'>Logo helper error: " . $e->getMessage() . "</p>";
}

echo "<h2>All Tests Complete</h2>";
echo "<p><a href='superadmin/dashboard.php'>Go to Dashboard</a></p>";
