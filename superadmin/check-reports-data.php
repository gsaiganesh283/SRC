<?php
// Quick Database Check for Reports
// URL: superadmin/check-reports-data.php

require_once '../config/config.php';
require_once 'session.php';

if (!isset($_SESSION['superadmin_id'])) {
    header('Location: login.php');
    exit();
}

echo "<h2>Reports Data Diagnostic</h2>";
echo "<hr>";

try {
    // Check total members
    echo "<h3>1. Total Members</h3>";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM members");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p><strong>Total Members:</strong> " . $count['count'] . "</p>";
    
    // Check total academic years
    echo "<h3>2. Total Academic Years</h3>";
    $stmt = $pdo->query("SELECT * FROM academic_years ORDER BY StartDate DESC");
    $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p><strong>Total Years:</strong> " . count($years) . "</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>YearID</th><th>Year Name</th><th>Start Date</th><th>End Date</th></tr>";
    foreach ($years as $year) {
        echo "<tr>";
        echo "<td>" . $year['YearID'] . "</td>";
        echo "<td>" . $year['YearName'] . "</td>";
        echo "<td>" . $year['StartDate'] . "</td>";
        echo "<td>" . $year['EndDate'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check total transactions
    echo "<h3>3. Total Transactions</h3>";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM transactions");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p><strong>Total Transactions:</strong> " . $count['count'] . "</p>";
    
    // Check transaction date range
    echo "<h3>4. Transaction Date Range</h3>";
    $stmt = $pdo->query("SELECT MIN(TransactionDate) as minDate, MAX(TransactionDate) as maxDate FROM transactions");
    $dateRange = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p><strong>Earliest Transaction:</strong> " . ($dateRange['minDate'] ?? 'None') . "</p>";
    echo "<p><strong>Latest Transaction:</strong> " . ($dateRange['maxDate'] ?? 'None') . "</p>";
    
    // Check transactions by month
    echo "<h3>5. Transactions by Month</h3>";
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(TransactionDate, '%Y-%m') as Month,
            DATE_FORMAT(TransactionDate, '%b %Y') as MonthName,
            COUNT(*) as Count,
            SUM(CollectionAmount) as TotalCollection
        FROM transactions
        GROUP BY DATE_FORMAT(TransactionDate, '%Y-%m')
        ORDER BY Month DESC
    ");
    $monthly = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($monthly)) {
        echo "<p style='color: red;'>No transaction data found!</p>";
    } else {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Month</th><th>Count</th><th>Total Collection</th></tr>";
        foreach ($monthly as $row) {
            echo "<tr>";
            echo "<td>" . $row['MonthName'] . "</td>";
            echo "<td>" . $row['Count'] . "</td>";
            echo "<td>Rs " . number_format($row['TotalCollection'], 2) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Test current date range filter
    echo "<h3>6. Testing Default Date Range Filter</h3>";
    $dateFrom = date('Y-04-01'); // April 1st of current year
    $dateTo = date('Y-m-d'); // Today
    
    echo "<p><strong>Date From:</strong> $dateFrom</p>";
    echo "<p><strong>Date To:</strong> $dateTo</p>";
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count, 
               SUM(CollectionAmount) as total 
        FROM transactions 
        WHERE TransactionDate BETWEEN ? AND ?
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p><strong>Transactions in Range:</strong> " . $result['count'] . "</p>";
    echo "<p><strong>Total Collection:</strong> Rs " . number_format($result['total'], 2) . "</p>";
    
    // If no results, check all data
    if ($result['count'] == 0) {
        echo "<p style='color: orange;'><strong>⚠️ No transactions in default date range!</strong></p>";
        echo "<p>Checking all transactions without date filter:</p>";
        
        $stmt = $pdo->query("
            SELECT COUNT(*) as count, 
                   SUM(CollectionAmount) as total 
            FROM transactions
        ");
        $allResult = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<p><strong>Total Transactions (All Time):</strong> " . $allResult['count'] . "</p>";
        echo "<p><strong>Total Collection (All Time):</strong> Rs " . number_format($allResult['total'], 2) . "</p>";
        
        if ($allResult['count'] > 0) {
            echo "<p style='color: green;'><strong>✅ Data exists! The date filter is the issue.</strong></p>";
            echo "<p><strong>Recommendation:</strong> Adjust the date range on the reports page to match your actual transaction dates.</p>";
        }
    } else {
        echo "<p style='color: green;'><strong>✅ Data found in default date range!</strong></p>";
    }
    
    // Show sample transactions
    echo "<h3>7. Sample Transactions (Last 10)</h3>";
    $stmt = $pdo->query("
        SELECT 
            t.TransactionID,
            t.TransactionDate,
            m.MemberName,
            t.CollectionAmount,
            t.DistributedAmount,
            t.InterestAmount
        FROM transactions t
        LEFT JOIN members m ON t.MemberID = m.MemberID
        ORDER BY t.TransactionDate DESC
        LIMIT 10
    ");
    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($samples)) {
        echo "<p style='color: red;'>No transactions found!</p>";
    } else {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Date</th><th>Member</th><th>Collection</th><th>Distribution</th><th>Interest</th></tr>";
        foreach ($samples as $row) {
            echo "<tr>";
            echo "<td>" . $row['TransactionID'] . "</td>";
            echo "<td>" . $row['TransactionDate'] . "</td>";
            echo "<td>" . $row['MemberName'] . "</td>";
            echo "<td>Rs " . number_format($row['CollectionAmount'], 2) . "</td>";
            echo "<td>Rs " . number_format($row['DistributedAmount'], 2) . "</td>";
            echo "<td>Rs " . number_format($row['InterestAmount'], 2) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    echo "<hr>";
    echo "<h2 style='color: green;'>✅ Diagnostic Complete!</h2>";
    echo "<p><a href='reports.php'>Back to Reports</a></p>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ ERROR</h2>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
}
?>
