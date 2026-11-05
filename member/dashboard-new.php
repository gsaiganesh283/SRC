<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../includes/net-balance-helper.php';

requireMemberLogin();

require_once '../includes/logo-helper.php';

$systemLogoHtml = renderSystemLogo('', '', 40);
$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Check permission to view dashboard
if (!hasMemberPermission($_SESSION['member_id'], 'view_dashboard')) {
    // If no explicit permission, allow by default (basic access)
    // This ensures backward compatibility
}

$memberId = $_SESSION['member_id'];

// Get selected academic year filter
$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';

// Fetch academic years for filter dropdown
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Update last login time
try {
    $stmt = $pdo->prepare("UPDATE members SET LastLogin = NOW() WHERE MemberID = ?");
    $stmt->execute([$memberId]);
} catch (Exception $e) {
    // Silent fail
}

// Fetch member details
try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE MemberID = ?");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("Error fetching member details");
}

// Get member statistics
try {
    // Total transactions
    if ($selectedYear === 'all') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE MemberID = ?");
        $stmt->execute([$memberId]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE MemberID = ? AND YearID = ?");
        $stmt->execute([$memberId, $selectedYear]);
    }
    $totalTransactions = $stmt->fetchColumn();
    
    // Total collected
    if ($selectedYear === 'all') {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CollectionAmount), 0) FROM transactions WHERE MemberID = ?");
        $stmt->execute([$memberId]);
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CollectionAmount), 0) FROM transactions WHERE MemberID = ? AND YearID = ?");
        $stmt->execute([$memberId, $selectedYear]);
    }
    $totalCollected = $stmt->fetchColumn();
    
    // Total distributed
    if ($selectedYear === 'all') {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(DistributedAmount), 0) FROM transactions WHERE MemberID = ?");
        $stmt->execute([$memberId]);
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(DistributedAmount), 0) FROM transactions WHERE MemberID = ? AND YearID = ?");
        $stmt->execute([$memberId, $selectedYear]);
    }
    $totalDistributed = $stmt->fetchColumn();
    
    // Total interest
    if ($selectedYear === 'all') {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(InterestAmount), 0) FROM transactions WHERE MemberID = ?");
        $stmt->execute([$memberId]);
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(InterestAmount), 0) FROM transactions WHERE MemberID = ? AND YearID = ?");
        $stmt->execute([$memberId, $selectedYear]);
    }
    $totalInterest = $stmt->fetchColumn();
    
    // Total fixed amount
    if ($selectedYear === 'all') {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(FixedAmount), 0) FROM transactions WHERE MemberID = ?");
        $stmt->execute([$memberId]);
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(FixedAmount), 0) FROM transactions WHERE MemberID = ? AND YearID = ?");
        $stmt->execute([$memberId, $selectedYear]);
    }
    $totalFixedAmount = $stmt->fetchColumn();
    
    // Net balance using unified formula: (Distribution + Interest) - Collection
    // Respects manual overrides from net balance management system
    $yearId = ($selectedYear !== 'all') ? intval($selectedYear) : null;
    $netBalance = getCurrentMemberNetBalance($memberId, $yearId);
    
    // This year's statistics
    if ($selectedYear === 'all') {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CollectionAmount), 0) as YearCollected,
                COALESCE(SUM(DistributedAmount), 0) as YearDistributed,
                COUNT(*) as YearTransactions
            FROM transactions t
            JOIN academic_years y ON t.YearID = y.YearID
            WHERE t.MemberID = ? AND y.Status = 'Active'
        ");
        $stmt->execute([$memberId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CollectionAmount), 0) as YearCollected,
                COALESCE(SUM(DistributedAmount), 0) as YearDistributed,
                COUNT(*) as YearTransactions
            FROM transactions t
            JOIN academic_years y ON t.YearID = y.YearID
            WHERE t.MemberID = ? AND t.YearID = ?
        ");
        $stmt->execute([$memberId, $selectedYear]);
    }
    $yearStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Recent transactions
    if ($selectedYear === 'all') {
        $stmt = $pdo->prepare("
            SELECT t.*, y.YearName 
            FROM transactions t
            JOIN academic_years y ON t.YearID = y.YearID
            WHERE t.MemberID = ?
            ORDER BY t.TransactionDate DESC, t.CreatedAt DESC
            LIMIT 10
        ");
        $stmt->execute([$memberId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT t.*, y.YearName 
            FROM transactions t
            JOIN academic_years y ON t.YearID = y.YearID
            WHERE t.MemberID = ? AND t.YearID = ?
            ORDER BY t.TransactionDate DESC, t.CreatedAt DESC
            LIMIT 10
        ");
        $stmt->execute([$memberId, $selectedYear]);
    }
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Monthly trend (last 6 months)
    if ($selectedYear === 'all') {
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(TransactionDate, '%b %Y') as Month,
                YEAR(TransactionDate) as Year,
                MONTH(TransactionDate) as MonthNum,
                COALESCE(SUM(CollectionAmount), 0) as Collection,
                COALESCE(SUM(DistributedAmount), 0) as Distribution
            FROM transactions
            WHERE MemberID = ? AND TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY YEAR(TransactionDate), MONTH(TransactionDate)
            ORDER BY YEAR(TransactionDate) ASC, MONTH(TransactionDate) ASC
        ");
        $stmt->execute([$memberId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(TransactionDate, '%b %Y') as Month,
                YEAR(TransactionDate) as Year,
                MONTH(TransactionDate) as MonthNum,
                COALESCE(SUM(CollectionAmount), 0) as Collection,
                COALESCE(SUM(DistributedAmount), 0) as Distribution
            FROM transactions
            WHERE MemberID = ? AND YearID = ? AND TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY YEAR(TransactionDate), MONTH(TransactionDate)
            ORDER BY YEAR(TransactionDate) ASC, MONTH(TransactionDate) ASC
        ");
        $stmt->execute([$memberId, $selectedYear]);
    }
    $monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Notifications (check if table exists)
    $notifications = [];
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'system_notifications'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT * FROM system_notifications
                WHERE (TargetType = 'All' OR (TargetType = 'Member' AND TargetID = ?))
                AND (ExpiresAt IS NULL OR ExpiresAt > NOW())
                ORDER BY Priority DESC, CreatedAt DESC
                LIMIT 5
            ");
            $stmt->execute([$memberId]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $notifications = [];
    }
    
    // Unread notifications count
    $unreadNotifications = 0;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'system_notifications'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM system_notifications
                WHERE (TargetType = 'All' OR (TargetType = 'Member' AND TargetID = ?))
                AND IsRead = 0
                AND (ExpiresAt IS NULL OR ExpiresAt > NOW())
            ");
            $stmt->execute([$memberId]);
            $unreadNotifications = $stmt->fetchColumn();
        }
    } catch (Exception $e) {
        $unreadNotifications = 0;
    }
    
    // Documents count
    $documentsCount = 0;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'member_documents'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM member_documents WHERE MemberID = ?");
            $stmt->execute([$memberId]);
            $documentsCount = $stmt->fetchColumn();
        }
    } catch (Exception $e) {
        $documentsCount = 0;
    }
    
} catch (Exception $e) {
    // Log error for debugging
    error_log("Member Dashboard Error: " . $e->getMessage());
    
    // Default values
    $totalTransactions = 0;
    $totalCollected = 0;
    $totalDistributed = 0;
    $totalInterest = 0;
    $netBalance = 0;
    $yearStats = ['YearCollected' => 0, 'YearDistributed' => 0, 'YearTransactions' => 0];
    $recentTransactions = [];
    $monthlyTrend = [];
    $notifications = [];
    $unreadNotifications = 0;
    $documentsCount = 0;
}

