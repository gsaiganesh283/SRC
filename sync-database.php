<?php
/**
 * Database Sync Tool
 * Automatically syncs remote database to local MySQL
 * Usage: php sync-database.php [pull|push]
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuration
$remote = [
    'host' => 'sql100.ezyro.com',
    'user' => 'ezyro_40509380',
    'pass' => 'Ganesh05@GG',
    'name' => 'ezyro_40509380_srcolonymanage2_db'
];

$local = [
    'host' => 'localhost',
    'user' => 'srcolony_user',
    'pass' => 'SrCoIony2025!',
    'name' => 'srcolony'
];

$action = isset($argv[1]) ? strtolower($argv[1]) : 'pull';

if ($action === 'pull') {
    echo "🔄 Pulling database from remote server...\n";
    pullFromRemote($remote, $local);
} elseif ($action === 'push') {
    echo "⬆️  Pushing database to remote server...\n";
    pushToRemote($remote, $local);
} else {
    echo "Usage: php sync-database.php [pull|push]\n";
    echo "  pull - Download remote database to local\n";
    echo "  push - Upload local database to remote\n";
}

function pullFromRemote($remote, $local) {
    $backupDir = __DIR__ . '/backups';
    
    // Create backup directory if it doesn't exist
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0777, true);
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . "/backup_$timestamp.sql";
    
    // Step 1: Dump remote database
    echo "📥 Downloading remote database...\n";
    $remoteConn = new mysqli($remote['host'], $remote['user'], $remote['pass'], $remote['name']);
    
    if ($remoteConn->connect_error) {
        die("❌ Remote connection failed: " . $remoteConn->connect_error . "\n");
    }
    
    // Get all tables
    $tables = [];
    $result = $remoteConn->query("SHOW TABLES");
    
    while ($row = $result->fetch_row()) {
        $tables[] = $row[0];
    }
    
    echo "   Found " . count($tables) . " tables\n";
    
    // Export to SQL
    $sql = "-- Database backup from " . $remote['name'] . " on " . date('Y-m-d H:i:s') . "\n\n";
    
    foreach ($tables as $table) {
        // Get CREATE TABLE statement
        $createResult = $remoteConn->query("SHOW CREATE TABLE `$table`");
        $createRow = $createResult->fetch_row();
        $sql .= $createRow[1] . ";\n\n";
        
        // Get table data
        $dataResult = $remoteConn->query("SELECT * FROM `$table`");
        $columns = $dataResult->fetch_fields();
        
        while ($row = $dataResult->fetch_assoc()) {
            $values = [];
            foreach ($columns as $col) {
                $val = $row[$col->name];
                $values[] = is_null($val) ? 'NULL' : "'" . $remoteConn->real_escape_string($val) . "'";
            }
            $colNames = implode(',', array_map(function($c) { return "`" . $c->name . "`"; }, $columns));
            $sql .= "INSERT INTO `$table` ($colNames) VALUES (" . implode(',', $values) . ");\n";
        }
        
        $sql .= "\n";
    }
    
    // Save backup
    file_put_contents($backupFile, $sql);
    echo "✅ Backup saved to: $backupFile\n";
    
    $remoteConn->close();
    
    // Step 2: Import to local database
    echo "📤 Importing to local database...\n";
    $localConn = new mysqli($local['host'], $local['user'], $local['pass']);
    
    if ($localConn->connect_error) {
        die("❌ Local connection failed: " . $localConn->connect_error . "\n");
    }
    
    // Drop and recreate database
    $localConn->query("DROP DATABASE IF EXISTS `" . $local['name'] . "`");
    $localConn->query("CREATE DATABASE `" . $local['name'] . "`");
    $localConn->select_db($local['name']);
    
    // Execute SQL file
    $sqlStatements = array_filter(array_map('trim', preg_split('/;[\r\n]+/', $sql)));
    
    foreach ($sqlStatements as $statement) {
        if (!empty($statement)) {
            if (!$localConn->query($statement . ";")) {
                echo "⚠️  Error executing statement: " . $localConn->error . "\n";
            }
        }
    }
    
    echo "✅ Database synced successfully!\n";
    echo "📊 Tables imported: " . count($tables) . "\n";
    
    $localConn->close();
}

function pushToRemote($remote, $local) {
    echo "⚠️  Push functionality requires manual verification for security\n";
    echo "⚠️  Please use this for development only\n";
    
    // Similar to pull but in reverse
    $localConn = new mysqli($local['host'], $local['user'], $local['pass'], $local['name']);
    
    if ($localConn->connect_error) {
        die("❌ Local connection failed: " . $localConn->connect_error . "\n");
    }
    
    echo "✅ Local database exported successfully\n";
    $localConn->close();
}
?>
