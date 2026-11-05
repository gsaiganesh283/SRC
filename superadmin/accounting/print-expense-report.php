<?php
/**
 * Advanced Print Expense Report
 * Generate comprehensive printable expense reports
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');
$logoUrl = getWebsiteLogo(); // Get logo for print header

// Check if expenses table exists
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'expenses'");
if ($tableCheckStmt->rowCount() === 0) {
    header('Location: setup-expenses.php');
    exit;
}

// Get filter parameters
$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01', strtotime('-11 months'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$category = isset($_GET['category']) ? $_GET['category'] : 'all';
$reportFormat = isset($_GET['format']) ? $_GET['format'] : 'detailed';
$includeCharts = isset($_GET['charts']) ? $_GET['charts'] === '1' : true;
$groupBy = isset($_GET['group_by']) ? $_GET['group_by'] : 'date';

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch categories
$catStmt = $pdo->query("SELECT DISTINCT Category FROM expenses ORDER BY Category");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Build query conditions
$whereConditions = ["ExpenseDate BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($selectedYear !== 'all') {
    $whereConditions[] = "YearID = ?";
    $params[] = $selectedYear;
}

if ($category !== 'all') {
    $whereConditions[] = "Category = ?";
    $params[] = $category;
}

$whereClause = " WHERE " . implode(" AND ", $whereConditions);

// Summary statistics
$summaryQuery = "SELECT 
    COUNT(*) as total_count,
    IFNULL(SUM(Amount), 0) as total_amount,
    IFNULL(AVG(Amount), 0) as avg_amount,
    IFNULL(MIN(Amount), 0) as min_amount,
    IFNULL(MAX(Amount), 0) as max_amount
    FROM expenses" . $whereClause;
$summaryStmt = $pdo->prepare($summaryQuery);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// Category breakdown
$categoryQuery = "SELECT 
    Category,
    COUNT(*) as expense_count,
    IFNULL(SUM(Amount), 0) as total_amount
    FROM expenses" . $whereClause . "
    GROUP BY Category
    ORDER BY total_amount DESC";
$categoryStmt = $pdo->prepare($categoryQuery);
$categoryStmt->execute($params);
$categoryBreakdown = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

// Monthly summary
$monthlyQuery = "SELECT 
    DATE_FORMAT(ExpenseDate, '%Y-%m') as month_key,
    DATE_FORMAT(ExpenseDate, '%b %Y') as month_label,
    COUNT(*) as expense_count,
    IFNULL(SUM(Amount), 0) as total_amount
    FROM expenses" . $whereClause . "
    GROUP BY month_key
    ORDER BY month_key ASC";
$monthlyStmt = $pdo->prepare($monthlyQuery);
$monthlyStmt->execute($params);
$monthlyTrend = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

// Payment mode breakdown
$paymentQuery = "SELECT 
    PaymentMode,
    COUNT(*) as expense_count,
    IFNULL(SUM(Amount), 0) as total_amount
    FROM expenses" . $whereClause . "
    GROUP BY PaymentMode
    ORDER BY total_amount DESC";
$paymentStmt = $pdo->prepare($paymentQuery);
$paymentStmt->execute($params);
$paymentBreakdown = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

// Top vendors
$vendorQuery = "SELECT 
    IFNULL(VendorName, 'Not Specified') as VendorName,
    COUNT(*) as expense_count,
    IFNULL(SUM(Amount), 0) as total_amount
    FROM expenses" . $whereClause . "
    GROUP BY VendorName
    ORDER BY total_amount DESC
    LIMIT 15";
$vendorStmt = $pdo->prepare($vendorQuery);
$vendorStmt->execute($params);
$topVendors = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

// Detailed expense list
switch ($groupBy) {
    case 'category':
        $orderBy = 'Category, ExpenseDate DESC';
        break;
    case 'vendor':
        $orderBy = 'VendorName, ExpenseDate DESC';
        break;
    case 'amount':
        $orderBy = 'Amount DESC';
        break;
    default:
        $orderBy = 'ExpenseDate DESC, ExpenseID DESC';
}

$detailQuery = "SELECT e.*, ay.YearName 
    FROM expenses e 
    LEFT JOIN academic_years ay ON e.YearID = ay.YearID" . 
    $whereClause . " ORDER BY " . $orderBy;
$detailStmt = $pdo->prepare($detailQuery);
$detailStmt->execute($params);
$expenses = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

// Get year name for header
$selectedYearName = 'All Years';
if ($selectedYear !== 'all') {
    foreach ($academicYears as $year) {
        if ($year['YearID'] == $selectedYear) {
            $selectedYearName = $year['YearName'];
            break;
        }
    }
}

// Build chart data
$chartLabels = array_column($monthlyTrend, 'month_label');
$chartData = array_column($monthlyTrend, 'total_amount');
$catChartLabels = array_column($categoryBreakdown, 'Category');
$catChartData = array_column($categoryBreakdown, 'total_amount');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Report - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../shared-styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* Screen Styles */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .page-header h1 {
            font-size: 28px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .page-header h1 i { color: #10b981; }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            margin-bottom: 20px;
        }
        
        .back-link:hover { color: #10b981; }
        
        .btn-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }
        
        .filters-card {
            background: var(--bg-secondary);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 10px 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            border-color: #10b981;
            outline: none;
        }
        
        .btn-filter {
            padding: 10px 20px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            font-weight: 600;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        
        /* Report Content */
        .report-container {
            background: var(--bg-secondary);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .report-header {
            padding: 30px;
            text-align: center;
            border-bottom: 2px solid var(--border-color);
        }
        
        .report-logo {
            max-height: 60px;
            margin-bottom: 15px;
        }
        
        .report-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .report-subtitle {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .report-period {
            margin-top: 10px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .report-body {
            padding: 30px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-color);
            color: var(--text-primary);
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 24px;
            margin-bottom: 35px;
            justify-content: center;
        }
        
        .summary-box {
            background: var(--bg-tertiary);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        
        .summary-box h4 {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .summary-box .value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .summary-box.highlight .value { color: #10b981; }
        
        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        
        .breakdown-table th,
        .breakdown-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .breakdown-table th {
            background: var(--bg-tertiary);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-secondary);
        }
        
        .breakdown-table tr:hover td {
            background: var(--bg-tertiary);
        }
        
        .amount-cell {
            text-align: right;
            font-weight: 600;
        }
        
        .charts-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .chart-container {
            background: var(--bg-tertiary);
            padding: 20px;
            border-radius: 10px;
        }
        
        .chart-container h4 {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 15px;
            text-align: center;
        }
        
        .expenses-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        .expenses-table th,
        .expenses-table td {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
        }
        
        .expenses-table th {
            background: var(--bg-tertiary);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
        }
        
        .expenses-table tr:nth-child(even) td {
            background: var(--bg-tertiary);
        }
        
        .report-footer {
            padding: 20px 30px;
            border-top: 2px solid var(--border-color);
            text-align: center;
            color: var(--text-secondary);
            font-size: 12px;
        }
        
        .signature-section {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 40px;
            margin-top: 50px;
            padding-top: 30px;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-line {
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 10px;
            padding-bottom: 40px;
        }
        
        .signature-label {
            font-size: 12px;
            color: var(--text-secondary);
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white !important;
                color: black !important;
            }
            
            .navbar, .back-link, .filters-card, .btn-group, #themeToggle,
            .no-print {
                display: none !important;
            }
            
            .container {
                max-width: 100% !important;
                padding: 0 !important;
            }
            
            .report-container {
                box-shadow: none !important;
                border: none !important;
            }
            
            .report-header {
                padding: 20px !important;
            }
            
            .report-body {
                padding: 20px !important;
            }
            
            .summary-box, .chart-container, .breakdown-table th,
            .expenses-table th, .expenses-table tr:nth-child(even) td {
                background: #f8f9fa !important;
            }
            
            .charts-section {
                page-break-inside: avoid;
            }
            
            .expenses-table {
                page-break-inside: auto;
            }
            
            .expenses-table tr {
                page-break-inside: avoid;
            }
            
            .signature-section {
                page-break-inside: avoid;
            }
            
            @page {
                margin: 1cm;
                size: A4;
            }
        }
        
        @media (max-width: 768px) {
            .charts-section {
                grid-template-columns: 1fr;
            }
            
            .signature-section {
                grid-template-columns: 1fr;
                gap: 30px;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar no-print">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-calculator"></i>
                <span>Accounting Portal</span>
            </div>
            <div class="navbar-user">
                <button id="themeToggle" class="theme-toggle">
                    <i id="themeIcon" class="fas fa-moon"></i>
                    <span id="themeText">Dark</span>
                </button>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_name); ?></div>
                    <div class="user-role">Accountant</div>
                </div>
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                    <span>Dashboard</span>
                </a>
                <?php include "../includes/datetime-widget.php"; ?>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <a href="expense-reports.php" class="back-link no-print">
            <i class="fas fa-arrow-left"></i>
            Back to Reports
        </a>
        
        <div class="page-header no-print">
            <div>
                <h1><i class="fas fa-print"></i> Print Expense Report</h1>
                <p style="color: var(--text-secondary); margin-top: 8px;">Generate detailed printable expense reports</p>
            </div>
            <div class="btn-group">
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print Report
                </button>
                <a href="download-expense-report-pdf.php?<?php echo htmlspecialchars(http_build_query([
                    'year'       => $selectedYear,
                    'start_date' => $startDate,
                    'end_date'   => $endDate,
                    'category'   => $category,
                ])); ?>" class="btn btn-secondary" target="_blank">
                    <i class="fas fa-file-pdf"></i> Download PDF
                </a>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters-card no-print">
            <form method="GET">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Academic Year</label>
                        <select name="year">
                            <option value="all">All Years</option>
                            <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['YearID']; ?>" <?php echo $selectedYear == $year['YearID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['YearName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                    </div>
                    <div class="filter-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                    </div>
                    <div class="filter-group">
                        <label>Category</label>
                        <select name="category">
                            <option value="all">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Group By</label>
                        <select name="group_by">
                            <option value="date" <?php echo $groupBy === 'date' ? 'selected' : ''; ?>>Date</option>
                            <option value="category" <?php echo $groupBy === 'category' ? 'selected' : ''; ?>>Category</option>
                            <option value="vendor" <?php echo $groupBy === 'vendor' ? 'selected' : ''; ?>>Vendor</option>
                            <option value="amount" <?php echo $groupBy === 'amount' ? 'selected' : ''; ?>>Amount</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Include Charts</label>
                        <div class="checkbox-group">
                            <input type="checkbox" name="charts" value="1" id="includeCharts" <?php echo $includeCharts ? 'checked' : ''; ?>>
                            <label for="includeCharts" style="text-transform: none;">Show Charts</label>
                        </div>
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn-filter">
                            <i class="fas fa-sync"></i> Generate
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Report Content -->
        <div class="report-container" id="reportContent">
            <!-- Report Header -->
            <div class="report-header">
                <?php if ($logoUrl): ?>
                    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Logo" class="report-logo">
                <?php endif; ?>
                <h1 class="report-title"><?php echo htmlspecialchars($siteName); ?></h1>
                <div class="report-subtitle">Expense Report</div>
                <div class="report-period">
                    <strong>Period:</strong> <?php echo date('M d, Y', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?>
                    <?php if ($selectedYear !== 'all'): ?>
                        | <strong>Year:</strong> <?php echo htmlspecialchars($selectedYearName); ?>
                    <?php endif; ?>
                    <?php if ($category !== 'all'): ?>
                        | <strong>Category:</strong> <?php echo htmlspecialchars($category); ?>
                    <?php endif; ?>
                    <br>
                    <small>Generated on: <?php echo date('M d, Y h:i A'); ?></small>
                </div>
            </div>
            
            <div class="report-body">
                <!-- Summary Statistics -->
                <h3 class="section-title"><i class="fas fa-chart-pie"></i> Summary Statistics</h3>
                <div class="summary-grid">
                    <div class="summary-box highlight">
                        <h4>Total Expenses</h4>
                        <div class="value">₹<?php echo number_format($summary['total_amount'], 2); ?></div>
                    </div>
                    <div class="summary-box">
                        <h4>Total Transactions</h4>
                        <div class="value"><?php echo number_format($summary['total_count']); ?></div>
                    </div>
                    <div class="summary-box">
                        <h4>Average Expense</h4>
                        <div class="value">₹<?php echo number_format($summary['avg_amount'], 2); ?></div>
                    </div>
                    <div class="summary-box">
                        <h4>Highest Expense</h4>
                        <div class="value">₹<?php echo number_format($summary['max_amount'], 2); ?></div>
                    </div>
                    <div class="summary-box">
                        <h4>Lowest Expense</h4>
                        <div class="value">₹<?php echo number_format($summary['min_amount'], 2); ?></div>
                    </div>
                </div>
                
                <?php if ($includeCharts && !empty($monthlyTrend)): ?>
                <!-- Charts Section -->
                <div class="charts-section no-print">
                    <div class="chart-container">
                        <h4>Monthly Expense Trend</h4>
                        <canvas id="trendChart" height="200"></canvas>
                    </div>
                    <div class="chart-container">
                        <h4>Category Distribution</h4>
                        <canvas id="categoryChart" height="200"></canvas>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Category Breakdown -->
                <h3 class="section-title"><i class="fas fa-tags"></i> Category Breakdown</h3>
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Transactions</th>
                            <th class="amount-cell">Total Amount</th>
                            <th class="amount-cell">Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoryBreakdown as $cat): 
                            $percent = $summary['total_amount'] > 0 ? ($cat['total_amount'] / $summary['total_amount']) * 100 : 0;
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($cat['Category']); ?></td>
                                <td><?php echo number_format($cat['expense_count']); ?></td>
                                <td class="amount-cell">₹<?php echo number_format($cat['total_amount'], 2); ?></td>
                                <td class="amount-cell"><?php echo number_format($percent, 1); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold; background: var(--bg-tertiary);">
                            <td>Total</td>
                            <td><?php echo number_format($summary['total_count']); ?></td>
                            <td class="amount-cell">₹<?php echo number_format($summary['total_amount'], 2); ?></td>
                            <td class="amount-cell">100%</td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- Monthly Summary -->
                <h3 class="section-title"><i class="fas fa-calendar-alt"></i> Monthly Summary</h3>
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Transactions</th>
                            <th class="amount-cell">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyTrend as $month): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($month['month_label']); ?></td>
                                <td><?php echo number_format($month['expense_count']); ?></td>
                                <td class="amount-cell">₹<?php echo number_format($month['total_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Payment Mode Breakdown -->
                <h3 class="section-title"><i class="fas fa-credit-card"></i> Payment Mode Breakdown</h3>
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th>Payment Mode</th>
                            <th>Transactions</th>
                            <th class="amount-cell">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($paymentBreakdown as $payment): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($payment['PaymentMode']); ?></td>
                                <td><?php echo number_format($payment['expense_count']); ?></td>
                                <td class="amount-cell">₹<?php echo number_format($payment['total_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Top Vendors -->
                <?php if (!empty($topVendors)): ?>
                <h3 class="section-title"><i class="fas fa-store"></i> Top Vendors</h3>
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th>Transactions</th>
                            <th class="amount-cell">Total Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topVendors as $vendor): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($vendor['VendorName']); ?></td>
                                <td><?php echo number_format($vendor['expense_count']); ?></td>
                                <td class="amount-cell">₹<?php echo number_format($vendor['total_amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <!-- Detailed Expense List -->
                <h3 class="section-title"><i class="fas fa-list"></i> Detailed Expense List</h3>
                <table class="expenses-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Vendor</th>
                            <th>Payment</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($expenses)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px;">No expenses found for the selected criteria</td>
                            </tr>
                        <?php else: ?>
                            <?php $i = 1; foreach ($expenses as $expense): ?>
                                <tr>
                                    <td><?php echo $i++; ?></td>
                                    <td><?php echo date('M d, Y', strtotime($expense['ExpenseDate'])); ?></td>
                                    <td><?php echo htmlspecialchars($expense['Description']); ?></td>
                                    <td><?php echo htmlspecialchars($expense['Category']); ?></td>
                                    <td><?php echo htmlspecialchars($expense['VendorName'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($expense['PaymentMode']); ?></td>
                                    <td style="text-align: right; font-weight: 600;">₹<?php echo number_format($expense['Amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="font-weight: bold; background: var(--bg-tertiary);">
                                <td colspan="6" style="text-align: right;">Grand Total:</td>
                                <td style="text-align: right;">₹<?php echo number_format($summary['total_amount'], 2); ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Signature Section -->
                <div class="signature-section">
                    <div class="signature-box">
                        <div class="signature-line"></div>
                        <div class="signature-label">Prepared By</div>
                    </div>
                    <div class="signature-box">
                        <div class="signature-line"></div>
                        <div class="signature-label">Verified By</div>
                    </div>
                    <div class="signature-box">
                        <div class="signature-line"></div>
                        <div class="signature-label">Approved By</div>
                    </div>
                </div>
            </div>
            
            <div class="report-footer">
                This is a computer-generated report from <?php echo htmlspecialchars($siteName); ?> Accounting System.
                <br>
                Report generated by: <?php echo htmlspecialchars($superadmin_name); ?> | 
                Date: <?php echo date('F d, Y h:i A'); ?>
            </div>
        </div>
    </div>
    
    <script src="../theme-manager.js"></script>
    <?php if ($includeCharts && !empty($monthlyTrend)): ?>
    <script>
        // Monthly Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    label: 'Expenses',
                    data: <?php echo json_encode($chartData); ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
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
        
        // Category Distribution Chart
        const catCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(catCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($catChartLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($catChartData); ?>,
                    backgroundColor: [
                        '#10b981', '#3b82f6', '#f59e0b', '#ef4444', 
                        '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16',
                        '#f97316', '#6366f1'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
