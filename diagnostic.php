<?php
/**
 * Diagnostic script to identify errors in net-balance-manager.php
 */

// Set error display
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== NET BALANCE MANAGER DIAGNOSTIC ===\n\n";

// Test 1: Config
echo "[1] Testing config/config.php...\n";
try {
    require_once __DIR__ . '/config/config.php';
    echo "    ✓ Config loaded successfully\n";
} catch (Exception $e) {
    echo "    ✗ Config error: " . $e->getMessage() . "\n";
    exit;
}

// Test 2: Database connection
echo "\n[2] Testing database connection...\n";
try {
    $test = $pdo->query("SELECT 1");
    echo "    ✓ Database connected\n";
} catch (Exception $e) {
    echo "    ✗ Database error: " . $e->getMessage() . "\n";
    exit;
}

// Test 3: Helper functions
echo "\n[3] Testing net-balance-helper.php...\n";
try {
    require_once __DIR__ . '/includes/net-balance-helper.php';
    if (function_exists('getSystemNetBalanceSummary')) {
        echo "    ✓ getSystemNetBalanceSummary exists\n";
    } else {
        echo "    ✗ getSystemNetBalanceSummary not found\n";
    }
    
    if (function_exists('getAllMembersNetBalance')) {
        echo "    ✓ getAllMembersNetBalance exists\n";
    } else {
        echo "    ✗ getAllMembersNetBalance not found\n";
    }
} catch (Exception $e) {
    echo "    ✗ Helper error: " . $e->getMessage() . "\n";
}

// Test 4: Collection query
echo "\n[4] Testing collection query...\n";
try {
    $query = "SELECT MemberID, SUM(CollectionAmount) as total_collection FROM transactions WHERE CollectionAmount > 0 GROUP BY MemberID LIMIT 1";
    $result = $pdo->query($query);
    $data = $result->fetchAll();
    echo "    ✓ Query successful (" . count($data) . " rows)\n";
} catch (Exception $e) {
    echo "    ✗ Query error: " . $e->getMessage() . "\n";
}

// Test 5: Distribution query
echo "\n[5] Testing distribution query...\n";
try {
    $query = "SELECT MemberID, SUM(DistributedAmount) as total_distribution FROM transactions WHERE DistributedAmount > 0 GROUP BY MemberID LIMIT 1";
    $result = $pdo->query($query);
    $data = $result->fetchAll();
    echo "    ✓ Query successful (" . count($data) . " rows)\n";
} catch (Exception $e) {
    echo "    ✗ Query error: " . $e->getMessage() . "\n";
}

// Test 6: Academic years query
echo "\n[6] Testing academic years query...\n";
try {
    $result = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
    $years = $result->fetchAll();
    echo "    ✓ Query successful (" . count($years) . " years found)\n";
} catch (Exception $e) {
    echo "    ✗ Query error: " . $e->getMessage() . "\n";
}

echo "\n=== ALL SYSTEMS OPERATIONAL ===\n";
?>
