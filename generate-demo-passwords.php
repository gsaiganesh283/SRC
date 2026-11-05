<?php
// Generate password hashes for demo accounts

echo "Demo Account Password Hashes:\n\n";

// Member password: 1234567890
$memberPassword = password_hash('1234567890', PASSWORD_DEFAULT);
echo "Member Password (1234567890):\n";
echo $memberPassword . "\n\n";

// Admin/Super Admin password: DEMO
$adminPassword = password_hash('DEMO', PASSWORD_DEFAULT);
echo "Admin Password (DEMO):\n";
echo $adminPassword . "\n\n";

echo "Copy these hashes to CREATE_DEMO_ACCOUNTS.sql file\n";
?>
