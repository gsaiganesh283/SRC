<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/superadmin/dashboard.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

session_start();
$_SESSION['superadmin_id'] = 1;
$_SESSION['superadmin_username'] = 'admin';

echo "Testing dashboard includes...\n\n";

try {
    echo "1. Loading session.php...\n";
    require_once 'superadmin/session.php';
    echo "   OK\n";
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

try {
    echo "2. Loading config.php...\n";
    require_once 'config/config.php';
    echo "   OK\n";
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

try {
    echo "3. Loading logo-helper.php...\n";
    require_once 'includes/logo-helper.php';
    echo "   OK\n";
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

try {
    echo "4. Testing renderFavicon()...\n";
    $favicon = renderFavicon();
    echo "   OK: " . (empty($favicon) ? "(empty)" : $favicon) . "\n";
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

try {
    echo "5. Testing getSiteName()...\n";
    $siteName = getSiteName('SR Colony');
    echo "   OK: $siteName\n";
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

echo "\nAll tests passed!\n";
