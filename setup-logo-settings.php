<?php
// Force localhost mode for CLI
$_SERVER['HTTP_HOST'] = 'localhost';

require_once 'config/config.php';

try {
    // Website Logo
    $pdo->exec("INSERT INTO system_settings (SettingKey, SettingValue, UpdatedAt) 
    VALUES ('website_logo', '', NOW())
    ON DUPLICATE KEY UPDATE SettingKey = SettingKey");
    
    // System Logo
    $pdo->exec("INSERT INTO system_settings (SettingKey, SettingValue, UpdatedAt) 
    VALUES ('system_logo', '', NOW())
    ON DUPLICATE KEY UPDATE SettingKey = SettingKey");
    
    // Favicon
    $pdo->exec("INSERT INTO system_settings (SettingKey, SettingValue, UpdatedAt) 
    VALUES ('favicon', '', NOW())
    ON DUPLICATE KEY UPDATE SettingKey = SettingKey");
    
    // Site Name
    $pdo->exec("INSERT INTO system_settings (SettingKey, SettingValue, UpdatedAt) 
    VALUES ('site_name', 'SR Colony Chitti', NOW())
    ON DUPLICATE KEY UPDATE SettingKey = SettingKey");
    
    echo "Logo settings added successfully!\n";
    
    // Display current settings
    $stmt = $pdo->query("SELECT SettingKey, SettingValue FROM system_settings WHERE SettingKey IN ('website_logo', 'system_logo', 'favicon', 'site_name')");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nCurrent Logo Settings:\n";
    foreach ($settings as $setting) {
        echo "- {$setting['SettingKey']}: " . ($setting['SettingValue'] ?: '(not set)') . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
