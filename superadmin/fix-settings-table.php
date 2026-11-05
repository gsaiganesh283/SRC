<?php
/**
 * Fix system_settings Table Structure
 * Run this script once to add missing columns and populate default settings
 */

require_once '../config/config.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>Fixing system_settings Table...</h2>";
    
    // Check current structure
    $stmt = $pdo->query("DESCRIBE system_settings");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h3>Current Columns:</h3><ul>";
    foreach ($columns as $col) {
        echo "<li>$col</li>";
    }
    echo "</ul>";
    
    // Add missing columns
    if (!in_array('SettingCategory', $columns)) {
        $pdo->exec("ALTER TABLE `system_settings` ADD COLUMN `SettingCategory` VARCHAR(50) DEFAULT 'General' AFTER `SettingValue`");
        echo "<p style='color: green;'>✓ Added SettingCategory column</p>";
    } else {
        echo "<p style='color: blue;'>ℹ SettingCategory column already exists</p>";
    }
    
    if (!in_array('Description', $columns)) {
        $pdo->exec("ALTER TABLE `system_settings` ADD COLUMN `Description` TEXT NULL AFTER `SettingCategory`");
        echo "<p style='color: green;'>✓ Added Description column</p>";
    } else {
        echo "<p style='color: blue;'>ℹ Description column already exists</p>";
    }
    
    if (!in_array('DataType', $columns)) {
        $pdo->exec("ALTER TABLE `system_settings` ADD COLUMN `DataType` VARCHAR(20) DEFAULT 'string' AFTER `Description`");
        echo "<p style='color: green;'>✓ Added DataType column</p>";
    } else {
        echo "<p style='color: blue;'>ℹ DataType column already exists</p>";
    }
    
    // Default settings to insert
    $defaultSettings = [
        // General
        ['system_timezone', 'Asia/Kolkata', 'General', 'Default timezone for the system', 'string'],
        ['records_per_page', '50', 'General', 'Number of records to display per page', 'integer'],
        ['enable_maintenance', 'false', 'General', 'Enable maintenance mode', 'boolean'],
        ['system_language', 'en', 'General', 'Default system language', 'string'],
        
        // Financial
        ['default_interest_rate', '24', 'Financial', 'Default annual interest rate (%)', 'decimal'],
        ['currency_symbol', '₹', 'Financial', 'Currency symbol to display', 'string'],
        ['enable_interest', 'true', 'Financial', 'Enable interest calculations', 'boolean'],
        ['financial_year_start', '04-01', 'Financial', 'Financial year start date (MM-DD)', 'string'],
        
        // Security
        ['session_timeout', '3600', 'Security', 'Session timeout in seconds', 'integer'],
        ['max_login_attempts', '5', 'Security', 'Maximum failed login attempts', 'integer'],
        ['require_strong_password', 'true', 'Security', 'Require strong passwords', 'boolean'],
        ['enable_2fa', 'false', 'Security', 'Enable two-factor authentication', 'boolean'],
        
        // Email
        ['smtp_host', 'smtp.gmail.com', 'Email', 'SMTP server hostname', 'string'],
        ['smtp_port', '587', 'Email', 'SMTP server port', 'integer'],
        ['smtp_username', '', 'Email', 'SMTP authentication username', 'string'],
        ['smtp_encryption', 'tls', 'Email', 'SMTP encryption type (tls/ssl)', 'string'],
        ['enable_email_notifications', 'true', 'Email', 'Enable email notifications', 'boolean'],
        
        // Backup
        ['backup_retention_days', '30', 'Backup', 'Number of days to keep backups', 'integer'],
        ['auto_backup_enabled', 'false', 'Backup', 'Enable automatic daily backups', 'boolean'],
        ['backup_time', '02:00', 'Backup', 'Daily backup time (HH:MM 24-hour)', 'string'],
        
        // Display
        ['default_theme', 'light', 'Display', 'Default theme (light/dark)', 'string'],
        ['date_format', 'Y-m-d', 'Display', 'Date display format (PHP format)', 'string'],
        ['time_format', 'H:i:s', 'Display', 'Time display format (PHP format)', 'string'],
        
        // Advanced
        ['enable_debug_mode', 'false', 'Advanced', 'Enable debug/development mode', 'boolean'],
        ['cache_enabled', 'true', 'Advanced', 'Enable system caching', 'boolean'],
        ['log_level', 'info', 'Advanced', 'System log level (debug/info/warning/error)', 'string'],
        ['api_rate_limit', '100', 'Advanced', 'API requests per minute limit', 'integer']
    ];
    
    echo "<h3>Adding Default Settings:</h3>";
    
    $insertStmt = $pdo->prepare("
        INSERT INTO system_settings (SettingKey, SettingValue, SettingCategory, Description, DataType)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            SettingCategory = VALUES(SettingCategory),
            Description = VALUES(Description),
            DataType = VALUES(DataType)
    ");
    
    $added = 0;
    $updated = 0;
    
    foreach ($defaultSettings as $setting) {
        try {
            $insertStmt->execute($setting);
            if ($insertStmt->rowCount() > 0) {
                $added++;
                echo "<p style='color: green;'>✓ Added/Updated: {$setting[0]}</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠ {$setting[0]}: " . $e->getMessage() . "</p>";
        }
    }
    
    // Update existing settings with categories if they don't have them
    $pdo->exec("UPDATE system_settings SET SettingCategory = 'Email', Description = 'Email address for system notifications', DataType = 'email' WHERE SettingKey = 'admin_email' AND (SettingCategory IS NULL OR SettingCategory = '')");
    $pdo->exec("UPDATE system_settings SET SettingCategory = 'General', Description = 'Name of the organization or colony', DataType = 'string' WHERE SettingKey = 'site_name' AND (SettingCategory IS NULL OR SettingCategory = '')");
    $pdo->exec("UPDATE system_settings SET SettingCategory = 'General', Description = 'Official website URL', DataType = 'string' WHERE SettingKey = 'site_url' AND (SettingCategory IS NULL OR SettingCategory = '')");
    
    echo "<hr>";
    echo "<h3 style='color: green;'>✓ Table structure fixed successfully!</h3>";
    echo "<p><strong>Total settings:</strong> " . count($defaultSettings) . "</p>";
    echo "<p><a href='settings.php' style='padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px;'>Go to System Settings</a></p>";
    
    // Show final structure
    echo "<h3>Updated Structure:</h3>";
    $stmt = $pdo->query("DESCRIBE system_settings");
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>{$row['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (PDOException $e) {
    echo "<p style='color: red; font-weight: bold;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
