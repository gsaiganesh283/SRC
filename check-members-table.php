<?php
/**
 * Check Members Table Structure
 * Access: http://localhost:3000/check-members-table.php
 */

require_once 'config/config.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Members Table Structure</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #007bff; color: white; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .code { background: #f4f4f4; padding: 10px; border-radius: 5px; font-family: monospace; }
    </style>
</head>
<body>
<div class='container'>
<h1>📊 Members Table Structure</h1>";

try {
    // Get table structure
    $stmt = $pdo->query("SHOW COLUMNS FROM members");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Table Columns:</h2>";
    echo "<table>";
    echo "<tr><th>Column Name</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td><strong>{$col['Field']}</strong></td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>" . ($col['Default'] ?: 'NULL') . "</td>";
        echo "<td>{$col['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Show sample data
    echo "<h2>Sample Data (First 3 Rows):</h2>";
    $stmt = $pdo->query("SELECT * FROM members LIMIT 3");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($members)) {
        echo "<table>";
        echo "<tr>";
        foreach (array_keys($members[0]) as $key) {
            echo "<th>$key</th>";
        }
        echo "</tr>";
        
        foreach ($members as $member) {
            echo "<tr>";
            foreach ($member as $value) {
                echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='info'>No data in members table yet.</div>";
    }
    
    // Suggest correct query
    echo "<h2>✅ Correct Query to Use:</h2>";
    echo "<div class='code'>";
    echo "SELECT<br>";
    $columnNames = array_column($columns, 'Field');
    
    // Map common variations
    $mapping = [
        'Name' => ['MemberName', 'Name', 'FullName'],
        'Phone' => ['MobileNumber', 'Phone', 'Mobile', 'PhoneNumber', 'ContactNumber'],
        'Email' => ['Email'],
        'Address' => ['Address'],
    ];
    
    echo "    MemberID,<br>";
    
    // Find Name column
    foreach ($mapping['Name'] as $possible) {
        if (in_array($possible, $columnNames)) {
            echo "    $possible AS Name,<br>";
            break;
        }
    }
    
    // Find Phone column
    foreach ($mapping['Phone'] as $possible) {
        if (in_array($possible, $columnNames)) {
            echo "    $possible AS Phone,<br>";
            break;
        }
    }
    
    // Other columns
    echo "    Email,<br>";
    if (in_array('Address', $columnNames)) echo "    Address,<br>";
    if (in_array('Photo', $columnNames)) echo "    Photo,<br>";
    if (in_array('Status', $columnNames)) echo "    Status,<br>";
    if (in_array('CreatedAt', $columnNames)) echo "    CreatedAt,<br>";
    if (in_array('UpdatedAt', $columnNames)) echo "    UpdatedAt<br>";
    
    echo "FROM members<br>";
    echo "ORDER BY MemberID ASC";
    echo "</div>";
    
} catch (PDOException $e) {
    echo "<div class='error'>Error: " . $e->getMessage() . "</div>";
}

echo "</div></body></html>";
?>
