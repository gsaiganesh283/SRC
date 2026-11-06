<?php
// Reports and Analytics Module - Super Admin
// URL: superadmin/reports.php

require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/change-amount-helper.php';

$superAdminName = $_SESSION['superadmin_name'] ?? 'Super Admin';

// Get date range for reports (default: current financial year)
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('2023-09-22');
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$memberID = isset($_GET['member_id']) ? $_GET['member_id'] : null;

// Initialize all variables to prevent undefined variable warnings
$summary = [];
$monthlyTrend = [];
$memberSummary = [];
$yearSummary = [];
$paymentModes = [];
$growthAnalysis = [];
$engagementAnalysis = [];
$ratioAnalysis = [];
$timeDistribution = [];
$avgTransactionAnalysis = [];
$retention = ['ActiveLast30' => 0, 'ActivePrev30' => 0, 'TotalActiveMembers' => 0];
$retentionRate = 0;
$error = null;

// Build WHERE clause based on member filter - Use COALESCE to handle NULL TransactionDate
$whereClause = "COALESCE(t.TransactionDate, DATE(t.CreatedAt)) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];

if ($memberID) {
    $whereClause .= " AND t.MemberID = ?";
    $params[] = $memberID;
}

// Get actual transaction date range from database
$actualDateRangeStmt = $pdo->query("
    SELECT 
        MIN(COALESCE(TransactionDate, DATE(CreatedAt))) as MinDate,
        MAX(COALESCE(TransactionDate, DATE(CreatedAt))) as MaxDate,
        COUNT(*) as TotalCount
    FROM transactions
");
$actualDateRange = $actualDateRangeStmt->fetch(PDO::FETCH_ASSOC);

// Fetch summary statistics
try {
    // Total counts - Fixed query to properly count members and years
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM members" . ($memberID ? " WHERE MemberID = $memberID" : "") . ") as totalMembers,
            (SELECT COUNT(*) FROM academic_years) as totalYears,
            COUNT(t.TransactionID) as totalTransactions,
            COALESCE(SUM(t.CollectionAmount), 0) as totalCollection,
            COALESCE(SUM(t.DistributedAmount), 0) as totalDistribution,
            COALESCE(SUM(t.InterestAmount), 0) as totalInterest
        FROM transactions t
        WHERE $whereClause
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Change amount entries in the same period (and member)
    $changeTotals = caTotals($pdo, null, $dateFrom, $dateTo, $memberID ?: null);
    $summary['totalChangeAmount'] = $changeTotals['total'];
    $summary['changeEntries'] = $changeTotals['count'];

    // Month-wise collection trend with Fixed Amount and Net Balance
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(t.TransactionDate, '%Y-%m') as Month,
            DATE_FORMAT(t.TransactionDate, '%b %Y') as MonthName,
            SUM(t.CollectionAmount) as Collection,
            SUM(t.DistributedAmount) as Distribution,
            SUM(CASE WHEN t.DistributedAmount > 0 THEN t.InterestAmount ELSE 0 END) as Interest,
            SUM(t.FixedAmount) as FixedAmount,
            COUNT(*) as TransactionCount
        FROM transactions t
        WHERE $whereClause
        GROUP BY DATE_FORMAT(t.TransactionDate, '%Y-%m')
        ORDER BY Month ASC
    ");
    $stmt->execute($params);
    $monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add Net Balance calculation and FixedAmount from academic years for each month
    foreach ($monthlyTrend as &$month) {
        // Get FixedAmount from academic years for this month's transactions
        $monthYear = $month['Month'];
        $academicYearFixedStmt = $pdo->prepare("
            SELECT COALESCE(SUM(DISTINCT ay.FixedAmount), 0) as AcademicYearFixed
            FROM transactions t
            LEFT JOIN academic_years ay ON t.YearID = ay.YearID
            WHERE DATE_FORMAT(t.TransactionDate, '%Y-%m') = ?
        ");
        $academicYearFixedStmt->execute([$monthYear]);
        $academicFixed = $academicYearFixedStmt->fetchColumn();
        
        $month['AcademicYearFixed'] = $academicFixed;
        // Net Balance = Interest + FixedAmount (from academic years)
        $month['NetBalance'] = ($month['Interest'] ?? 0) + $academicFixed;
    }
    unset($month);
    
    // Member-wise summary
    $memberJoinCondition = "t.TransactionDate BETWEEN ? AND ?";
    $memberJoinParams = [$dateFrom, $dateTo];
    if ($memberID) {
        $memberJoinCondition .= " AND t.MemberID = ?";
        $memberJoinParams[] = $memberID;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            m.MemberID,
            m.MemberName,
            m.Phone,
            COUNT(t.TransactionID) as TransactionCount,
            SUM(t.CollectionAmount) as TotalCollection,
            SUM(t.DistributedAmount) as TotalDistribution,
            SUM(t.InterestAmount) as TotalInterest,
            MAX(COALESCE(t.TransactionDate, DATE(t.CreatedAt))) as LastTransaction
        FROM members m
        LEFT JOIN transactions t ON m.MemberID = t.MemberID AND $memberJoinCondition
        " . ($memberID ? "WHERE m.MemberID = $memberID" : "") . "
        GROUP BY m.MemberID
        ORDER BY TotalCollection DESC
    ");
    $stmt->execute($memberJoinParams);
    $memberSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $memberChange = caMemberTotals($pdo, null, null, null, $dateFrom, $dateTo);
    foreach ($memberSummary as &$ms) {
        $ms['TotalChange'] = $memberChange[$ms['MemberID']] ?? 0;
    }
    unset($ms);

    // Year-wise summary
    $yearJoinCondition = "t.TransactionDate BETWEEN ? AND ?";
    $yearJoinParams = [$dateFrom, $dateTo];
    if ($memberID) {
        $yearJoinCondition .= " AND t.MemberID = ?";
        $yearJoinParams[] = $memberID;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            ay.YearID,
            ay.YearName,
            COUNT(t.TransactionID) as TransactionCount,
            SUM(t.CollectionAmount) as TotalCollection,
            SUM(t.DistributedAmount) as TotalDistribution,
            SUM(t.InterestAmount) as TotalInterest
        FROM academic_years ay
        LEFT JOIN transactions t ON ay.YearID = t.YearID AND $yearJoinCondition
        GROUP BY ay.YearID
        ORDER BY ay.StartDate DESC
    ");
    $stmt->execute($yearJoinParams);
    $yearSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($yearSummary as &$ys) {
        $ys['TotalChange'] = caTotals($pdo, $ys['YearID'], $dateFrom, $dateTo, $memberID ?: null)['total'];
    }
    unset($ys);

    // Payment mode distribution
    $stmt = $pdo->prepare("
        SELECT 
            t.PaymentMode,
            COUNT(*) as Count,
            SUM(t.CollectionAmount) as TotalAmount
        FROM transactions t
        WHERE $whereClause
        GROUP BY t.PaymentMode
        ORDER BY TotalAmount DESC
    ");
    $stmt->execute($params);
    $paymentModes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top collectors
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
    
    // Advanced Analytics - Growth Rate Analysis
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(t.TransactionDate, '%Y-%m') as Month,
            SUM(t.CollectionAmount) as MonthlyCollection,
            LAG(SUM(t.CollectionAmount), 1) OVER (ORDER BY DATE_FORMAT(t.TransactionDate, '%Y-%m')) as PrevMonthCollection
        FROM transactions t
        WHERE $whereClause
        GROUP BY DATE_FORMAT(t.TransactionDate, '%Y-%m')
        ORDER BY Month ASC
    ");
    $stmt->execute($params);
    $growthAnalysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Member Engagement Score (based on transaction frequency and amounts)
    $engagementJoinCondition = "t.TransactionDate BETWEEN ? AND ?";
    $engagementJoinParams = [$dateFrom, $dateTo];
    if ($memberID) {
        $engagementJoinCondition .= " AND t.MemberID = ?";
        $engagementJoinParams[] = $memberID;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            m.MemberID,
            m.MemberName,
            COUNT(t.TransactionID) as FrequencyScore,
            SUM(t.CollectionAmount) as AmountScore,
            DATEDIFF(CURDATE(), MAX(COALESCE(t.TransactionDate, DATE(t.CreatedAt)))) as DaysSinceLastTransaction,
            CASE 
                WHEN DATEDIFF(CURDATE(), MAX(COALESCE(t.TransactionDate, DATE(t.CreatedAt)))) <= 30 THEN 'Highly Active'
                WHEN DATEDIFF(CURDATE(), MAX(COALESCE(t.TransactionDate, DATE(t.CreatedAt)))) <= 90 THEN 'Active'
                WHEN DATEDIFF(CURDATE(), MAX(COALESCE(t.TransactionDate, DATE(t.CreatedAt)))) <= 180 THEN 'Inactive'
                ELSE 'Dormant'
            END as EngagementStatus
        FROM members m
        LEFT JOIN transactions t ON m.MemberID = t.MemberID AND $engagementJoinCondition
        " . ($memberID ? "WHERE m.MemberID = $memberID" : "") . "
        GROUP BY m.MemberID
        ORDER BY FrequencyScore DESC, AmountScore DESC
    ");
    $stmt->execute($engagementJoinParams);
    $memberEngagement = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Collection vs Distribution Ratio Analysis
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(t.TransactionDate, '%Y-%m') as Month,
            DATE_FORMAT(t.TransactionDate, '%b %Y') as MonthName,
            SUM(t.CollectionAmount) as Collection,
            SUM(t.DistributedAmount) as Distribution,
            SUM(t.InterestAmount) as Interest,
            SUM(t.FixedAmount) as FixedAmount,
            (SUM(t.DistributedAmount) + SUM(t.InterestAmount) + SUM(t.FixedAmount) - SUM(t.CollectionAmount)) as NetBalance,
            ROUND((SUM(t.CollectionAmount) / NULLIF(SUM(t.DistributedAmount), 0)) * 100, 2) as CollectionRatio
        FROM transactions t
        WHERE $whereClause
        GROUP BY DATE_FORMAT(t.TransactionDate, '%Y-%m')
        ORDER BY Month ASC
    ");
    $stmt->execute($params);
    $ratioAnalysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Predictive Forecast (Simple Moving Average)
    $forecastMonths = 3;
    if (count($monthlyTrend) >= 3) {
        $recentMonths = array_slice($monthlyTrend, -3);
        $avgCollection = array_sum(array_column($recentMonths, 'Collection')) / 3;
        $predictedCollection = round($avgCollection, 2);
    } else {
        $predictedCollection = 0;
    }
    
    // Transaction Time Distribution (Peak Hours)
    $stmt = $pdo->prepare("
        SELECT 
            HOUR(t.CreatedAt) as Hour,
            COUNT(*) as TransactionCount,
            SUM(t.CollectionAmount) as TotalAmount
        FROM transactions t
        WHERE $whereClause
        GROUP BY HOUR(t.CreatedAt)
        ORDER BY TransactionCount DESC
    ");
    $stmt->execute($params);
    $timeDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Average Transaction Value by Year
    $avgTransJoinCondition = "t.TransactionDate BETWEEN ? AND ?";
    $avgTransJoinParams = [$dateFrom, $dateTo];
    if ($memberID) {
        $avgTransJoinCondition .= " AND t.MemberID = ?";
        $avgTransJoinParams[] = $memberID;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            ay.YearName,
            COUNT(t.TransactionID) as TotalTransactions,
            AVG(t.CollectionAmount) as AvgCollection,
            AVG(t.DistributedAmount) as AvgDistribution,
            MAX(t.CollectionAmount) as MaxCollection,
            MIN(t.CollectionAmount) as MinCollection
        FROM academic_years ay
        LEFT JOIN transactions t ON ay.YearID = t.YearID AND $avgTransJoinCondition
        GROUP BY ay.YearID
        HAVING TotalTransactions > 0
        ORDER BY ay.StartDate DESC
    ");
    $stmt->execute($avgTransJoinParams);
    $avgTransactionAnalysis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Member Retention Rate
    $stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT CASE WHEN TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN MemberID END) as ActiveLast30,
            COUNT(DISTINCT CASE WHEN TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND TransactionDate < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN MemberID END) as ActivePrev30,
            COUNT(DISTINCT MemberID) as TotalActiveMembers
        FROM transactions
    ");
    $retention = $stmt->fetch(PDO::FETCH_ASSOC);
    $retentionRate = $retention['ActivePrev30'] > 0 ? round(($retention['ActiveLast30'] / $retention['ActivePrev30']) * 100, 2) : 0;
    
} catch (Exception $e) {
    error_log("Reports Error: " . $e->getMessage());
    $error = 'Error fetching report data: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Super Admin</title>
    <link rel="stylesheet" href="shared-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .reports-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .advanced-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-md);
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 14px;
        }
        
        .quick-date-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        
        .quick-date-btn {
            padding: 6px 12px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
        }
        
        .quick-date-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .date-filter {
            background: var(--card-bg);
            padding: 15px 20px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .date-filter label {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .date-filter input[type="date"] {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(26, 115, 232, 0.3);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            border-left: 4px solid;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.blue { border-left-color: #1a73e8; }
        .stat-card.green { border-left-color: #34a853; }
        .stat-card.orange { border-left-color: #fbbc04; }
        .stat-card.red { border-left-color: #ea4335; }
        .stat-card.purple { border-left-color: #9334e6; }
        .stat-card.teal { border-left-color: #0d9488; }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 15px;
        }
        
        .stat-card.blue .stat-icon { background: rgba(26, 115, 232, 0.1); color: #1a73e8; }
        .stat-card.green .stat-icon { background: rgba(52, 168, 83, 0.1); color: #34a853; }
        .stat-card.orange .stat-icon { background: rgba(251, 188, 4, 0.1); color: #fbbc04; }
        .stat-card.red .stat-icon { background: rgba(234, 67, 53, 0.1); color: #ea4335; }
        .stat-card.purple .stat-icon { background: rgba(147, 52, 230, 0.1); color: #9334e6; }
        .stat-card.teal .stat-icon { background: rgba(13, 148, 136, 0.1); color: #0d9488; }
        
        .stat-label {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 5px;
        }
        
        .stat-change {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .chart-card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        .table-card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            margin-bottom: 20px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        th {
            background: rgba(26, 115, 232, 0.1);
            color: var(--primary-color);
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
        }
        
        tr:hover {
            background: rgba(26, 115, 232, 0.05);
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 12px;
            border-bottom: 3px solid var(--primary-color);
        }
        
        .analytics-section {
            margin-top: 30px;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-success { background: rgba(52, 168, 83, 0.2); color: #34a853; }
        .badge-info { background: rgba(26, 115, 232, 0.2); color: #1a73e8; }
        .badge-warning { background: rgba(251, 188, 4, 0.2); color: #fbbc04; }
        .badge-danger { background: rgba(234, 67, 53, 0.2); color: #ea4335; }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
        }
        
        /* Chart Builder Styles */
        .chart-builder-section {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            margin: 30px 0;
            box-shadow: var(--shadow-md);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .section-header h2 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
        }
        
        .chart-builder-panel {
            background: var(--bg-primary);
            border-radius: 12px;
            padding: 25px;
            margin-top: 20px;
            border: 2px solid var(--border-color);
        }
        
        .builder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .builder-section {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
        }
        
        .builder-section.full-width {
            grid-column: 1 / -1;
        }
        
        .builder-section h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .chart-type-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
        }
        
        .chart-option {
            background: var(--bg-primary);
            border: 2px solid var(--border-color);
            padding: 15px 10px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            text-align: center;
        }
        
        .chart-option i {
            font-size: 28px;
            color: var(--text-secondary);
        }
        
        .chart-option span {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .chart-option:hover {
            border-color: var(--primary-color);
            background: rgba(26, 115, 232, 0.05);
            transform: translateY(-3px);
        }
        
        .chart-option.active {
            border-color: var(--primary-color);
            background: rgba(26, 115, 232, 0.1);
            box-shadow: 0 4px 12px rgba(26, 115, 232, 0.2);
        }
        
        .chart-option.active i {
            color: var(--primary-color);
        }
        
        .column-select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }
        
        .column-select:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.5);
            transform: translateY(-1px);
        }
        
        .column-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }
        
        .column-select option {
            background: white;
            color: #333;
            padding: 8px;
            font-weight: normal;
        }
        
        .column-select option:hover {
            background: #667eea;
            color: white;
        }
        
        .column-select optgroup {
            background: #f0f0f0;
            font-weight: bold;
            color: #764ba2;
        }
        
        /* Member Filter Dropdown Styling */
        #memberFilter {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(79, 172, 254, 0.3);
        }
        
        #memberFilter option {
            background: white;
            color: #333;
            padding: 10px;
            font-weight: normal;
        }
        
        #memberFilter option:hover,
        #memberFilter option:checked {
            background: #4facfe;
            color: white;
        }
        
        /* Report Type Dropdown Styling */
        #reportType {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(240, 147, 251, 0.3);
        }
        
        #reportType option {
            background: white;
            color: #333;
            padding: 10px;
            font-weight: normal;
        }
        
        #reportType option:hover,
        #reportType option:checked {
            background: #f093fb;
            color: white;
        }
        
        .metrics-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .metric-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: var(--bg-primary);
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s ease;
        }
        
        .metric-checkbox:hover {
            background: rgba(26, 115, 232, 0.05);
        }
        
        .metric-checkbox input[type=\"checkbox\"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .metric-checkbox span {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text-primary);
        }
        
        .metric-checkbox span i {
            color: var(--text-secondary);
        }
        
        .chart-options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
        }
        
        .option-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }
        
        .option-checkbox input[type=\"checkbox\"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .option-checkbox span {
            font-size: 14px;
            color: var(--text-primary);
        }
        
        .builder-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            padding-top: 20px;
            border-top: 2px solid var(--border-color);
        }
        
        .custom-chart-container {
            margin: 30px 0;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .chart-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }
        
        @media (max-width: 768px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .advanced-filters {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="reports-container">
        <!-- Quick Navigation -->
        
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-chart-line"></i>
                Reports & Analytics
            </h1>
            
            <form method="GET" class="date-filter">
                <label>From:</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" required>
                
                <label>To:</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" required>
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> Apply Filter
                </button>
            </form>
        </div>
        
        <!-- Advanced Filters -->
        <div class="advanced-filters">
            <div class="filter-group">
                <label><i class="fas fa-calendar-alt"></i> Quick Date Ranges</label>
                <div class="quick-date-buttons">
                    <button class="quick-date-btn" onclick="setDateRange('today')">Today</button>
                    <button class="quick-date-btn" onclick="setDateRange('week')">This Week</button>
                    <button class="quick-date-btn" onclick="setDateRange('month')">This Month</button>
                    <button class="quick-date-btn" onclick="setDateRange('quarter')">This Quarter</button>
                    <button class="quick-date-btn" onclick="setDateRange('year')">This Year</button>
                    <button class="quick-date-btn" onclick="setDateRange('all')">All Time</button>
                </div>
            </div>
            
            <div class="filter-group">
                <label><i class="fas fa-chart-bar"></i> Report Type</label>
                <select id="reportType" onchange="toggleReportSections()">
                    <option value="overview">Overview Dashboard</option>
                    <option value="financial">Financial Analysis</option>
                    <option value="members">Member Analytics</option>
                    <option value="predictive">Predictive Insights</option>
                    <option value="comparative">Comparative Analysis</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label><i class="fas fa-user-friends"></i> Filter by Member</label>
                <select id="memberFilter" onchange="applyMemberFilter()">
                    <option value="">All Members</option>
                    <?php
                    try {
                        $memberStmt = $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberName ASC");
                        while ($member = $memberStmt->fetch(PDO::FETCH_ASSOC)) {
                            $selected = ($memberID && $memberID == $member['MemberID']) ? 'selected' : '';
                            echo '<option value="' . $member['MemberID'] . '" ' . $selected . '>' . htmlspecialchars($member['MemberName']) . ' (ID: ' . $member['MemberID'] . ')</option>';
                        }
                    } catch (Exception $e) {
                        echo '<option value="">Error loading members</option>';
                    }
                    ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label><i class="fas fa-file-invoice"></i> Report Template</label>
                <select id="reportTemplate" onchange="updateTemplatePreview()" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; font-weight: 600; padding: 10px 12px; border: 2px solid var(--border-color); border-radius: 8px; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3); min-width: 200px;">
                    <?php
                    try {
                        // Get selected template from session or default
                        $selectedTemplateID = $_SESSION['selected_template_id'] ?? null;
                        
                        $templateStmt = $pdo->query("
                            SELECT TemplateID, TemplateName, TemplateType, IsDefault, TemplateConfig 
                            FROM report_templates 
                            WHERE IsActive = 1 AND ApplicableTo IN ('All', 'SuperAdmin')
                            ORDER BY IsDefault DESC, TemplateName
                        ");
                        while ($template = $templateStmt->fetch(PDO::FETCH_ASSOC)) {
                            // Select either the session template or the default template
                            $selected = '';
                            if ($selectedTemplateID && $template['TemplateID'] == $selectedTemplateID) {
                                $selected = 'selected';
                            } elseif (!$selectedTemplateID && $template['IsDefault']) {
                                $selected = 'selected';
                            }
                            
                            $icon = $template['TemplateType'] == 'PDF' ? 'fa-file-pdf' : 
                                   ($template['TemplateType'] == 'Print' ? 'fa-print' : 'fa-file-invoice');
                            echo '<option value="' . $template['TemplateID'] . '" ' . $selected . ' data-config=\'' . htmlspecialchars($template['TemplateConfig']) . '\'>';
                            echo htmlspecialchars($template['TemplateName']);
                            if ($template['IsDefault']) echo ' ⭐';
                            echo '</option>';
                        }
                    } catch (Exception $e) {
                        echo '<option value="">Default Template</option>';
                    }
                    ?>
                </select>
                <div id="templatePreviewInfo" style="margin-top: 8px; font-size: 11px; color: #64748b; min-height: 20px;"></div>
                <div style="margin-top: 8px;">
                    <a href="manage-templates.php" style="font-size: 11px; color: #64748b;">
                        <i class="fas fa-cog"></i> Manage Templates
                    </a>
                </div>
            </div>
            
            <div class="filter-group">
                <label><i class="fas fa-download"></i> Export Options</label>
                <div class="quick-date-buttons">
                    <button class="quick-date-btn" onclick="exportReport('pdf')"><i class="fas fa-file-pdf"></i> PDF</button>
                    <button class="quick-date-btn" onclick="exportReport('excel')"><i class="fas fa-file-excel"></i> Excel</button>
                    <button class="quick-date-btn" onclick="exportReport('csv')"><i class="fas fa-file-csv"></i> CSV</button>
                </div>
            </div>
        </div>
        
        <!-- Custom Chart Builder -->
        <div class="chart-builder-section">
            <div class="section-header">
                <h2><i class="fas fa-chart-line"></i> Custom Chart Builder</h2>
                <button class="btn btn-primary" onclick="toggleChartBuilder()">
                    <i class="fas fa-plus"></i> Create Custom Visualization
                </button>
            </div>
            
            <div id="chartBuilderPanel" class="chart-builder-panel" style="display: none;">
                <div class="builder-grid">
                    <div class="builder-section">
                        <h3><i class="fas fa-chart-bar"></i> Chart Type</h3>
                        <div class="chart-type-grid">
                            <button class="chart-option active" data-type="line" onclick="selectChartType('line')">
                                <i class="fas fa-chart-line"></i>
                                <span>Line Chart</span>
                            </button>
                            <button class="chart-option" data-type="bar" onclick="selectChartType('bar')">
                                <i class="fas fa-chart-bar"></i>
                                <span>Bar Chart</span>
                            </button>
                            <button class="chart-option" data-type="horizontalBar" onclick="selectChartType('horizontalBar')">
                                <i class="fas fa-bars"></i>
                                <span>Horizontal Bar</span>
                            </button>
                            <button class="chart-option" data-type="pie" onclick="selectChartType('pie')">
                                <i class="fas fa-chart-pie"></i>
                                <span>Pie Chart</span>
                            </button>
                            <button class="chart-option" data-type="doughnut" onclick="selectChartType('doughnut')">
                                <i class="fas fa-circle-notch"></i>
                                <span>Doughnut</span>
                            </button>
                            <button class="chart-option" data-type="radar" onclick="selectChartType('radar')">
                                <i class="fas fa-spider"></i>
                                <span>Radar Chart</span>
                            </button>
                            <button class="chart-option" data-type="polarArea" onclick="selectChartType('polarArea')">
                                <i class="fas fa-bullseye"></i>
                                <span>Polar Area</span>
                            </button>
                            <button class="chart-option" data-type="scatter" onclick="selectChartType('scatter')">
                                <i class="fas fa-braille"></i>
                                <span>Scatter Plot</span>
                            </button>
                            <button class="chart-option" data-type="bubble" onclick="selectChartType('bubble')">
                                <i class="fas fa-circle"></i>
                                <span>Bubble Chart</span>
                            </button>
                            <button class="chart-option" data-type="area" onclick="selectChartType('area')">
                                <i class="fas fa-chart-area"></i>
                                <span>Area Chart</span>
                            </button>
                        </div>
                    </div>
                    
                    <div class="builder-section">
                        <h3><i class="fas fa-database"></i> Data Columns (X-Axis / Labels)</h3>
                        <select id="xAxisColumn" class="column-select" onchange="updateChartPreview()">
                            <optgroup label="Time Dimensions">
                                <option value="TransactionDate">Transaction Date</option>
                                <option value="Month" selected>Month</option>
                                <option value="Year">Year</option>
                                <option value="Quarter">Quarter</option>
                                <option value="DayOfWeek">Day of Week</option>
                            </optgroup>
                            <optgroup label="Member Dimensions">
                                <option value="MemberName">Member Name</option>
                                <option value="MemberID">Member ID</option>
                            </optgroup>
                            <optgroup label="Transaction Dimensions">
                                <option value="YearName">Academic Year</option>
                                <option value="PaymentMode">Payment Mode</option>
                                <option value="TransactionType">Transaction Type</option>
                            </optgroup>
                        </select>
                    </div>
                    
                    <div class="builder-section">
                        <h3><i class="fas fa-calculator"></i> Metrics (Y-Axis / Values)</h3>
                        <div id="yAxisMetrics" class="metrics-list">
                            <label class="metric-checkbox">
                                <input type="checkbox" value="CollectionAmount" checked onchange="updateChartPreview()">
                                <span><i class="fas fa-coins"></i> Collection Amount</span>
                            </label>
                            <label class="metric-checkbox">
                                <input type="checkbox" value="DistributedAmount" onchange="updateChartPreview()">
                                <span><i class="fas fa-hand-holding-usd"></i> Distribution Amount</span>
                            </label>
                            <label class="metric-checkbox">
                                <input type="checkbox" value="InterestAmount" onchange="updateChartPreview()">
                                <span><i class="fas fa-percent"></i> Interest Amount</span>
                            </label>
                            <label class="metric-checkbox">
                                <input type="checkbox" value="TransactionCount" onchange="updateChartPreview()">
                                <span><i class="fas fa-hashtag"></i> Transaction Count</span>
                            </label>
                            <label class="metric-checkbox">
                                <input type="checkbox" value="NetBalance" onchange="updateChartPreview()">
                                <span><i class="fas fa-balance-scale"></i> Net Balance</span>
                            </label>
                            <label class="metric-checkbox">
                                <input type="checkbox" value="AvgCollection" onchange="updateChartPreview()">
                                <span><i class="fas fa-chart-line"></i> Average Collection</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="builder-section">
                        <h3><i class="fas fa-filter"></i> Aggregation Method</h3>
                        <select id="aggregationMethod" class="column-select" onchange="updateChartPreview()">
                            <option value="SUM" selected>Sum (Total)</option>
                            <option value="AVG">Average (Mean)</option>
                            <option value="COUNT">Count</option>
                            <option value="MAX">Maximum</option>
                            <option value="MIN">Minimum</option>
                        </select>
                    </div>
                    
                    <div class="builder-section full-width">
                        <h3><i class="fas fa-sliders-h"></i> Chart Options</h3>
                        <div class="chart-options-grid">
                            <label class="option-checkbox">
                                <input type="checkbox" id="showLegend" checked onchange="updateChartPreview()">
                                <span>Show Legend</span>
                            </label>
                            <label class="option-checkbox">
                                <input type="checkbox" id="showGridLines" checked onchange="updateChartPreview()">
                                <span>Show Grid Lines</span>
                            </label>
                            <label class="option-checkbox">
                                <input type="checkbox" id="showDataLabels" onchange="updateChartPreview()">
                                <span>Show Data Labels</span>
                            </label>
                            <label class="option-checkbox">
                                <input type="checkbox" id="enableAnimation" checked onchange="updateChartPreview()">
                                <span>Enable Animation</span>
                            </label>
                            <label class="option-checkbox">
                                <input type="checkbox" id="stackedBars" onchange="updateChartPreview()">
                                <span>Stacked (Bar/Area)</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="builder-actions">
                    <button class="btn btn-success" onclick="generateCustomChart()">
                        <i class="fas fa-magic"></i> Generate Chart
                    </button>
                    <button class="btn btn-secondary" onclick="resetChartBuilder()">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                    <button class="btn btn-info" onclick="saveChartTemplate()">
                        <i class="fas fa-save"></i> Save Template
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Custom Chart Display Area -->
        <div id="customChartContainer" class="custom-chart-container" style="display: none;">
            <div class="chart-card" style="grid-column: 1 / -1;">
                <div class="chart-header">
                    <h3 class="chart-title">
                        <i class="fas fa-chart-line"></i>
                        <span id="customChartTitle">Custom Visualization</span>
                    </h3>
                    <div class="chart-actions">
                        <button class="btn btn-sm btn-secondary" onclick="editCustomChart()">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn btn-sm btn-primary" onclick="exportCustomChart()">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="closeCustomChart()">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
                <div class="chart-container" style="height: 500px;">
                    <canvas id="customChart"></canvas>
                </div>
            </div>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php 
        // Check if no transactions found in date range
        if ($summary['totalTransactions'] == 0): 
            // Use the actual date range we already fetched
            $allTransactions = [
                'count' => $actualDateRange['TotalCount'] ?? 0,
                'minDate' => $actualDateRange['MinDate'] ?? null,
                'maxDate' => $actualDateRange['MaxDate'] ?? null
            ];
            
            if ($allTransactions['count'] > 0):
        ?>
            <div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; padding: 20px; margin: 20px 0;">
                <h3 style="color: #856404; margin-top: 0;">
                    <i class="fas fa-exclamation-triangle"></i> No Transactions in Selected Date Range
                </h3>
                <p style="color: #856404; font-size: 16px;">
                    <strong><?php echo $allTransactions['count']; ?> transactions</strong> exist in your database, 
                    but they are <strong>outside</strong> the current date filter 
                    (<?php echo date('d M Y', strtotime($dateFrom)); ?> to <?php echo date('d M Y', strtotime($dateTo)); ?>).
                </p>
                <p style="color: #856404; font-size: 16px;">
                    <strong>Your actual transaction date range:</strong><br>
                    From: <strong><?php echo date('d M Y', strtotime($allTransactions['minDate'])); ?></strong> 
                    to <strong><?php echo date('d M Y', strtotime($allTransactions['maxDate'])); ?></strong>
                </p>
                <a href="reports.php?date_from=<?php echo $allTransactions['minDate']; ?>&date_to=<?php echo $allTransactions['maxDate']; ?>" 
                   style="background: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">
                    <i class="fas fa-sync-alt"></i> View All Transactions
                </a>
            </div>
        <?php 
            endif;
        endif; 
        ?>
        
        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-label">Active Members</div>
                <div class="stat-value"><?php echo number_format($summary['totalMembers']); ?></div>
                <div class="stat-change">Participating members</div>
            </div>
            
            <div class="stat-card green">
                <div class="stat-icon">
                    <i class="fas fa-rupee-sign"></i>
                </div>
                <div class="stat-label">Total Collection</div>
                <div class="stat-value">₹<?php echo number_format($summary['totalCollection'], 2); ?></div>
                <div class="stat-change"><?php echo number_format($summary['totalTransactions']); ?> transactions</div>
            </div>
            
            <div class="stat-card orange">
                <div class="stat-icon">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="stat-label">Total Distribution</div>
                <div class="stat-value">₹<?php echo number_format($summary['totalDistribution'], 2); ?></div>
                <div class="stat-change">Distributed amount</div>
            </div>
            
            <div class="stat-card purple">
                <div class="stat-icon">
                    <i class="fas fa-percent"></i>
                </div>
                <div class="stat-label">Total Interest</div>
                <div class="stat-value">₹<?php echo number_format($summary['totalInterest'], 2); ?></div>
                <div class="stat-change">Interest earned</div>
            </div>

            <div class="stat-card purple">
                <div class="stat-icon">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-label">Change Amount</div>
                <div class="stat-value">₹<?php echo number_format($summary['totalChangeAmount'] ?? 0, 2); ?></div>
                <div class="stat-change"><?php echo (int)($summary['changeEntries'] ?? 0); ?> entries &middot; <a href="change-amount.php" style="color:inherit;">manage</a></div>
            </div>

            <div class="stat-card teal">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-label">Academic Years</div>
                <div class="stat-value"><?php echo number_format($summary['totalYears']); ?></div>
                <div class="stat-change">Total years tracked</div>
            </div>
            
            <div class="stat-card red">
                <div class="stat-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stat-label">Transactions</div>
                <div class="stat-value"><?php echo number_format($summary['totalTransactions']); ?></div>
                <div class="stat-change">Total count</div>
            </div>
        </div>
        
        <!-- Charts -->
        <div class="charts-grid">
            <!-- Monthly Trend Chart -->
            <div class="chart-card" style="grid-column: 1 / -1;">
                <h3 class="chart-title">
                    <i class="fas fa-chart-line"></i>
                    Monthly Collection Trend
                </h3>
                <div class="chart-container">
                    <canvas id="monthlyTrendChart"></canvas>
                </div>
            </div>
            
            <!-- Payment Mode Distribution -->
            <div class="chart-card">
                <h3 class="chart-title">
                    <i class="fas fa-pie-chart"></i>
                    Payment Mode Distribution
                </h3>
                <div class="chart-container">
                    <canvas id="paymentModeChart"></canvas>
                </div>
            </div>
            
            <!-- Top Collectors -->
            <div class="chart-card">
                <h3 class="chart-title">
                    <i class="fas fa-trophy"></i>
                    Top 5 Collectors
                </h3>
                <div class="chart-container">
                    <canvas id="topCollectorsChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Month-wise Detailed Report Table -->
        <div class="table-card" style="margin-top: 30px;">
            <h3 class="chart-title">
                <i class="fas fa-calendar-alt"></i>
                Month-wise Financial Report
            </h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Total Collection</th>
                            <th>Total Distribution</th>
                            <th>Total Interest</th>
                            <!-- <th>Fixed Amount (Txn)</th> -->
                            <th>Fixed Amount (Year)</th>
                            <th>Net Balance</th>
                            <th>Transactions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $grandCollection = 0;
                        $grandDistribution = 0;
                        $grandInterest = 0;
                        $grandFixedTxn = 0;
                        $grandNetBalance = 0;
                        $grandTransactions = 0;
                        
                        foreach ($monthlyTrend as $month): 
                            $grandCollection += $month['Collection'] ?? 0;
                            $grandDistribution += $month['Distribution'] ?? 0;
                            $grandInterest += $month['Interest'] ?? 0;
                            $grandFixedTxn += $month['FixedAmount'] ?? 0;
                            $grandNetBalance += $month['NetBalance'] ?? 0;
                            $grandTransactions += $month['TransactionCount'] ?? 0;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($month['MonthName']); ?></strong></td>
                            <td class="amount-positive">₹<?php echo number_format($month['Collection'] ?? 0, 2); ?></td>
                            <td class="amount-negative">₹<?php echo number_format($month['Distribution'] ?? 0, 2); ?></td>
                            <td style="color: #ffc107; font-weight: 600;">₹<?php echo number_format($month['Interest'] ?? 0, 2); ?></td>
                            <!-- <td>₹<?php echo number_format($month['FixedAmount'] ?? 0, 2); ?></td> -->
                            <td style="color: #17a2b8; font-weight: 600;">₹<?php echo number_format($month['AcademicYearFixed'] ?? 0, 2); ?></td>
                            <td class="<?php echo ($month['NetBalance'] ?? 0) >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                ₹<?php echo number_format($month['NetBalance'] ?? 0, 2); ?>
                            </td>
                            <td><?php echo number_format($month['TransactionCount'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (count($monthlyTrend) > 0): 
                            // Get total Fixed Amount from academic_years table
                            try {
                                if ($filterYear > 0) {
                                    $fixedStmt = $pdo->prepare("SELECT COALESCE(FixedAmount, 0) FROM academic_years WHERE YearID = ?");
                                    $fixedStmt->execute([$filterYear]);
                                } else {
                                    $fixedStmt = $pdo->query("SELECT COALESCE(SUM(FixedAmount), 0) FROM academic_years");
                                }
                                $totalFixedFromYears = $fixedStmt->fetchColumn();
                            } catch (Exception $e) {
                                $totalFixedFromYears = 0;
                            }
                            // Grand Total Net Balance = Fixed Amount (from academic years) + Interest Amount
                            $grandTotalNetBalance = $totalFixedFromYears + $grandInterest;
                        ?>
                        <tr style="background: var(--bg-secondary); font-weight: 700; border-top: 3px solid var(--border-color);">
                            <td><strong>GRAND TOTAL</strong></td>
                            <td class="amount-positive">₹<?php echo number_format($grandCollection, 2); ?></td>
                            <td class="amount-negative">₹<?php echo number_format($grandDistribution, 2); ?></td>
                            <td style="color: #ffc107;">₹<?php echo number_format($grandInterest, 2); ?></td>
                           <!-- <td>₹<?php echo number_format($grandFixedTxn, 2); ?></td> -->
                            <td style="color: #17a2b8;">₹<?php echo number_format($totalFixedFromYears, 2); ?></td>
                            <td class="<?php echo $grandTotalNetBalance >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                ₹<?php echo number_format($grandTotalNetBalance, 2); ?>
                            </td>
                            <td><?php echo number_format($grandTransactions); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Advanced Analytics Sections -->
        <div id="predictiveSection" class="analytics-section" style="display: none;">
            <h2 class="section-title" style="margin-top: 40px;">
                <i class="fas fa-brain"></i> Predictive Analytics & Forecasting
            </h2>
            
            <div class="stats-grid">
                <div class="stat-card blue">
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-label">Predicted Next Month Collection</div>
                    <div class="stat-value">₹<?php echo number_format($predictedCollection ?? 0, 2); ?></div>
                    <div class="stat-change">Based on 3-month average</div>
                </div>
                
                <div class="stat-card green">
                    <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                    <div class="stat-label">Member Retention Rate</div>
                    <div class="stat-value"><?php echo number_format($retentionRate ?? 0, 1); ?>%</div>
                    <div class="stat-change">Last 30 vs Previous 30 days</div>
                </div>
                
                <div class="stat-card purple">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-label">Active Members (30 Days)</div>
                    <div class="stat-value"><?php echo number_format($retention['ActiveLast30'] ?? 0); ?></div>
                    <div class="stat-change">Out of <?php echo number_format($retention['TotalActiveMembers'] ?? 0); ?> total</div>
                </div>
            </div>
            
            <div class="charts-grid">
                <div class="chart-card">
                    <h3 class="chart-title"><i class="fas fa-balance-scale"></i> Collection vs Distribution Ratio</h3>
                    <div class="chart-container">
                        <canvas id="ratioChart"></canvas>
                    </div>
                </div>
                
                <div class="chart-card">
                    <h3 class="chart-title"><i class="fas fa-clock"></i> Peak Transaction Hours</h3>
                    <div class="chart-container">
                        <canvas id="timeDistributionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="memberAnalyticsSection" class="analytics-section" style="display: none;">
            <h2 class="section-title" style="margin-top: 40px;">
                <i class="fas fa-user-chart"></i> Member Engagement Analytics
            </h2>
            
            <div class="table-card">
                <h3 class="chart-title"><i class="fas fa-fire"></i> Member Engagement Score</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Member Name</th>
                                <th>Engagement Status</th>
                                <th>Frequency Score</th>
                                <th>Total Amount</th>
                                <th>Days Since Last Transaction</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($memberEngagement ?? [], 0, 20) as $member): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($member['MemberName']); ?></strong></td>
                                <td>
                                    <span class="badge badge-<?php 
                                        echo $member['EngagementStatus'] == 'Highly Active' ? 'success' : 
                                            ($member['EngagementStatus'] == 'Active' ? 'info' : 
                                            ($member['EngagementStatus'] == 'Inactive' ? 'warning' : 'danger')); 
                                    ?>">
                                        <?php echo $member['EngagementStatus']; ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($member['FrequencyScore']); ?> transactions</td>
                                <td>₹<?php echo number_format($member['AmountScore'], 2); ?></td>
                                <td><?php echo number_format($member['DaysSinceLastTransaction']); ?> days</td>
                                <td>
                                    <?php if ($member['EngagementStatus'] == 'Inactive' || $member['EngagementStatus'] == 'Dormant'): ?>
                                        <button class="btn btn-sm btn-warning" onclick="alert('Send reminder to <?php echo addslashes($member['MemberName']); ?>')">
                                            <i class="fas fa-bell"></i> Remind
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div id="comparativeSection" class="analytics-section" style="display: none;">
            <h2 class="section-title" style="margin-top: 40px;">
                <i class="fas fa-chart-bar"></i> Comparative Analysis
            </h2>
            
            <div class="table-card">
                <h3 class="chart-title"><i class="fas fa-graduation-cap"></i> Average Transaction Analysis by Year</h3>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Academic Year</th>
                                <th>Total Transactions</th>
                                <th>Avg Collection</th>
                                <th>Avg Distribution</th>
                                <th>Max Collection</th>
                                <th>Min Collection</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($avgTransactionAnalysis ?? [] as $year): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($year['YearName']); ?></strong></td>
                                <td><?php echo number_format($year['TotalTransactions']); ?></td>
                                <td>₹<?php echo number_format($year['AvgCollection'], 2); ?></td>
                                <td>₹<?php echo number_format($year['AvgDistribution'], 2); ?></td>
                                <td>₹<?php echo number_format($year['MaxCollection'], 2); ?></td>
                                <td>₹<?php echo number_format($year['MinCollection'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="charts-grid">
                <div class="chart-card" style="grid-column: 1 / -1;">
                    <h3 class="chart-title"><i class="fas fa-chart-area"></i> Growth Rate Analysis</h3>
                    <div class="chart-container">
                        <canvas id="growthRateChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Member-wise Summary Table -->
        <div class="table-card">
            <h3 class="chart-title">
                <i class="fas fa-users"></i>
                Member-wise Summary
            </h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Member Name</th>
                            <th>Phone</th>
                            <th>Transactions</th>
                            <th>Total Collection</th>
                            <th>Total Distribution</th>
                            <th>Total Interest</th>
                            <th>Change Amount</th>
                            <th>Last Transaction</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($memberSummary as $member): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($member['MemberName']); ?></strong></td>
                            <td><?php echo htmlspecialchars($member['Phone']); ?></td>
                            <td><?php echo number_format($member['TransactionCount']); ?></td>
                            <td>₹<?php echo number_format($member['TotalCollection'], 2); ?></td>
                            <td>₹<?php echo number_format($member['TotalDistribution'], 2); ?></td>
                            <td>₹<?php echo number_format($member['TotalInterest'], 2); ?></td>
                            <td>₹<?php echo number_format($member['TotalChange'] ?? 0, 2); ?></td>
                            <td><?php
                            if ($member['LastTransaction'] && $member['LastTransaction'] != '0000-00-00' && $member['LastTransaction'] != '-0001-11-30') {
                                echo date('d M Y', strtotime($member['LastTransaction']));
                            } else {
                                echo 'N/A';
                            }
                            ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Year-wise Summary Table -->
        <div class="table-card">
            <h3 class="chart-title">
                <i class="fas fa-calendar-alt"></i>
                Year-wise Summary
            </h3>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Academic Year</th>
                            <th>Transactions</th>
                            <th>Total Collection</th>
                            <th>Total Distribution</th>
                            <th>Total Interest</th>
                            <th>Change Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($yearSummary as $year): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($year['YearName']); ?></strong></td>
                            <td><?php echo number_format($year['TransactionCount']); ?></td>
                            <td>₹<?php echo number_format($year['TotalCollection'], 2); ?></td>
                            <td>₹<?php echo number_format($year['TotalDistribution'], 2); ?></td>
                            <td>₹<?php echo number_format($year['TotalInterest'], 2); ?></td>
                            <td>₹<?php echo number_format($year['TotalChange'] ?? 0, 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Export Buttons -->
        <div class="export-buttons">
            <button onclick="exportReportPDF()" class="btn btn-primary" style="background: #dc3545;">
                <i class="fas fa-file-pdf"></i> Export Full Report (PDF)
            </button>
            <button onclick="window.print()" class="btn btn-primary" style="background: #6c757d;">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
    </div>
    
    <script>
        // Monthly Trend Chart
        const monthlyTrendData = <?php echo json_encode($monthlyTrend); ?>;
        const monthlyTrendCtx = document.getElementById('monthlyTrendChart').getContext('2d');
        window.monthlyChart = new Chart(monthlyTrendCtx, {
            type: 'line',
            data: {
                labels: monthlyTrendData.map(d => d.MonthName),
                datasets: [{
                    label: 'Collection',
                    data: monthlyTrendData.map(d => d.Collection),
                    borderColor: '#34a853',
                    backgroundColor: 'rgba(52, 168, 83, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Distribution',
                    data: monthlyTrendData.map(d => d.Distribution),
                    borderColor: '#1a73e8',
                    backgroundColor: 'rgba(26, 115, 232, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Interest',
                    data: monthlyTrendData.map(d => d.Interest),
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Fixed Amount (Year)',
                    data: monthlyTrendData.map(d => d.AcademicYearFixed),
                    borderColor: '#17a2b8',
                    backgroundColor: 'rgba(23, 162, 184, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Net Balance',
                    data: monthlyTrendData.map(d => d.NetBalance),
                    borderColor: '#9334e6',
                    backgroundColor: 'rgba(147, 52, 230, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        
        // Payment Mode Chart
        const paymentModeData = <?php echo json_encode($paymentModes); ?>;
        const paymentModeCtx = document.getElementById('paymentModeChart').getContext('2d');
        window.paymentChart = new Chart(paymentModeCtx, {
            type: 'doughnut',
            data: {
                labels: paymentModeData.map(d => d.PaymentMode),
                datasets: [{
                    data: paymentModeData.map(d => d.TotalAmount),
                    backgroundColor: [
                        '#1a73e8',
                        '#34a853',
                        '#fbbc04',
                        '#ea4335',
                        '#9334e6'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        
        // Top Collectors Chart
        const topCollectorsData = <?php echo json_encode($topCollectors); ?>;
        const topCollectorsCtx = document.getElementById('topCollectorsChart').getContext('2d');
        window.topCollectorsChart = new Chart(topCollectorsCtx, {
            type: 'bar',
            data: {
                labels: topCollectorsData.map(d => d.MemberName),
                datasets: [{
                    label: 'Total Collection',
                    data: topCollectorsData.map(d => d.TotalCollection),
                    backgroundColor: '#34a853'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₹' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
        
        function exportReportPDF() {
            const params = new URLSearchParams(window.location.search);
            
            // Add selected template
            const templateSelect = document.getElementById('reportTemplate');
            console.log('Template select element:', templateSelect);
            console.log('Template value:', templateSelect ? templateSelect.value : 'NOT FOUND');
            
            if (templateSelect && templateSelect.value) {
                params.append('template_id', templateSelect.value);
                console.log('Added template_id to params:', templateSelect.value);
            } else {
                console.warn('No template selected or select element not found!');
            }
            
            const finalUrl = 'export-report-pdf.php?' + params.toString();
            console.log('Opening PDF export with URL:', finalUrl);
            
            window.open(finalUrl, '_blank');
        }
        
        function updateTemplatePreview() {
            const templateSelect = document.getElementById('reportTemplate');
            const previewInfo = document.getElementById('templatePreviewInfo');
            if (!templateSelect) return;
            
            const selectedOption = templateSelect.selectedOptions[0];
            if (!selectedOption) return;
            
            try {
                const config = JSON.parse(selectedOption.dataset.config || '{}');
                const templateName = selectedOption.text.replace(' ⭐', '');
                
                // Save selection to session
                fetch('save-template-selection.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'template_id=' + selectedOption.value
                });
                
                // Show preview info
                if (previewInfo) {
                    const colors = config.colors || {};
                    previewInfo.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> ' + 
                        templateName + ' selected';
                }
                
                console.log('Selected template:', templateName, config);
            } catch (e) {
                console.error('Error parsing template config:', e);
            }
        }
        
        // Initialize template preview on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateTemplatePreview();
        });
        
        // Advanced Features JavaScript
        function setDateRange(range) {
            const today = new Date();
            let fromDate, toDate = today.toISOString().split('T')[0];
            
            switch(range) {
                case 'today':
                    fromDate = toDate;
                    break;
                case 'week':
                    fromDate = new Date(today.setDate(today.getDate() - 7)).toISOString().split('T')[0];
                    break;
                case 'month':
                    fromDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
                    break;
                case 'quarter':
                    const quarter = Math.floor(today.getMonth() / 3);
                    fromDate = new Date(today.getFullYear(), quarter * 3, 1).toISOString().split('T')[0];
                    break;
                case 'year':
                    fromDate = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0];
                    break;
                case 'all':
                    fromDate = '2020-01-01';
                    break;
            }
            
            document.querySelector('input[name=\"date_from\"]').value = fromDate;
            document.querySelector('input[name=\"date_to\"]').value = toDate;
            document.querySelector('.date-filter').submit();
        }
        
        function toggleReportSections() {
            const reportType = document.getElementById('reportType').value;
            const sections = {
                overview: ['memberAnalyticsSection', 'predictiveSection', 'comparativeSection'],
                financial: ['memberAnalyticsSection', 'predictiveSection', 'comparativeSection'],
                members: ['memberAnalyticsSection'],
                predictive: ['predictiveSection'],
                comparative: ['comparativeSection']
            };
            
            // Hide all sections first
            document.querySelectorAll('.analytics-section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Show selected sections
            if (sections[reportType]) {
                sections[reportType].forEach(sectionId => {
                    const element = document.getElementById(sectionId);
                    if (element) {
                        element.style.display = 'block';
                    }
                });
            }
        }
        
        function applyMemberFilter() {
            const memberFilter = document.getElementById('memberFilter').value;
            const dateFrom = document.querySelector('input[name="date_from"]').value;
            const dateTo = document.querySelector('input[name="date_to"]').value;
            
            // Build URL with filters
            let url = 'reports.php?date_from=' + dateFrom + '&date_to=' + dateTo;
            if (memberFilter) {
                url += '&member_id=' + memberFilter;
            }
            
            window.location.href = url;
        }
        
        function exportReport(format) {
            const params = new URLSearchParams(window.location.search);
            params.append('export', format);
            
            // Get member filter value
            const memberFilter = document.getElementById('memberFilter')?.value || '';
            
            if (format === 'pdf') {
                // Wait a moment for charts to fully render
                setTimeout(() => {
                    // Capture all chart images
                    const chartImages = {};
                    
                    // Capture main charts with error handling
                    try {
                        if (window.monthlyChart && typeof window.monthlyChart.toBase64Image === 'function') {
                            chartImages.monthlyTrend = window.monthlyChart.toBase64Image();
                        }
                    } catch(e) { console.warn('Failed to capture monthly chart:', e); }
                    
                    try {
                        if (window.paymentChart && typeof window.paymentChart.toBase64Image === 'function') {
                            chartImages.paymentMode = window.paymentChart.toBase64Image();
                        }
                    } catch(e) { console.warn('Failed to capture payment chart:', e); }
                    
                    try {
                        if (window.topCollectorsChart && typeof window.topCollectorsChart.toBase64Image === 'function') {
                            chartImages.topCollectors = window.topCollectorsChart.toBase64Image();
                        }
                    } catch(e) { console.warn('Failed to capture top collectors chart:', e); }
                    
                    try {
                        if (window.growthChart && typeof window.growthChart.toBase64Image === 'function') {
                            chartImages.growthRate = window.growthChart.toBase64Image();
                        }
                    } catch(e) { console.warn('Failed to capture growth chart:', e); }
                    
                    try {
                        if (window.ratioChart && typeof window.ratioChart.toBase64Image === 'function') {
                            chartImages.collectionRatio = window.ratioChart.toBase64Image();
                        }
                    } catch(e) { console.warn('Failed to capture ratio chart:', e); }
                    
                    try {
                        if (window.timeChart && typeof window.timeChart.toBase64Image === 'function') {
                            chartImages.timeDistribution = window.timeChart.toBase64Image();
                        }
                    } catch(e) { console.warn('Failed to capture time chart:', e); }
                    
                    try {
                        if (customChartInstance && typeof customChartInstance.toBase64Image === 'function') {
                            chartImages.customChart = customChartInstance.toBase64Image();
                        }
                    } catch(e) { console.warn('Failed to capture custom chart:', e); }
                    
                    console.log('Captured charts:', Object.keys(chartImages));
                    
                    // Get selected template
                    const templateSelect = document.getElementById('reportTemplate');
                    const templateId = templateSelect ? templateSelect.value : '';
                    if (templateId) {
                        params.append('template_id', templateId);
                    }
                    
                    // Create form to POST chart data
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'export-report-pdf.php?' + params.toString();
                    form.target = '_blank';
                    
                    // Add member filter
                    if (memberFilter) {
                        const memberInput = document.createElement('input');
                        memberInput.type = 'hidden';
                        memberInput.name = 'member_id';
                        memberInput.value = memberFilter;
                        form.appendChild(memberInput);
                    }
                    
                    // Add chart images
                    const chartsInput = document.createElement('input');
                    chartsInput.type = 'hidden';
                    chartsInput.name = 'charts';
                    chartsInput.value = JSON.stringify(chartImages);
                    form.appendChild(chartsInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                }, 500); // Wait 500ms for charts to render
            } else if (format === 'excel' || format === 'csv') {
                // Create export request
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'export-report.php';
                
                const formatInput = document.createElement('input');
                formatInput.type = 'hidden';
                formatInput.name = 'format';
                formatInput.value = format;
                form.appendChild(formatInput);
                
                const dateFromInput = document.createElement('input');
                dateFromInput.type = 'hidden';
                dateFromInput.name = 'date_from';
                dateFromInput.value = document.querySelector('input[name=\"date_from\"]').value;
                form.appendChild(dateFromInput);
                
                const dateToInput = document.createElement('input');
                dateToInput.type = 'hidden';
                dateToInput.name = 'date_to';
                dateToInput.value = document.querySelector('input[name=\"date_to\"]').value;
                form.appendChild(dateToInput);
                
                // Add member filter
                if (memberFilter) {
                    const memberInput = document.createElement('input');
                    memberInput.type = 'hidden';
                    memberInput.name = 'member_id';
                    memberInput.value = memberFilter;
                    form.appendChild(memberInput);
                }
                
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            }
        }
        
        // Create additional charts for advanced analytics
        <?php if (!empty($ratioAnalysis)): ?>
        const ratioCtx = document.getElementById('ratioChart');
        if (ratioCtx) {
            window.ratioChart = new Chart(ratioCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($ratioAnalysis, 'MonthName')); ?>,
                    datasets: [{
                        label: 'Collection/Distribution Ratio (%)',
                        data: <?php echo json_encode(array_column($ratioAnalysis, 'CollectionRatio')); ?>,
                        borderColor: '#9334e6',
                        backgroundColor: 'rgba(147, 52, 230, 0.1)',
                        tension: 0.4,
                        fill: true
                    }, {
                        label: 'Net Balance',
                        data: <?php echo json_encode(array_column($ratioAnalysis, 'NetBalance')); ?>,
                        borderColor: '#0d9488',
                        backgroundColor: 'rgba(13, 148, 136, 0.1)',
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: { display: true, text: 'Ratio (%)' }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: { display: true, text: 'Net Balance (₹)' },
                            grid: { drawOnChartArea: false }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
        
        <?php if (!empty($timeDistribution)): ?>
        const timeCtx = document.getElementById('timeDistributionChart');
        if (timeCtx) {
            window.timeChart = new Chart(timeCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($timeDistribution, 'Hour')); ?>.map(h => h + ':00'),
                    datasets: [{
                        label: 'Transactions',
                        data: <?php echo json_encode(array_column($timeDistribution, 'TransactionCount')); ?>,
                        backgroundColor: 'rgba(26, 115, 232, 0.6)',
                        borderColor: '#1a73e8',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }
        <?php endif; ?>
        
        <?php if (!empty($growthAnalysis)): ?>
        const growthCtx = document.getElementById('growthRateChart');
        if (growthCtx) {
            const growthRates = <?php echo json_encode($growthAnalysis); ?>.map((item, index, arr) => {
                if (index === 0 || !item.PrevMonthCollection || item.PrevMonthCollection == 0) return 0;
                return ((item.MonthlyCollection - item.PrevMonthCollection) / item.PrevMonthCollection * 100).toFixed(2);
            });
            
            window.growthChart = new Chart(growthCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode(array_column($growthAnalysis, 'Month')); ?>,
                    datasets: [{
                        label: 'Growth Rate (%)',
                        data: growthRates,
                        backgroundColor: growthRates.map(rate => rate >= 0 ? 'rgba(52, 168, 83, 0.6)' : 'rgba(234, 67, 53, 0.6)'),
                        borderColor: growthRates.map(rate => rate >= 0 ? '#34a853' : '#ea4335'),
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Growth: ' + context.parsed.y + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Growth Rate (%)' }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
        
        // Custom Chart Builder Functions
        let customChartInstance = null;
        let currentChartType = 'line';
        
        function toggleChartBuilder() {
            const panel = document.getElementById('chartBuilderPanel');
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        }
        
        function selectChartType(type) {
            currentChartType = type;
            // Remove active class from all options
            document.querySelectorAll('.chart-option').forEach(option => {
                option.classList.remove('active');
            });
            // Add active class to selected option
            event.currentTarget.classList.add('active');
            updateChartPreview();
        }
        
        function updateChartPreview() {
            // This function can be used for real-time preview in future enhancement
            console.log('Chart configuration updated');
        }
        
        function generateCustomChart() {
            // Get selected X-Axis column
            const xAxis = document.getElementById('xAxisColumn').value;
            if (!xAxis) {
                alert('Please select an X-Axis column');
                return;
            }
            
            // Get selected Y-Axis metrics
            const yMetrics = [];
            const checkboxes = document.querySelectorAll('#yAxisMetrics input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    yMetrics.push(checkbox.value);
                }
            });
            
            // Validate at least one metric is selected
            if (yMetrics.length === 0) {
                alert('Please select at least one Y-Axis metric');
                return;
            }
            
            // Get aggregation method
            const aggregation = document.getElementById('aggregationMethod').value;
            
            // Get chart options
            const showLegend = document.getElementById('showLegend').checked;
            const showGridLines = document.getElementById('showGridLines').checked;
            const showDataLabels = document.getElementById('showDataLabels').checked;
            const enableAnimation = document.getElementById('enableAnimation').checked;
            const stackedBars = document.getElementById('stackedBars').checked;
            
            // Get date range from main filters
            const startDate = document.getElementById('startDate') ? document.getElementById('startDate').value : '';
            const endDate = document.getElementById('endDate') ? document.getElementById('endDate').value : '';
            
            // Show loading state
            const container = document.getElementById('customChartContainer');
            container.style.display = 'block';
            
            // Prepare request data
            const requestData = {
                action: 'getCustomChartData',
                chartType: currentChartType,
                xAxis: xAxis,
                yMetrics: yMetrics,
                aggregation: aggregation,
                startDate: startDate,
                endDate: endDate
            };
            
            // Fetch data and render chart
            fetch('fetch-custom-chart-data.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            })
            .then(response => response.json())
            .then(data => {
                console.log('Chart data response:', data);
                if (data.error) {
                    console.error('Chart generation error:', data.error);
                    if (data.details) {
                        console.error('Error details:', data.details);
                    }
                    alert('Error: ' + data.error);
                    return;
                }
                
                renderCustomChart(data, {
                    type: currentChartType,
                    showLegend: showLegend,
                    showGridLines: showGridLines,
                    showDataLabels: showDataLabels,
                    enableAnimation: enableAnimation,
                    stacked: stackedBars
                });
            })
            .catch(error => {
                console.error('Error fetching chart data:', error);
                alert('Failed to generate chart. Please try again.');
            });
        }
        
        function renderCustomChart(data, options) {
            // Destroy existing chart if any
            if (customChartInstance) {
                customChartInstance.destroy();
            }
            
            const ctx = document.getElementById('customChart');
            
            // Prepare datasets
            const datasets = data.datasets.map((dataset, index) => {
                const colors = [
                    '#1a73e8', '#34a853', '#fbbc04', '#ea4335', '#9333ea', '#06b6d4'
                ];
                const color = colors[index % colors.length];
                
                return {
                    label: dataset.label,
                    data: dataset.data,
                    backgroundColor: options.type === 'line' || options.type === 'scatter' ? 
                        color.replace(')', ', 0.2)').replace('rgb', 'rgba') : 
                        color.replace(')', ', 0.6)').replace('rgb', 'rgba'),
                    borderColor: color,
                    borderWidth: 2,
                    fill: options.type === 'area',
                    tension: 0.4,
                    pointRadius: options.type === 'scatter' || options.type === 'bubble' ? 6 : 3,
                    pointHoverRadius: options.type === 'scatter' || options.type === 'bubble' ? 8 : 5
                };
            });
            
            // Chart configuration
            const config = {
                type: options.type === 'area' ? 'line' : options.type,
                data: {
                    labels: data.labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: options.enableAnimation ? 1000 : 0
                    },
                    plugins: {
                        legend: {
                            display: options.showLegend,
                            position: 'top'
                        },
                        tooltip: {
                            enabled: true,
                            mode: 'index',
                            intersect: false
                        },
                        datalabels: options.showDataLabels ? {
                            display: true,
                            color: '#666',
                            font: { size: 10 }
                        } : false
                    },
                    scales: {}
                }
            };
            
            // Add scales for non-pie/doughnut charts
            if (!['pie', 'doughnut', 'radar', 'polarArea'].includes(options.type)) {
                config.options.scales = {
                    x: {
                        grid: { display: options.showGridLines },
                        stacked: options.stacked && (options.type === 'bar' || options.type === 'horizontalBar')
                    },
                    y: {
                        grid: { display: options.showGridLines },
                        beginAtZero: true,
                        stacked: options.stacked && (options.type === 'bar' || options.type === 'horizontalBar')
                    }
                };
            }
            
            // Handle horizontal bar
            if (options.type === 'horizontalBar') {
                config.type = 'bar';
                config.options.indexAxis = 'y';
            }
            
            // Create chart
            customChartInstance = new Chart(ctx, config);
            
            // Update chart title
            const xAxisLabel = document.getElementById('xAxisColumn').selectedOptions[0].text;
            const metricsCount = data.datasets.length;
            document.getElementById('customChartTitle').textContent = 
                `${xAxisLabel} vs ${metricsCount} Metric${metricsCount > 1 ? 's' : ''} (${options.type.charAt(0).toUpperCase() + options.type.slice(1)} Chart)`;
        }
        
        function resetChartBuilder() {
            // Reset chart type selection
            currentChartType = 'line';
            document.querySelectorAll('.chart-option').forEach(option => {
                option.classList.remove('active');
            });
            document.querySelector('.chart-option[onclick*="line"]').classList.add('active');
            
            // Reset X-Axis
            document.getElementById('xAxisColumn').value = '';
            
            // Uncheck all Y-Axis metrics
            document.querySelectorAll('#yAxisMetrics input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            
            // Reset aggregation
            document.getElementById('aggregationMethod').value = 'SUM';
            
            // Reset options
            document.getElementById('showLegend').checked = true;
            document.getElementById('showGridLines').checked = true;
            document.getElementById('showDataLabels').checked = false;
            document.getElementById('enableAnimation').checked = true;
            document.getElementById('stackedBars').checked = false;
            
            // Hide custom chart
            document.getElementById('customChartContainer').style.display = 'none';
            
            console.log('Chart builder reset');
        }
        
        function saveChartTemplate() {
            const xAxis = document.getElementById('xAxisColumn').value;
            const yMetrics = [];
            document.querySelectorAll('#yAxisMetrics input[type="checkbox"]:checked').forEach(checkbox => {
                yMetrics.push(checkbox.value);
            });
            
            if (!xAxis || yMetrics.length === 0) {
                alert('Please configure a chart before saving');
                return;
            }
            
            const template = {
                chartType: currentChartType,
                xAxis: xAxis,
                yMetrics: yMetrics,
                aggregation: document.getElementById('aggregationMethod').value,
                options: {
                    showLegend: document.getElementById('showLegend').checked,
                    showGridLines: document.getElementById('showGridLines').checked,
                    showDataLabels: document.getElementById('showDataLabels').checked,
                    enableAnimation: document.getElementById('enableAnimation').checked,
                    stackedBars: document.getElementById('stackedBars').checked
                }
            };
            
            // Save to localStorage
            const templates = JSON.parse(localStorage.getItem('chartTemplates') || '[]');
            const templateName = prompt('Enter a name for this template:');
            
            if (templateName) {
                template.name = templateName;
                template.createdAt = new Date().toISOString();
                templates.push(template);
                localStorage.setItem('chartTemplates', JSON.stringify(templates));
                alert('Template saved successfully!');
            }
        }
        
        function editCustomChart() {
            document.getElementById('chartBuilderPanel').style.display = 'block';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        function exportCustomChart() {
            if (!customChartInstance) {
                alert('No chart to export');
                return;
            }
            
            // Export as PNG image
            const link = document.createElement('a');
            link.download = 'custom-chart-' + new Date().getTime() + '.png';
            link.href = customChartInstance.toBase64Image();
            link.click();
        }
        
        function closeCustomChart() {
            document.getElementById('customChartContainer').style.display = 'none';
            if (customChartInstance) {
                customChartInstance.destroy();
                customChartInstance = null;
            }
        }
        
    </script>
    <script src="theme-manager.js"></script>
</body>
</html>
