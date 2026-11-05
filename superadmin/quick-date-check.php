<?php
// Quick Transaction Date Check
require_once '../config/config.php';

try {
    // Check transaction count and date range
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_count,
            MIN(TransactionDate) as earliest_date,
            MAX(TransactionDate) as latest_date,
            COUNT(CASE WHEN TransactionDate >= '2025-04-01' AND TransactionDate <= '2025-10-21' THEN 1 END) as count_in_default_range
        FROM transactions
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<h2>Transaction Date Analysis</h2>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr><th>Metric</th><th>Value</th></tr>";
    echo "<tr><td><strong>Total Transactions</strong></td><td>" . $result['total_count'] . "</td></tr>";
    echo "<tr><td><strong>Earliest Transaction</strong></td><td>" . ($result['earliest_date'] ?? 'None') . "</td></tr>";
    echo "<tr><td><strong>Latest Transaction</strong></td><td>" . ($result['latest_date'] ?? 'None') . "</td></tr>";
    echo "<tr><td><strong>In Default Range (Apr 1 - Oct 21, 2025)</strong></td><td>" . $result['count_in_default_range'] . "</td></tr>";
    echo "</table>";
    
    if ($result['count_in_default_range'] == 0 && $result['total_count'] > 0) {
        echo "<h3 style='color: red;'>⚠️ ISSUE FOUND!</h3>";
        echo "<p><strong>Problem:</strong> You have {$result['total_count']} transactions, but NONE are in the default date range (Apr 1 - Oct 21, 2025).</p>";
        echo "<p><strong>Solution:</strong> On the reports page, change the date filter to:</p>";
        echo "<ul>";
        echo "<li><strong>From:</strong> {$result['earliest_date']}</li>";
        echo "<li><strong>To:</strong> {$result['latest_date']}</li>";
        echo "</ul>";
        echo "<p><a href='reports.php?date_from={$result['earliest_date']}&date_to={$result['latest_date']}' style='background: green; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>VIEW REPORTS WITH CORRECT DATE RANGE</a></p>";
    } else if ($result['count_in_default_range'] > 0) {
        echo "<h3 style='color: green;'>✅ Data exists in default range!</h3>";
        echo "<p>Found {$result['count_in_default_range']} transactions in the current date filter.</p>";
        echo "<p><a href='reports.php'>View Reports</a></p>";
    } else {
        echo "<h3 style='color: red;'>❌ No transactions found in database!</h3>";
        echo "<p>Please add transactions first.</p>";
    }
    
    // Show monthly breakdown
    echo "<h3>Monthly Transaction Breakdown</h3>";
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(TransactionDate, '%Y-%m') as month,
            DATE_FORMAT(TransactionDate, '%M %Y') as month_name,
            COUNT(*) as count,
            SUM(CollectionAmount) as total_collection
        FROM transactions
        GROUP BY DATE_FORMAT(TransactionDate, '%Y-%m')
        ORDER BY month DESC
        LIMIT 12
    ");
    $monthly = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($monthly)) {
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr><th>Month</th><th>Count</th><th>Total Collection</th></tr>";
        foreach ($monthly as $row) {
            echo "<tr>";
            echo "<td>{$row['month_name']}</td>";
            echo "<td>{$row['count']}</td>";
            echo "<td>₹" . number_format($row['total_collection'], 2) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
