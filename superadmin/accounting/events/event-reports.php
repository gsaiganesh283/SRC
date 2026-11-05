<?php
/**
 * Event Reports Page
 * Generate and view comprehensive event reports
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../session.php';
require_once '../../../config/config.php';
require_once '../../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Check if tables exist
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'events'");
if ($tableCheckStmt->rowCount() === 0) {
    header('Location: ../setup-events.php');
    exit;
}

// Get filter parameters
$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$eventType = isset($_GET['type']) ? $_GET['type'] : 'all';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Event types for filter
$eventTypes = ['General', 'Festival', 'Meeting', 'Function', 'Tour', 'Charity', 'Sports', 'Other'];

// Build filter conditions
$whereConditions = ["EventDate BETWEEN ? AND ?"];
$params = [$startDate, $endDate];

if ($selectedYear !== 'all') {
    $whereConditions[] = "e.YearID = ?";
    $params[] = $selectedYear;
}

if ($statusFilter !== 'all') {
    $whereConditions[] = "e.Status = ?";
    $params[] = $statusFilter;
}

if ($eventType !== 'all') {
    $whereConditions[] = "e.EventType = ?";
    $params[] = $eventType;
}

$whereClause = "WHERE " . implode(" AND ", $whereConditions);

// Get summary statistics
$summaryQuery = "SELECT 
    COUNT(*) as total_events,
    IFNULL(SUM(TotalCollection), 0) as total_collections,
    IFNULL(SUM(TotalExpense), 0) as total_expenses,
    IFNULL(SUM(EventBalance), 0) as total_balance,
    IFNULL(SUM(BudgetAmount), 0) as total_budget,
    COUNT(CASE WHEN e.Status = 'Planned' THEN 1 END) as planned_count,
    COUNT(CASE WHEN e.Status = 'Ongoing' THEN 1 END) as ongoing_count,
    COUNT(CASE WHEN e.Status = 'Completed' THEN 1 END) as completed_count,
    COUNT(CASE WHEN e.Status = 'Cancelled' THEN 1 END) as cancelled_count
FROM events e $whereClause";
$summaryStmt = $pdo->prepare($summaryQuery);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

// Events by type
$typeQuery = "SELECT 
    e.EventType,
    COUNT(*) as count,
    IFNULL(SUM(e.TotalCollection), 0) as collections,
    IFNULL(SUM(e.TotalExpense), 0) as expenses,
    IFNULL(SUM(e.EventBalance), 0) as balance
FROM events e $whereClause
GROUP BY e.EventType
ORDER BY count DESC";
$typeStmt = $pdo->prepare($typeQuery);
$typeStmt->execute($params);
$eventsByType = $typeStmt->fetchAll(PDO::FETCH_ASSOC);

// Monthly breakdown
$monthlyQuery = "SELECT 
    DATE_FORMAT(e.EventDate, '%Y-%m') as month_key,
    DATE_FORMAT(e.EventDate, '%b %Y') as month_label,
    COUNT(*) as event_count,
    IFNULL(SUM(e.TotalCollection), 0) as collections,
    IFNULL(SUM(e.TotalExpense), 0) as expenses,
    IFNULL(SUM(e.EventBalance), 0) as balance
FROM events e $whereClause
GROUP BY month_key
ORDER BY month_key";
$monthlyStmt = $pdo->prepare($monthlyQuery);
$monthlyStmt->execute($params);
$monthlyData = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

// Top events by collection
$topCollectionsQuery = "SELECT 
    e.EventID, e.EventName, e.EventDate, e.EventType, 
    e.TotalCollection, e.TotalExpense, e.EventBalance
FROM events e $whereClause
ORDER BY e.TotalCollection DESC
LIMIT 10";
$topCollectionsStmt = $pdo->prepare($topCollectionsQuery);
$topCollectionsStmt->execute($params);
$topCollections = $topCollectionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Top events by expense
$topExpensesQuery = "SELECT 
    e.EventID, e.EventName, e.EventDate, e.EventType, 
    e.TotalCollection, e.TotalExpense, e.EventBalance
FROM events e $whereClause
ORDER BY e.TotalExpense DESC
LIMIT 10";
$topExpensesStmt = $pdo->prepare($topExpensesQuery);
$topExpensesStmt->execute($params);
$topExpenses = $topExpensesStmt->fetchAll(PDO::FETCH_ASSOC);

// Detailed events list
$eventsQuery = "SELECT 
    e.*,
    ay.YearName
FROM events e
LEFT JOIN academic_years ay ON e.YearID = ay.YearID
$whereClause
ORDER BY EventDate DESC";
$eventsStmt = $pdo->prepare($eventsQuery);
$eventsStmt->execute($params);
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Reports - <?php echo htmlspecialchars($siteName); ?></title>
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
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }
        
        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.3);
        }
        
        .filters-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 13px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }
        
        .form-control:focus {
            outline: none;
            border-color: #8b5cf6;
        }
        
        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .summary-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            box-shadow: var(--shadow);
            border-left: 4px solid #8b5cf6;
        }
        
        .summary-card.collections {
            border-left-color: #10b981;
        }
        
        .summary-card.expenses {
            border-left-color: #ef4444;
        }
        
        .summary-card.balance {
            border-left-color: #3b82f6;
        }
        
        .summary-card.budget {
            border-left-color: #f59e0b;
        }
        
        .summary-card h3 {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .summary-card .value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .summary-card .subtext {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 5px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow);
        }
        
        .card h2 {
            font-size: 18px;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card h2 i {
            color: #8b5cf6;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
        }
        
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 13px;
        }
        
        .data-table tbody tr:hover {
            background: var(--bg-tertiary);
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-planned { background: #e0e7ff; color: #3730a3; }
        .status-ongoing { background: #fef3c7; color: #92400e; }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        
        .amount-positive { color: #10b981; font-weight: 600; }
        .amount-negative { color: #ef4444; font-weight: 600; }
        
        .type-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(139, 92, 246, 0.1);
            color: #7c3aed;
        }
        
        .status-counts {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .status-count {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .status-count .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .status-count .dot.planned { background: #6366f1; }
        .status-count .dot.ongoing { background: #f59e0b; }
        .status-count .dot.completed { background: #10b981; }
        .status-count .dot.cancelled { background: #ef4444; }
        
        .print-section {
            display: none;
        }
        
        @media print {
            body {
                background: white !important;
            }
            
            .navbar, .filters-card, .header-actions, .btn {
                display: none !important;
            }
            
            .print-section {
                display: block !important;
            }
            
            .card {
                break-inside: avoid;
                box-shadow: none !important;
                border: 1px solid #ddd;
            }
            
            .content-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        /* Dark Mode Enhancements */
        [data-theme="dark"] .data-table th {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }
        
        [data-theme="dark"] .status-planned { background: #312e81; color: #c7d2fe; }
        [data-theme="dark"] .status-ongoing { background: #78350f; color: #fde68a; }
        [data-theme="dark"] .status-completed { background: #064e3b; color: #a7f3d0; }
        [data-theme="dark"] .status-cancelled { background: #7f1d1d; color: #fecaca; }
        
        [data-theme="dark"] .type-badge {
            background: rgba(139, 92, 246, 0.2);
            color: #a78bfa;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-content">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-calendar-star"></i>
                Event Reports
            </a>
            <div class="navbar-user">
                <button class="theme-toggle" onclick="toggleTheme()">
                    <i class="fas fa-moon"></i>
                    <span>Theme</span>
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <?php include "../../includes/datetime-widget.php"; ?>
            </div>
        </div>
    </nav>
    
    <div class="container" style="max-width: 1600px; margin: 30px auto; padding: 0 20px;">
        <!-- Print Header -->
        <div class="print-section">
            <h1 style="text-align: center; margin-bottom: 10px;">Event Management Report</h1>
            <p style="text-align: center; color: #666; margin-bottom: 30px;">
                Period: <?php echo date('M d, Y', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?>
            </p>
        </div>
        
        <div class="page-header">
            <h1><i class="fas fa-chart-pie"></i> Event Reports</h1>
            <div class="header-actions">
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print Report
                </button>
                <a href="export-event-report.php?year=<?php echo urlencode($selectedYear); ?>&status=<?php echo urlencode($statusFilter); ?>&type=<?php echo urlencode($eventType); ?>&start_date=<?php echo urlencode($startDate); ?>&end_date=<?php echo urlencode($endDate); ?>" class="btn btn-secondary" title="Download as PDF">
                    <i class="fas fa-download"></i> Download PDF
                </a>
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters-card">
            <form method="GET">
                <div class="filters-grid">
                    <div class="form-group">
                        <label>Academic Year</label>
                        <select name="year" class="form-control">
                            <option value="all">All Years</option>
                            <?php foreach ($academicYears as $year): ?>
                            <option value="<?php echo $year['YearID']; ?>" <?php echo ($selectedYear == $year['YearID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['YearName']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Event Status</label>
                        <select name="status" class="form-control">
                            <option value="all">All Status</option>
                            <option value="Planned" <?php echo ($statusFilter == 'Planned') ? 'selected' : ''; ?>>Planned</option>
                            <option value="Ongoing" <?php echo ($statusFilter == 'Ongoing') ? 'selected' : ''; ?>>Ongoing</option>
                            <option value="Completed" <?php echo ($statusFilter == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="Cancelled" <?php echo ($statusFilter == 'Cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Event Type</label>
                        <select name="type" class="form-control">
                            <option value="all">All Types</option>
                            <?php foreach ($eventTypes as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo ($eventType == $type) ? 'selected' : ''; ?>>
                                <?php echo $type; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="event-reports.php" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Summary Cards -->
        <div class="summary-grid">
            <div class="summary-card">
                <h3><i class="fas fa-calendar-check"></i> Total Events</h3>
                <div class="value"><?php echo $summary['total_events']; ?></div>
                <div class="status-counts">
                    <span class="status-count"><span class="dot planned"></span> <?php echo $summary['planned_count']; ?> Planned</span>
                    <span class="status-count"><span class="dot ongoing"></span> <?php echo $summary['ongoing_count']; ?> Ongoing</span>
                    <span class="status-count"><span class="dot completed"></span> <?php echo $summary['completed_count']; ?> Done</span>
                </div>
            </div>
            <div class="summary-card collections">
                <h3><i class="fas fa-hand-holding-usd"></i> Total Collections</h3>
                <div class="value amount-positive">₹<?php echo number_format($summary['total_collections'], 2); ?></div>
                <div class="subtext">From all events</div>
            </div>
            <div class="summary-card expenses">
                <h3><i class="fas fa-receipt"></i> Total Expenses</h3>
                <div class="value amount-negative">₹<?php echo number_format($summary['total_expenses'], 2); ?></div>
                <div class="subtext">Event costs</div>
            </div>
            <div class="summary-card balance">
                <h3><i class="fas fa-balance-scale"></i> Net Balance</h3>
                <div class="value <?php echo $summary['total_balance'] >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                    ₹<?php echo number_format(abs($summary['total_balance']), 2); ?>
                </div>
                <div class="subtext"><?php echo $summary['total_balance'] >= 0 ? 'Surplus' : 'Deficit'; ?></div>
            </div>
            <div class="summary-card budget">
                <h3><i class="fas fa-piggy-bank"></i> Total Budget</h3>
                <div class="value">₹<?php echo number_format($summary['total_budget'], 2); ?></div>
                <div class="subtext">Combined budgets</div>
            </div>
        </div>
        
        <!-- Charts and Data -->
        <div class="content-grid">
            <!-- Monthly Trend Chart -->
            <div class="card">
                <h2><i class="fas fa-chart-line"></i> Monthly Trend</h2>
                <div class="chart-container">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
            
            <!-- Events by Type Chart -->
            <div class="card">
                <h2><i class="fas fa-chart-pie"></i> Events by Type</h2>
                <div class="chart-container">
                    <canvas id="typeChart"></canvas>
                </div>
            </div>
        </div>
        
        <div class="content-grid">
            <!-- Events by Type Table -->
            <div class="card">
                <h2><i class="fas fa-tags"></i> Breakdown by Type</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Event Type</th>
                            <th>Count</th>
                            <th>Collections</th>
                            <th>Expenses</th>
                            <th>Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventsByType as $type): ?>
                        <tr>
                            <td><span class="type-badge"><?php echo htmlspecialchars($type['EventType'] ?: 'General'); ?></span></td>
                            <td><?php echo $type['count']; ?></td>
                            <td class="amount-positive">₹<?php echo number_format($type['collections'], 2); ?></td>
                            <td class="amount-negative">₹<?php echo number_format($type['expenses'], 2); ?></td>
                            <td class="<?php echo $type['balance'] >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                ₹<?php echo number_format(abs($type['balance']), 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Top Events by Collection -->
            <div class="card">
                <h2><i class="fas fa-trophy"></i> Top Collections</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th>Date</th>
                            <th>Collection</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topCollections as $event): ?>
                        <tr>
                            <td>
                                <a href="view-event.php?id=<?php echo $event['EventID']; ?>" style="color: inherit; text-decoration: none;">
                                    <?php echo htmlspecialchars($event['EventName']); ?>
                                </a>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($event['EventDate'])); ?></td>
                            <td class="amount-positive">₹<?php echo number_format($event['TotalCollection'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- All Events List -->
        <div class="card" style="margin-top: 25px;">
            <h2><i class="fas fa-list"></i> All Events (<?php echo count($events); ?>)</h2>
            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Event Name</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Budget</th>
                            <th>Collections</th>
                            <th>Expenses</th>
                            <th>Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                        <tr>
                            <td>
                                <a href="view-event.php?id=<?php echo $event['EventID']; ?>" style="color: inherit; text-decoration: none; font-weight: 600;">
                                    <?php echo htmlspecialchars($event['EventName']); ?>
                                </a>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($event['EventDate'])); ?></td>
                            <td><span class="type-badge"><?php echo htmlspecialchars($event['EventType'] ?: 'General'); ?></span></td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($event['Status']); ?>">
                                    <?php echo $event['Status']; ?>
                                </span>
                            </td>
                            <td>₹<?php echo number_format($event['BudgetAmount'], 2); ?></td>
                            <td class="amount-positive">₹<?php echo number_format($event['TotalCollection'], 2); ?></td>
                            <td class="amount-negative">₹<?php echo number_format($event['TotalExpense'], 2); ?></td>
                            <td class="<?php echo $event['EventBalance'] >= 0 ? 'amount-positive' : 'amount-negative'; ?>">
                                ₹<?php echo number_format(abs($event['EventBalance']), 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($events)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 30px; color: var(--text-secondary);">
                                No events found for the selected filters.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script src="../../theme-manager.js"></script>
    <script>
        // Monthly Trend Chart
        const monthlyData = <?php echo json_encode($monthlyData); ?>;
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: monthlyData.map(d => d.month_label),
                datasets: [
                    {
                        label: 'Collections',
                        data: monthlyData.map(d => d.collections),
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Expenses',
                        data: monthlyData.map(d => d.expenses),
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        tension: 0.4,
                        fill: true
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
                            callback: value => '₹' + value.toLocaleString()
                        }
                    }
                }
            }
        });
        
        // Events by Type Chart
        const typeData = <?php echo json_encode($eventsByType); ?>;
        const typeCtx = document.getElementById('typeChart').getContext('2d');
        new Chart(typeCtx, {
            type: 'doughnut',
            data: {
                labels: typeData.map(d => d.EventType || 'General'),
                datasets: [{
                    data: typeData.map(d => d.count),
                    backgroundColor: [
                        '#8b5cf6', '#10b981', '#f59e0b', '#ef4444', 
                        '#3b82f6', '#ec4899', '#6366f1', '#14b8a6'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
    </script>
</body>
</html>
