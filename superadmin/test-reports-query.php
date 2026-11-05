<?php
// Test Reports Query - Quick Diagnostic
// URL: superadmin/test-reports-query.php

require_once '../config/config.php';
require_once 'session.php';

// Require super admin authentication
if (!isset($_SESSION['superadmin_id'])) {
    header('Location: login.php');
    exit();
}

$dateFrom = date('Y-04-01');
$dateTo = date('Y-m-d');

echo "<h2>Reports Query Test</h2>";
echo "<p><strong>Date Range:</strong> $dateFrom to $dateTo</p>";
echo "<hr>";

try {
    // Test summary query
    echo "<h3>1. Testing Summary Statistics Query</h3>";
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT m.MemberID) as totalMembers,
            COUNT(DISTINCT ay.YearID) as totalYears,
            COUNT(t.TransactionID) as totalTransactions,
            SUM(t.CollectionAmount) as totalCollection,
            SUM(t.DistributedAmount) as totalDistribution,
            SUM(t.InterestAmount) as totalInterest
        FROM members m
        CROSS JOIN academic_years ay
        LEFT JOIN transactions t ON t.MemberID = m.MemberID AND t.YearID = ay.YearID
        WHERE t.TransactionDate BETWEEN ? AND ?
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<pre>";
    print_r($summary);
    echo "</pre>";
    echo "<p style='color: green;'>✅ Summary query successful!</p>";
    
    // Test monthly trend
    echo "<h3>2. Testing Monthly Trend Query</h3>";
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(TransactionDate, '%Y-%m') as Month,
            DATE_FORMAT(TransactionDate, '%b %Y') as MonthName,
            SUM(CollectionAmount) as Collection,
            SUM(DistributedAmount) as Distribution,
            SUM(InterestAmount) as Interest,
            COUNT(*) as TransactionCount
        FROM transactions
        WHERE TransactionDate BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(TransactionDate, '%Y-%m')
        ORDER BY Month ASC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Found " . count($monthlyTrend) . " months with data</p>";
    echo "<pre>";
    print_r($monthlyTrend);
    echo "</pre>";
    echo "<p style='color: green;'>✅ Monthly trend query successful!</p>";
    
    // Test member summary
    echo "<h3>3. Testing Member Summary Query</h3>";
    $stmt = $pdo->prepare("
        SELECT 
            m.MemberID,
            m.MemberName,
            m.Phone,
            COUNT(t.TransactionID) as TransactionCount,
            SUM(t.CollectionAmount) as TotalCollection,
            SUM(t.DistributedAmount) as TotalDistribution,
            SUM(t.InterestAmount) as TotalInterest,
            MAX(t.TransactionDate) as LastTransaction
        FROM members m
        LEFT JOIN transactions t ON m.MemberID = t.MemberID AND t.TransactionDate BETWEEN ? AND ?
        GROUP BY m.MemberID
        ORDER BY TotalCollection DESC
        LIMIT 5
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $memberSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Found " . count($memberSummary) . " members</p>";
    echo "<pre>";
    print_r($memberSummary);
    echo "</pre>";
    echo "<p style='color: green;'>✅ Member summary query successful!</p>";
    
    // Test year summary
    echo "<h3>4. Testing Year Summary Query</h3>";
    $stmt = $pdo->prepare("
        SELECT 
            ay.YearID,
            ay.YearName,
            COUNT(t.TransactionID) as TransactionCount,
            SUM(t.CollectionAmount) as TotalCollection,
            SUM(t.DistributedAmount) as TotalDistribution,
            SUM(t.InterestAmount) as TotalInterest
        FROM academic_years ay
        LEFT JOIN transactions t ON ay.YearID = t.YearID AND t.TransactionDate BETWEEN ? AND ?
        GROUP BY ay.YearID
        ORDER BY ay.StartDate DESC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $yearSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Found " . count($yearSummary) . " years</p>";
    echo "<pre>";
    print_r($yearSummary);
    echo "</pre>";
    echo "<p style='color: green;'>✅ Year summary query successful!</p>";
    
    // Test payment modes
    echo "<h3>5. Testing Payment Mode Query</h3>";
    $stmt = $pdo->prepare("
        SELECT 
            PaymentMode,
            COUNT(*) as Count,
            SUM(CollectionAmount) as TotalAmount
        FROM transactions
        WHERE TransactionDate BETWEEN ? AND ?
        GROUP BY PaymentMode
        ORDER BY TotalAmount DESC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $paymentModes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Found " . count($paymentModes) . " payment modes</p>";
    echo "<pre>";
    print_r($paymentModes);
    echo "</pre>";
    echo "<p style='color: green;'>✅ Payment mode query successful!</p>";
    
    // Test top collectors
    echo "<h3>6. Testing Top Collectors Query</h3>";
    $stmt = $pdo->prepare("
        SELECT 
            m.MemberName,
            SUM(t.CollectionAmount) as TotalCollection
        FROM transactions t
        LEFT JOIN members m ON t.MemberID = m.MemberID
        WHERE t.TransactionDate BETWEEN ? AND ?
        GROUP BY t.MemberID
        ORDER BY TotalCollection DESC
        LIMIT 5
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $topCollectors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Found " . count($topCollectors) . " top collectors</p>";
    echo "<pre>";
    print_r($topCollectors);
    echo "</pre>";
    echo "<p style='color: green;'>✅ Top collectors query successful!</p>";
    
    echo "<hr>";
    echo "<h2 style='color: green;'>✅ ALL QUERIES SUCCESSFUL!</h2>";
    echo "<p><a href='reports.php'>Go to Reports Page</a></p>";
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ ERROR</h2>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
