<?php
/**
 * Fix database_backups Table Structure
 * Run this script once to ensure the table has all required columns
 */

require_once '../config/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Fixing database_backups Table...</h2>";
    
    // Drop existing table if it exists
    $pdo->exec("DROP TABLE IF EXISTS `database_backups`");
    echo "<p>✓ Dropped existing table (if any)</p>";
    
    // Create table with correct structure
    $createTable = "
    CREATE TABLE `database_backups` (
      `BackupID` int(11) NOT NULL AUTO_INCREMENT,
      `BackupName` varchar(255) NOT NULL,
      `BackupPath` varchar(500) NOT NULL,
      `FileSize` bigint(20) DEFAULT 0,
      `CreatedBy` int(11) DEFAULT NULL,
      `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
      `Status` enum('success','failed') DEFAULT 'success',
      PRIMARY KEY (`BackupID`),
      KEY `CreatedBy` (`CreatedBy`),
      CONSTRAINT `database_backups_ibfk_1` FOREIGN KEY (`CreatedBy`) REFERENCES `super_admins` (`SuperAdminID`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";
    
    $pdo->exec($createTable);
    echo "<p>✓ Created database_backups table with correct structure</p>";
    
    // Verify columns
    $stmt = $pdo->query("DESCRIBE database_backups");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h3>Table Structure Verified:</h3>";
    echo "<ul>";
    foreach ($columns as $column) {
        echo "<li>$column</li>";
    }
    echo "</ul>";
    
    // Check if BackupName exists
    if (in_array('BackupName', $columns)) {
        echo "<p style='color: green; font-weight: bold;'>✓ BackupName column exists!</p>";
    } else {
        echo "<p style='color: red; font-weight: bold;'>✗ BackupName column missing!</p>";
    }
    
    echo "<hr>";
    echo "<h3>✓ Table structure fixed successfully!</h3>";
    echo "<p><a href='database-backup.php'>Go to Database Backup Module</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
