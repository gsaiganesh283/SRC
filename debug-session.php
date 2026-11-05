<?php
echo "<h2>Session Debug Information</h2>";

echo "<h3>Cookies:</h3>";
echo "<pre>";
print_r($_COOKIE);
echo "</pre>";

echo "<h3>Current Session Name:</h3>";
echo "<pre>" . session_name() . "</pre>";

echo "<h3>Session Status:</h3>";
echo "<pre>";
switch (session_status()) {
    case PHP_SESSION_DISABLED:
        echo "Sessions are disabled";
        break;
    case PHP_SESSION_NONE:
        echo "Session not started yet";
        break;
    case PHP_SESSION_ACTIVE:
        echo "Session is active";
        break;
}
echo "</pre>";

// Try to start session with cookie name if available
if (isset($_COOKIE['member_session_name'])) {
    echo "<h3>Found member_session_name cookie: " . htmlspecialchars($_COOKIE['member_session_name']) . "</h3>";
    if (session_status() === PHP_SESSION_NONE) {
        session_name($_COOKIE['member_session_name']);
        session_start();
    }
} elseif (isset($_COOKIE['admin_session_name'])) {
    echo "<h3>Found admin_session_name cookie: " . htmlspecialchars($_COOKIE['admin_session_name']) . "</h3>";
    if (session_status() === PHP_SESSION_NONE) {
        session_name($_COOKIE['admin_session_name']);
        session_start();
    }
} else {
    echo "<h3>No custom session cookie found</h3>";
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

echo "<h3>Session Data:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h3>Session ID:</h3>";
echo "<pre>" . session_id() . "</pre>";
?>
