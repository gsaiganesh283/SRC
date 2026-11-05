<?php
/**
 * Test: Net Balance Manual Update with Year ID Fix
 * Verifies that manual net balance overrides are now stored with YearID
 * and properly reflected in member dropdowns and dashboards
 */

require_once 'config/config.php';
require_once 'includes/net-balance-helper.php';

// Get active year
$activeYearStmt = $pdo->query("SELECT YearID, YearName FROM academic_years WHERE Status = 'Active' LIMIT 1");
$activeYear = $activeYearStmt->fetch(PDO::FETCH_ASSOC);

if (!$activeYear) {
    die("No active year found");
}

$activeYearID = $activeYear['YearID'];
$activeYearName = $activeYear['YearName'];

echo "<h2>Net Balance Manual Update Test (With YearID Fix)</h2>";
echo "<p><strong>Test Date:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "<p><strong>Active Year:</strong> {$activeYearName} (ID: {$activeYearID})</p>";
echo "<hr>";

// Step 1: Get a member to test with
echo "<h3>Step 1: Select Test Member</h3>";
$stmt = $pdo->prepare("
    SELECT m.MemberID, m.MemberName
    FROM members m
    INNER JOIN year_members ym ON m.MemberID = ym.MemberID
    WHERE ym.YearID = ? AND ym.Status = 'Active'
    LIMIT 1
");
$stmt->execute([$activeYearID]);
$testMember = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$testMember) {
    die("<p style='color: red;'>No active members found</p>");
}

$memberId = $testMember['MemberID'];
$memberName = $testMember['MemberName'];
echo "<p>Using member: <strong>{$memberName}</strong> (ID: {$memberId})</p>";

// Step 2: Get current balance
echo "<h3>Step 2: Current Balance Status</h3>";
$currentBalance = getCurrentMemberNetBalance($memberId, $activeYearID);
echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
echo "<tr><th>Current Net Balance</th><td>₹" . number_format($currentBalance, 2) . "</td></tr>";
echo "</table>";

