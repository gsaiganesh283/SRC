<?php
/**
 * Accounting Portal Dashboard
 * Main hub for all accounting and expense management functions
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/link-target-helper.php';
require_once '../../includes/logo-helper.php';
require_once '../../includes/net-balance-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Get selected academic year filter
$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';

// Fetch academic years for filter dropdown
$yearsStmt = $pdo->query("SELECT YearID, YearName, FixedAmount, TotalCollection, TotalExpenses, BalanceAmount FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build WHERE clause for year filtering
$yearFilter = '';
$yearParams = [];
if ($selectedYear !== 'all') {
    $yearFilter = ' WHERE YearID = ?';
    $yearParams = [$selectedYear];
}

// Fetch accounting statistics
try {
    // Get total expenses
    $expenseQuery = "SELECT 
        IFNULL(SUM(Amount), 0) as total_expenses,
        COUNT(*) as expense_count
        FROM expenses";
    if ($selectedYear !== 'all') {
        $expenseQuery .= " WHERE YearID = ?";
        $stmt = $pdo->prepare($expenseQuery);
        $stmt->execute([$selectedYear]);
    } else {
        $stmt = $pdo->query($expenseQuery);
    }
    $expenseData = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_expenses = $expenseData['total_expenses'] ?? 0;
    $expense_count = $expenseData['expense_count'] ?? 0;
    
    // Get total collection amount
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT IFNULL(SUM(CollectionAmount), 0) as total FROM transactions");
    } else {
        $stmt = $pdo->prepare("SELECT IFNULL(SUM(CollectionAmount), 0) as total FROM transactions WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $total_collection = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get total fixed amount from academic years
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT IFNULL(SUM(FixedAmount), 0) as total FROM academic_years");
    } else {
        $stmt = $pdo->prepare("SELECT IFNULL(SUM(FixedAmount), 0) as total FROM academic_years WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $total_fixed_amount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get total distribution amount
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT IFNULL(SUM(DistributedAmount), 0) as total FROM transactions");
    } else {
        $stmt = $pdo->prepare("SELECT IFNULL(SUM(DistributedAmount), 0) as total FROM transactions WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $total_distribution = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get total interest
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT IFNULL(SUM(InterestAmount), 0) as total FROM transactions");
    } else {
        $stmt = $pdo->prepare("SELECT IFNULL(SUM(InterestAmount), 0) as total FROM transactions WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $total_interest = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get total change amount from academic years
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT IFNULL(SUM(ChangeAmount), 0) as total FROM academic_years");
    } else {
        $stmt = $pdo->prepare("SELECT IFNULL(SUM(ChangeAmount), 0) as total FROM academic_years WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $total_change_amount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Calculate net balance (Interest + Fixed Amount + Change Amount - Expenses)
    $net_balance = ($total_interest + $total_fixed_amount + $total_change_amount) - $total_expenses;
    
    // Get this month's expenses
    $monthExpenseQuery = "SELECT IFNULL(SUM(Amount), 0) as total FROM expenses 
        WHERE MONTH(ExpenseDate) = MONTH(CURDATE()) 
        AND YEAR(ExpenseDate) = YEAR(CURDATE())";
    if ($selectedYear !== 'all') {
        $monthExpenseQuery .= " AND YearID = ?";
        $stmt = $pdo->prepare($monthExpenseQuery);
        $stmt->execute([$selectedYear]);
    } else {
        $stmt = $pdo->query($monthExpenseQuery);
    }
    $month_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get this month's collection
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT IFNULL(SUM(CollectionAmount), 0) as total FROM transactions WHERE MONTH(TransactionDate) = MONTH(CURDATE()) AND YEAR(TransactionDate) = YEAR(CURDATE())");
    } else {
        $stmt = $pdo->prepare("SELECT IFNULL(SUM(CollectionAmount), 0) as total FROM transactions WHERE MONTH(TransactionDate) = MONTH(CURDATE()) AND YEAR(TransactionDate) = YEAR(CURDATE()) AND YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $month_collection = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get expense categories summary
    $categoryQuery = "SELECT Category, 
        IFNULL(SUM(Amount), 0) as total_amount,
        COUNT(*) as count
        FROM expenses";
    if ($selectedYear !== 'all') {
        $categoryQuery .= " WHERE YearID = ?";
    }
    $categoryQuery .= " GROUP BY Category ORDER BY total_amount DESC LIMIT 5";
    
    if ($selectedYear !== 'all') {
        $stmt = $pdo->prepare($categoryQuery);
        $stmt->execute([$selectedYear]);
    } else {
        $stmt = $pdo->query($categoryQuery);
    }
    $expense_by_category = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent expenses
    $recentExpenseQuery = "SELECT e.*, ay.YearName 
        FROM expenses e 
        LEFT JOIN academic_years ay ON e.YearID = ay.YearID";
    if ($selectedYear !== 'all') {
        $recentExpenseQuery .= " WHERE e.YearID = ?";
    }
    $recentExpenseQuery .= " ORDER BY e.ExpenseDate DESC, e.CreatedAt DESC LIMIT 10";
    
    if ($selectedYear !== 'all') {
        $stmt = $pdo->prepare($recentExpenseQuery);
        $stmt->execute([$selectedYear]);
    } else {
        $stmt = $pdo->query($recentExpenseQuery);
    }
    $recent_expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get monthly expense trend (last 12 months)
    $trendQuery = "SELECT 
        DATE_FORMAT(ExpenseDate, '%b %Y') as month,
        YEAR(ExpenseDate) as year,
        MONTH(ExpenseDate) as month_num,
        IFNULL(SUM(Amount), 0) as total_expense
        FROM expenses 
        WHERE ExpenseDate >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
    if ($selectedYear !== 'all') {
        $trendQuery .= " AND YearID = ?";
    }
    $trendQuery .= " GROUP BY YEAR(ExpenseDate), MONTH(ExpenseDate) ORDER BY year ASC, month_num ASC";
    
    if ($selectedYear !== 'all') {
        $stmt = $pdo->prepare($trendQuery);
        $stmt->execute([$selectedYear]);
    } else {
        $stmt = $pdo->query($trendQuery);
    }
    $expense_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get year-wise financial summary including ChangeAmount
    $yearSummaryStmt = $pdo->query("
        SELECT 
            ay.YearID, ay.YearName, ay.FixedAmount, ay.ChangeAmount,
            IFNULL(SUM(t.CollectionAmount), 0) as total_collection,
            IFNULL(SUM(t.InterestAmount), 0) as total_interest,
            IFNULL(SUM(t.DistributedAmount), 0) as total_distribution,
            IFNULL((SELECT SUM(Amount) FROM expenses WHERE YearID = ay.YearID), 0) as total_expenses
        FROM academic_years ay
        LEFT JOIN transactions t ON ay.YearID = t.YearID
        GROUP BY ay.YearID, ay.FixedAmount, ay.ChangeAmount
        ORDER BY ay.YearName DESC
    ");
    $year_summaries = $yearSummaryStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Accounting Dashboard Error: " . $e->getMessage());
    
    // Check if expenses table exists, if not set default values
    $tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'expenses'");
    $expensesTableExists = $tableCheckStmt->rowCount() > 0;
    
    if (!$expensesTableExists) {
        $createTableNeeded = true;
    }
    
    $total_expenses = 0;
    $expense_count = 0;
    $total_collection = 0;
    $total_fixed_amount = 0;
    $total_distribution = 0;
    $total_interest = 0;
    $total_change_amount = 0;
    $net_balance = 0;
    $month_expenses = 0;
    $month_collection = 0;
    $expense_by_category = [];
    $recent_expenses = [];
    $expense_trend = [];
    $year_summaries = [];
}

// Check if expenses table exists
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'expenses'");
$expensesTableExists = $tableCheckStmt->rowCount() > 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Portal - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../shared-styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .navbar-brand i {
            font-size: 32px;
        }
        
        .navbar-brand {
            font-size: 24px;
            font-weight: 700;
        }
        
        .page-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-header h1 {
            font-size: 32px;
            color: var(--text-primary);
            margin-bottom: 10px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .page-header p {
            color: var(--text-secondary);
            font-size: 16px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 24px;
            margin-bottom: 35px;
            justify-content: center;
        }
        
        .stat-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }
        
        .stat-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 12px 24px rgba(0,0,0,0.2);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--card-color-1), var(--card-color-2));
        }
        
        .stat-card::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, var(--card-color-1) 0%, transparent 70%);
            opacity: 0.05;
            transition: opacity 0.3s ease;
        }
        
        .stat-card:hover::after {
            opacity: 0.1;
        }
        
        .stat-card.green { --card-color-1: #10b981; --card-color-2: #059669; }
        .stat-card.red { --card-color-1: #ef4444; --card-color-2: #dc2626; }
        .stat-card.blue { --card-color-1: #3b82f6; --card-color-2: #2563eb; }
        .stat-card.purple { --card-color-1: #8b5cf6; --card-color-2: #7c3aed; }
        .stat-card.orange { --card-color-1: #f97316; --card-color-2: #ea580c; }
        .stat-card.teal { --card-color-1: #14b8a6; --card-color-2: #0d9488; }
        .stat-card.indigo { --card-color-1: #667eea; --card-color-2: #764ba2; }
        .stat-card.pink { --card-color-1: #ec4899; --card-color-2: #db2777; }
        .stat-card.yellow { --card-color-1: #eab308; --card-color-2: #ca8a04; }
        .stat-card.cyan { --card-color-1: #06b6d4; --card-color-2: #0891b2; }
        
        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 15px;
        }
        
        .stat-title {
            font-size: 14px;
            color: var(--text-secondary);
            text-transform: uppercase;
            font-weight: 600;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: linear-gradient(135deg, var(--card-color-1), var(--card-color-2));
            color: white;
            animation: pulse-icon 2s ease-in-out infinite;
        }
        
        @keyframes pulse-icon {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 5px;
        }
        
        .stat-subtitle {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 35px;
            justify-content: center;
            max-width: 1400px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .action-btn {
            background: var(--bg-secondary);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-decoration: none;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            border-color: #10b981;
            background: var(--bg-tertiary);
            transform: translateX(5px);
        }
        
        .action-btn i {
            font-size: 32px;
            color: #10b981;
        }
        
        .action-btn-content h3 {
            font-size: 16px;
            margin-bottom: 5px;
            color: var(--text-primary);
        }
        
        .action-btn-content p {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .action-btn.event-action {
            border-color: rgba(139, 92, 246, 0.3);
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.05), rgba(124, 58, 237, 0.05));
        }
        
        .action-btn.event-action:hover {
            border-color: #8b5cf6;
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.15), rgba(124, 58, 237, 0.15));
        }
        
        .action-btn.event-action i {
            color: #8b5cf6;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-title i {
            color: #10b981;
        }
        
        .expense-item {
            padding: 15px;
            border-left: 4px solid var(--border-color);
            margin-bottom: 12px;
            background: var(--bg-tertiary);
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .expense-item:hover {
            transform: translateX(5px);
            border-left-color: #10b981;
        }
        
        .expense-item.expense {
            border-left-color: #ef4444;
        }
        
        .expense-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .expense-title {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .expense-amount {
            font-weight: 700;
            color: #ef4444;
        }
        
        .expense-details {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .category-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: var(--bg-tertiary);
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .category-item:hover {
            background: var(--bg-secondary);
            transform: translateX(3px);
        }
        
        .category-name {
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .category-name i {
            color: #10b981;
        }
        
        .category-value {
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .refresh-btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .refresh-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }
        
        .setup-notice {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(251, 191, 36, 0.1));
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .setup-notice i {
            font-size: 48px;
            color: #f59e0b;
        }
        
        .setup-notice-content h3 {
            color: var(--text-primary);
            margin-bottom: 10px;
        }
        
        .setup-notice-content p {
            color: var(--text-secondary);
            margin-bottom: 15px;
        }
        
        .setup-btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .setup-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(245, 158, 11, 0.3);
        }
        
        .year-summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .year-summary-table th,
        .year-summary-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .year-summary-table th {
            background: var(--bg-tertiary);
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .year-summary-table tr:hover td {
            background: var(--bg-tertiary);
        }
        
        .positive { color: #10b981; }
        .negative { color: #ef4444; }
        
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .navbar-content {
                flex-direction: column;
                height: auto;
                padding: 15px 0;
                gap: 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
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
                <a href="../dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Admin</span>
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
        <div class="page-header">
            <div>
                <h1><i class="fas fa-chart-pie"></i> Accounting Dashboard</h1>
                <p style="display: flex; align-items: center; gap: 15px; margin-top: 8px;">
                    <span style="font-weight: 600; font-size: 15px;"><?php echo date('l, F j, Y'); ?></span>
                    <span style="opacity: 0.7;">•</span>
                    <span id="dashboard-time" style="font-weight: 600; font-size: 15px;"></span>
                </p>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <select id="yearFilter" style="padding: 10px 15px; border-radius: 8px; border: 2px solid #e5e7eb; background: white; color: #1f2937; font-size: 14px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; outline: none; min-width: 200px;" onchange="window.location.href='dashboard.php?year=' + this.value" onfocus="this.style.borderColor='#10b981'; this.style.boxShadow='0 0 0 3px rgba(16, 185, 129, 0.1)'" onblur="this.style.borderColor='#e5e7eb'; this.style.boxShadow='none'">
                    <option value="all" <?php echo $selectedYear === 'all' ? 'selected' : ''; ?>>📅 All Years</option>
                    <?php foreach ($academicYears as $year): ?>
                        <option value="<?php echo $year['YearID']; ?>" <?php echo $selectedYear == $year['YearID'] ? 'selected' : ''; ?>>
                            📆 <?php echo htmlspecialchars($year['YearName']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="refresh-btn" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i>
                    <span>Refresh Data</span>
                </button>
            </div>
        </div>
        
        <?php if (!$expensesTableExists): ?>
        <!-- Setup Notice -->
        <div class="setup-notice">
            <i class="fas fa-exclamation-triangle"></i>
            <div class="setup-notice-content">
                <h3>Expenses Table Not Found</h3>
                <p>The expenses table needs to be created before you can start tracking expenses. Click the button below to set up the accounting system.</p>
                <a href="setup-expenses.php" class="setup-btn">
                    <i class="fas fa-database"></i>
                    <span>Setup Accounting System</span>
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card green">
                <div class="stat-header">
                    <span class="stat-title">Total Collection</span>
                    <div class="stat-icon"><i class="fas fa-hand-holding-usd"></i></div>
                </div>
                <div class="stat-value">₹<?php echo number_format($total_collection, 2); ?></div>
                <div class="stat-subtitle">All Time Collections</div>
            </div>
            
            <div class="stat-card blue">
                <div class="stat-header">
                    <span class="stat-title">Total Distribution</span>
                    <div class="stat-icon"><i class="fas fa-donate"></i></div>
                </div>
                <div class="stat-value">₹<?php echo number_format($total_distribution, 2); ?></div>
                <div class="stat-subtitle">All Time Distributions</div>
            </div>
            
            <div class="stat-card blue" style="opacity: 0.9;">
                <div class="stat-header">
                    <span class="stat-title">Total Fixed Amount</span>
                    <div class="stat-icon"><i class="fas fa-coins"></i></div>
                </div>
                <div class="stat-value">₹<?php echo number_format($total_fixed_amount, 2); ?></div>
                <div class="stat-subtitle">Academic Year Fixed Amount</div>
            </div>
            
            <div class="stat-card red">
                <div class="stat-header">
                    <span class="stat-title">Total Expenses</span>
                    <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
                </div>
                <div class="stat-value">₹<?php echo number_format($total_expenses, 2); ?></div>
                <div class="stat-subtitle"><?php echo number_format($expense_count); ?> Expense Records</div>
            </div>
            
            <div class="stat-card <?php echo $net_balance >= 0 ? 'teal' : 'orange'; ?>">
                <div class="stat-header">
                    <span class="stat-title">Net Balance</span>
                    <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                </div>
                <div class="stat-value">₹<?php echo number_format($net_balance, 2); ?></div>
                <div class="stat-subtitle">Interest + Fixed - Expenses</div>
            </div>
            
            <div class="stat-card purple">
                <div class="stat-header">
                    <span class="stat-title">Total Interest</span>
                    <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                </div>
                <div class="stat-value">₹<?php echo number_format($total_interest, 2); ?></div>
                <div class="stat-subtitle">All Time Interest</div>
            </div>
            
            <div class="stat-card pink">
                <div class="stat-header">
                    <span class="stat-title">This Month Expenses</span>
                    <div class="stat-icon"><i class="fas fa-calendar-minus"></i></div>
                </div>
                <div class="stat-value">₹<?php echo number_format($month_expenses, 2); ?></div>
                <div class="stat-subtitle"><?php echo date('F Y'); ?></div>
            </div>
            
            <div class="stat-card cyan">
                <div class="stat-header">
                    <span class="stat-title">This Month Collection</span>
                    <div class="stat-icon"><i class="fas fa-calendar-plus"></i></div>
                </div>
                <div class="stat-value">₹<?php echo number_format($month_collection, 2); ?></div>
                <div class="stat-subtitle"><?php echo date('F Y'); ?></div>
            </div>
            
            <div class="stat-card yellow">
                <div class="stat-header">
                    <span class="stat-title">Monthly Difference</span>
                    <div class="stat-icon"><i class="fas fa-balance-scale-right"></i></div>
                </div>
                <div class="stat-value <?php echo ($month_collection - $month_expenses) >= 0 ? 'positive' : 'negative'; ?>">
                    ₹<?php echo number_format($month_collection - $month_expenses, 2); ?>
                </div>
                <div class="stat-subtitle">Collection - Expenses</div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="manage-expenses.php" class="action-btn">
                <i class="fas fa-receipt"></i>
                <div class="action-btn-content">
                    <h3>Manage Expenses</h3>
                    <p>Add, edit, or delete expense records</p>
                </div>
            </a>
            
            <a href="add-expense.php" class="action-btn">
                <i class="fas fa-plus-circle"></i>
                <div class="action-btn-content">
                    <h3>Add New Expense</h3>
                    <p>Record a new expense transaction</p>
                </div>
            </a>
            
            <a href="manage-transactions.php" class="action-btn">
                <i class="fas fa-exchange-alt"></i>
                <div class="action-btn-content">
                    <h3>View Transactions</h3>
                    <p>View all member transactions</p>
                </div>
            </a>
            
            <a href="expense-reports.php" class="action-btn">
                <i class="fas fa-chart-bar"></i>
                <div class="action-btn-content">
                    <h3>Expense Reports</h3>
                    <p>Generate expense analysis reports</p>
                </div>
            </a>
            
            <a href="budget-tracking.php" class="action-btn">
                <i class="fas fa-piggy-bank"></i>
                <div class="action-btn-content">
                    <h3>Budget Tracking</h3>
                    <p>Track budgets and spending limits</p>
                </div>
            </a>
            
            <a href="export-expenses.php" class="action-btn">
                <i class="fas fa-file-export"></i>
                <div class="action-btn-content">
                    <h3>Export Expenses</h3>
                    <p>Download expense data as Excel/PDF</p>
                </div>
            </a>
            
            <a href="../net-balance-manager.php" class="action-btn">
                <i class="fas fa-balance-scale"></i>
                <div class="action-btn-content">
                    <h3>Net Balance Manager</h3>
                    <p>Manage member net balance calculations</p>
                </div>
            </a>
            
            <a href="../audit-logs.php" class="action-btn">
                <i class="fas fa-history"></i>
                <div class="action-btn-content">
                    <h3>Audit Logs</h3>
                    <p>View accounting activity logs</p>
                </div>
            </a>
            
            <a href="events/dashboard.php" target="_blank" class="action-btn event-action">
                <i class="fas fa-calendar-star"></i>
                <div class="action-btn-content">
                    <h3>Event Management</h3>
                    <p>Manage event finances separately</p>
                </div>
            </a>
        </div>
        
        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Recent Expenses -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-receipt"></i>
                        Recent Expenses
                    </h2>
                    <a href="manage-expenses.php" style="color: #10b981; text-decoration: none; font-size: 14px;">View All</a>
                </div>
                
                <?php if (empty($recent_expenses)): ?>
                    <p style="color: var(--text-secondary); text-align: center; padding: 40px;">
                        <i class="fas fa-inbox" style="font-size: 48px; display: block; margin-bottom: 15px; opacity: 0.5;"></i>
                        No expenses recorded yet
                    </p>
                <?php else: ?>
                    <?php foreach ($recent_expenses as $expense): ?>
                        <div class="expense-item expense">
                            <div class="expense-header">
                                <span class="expense-title"><?php echo htmlspecialchars($expense['Description']); ?></span>
                                <span class="expense-amount">-₹<?php echo number_format($expense['Amount'], 2); ?></span>
                            </div>
                            <div class="expense-details">
                                <i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($expense['ExpenseDate'])); ?>
                                | <i class="fas fa-tag"></i> <?php echo htmlspecialchars($expense['Category']); ?>
                                | <i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($expense['YearName'] ?? 'All Years'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- Expense by Category -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-tags"></i>
                        Expenses by Category
                    </h2>
                </div>
                
                <?php if (empty($expense_by_category)): ?>
                    <p style="color: var(--text-secondary); text-align: center; padding: 40px;">
                        <i class="fas fa-chart-pie" style="font-size: 48px; display: block; margin-bottom: 15px; opacity: 0.5;"></i>
                        No category data available
                    </p>
                <?php else: ?>
                    <?php foreach ($expense_by_category as $category): ?>
                        <div class="category-item">
                            <span class="category-name">
                                <i class="fas fa-folder"></i>
                                <?php echo htmlspecialchars($category['Category'] ?? 'Uncategorized'); ?>
                            </span>
                            <span class="category-value">₹<?php echo number_format($category['total_amount'], 2); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Year-wise Financial Summary -->
        <div class="card" style="margin-top: 30px;">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-calendar-alt"></i>
                    Year-wise Financial Summary
                </h2>
            </div>
            
            <?php if (empty($year_summaries)): ?>
                <p style="color: var(--text-secondary); text-align: center; padding: 40px;">
                    No financial data available
                </p>
            <?php else: ?>
                <table class="year-summary-table">
                    <thead>
                        <tr>
                            <th>Academic Year</th>
                            <th>Collection</th>
                            <th>Distribution</th>
                            <th>Interest</th>
                            <th>Expenses</th>
                            <th>Net Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($year_summaries as $summary): 
                            $yearNetBalance = ($summary['total_interest'] + $summary['FixedAmount']) - $summary['total_expenses'];
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($summary['YearName']); ?></strong></td>
                                <td class="positive">₹<?php echo number_format($summary['total_collection'], 2); ?></td>
                                <td>₹<?php echo number_format($summary['FixedAmount'], 2); ?></td>
                                <td>₹<?php echo number_format($summary['total_interest'], 2); ?></td>
                                <td class="negative">₹<?php echo number_format($summary['total_expenses'], 2); ?></td>
                                <td class="<?php echo $yearNetBalance >= 0 ? 'positive' : 'negative'; ?>">
                                    <strong>₹<?php echo number_format($yearNetBalance, 2); ?></strong>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Expense Trend Chart -->
        <div class="card" style="margin-top: 30px;">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-chart-line"></i>
                    Monthly Expense Trend
                </h2>
            </div>
            <canvas id="expenseTrendChart" height="100"></canvas>
        </div>
    </div>
    
    <script src="../theme-manager.js"></script>
    <script>
        // Update time display
        function updateTime() {
            const now = new Date();
            const options = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true };
            document.getElementById('dashboard-time').textContent = now.toLocaleTimeString('en-US', options);
        }
        updateTime();
        setInterval(updateTime, 1000);
        
        // Expense Trend Chart
        const expenseTrendData = <?php echo json_encode($expense_trend); ?>;
        
        if (expenseTrendData.length > 0) {
            const ctx = document.getElementById('expenseTrendChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: expenseTrendData.map(item => item.month),
                    datasets: [{
                        label: 'Monthly Expenses',
                        data: expenseTrendData.map(item => parseFloat(item.total_expense)),
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#ef4444',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5
                    }]
                },
                options: {
                    responsive: true,
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
    </script>
</body>
</html>
