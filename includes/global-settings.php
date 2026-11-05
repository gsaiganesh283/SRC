<?php
// Global Settings Loader
// This file loads system settings from database and makes them available globally
// Include this file in any page that needs access to system settings

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/config.php';
}

// Load system settings into global variable
global $systemSettings;
$systemSettings = [];

try {
    $stmt = $pdo->query("SELECT SettingKey, SettingValue FROM system_settings");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($settings as $setting) {
        $systemSettings[$setting['SettingKey']] = $setting['SettingValue'];
    }
} catch (PDOException $e) {
    // If settings table doesn't exist or error, use defaults
    $systemSettings = [
        'link_target_behavior' => '_self'
    ];
}

// Helper function to get setting value
if (!function_exists('getSystemSetting')) {
    function getSystemSetting($key, $default = null) {
        global $systemSettings;
        return $systemSettings[$key] ?? $default;
    }
}

// Helper function to get link target attribute
if (!function_exists('getLinkTarget')) {
    function getLinkTarget() {
        $target = getSystemSetting('link_target_behavior', '_self');
        return ($target === '_blank') ? 'target="_blank" rel="noopener noreferrer"' : '';
    }
}
