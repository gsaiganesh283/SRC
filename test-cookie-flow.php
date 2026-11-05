<?php
echo "<h2>Cookie Flow Test</h2>";

echo "<h3>Step 1: Check Cookies Before Any Includes</h3>";
echo "<pre>";
echo "member_session_name cookie: " . (isset($_COOKIE['member_session_name']) ? $_COOKIE['member_session_name'] : 'NOT SET') . "\n";
echo "admin_session_name cookie: " . (isset($_COOKIE['admin_session_name']) ? $_COOKIE['admin_session_name'] : 'NOT SET') . "\n";
echo "</pre>";

echo "<h3>Step 2: Session Status Before Includes</h3>";
echo "<pre>";
echo "Session status: " . session_status() . " (1=disabled, 2=none, 3=active)\n";
echo "Session name: " . session_name() . "\n";
echo "</pre>";

echo "<h3>Step 3: Include config.php</h3>";
require_once 'config/config.php';
echo "<pre>";
echo "Session status after config: " . session_status() . "\n";
echo "Session name after config: " . session_name() . "\n";
echo "Session ID after config: " . (session_status() === PHP_SESSION_ACTIVE ? session_id() : 'not active') . "\n";
echo "</pre>";

echo "<h3>Step 4: Check Session Data</h3>";
echo "<pre>";
echo "Member ID in session: " . (isset($_SESSION['member_id']) ? $_SESSION['member_id'] : 'NOT SET') . "\n";
echo "Admin ID in session: " . (isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : 'NOT SET') . "\n";
print_r($_SESSION);
echo "</pre>";
?>