// Get available years
try {
    $stmt = $pdo->query("SELECT * FROM academic_years ORDER BY StartDate DESC");
    $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $years = [];
}

$pageTitle = "Member Dashboard";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --secondary: #06b6d4;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #1f2937;
            --light: #f9fafb;
            --border: #e5e7eb;
            --text: #374151;
            --text-light: #6b7280;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: var(--text);
        }
        
        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: white;
            box-shadow: var(--shadow-lg);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
            transition: transform 0.3s ease;
        }
        
        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }
        
        .sidebar-header h2 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }
        
        .sidebar-header p {
            font-size: 0.875rem;
            opacity: 0.9;
        }
        
        .sidebar-menu {
            padding: 1rem 0;
        }
        
        .menu-item {
            padding: 0.875rem 1.5rem;
            display: flex;
            align-items: center;
            color: var(--text);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        
        .menu-item:hover {
            background: var(--light);
            border-left-color: var(--primary);
            color: var(--primary);
        }
        
        .menu-item.active {
            background: #eef2ff;
            border-left-color: var(--primary);
            color: var(--primary);
            font-weight: 600;
        }
        
        .menu-item i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.125rem;
        }
        
        .menu-item .badge {
            margin-left: auto;
            background: var(--danger);
            color: white;
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
        }
        
        .top-bar {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .welcome-text h1 {
            font-size: 1.75rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .welcome-text p {
            color: var(--text-light);
            font-size: 0.875rem;
        }
        
        .top-bar-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
            font-size: 0.875rem;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background: var(--primary);
            color: white;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
            transition: width 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .stat-card:hover::before {
            width: 100%;
            opacity: 0.1;
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .stat-icon.blue {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .stat-icon.green {
            background: #d1fae5;
            color: #047857;
        }
        
        .stat-icon.orange {
            background: #fed7aa;
            color: #c2410c;
        }
        
        .stat-icon.purple {
            background: #e9d5ff;
            color: #7c3aed;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            color: var(--text-light);
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .stat-trend {
            margin-top: 0.75rem;
            font-size: 0.8125rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .stat-trend.up {
            color: var(--success);
        }
        
        .stat-trend.down {
            color: var(--danger);
        }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            font-size: 1.125rem;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Transactions Table */
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .transactions-table thead {
            background: var(--light);
        }
        
        .transactions-table th {
            padding: 0.875rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.8125rem;
            color: var(--text);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .transactions-table td {
            padding: 1rem 0.875rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
        }
        
        .transactions-table tbody tr:hover {
            background: var(--light);
        }
        
        .amount-positive {
            color: var(--success);
            font-weight: 600;
        }
        
        .amount-negative {
            color: var(--danger);
            font-weight: 600;
        }
        
        /* Notifications */
        .notification-item {
            padding: 1rem;
            border-left: 3px solid var(--primary);
            background: var(--light);
            border-radius: 8px;
            margin-bottom: 0.75rem;
            transition: all 0.2s;
        }
        
        .notification-item:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow);
        }
        
        .notification-item.unread {
            background: #eef2ff;
            border-left-color: var(--primary);
        }
        
        .notification-item.warning {
            border-left-color: var(--warning);
        }
        
        .notification-item.success {
            border-left-color: var(--success);
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 0.5rem;
        }
        
        .notification-title {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.875rem;
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: var(--text-light);
        }
        
        .notification-message {
            font-size: 0.8125rem;
            color: var(--text);
            line-height: 1.5;
        }
        
        /* Chart Container */
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0.75rem;
        }
        
        .action-btn {
            padding: 1rem;
            background: var(--light);
            border: 2px solid var(--border);
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: var(--text);
            transition: all 0.2s;
        }
        
        .action-btn:hover {
            background: white;
            border-color: var(--primary);
            transform: translateX(5px);
            box-shadow: var(--shadow);
        }
        
        .action-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        
        .action-content h4 {
            font-size: 0.875rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .action-content p {
            font-size: 0.75rem;
            color: var(--text-light);
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .top-bar {
                flex-direction: column;
                gap: 1rem;
            }
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stat-card, .card {
            animation: fadeInUp 0.5s ease-out;
        }
        
        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <?php if (!empty($systemLogoHtml)): ?>
                    <div style="margin-bottom: 10px;"><?php echo $systemLogoHtml; ?></div>
                <?php endif; ?>
                <h2><?php echo htmlspecialchars($member['MemberName']); ?> <span style="color: #6b7280; font-size: 16px; font-weight: 500;">(ID: <?php echo $member['MemberID']; ?>)</span></h2>
                <p><i class="fas fa-id-card"></i> Member ID: <?php echo $memberId; ?></p>
            </div>
            <nav class="sidebar-menu">
                <a href="dashboard-new.php" class="menu-item active">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                <a href="my-payments.php" class="menu-item">
                    <i class="fas fa-receipt"></i>
                    <span>Payment History</span>
                </a>
                <a href="documents.php" class="menu-item">
                    <i class="fas fa-file-alt"></i>
                    <span>Documents</span>
                    <span class="badge"><?php echo $documentsCount; ?></span>
                </a>
                <a href="export-statement.php" class="menu-item">
                    <i class="fas fa-file-download"></i>
                    <span>Export Statement</span>
                </a>
                <a href="reports.php" class="menu-item">
                    <i class="fas fa-chart-bar"></i>
                    <span>My Reports</span>
                </a>
                <a href="notifications.php" class="menu-item">
                    <i class="fas fa-bell"></i>
                    <span>Notifications</span>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="badge"><?php echo $unreadNotifications; ?></span>
                    <?php endif; ?>
                </a>
                <a href="settings.php" class="menu-item">
                    <i class="fas fa-user-cog"></i>
                    <span>Settings</span>
                </a>
                <a href="../auth/logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Bar -->
            <div class="top-bar">
                <div class="welcome-text">
                    <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $member['MemberName'])[0]); ?>! 👋</h1>
                    <p><?php echo date('l, F j, Y'); ?> • <?php echo date('g:i A'); ?></p>
                </div>
                <div class="top-bar-actions">
                    <select id="yearFilter" class="form-control" style="width: 200px; margin-right: 10px;" onchange="window.location.href='dashboard-new.php?year=' + this.value">
                        <option value="all" <?php echo $selectedYear === 'all' ? 'selected' : ''; ?>>All Years</option>
                        <?php foreach ($academicYears as $year): ?>
                            <option value="<?php echo $year['YearID']; ?>" <?php echo $selectedYear == $year['YearID'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['YearName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a href="export-statement.php" class="btn btn-outline">
                        <i class="fas fa-download"></i>
                        Export Statement
                    </a>
                    <a href="view-profile.php" class="btn btn-primary">
                        <i class="fas fa-eye"></i>
                        View Full Details
                    </a>
                </div>
            </div>
            
            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value">₹<?php echo number_format($totalDistributed, 2); ?></div>
                            <div class="stat-label">Total Collected</div>
                        </div>
                        <div class="stat-icon blue">
                            <i class="fas fa-arrow-down"></i>
                        </div>
                    </div>
                    <div class="stat-trend up">
                        <i class="fas fa-arrow-up"></i>
                        <span>This year: ₹<?php echo number_format($yearStats['YearCollected'], 2); ?></span>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value">₹<?php echo number_format($totalCollected, 2); ?></div>
                            <div class="stat-label">Total Paid</div>
                        </div>
                        <div class="stat-icon green">
                            <i class="fas fa-arrow-up"></i>
                        </div>
                    </div>
                    <div class="stat-trend up">
                        <i class="fas fa-arrow-up"></i>
                        <span>This year: ₹<?php echo number_format($yearStats['YearDistributed'], 2); ?></span>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value">₹<?php echo number_format($netBalance, 2); ?></div>
                            <div class="stat-label">Net Balance</div>
                        </div>
                        <div class="stat-icon purple">
                            <i class="fas fa-wallet"></i>
                        </div>
                    </div>
                    <div class="stat-trend <?php echo $netBalance >= 0 ? 'up' : 'down'; ?>">
                        <i class="fas fa-<?php echo $netBalance >= 0 ? 'check' : 'exclamation'; ?>-circle"></i>
                        <span><?php echo $netBalance >= 0 ? 'Positive balance' : 'Negative balance'; ?></span>
                    </div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div>
                            <div class="stat-value"><?php echo $totalTransactions; ?></div>
                            <div class="stat-label">Total Transactions</div>
                        </div>
                        <div class="stat-icon orange">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                    </div>
                    <div class="stat-trend up">
                        <i class="fas fa-calendar"></i>
                        <span>This year: <?php echo $yearStats['YearTransactions']; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Recent Transactions -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-history"></i>
                            Recent Transactions
                        </h3>
                        <a href="my-payments.php" class="btn btn-outline" style="padding: 0.5rem 1rem; font-size: 0.8125rem;">
                            View All
                        </a>
                    </div>
                    <div class="card-body" style="padding: 0;">
                        <?php if (empty($recentTransactions)): ?>
                            <p style="padding: 2rem; text-align: center; color: var(--text-light);">
                                No transactions found
                            </p>
                        <?php else: ?>
                            <table class="transactions-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Year</th>
                                        <th>Collected</th>
                                        <th>Paid</th>
                                        <th>Interest</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentTransactions as $txn): ?>
                                        <tr>
                                            <td>
                                                <?php 
                                                if (!empty($txn['TransactionDate']) && $txn['TransactionDate'] != '0000-00-00') {
                                                    echo date('d M Y', strtotime($txn['TransactionDate']));
                                                } else {
                                                    echo date('d M Y', strtotime($txn['CreatedAt']));
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($txn['YearName']); ?></td>
                                            <td class="amount-positive">
                                                <?php echo $txn['CollectionAmount'] > 0 ? '₹' . number_format($txn['CollectionAmount'], 2) : '-'; ?>
                                            </td>
                                            <td class="amount-negative">
                                                <?php echo $txn['DistributedAmount'] > 0 ? '₹' . number_format($txn['DistributedAmount'], 2) : '-'; ?>
                                            </td>
                                            <td>
                                                <?php echo $txn['InterestAmount'] > 0 ? '₹' . number_format($txn['InterestAmount'], 2) : '-'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Right Sidebar -->
                <div>
                    <!-- Quick Actions -->
                    <div class="card" style="margin-bottom: 1.5rem;">
                        <div class="card-header">
                            <h3>
                                <i class="fas fa-bolt"></i>
                                Quick Actions
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="quick-actions">
                                <a href="view-profile.php" class="action-btn">
                                    <div class="action-icon blue">
                                        <i class="fas fa-user-circle"></i>
                                    </div>
                                    <div class="action-content">
                                        <h4>View Profile</h4>
                                        <p>See your complete profile</p>
                                    </div>
                                </a>
                                <a href="my-payments.php" class="action-btn">
                                    <div class="action-icon green">
                                        <i class="fas fa-receipt"></i>
                                    </div>
                                    <div class="action-content">
                                        <h4>Payment History</h4>
                                        <p>View all transactions</p>
                                    </div>
                                </a>
                                <a href="export-statement.php" class="action-btn">
                                    <div class="action-icon orange">
                                        <i class="fas fa-file-download"></i>
                                    </div>
                                    <div class="action-content">
                                        <h4>Download Statement</h4>
                                        <p>Export your records</p>
                                    </div>
                                </a>
                                <a href="settings.php" class="action-btn">
                                    <div class="action-icon purple">
                                        <i class="fas fa-cog"></i>
                                    </div>
                                    <div class="action-content">
                                        <h4>Account Settings</h4>
                                        <p>Update your preferences</p>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notifications -->
                    <div class="card">
                        <div class="card-header">
                            <h3>
                                <i class="fas fa-bell"></i>
                                Notifications
                            </h3>
                            <a href="notifications.php" style="font-size: 0.8125rem; color: var(--primary); text-decoration: none;">
                                View All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($notifications)): ?>
                                <p style="text-align: center; color: var(--text-light); padding: 1rem;">
                                    No new notifications
                                </p>
                            <?php else: ?>
                                <?php foreach ($notifications as $notif): ?>
                                    <div class="notification-item <?php echo $notif['IsRead'] == 0 ? 'unread' : ''; ?> <?php echo strtolower($notif['Type']); ?>">
                                        <div class="notification-header">
                                            <div class="notification-title">
                                                <i class="fas <?php echo htmlspecialchars($notif['Icon']); ?>"></i>
                                                <?php echo htmlspecialchars($notif['Title']); ?>
                                            </div>
                                            <div class="notification-time">
                                                <?php echo date('M j, Y g:i A', strtotime($notif['CreatedAt'])); ?>
                                            </div>
                                        </div>
                                        <div class="notification-message">
                                            <?php echo htmlspecialchars($notif['Message']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Monthly Trend Chart -->
            <?php if (!empty($monthlyTrend)): ?>
            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-chart-line"></i>
                        Transaction Trend (Last 6 Months)
                    </h3>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-header">
                    <h3>
                        <i class="fas fa-chart-line"></i>
                        Transaction Trend (Last 6 Months)
                    </h3>
                </div>
                <div class="card-body">
                    <div class="empty-state">
                        <i class="fas fa-chart-line" style="font-size: 48px; color: #94a3b8; margin-bottom: 1rem;"></i>
                        <p>No transaction data available for the last 6 months</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
        // Monthly Trend Chart
        <?php if (!empty($monthlyTrend)): ?>
        console.log('Member Monthly Trend Data:', <?php echo json_encode($monthlyTrend); ?>);
        const trendCtx = document.getElementById('trendChart');
        if (trendCtx) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($monthlyTrend, 'Month')); ?>,
                    datasets: [
                        {
                            label: 'Collected',
                            data: <?php echo json_encode(array_column($monthlyTrend, 'Collection')); ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Paid',
                            data: <?php echo json_encode(array_column($monthlyTrend, 'Distribution')); ?>,
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
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 15
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ₹' + context.parsed.y.toFixed(2);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₹' + value.toFixed(0);
                                }
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
        
        // Mobile menu toggle
        const menuToggle = document.createElement('button');
        menuToggle.innerHTML = '<i class="fas fa-bars"></i>';
        menuToggle.style.cssText = 'position: fixed; top: 1rem; left: 1rem; z-index: 101; background: white; border: none; padding: 0.75rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: none; cursor: pointer;';
        document.body.appendChild(menuToggle);
        
        if (window.innerWidth <= 768) {
            menuToggle.style.display = 'block';
        }
        
        menuToggle.addEventListener('click', () => {
            document.querySelector('.sidebar').classList.toggle('active');
        });
        
        window.addEventListener('resize', () => {
            if (window.innerWidth <= 768) {
                menuToggle.style.display = 'block';
            } else {
                menuToggle.style.display = 'none';
                document.querySelector('.sidebar').classList.remove('active');
            }
        });
    </script>
    
    <?php include '../includes/modal-component.php'; ?>
</body>
</html>
