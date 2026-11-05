<?php
// import-backup-non-destructive.php
// This script imports data from a SQL backup file, skipping tables and rows that already exist.

$backupFile = __DIR__ . '/backups/Backup.sql';

// Database config
// $host = 'localhost';
// $user = 'srcolony_user';
// $pass = 'SrCoIony2025!';
// $dbname = 'srcolony';
$db_host = 'localhost';
$db_user = 'srcolony';
$db_password = 'srcolony123';
$db_name = 'srcolony';
$db_port = 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($db_host, $db_user, $db_password, $db_name, $db_port);
} catch (Exception $e) {
    die('Connection failed: ' . $e->getMessage());
}

// Disable foreign key checks for import
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$conn->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

$sql = file_get_contents($backupFile);

// Remove comments and SET statements
$sql = preg_replace('/^--.*$/m', '', $sql);
$sql = preg_replace('/^SET .*$/m', '', $sql);

// Split by semicolon followed by newline
$queries = preg_split('/;\s*\n/', $sql);

$tablesCreated = 0;
$tablesSkipped = 0;
$rowsInserted = 0;
$rowsSkipped = 0;
$errors = 0;

foreach ($queries as $query) {
    $query = trim($query);
    if (empty($query)) continue;
    
    // Skip DROP TABLE statements
    if (preg_match('/^DROP TABLE/i', $query)) {
        continue;
    }

    // Handle CREATE TABLE
    if (preg_match('/CREATE TABLE `([^`]+)`/i', $query, $matches)) {
        $table = $matches[1];
        try {
            $res = $conn->query("SHOW TABLES LIKE '$table'");
            if ($res && $res->num_rows > 0) {
                echo "[SKIP] Table `$table` already exists.\n";
                $tablesSkipped++;
                continue;
            }
            $conn->query($query);
            echo "[CREATE] Table `$table` created.\n";
            $tablesCreated++;
        } catch (Exception $e) {
            echo "[ERROR] Creating table `$table`: " . $e->getMessage() . "\n";
            $errors++;
        }
        continue;
    }

    // Handle INSERT INTO - support multi-row inserts with complex values (including nested parentheses, quotes)
    if (preg_match('/INSERT INTO `([^`]+)`\s*\(([^)]+)\)\s*VALUES\s*(.+)$/is', $query, $matches)) {
        $table = $matches[1];
        $columnsStr = $matches[2];
        $valuesBlock = trim($matches[3]);
        
        // Parse column names
        $columns = array_map(function($col) {
            return trim(str_replace('`', '', $col));
        }, explode(',', $columnsStr));
        
        // Get primary key for this table
        $pkCol = null;
        $pkIdx = null;
        try {
            $pkRes = $conn->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
            if ($pkRes && $pkRes->num_rows > 0) {
                $pkRow = $pkRes->fetch_assoc();
                $pkCol = $pkRow['Column_name'];
                $pkIdx = array_search($pkCol, $columns);
            }
        } catch (Exception $e) {
            // Table might not exist, skip
            continue;
        }
        
        // Parse value sets properly (handling nested parentheses and quoted strings)
        $valueSets = [];
        $depth = 0;
        $current = '';
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($valuesBlock); $i++) {
            $char = $valuesBlock[$i];
            $prevChar = $i > 0 ? $valuesBlock[$i-1] : '';
            
            if (!$inString && ($char === "'" || $char === '"')) {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
            } elseif ($inString && $char === $stringChar && $prevChar !== '\\') {
                $inString = false;
                $current .= $char;
            } elseif (!$inString && $char === '(') {
                $depth++;
                if ($depth === 1) {
                    $current = '';
                } else {
                    $current .= $char;
                }
            } elseif (!$inString && $char === ')') {
                $depth--;
                if ($depth === 0) {
                    $valueSets[] = $current;
                    $current = '';
                } else {
                    $current .= $char;
                }
            } else {
                if ($depth > 0) {
                    $current .= $char;
                }
            }
        }
        
        foreach ($valueSets as $valuesStr) {
            // Check if row exists by primary key
            if ($pkCol !== null && $pkIdx !== null) {
                // Parse values to get PK value
                $tempValues = [];
                $tempCurrent = '';
                $tempInString = false;
                $tempStringChar = '';
                
                for ($i = 0; $i < strlen($valuesStr); $i++) {
                    $char = $valuesStr[$i];
                    $prevChar = $i > 0 ? $valuesStr[$i-1] : '';
                    
                    if (!$tempInString && ($char === "'" || $char === '"')) {
                        $tempInString = true;
                        $tempStringChar = $char;
                        $tempCurrent .= $char;
                    } elseif ($tempInString && $char === $tempStringChar && $prevChar !== '\\') {
                        $tempInString = false;
                        $tempCurrent .= $char;
                    } elseif (!$tempInString && $char === ',') {
                        $tempValues[] = trim($tempCurrent);
                        $tempCurrent = '';
                    } else {
                        $tempCurrent .= $char;
                    }
                }
                if (!empty($tempCurrent)) {
                    $tempValues[] = trim($tempCurrent);
                }
                
                if (isset($tempValues[$pkIdx])) {
                    $pkVal = trim($tempValues[$pkIdx], "'\"");
                    try {
                        $checkRes = $conn->query("SELECT 1 FROM `$table` WHERE `$pkCol` = '" . $conn->real_escape_string($pkVal) . "' LIMIT 1");
                        if ($checkRes && $checkRes->num_rows > 0) {
                            $rowsSkipped++;
                            continue;
                        }
                    } catch (Exception $e) {
                        // Ignore check errors
                    }
                }
            }
            
            // Build and execute insert
            $insert = "INSERT INTO `$table` (`" . implode('`,`', $columns) . "`) VALUES (" . $valuesStr . ")";
            try {
                $conn->query($insert);
                $rowsInserted++;
            } catch (Exception $e) {
                echo "[ERROR] Inserting into `$table`: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
        continue;
    }
}

// Re-enable foreign key checks
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

$conn->close();

echo "\n========== IMPORT SUMMARY ==========\n";
echo "Tables created: $tablesCreated\n";
echo "Tables skipped (already exist): $tablesSkipped\n";
echo "Rows inserted: $rowsInserted\n";
echo "Rows skipped (already exist): $rowsSkipped\n";
echo "Errors: $errors\n";
echo "====================================\n";
echo "Import complete.\n";