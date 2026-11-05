<?php
/**
 * Debug script for auto-recalculate functionality
 */

session_start();
require_once 'config/config.php';
require_once 'includes/net-balance-helper.php';

// Set admin session for testing
$_SESSION['superadmin_id'] = 1;
$_SESSION['superadmin_name'] = 'Debug Admin';

echo "<h2>Auto-Recalculate Debug Test</h2>";
echo "<pre>";

try {
    // Test 1: Get active year
    echo "Test 1: Get Active Year\n";
    $stmt = $pdo->query("SELECT YearID, YearName FROM academic_years WHERE Status = 'Active' LIMIT 1");
    $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($activeYear) {
        echo "✓ Active Year Found: ID=" . $activeYear['YearID'] . ", Name=" . $activeYear['YearName'] . "\n\n";
        $yearId = $activeYear['YearID'];
    } else {
        echo "✗ No active year found\n\n";
        $yearId = null;
    }
    
    // Test 2: Get members
    echo "Test 2: Get Members\n";
    $stmt = $pdo->query("SELECT MemberID, MemberName FROM members LIMIT 3");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✓ Found " . count($members) . " members\n";
    foreach ($members as $m) {
        echo "  - Member " . $m['MemberID'] . ": " . $m['MemberName'] . "\n";
    }
    echo "\n";
    
    // Test 3: Test autoRecalculateSelectedNetBalances with specific member
    if (!empty($members)) {
        echo "Test 3: Auto-Recalculate Selected Members\n";
        $memberIds = array_slice(array_column($members, 'MemberID'), 0, 2);
        echo "Selected Member IDs: " . implode(", ", $memberIds) . "\n";
        
        $result = autoRecalculateSelectedNetBalances($yearId, $memberIds);
        
        echo "Result:\n";
        echo "  - success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
        echo "  - changes: " . $result['changes'] . "\n";
        if (!$result['success']) {
            echo "  - error: " . ($result['error'] ?? 'Unknown error') . "\n";
        }
        if (!empty($result['errors'])) {
            echo "  - errors:\n";
            foreach ($result['errors'] as $err) {
                echo "    • " . $err . "\n";
            }
        }
        echo "\n";
    }
    
    // Test 4: Verify member_net_balance table
    echo "Test 4: Check member_net_balance Table\n";
    $stmt = $pdo->query("DESCRIBE member_net_balance");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✓ Table exists with " . count($columns) . " columns\n";
    foreach ($columns as $col) {
        echo "  - " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    
    echo "\n✓ All tests completed successfully!\n";
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<hr>";
echo "<p><a href='superadmin/net-balance-manager.php'>Back to Net Balance Manager</a></p>";
?>
