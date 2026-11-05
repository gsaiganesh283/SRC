<?php
error_reporting(0);
ini_set('display_errors', 0);

try {
    // Simulate session
    $_SESSION = ['superadmin_id' => 1, 'superadmin_name' => 'Admin'];
    $_GET['year'] = 1;
    
    // Capture output
    ob_start();
    require_once 'superadmin/net-balance-manager.php';
    $output = ob_get_clean();
    
    // Check for key elements
    $checks = [
        'DOCTYPE html' => strpos($output, '<!DOCTYPE html') !== false,
        'Net Balance Management' => strpos($output, 'Net Balance Management') !== false,
        'tab-button' => strpos($output, 'tab-button') !== false,
        'Net Balance tab' => strpos($output, 'data-tab="net-balance"') !== false,
        'Collection tab' => strpos($output, 'data-tab="collection"') !== false,
        'Distribution tab' => strpos($output, 'data-tab="distribution"') !== false,
    ];
    
    echo "PAGE HEALTH CHECK:\n";
    echo str_repeat("=", 50) . "\n";
    
    $allPass = true;
    foreach ($checks as $check => $result) {
        $status = $result ? '✓ PASS' : '✗ FAIL';
        echo "$check: $status\n";
        if (!$result) $allPass = false;
    }
    
    echo str_repeat("=", 50) . "\n";
    echo $allPass ? "\n✓ PAGE IS WORKING CORRECTLY!\n" : "\n✗ Some checks failed\n";
    echo "\nPage size: " . strlen($output) . " bytes\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
?>