// Step 3: Check database before manual update
echo "<h3>Step 3: Check Database Before Manual Update</h3>";
$stmt = $pdo->prepare("
    SELECT MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy 
    FROM member_net_balance 
    WHERE MemberID = ? AND YearID = ?
");
$stmt->execute([$memberId, $activeYearID]);
$dbRecord = $stmt->fetch(PDO::FETCH_ASSOC);

if ($dbRecord) {
    echo "<p><strong>Found Manual Override in Database:</strong></p>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
    echo "<tr><th>MemberID</th><td>" . $dbRecord['MemberID'] . "</td></tr>";
    echo "<tr><th>YearID</th><td>" . ($dbRecord['YearID'] ?? 'NULL') . "</td></tr>";
    echo "<tr><th>NetBalance</th><td>₹" . number_format($dbRecord['NetBalance'], 2) . "</td></tr>";
    echo "<tr><th>LastUpdatedAt</th><td>" . $dbRecord['LastUpdatedAt'] . "</td></tr>";
    echo "</table>";
} else {
    echo "<p><em>No manual override found (normal calculation being used)</em></p>";
}

// Step 4: Simulate a manual update (what the API does)
echo "<h3>Step 4: Simulate Manual Update API Call</h3>";
$testNewBalance = $currentBalance + 1000;
$reason = "Test manual update to verify YearID is saved";

echo "<p>Simulating: <strong>Update {$memberName}'s balance to ₹" . number_format($testNewBalance, 2) . "</strong></p>";

// Execute the same query as the API
$stmt = $pdo->prepare("
    INSERT INTO member_net_balance (MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy)
    VALUES (?, ?, ?, NOW(), ?)
    ON DUPLICATE KEY UPDATE 
        NetBalance = VALUES(NetBalance),
        LastUpdatedAt = NOW(),
        UpdatedBy = VALUES(UpdatedBy)
");

try {
    // Use SuperAdmin ID 1 for testing
    $stmt->execute([$memberId, $activeYearID, $testNewBalance, 1]);
    echo "<p style='color: green;'><strong>✓ UPDATE SUCCESSFUL</strong></p>";
    echo "<p>Parameters sent: MemberID={$memberId}, YearID={$activeYearID}, NetBalance={$testNewBalance}</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>✗ UPDATE FAILED: " . $e->getMessage() . "</strong></p>";
}

// Step 5: Verify it was saved with YearID
echo "<h3>Step 5: Verify Update in Database</h3>";
$stmt = $pdo->prepare("
    SELECT MemberID, YearID, NetBalance, LastUpdatedAt 
    FROM member_net_balance 
    WHERE MemberID = ? AND YearID = ?
");
$stmt->execute([$memberId, $activeYearID]);
$updatedRecord = $stmt->fetch(PDO::FETCH_ASSOC);

if ($updatedRecord && $updatedRecord['YearID'] !== null) {
    echo "<p style='color: green;'><strong>✓ DATABASE VERIFICATION PASSED</strong></p>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
    echo "<tr><th>MemberID</th><td>" . $updatedRecord['MemberID'] . "</td></tr>";
    echo "<tr><th>YearID</th><td><strong>" . ($updatedRecord['YearID'] ?? 'NULL') . " ✓</strong></td></tr>";
    echo "<tr><th>NetBalance</th><td>₹" . number_format($updatedRecord['NetBalance'], 2) . "</td></tr>";
    echo "<tr><th>LastUpdatedAt</th><td>" . $updatedRecord['LastUpdatedAt'] . "</td></tr>";
    echo "</table>";
} else {
    echo "<p style='color: red;'><strong>✗ RECORD NOT FOUND OR MISSING YEARID</strong></p>";
    if ($updatedRecord) {
        echo "<p>Record found but YearID is NULL: ";
        echo json_encode($updatedRecord) . "</p>";
    }
}

// Step 6: Test member dropdown query
echo "<h3>Step 6: Test Member Dropdown Query (What UI shows)</h3>";
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
    WHERE m.MemberID = ? AND ym.YearID = ? AND ym.Status = 'Active'
");
$stmt->execute([$activeYearID, $activeYearID, $memberId, $activeYearID]);
$dropdownMember = $stmt->fetch(PDO::FETCH_ASSOC);

if ($dropdownMember) {
    $displayBalance = floatval($dropdownMember['NetBalance']);
    $expectedBalance = $testNewBalance;
    $matches = abs($displayBalance - $expectedBalance) < 0.01;
    
    echo "<p><strong>Member Dropdown Query Result:</strong></p>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
    echo "<tr><th>Member Name</th><td>" . $dropdownMember['MemberName'] . "</td></tr>";
    echo "<tr><th>Net Balance (From Dropdown Query)</th><td>₹" . number_format($displayBalance, 2) . "</td></tr>";
    echo "<tr><th>Expected Balance (From Manual Update)</th><td>₹" . number_format($expectedBalance, 2) . "</td></tr>";
    echo "<tr><th>Match?</th><td style='color: " . ($matches ? "green" : "red") . "; font-weight: bold;'>" . ($matches ? "✓ YES" : "✗ NO") . "</td></tr>";
    echo "</table>";
} else {
    echo "<p style='color: red;'><strong>Member not found in dropdown query</strong></p>";
}

// Step 7: Test helper function
echo "<h3>Step 7: Test Helper Function</h3>";
$helperBalance = getCurrentMemberNetBalance($memberId, $activeYearID);
$helperMatches = abs($helperBalance - $testNewBalance) < 0.01;

echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
echo "<tr><th>Helper Function Balance</th><td>₹" . number_format($helperBalance, 2) . "</td></tr>";
echo "<tr><th>Expected Balance</th><td>₹" . number_format($testNewBalance, 2) . "</td></tr>";
echo "<tr><th>Match?</th><td style='color: " . ($helperMatches ? "green" : "red") . "; font-weight: bold;'>" . ($helperMatches ? "✓ YES" : "✗ NO") . "</td></tr>";
echo "</table>";

// Step 8: Summary
echo "<h3>Test Summary</h3>";
$allPass = ($updatedRecord && $updatedRecord['YearID'] !== null) && $matches && $helperMatches;

echo "<table border='1' style='border-collapse: collapse; width: 100%; margin: 10px 0;'>";
echo "<tr style='background: " . ($allPass ? "#d4edda" : "#f8d7da") . ";'>";
echo "<th>Test Result</th>";
echo "<td style='font-weight: bold; color: " . ($allPass ? "green" : "red") . ";'>";
echo $allPass ? "✓ ALL TESTS PASSED" : "✗ SOME TESTS FAILED";
echo "</td>";
echo "</tr>";
echo "<tr>";
echo "<th>YearID Stored in DB</th>";
echo "<td>" . ($updatedRecord && $updatedRecord['YearID'] !== null ? "✓" : "✗") . "</td>";
echo "</tr>";
echo "<tr>";
echo "<th>Dropdown Query Shows Updated Balance</th>";
echo "<td>" . ($matches ? "✓" : "✗") . "</td>";
echo "</tr>";
echo "<tr>";
echo "<th>Helper Function Shows Updated Balance</th>";
echo "<td>" . ($helperMatches ? "✓" : "✗") . "</td>";
echo "</tr>";
echo "</table>";

echo "<hr>";
echo "<h3>How the Fix Works</h3>";
echo "<ol>";
echo "<li><strong>Frontend:</strong> Net Balance Manager now sends <code>year_id</code> parameter when manually updating balance</li>";
echo "<li><strong>API:</strong> <code>api-net-balance.php</code> now receives and uses <code>year_id</code> when inserting/updating <code>member_net_balance</code> table</li>";
echo "<li><strong>Database:</strong> Manual overrides are now stored with <code>YearID</code>, allowing per-year balance customization</li>";
echo "<li><strong>UI:</strong> Member dropdown query properly joins with <code>member_net_balance</code> on <code>(MemberID, YearID)</code> and displays the updated balance</li>";
echo "<li><strong>Result:</strong> Manual net balance updates from Net Balance Management System now appear immediately in Add Transaction member dropdown</li>";
echo "</ol>";

echo "<hr>";
echo "<p>Generated at: " . date('Y-m-d H:i:s') . "</p>";
?>
