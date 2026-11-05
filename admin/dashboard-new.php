<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../includes/net-balance-helper.php';

requireAdmin();

require_once '../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Get admin details
$adminId = $_SESSION['admin_id'];
$stmt = $pdo->prepare("SELECT * FROM admins WHERE AdminID = ?");
$stmt->execute([$adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Get selected academic year filter
$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';

// Fetch academic years for filter dropdown
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get comprehensive statistics
try {
    // Total admins
    $stmt = $pdo->query("SELECT COUNT(*) FROM admins");
    $totalAdmins = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM admins WHERE Status = 'Active'");
    $activeAdmins = $stmt->fetchColumn();
    
    // Total members
    $stmt = $pdo->query("SELECT COUNT(*) FROM members");
    $totalMembers = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM members WHERE Status = 'Active'");
    $activeMembers = $stmt->fetchColumn();
    
    // Total transactions
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM transactions");
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $totalTransactions = $stmt->fetchColumn();
    
    // Financial stats
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT COALESCE(SUM(CollectionAmount), 0) FROM transactions");
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CollectionAmount), 0) FROM transactions WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $totalCollection = $stmt->fetchColumn();
    
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT COALESCE(SUM(DistributedAmount), 0) FROM transactions");
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(DistributedAmount), 0) FROM transactions WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $totalDistribution = $stmt->fetchColumn();
    
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT COALESCE(SUM(InterestAmount), 0) FROM transactions");
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(InterestAmount), 0) FROM transactions WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $totalInterest = $stmt->fetchColumn();
    
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT COALESCE(SUM(FixedAmount), 0) FROM academic_years");
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(FixedAmount), 0) FROM academic_years WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $totalFixedAmount = $stmt->fetchColumn();
    
    // Get system net balance using unified system that respects manual overrides
    $systemSummary = getSystemNetBalanceSummary(($selectedYear !== 'all') ? intval($selectedYear) : null);
    $netBalance = $systemSummary['net_balance'] ?? 0;
    
    // Today's stats
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM transactions WHERE DATE(TransactionDate) = CURDATE()");
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE DATE(TransactionDate) = CURDATE() AND YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $todayTransactions = $stmt->fetchColumn();
    
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT COALESCE(SUM(CollectionAmount), 0) FROM transactions WHERE DATE(TransactionDate) = CURDATE()");
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CollectionAmount), 0) FROM transactions WHERE DATE(TransactionDate) = CURDATE() AND YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $todayCollection = $stmt->fetchColumn();
    
    // This month's stats
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(CollectionAmount), 0) 
            FROM transactions 
            WHERE MONTH(TransactionDate) = MONTH(CURDATE()) AND YEAR(TransactionDate) = YEAR(CURDATE())
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CollectionAmount), 0) 
            FROM transactions 
            WHERE MONTH(TransactionDate) = MONTH(CURDATE()) AND YEAR(TransactionDate) = YEAR(CURDATE()) AND YearID = ?
        ");
        $stmt->execute([$selectedYear]);
    }
    $monthCollection = $stmt->fetchColumn();
    
    // Recent transactions
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("
            SELECT t.*, m.MemberName, y.YearName
            FROM transactions t
            JOIN members m ON t.MemberID = m.MemberID
            JOIN academic_years y ON t.YearID = y.YearID
            ORDER BY t.CreatedAt DESC
            LIMIT 10
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT t.*, m.MemberName, y.YearName
            FROM transactions t
            JOIN members m ON t.MemberID = m.MemberID
            JOIN academic_years y ON t.YearID = y.YearID
            WHERE t.YearID = ?
            ORDER BY t.CreatedAt DESC
            LIMIT 10
        ");
        $stmt->execute([$selectedYear]);
    }
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top members by collection
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("
            SELECT m.MemberID, m.MemberName, COALESCE(SUM(t.CollectionAmount), 0) as TotalCollection
            FROM members m
            LEFT JOIN transactions t ON m.MemberID = t.MemberID
            GROUP BY m.MemberID
            ORDER BY TotalCollection DESC
            LIMIT 5
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT m.MemberID, m.MemberName, COALESCE(SUM(t.CollectionAmount), 0) as TotalCollection
            FROM members m
            LEFT JOIN transactions t ON m.MemberID = t.MemberID
            WHERE t.YearID = ? OR t.YearID IS NULL
            GROUP BY m.MemberID
            ORDER BY TotalCollection DESC
            LIMIT 5
        ");
        $stmt->execute([$selectedYear]);
    }
    $topMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Monthly trend (last 6 months)
    $stmt = $pdo->query("
        SELECT 
            DATE_FORMAT(TransactionDate, '%b %Y') as Month,
            YEAR(TransactionDate) as Year,
            MONTH(TransactionDate) as MonthNum,
            COALESCE(SUM(CollectionAmount), 0) as Collection,
            COALESCE(SUM(DistributedAmount), 0) as Distribution
        FROM transactions
        WHERE TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY YEAR(TransactionDate), MONTH(TransactionDate)
        ORDER BY YEAR(TransactionDate) ASC, MONTH(TransactionDate) ASC
    ");
    $monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get audit logs
    $stmt = $pdo->query("SELECT COUNT(*) FROM audit_logs");
    $totalLogs = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY CreatedAt DESC LIMIT 10");
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get database size
    $stmt = $pdo->query("
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb 
        FROM information_schema.TABLES 
        WHERE table_schema = DATABASE()
    ");
    $dbSize = $stmt->fetch(PDO::FETCH_ASSOC)['size_mb'] ?? 0;
    
    // Recent member registrations
    $stmt = $pdo->query("
        SELECT * FROM members 
        ORDER BY CreatedAt DESC 
        LIMIT 5
    ");
    $recentMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pending notifications (check if table exists)
    $unreadNotifications = 0;
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'system_notifications'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM system_notifications 
                WHERE (TargetType = 'Admin' OR TargetType = 'All') 
                AND IsRead = 0
            ");
            $unreadNotifications = $stmt->fetchColumn();
        }
    } catch (Exception $e) {
        $unreadNotifications = 0;
    }
    
    // Get academic years
    $stmt = $pdo->query("SELECT * FROM academic_years ORDER BY StartDate DESC");
    $academicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    // Log error for debugging
    error_log("Admin Dashboard Error: " . $e->getMessage());
    
    // Default values
    $totalMembers = 0;
    $activeMembers = 0;
    $totalTransactions = 0;
    $totalCollection = 0;
    $totalDistribution = 0;
    $totalInterest = 0;
    $netBalance = 0;
    $todayTransactions = 0;
    $todayCollection = 0;
    $monthCollection = 0;
    $recentTransactions = [];
    $topMembers = [];
    $monthlyTrend = [];
    $recentMembers = [];
    $unreadNotifications = 0;
    $academicYears = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #14b8a6;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #0f172a;
            --light: #f8fafc;
            --border: #e2e8f0;
            --text: #334155;
            --text-light: #64748b;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: var(--text);
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            z-index: 100;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header h2 {
            color: white;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }
        
        .sidebar-header .admin-role {
            color: #94a3b8;
            font-size: 0.875rem;
        }
        
        .sidebar-menu {
            padding: 1rem 0;
        }
        
        .menu-item {
            padding: 0.875rem 1.5rem;
            display: flex;
            align-items: center;
            color: #cbd5e1;
            text-decoration: none;
            transition: all 0.2s;
            position: relative;
        }
        
        .menu-item:hover {
            background: rgba(255,255,255,0.05);
            color: white;
        }
        
        .menu-item.active {
            background: rgba(99, 102, 241, 0.2);
            color: white;
            border-left: 4px solid var(--primary);
        }
        
        .menu-item i {
            width: 24px;
            margin-right: 0.75rem;
            font-size: 1.125rem;
        }
        
        .menu-badge {
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
            margin-left: 280px;
            padding: 2rem;
            min-height: 100vh;
        }
        
        .top-bar {
            background: white;
            padding: 1.5rem 2rem;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .welcome-section h1 {
            font-size: 1.875rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .welcome-section p {
            color: var(--text-light);
            font-size: 0.875rem;
        }
        
        .top-actions {
            display: flex;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.75rem 1.25rem;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
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
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid var(--border);
            color: var(--text);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.75rem;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--secondary) 100%);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
        }
        
        .stat-icon.blue { background: #dbeafe; color: #1e40af; }
        .stat-icon.green { background: #d1fae5; color: #065f46; }
        .stat-icon.purple { background: #e9d5ff; color: #6b21a8; }
        .stat-icon.orange { background: #fed7aa; color: #c2410c; }
        .stat-icon.red { background: #fee2e2; color: #991b1b; }
        .stat-icon.teal { background: #ccfbf1; color: #115e59; }
        
        .stat-value {
            font-size: 2.25rem;
            font-weight: 800;
            color: var(--dark);
            line-height: 1;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--text-light);
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .stat-trend {
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
        }
        
        .stat-trend.up { color: var(--success); }
        .stat-trend.down { color: var(--danger); }
        
        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .card-header {
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h3 {
            font-size: 1.125rem;
            color: var(--dark);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-body {
            padding: 1.5rem 2rem;
        }
        
        /* Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table thead {
            background: var(--light);
        }
        
        .data-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.8125rem;
            color: var(--text);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
        }
        
        .data-table tbody tr:hover {
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
        
        .badge {
            padding: 0.375rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .badge-primary {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-info {
            background: #cffafe;
            color: #155e75;
        }
        
        .badge-warning {
            background: #fed7aa;
            color: #c2410c;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-secondary {
            background: #e2e8f0;
            color: #475569;
        }
        
        /* Quick Actions */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }
        
        .quick-action {
            padding: 1.25rem;
            background: var(--light);
            border: 2px solid var(--border);
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: var(--text);
            transition: all 0.2s;
        }
        
        .quick-action:hover {
            background: white;
            border-color: var(--primary);
            transform: translateX(5px);
        }
        
        .quick-action-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .quick-action-content h4 {
            font-size: 0.9375rem;
            color: var(--dark);
            margin-bottom: 0.25rem;
        }
        
        .quick-action-content p {
            font-size: 0.8125rem;
            color: var(--text-light);
        }
        
        /* Chart */
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        /* Member List */
        .member-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 0;
            border-bottom: 1px solid var(--border);
        }
        
        .member-item:last-child {
            border-bottom: none;
        }
        
        .member-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .member-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.875rem;
        }
        
        .member-details h4 {
            font-size: 0.9375rem;
            color: var(--dark);
            margin-bottom: 0.125rem;
        }
        
        .member-details p {
            font-size: 0.8125rem;
            color: var(--text-light);
        }
        
        .member-amount {
            font-weight: 700;
            color: var(--success);
            font-size: 1rem;
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
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stat-card {
            animation: fadeInUp 0.6s ease-out;
        }
        
        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2><?php echo htmlspecialchars($admin['FullName'] ?? 'Admin'); ?></h2>
            <p class="admin-role">Administrator</p>
        </div>
        <nav class="sidebar-menu">
            <a href="dashboard-new.php" class="menu-item active">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="manage-members.php" class="menu-item">
                <i class="fas fa-users"></i>
                <span>Manage Members</span>
            </a>
            <a href="manage-transactions.php" class="menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span>Transactions</span>
            </a>
            <a href="manage-years.php" class="menu-item">
                <i class="fas fa-calendar"></i>
                <span>Academic Years</span>
            </a>
            <a href="reports.php" class="menu-item">
                <i class="fas fa-chart-bar"></i>
                <span>Reports</span>
            </a>
            <a href="notifications.php" class="menu-item">
                <i class="fas fa-bell"></i>
                <span>Notifications</span>
                <?php if ($unreadNotifications > 0): ?>
                    <span class="menu-badge"><?php echo $unreadNotifications; ?></span>
                <?php endif; ?>
            </a>
            <a href="profile.php" class="menu-item">
                <i class="fas fa-user-cog"></i>
                <span>My Profile</span>
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
            <div class="welcome-section">
                <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $admin['FullName'] ?? 'Admin')[0]); ?>! 👋</h1>
                <p><?php echo date('l, F j, Y'); ?> • <?php echo date('g:i A'); ?></p>
            </div>
            <div class="top-actions">
                <select id="yearFilter" class="form-control" style="width: 200px; margin-right: 10px;" onchange="window.location.href='dashboard-new.php?year=' + this.value">
                    <option value="all" <?php echo $selectedYear === 'all' ? 'selected' : ''; ?>>All Years</option>
                    <?php foreach ($academicYears as $year): ?>
                        <option value="<?php echo $year['YearID']; ?>" <?php echo $selectedYear == $year['YearID'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($year['YearName']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="add-member.php" class="btn btn-success">
                    <i class="fas fa-user-plus"></i>
                    Add Member
                </a>
                <!-- <a href="add-transaction.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i>
                    New Transaction
                </a> -->
            </div>
        </div>
        
        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo $totalAdmins; ?></div>
                        <div class="stat-label">Total Admins</div>
                    </div>
                    <div class="stat-icon purple">
                        <i class="fas fa-user-shield"></i>
                    </div>
                </div>
                <div class="stat-trend up">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $activeAdmins; ?> Active</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo $totalMembers; ?></div>
                        <div class="stat-label">Total Members</div>
                    </div>
                    <div class="stat-icon blue">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                <div class="stat-trend up">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $activeMembers; ?> Active</span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value">₹<?php echo number_format($totalCollection, 0); ?></div>
                        <div class="stat-label">Total Collection</div>
                    </div>
                    <div class="stat-icon green">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                </div>
                <div class="stat-trend up">
                    <i class="fas fa-calendar-day"></i>
                    <span>Today: ₹<?php echo number_format($todayCollection, 2); ?></span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value">₹<?php echo number_format($totalDistribution, 0); ?></div>
                        <div class="stat-label">Total Distribution</div>
                    </div>
                    <div class="stat-icon purple">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                </div>
                <div class="stat-trend down">
                    <i class="fas fa-info-circle"></i>
                    <span>Balance: ₹<?php echo number_format($netBalance, 2); ?></span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo $totalTransactions; ?></div>
                        <div class="stat-label">Transactions</div>
                    </div>
                    <div class="stat-icon orange">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                </div>
                <div class="stat-trend up">
                    <i class="fas fa-calendar-week"></i>
                    <span>Today: <?php echo $todayTransactions; ?></span>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-header">
                    <div>
                        <div class="stat-value"><?php echo number_format($totalLogs); ?></div>
                        <div class="stat-label">Audit Logs</div>
                    </div>
                    <div class="stat-icon teal">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                </div>
                <div class="stat-trend up">
                    <i class="fas fa-database"></i>
                    <span>DB: <?php echo $dbSize; ?> MB</span>
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
                    <a href="manage-transactions.php" class="btn btn-outline" style="padding: 0.5rem 1rem; font-size: 0.8125rem;">
                        View All
                    </a>
                </div>
                <div class="card-body" style="padding: 0;">
                    <?php if (empty($recentTransactions)): ?>
                        <p style="padding: 2rem; text-align: center; color: var(--text-light);">
                            No transactions found
                        </p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Member</th>
                                    <th>Year</th>
                                    <th>Collection</th>
                                    <th>Distribution</th>
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
                                        <td><?php echo htmlspecialchars($txn['MemberName']); ?></td>
                                        <td><?php echo htmlspecialchars($txn['YearName']); ?></td>
                                        <td class="amount-positive">
                                            <?php echo $txn['CollectionAmount'] > 0 ? '₹' . number_format($txn['CollectionAmount'], 2) : '-'; ?>
                                        </td>
                                        <td class="amount-negative">
                                            <?php echo $txn['DistributedAmount'] > 0 ? '₹' . number_format($txn['DistributedAmount'], 2) : '-'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Right Column -->
            <div>
                <!-- Quick Actions -->
                <div class="card" style="margin-bottom: 2rem;">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-bolt"></i>
                            Quick Actions
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="quick-actions-grid">
                            <a href="add-member.php" class="quick-action">
                                <div class="quick-action-icon blue">
                                    <i class="fas fa-user-plus"></i>
                                </div>
                                <div class="quick-action-content">
                                    <h4>Add New Member</h4>
                                    <p>Register a new member</p>
                                </div>
                            </a>
                            <a href="add-transaction.php" class="quick-action">
                                <div class="quick-action-icon green">
                                    <i class="fas fa-plus-circle"></i>
                                </div>
                                <div class="quick-action-content">
                                    <h4>New Transaction</h4>
                                    <p>Add collection/distribution</p>
                                </div>
                            </a>
                            <a href="manage-members.php" class="quick-action">
                                <div class="quick-action-icon purple">
                                    <i class="fas fa-users-cog"></i>
                                </div>
                                <div class="quick-action-content">
                                    <h4>Manage Members</h4>
                                    <p>View and edit members</p>
                                </div>
                            </a>
                            <a href="reports.php" class="quick-action">
                                <div class="quick-action-icon orange">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div class="quick-action-content">
                                    <h4>Generate Reports</h4>
                                    <p>Financial reports & analytics</p>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Top Members -->
                <div class="card">
                    <div class="card-header">
                        <h3>
                            <i class="fas fa-trophy"></i>
                            Top Contributors
                        </h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($topMembers)): ?>
                            <p style="text-align: center; color: var(--text-light);">
                                No data available
                            </p>
                        <?php else: ?>
                            <?php foreach ($topMembers as $idx => $member): ?>
                                <div class="member-item">
                                    <div class="member-info">
                                        <div class="member-avatar">
                                            <?php echo strtoupper(substr($member['MemberName'], 0, 2)); ?>
                                        </div>
                                        <div class="member-details">
                                            <h4><?php echo htmlspecialchars($member['MemberName']); ?></h4>
                                            <p>Member ID: <?php echo $member['MemberID']; ?></p>
                                        </div>
                                    </div>
                                    <div class="member-amount">
                                        ₹<?php echo number_format($member['TotalCollection'], 2); ?>
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
                    <i class="fas fa-chart-area"></i>
                    Collection & Distribution Trend (Last 6 Months)
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
                    <i class="fas fa-chart-area"></i>
                    Collection & Distribution Trend (Last 6 Months)
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
        
        <!-- Recent Audit Logs -->
        <div class="card">
            <div class="card-header">
                <h3>
                    <i class="fas fa-clipboard-list"></i>
                    Recent Audit Logs
                </h3>
            </div>
            <div class="card-body">
                <?php if (empty($recentLogs)): ?>
                    <p style="text-align: center; color: var(--text-light);">
                        No audit logs available
                    </p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Entity</th>
                                    <th>Details</th>
                                    <th>IP Address</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentLogs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['Username']); ?></td>
                                        <td>
                                            <?php
                                                $actionBadge = 'secondary';
                                                switch($log['Action']) {
                                                    case 'CREATE': $actionBadge = 'success'; break;
                                                    case 'UPDATE': $actionBadge = 'info'; break;
                                                    case 'DELETE': $actionBadge = 'danger'; break;
                                                    case 'LOGIN': $actionBadge = 'primary'; break;
                                                    case 'LOGOUT': $actionBadge = 'secondary'; break;
                                                }
                                            ?>
                                            <span class="badge badge-<?php echo $actionBadge; ?>">
                                                <?php echo htmlspecialchars($log['Action']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($log['EntityType'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars(substr($log['Details'] ?? '', 0, 50)); ?></td>
                                        <td><?php echo htmlspecialchars($log['IPAddress'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($log['CreatedAt'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <script>
        // Monthly Trend Chart
        <?php if (!empty($monthlyTrend)): ?>
        console.log('Admin Monthly Trend Data:', <?php echo json_encode($monthlyTrend); ?>);
        const trendCtx = document.getElementById('trendChart');
        if (trendCtx) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($monthlyTrend, 'Month')); ?>,
                    datasets: [
                        {
                            label: 'Collection',
                            data: <?php echo json_encode(array_column($monthlyTrend, 'Collection')); ?>,
                            borderColor: '#22c55e',
                            backgroundColor: 'rgba(34, 197, 94, 0.1)',
                            tension: 0.4,
                            fill: true,
                            borderWidth: 3
                        },
                        {
                            label: 'Distribution',
                            data: <?php echo json_encode(array_column($monthlyTrend, 'Distribution')); ?>,
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            tension: 0.4,
                            fill: true,
                            borderWidth: 3
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
                                padding: 20,
                                font: {
                                    family: 'Inter',
                                    size: 12,
                                    weight: 600
                                }
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            backgroundColor: 'rgba(15, 23, 42, 0.9)',
                            padding: 12,
                            titleFont: {
                                family: 'Inter',
                                size: 13,
                                weight: 600
                            },
                            bodyFont: {
                                family: 'Inter',
                                size: 12
                            },
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
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                font: {
                                    family: 'Inter',
                                    size: 11
                                },
                                callback: function(value) {
                                    return '₹' + value.toFixed(0);
                                }
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    family: 'Inter',
                                    size: 11
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
        menuToggle.style.cssText = 'position: fixed; top: 1rem; left: 1rem; z-index: 101; background: white; border: none; padding: 0.75rem; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: none; cursor: pointer;';
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
</body>
</html>
