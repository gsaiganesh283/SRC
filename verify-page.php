<?php
error_reporting(0);
$_SESSION = array('superadmin_id' => 1, 'superadmin_name' => 'Admin');
$_GET['year'] = 1;

ob_start();
include '/workspaces/SRCOLONY-VIEWS/superadmin/net-balance-manager.php';
$html = ob_get_clean();

// Check for key elements
$checks = array(
    'DOCTYPE' => strpos($html, 'DOCTYPE'),
    'tab-navigation' => strpos($html, 'tab-navigation'),
    'Net Balance tab' => strpos($html, 'data-tab="net-balance"'),
    'Collection tab' => strpos($html, 'data-tab="collection"'),
    'Distribution tab' => strpos($html, 'data-tab="distribution"'),
    'summary-cards' => strpos($html, 'summary-cards'),
    'members-table' => strpos($html, 'members-table'),
    'switchTab function' => strpos($html, 'function switchTab'),
    'Export dialog overlay' => strpos($html, 'export-dialog-overlay'),
);

echo "PAGE CONTENT VERIFICATION:\n";
echo str_repeat("=", 50) . "\n";
echo "HTML Size: " . strlen($html) . " bytes\n\n";

$allFound = true;
foreach ($checks as $item => $found) {
    $status = $found !== false ? '✓ YES' : '✗ NOT FOUND';
    echo "$item: $status\n";
    if ($found === false) $allFound = false;
}

echo "\n" . str_repeat("=", 50) . "\n";
if ($allFound) {
    echo "✓ ALL ELEMENTS PRESENT - PAGE IS COMPLETE\n";
} else {
    echo "✗ Some elements missing - check above\n";
}

// Show file size comparison
$nbmSize = filesize('/workspaces/SRCOLONY-VIEWS/superadmin/net-balance-manager.php');
echo "\nFile Sizes:\n";
echo "  net-balance-manager.php: " . number_format($nbmSize) . " bytes\n";
echo "  Generated HTML output: " . number_format(strlen($html)) . " bytes\n";
?>
