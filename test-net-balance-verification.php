<?php
/**
 * TEST: Net Balance Verification Across All Portals
 * This script verifies that net balance calculations are consistent
 * across all member portals and uses the correct formula
 */

require_once 'config/config.php';
require_once 'includes/functions.php';
require_once 'includes/net-balance-helper.php';

// Get active year
$activeYearStmt = $pdo->query("SELECT YearID, YearName FROM academic_years WHERE Status = 'Active' LIMIT 1");
$activeYear = $activeYearStmt->fetch(PDO::FETCH_ASSOC);

if (!$activeYear) {
    die("No active year found");
}

$activeYearID = $activeYear['YearID'];
$activeYearName = $activeYear['YearName'];

echo "<h2>Net Balance Verification Report</h2>";
echo "<p><strong>Active Year:</strong> {$activeYearName} (ID: {$activeYearID})</p>";
echo "<hr>";

// Test 1: Member Selection Query (from superadmin/manage-transactions.php)
echo "<h3>Test 1: Member Selection Query (Superadmin Add Transaction)</h3>";
$stmt = $pdo->prepare("
    SELECT DISTINCT 
        m.MemberID, 
        m.MemberName,
        COALESCE(mnb.NetBalance, 
            (SELECT COALESCE(SUM(COALESCE(DistributedAmount, 0) + COALESCE(InterestAmount, 0) - COALESCE(CollectionAmount, 0)), 0)
             FROM transactions 
             WHERE MemberID = m.MemberID AND YearID = ?)) as NetBalance
    FROM members m
    INNER JOIN year_members ym ON m.MemberID = ym.MemberID
    LEFT JOIN member_net_balance mnb ON m.MemberID = mnb.MemberID AND mnb.YearID = ?
    WHERE ym.YearID = ? AND ym.Status = 'Active'
    ORDER BY m.MemberID ASC
");
$stmt->execute([$activeYearID, $activeYearID, $activeYearID]);
$membersFromQuery = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
echo "<thead><tr><th>Member ID</th><th>Member Name</th><th>Net Balance (Query)</th><th>Net Balance (Helper)</th><th>Match?</th></tr></thead>";
echo "<tbody>";

$allMatch = true;
foreach ($membersFromQuery as $member) {
    $queryBalance = floatval($member['NetBalance']);
    $helperBalance = floatval(getCurrentMemberNetBalance($member['MemberID'], $activeYearID));
    $match = abs($queryBalance - $helperBalance) < 0.01 ? '✓' : '✗';
    
    if ($match !== '✓') {
        $allMatch = false;
    }
    
    echo "<tr>";
    echo "<td>{$member['MemberID']}</td>";
    echo "<td>{$member['MemberName']}</td>";
    echo "<td>" . number_format($queryBalance, 2) . "</td>";
    echo "<td>" . number_format($helperBalance, 2) . "</td>";
    echo "<td><strong>{$match}</strong></td>";
    echo "</tr>";
}
echo "</tbody>";
echo "</table>";
echo "<p><strong>Query Results Match Helper Functions:</strong> " . ($allMatch ? "✓ YES" : "✗ NO") . "</p>";

// Test 2: System Summary (Dashboard)
echo "<h3>Test 2: System Summary (Dashboard)</h3>";
$systemSummary = getSystemNetBalanceSummary($activeYearID);
$totalCollected = $systemSummary['total_collection'] ?? 0;
$totalDistributed = $systemSummary['total_distributed'] ?? 0;
$totalInterest = $systemSummary['total_interest'] ?? 0;
$netBalance = $systemSummary['net_balance'] ?? 0;

echo "<ul>";
echo "<li><strong>Total Collections:</strong> ₹" . number_format($totalCollected, 2) . "</li>";
echo "<li><strong>Total Distributions:</strong> ₹" . number_format($totalDistributed, 2) . "</li>";
echo "<li><strong>Total Interest:</strong> ₹" . number_format($totalInterest, 2) . "</li>";
echo "<li><strong>System Net Balance:</strong> ₹" . number_format($netBalance, 2) . "</li>";
echo "<li><strong>Expected Formula:</strong> (" . number_format($totalDistributed, 2) . " + " . number_format($totalInterest, 2) . ") - " . number_format($totalCollected, 2) . " = ₹" . number_format((($totalDistributed + $totalInterest) - $totalCollected), 2) . "</li>";
echo "</ul>";

// Test 3: Transaction to Net Balance Mapping
echo "<h3>Test 3: Transaction Details & Net Balance Calculation</h3>";
$stmt = $pdo->prepare("
    SELECT TransactionID, MemberID, YearID, 
           COALESCE(DistributedAmount, 0) as Dist,
           COALESCE(InterestAmount, 0) as Interest,
           COALESCE(CollectionAmount, 0) as Collection,
           (COALESCE(DistributedAmount, 0) + COALESCE(InterestAmount, 0) - COALESCE(CollectionAmount, 0)) as SingleTxnBalance
    FROM transactions 
    WHERE YearID = ?
    LIMIT 10
");
$stmt->execute([$activeYearID]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($transactions)) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0; font-size: 12px;'>";
    echo "<thead><tr><th>Txn ID</th><th>Member ID</th><th>Dist</th><th>Int</th><th>Coll</th><th>Formula<br>= D+I-C</th></tr></thead>";
    echo "<tbody>";
    foreach ($transactions as $t) {
        echo "<tr>";
        echo "<td>{$t['TransactionID']}</td>";
        echo "<td>{$t['MemberID']}</td>";
        echo "<td>₹" . number_format($t['Dist'], 2) . "</td>";
        echo "<td>₹" . number_format($t['Interest'], 2) . "</td>";
        echo "<td>₹" . number_format($t['Collection'], 2) . "</td>";
        echo "<td>₹" . number_format($t['SingleTxnBalance'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</tbody>";
    echo "</table>";
} else {
    echo "<p><em>No transactions found for active year</em></p>";
}

// Test 4: Manual Overrides
echo "<h3>Test 4: Manual Overrides in member_net_balance</h3>";
$stmt = $pdo->prepare("
    SELECT mnb.MemberID, m.MemberName, mnb.NetBalance as OverrideBalance,
           (SELECT COALESCE(SUM(COALESCE(DistributedAmount, 0) + COALESCE(InterestAmount, 0) - COALESCE(CollectionAmount, 0)), 0)
            FROM transactions 
            WHERE MemberID = mnb.MemberID AND YearID = ?) as CalculatedBalance
    FROM member_net_balance mnb
    JOIN members m ON mnb.MemberID = m.MemberID
    WHERE mnb.YearID = ?
    LIMIT 10
");
$stmt->execute([$activeYearID, $activeYearID]);
$overrides = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($overrides)) {
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
    echo "<thead><tr><th>Member</th><th>Override Balance</th><th>Calculated Balance</th><th>Difference</th></tr></thead>";
    echo "<tbody>";
    foreach ($overrides as $override) {
        $diff = floatval($override['OverrideBalance']) - floatval($override['CalculatedBalance']);
        $diffDisplay = $diff > 0 ? "+₹" . number_format($diff, 2) : "₹" . number_format($diff, 2);
        echo "<tr>";
        echo "<td>{$override['MemberName']}</td>";
        echo "<td>₹" . number_format($override['OverrideBalance'], 2) . "</td>";
        echo "<td>₹" . number_format($override['CalculatedBalance'], 2) . "</td>";
        echo "<td><strong>{$diffDisplay}</strong></td>";
        echo "</tr>";
    }
    echo "</tbody>";
    echo "</table>";
} else {
    echo "<p><em>No manual overrides found</em></p>";
}

echo "<hr>";
echo "<p><strong>Summary:</strong> This script verifies that net balance calculations are working correctly.</p>";
echo "<p>If all tests show matching values, the net balance system is functioning properly.</p>";
?>
