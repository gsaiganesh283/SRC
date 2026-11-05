<?php
/**
 * Event Management Dashboard
 * Central hub for event finance tracking - separate from main accounts
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../session.php';
require_once '../../../config/config.php';
require_once '../../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Check if events table exists
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'events'");
if ($tableCheckStmt->rowCount() === 0) {
    header('Location: ../setup-events.php');
    exit;
}

// Get filter parameters
$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build filter conditions
$whereConditions = [];
$params = [];

if ($selectedYear !== 'all') {
    $whereConditions[] = "YearID = ?";
    $params[] = $selectedYear;
}

if ($statusFilter !== 'all') {
    $whereConditions[] = "Status = ?";
    $params[] = $statusFilter;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Get summary stats
$summaryQuery = "SELECT 
    COUNT(*) as total_events,
    IFNULL(SUM(TotalCollection), 0) as total_collections,
    IFNULL(SUM(TotalExpense), 0) as total_expenses,
    IFNULL(SUM(EventBalance), 0) as total_balance,
    COUNT(CASE WHEN Status = 'Planned' THEN 1 END) as planned_count,
    COUNT(CASE WHEN Status = 'Ongoing' THEN 1 END) as ongoing_count,
    COUNT(CASE WHEN Status = 'Completed' THEN 1 END) as completed_count
FROM events $whereClause";
$summaryStmt = $pdo->prepare($summaryQuery);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// Recent events
$recentQuery = "SELECT 
    e.*,
    ay.YearName
FROM events e
LEFT JOIN academic_years ay ON e.YearID = ay.YearID
$whereClause
ORDER BY e.EventDate DESC
LIMIT 5";
$recentStmt = $pdo->prepare($recentQuery);
$recentStmt->execute($params);
$recentEvents = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

// Recent transactions
$recentTxnQuery = "SELECT 
    et.*,
    e.EventName
FROM event_transactions et
JOIN events e ON et.EventID = e.EventID
ORDER BY et.CreatedAt DESC
LIMIT 10";
$recentTxnStmt = $pdo->query($recentTxnQuery);
$recentTransactions = $recentTxnStmt->fetchAll(PDO::FETCH_ASSOC);

// Monthly event data for chart
$monthlyQuery = "SELECT 
    DATE_FORMAT(EventDate, '%Y-%m') as month_key,
    DATE_FORMAT(EventDate, '%b %Y') as month_label,
    SUM(TotalCollection) as collections,
    SUM(TotalExpense) as expenses,
    COUNT(*) as event_count
FROM events
WHERE EventDate >= DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH)
GROUP BY month_key
ORDER BY month_key";
$monthlyStmt = $pdo->query($monthlyQuery);
$monthlyData = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Management - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../shared-styles.css">
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
            color: #8b5cf6;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .btn-primary {
            padding: 12px 24px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-secondary {
            padding: 12px 24px;
            background: var(--bg-tertiary);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .filter-select {
            padding: 12px 20px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
        }
        
        .filter-select:focus {
            border-color: #8b5cf6;
            outline: none;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 24px;
            margin-bottom: 35px;
            justify-content: center;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .stat-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
        }
        
        .stat-card.events::before { background: #8b5cf6; }
        .stat-card.collections::before { background: #10b981; }
        .stat-card.expenses::before { background: #ef4444; }
        .stat-card.balance::before { background: #3b82f6; }
        
        .stat-card .icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 22px;
        }
        
        .stat-card.events .icon { background: rgba(139, 92, 246, 0.15); color: #8b5cf6; }
        .stat-card.collections .icon { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .stat-card.expenses .icon { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .stat-card.balance .icon { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        
        .stat-card h3 {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        
        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .stat-card .sub-stats {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 15px;
            font-size: 12px;
        }
        
        .stat-card .sub-stat {
            padding: 5px 12px;
            background: var(--bg-tertiary);
            border-radius: 20px;
            color: var(--text-secondary);
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
            box-shadow: var(--shadow);
        }
        
        .card-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h2 {
            font-size: 16px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-header h2 i {
            color: #8b5cf6;
        }
        
        .card-body {
            padding: 20px 25px;
        }
        
        .event-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .event-item {
            padding: 15px 0;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .event-item:last-child {
            border-bottom: none;
        }
        
        .event-info {
            flex: 1;
        }
        
        .event-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        
        .event-meta {
            font-size: 13px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .event-stats {
            text-align: right;
        }
        
        .event-balance {
            font-size: 18px;
            font-weight: 700;
        }
        
        .event-balance.positive { color: #10b981; }
        .event-balance.negative { color: #ef4444; }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-badge.planned {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }
        
        .status-badge.ongoing {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }
        
        .status-badge.completed {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        
        .status-badge.cancelled {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .txn-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .txn-item {
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .txn-item:last-child {
            border-bottom: none;
        }
        
        .txn-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }
        
        .txn-type {
            font-weight: 600;
            font-size: 13px;
        }
        
        .txn-type.collection { color: #10b981; }
        .txn-type.expense { color: #ef4444; }
        
        .txn-amount {
            font-weight: 700;
        }
        
        .txn-amount.positive { color: #10b981; }
        .txn-amount.negative { color: #ef4444; }
        
        .txn-desc {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .txn-event {
            font-size: 12px;
            color: #8b5cf6;
            margin-top: 4px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            justify-content: center;
        }
        
        .quick-action {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            padding: 20px 15px;
            background: var(--bg-tertiary);
            border-radius: 12px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all 0.3s ease;
        }
        
        .quick-action:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.2);
        }
        
        .quick-action i {
            font-size: 24px;
            color: #8b5cf6;
        }
        
        .quick-action span {
            font-size: 13px;
            font-weight: 600;
            text-align: center;
        }
        
        .chart-container {
            height: 250px;
            position: relative;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            margin-bottom: 20px;
            transition: color 0.3s ease;
        }
        
        .back-link:hover {
            color: #8b5cf6;
        }
        
        .view-all {
            font-size: 13px;
            color: #8b5cf6;
            text-decoration: none;
        }
        
        .view-all:hover {
            text-decoration: underline;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-secondary);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .header-actions {
                width: 100%;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-calendar-star"></i>
                <span>Event Management</span>
            </div>
            <div class="navbar-user">
                <button id="themeToggle" class="theme-toggle">
                    <i id="themeIcon" class="fas fa-moon"></i>
                    <span id="themeText">Dark</span>
                </button>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_name); ?></div>
                    <div class="user-role">Event Manager</div>
                </div>
                <a href="../dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                    <span>Accounting</span>
                </a>
                <a href="../../logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
                <?php include "../../includes/datetime-widget.php"; ?>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <a href="../dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Accounting Dashboard
        </a>
        
        <div class="page-header">
            <div>
                <h1><i class="fas fa-calendar-star"></i> Event Management</h1>
                <p style="color: var(--text-secondary); margin-top: 8px;">Track event finances separately from main accounts</p>
            </div>
            <div class="header-actions">
                <form method="GET" style="display: flex; gap: 10px;">
                    <select name="year" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $selectedYear === 'all' ? 'selected' : ''; ?>>All Years</option>
                        <?php foreach ($academicYears as $year): ?>
                            <option value="<?php echo $year['YearID']; ?>" <?php echo $selectedYear == $year['YearID'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['YearName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="Planned" <?php echo $statusFilter === 'Planned' ? 'selected' : ''; ?>>Planned</option>
                        <option value="Ongoing" <?php echo $statusFilter === 'Ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                        <option value="Completed" <?php echo $statusFilter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </form>
                <a href="add-event.php" class="btn-primary">
                    <i class="fas fa-plus"></i>
                    New Event
                </a>
            </div>
        </div>
        
        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card events">
                <div class="icon"><i class="fas fa-calendar-alt"></i></div>
                <h3>Total Events</h3>
                <div class="value"><?php echo number_format($summary['total_events']); ?></div>
                <div class="sub-stats">
                    <span class="sub-stat"><?php echo $summary['planned_count']; ?> Planned</span>
                    <span class="sub-stat"><?php echo $summary['ongoing_count']; ?> Ongoing</span>
                    <span class="sub-stat"><?php echo $summary['completed_count']; ?> Done</span>
                </div>
            </div>
            <div class="stat-card collections">
                <div class="icon"><i class="fas fa-hand-holding-usd"></i></div>
                <h3>Total Collections</h3>
                <div class="value">₹<?php echo number_format($summary['total_collections'], 0); ?></div>
            </div>
            <div class="stat-card expenses">
                <div class="icon"><i class="fas fa-receipt"></i></div>
                <h3>Total Expenses</h3>
                <div class="value">₹<?php echo number_format($summary['total_expenses'], 0); ?></div>
            </div>
            <div class="stat-card balance">
                <div class="icon"><i class="fas fa-wallet"></i></div>
                <h3>Net Balance</h3>
                <div class="value">₹<?php echo number_format($summary['total_balance'], 0); ?></div>
            </div>
        </div>
        
        <div class="content-grid">
            <div>
                <!-- Event Chart -->
                <div class="card" style="margin-bottom: 25px;">
                    <div class="card-header">
                        <h2><i class="fas fa-chart-bar"></i> Event Finance Trend</h2>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="eventChart"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Events -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-calendar-alt"></i> Recent Events</h2>
                        <a href="manage-events.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recentEvents)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <p>No events found</p>
                            </div>
                        <?php else: ?>
                            <ul class="event-list">
                                <?php foreach ($recentEvents as $event): ?>
                                    <li class="event-item">
                                        <div class="event-info">
                                            <div class="event-name">
                                                <a href="view-event.php?id=<?php echo $event['EventID']; ?>" style="color: inherit; text-decoration: none;">
                                                    <?php echo htmlspecialchars($event['EventName']); ?>
                                                </a>
                                            </div>
                                            <div class="event-meta">
                                                <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($event['EventDate'])); ?></span>
                                                <span class="status-badge <?php echo strtolower($event['Status']); ?>">
                                                    <?php echo $event['Status']; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="event-stats">
                                            <div class="event-balance <?php echo $event['EventBalance'] >= 0 ? 'positive' : 'negative'; ?>">
                                                ₹<?php echo number_format($event['EventBalance'], 0); ?>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div>
                <!-- Quick Actions -->
                <div class="card" style="margin-bottom: 25px;">
                    <div class="card-header">
                        <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions">
                            <a href="add-event.php" class="quick-action">
                                <i class="fas fa-plus-circle"></i>
                                <span>Create Event</span>
                            </a>
                            <a href="manage-events.php" class="quick-action">
                                <i class="fas fa-list"></i>
                                <span>All Events</span>
                            </a>
                            <a href="event-transactions.php" class="quick-action">
                                <i class="fas fa-exchange-alt"></i>
                                <span>Transactions</span>
                            </a>
                            <a href="event-reports.php" class="quick-action">
                                <i class="fas fa-chart-pie"></i>
                                <span>Reports</span>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Transactions -->
                <div class="card">
                    <div class="card-header">
                        <h2><i class="fas fa-exchange-alt"></i> Recent Transactions</h2>
                        <a href="event-transactions.php" class="view-all">View All</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recentTransactions)): ?>
                            <div class="empty-state">
                                <i class="fas fa-receipt"></i>
                                <p>No transactions yet</p>
                            </div>
                        <?php else: ?>
                            <ul class="txn-list">
                                <?php foreach ($recentTransactions as $txn): ?>
                                    <li class="txn-item">
                                        <div class="txn-header">
                                            <span class="txn-type <?php echo strtolower($txn['TransactionType']); ?>">
                                                <i class="fas fa-<?php echo $txn['TransactionType'] === 'Collection' ? 'arrow-down' : 'arrow-up'; ?>"></i>
                                                <?php echo $txn['TransactionType']; ?>
                                            </span>
                                            <span class="txn-amount <?php echo $txn['TransactionType'] === 'Collection' ? 'positive' : 'negative'; ?>">
                                                <?php echo $txn['TransactionType'] === 'Collection' ? '+' : '-'; ?>₹<?php echo number_format($txn['Amount'], 0); ?>
                                            </span>
                                        </div>
                                        <div class="txn-desc"><?php echo htmlspecialchars($txn['Description']); ?></div>
                                        <div class="txn-event"><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($txn['EventName']); ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../../theme-manager.js"></script>
    <script>
        // Event finance trend chart
        const monthlyData = <?php echo json_encode($monthlyData); ?>;
        
        if (monthlyData.length > 0) {
            const ctx = document.getElementById('eventChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: monthlyData.map(item => item.month_label),
                    datasets: [
                        {
                            label: 'Collections',
                            data: monthlyData.map(item => parseFloat(item.collections)),
                            backgroundColor: 'rgba(16, 185, 129, 0.7)',
                            borderColor: '#10b981',
                            borderWidth: 1,
                            borderRadius: 4
                        },
                        {
                            label: 'Expenses',
                            data: monthlyData.map(item => parseFloat(item.expenses)),
                            backgroundColor: 'rgba(239, 68, 68, 0.7)',
                            borderColor: '#ef4444',
                            borderWidth: 1,
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
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
