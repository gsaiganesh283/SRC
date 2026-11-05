<?php
/**
 * Expense Reports Page
 * Generate and view expense analysis reports
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

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
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'summary';

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build base query conditions
$whereConditions = ["ExpenseDate BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($selectedYear !== 'all') {
    $whereConditions[] = "YearID = ?";
    $params[] = $selectedYear;
}

$whereClause = " WHERE " . implode(" AND ", $whereConditions);

// Summary Report
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

// Category-wise breakdown
$categoryQuery = "SELECT 
    Category,
    COUNT(*) as expense_count,
    IFNULL(SUM(Amount), 0) as total_amount,
    IFNULL(AVG(Amount), 0) as avg_amount
    FROM expenses" . $whereClause . "
    GROUP BY Category
    ORDER BY total_amount DESC";
$categoryStmt = $pdo->prepare($categoryQuery);
$categoryStmt->execute($params);
$categoryBreakdown = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

// Monthly trend
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
    IFNULL(VendorName, 'Unknown Vendor') as VendorName,
    COUNT(*) as expense_count,
    IFNULL(SUM(Amount), 0) as total_amount
    FROM expenses" . $whereClause . "
    GROUP BY VendorName
    ORDER BY total_amount DESC
    LIMIT 10";
$vendorStmt = $pdo->prepare($vendorQuery);
$vendorStmt->execute($params);
$topVendors = $vendorStmt->fetchAll(PDO::FETCH_ASSOC);

// Year-over-year comparison (if applicable)
$yoyQuery = "SELECT 
    ay.YearName,
    COUNT(*) as expense_count,
    IFNULL(SUM(e.Amount), 0) as total_amount
    FROM expenses e
    JOIN academic_years ay ON e.YearID = ay.YearID
    WHERE e.ExpenseDate BETWEEN ? AND ?
    GROUP BY e.YearID
    ORDER BY ay.YearName";
$yoyStmt = $pdo->prepare($yoyQuery);
$yoyStmt->execute([$startDate, $endDate]);
$yearComparison = $yoyStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Reports - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../shared-styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .page-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        
        .page-header h1 i {
            color: #10b981;
        }
        
        .filters-card {
            background: var(--bg-secondary);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            align-items: end;
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
        }
        
        .filter-group select,
        .filter-group input {
            padding: 12px 15px;
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
        
        .btn-apply {
            padding: 12px 24px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: var(--bg-secondary);
            padding: 25px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            text-align: center;
            border-top: 4px solid;
        }
        
        .summary-card.total { border-color: #ef4444; }
        .summary-card.count { border-color: #3b82f6; }
        .summary-card.avg { border-color: #f59e0b; }
        .summary-card.max { border-color: #10b981; }
        
        .summary-card h3 {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }
        
        .summary-card .value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .report-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .report-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow);
        }
        
        .report-card.full-width {
            grid-column: span 2;
        }
        
        .report-card h2 {
            font-size: 18px;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .report-card h2 i {
            color: #10b981;
        }
        
        .breakdown-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            background: var(--bg-tertiary);
            border-radius: 8px;
            margin-bottom: 10px;
        }
        
        .breakdown-item:hover {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
        }
        
        .breakdown-item .name {
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .breakdown-item .name i {
            color: #10b981;
        }
        
        .breakdown-item .stats {
            text-align: right;
        }
        
        .breakdown-item .amount {
            font-weight: 700;
            color: #ef4444;
            font-size: 16px;
        }
        
        .breakdown-item .count {
            font-size: 12px;
            color: var(--text-secondary);
        }
        
        .progress-bar-container {
            flex-grow: 1;
            margin: 0 20px;
        }
        
        .progress-bar {
            height: 8px;
            background: var(--bg-tertiary);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 4px;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .data-table th {
            background: var(--bg-tertiary);
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .data-table tr:hover td {
            background: var(--bg-tertiary);
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            color: #10b981;
        }
        
        @media (max-width: 1024px) {
            .report-grid {
                grid-template-columns: 1fr;
            }
            
            .report-card.full-width {
                grid-column: span 1;
            }
        }
        
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media print {
            .navbar, .filters-card, .back-link {
                display: none !important;
            }
            
            body {
                background: white !important;
            }
            
            .report-card, .summary-card {
                break-inside: avoid;
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar" style="background: linear-gradient(135deg, #10b981, #059669);">
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
                    <div class="user-role">Accounting Manager</div>
                </div>
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                    <span>Dashboard</span>
                </a>
                <a href="../logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
                <?php include "../includes/datetime-widget.php"; ?>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
        </a>
        
        <div class="page-header">
            <div>
                <h1><i class="fas fa-chart-bar"></i> Expense Reports</h1>
                <p style="color: var(--text-secondary); margin-top: 8px;">Analyze expense patterns and generate reports</p>
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="print-expense-report.php?year=<?php echo $selectedYear; ?>&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" class="btn-apply" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed); text-decoration: none;">
                    <i class="fas fa-file-alt"></i>
                    Advanced Report
                </a>
                <button onclick="window.print()" class="btn-apply" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                    <i class="fas fa-print"></i>
                    Print Report
                </button>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" id="reportFilters">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Academic Year</label>
                        <select name="year">
                            <option value="all" <?php echo $selectedYear === 'all' ? 'selected' : ''; ?>>All Years</option>
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
                        <label>&nbsp;</label>
                        <button type="submit" class="btn-apply">
                            <i class="fas fa-filter"></i>
                            Apply Filters
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card total">
                <h3>Total Expenses</h3>
                <div class="value">₹<?php echo number_format($summary['total_amount'], 2); ?></div>
            </div>
            <div class="summary-card count">
                <h3>Total Records</h3>
                <div class="value"><?php echo number_format($summary['total_count']); ?></div>
            </div>
            <div class="summary-card avg">
                <h3>Average Expense</h3>
                <div class="value">₹<?php echo number_format($summary['avg_amount'], 2); ?></div>
            </div>
            <div class="summary-card max">
                <h3>Largest Expense</h3>
                <div class="value">₹<?php echo number_format($summary['max_amount'], 2); ?></div>
            </div>
        </div>
        
        <!-- Report Grid -->
        <div class="report-grid">
            <!-- Monthly Trend Chart -->
            <div class="report-card full-width">
                <h2><i class="fas fa-chart-line"></i> Monthly Expense Trend</h2>
                <div class="chart-container">
                    <canvas id="monthlyTrendChart"></canvas>
                </div>
            </div>
            
            <!-- Category Breakdown -->
            <div class="report-card">
                <h2><i class="fas fa-tags"></i> Category Breakdown</h2>
                <?php if (empty($categoryBreakdown)): ?>
                    <p style="text-align: center; color: var(--text-secondary); padding: 40px;">No data available</p>
                <?php else: ?>
                    <ul class="breakdown-list">
                        <?php 
                        $maxCategoryAmount = max(array_column($categoryBreakdown, 'total_amount')) ?: 1;
                        foreach ($categoryBreakdown as $cat): 
                            $percentage = ($cat['total_amount'] / $maxCategoryAmount) * 100;
                        ?>
                            <li class="breakdown-item">
                                <span class="name">
                                    <i class="fas fa-folder"></i>
                                    <?php echo htmlspecialchars($cat['Category']); ?>
                                </span>
                                <div class="progress-bar-container">
                                    <div class="progress-bar">
                                        <div class="progress-bar-fill" style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                                <span class="stats">
                                    <span class="amount">₹<?php echo number_format($cat['total_amount'], 2); ?></span>
                                    <span class="count"><?php echo $cat['expense_count']; ?> records</span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <!-- Category Pie Chart -->
            <div class="report-card">
                <h2><i class="fas fa-chart-pie"></i> Category Distribution</h2>
                <div class="chart-container">
                    <canvas id="categoryPieChart"></canvas>
                </div>
            </div>
            
            <!-- Payment Mode Breakdown -->
            <div class="report-card">
                <h2><i class="fas fa-credit-card"></i> Payment Mode Analysis</h2>
                <?php if (empty($paymentBreakdown)): ?>
                    <p style="text-align: center; color: var(--text-secondary); padding: 40px;">No data available</p>
                <?php else: ?>
                    <ul class="breakdown-list">
                        <?php 
                        $maxPaymentAmount = max(array_column($paymentBreakdown, 'total_amount')) ?: 1;
                        foreach ($paymentBreakdown as $payment): 
                            $percentage = ($payment['total_amount'] / $maxPaymentAmount) * 100;
                        ?>
                            <li class="breakdown-item">
                                <span class="name">
                                    <i class="fas fa-money-bill-wave"></i>
                                    <?php echo htmlspecialchars($payment['PaymentMode']); ?>
                                </span>
                                <span class="stats">
                                    <span class="amount">₹<?php echo number_format($payment['total_amount'], 2); ?></span>
                                    <span class="count"><?php echo $payment['expense_count']; ?> records</span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <!-- Top Vendors -->
            <div class="report-card">
                <h2><i class="fas fa-store"></i> Top Vendors</h2>
                <?php if (empty($topVendors)): ?>
                    <p style="text-align: center; color: var(--text-secondary); padding: 40px;">No vendor data available</p>
                <?php else: ?>
                    <ul class="breakdown-list">
                        <?php foreach ($topVendors as $index => $vendor): ?>
                            <li class="breakdown-item">
                                <span class="name">
                                    <span style="background: linear-gradient(135deg, #10b981, #059669); color: white; width: 24px; height: 24px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600;"><?php echo $index + 1; ?></span>
                                    <?php echo htmlspecialchars($vendor['VendorName']); ?>
                                </span>
                                <span class="stats">
                                    <span class="amount">₹<?php echo number_format($vendor['total_amount'], 2); ?></span>
                                    <span class="count"><?php echo $vendor['expense_count']; ?> records</span>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            
            <!-- Year Comparison -->
            <div class="report-card full-width">
                <h2><i class="fas fa-calendar-alt"></i> Year-over-Year Comparison</h2>
                <?php if (empty($yearComparison)): ?>
                    <p style="text-align: center; color: var(--text-secondary); padding: 40px;">No yearly data available</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Academic Year</th>
                                <th>Total Expenses</th>
                                <th>Record Count</th>
                                <th>Avg per Record</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($yearComparison as $year): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($year['YearName']); ?></strong></td>
                                    <td style="color: #ef4444; font-weight: 600;">₹<?php echo number_format($year['total_amount'], 2); ?></td>
                                    <td><?php echo number_format($year['expense_count']); ?></td>
                                    <td>₹<?php echo $year['expense_count'] > 0 ? number_format($year['total_amount'] / $year['expense_count'], 2) : '0.00'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="../theme-manager.js"></script>
    <script>
        // Monthly Trend Chart
        const monthlyData = <?php echo json_encode($monthlyTrend); ?>;
        
        if (monthlyData.length > 0) {
            const ctx1 = document.getElementById('monthlyTrendChart').getContext('2d');
            new Chart(ctx1, {
                type: 'bar',
                data: {
                    labels: monthlyData.map(item => item.month_label),
                    datasets: [{
                        label: 'Monthly Expenses',
                        data: monthlyData.map(item => parseFloat(item.total_amount)),
                        backgroundColor: 'rgba(239, 68, 68, 0.6)',
                        borderColor: '#ef4444',
                        borderWidth: 2,
                        borderRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
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
        }
        
        // Category Pie Chart
        const categoryData = <?php echo json_encode($categoryBreakdown); ?>;
        
        if (categoryData.length > 0) {
            const colors = ['#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#64748b'];
            const ctx2 = document.getElementById('categoryPieChart').getContext('2d');
            new Chart(ctx2, {
                type: 'doughnut',
                data: {
                    labels: categoryData.map(item => item.Category),
                    datasets: [{
                        data: categoryData.map(item => parseFloat(item.total_amount)),
                        backgroundColor: categoryData.map((_, index) => colors[index % colors.length]),
                        borderWidth: 2,
                        borderColor: 'var(--bg-secondary)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                padding: 15
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
