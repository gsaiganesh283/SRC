<?php
/**
 * Check Backup File Content
 * This helps diagnose what's actually in the backup SQL file
 */

require_once '../config/config.php';

// Get the most recent backup file
$backupDir = '../backups/';
$files = glob($backupDir . '*.sql');

if (empty($files)) {
    die("No backup files found!");
}

// Get the most recent file
usort($files, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$latestBackup = $files[0];
$content = file_get_contents($latestBackup);

echo "<h2>Backup File Analysis</h2>";
echo "<p><strong>File:</strong> " . basename($latestBackup) . "</p>";
echo "<p><strong>Size:</strong> " . number_format(filesize($latestBackup)) . " bytes</p>";

// Extract first 3000 characters
$preview = substr($content, 0, 3000);

echo "<h3>First 3000 Characters:</h3>";
echo "<pre style='background: #f5f5f5; padding: 15px; overflow-x: auto; font-size: 12px;'>";
echo htmlspecialchars($preview);
echo "</pre>";

// Check for DROP TABLE statements
$dropCount = substr_count($content, 'DROP TABLE IF EXISTS');
echo "<h3>DROP TABLE Statements Found: <span style='color: " . ($dropCount > 0 ? 'green' : 'red') . ";'>{$dropCount}</span></h3>";

// Check for CREATE TABLE statements
$createCount = substr_count($content, 'CREATE TABLE');
echo "<h3>CREATE TABLE Statements Found: {$createCount}</h3>";

// Show all table operations
preg_match_all('/DROP TABLE IF EXISTS `([^`]+)`/', $content, $drops);
preg_match_all('/CREATE TABLE.*?`([^`]+)`/', $content, $creates);

if (!empty($drops[1])) {
    echo "<h3>Tables with DROP statements:</h3><ul>";
    foreach ($drops[1] as $table) {
        echo "<li>{$table}</li>";
    }
    echo "</ul>";
}

if (!empty($creates[1])) {
    echo "<h3>Tables with CREATE statements:</h3><ul>";
    foreach ($creates[1] as $table) {
        echo "<li>{$table}</li>";
    }
    echo "</ul>";
}

echo "<hr>";
echo "<p><a href='database-backup.php'>Back to Backup Module</a></p>";
?>
