<?php
/**
 * Local Database Configuration Template
 * 
 * Copy this file to config.local.php and update with your actual database credentials
 * DO NOT commit config.local.php to version control - it contains sensitive information
 * 
 * For hosting environments, update the values below with your hosting provider's database details
 */

// Your hosting database credentials
$db_host = 'localhost';          // e.g., 'localhost', '127.0.0.1', or your hosting provider's database host
$db_user = 'root';               // Your database username
$db_password = '';               // Your database password
$db_name = 'srcolony';           // Your database name
$db_port = 3306;                 // Database port (usually 3306 for MySQL)

/*
 * HOSTING SETUP EXAMPLES:
 * 
 * Example 1: GoDaddy Hosting
 * $db_host = 'mysql123.hostgator.com';
 * $db_user = 'username_dbuser';
 * $db_password = 'your_database_password';
 * $db_name = 'username_srcolony';
 * $db_port = 3306;
 * 
 * Example 2: Bluehost
 * $db_host = 'localhost';
 * $db_user = 'username_srcolony';
 * $db_password = 'your_database_password';
 * $db_name = 'username_srcolony';
 * $db_port = 3306;
 * 
 * Example 3: AWS RDS
 * $db_host = 'myinstance.123456789.us-east-1.rds.amazonaws.com';
 * $db_user = 'admin';
 * $db_password = 'your_rds_password';
 * $db_name = 'srcolony';
 * $db_port = 3306;
 */
?>
