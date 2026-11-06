<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/link-target-helper.php';
require_once '../includes/logo-helper.php';
require_once '../includes/net-balance-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Get selected academic year filter
$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';

// Fetch academic years for filter dropdown
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build WHERE clause for year filtering
$yearFilter = '';
$yearParams = [];
if ($selectedYear !== 'all') {
    $yearFilter = ' WHERE YearID = ?';
    $yearParams = [$selectedYear];
}

// Fetch system statistics
try {
    // Get total admins
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM admins");
    $total_admins = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM admins WHERE Status = 'Active'");
    $active_admins = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total members
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM members");
    $total_members = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM members WHERE Status = 'Active'");
    $active_members = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total transactions
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM transactions");
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM transactions WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $total_transactions = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total collection amount
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT IFNULL(SUM(CollectionAmount), 0) as total FROM transactions");
    } else {
        $stmt = $pdo->prepare("SELECT IFNULL(SUM(CollectionAmount), 0) as total FROM transactions WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $total_collection = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

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

    // Get total fixed amount from academic years
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT IFNULL(SUM(FixedAmount), 0) as total FROM academic_years");
    } else {
        $stmt = $pdo->prepare("SELECT IFNULL(SUM(FixedAmount), 0) as total FROM academic_years WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $total_fixed_amount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total expenses
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT IFNULL(SUM(Amount), 0) as total FROM expenses");
    } else {
        $stmt = $pdo->prepare("SELECT IFNULL(SUM(Amount), 0) as total FROM expenses WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $total_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total change amount from academic years
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT IFNULL(SUM(ChangeAmount), 0) as total FROM academic_years");
    } else {
        $stmt = $pdo->prepare("SELECT IFNULL(SUM(ChangeAmount), 0) as total FROM academic_years WHERE YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $total_change_amount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Calculate net balance
    $net_balance = ($total_interest + $total_fixed_amount + $total_change_amount) - $total_expenses;

    // Get today's transactions
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM transactions WHERE DATE(TransactionDate) = CURDATE()");
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM transactions WHERE DATE(TransactionDate) = CURDATE() AND YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $today_transactions = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get this month's collection
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT IFNULL(SUM(CollectionAmount), 0) as total FROM transactions WHERE MONTH(TransactionDate) = MONTH(CURDATE()) AND YEAR(TransactionDate) = YEAR(CURDATE())");
    } else {
        $stmt = $pdo->prepare("SELECT IFNULL(SUM(CollectionAmount), 0) as total FROM transactions WHERE MONTH(TransactionDate) = MONTH(CURDATE()) AND YEAR(TransactionDate) = YEAR(CURDATE()) AND YearID = ?");
        $stmt->execute([$selectedYear]);
    }
    $month_collection = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get audit logs count
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM audit_logs");
    $total_logs = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get recent audit logs
    $stmt = $pdo->query("SELECT * FROM audit_logs ORDER BY CreatedAt DESC LIMIT 10");
    $recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get database size
    $stmt = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb FROM information_schema.TABLES WHERE table_schema = DATABASE()");
    $db_size = $stmt->fetch(PDO::FETCH_ASSOC)['size_mb'] ?? 0;

    // Get system settings
    $stmt = $pdo->query("SELECT * FROM system_settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['SettingKey']] = $row['SettingValue'];
    }

    // Get monthly collection trend (last 12 months)
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT DATE_FORMAT(TransactionDate, '%b %Y') as month, YEAR(TransactionDate) as year, MONTH(TransactionDate) as month_num, IFNULL(SUM(CollectionAmount), 0) as collection, IFNULL(SUM(DistributedAmount), 0) as distribution FROM transactions WHERE TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY YEAR(TransactionDate), MONTH(TransactionDate) ORDER BY year ASC, month_num ASC");
    } else {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(TransactionDate, '%b %Y') as month, YEAR(TransactionDate) as year, MONTH(TransactionDate) as month_num, IFNULL(SUM(CollectionAmount), 0) as collection, IFNULL(SUM(DistributedAmount), 0) as distribution FROM transactions WHERE TransactionDate >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND YearID = ? GROUP BY YEAR(TransactionDate), MONTH(TransactionDate) ORDER BY year ASC, month_num ASC");
        $stmt->execute([$selectedYear]);
    }
    $monthly_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get transaction type distribution
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT CASE WHEN DistributedAmount > 0 THEN 'Distribution' WHEN CollectionAmount > 0 THEN 'Collection' ELSE 'Other' END as type, COUNT(*) as count FROM transactions GROUP BY type");
    } else {
        $stmt = $pdo->prepare("SELECT CASE WHEN DistributedAmount > 0 THEN 'Distribution' WHEN CollectionAmount > 0 THEN 'Collection' ELSE 'Other' END as type, COUNT(*) as count FROM transactions WHERE YearID = ? GROUP BY type");
        $stmt->execute([$selectedYear]);
    }
    $transaction_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get top 5 members by collection
    if ($selectedYear === 'all') {
        $stmt = $pdo->query("SELECT m.MemberName, IFNULL(SUM(t.CollectionAmount), 0) as total_collection FROM members m LEFT JOIN transactions t ON m.MemberID = t.MemberID GROUP BY m.MemberID ORDER BY total_collection DESC LIMIT 5");
    } else {
        $stmt = $pdo->prepare("SELECT m.MemberName, IFNULL(SUM(t.CollectionAmount), 0) as total_collection FROM members m LEFT JOIN transactions t ON m.MemberID = t.MemberID WHERE t.YearID = ? OR t.YearID IS NULL GROUP BY m.MemberID ORDER BY total_collection DESC LIMIT 5");
        $stmt->execute([$selectedYear]);
    }
    $top_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get activity by hour (last 7 days)
    $stmt = $pdo->query("SELECT HOUR(CreatedAt) as hour, COUNT(*) as count FROM audit_logs WHERE CreatedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY hour ORDER BY hour");
    $activity_by_hour = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent growth stats
    $stmt = $pdo->query("SELECT COUNT(CASE WHEN DATE(CreatedAt) = CURDATE() THEN 1 END) as today_members, COUNT(CASE WHEN DATE(CreatedAt) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as week_members, COUNT(CASE WHEN DATE(CreatedAt) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as month_members FROM members");
    $growth_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // ===== NEW QUERIES =====

    // Expense by category (top 5)
    try {
        $catQuery = "SELECT Category, IFNULL(SUM(Amount), 0) as total_amount, COUNT(*) as count FROM expenses";
        if ($selectedYear !== 'all') {
            $catQuery .= " WHERE YearID = ?";
            $stmt = $pdo->prepare($catQuery . " GROUP BY Category ORDER BY total_amount DESC LIMIT 5");
            $stmt->execute([$selectedYear]);
        } else {
            $stmt = $pdo->query($catQuery . " GROUP BY Category ORDER BY total_amount DESC LIMIT 5");
        }
        $expense_by_category = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $expense_by_category = []; }

    // Payment mode breakdown
    try {
        $pmQuery = "SELECT PaymentMode, COUNT(*) as count, IFNULL(SUM(CollectionAmount), 0) as total_amount FROM transactions";
        if ($selectedYear !== 'all') {
            $pmQuery .= " WHERE YearID = ?";
            $stmt = $pdo->prepare($pmQuery . " GROUP BY PaymentMode ORDER BY count DESC");
            $stmt->execute([$selectedYear]);
        } else {
            $stmt = $pdo->query($pmQuery . " GROUP BY PaymentMode ORDER BY count DESC");
        }
        $payment_modes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $payment_modes = []; }

    // Event summary
    try {
        $evQ = "SELECT COUNT(*) as total_events, IFNULL(SUM(TotalCollection), 0) as total_event_collections, IFNULL(SUM(TotalExpense), 0) as total_event_expenses, COUNT(CASE WHEN Status = 'Ongoing' THEN 1 END) as ongoing_count, COUNT(CASE WHEN Status = 'Completed' THEN 1 END) as completed_count FROM events";
        if ($selectedYear !== 'all') {
            $stmt = $pdo->prepare($evQ . " WHERE YearID = ?");
            $stmt->execute([$selectedYear]);
        } else {
            $stmt = $pdo->query($evQ);
        }
        $event_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $event_summary = ['total_events' => 0, 'total_event_collections' => 0, 'total_event_expenses' => 0, 'ongoing_count' => 0, 'completed_count' => 0]; }

    // Notification stats
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total, IFNULL(SUM(CASE WHEN IsRead = 0 THEN 1 ELSE 0 END), 0) as unread FROM notifications");
        $notification_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $notification_stats = ['total' => 0, 'unread' => 0]; }

    // Monthly expense trend (last 12 months)
    try {
        $expTQ = "SELECT DATE_FORMAT(ExpenseDate, '%b %Y') as month, YEAR(ExpenseDate) as year, MONTH(ExpenseDate) as month_num, IFNULL(SUM(Amount), 0) as total_expense FROM expenses WHERE ExpenseDate >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
        if ($selectedYear !== 'all') {
            $stmt = $pdo->prepare($expTQ . " AND YearID = ? GROUP BY YEAR(ExpenseDate), MONTH(ExpenseDate) ORDER BY year ASC, month_num ASC");
            $stmt->execute([$selectedYear]);
        } else {
            $stmt = $pdo->query($expTQ . " GROUP BY YEAR(ExpenseDate), MONTH(ExpenseDate) ORDER BY year ASC, month_num ASC");
        }
        $expense_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $expense_trend = []; }

    // Year-wise financial summary
    try {
        $stmt = $pdo->query("SELECT ay.YearID, ay.YearName, IFNULL(ay.FixedAmount, 0) as FixedAmount, IFNULL(ay.ChangeAmount, 0) as ChangeAmount, ay.InterestRate, ay.Status, IFNULL(SUM(t.CollectionAmount), 0) as total_collection, IFNULL(SUM(t.InterestAmount), 0) as total_interest, IFNULL(SUM(t.DistributedAmount), 0) as total_distribution, IFNULL((SELECT SUM(Amount) FROM expenses WHERE YearID = ay.YearID), 0) as total_expenses, (SELECT COUNT(DISTINCT MemberID) FROM transactions WHERE YearID = ay.YearID) as member_count FROM academic_years ay LEFT JOIN transactions t ON ay.YearID = t.YearID GROUP BY ay.YearID ORDER BY ay.YearName DESC");
        $year_summaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $year_summaries = []; }

    // Combined activity feed (transactions + expenses + audit logs)
    try {
        $stmt = $pdo->query("(SELECT 'transaction' as feed_type, TransactionID as feed_id, CONCAT('Collection from member #', MemberID) as feed_text, CollectionAmount as feed_amount, TransactionDate as feed_date FROM transactions ORDER BY TransactionDate DESC, TransactionID DESC LIMIT 5) UNION ALL (SELECT 'expense' as feed_type, ExpenseID as feed_id, Category as feed_text, Amount as feed_amount, ExpenseDate as feed_date FROM expenses ORDER BY ExpenseDate DESC, ExpenseID DESC LIMIT 5) UNION ALL (SELECT 'audit' as feed_type, LogID as feed_id, Action as feed_text, 0 as feed_amount, CreatedAt as feed_date FROM audit_logs ORDER BY CreatedAt DESC LIMIT 5) ORDER BY feed_date DESC LIMIT 15");
        $combined_feed = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $combined_feed = []; }

    // This month's expenses
    try {
        $meQ = "SELECT IFNULL(SUM(Amount), 0) as total FROM expenses WHERE MONTH(ExpenseDate) = MONTH(CURDATE()) AND YEAR(ExpenseDate) = YEAR(CURDATE())";
        if ($selectedYear !== 'all') {
            $stmt = $pdo->prepare($meQ . " AND YearID = ?");
            $stmt->execute([$selectedYear]);
        } else {
            $stmt = $pdo->query($meQ);
        }
        $month_expenses = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch (Exception $e) { $month_expenses = 0; }

} catch (Exception $e) {
    error_log("SuperAdmin Dashboard Error: " . $e->getMessage());
    $error = "Error loading dashboard: " . $e->getMessage();
    $total_admins = 0; $active_admins = 0; $total_members = 0; $active_members = 0;
    $total_transactions = 0; $total_collection = 0; $total_distribution = 0; $total_interest = 0;
    $total_fixed_amount = 0; $total_expenses = 0; $total_change_amount = 0; $net_balance = 0;
    $today_transactions = 0; $month_collection = 0; $total_logs = 0; $recent_logs = [];
    $db_size = 0; $settings = []; $monthly_trend = []; $transaction_types = [];
    $top_members = []; $activity_by_hour = []; $growth_stats = ['today_members' => 0, 'week_members' => 0, 'month_members' => 0];
    $expense_by_category = []; $payment_modes = []; $event_summary = ['total_events' => 0, 'total_event_collections' => 0, 'total_event_expenses' => 0, 'ongoing_count' => 0, 'completed_count' => 0];
    $notification_stats = ['total' => 0, 'unread' => 0]; $expense_trend = []; $year_summaries = [];
    $combined_feed = []; $month_expenses = 0;
}

// 2FA and Face Lock status
$twofa_status = false;
try {
    $stmt2fa = $pdo->prepare("SELECT totp_enabled FROM super_admins WHERE SuperAdminID = ?");
    $stmt2fa->execute([$superadmin_id]);
    $twofa_row = $stmt2fa->fetch(PDO::FETCH_ASSOC);
    $twofa_status = $twofa_row && $twofa_row['totp_enabled'] == 1;
} catch (Exception $e) {}
$face_status = false;
try {
    $stmtFace = $pdo->prepare("SELECT face_enabled FROM super_admins WHERE SuperAdminID = ?");
    $stmtFace->execute([$superadmin_id]);
    $face_row = $stmtFace->fetch(PDO::FETCH_ASSOC);
    $face_status = $face_row && $face_row['face_enabled'] == 1;
} catch (Exception $e) {}

// Distribution rate
$distribution_rate = $total_collection > 0 ? ($total_distribution / $total_collection) * 100 : 0;
$income_base = $total_interest + $total_fixed_amount + $total_change_amount;
$expense_ratio = $income_base > 0 ? ($total_expenses / $income_base) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
    /* ============================================
       SR Colony Super Admin Dashboard
       Advanced Animated CSS Design
       ============================================ */

    /* === GLOBAL ANIMATION KEYFRAMES === */
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeInLeft {
        from { opacity: 0; transform: translateX(-30px); }
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes fadeInRight {
        from { opacity: 0; transform: translateX(30px); }
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes scaleIn {
        from { opacity: 0; transform: scale(0.85); }
        to { opacity: 1; transform: scale(1); }
    }
    @keyframes slideInRight {
        from { opacity: 0; transform: translateX(60px); }
        to { opacity: 1; transform: translateX(0); }
    }
    @keyframes float {
        0%, 100% { transform: translateY(0) rotate(0deg); }
        25% { transform: translateY(-8px) rotate(2deg); }
        75% { transform: translateY(4px) rotate(-1deg); }
    }
    @keyframes pulse {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.05); opacity: 0.8; }
    }
    @keyframes shimmer {
        0% { background-position: -200% 0; }
        100% { background-position: 200% 0; }
    }
    @keyframes gradientShift {
        0% { background-position: 0% 50%; }
        50% { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    @keyframes ripple {
        0% { transform: scale(0); opacity: 0.5; }
        100% { transform: scale(4); opacity: 0; }
    }
    @keyframes glow {
        0%, 100% { box-shadow: 0 0 5px rgba(102, 126, 234, 0.2), 0 0 10px rgba(102, 126, 234, 0.1); }
        50% { box-shadow: 0 0 20px rgba(102, 126, 234, 0.4), 0 0 40px rgba(102, 126, 234, 0.2); }
    }
    @keyframes borderGlow {
        0%, 100% { border-color: rgba(102, 126, 234, 0.3); }
        50% { border-color: rgba(102, 126, 234, 0.8); }
    }
    @keyframes countUp {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeInScale {
        from { opacity: 0; transform: translate(-50%, -50%) scale(0.9); }
        to { opacity: 1; transform: translate(-50%, -50%) scale(1); }
    }
    @keyframes orbFloat1 {
        0%, 100% { transform: translate(0, 0) scale(1); }
        33% { transform: translate(30px, -20px) scale(1.1); }
        66% { transform: translate(-20px, 15px) scale(0.95); }
    }
    @keyframes orbFloat2 {
        0%, 100% { transform: translate(0, 0) scale(1); }
        33% { transform: translate(-25px, 20px) scale(0.9); }
        66% { transform: translate(15px, -25px) scale(1.05); }
    }
    @keyframes progressGrow {
        from { width: 0; }
    }
    @keyframes dotPulse {
        0%, 100% { box-shadow: 0 0 0 0 currentColor; }
        50% { box-shadow: 0 0 0 6px transparent; }
    }
    @keyframes typewriter {
        from { width: 0; }
        to { width: 100%; }
    }

    /* === PAGE LAYOUT === */
    .container {
        max-width: 100%; margin: 0 auto; padding: 24px;
        animation: fadeInUp 0.6s cubic-bezier(0.22, 1, 0.36, 1) both;
    }
    .navbar-brand { display: flex; align-items: center; gap: 10px; }
    .navbar-brand i { font-size: 22px; animation: float 4s ease-in-out infinite; }
    .navbar-brand span { font-size: 18px; font-weight: 700; }

    /* === PAGE HEADER === */
    .page-header {
        display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;
        margin-bottom: 28px; padding-bottom: 20px; border-bottom: 1px solid var(--border-color);
        animation: fadeInDown 0.5s ease 0.1s both;
    }
    .page-header h1 {
        font-size: 28px; font-weight: 800; color: var(--text-primary);
        display: flex; align-items: center; gap: 12px;
    }
    .page-header h1 i {
        background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .header-right { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .year-filter {
        padding: 10px 16px; border: 2px solid var(--border-color); border-radius: 10px;
        background: var(--bg-secondary-solid, #fff); color: var(--text-primary);
        font-size: 13px; font-weight: 600; cursor: pointer; min-width: 160px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .year-filter:focus { border-color: var(--primary-color); outline: none; box-shadow: 0 0 0 4px var(--focus-shadow); }
    .refresh-btn {
        padding: 10px 18px; border-radius: 10px; border: 2px solid var(--border-color);
        background: var(--bg-secondary-solid, #fff); color: var(--text-primary);
        font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); font-size: 13px;
        position: relative; overflow: hidden;
    }
    .refresh-btn::after {
        content: ''; position: absolute; top: 50%; left: 50%; width: 0; height: 0;
        background: rgba(102, 126, 234, 0.15); border-radius: 50%;
        transform: translate(-50%, -50%); transition: all 0.5s ease;
    }
    .refresh-btn:hover::after { width: 200px; height: 200px; }
    .refresh-btn:hover { border-color: var(--primary-color); color: var(--primary-color); }
    .refresh-btn.spinning i { animation: spin 0.8s linear infinite; }
    .notif-badge {
        display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px;
        background: linear-gradient(135deg, rgba(239,68,68,0.1), rgba(220,38,38,0.05));
        border: 1px solid rgba(239,68,68,0.2); border-radius: 10px; color: #ef4444;
        font-weight: 700; font-size: 13px; animation: pulse 2s ease infinite;
    }
    .notif-badge i { font-size: 14px; }
    .header-date { color: var(--text-secondary); font-size: 13px; }

    /* ============ HERO FINANCIAL KPIs ============ */
    .hero-stats {
        display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px;
    }
    .hero-card {
        border-radius: 20px; padding: 28px 24px; color: #fff; display: flex;
        justify-content: space-between; align-items: center; position: relative; overflow: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); cursor: default;
        background-size: 200% 200%;
    }
    .hero-card:hover {
        transform: translateY(-8px) scale(1.02);
        filter: brightness(1.08);
    }
    /* Animated orbs */
    .hero-card::before {
        content: ''; position: absolute; top: -40%; right: -15%; width: 180px; height: 180px;
        background: rgba(255,255,255,0.1); border-radius: 50%; pointer-events: none;
        animation: orbFloat1 8s ease-in-out infinite;
    }
    .hero-card::after {
        content: ''; position: absolute; bottom: -30%; left: -10%; width: 140px; height: 140px;
        background: rgba(255,255,255,0.07); border-radius: 50%; pointer-events: none;
        animation: orbFloat2 10s ease-in-out infinite;
    }
    .hero-card.hc-collection {
        background: linear-gradient(135deg, #00b894, #00cec9, #2ecc71);
        background-size: 200% 200%; animation: fadeInUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.05s both, gradientShift 6s ease infinite;
        box-shadow: 0 8px 32px rgba(0, 184, 148, 0.35);
    }
    .hero-card.hc-collection:hover { box-shadow: 0 16px 48px rgba(0, 184, 148, 0.5); }
    .hero-card.hc-distribution {
        background: linear-gradient(135deg, #fdcb6e, #f39c12, #e17055);
        background-size: 200% 200%; animation: fadeInUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.12s both, gradientShift 6s ease infinite;
        box-shadow: 0 8px 32px rgba(243, 156, 18, 0.35);
    }
    .hero-card.hc-distribution:hover { box-shadow: 0 16px 48px rgba(243, 156, 18, 0.5); }
    .hero-card.hc-expenses {
        background: linear-gradient(135deg, #ff7675, #e74c3c, #d63031);
        background-size: 200% 200%; animation: fadeInUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.19s both, gradientShift 6s ease infinite;
        box-shadow: 0 8px 32px rgba(231, 76, 60, 0.35);
    }
    .hero-card.hc-expenses:hover { box-shadow: 0 16px 48px rgba(231, 76, 60, 0.5); }
    .hero-card.hc-balance-pos {
        background: linear-gradient(135deg, #667eea, #764ba2, #6c5ce7);
        background-size: 200% 200%; animation: fadeInUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.26s both, gradientShift 6s ease infinite;
        box-shadow: 0 8px 32px rgba(102, 126, 234, 0.35);
    }
    .hero-card.hc-balance-pos:hover { box-shadow: 0 16px 48px rgba(102, 126, 234, 0.5); }
    .hero-card.hc-balance-neg {
        background: linear-gradient(135deg, #eb3349, #f45c43, #e74c3c);
        background-size: 200% 200%; animation: fadeInUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.26s both, gradientShift 6s ease infinite;
        box-shadow: 0 8px 32px rgba(235, 51, 73, 0.35);
    }
    .hero-card.hc-balance-neg:hover { box-shadow: 0 16px 48px rgba(235, 51, 73, 0.5); }
    .hero-card-content { position: relative; z-index: 1; }
    .hero-label {
        font-size: 11px; text-transform: uppercase; font-weight: 700; letter-spacing: 2px; opacity: 0.9;
        margin-bottom: 4px;
    }
    .hero-value {
        font-size: 32px; font-weight: 800; letter-spacing: -0.5px; line-height: 1.2;
        animation: countUp 0.8s ease both;
        text-shadow: 0 2px 10px rgba(0,0,0,0.15);
    }
    .hero-subtitle {
        font-size: 12px; opacity: 0.85; font-weight: 500; margin-top: 8px;
        display: flex; align-items: center; gap: 6px;
    }
    .hero-icon {
        font-size: 56px; opacity: 0.15; position: relative; z-index: 1;
        animation: float 5s ease-in-out infinite;
    }

    /* ============ SECONDARY STAT CARDS ============ */
    .stats-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; margin-bottom: 32px;
    }
    .stat-card {
        background: var(--bg-secondary); border-radius: 16px; padding: 22px; position: relative;
        overflow: hidden; border: 1px solid var(--border-color);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        animation: fadeInUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
    }
    .stat-card:nth-child(1) { animation-delay: 0.3s; }
    .stat-card:nth-child(2) { animation-delay: 0.35s; }
    .stat-card:nth-child(3) { animation-delay: 0.4s; }
    .stat-card:nth-child(4) { animation-delay: 0.45s; }
    .stat-card:nth-child(5) { animation-delay: 0.5s; }
    .stat-card:nth-child(6) { animation-delay: 0.55s; }
    .stat-card:nth-child(7) { animation-delay: 0.6s; }
    .stat-card:nth-child(8) { animation-delay: 0.65s; }
    .stat-card::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
        background: var(--sc-color, var(--primary-color));
        transition: height 0.3s ease;
    }
    .stat-card:hover {
        transform: translateY(-6px);
        box-shadow: 0 12px 40px rgba(0,0,0,0.12);
    }
    .stat-card:hover::before { height: 6px; }
    .stat-card-inner { display: flex; justify-content: space-between; align-items: flex-start; }
    .stat-card-info { flex: 1; }
    .stat-card-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 8px; }
    .stat-card-value {
        font-size: 28px; font-weight: 800; color: var(--text-primary); line-height: 1;
        transition: transform 0.3s ease;
    }
    .stat-card:hover .stat-card-value { transform: scale(1.05); }
    .stat-card-sub { font-size: 12px; color: var(--text-secondary); margin-top: 8px; font-weight: 500; }
    .stat-card-icon {
        width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center;
        justify-content: center; font-size: 20px; color: #fff; flex-shrink: 0;
        background: var(--sc-color, var(--primary-color));
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .stat-card:hover .stat-card-icon {
        transform: scale(1.1) rotate(5deg);
        box-shadow: 0 6px 20px rgba(0,0,0,0.2);
    }
    .sc-blue { --sc-color: #3498db; } .sc-green { --sc-color: #00b894; } .sc-orange { --sc-color: #f39c12; }
    .sc-indigo { --sc-color: #667eea; } .sc-teal { --sc-color: #14b8a6; } .sc-pink { --sc-color: #ec4899; }
    .sc-yellow { --sc-color: #eab308; } .sc-gray { --sc-color: #6b7280; }

    /* ============ SECTION HEADERS ============ */
    .section-header {
        display: flex; justify-content: space-between; align-items: center;
        margin: 36px 0 18px; padding-bottom: 12px; border-bottom: 2px solid var(--border-color);
        animation: fadeInLeft 0.5s ease both;
        position: relative;
    }
    .section-header::after {
        content: ''; position: absolute; bottom: -2px; left: 0; width: 60px; height: 2px;
        background: var(--gradient-primary);
        animation: progressGrow 0.8s ease 0.5s both;
    }
    .section-header.collapsible { cursor: pointer; user-select: none; }
    .section-header.collapsible:hover .section-title { opacity: 0.85; }
    .section-title {
        font-size: 20px; font-weight: 700; color: var(--text-primary);
        display: flex; align-items: center; gap: 10px;
    }
    .section-title i { color: var(--primary-color); font-size: 18px; }
    .section-toggle {
        color: var(--text-secondary); font-size: 14px;
        transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .section-toggle.collapsed { transform: rotate(-90deg); }

    /* ============ QUICK ACTIONS GROUPED ============ */
    .quick-actions-grouped {
        margin-bottom: 10px; overflow: hidden;
        transition: max-height 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .action-group { margin-bottom: 22px; }
    .action-group-title {
        font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
        color: var(--text-secondary); margin-bottom: 12px; padding-left: 4px;
        display: flex; align-items: center; gap: 8px;
    }
    .action-group-title i { color: var(--primary-color); font-size: 14px; }
    .action-group-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;
    }
    .action-btn {
        display: flex; align-items: center; gap: 12px; padding: 14px 18px;
        background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: 14px;
        text-decoration: none; color: var(--text-primary); font-weight: 600; font-size: 13px;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); overflow: hidden; position: relative;
    }
    .action-btn::before {
        content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
        background: linear-gradient(90deg, transparent, var(--btn-glow, rgba(102, 126, 234, 0.08)), transparent);
        transition: left 0.6s ease;
    }
    .action-btn:hover::before { left: 100%; }
    .action-btn:hover {
        border-color: var(--btn-color, var(--primary-color));
        transform: translateY(-4px);
        box-shadow: 0 10px 30px var(--btn-shadow, rgba(102, 126, 234, 0.2));
        color: var(--btn-color, var(--primary-color));
    }
    .action-btn i {
        width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center;
        justify-content: center; font-size: 16px; flex-shrink: 0;
        background: var(--btn-bg, var(--bg-tertiary)); color: var(--btn-color, var(--primary-color));
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .action-btn:hover i {
        background: var(--btn-color, var(--primary-color)); color: #fff;
        transform: scale(1.15) rotate(-5deg);
        box-shadow: 0 4px 15px var(--btn-shadow, rgba(102, 126, 234, 0.3));
    }
    /* Button color variants */
    .ab-blue { --btn-color: #3498db; --btn-bg: rgba(52,152,219,0.12); --btn-shadow: rgba(52,152,219,0.25); --btn-glow: rgba(52,152,219,0.08); }
    .ab-green { --btn-color: #00b894; --btn-bg: rgba(0,184,148,0.12); --btn-shadow: rgba(0,184,148,0.25); --btn-glow: rgba(0,184,148,0.08); }
    .ab-purple { --btn-color: #8b5cf6; --btn-bg: rgba(139,92,246,0.12); --btn-shadow: rgba(139,92,246,0.25); --btn-glow: rgba(139,92,246,0.08); }
    .ab-orange { --btn-color: #f39c12; --btn-bg: rgba(243,156,18,0.12); --btn-shadow: rgba(243,156,18,0.25); --btn-glow: rgba(243,156,18,0.08); }
    .ab-red { --btn-color: #ef4444; --btn-bg: rgba(239,68,68,0.12); --btn-shadow: rgba(239,68,68,0.25); --btn-glow: rgba(239,68,68,0.08); }
    .ab-teal { --btn-color: #14b8a6; --btn-bg: rgba(20,184,166,0.12); --btn-shadow: rgba(20,184,166,0.25); --btn-glow: rgba(20,184,166,0.08); }
    .ab-pink { --btn-color: #ec4899; --btn-bg: rgba(236,72,153,0.12); --btn-shadow: rgba(236,72,153,0.25); --btn-glow: rgba(236,72,153,0.08); }
    .ab-indigo { --btn-color: #667eea; --btn-bg: rgba(102,126,234,0.12); --btn-shadow: rgba(102,126,234,0.25); --btn-glow: rgba(102,126,234,0.08); }
    .ab-cyan { --btn-color: #06b6d4; --btn-bg: rgba(6,182,212,0.12); --btn-shadow: rgba(6,182,212,0.25); --btn-glow: rgba(6,182,212,0.08); }
    .ab-amber { --btn-color: #f59e0b; --btn-bg: rgba(245,158,11,0.12); --btn-shadow: rgba(245,158,11,0.25); --btn-glow: rgba(245,158,11,0.08); }
    .ab-rose { --btn-color: #f43f5e; --btn-bg: rgba(244,63,94,0.12); --btn-shadow: rgba(244,63,94,0.25); --btn-glow: rgba(244,63,94,0.08); }
    .ab-emerald { --btn-color: #10b981; --btn-bg: rgba(16,185,129,0.12); --btn-shadow: rgba(16,185,129,0.25); --btn-glow: rgba(16,185,129,0.08); }
    .ab-slate { --btn-color: #64748b; --btn-bg: rgba(100,116,139,0.12); --btn-shadow: rgba(100,116,139,0.25); --btn-glow: rgba(100,116,139,0.08); }
    .ab-violet { --btn-color: #7c3aed; --btn-bg: rgba(124,58,237,0.12); --btn-shadow: rgba(124,58,237,0.25); --btn-glow: rgba(124,58,237,0.08); }

    /* ============ FINANCIAL OVERVIEW ============ */
    .financial-grid {
        display: grid; grid-template-columns: 3fr 1fr; gap: 22px; margin-bottom: 32px;
        animation: fadeInUp 0.6s ease 0.2s both;
    }
    .card {
        background: var(--bg-secondary); border-radius: 18px; border: 1px solid var(--border-color);
        overflow: hidden; transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
    }
    .card::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 0; opacity: 0;
        background: var(--gradient-primary);
        transition: all 0.3s ease;
    }
    .card:hover {
        box-shadow: 0 12px 40px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }
    .card:hover::before { height: 3px; opacity: 1; }
    .card-header {
        padding: 18px 22px; border-bottom: 1px solid var(--border-color);
        display: flex; justify-content: space-between; align-items: center;
    }
    .card-title {
        font-size: 16px; font-weight: 700; color: var(--text-primary);
        display: flex; align-items: center; gap: 8px;
    }
    .card-title i { color: var(--primary-color); }
    .card-body { padding: 22px; }

    /* Health Metrics */
    .health-metrics { display: flex; flex-direction: column; gap: 22px; }
    .health-metric { display: flex; flex-direction: column; gap: 8px; }
    .metric-row { display: flex; justify-content: space-between; align-items: center; }
    .metric-label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
    .metric-value { font-size: 14px; font-weight: 700; color: var(--text-primary); }
    .progress-track {
        width: 100%; height: 8px; background: var(--bg-tertiary); border-radius: 8px; overflow: hidden;
        position: relative;
    }
    .progress-fill {
        height: 100%; border-radius: 8px;
        transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1);
        background: linear-gradient(135deg, #667eea, #764ba2);
        position: relative; overflow: hidden;
        animation: progressGrow 1.5s cubic-bezier(0.4, 0, 0.2, 1) 0.5s both;
    }
    .progress-fill::after {
        content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
        animation: shimmer 2s ease infinite;
        background-size: 200% 100%;
    }
    .progress-fill.green { background: linear-gradient(135deg, #00b894, #2ecc71); }
    .progress-fill.orange { background: linear-gradient(135deg, #fdcb6e, #f39c12); }
    .progress-fill.red { background: linear-gradient(135deg, #ff7675, #e74c3c); }
    .net-balance-big {
        font-size: 32px; font-weight: 800; display: flex; align-items: center; gap: 8px; margin-top: 4px;
        animation: countUp 0.8s ease 0.5s both;
    }
    .net-balance-big.positive { color: #00b894; }
    .net-balance-big.negative { color: #e74c3c; }
    .net-balance-big i { font-size: 20px; animation: float 3s ease-in-out infinite; }

    /* ============ CHARTS ============ */
    .charts-grid {
        display: grid; grid-template-columns: repeat(2, 1fr); gap: 22px; margin-bottom: 32px;
    }
    .chart-card canvas { padding: 16px; max-height: 320px; }
    .chart-type-selector { display: flex; gap: 4px; }
    .chart-type-btn {
        padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color); cursor: pointer;
        font-size: 11px; font-weight: 600; color: var(--text-secondary);
        background: var(--bg-tertiary); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative; overflow: hidden;
    }
    .chart-type-btn:hover { border-color: var(--primary-color); color: var(--primary-color); transform: translateY(-1px); }
    .chart-type-btn.active {
        background: var(--gradient-primary); color: #fff; border-color: transparent;
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    }

    /* ============ ACTIVITY TIMELINE ============ */
    .content-grid {
        display: grid; grid-template-columns: 3fr 2fr; gap: 22px; margin-bottom: 32px;
    }
    .activity-timeline { position: relative; padding-left: 40px; }
    .activity-timeline::before {
        content: ''; position: absolute; left: 15px; top: 0; bottom: 0; width: 2px;
        background: linear-gradient(to bottom, var(--gradient-1), var(--gradient-2), transparent);
    }
    .timeline-item {
        position: relative; margin-bottom: 14px; padding: 14px 18px;
        background: var(--bg-tertiary); border-radius: 12px;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        animation: fadeInLeft 0.4s ease both;
        border: 1px solid transparent;
    }
    .timeline-item:nth-child(1) { animation-delay: 0.1s; }
    .timeline-item:nth-child(2) { animation-delay: 0.15s; }
    .timeline-item:nth-child(3) { animation-delay: 0.2s; }
    .timeline-item:nth-child(4) { animation-delay: 0.25s; }
    .timeline-item:nth-child(5) { animation-delay: 0.3s; }
    .timeline-item:hover {
        transform: translateX(6px);
        background: var(--bg-secondary);
        box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        border-color: var(--border-color);
    }
    .timeline-dot {
        position: absolute; left: -32px; top: 16px; width: 24px; height: 24px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center; font-size: 10px; color: #fff; z-index: 1;
        transition: all 0.3s ease;
    }
    .timeline-item:hover .timeline-dot { transform: scale(1.2); }
    .timeline-dot.td-blue { background: linear-gradient(135deg, #3498db, #2980b9); box-shadow: 0 4px 12px rgba(52, 152, 219, 0.4); }
    .timeline-dot.td-red { background: linear-gradient(135deg, #e74c3c, #c0392b); box-shadow: 0 4px 12px rgba(231, 76, 60, 0.4); }
    .timeline-dot.td-purple { background: linear-gradient(135deg, #9b59b6, #8e44ad); box-shadow: 0 4px 12px rgba(155, 89, 182, 0.4); }
    .timeline-text { font-size: 13px; color: var(--text-primary); font-weight: 500; line-height: 1.4; }
    .timeline-meta { display: flex; align-items: center; gap: 10px; margin-top: 6px; }
    .timeline-date { font-size: 11px; color: var(--text-secondary); }
    .timeline-type {
        font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        padding: 2px 10px; border-radius: 6px; background: var(--bg-secondary); color: var(--text-secondary);
        border: 1px solid var(--border-color); transition: all 0.3s ease;
    }
    .timeline-item:hover .timeline-type { background: var(--gradient-primary); color: #fff; border-color: transparent; }
    .timeline-amount { font-size: 13px; font-weight: 700; color: var(--primary-color); }

    /* System Info */
    .info-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 12px 0; border-bottom: 1px solid var(--border-color);
        transition: all 0.3s ease;
    }
    .info-row:last-child { border-bottom: none; }
    .info-row:hover { padding-left: 8px; background: rgba(102, 126, 234, 0.03); border-radius: 8px; }
    .info-label { font-size: 13px; font-weight: 600; color: var(--text-secondary); }
    .info-value { font-size: 13px; font-weight: 600; color: var(--text-primary); text-align: right; }

    /* ============ YEAR COMPARISON ============ */
    .year-scroll {
        display: flex; gap: 18px; overflow-x: auto; padding: 8px 4px 20px;
        scroll-snap-type: x mandatory; scrollbar-width: thin;
    }
    .year-card {
        min-width: 260px; flex-shrink: 0; scroll-snap-align: start;
        background: var(--bg-secondary); border: 2px solid var(--border-color);
        border-radius: 16px; padding: 22px;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        animation: scaleIn 0.5s ease both;
        position: relative; overflow: hidden;
    }
    .year-card::before {
        content: ''; position: absolute; top: -50%; right: -50%; width: 100%; height: 100%;
        background: radial-gradient(circle, rgba(102, 126, 234, 0.05), transparent);
        transition: all 0.5s ease; opacity: 0;
    }
    .year-card:hover::before { opacity: 1; top: -20%; right: -20%; }
    .year-card:hover {
        transform: translateY(-5px) scale(1.02);
        box-shadow: 0 12px 35px rgba(0,0,0,0.1);
        border-color: var(--primary-color);
    }
    .year-card.active-year {
        border-color: var(--primary-color);
        box-shadow: 0 0 25px rgba(102, 126, 234, 0.2);
        animation: scaleIn 0.5s ease both, glow 3s ease infinite;
    }
    .year-card h4 { font-size: 17px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }
    .year-card .chip-active {
        display: inline-block; padding: 3px 12px; border-radius: 8px; font-size: 10px; font-weight: 700;
        background: linear-gradient(135deg, #00b894, #2ecc71); color: #fff; margin-bottom: 12px;
        animation: pulse 2s ease infinite;
    }
    .year-stat-row {
        display: flex; justify-content: space-between; padding: 8px 0;
        border-bottom: 1px solid var(--border-color); font-size: 13px;
    }
    .year-stat-row:last-child { border-bottom: none; }
    .year-stat-row span:first-child { color: var(--text-secondary); font-weight: 500; }
    .text-green { color: #00b894; font-weight: 700; } .text-orange { color: #f39c12; font-weight: 700; }
    .text-red { color: #e74c3c; font-weight: 700; } .text-blue { color: #3498db; font-weight: 700; }
    .year-meta { margin-top: 10px; font-size: 11px; color: var(--text-secondary); }

    /* ============ GROWTH METRICS ============ */
    .growth-grid {
        display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 32px;
    }
    .growth-card {
        background: var(--bg-secondary); border: 1px solid var(--border-color);
        border-radius: 16px; padding: 24px; text-align: center;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        animation: fadeInUp 0.5s ease both;
        position: relative; overflow: hidden;
    }
    .growth-card:nth-child(1) { animation-delay: 0.6s; }
    .growth-card:nth-child(2) { animation-delay: 0.65s; }
    .growth-card:nth-child(3) { animation-delay: 0.7s; }
    .growth-card:nth-child(4) { animation-delay: 0.75s; }
    .growth-card::before {
        content: ''; position: absolute; bottom: -50%; left: -50%; width: 200%; height: 200%;
        background: radial-gradient(circle at center, rgba(102, 126, 234, 0.04), transparent 70%);
        transition: all 0.5s ease; opacity: 0;
    }
    .growth-card:hover::before { opacity: 1; bottom: -30%; }
    .growth-card:hover {
        transform: translateY(-6px);
        box-shadow: 0 12px 35px rgba(0,0,0,0.1);
    }
    .growth-icon {
        width: 52px; height: 52px; border-radius: 16px; display: flex; align-items: center;
        justify-content: center; font-size: 22px; margin: 0 auto 14px;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .growth-card:hover .growth-icon { transform: scale(1.15) rotate(-5deg); }
    .growth-icon.gi-green { background: rgba(0, 184, 148, 0.12); color: #00b894; }
    .growth-icon.gi-blue { background: rgba(52, 152, 219, 0.12); color: #3498db; }
    .growth-icon.gi-purple { background: rgba(155, 89, 182, 0.12); color: #9b59b6; }
    .growth-icon.gi-orange { background: rgba(243, 156, 18, 0.12); color: #f39c12; }
    .growth-value {
        font-size: 30px; font-weight: 800; color: var(--text-primary); margin-bottom: 4px;
        transition: transform 0.3s ease;
    }
    .growth-card:hover .growth-value { transform: scale(1.08); }
    .growth-label {
        font-size: 12px; font-weight: 600; color: var(--text-secondary);
        text-transform: uppercase; letter-spacing: 0.5px;
    }

    /* ============ GOOGLE DRIVE BACKUP WIDGET ============ */
    .gdrive-widget {
        background: var(--card-bg, var(--bg-secondary));
        border-radius: 18px;
        border: 1px solid var(--border-color);
        box-shadow: var(--card-shadow, 0 4px 20px rgba(0,0,0,.06));
        overflow: hidden;
        margin-bottom: 28px;
        animation: fadeInUp 0.5s ease both;
    }
    .gdrive-top-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 22px;
        background: linear-gradient(135deg, rgba(66,133,244,.08), rgba(52,168,83,.06));
        border-bottom: 1px solid var(--border-color);
        flex-wrap: wrap;
        gap: 12px;
    }
    .gdrive-conn { display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 500; color: var(--text-primary); }
    .gdrive-conn-dot {
        width: 10px; height: 10px; border-radius: 50%;
        background: #9ca3af; flex-shrink: 0;
        box-shadow: 0 0 0 3px rgba(156,163,175,.2);
    }
    .gdrive-conn-dot.connected { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.2); animation: pulse 2s infinite; }
    .gdrive-conn-dot.error { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.2); }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.6} }
    .gdrive-autosync { display: flex; align-items: center; gap: 10px; }
    .gdrive-autosync-label { font-size: 13px; color: var(--text-secondary); display: flex; align-items: center; gap: 6px; }
    .gdrive-autosync-status { font-size: 12px; font-weight: 700; color: var(--text-secondary); min-width: 24px; }
    .gdrive-toggle { position: relative; width: 44px; height: 24px; display: inline-block; flex-shrink: 0; }
    .gdrive-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
    .gdrive-toggle-track {
        position: absolute; inset: 0; border-radius: 24px;
        background: var(--border-color, #d1d5db); cursor: pointer; transition: background .3s;
    }
    .gdrive-toggle-track::before {
        content: ''; position: absolute; width: 18px; height: 18px; border-radius: 50%;
        background: #fff; top: 3px; left: 3px; transition: transform .3s;
        box-shadow: 0 1px 4px rgba(0,0,0,.2);
    }
    .gdrive-toggle input:checked + .gdrive-toggle-track { background: #10b981; }
    .gdrive-toggle input:checked + .gdrive-toggle-track::before { transform: translateX(20px); }

    /* Stats row */
    .gdrive-stats-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0;
        border-bottom: 1px solid var(--border-color);
    }
    .gdrive-stat-card {
        display: flex; align-items: center; gap: 14px;
        padding: 20px 22px;
        cursor: pointer;
        transition: background .2s;
        border-right: 1px solid var(--border-color);
    }
    .gdrive-stat-card:last-child { border-right: none; }
    .gdrive-stat-card:hover { background: var(--hover-bg, rgba(0,0,0,.03)); }
    .gdrive-stat-card.active { background: rgba(66,133,244,.06); }
    .gdrive-stat-icon {
        width: 44px; height: 44px; border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 20px; flex-shrink: 0;
    }
    .gdrive-stat-local .gdrive-stat-icon  { background: rgba(245,158,11,.12); color: #d97706; }
    .gdrive-stat-synced .gdrive-stat-icon { background: rgba(16,185,129,.12); color: #059669; }
    .gdrive-stat-failed .gdrive-stat-icon { background: rgba(239,68,68,.12);  color: #dc2626; }
    .gdrive-stat-total .gdrive-stat-icon  { background: rgba(99,102,241,.12); color: #4f46e5; }
    .gdrive-stat-value { font-size: 26px; font-weight: 800; color: var(--text-primary); line-height: 1; }
    .gdrive-stat-label { font-size: 11px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .5px; margin-top: 3px; }

    /* Progress */
    .gdrive-progress-wrap {
        display: flex; align-items: center; gap: 14px; padding: 14px 22px;
        border-bottom: 1px solid var(--border-color);
    }
    .gdrive-progress-bar-bg {
        flex: 1; height: 8px; border-radius: 8px;
        background: var(--border-color, #e5e7eb); overflow: hidden;
    }
    .gdrive-progress-bar-fill {
        height: 100%; border-radius: 8px;
        background: linear-gradient(90deg, #10b981, #34d399);
        transition: width .6s ease;
    }
    .gdrive-progress-label { font-size: 12px; font-weight: 600; color: var(--text-secondary); white-space: nowrap; }

    /* Sync in progress */
    .gdrive-sync-progress {
        display: flex; align-items: center; gap: 14px;
        padding: 14px 22px;
        background: rgba(66,133,244,.06);
        border-bottom: 1px solid var(--border-color);
    }
    .gdrive-sync-spinner { font-size: 22px; color: #4285f4; }
    .gdrive-sync-text { font-size: 14px; font-weight: 600; color: var(--text-primary); }
    .gdrive-sync-sub { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }

    /* Action bar */
    .gdrive-action-bar {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 22px; border-bottom: 1px solid var(--border-color);
        flex-wrap: wrap; gap: 10px;
        background: var(--bg-tertiary, #f9fafb);
    }
    .gdrive-btn {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 9px 18px; border-radius: 9px; border: none;
        font-size: 13px; font-weight: 600; font-family: inherit;
        cursor: pointer; transition: all .2s; text-decoration: none;
        white-space: nowrap;
    }
    .gdrive-btn-primary { background: linear-gradient(135deg,#4285f4,#3b72e0); color:#fff; box-shadow:0 2px 8px rgba(66,133,244,.3); }
    .gdrive-btn-primary:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(66,133,244,.45); }
    .gdrive-btn-primary:disabled { opacity:.5; cursor:not-allowed; transform:none; }
    .gdrive-btn-secondary { background:var(--bg-tertiary,#f3f4f6); color:var(--text-primary); border:1.5px solid var(--border-color,#e5e7eb); }
    .gdrive-btn-secondary:hover { background:var(--border-color,#e5e7eb); }
    .gdrive-btn-success { background:linear-gradient(135deg,#10b981,#059669); color:#fff; }
    .gdrive-btn-danger-sm { background:rgba(239,68,68,.1); color:#dc2626; border:1px solid rgba(239,68,68,.25); padding:5px 10px; font-size:11px; border-radius:6px; }
    .gdrive-last-sync { font-size: 12px; color: var(--text-secondary); }

    /* File section */
    .gdrive-file-section { padding: 0; }
    .gdrive-file-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 22px; border-bottom: 1px solid var(--border-color);
        flex-wrap: wrap; gap: 10px;
    }
    .gdrive-file-title { font-size: 14px; font-weight: 700; color: var(--text-primary); }
    .gdrive-file-count { font-size: 12px; color: var(--text-secondary); background: var(--bg-tertiary,#f3f4f6); padding: 2px 8px; border-radius: 10px; }
    .gdrive-search-wrap {
        display: flex; align-items: center; gap: 7px;
        padding: 7px 12px; border: 1.5px solid var(--border-color,#e5e7eb);
        border-radius: 8px; background: var(--bg-secondary);
    }
    .gdrive-search-wrap i { color: var(--text-secondary); font-size: 12px; }
    .gdrive-search-wrap input { border: none; outline: none; background: transparent; font-size: 13px; color: var(--text-primary); width: 160px; font-family: inherit; }
    .gdrive-filter-select {
        padding: 7px 28px 7px 10px; border: 1.5px solid var(--border-color,#e5e7eb);
        border-radius: 8px; background: var(--bg-secondary); color: var(--text-primary);
        font-size: 13px; font-family: inherit; cursor: pointer; outline: none;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 8px center;
    }

    /* Table */
    .gdrive-table-wrap { overflow-x: auto; }
    .gdrive-table { width: 100%; border-collapse: collapse; }
    .gdrive-table th {
        padding: 11px 16px; text-align: left; font-size: 11px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .5px; color: var(--text-secondary);
        background: var(--bg-tertiary,#f9fafb); border-bottom: 2px solid var(--border-color);
        white-space: nowrap;
    }
    .gdrive-table td { padding: 12px 16px; border-bottom: 1px solid var(--border-light,#f3f4f6); font-size: 13px; vertical-align: middle; }
    .gdrive-table tr:last-child td { border-bottom: none; }
    .gdrive-table tbody tr:hover td { background: var(--hover-bg,rgba(0,0,0,.02)); }
    .gdrive-loading-row { text-align: center; padding: 40px !important; color: var(--text-secondary); }
    .gdrive-empty-row { text-align: center; padding: 36px !important; color: var(--text-secondary); }

    /* File icon */
    .gdrive-file-thumb {
        width: 32px; height: 32px; border-radius: 7px; object-fit: cover;
        border: 1px solid var(--border-color);
    }
    .gdrive-file-icon {
        width: 32px; height: 32px; border-radius: 7px;
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 15px; flex-shrink: 0;
    }
    .gdrive-icon-img  { background: rgba(59,130,246,.1);  color: #3b82f6; }
    .gdrive-icon-pdf  { background: rgba(239,68,68,.1);   color: #ef4444; }
    .gdrive-icon-doc  { background: rgba(37,99,235,.1);   color: #2563eb; }
    .gdrive-icon-xls  { background: rgba(16,185,129,.1);  color: #059669; }
    .gdrive-icon-file { background: rgba(107,114,128,.1); color: #6b7280; }

    /* Status badges */
    .gdrive-badge {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600;
        white-space: nowrap;
    }
    .gdrive-badge-synced { background: rgba(16,185,129,.12); color: #059669; }
    .gdrive-badge-local  { background: rgba(245,158,11,.12);  color: #d97706; }
    .gdrive-badge-failed { background: rgba(239,68,68,.12);   color: #dc2626; }

    /* Entity type badge */
    .gdrive-type-badge {
        padding: 2px 8px; border-radius: 12px; font-size: 10px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .5px;
    }
    .gdrive-type-expense { background: rgba(139,92,246,.12); color: #7c3aed; }
    .gdrive-type-event   { background: rgba(14,165,233,.12);  color: #0284c7; }

    /* Pagination */
    .gdrive-pagination {
        display: flex; align-items: center; justify-content: center;
        gap: 6px; padding: 14px 22px;
        border-top: 1px solid var(--border-color);
    }
    .gdrive-page-btn {
        min-width: 34px; height: 34px; padding: 0 10px;
        border-radius: 8px; border: 1.5px solid var(--border-color);
        background: var(--bg-secondary); color: var(--text-primary);
        font-size: 13px; font-weight: 600; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        transition: all .2s; font-family: inherit;
    }
    .gdrive-page-btn:hover { background: var(--border-color); }
    .gdrive-page-btn.active { background: #4285f4; color: #fff; border-color: #4285f4; }
    .gdrive-page-btn:disabled { opacity: .4; cursor: not-allowed; }

    @media (max-width: 768px) {
        .gdrive-stats-row { grid-template-columns: repeat(2,1fr); }
        .gdrive-stat-card { border-right: none; border-bottom: 1px solid var(--border-color); }
    }

    /* ============ TOAST ============ */
    .toast {
        position: fixed; bottom: 30px; right: 30px; padding: 16px 24px; border-radius: 14px;
        color: #fff; z-index: 9999; animation: slideInRight 0.4s cubic-bezier(0.22, 1, 0.36, 1);
        max-width: 350px; display: none;
        box-shadow: 0 12px 40px rgba(0,0,0,0.25);
        backdrop-filter: blur(10px);
    }
    .toast.success { background: linear-gradient(135deg, #00b894, #2ecc71); }
    .toast.info { background: linear-gradient(135deg, #3498db, #2980b9); }
    .toast.warning { background: linear-gradient(135deg, #f39c12, #e67e22); }
    .toast.error { background: linear-gradient(135deg, #e74c3c, #c0392b); }
    .toast-title { font-weight: 700; font-size: 14px; }
    .toast-message { font-size: 13px; margin-top: 4px; opacity: 0.9; }

    /* Shortcuts Help */
    .shortcuts-overlay {
        position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000;
        display: none; backdrop-filter: blur(6px);
        animation: fadeIn 0.3s ease;
    }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    .shortcuts-panel {
        position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
        background: var(--bg-secondary); border-radius: 20px; padding: 28px;
        z-index: 10001; min-width: 400px; display: none;
        box-shadow: 0 25px 80px rgba(0,0,0,0.3); animation: fadeInScale 0.3s ease;
        border: 1px solid var(--border-color);
    }
    .shortcuts-title {
        font-size: 18px; font-weight: 700; color: var(--text-primary); margin-bottom: 18px;
        display: flex; justify-content: space-between; align-items: center;
    }
    .shortcut-item {
        display: flex; justify-content: space-between; padding: 10px 0;
        border-bottom: 1px solid var(--border-color);
        transition: all 0.2s ease;
    }
    .shortcut-item:last-child { border-bottom: none; }
    .shortcut-item:hover { padding-left: 6px; }
    .shortcut-key {
        padding: 4px 12px; background: var(--bg-tertiary); border-radius: 8px;
        font-family: 'SF Mono', Monaco, monospace; font-weight: 700; font-size: 13px;
        color: var(--text-primary); border: 1px solid var(--border-color);
        box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        transition: all 0.2s ease;
    }
    .shortcut-item:hover .shortcut-key {
        background: var(--gradient-primary); color: #fff; border-color: transparent;
    }
    .shortcut-desc { font-size: 13px; color: var(--text-secondary); }

    /* ============ RESPONSIVE ============ */
    @media (max-width: 1200px) {
        .hero-stats { grid-template-columns: repeat(2, 1fr); }
        .financial-grid { grid-template-columns: 1fr; }
        .charts-grid { grid-template-columns: 1fr; }
        .content-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 768px) {
        .hero-stats { grid-template-columns: 1fr; }
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .growth-grid { grid-template-columns: repeat(2, 1fr); }
        .page-header { flex-direction: column; align-items: flex-start; }
        .hero-value { font-size: 26px; }
        .container { padding: 16px; }
        .shortcuts-panel { min-width: 300px; width: 90%; }
    }
    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: 1fr; }
        .growth-grid { grid-template-columns: 1fr; }
    }
    @media print {
        .hero-card { box-shadow: none !important; animation: none !important; }
        .quick-actions-grouped, .year-scroll, .toast, .shortcuts-overlay, .shortcuts-panel,
        .chatbot-toggle, .chatbot-window, .refresh-btn, .navbar { display: none !important; }
        * { animation: none !important; transition: none !important; }
    }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-shield-alt"></i>
                <span>Super Admin Control Panel</span>
            </div>
            <div class="navbar-user">
                <button class="theme-toggle" onclick="window.ThemeManager && window.ThemeManager.toggleTheme()">
                    <i class="fas fa-moon" id="themeIcon"></i>
                    <span id="themeText">Dark</span>
                </button>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_name); ?></div>
                    <div class="user-role">Super Administrator</div>
                </div>
                <div class="navbar-datetime" id="dash-navbar-datetime">
                    <span class="datetime-date" id="dash-navbar-date"></span>
                    <span class="datetime-time" id="dash-navbar-time"></span>
                </div>
                <a href="logout.php" class="btn-logout" onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="container">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1><i class="fas fa-tachometer-alt"></i> Dashboard</h1>
            <span class="header-date"><?php echo date('l, F j, Y'); ?> <span id="dashboard-time"></span></span>
        </div>
        <div class="header-right">
            <?php if (($notification_stats['unread'] ?? 0) > 0): ?>
            <span class="notif-badge"><i class="fas fa-bell"></i> <?php echo $notification_stats['unread']; ?> Unread</span>
            <?php endif; ?>
            <select class="year-filter" onchange="window.location.href='?year='+this.value">
                <option value="all" <?php echo $selectedYear === 'all' ? 'selected' : ''; ?>>All Years</option>
                <?php foreach ($academicYears as $yr): ?>
                <option value="<?php echo $yr['YearID']; ?>" <?php echo $selectedYear == $yr['YearID'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($yr['YearName']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button class="refresh-btn" onclick="this.classList.add('spinning');location.reload();">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
    </div>

    <!-- SECTION 1: Hero Financial KPIs -->
    <div class="hero-stats">
        <div class="hero-card hc-collection">
            <div class="hero-card-content">
                <div class="hero-label">Total Collection</div>
                <div class="hero-value" data-count="<?php echo $total_collection; ?>" data-prefix="Rs ">Rs <?php echo number_format($total_collection, 2); ?></div>
                <div class="hero-subtitle"><i class="fas fa-calendar-day"></i> This Month: Rs <?php echo number_format($month_collection, 2); ?></div>
            </div>
            <div class="hero-icon"><i class="fas fa-hand-holding-usd"></i></div>
        </div>
        <div class="hero-card hc-distribution">
            <div class="hero-card-content">
                <div class="hero-label">Total Distribution</div>
                <div class="hero-value" data-count="<?php echo $total_distribution; ?>" data-prefix="Rs ">Rs <?php echo number_format($total_distribution, 2); ?></div>
                <div class="hero-subtitle"><i class="fas fa-chart-line"></i> <?php echo number_format($distribution_rate, 1); ?>% of Collection</div>
            </div>
            <div class="hero-icon"><i class="fas fa-donate"></i></div>
        </div>
        <div class="hero-card hc-expenses">
            <div class="hero-card-content">
                <div class="hero-label">Total Expenses</div>
                <div class="hero-value" data-count="<?php echo $total_expenses; ?>" data-prefix="Rs ">Rs <?php echo number_format($total_expenses, 2); ?></div>
                <div class="hero-subtitle"><i class="fas fa-calendar-day"></i> This Month: Rs <?php echo number_format($month_expenses, 2); ?></div>
            </div>
            <div class="hero-icon"><i class="fas fa-receipt"></i></div>
        </div>
        <div class="hero-card <?php echo $net_balance >= 0 ? 'hc-balance-pos' : 'hc-balance-neg'; ?>">
            <div class="hero-card-content">
                <div class="hero-label">Net Balance</div>
                <div class="hero-value">Rs <?php echo number_format(abs($net_balance), 2); ?></div>
                <div class="hero-subtitle"><i class="fas fa-arrow-<?php echo $net_balance >= 0 ? 'up' : 'down'; ?>"></i> Interest + Fixed - Expenses</div>
            </div>
            <div class="hero-icon"><i class="fas fa-wallet"></i></div>
        </div>
    </div>

    <!-- SECTION 2: Secondary Stat Cards -->
    <div class="stats-grid">
        <div class="stat-card sc-blue"><div class="stat-card-inner"><div class="stat-card-info"><div class="stat-card-title">Total Admins</div><div class="stat-card-value"><?php echo $total_admins; ?></div><div class="stat-card-sub"><?php echo $active_admins; ?> Active</div></div><div class="stat-card-icon"><i class="fas fa-user-cog"></i></div></div></div>
        <div class="stat-card sc-green"><div class="stat-card-inner"><div class="stat-card-info"><div class="stat-card-title">Total Members</div><div class="stat-card-value"><?php echo $total_members; ?></div><div class="stat-card-sub"><?php echo $active_members; ?> Active</div></div><div class="stat-card-icon"><i class="fas fa-users"></i></div></div></div>
        <div class="stat-card sc-orange"><div class="stat-card-inner"><div class="stat-card-info"><div class="stat-card-title">Total Transactions</div><div class="stat-card-value"><?php echo number_format($total_transactions); ?></div><div class="stat-card-sub"><?php echo $today_transactions; ?> Today</div></div><div class="stat-card-icon"><i class="fas fa-exchange-alt"></i></div></div></div>
        <div class="stat-card sc-indigo"><div class="stat-card-inner"><div class="stat-card-info"><div class="stat-card-title">Total Interest</div><div class="stat-card-value">Rs <?php echo number_format($total_interest, 0); ?></div><div class="stat-card-sub">All Time</div></div><div class="stat-card-icon"><i class="fas fa-percentage"></i></div></div></div>
        <div class="stat-card sc-teal"><div class="stat-card-inner"><div class="stat-card-info"><div class="stat-card-title">Total Events</div><div class="stat-card-value"><?php echo $event_summary['total_events'] ?? 0; ?></div><div class="stat-card-sub"><?php echo $event_summary['ongoing_count'] ?? 0; ?> Ongoing</div></div><div class="stat-card-icon"><i class="fas fa-calendar-check"></i></div></div></div>
        <div class="stat-card sc-pink"><div class="stat-card-inner"><div class="stat-card-info"><div class="stat-card-title">Notifications</div><div class="stat-card-value"><?php echo $notification_stats['unread'] ?? 0; ?></div><div class="stat-card-sub"><?php echo $notification_stats['total'] ?? 0; ?> Total</div></div><div class="stat-card-icon"><i class="fas fa-bell"></i></div></div></div>
        <div class="stat-card sc-yellow"><div class="stat-card-inner"><div class="stat-card-info"><div class="stat-card-title">Audit Logs</div><div class="stat-card-value"><?php echo number_format($total_logs); ?></div><div class="stat-card-sub">Total Records</div></div><div class="stat-card-icon"><i class="fas fa-history"></i></div></div></div>
        <div class="stat-card sc-gray"><div class="stat-card-inner"><div class="stat-card-info"><div class="stat-card-title">Database Size</div><div class="stat-card-value"><?php echo $db_size; ?> MB</div><div class="stat-card-sub">Current Size</div></div><div class="stat-card-icon"><i class="fas fa-database"></i></div></div></div>
    </div>

    <!-- SECTION 3: Quick Actions -->
    <div class="section-header collapsible" onclick="toggleSection('quickActions')">
        <h2 class="section-title"><i class="fas fa-bolt"></i> Quick Actions</h2>
        <i class="fas fa-chevron-down section-toggle" id="quickActionsToggle"></i>
    </div>
    <div class="quick-actions-grouped" id="quickActions">
        <div class="action-group">
            <h3 class="action-group-title"><i class="fas fa-users-cog"></i> People Management</h3>
            <div class="action-group-grid">
                <a href="manage-admins.php" class="action-btn ab-blue" <?php echo getLinkTarget('manage-admins', 'superadmin'); ?>><i class="fas fa-user-cog"></i> Manage Admins</a>
                <a href="manage-members.php" class="action-btn ab-green" <?php echo getLinkTarget('manage-members', 'superadmin'); ?>><i class="fas fa-users"></i> Manage Members</a>
                <a href="permissions-management.php" class="action-btn ab-purple"><i class="fas fa-shield-alt"></i> Permissions</a>
            </div>
        </div>
        <div class="action-group">
            <h3 class="action-group-title"><i class="fas fa-rupee-sign"></i> Financial</h3>
            <div class="action-group-grid">
                <a href="manage-transactions.php" class="action-btn ab-emerald" <?php echo getLinkTarget('manage-transactions', 'superadmin'); ?>><i class="fas fa-exchange-alt"></i> Manage Transactions</a>
                <a href="manage-academic-years.php" class="action-btn ab-orange" <?php echo getLinkTarget('manage-academic-years', 'superadmin'); ?>><i class="fas fa-calendar-alt"></i> Academic Years</a>
                <a href="net-balance-manager.php" class="action-btn ab-teal" <?php echo getLinkTarget('net-balance-manager', 'superadmin'); ?>><i class="fas fa-balance-scale"></i> Net Balance</a>
                <a href="reports.php" class="action-btn ab-indigo" <?php echo getLinkTarget('reports', 'superadmin'); ?>><i class="fas fa-chart-line"></i> Reports</a>
                <a href="report-builder.php" class="action-btn ab-violet" <?php echo getLinkTarget('report-builder', 'superadmin'); ?>><i class="fas fa-file-signature"></i> Report Builder</a>
                <a href="change-amount.php" class="action-btn ab-purple"><i class="fas fa-coins"></i> Change Amount</a>
                <a href="bills.php" class="action-btn ab-rose"><i class="fas fa-file-invoice-dollar"></i> Bill Generator</a>
                <a href="accounting/dashboard.php" class="action-btn ab-violet" <?php echo getLinkTarget('accounting-dashboard', 'superadmin'); ?>><i class="fas fa-calculator"></i> Accounting Portal</a>
            </div>
        </div>
        <div class="action-group">
            <h3 class="action-group-title"><i class="fas fa-tools"></i> System & Tools</h3>
            <div class="action-group-grid">
                <a href="settings.php" class="action-btn ab-slate" <?php echo getLinkTarget('settings', 'superadmin'); ?>><i class="fas fa-cogs"></i> Settings</a>
                <a href="database-backup.php" class="action-btn ab-cyan" <?php echo getLinkTarget('database-backup', 'superadmin'); ?>><i class="fas fa-cloud-download-alt"></i> Database Backup</a>
                <a href="audit-logs.php" class="action-btn ab-amber" <?php echo getLinkTarget('audit-logs', 'superadmin'); ?>><i class="fas fa-history"></i> Audit Logs</a>
                <a href="dbadmin/dbadmin.php" class="action-btn ab-blue"><i class="fas fa-database"></i> DB Admin</a>
                <a href="live-monitor.php" class="action-btn ab-red"><i class="fas fa-heart-pulse"></i> Live Monitor</a>
                <a href="data-import-export.php" class="action-btn ab-green" <?php echo getLinkTarget('data-import-export', 'superadmin'); ?>><i class="fas fa-file-excel"></i> Import/Export</a>
                <a href="manage-templates.php" class="action-btn ab-pink" <?php echo getLinkTarget('manage-templates', 'superadmin'); ?>><i class="fas fa-file-invoice"></i> Templates</a>
                <a href="link-behavior-settings.php" class="action-btn ab-orange" <?php echo getLinkTarget('link-behavior-settings', 'superadmin'); ?>><i class="fas fa-external-link-alt"></i> Link Behavior</a>
                <a href="online-excel-editor.php" class="action-btn ab-teal" <?php echo getLinkTarget('online-excel-editor', 'superadmin'); ?>><i class="fas fa-table"></i> Excel Editor</a>
            </div>
        </div>
        <div class="action-group">
            <h3 class="action-group-title"><i class="fas fa-lock"></i> Security</h3>
            <div class="action-group-grid">
                <a href="two-factor-auth.php" class="action-btn ab-rose"><i class="fas fa-mobile-alt"></i> Two-Factor Auth</a>
                <a href="face-auth.php" class="action-btn ab-violet"><i class="fas fa-face-smile"></i> Face Lock</a>
                <a href="google-drive-settings.php" class="action-btn ab-blue" <?php echo getLinkTarget('google-drive-settings', 'superadmin'); ?>><i class="fab fa-google-drive"></i> Google Drive</a>
            </div>
        </div>
    </div>

    <!-- SECTION 4: Financial Overview -->
    <div class="section-header">
        <h2 class="section-title"><i class="fas fa-chart-area"></i> Financial Overview</h2>
    </div>
    <div class="financial-grid">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-line"></i> Collection vs Expenses (12 Months)</h3>
            </div>
            <canvas id="financialOverviewChart" height="80"></canvas>
        </div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-heartbeat"></i> Financial Health</h3>
            </div>
            <div class="card-body">
                <div class="health-metrics">
                    <div class="health-metric">
                        <div class="metric-row"><span class="metric-label">Distribution Rate</span><span class="metric-value"><?php echo number_format($distribution_rate, 1); ?>%</span></div>
                        <div class="progress-track"><div class="progress-fill orange" style="width: <?php echo min(100, $distribution_rate); ?>%"></div></div>
                    </div>
                    <div class="health-metric">
                        <div class="metric-row"><span class="metric-label">Expense Ratio</span><span class="metric-value"><?php echo number_format($expense_ratio, 1); ?>%</span></div>
                        <div class="progress-track"><div class="progress-fill red" style="width: <?php echo min(100, $expense_ratio); ?>%"></div></div>
                    </div>
                    <div class="health-metric">
                        <div class="metric-row"><span class="metric-label">This Month Collection</span><span class="metric-value">Rs <?php echo number_format($month_collection, 0); ?></span></div>
                        <div class="progress-track"><div class="progress-fill green" style="width: <?php echo $total_collection > 0 ? min(100, ($month_collection / $total_collection) * 100 * 12) : 0; ?>%"></div></div>
                    </div>
                    <div class="health-metric">
                        <div class="metric-label">Net Balance</div>
                        <div class="net-balance-big <?php echo $net_balance >= 0 ? 'positive' : 'negative'; ?>">
                            <i class="fas fa-arrow-<?php echo $net_balance >= 0 ? 'up' : 'down'; ?>"></i>
                            Rs <?php echo number_format(abs($net_balance), 2); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 5: Analytics Charts -->
    <div class="section-header">
        <h2 class="section-title"><i class="fas fa-chart-bar"></i> Analytics & Insights</h2>
    </div>
    <div class="charts-grid">
        <div class="card chart-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-line"></i> Monthly Trend</h3>
                <div class="chart-type-selector">
                    <button class="chart-type-btn active" data-chart="trendChart" data-type="line">Line</button>
                    <button class="chart-type-btn" data-chart="trendChart" data-type="bar">Bar</button>
                </div>
            </div>
            <canvas id="trendChart"></canvas>
        </div>
        <div class="card chart-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-money-check-alt"></i> Payment Modes</h3>
            </div>
            <canvas id="paymentModeChart"></canvas>
        </div>
        <div class="card chart-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-trophy"></i> Top 5 Members</h3>
            </div>
            <canvas id="topMembersChart"></canvas>
        </div>
        <div class="card chart-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-receipt"></i> Expense Categories</h3>
            </div>
            <canvas id="expenseCategoryChart"></canvas>
        </div>
        <div class="card chart-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chart-pie"></i> Transaction Types</h3>
            </div>
            <canvas id="typeChart"></canvas>
        </div>
        <div class="card chart-card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-clock"></i> Activity by Hour</h3>
            </div>
            <canvas id="activityChart"></canvas>
        </div>
    </div>

    <!-- SECTION 6: Activity Feed + System Health -->
    <div class="section-header">
        <h2 class="section-title"><i class="fas fa-stream"></i> Activity & System</h2>
    </div>
    <div class="content-grid">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-stream"></i> Activity Timeline</h3>
                <a href="audit-logs.php" style="color: var(--primary-color); text-decoration: none; font-size: 13px; font-weight: 600;">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="card-body">
                <?php if (empty($combined_feed)): ?>
                    <div style="text-align: center; padding: 30px; color: var(--text-secondary);">
                        <i class="fas fa-inbox" style="font-size: 32px; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                        No recent activity
                    </div>
                <?php else: ?>
                <div class="activity-timeline">
                    <?php foreach ($combined_feed as $item):
                        $dotClass = $item['feed_type'] === 'transaction' ? 'td-blue' : ($item['feed_type'] === 'expense' ? 'td-red' : 'td-purple');
                        $icon = $item['feed_type'] === 'transaction' ? 'fas fa-exchange-alt' : ($item['feed_type'] === 'expense' ? 'fas fa-receipt' : 'fas fa-clipboard-check');
                    ?>
                    <div class="timeline-item">
                        <div class="timeline-dot <?php echo $dotClass; ?>"><i class="<?php echo $icon; ?>"></i></div>
                        <div class="timeline-text"><?php echo htmlspecialchars($item['feed_text']); ?></div>
                        <div class="timeline-meta">
                            <span class="timeline-type"><?php echo ucfirst($item['feed_type']); ?></span>
                            <span class="timeline-date"><?php echo date('M d, H:i', strtotime($item['feed_date'])); ?></span>
                            <?php if ($item['feed_amount'] > 0): ?>
                                <span class="timeline-amount">Rs <?php echo number_format($item['feed_amount'], 0); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle"></i> System Information</h3>
            </div>
            <div class="card-body">
                <div class="system-info">
                    <div class="info-row"><span class="info-label">Site Name</span><span class="info-value"><?php echo htmlspecialchars($settings['site_name'] ?? 'SR Colony'); ?></span></div>
                    <div class="info-row"><span class="info-label">Interest Rate</span><span class="info-value"><?php echo htmlspecialchars($settings['interest_rate'] ?? '24'); ?>%</span></div>
                    <div class="info-row"><span class="info-label">Auto Backup</span><span class="info-value"><span class="badge badge-<?php echo ($settings['enable_auto_backup'] ?? 'No') === 'Yes' ? 'success' : 'danger'; ?>"><?php echo $settings['enable_auto_backup'] ?? 'No'; ?></span></span></div>
                    <div class="info-row"><span class="info-label">Email Notifications</span><span class="info-value"><span class="badge badge-<?php echo ($settings['enable_email_notifications'] ?? 'No') === 'Yes' ? 'success' : 'danger'; ?>"><?php echo $settings['enable_email_notifications'] ?? 'No'; ?></span></span></div>
                    <div class="info-row"><span class="info-label">Two-Factor Auth</span><span class="info-value"><span class="badge badge-<?php echo $twofa_status ? 'success' : 'danger'; ?>"><?php echo $twofa_status ? 'Enabled' : 'Disabled'; ?></span></span></div>
                    <div class="info-row"><span class="info-label">Face Lock</span><span class="info-value"><span class="badge badge-<?php echo $face_status ? 'success' : 'danger'; ?>"><?php echo $face_status ? 'Enabled' : 'Disabled'; ?></span></span></div>
                    <div class="info-row"><span class="info-label">System Status</span><span class="info-value"><span class="badge badge-success"><i class="fas fa-check-circle"></i> Active</span></span></div>
                    <div class="info-row"><span class="info-label">PHP Version</span><span class="info-value"><?php echo phpversion(); ?></span></div>
                    <div class="info-row"><span class="info-label">Server Time</span><span class="info-value"><?php echo date('Y-m-d H:i:s'); ?></span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- SECTION 7: Academic Year Comparison -->
    <?php if (!empty($year_summaries)): ?>
    <div class="section-header collapsible" onclick="toggleSection('yearComparison')">
        <h2 class="section-title"><i class="fas fa-calendar-alt"></i> Academic Year Comparison</h2>
        <i class="fas fa-chevron-down section-toggle" id="yearComparisonToggle"></i>
    </div>
    <div id="yearComparison">
        <div class="year-scroll">
            <?php foreach ($year_summaries as $idx => $yr):
                $yrNet = ($yr['total_interest'] + $yr['FixedAmount'] + $yr['ChangeAmount']) - $yr['total_expenses'];
            ?>
            <div class="year-card <?php echo ($yr['Status'] ?? '') === 'Active' ? 'active-year' : ''; ?>" style="animation-delay: <?php echo 0.1 * $idx; ?>s">
                <h4><?php echo htmlspecialchars($yr['YearName']); ?></h4>
                <?php if (($yr['Status'] ?? '') === 'Active'): ?>
                    <span class="chip-active">Active</span>
                <?php endif; ?>
                <div>
                    <div class="year-stat-row"><span>Collection</span><span class="text-green">Rs <?php echo number_format($yr['total_collection'], 0); ?></span></div>
                    <div class="year-stat-row"><span>Distribution</span><span class="text-orange">Rs <?php echo number_format($yr['total_distribution'], 0); ?></span></div>
                    <div class="year-stat-row"><span>Interest</span><span class="text-blue">Rs <?php echo number_format($yr['total_interest'], 0); ?></span></div>
                    <div class="year-stat-row"><span>Expenses</span><span class="text-red">Rs <?php echo number_format($yr['total_expenses'], 0); ?></span></div>
                    <div class="year-stat-row"><span>Net Balance</span><span class="<?php echo $yrNet >= 0 ? 'text-green' : 'text-red'; ?>">Rs <?php echo number_format($yrNet, 0); ?></span></div>
                </div>
                <div class="year-meta">Interest: <?php echo $yr['InterestRate'] ?? 'N/A'; ?>% | Members: <?php echo $yr['member_count'] ?? 0; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- SECTION 8: Growth Metrics -->
    <div class="section-header">
        <h2 class="section-title"><i class="fas fa-seedling"></i> Growth Metrics</h2>
    </div>
    <div class="growth-grid">
        <div class="growth-card">
            <div class="growth-icon gi-green"><i class="fas fa-user-plus"></i></div>
            <div class="growth-value"><?php echo $growth_stats['today_members'] ?? 0; ?></div>
            <div class="growth-label">New Members Today</div>
        </div>
        <div class="growth-card">
            <div class="growth-icon gi-blue"><i class="fas fa-user-friends"></i></div>
            <div class="growth-value"><?php echo $growth_stats['week_members'] ?? 0; ?></div>
            <div class="growth-label">New This Week</div>
        </div>
        <div class="growth-card">
            <div class="growth-icon gi-purple"><i class="fas fa-users"></i></div>
            <div class="growth-value"><?php echo $growth_stats['month_members'] ?? 0; ?></div>
            <div class="growth-label">New This Month</div>
        </div>
        <div class="growth-card">
            <div class="growth-icon gi-orange"><i class="fas fa-percentage"></i></div>
            <div class="growth-value"><?php echo number_format($distribution_rate, 1); ?>%</div>
            <div class="growth-label">Distribution Rate</div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════
         SECTION 9: Google Drive Backup & Sync
         ═══════════════════════════════════════════════════════ -->
    <div class="section-header">
        <h2 class="section-title"><i class="fab fa-google-drive"></i> Google Drive Backup</h2>
    </div>

    <div class="gdrive-widget" id="gdrive-widget">

        <!-- Connection + Auto-sync bar -->
        <div class="gdrive-top-bar">
            <div class="gdrive-conn" id="gdrive-conn-info">
                <div class="gdrive-conn-dot" id="gdrive-dot"></div>
                <span id="gdrive-conn-text">Checking connection…</span>
            </div>
            <div class="gdrive-autosync">
                <span class="gdrive-autosync-label"><i class="fas fa-sync-alt"></i> Auto-Sync on Upload</span>
                <label class="gdrive-toggle" title="Auto-upload files to Drive when they are uploaded">
                    <input type="checkbox" id="gdrive-auto-toggle" onchange="gdriveToggleAuto(this.checked)">
                    <span class="gdrive-toggle-track"></span>
                </label>
                <span class="gdrive-autosync-status" id="gdrive-auto-label">Off</span>
            </div>
        </div>

        <!-- Stats cards row -->
        <div class="gdrive-stats-row">
            <div class="gdrive-stat-card gdrive-stat-local" onclick="gdriveFilterFiles('local')">
                <div class="gdrive-stat-icon"><i class="fas fa-hdd"></i></div>
                <div>
                    <div class="gdrive-stat-value" id="gd-local-count">—</div>
                    <div class="gdrive-stat-label">Local Only</div>
                </div>
            </div>
            <div class="gdrive-stat-card gdrive-stat-synced" onclick="gdriveFilterFiles('synced')">
                <div class="gdrive-stat-icon"><i class="fab fa-google-drive"></i></div>
                <div>
                    <div class="gdrive-stat-value" id="gd-synced-count">—</div>
                    <div class="gdrive-stat-label">In Drive</div>
                </div>
            </div>
            <div class="gdrive-stat-card gdrive-stat-failed" onclick="gdriveFilterFiles('failed')">
                <div class="gdrive-stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div>
                    <div class="gdrive-stat-value" id="gd-failed-count">—</div>
                    <div class="gdrive-stat-label">Failed</div>
                </div>
            </div>
            <div class="gdrive-stat-card gdrive-stat-total" onclick="gdriveFilterFiles('all')">
                <div class="gdrive-stat-icon"><i class="fas fa-folder-open"></i></div>
                <div>
                    <div class="gdrive-stat-value" id="gd-total-count">—</div>
                    <div class="gdrive-stat-label">Total Files</div>
                </div>
            </div>
        </div>

        <!-- Progress bar -->
        <div class="gdrive-progress-wrap" id="gdrive-progress-wrap">
            <div class="gdrive-progress-bar-bg">
                <div class="gdrive-progress-bar-fill" id="gdrive-prog-fill" style="width:0%"></div>
            </div>
            <span class="gdrive-progress-label" id="gdrive-prog-label">0 / 0 files backed up</span>
        </div>

        <!-- Sync progress (shown during active sync) -->
        <div class="gdrive-sync-progress" id="gdrive-sync-progress" style="display:none;">
            <div class="gdrive-sync-spinner"><i class="fas fa-circle-notch fa-spin"></i></div>
            <div>
                <div class="gdrive-sync-text" id="gdrive-sync-text">Syncing files…</div>
                <div class="gdrive-sync-sub" id="gdrive-sync-sub"></div>
            </div>
        </div>

        <!-- Action bar -->
        <div class="gdrive-action-bar">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <button class="gdrive-btn gdrive-btn-primary" onclick="gdriveSyncAll()" id="gdrive-sync-all-btn">
                    <i class="fas fa-cloud-upload-alt"></i> Sync All to Drive
                </button>
                <button class="gdrive-btn gdrive-btn-secondary" onclick="gdriveRefresh()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
                <a href="google-drive-settings.php" class="gdrive-btn gdrive-btn-secondary">
                    <i class="fas fa-cog"></i> Drive Settings
                </a>
            </div>
            <div class="gdrive-last-sync" id="gdrive-last-sync"></div>
        </div>

        <!-- File list -->
        <div class="gdrive-file-section">
            <div class="gdrive-file-header">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <span class="gdrive-file-title" id="gdrive-file-title">All Files</span>
                    <span class="gdrive-file-count" id="gdrive-file-count"></span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <div class="gdrive-search-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" id="gdrive-search" placeholder="Search files…" oninput="gdriveSearch(this.value)">
                    </div>
                    <select class="gdrive-filter-select" id="gdrive-filter-sel" onchange="gdriveFilterFiles(this.value)">
                        <option value="all">All Files</option>
                        <option value="local">Local Only</option>
                        <option value="synced">In Drive</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
            </div>

            <div class="gdrive-table-wrap">
                <table class="gdrive-table" id="gdrive-table">
                    <thead>
                        <tr>
                            <th style="width:36px;"></th>
                            <th>File Name</th>
                            <th>Type</th>
                            <th>Size</th>
                            <th>Status</th>
                            <th>Uploaded</th>
                            <th style="width:120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="gdrive-tbody">
                        <tr><td colspan="7" class="gdrive-loading-row">
                            <i class="fas fa-circle-notch fa-spin"></i> Loading files…
                        </td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="gdrive-pagination" id="gdrive-pagination"></div>
        </div>

    </div><!-- /.gdrive-widget -->

    </div><!-- /.container -->

    <!-- Toast -->
    <div class="toast" id="toast"><div class="toast-title" id="toastTitle"></div><div class="toast-message" id="toastMsg"></div></div>

    <!-- Shortcuts -->
    <div class="shortcuts-overlay" id="shortcutsOverlay" onclick="hideShortcuts()"></div>
    <div class="shortcuts-panel" id="shortcutsPanel">
        <div class="shortcuts-title">
            Keyboard Shortcuts
            <button onclick="hideShortcuts()" style="background:none;border:none;cursor:pointer;color:var(--text-secondary);font-size:18px;"><i class="fas fa-times"></i></button>
        </div>
        <div class="shortcut-item"><span class="shortcut-desc">Show shortcuts</span><span class="shortcut-key">?</span></div>
        <div class="shortcut-item"><span class="shortcut-desc">Toggle dark mode</span><span class="shortcut-key">D</span></div>
        <div class="shortcut-item"><span class="shortcut-desc">Refresh dashboard</span><span class="shortcut-key">R</span></div>
        <div class="shortcut-item"><span class="shortcut-desc">Manage Admins</span><span class="shortcut-key">A</span></div>
        <div class="shortcut-item"><span class="shortcut-desc">Manage Members</span><span class="shortcut-key">M</span></div>
        <div class="shortcut-item"><span class="shortcut-desc">Manage Transactions</span><span class="shortcut-key">T</span></div>
        <div class="shortcut-item"><span class="shortcut-desc">Reports</span><span class="shortcut-key">P</span></div>
        <div class="shortcut-item"><span class="shortcut-desc">Accounting Portal</span><span class="shortcut-key">E</span></div>
        <div class="shortcut-item"><span class="shortcut-desc">Settings</span><span class="shortcut-key">S</span></div>
        <div class="shortcut-item"><span class="shortcut-desc">Audit Logs</span><span class="shortcut-key">L</span></div>
    </div>

    <script src="theme-manager.js"></script>
    <script>
    // ===== Theme Integration =====
    const getChartColors = () => window.ThemeManager ? window.ThemeManager.getChartColors() : {text:'#2d3748',grid:'#e2e8f0',bg:'rgba(102,126,234,0.1)',cardBg:'#ffffff'};
    if (window.ThemeManager) {
        document.addEventListener('themeChanged', () => updateCharts());
    }

    // ===== Chart Data from PHP =====
    const monthlyTrend = <?php echo json_encode($monthly_trend); ?>;
    const transactionTypes = <?php echo json_encode($transaction_types); ?>;
    const topMembersData = <?php echo json_encode($top_members); ?>;
    const activityByHour = <?php echo json_encode($activity_by_hour); ?>;
    const paymentModesData = <?php echo json_encode($payment_modes); ?>;
    const expenseCatData = <?php echo json_encode($expense_by_category); ?>;
    const expenseTrendData = <?php echo json_encode($expense_trend); ?>;

    const cc = getChartColors();
    const chartPalette = ['#3498db','#00b894','#f39c12','#e74c3c','#9b59b6','#1abc9c','#e67e22','#34495e'];

    // ===== Financial Overview Chart =====
    const finCtx = document.getElementById('financialOverviewChart');
    let financialChart = new Chart(finCtx, {
        type: 'line',
        data: {
            labels: monthlyTrend.map(d => d.month),
            datasets: [
                { label: 'Collection', data: monthlyTrend.map(d => d.collection), borderColor: '#00b894', backgroundColor: 'rgba(0,184,148,0.12)', tension: 0.4, fill: true, borderWidth: 3, pointRadius: 5, pointBackgroundColor: '#00b894', pointHoverRadius: 8, pointHoverBackgroundColor: '#00b894' },
                { label: 'Distribution', data: monthlyTrend.map(d => d.distribution), borderColor: '#f39c12', backgroundColor: 'rgba(243,156,18,0.12)', tension: 0.4, fill: true, borderWidth: 3, pointRadius: 5, pointBackgroundColor: '#f39c12', pointHoverRadius: 8 },
                { label: 'Expenses', data: monthlyTrend.map(d => { const m = expenseTrendData.find(e => e.month === d.month); return m ? parseFloat(m.total_expense) : 0; }), borderColor: '#e74c3c', backgroundColor: 'rgba(231,76,60,0.08)', tension: 0.4, fill: true, borderWidth: 2, borderDash: [5,5], pointRadius: 4, pointBackgroundColor: '#e74c3c' }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            animation: { duration: 1500, easing: 'easeOutQuart' },
            plugins: { legend: { labels: { color: cc.text, padding: 16, usePointStyle: true, pointStyle: 'circle' } }, tooltip: { mode: 'index', intersect: false, backgroundColor: 'rgba(0,0,0,0.85)', titleFont: { weight: 'bold' }, bodySpacing: 6, padding: 14, cornerRadius: 10, callbacks: { label: ctx => ctx.dataset.label + ': Rs ' + ctx.parsed.y.toLocaleString() } } },
            scales: { y: { beginAtZero: true, ticks: { color: cc.text, callback: v => 'Rs ' + v.toLocaleString() }, grid: { color: cc.grid } }, x: { ticks: { color: cc.text }, grid: { color: cc.grid } } },
            interaction: { mode: 'nearest', axis: 'x', intersect: false }
        }
    });

    // ===== Monthly Trend Chart =====
    const trendCtx = document.getElementById('trendChart');
    let trendChart = new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: monthlyTrend.map(d => d.month),
            datasets: [
                { label: 'Collection', data: monthlyTrend.map(d => d.collection), borderColor: '#00b894', backgroundColor: 'rgba(0,184,148,0.15)', tension: 0.4, fill: true, borderWidth: 2, pointRadius: 4, pointHoverRadius: 7 },
                { label: 'Distribution', data: monthlyTrend.map(d => d.distribution), borderColor: '#e74c3c', backgroundColor: 'rgba(231,76,60,0.1)', tension: 0.4, fill: true, borderWidth: 2, pointRadius: 4, pointHoverRadius: 7 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: true, animation: { duration: 1200, easing: 'easeOutQuart' }, plugins: { legend: { labels: { color: cc.text, usePointStyle: true } }, tooltip: { mode: 'index', intersect: false, backgroundColor: 'rgba(0,0,0,0.85)', cornerRadius: 10, callbacks: { label: ctx => ctx.dataset.label + ': Rs ' + ctx.parsed.y.toLocaleString() } } }, scales: { y: { beginAtZero: true, ticks: { color: cc.text, callback: v => 'Rs ' + v.toLocaleString() }, grid: { color: cc.grid } }, x: { ticks: { color: cc.text }, grid: { color: cc.grid } } } }
    });

    // ===== Payment Mode Chart =====
    const pmCtx = document.getElementById('paymentModeChart');
    let paymentModeChart = new Chart(pmCtx, {
        type: 'doughnut',
        data: {
            labels: paymentModesData.map(d => d.PaymentMode || 'Unknown'),
            datasets: [{ data: paymentModesData.map(d => d.count), backgroundColor: chartPalette, borderWidth: 3, borderColor: cc.cardBg || '#fff', hoverOffset: 15 }]
        },
        options: { responsive: true, maintainAspectRatio: true, cutout: '55%', animation: { animateRotate: true, duration: 1500 }, plugins: { legend: { position: 'bottom', labels: { color: cc.text, padding: 14, usePointStyle: true } }, tooltip: { backgroundColor: 'rgba(0,0,0,0.85)', cornerRadius: 10, callbacks: { label: ctx => { const t = ctx.dataset.data.reduce((a,b) => a+b, 0); return ctx.label + ': ' + ctx.parsed + ' (' + ((ctx.parsed/t)*100).toFixed(1) + '%)'; } } } } }
    });

    // ===== Top Members Chart =====
    const tmCtx = document.getElementById('topMembersChart');
    let topMembersChart = new Chart(tmCtx, {
        type: 'bar',
        data: {
            labels: topMembersData.map(d => d.MemberName || 'Unknown'),
            datasets: [{ label: 'Collection', data: topMembersData.map(d => d.total_collection), backgroundColor: chartPalette.slice(0,5), borderRadius: 8, borderSkipped: false }]
        },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: true, animation: { duration: 1200, easing: 'easeOutQuart' }, plugins: { legend: { display: false }, tooltip: { backgroundColor: 'rgba(0,0,0,0.85)', cornerRadius: 10, callbacks: { label: ctx => 'Rs ' + ctx.parsed.x.toLocaleString() } } }, scales: { x: { beginAtZero: true, ticks: { color: cc.text, callback: v => 'Rs ' + v.toLocaleString() }, grid: { color: cc.grid } }, y: { ticks: { color: cc.text }, grid: { display: false } } } }
    });

    // ===== Expense Category Chart =====
    const ecCtx = document.getElementById('expenseCategoryChart');
    let expenseCategoryChart = null;
    if (expenseCatData.length > 0) {
        expenseCategoryChart = new Chart(ecCtx, {
            type: 'pie',
            data: {
                labels: expenseCatData.map(d => d.Category),
                datasets: [{ data: expenseCatData.map(d => parseFloat(d.total_amount)), backgroundColor: ['#e74c3c','#f39c12','#3498db','#00b894','#9b59b6'], borderWidth: 2, borderColor: cc.cardBg || '#fff', hoverOffset: 12 }]
            },
            options: { responsive: true, maintainAspectRatio: true, animation: { animateRotate: true, duration: 1500 }, plugins: { legend: { position: 'bottom', labels: { color: cc.text, padding: 14, usePointStyle: true } }, tooltip: { backgroundColor: 'rgba(0,0,0,0.85)', cornerRadius: 10, callbacks: { label: ctx => ctx.label + ': Rs ' + ctx.parsed.toLocaleString() } } } }
        });
    }

    // ===== Transaction Type Chart =====
    const ttCtx = document.getElementById('typeChart');
    let typeChart = new Chart(ttCtx, {
        type: 'doughnut',
        data: {
            labels: transactionTypes.map(d => d.type),
            datasets: [{ data: transactionTypes.map(d => d.count), backgroundColor: ['#00b894','#e74c3c','#f39c12'], borderWidth: 3, borderColor: cc.cardBg || '#fff', hoverOffset: 15 }]
        },
        options: { responsive: true, maintainAspectRatio: true, cutout: '55%', animation: { animateRotate: true, duration: 1500 }, plugins: { legend: { position: 'bottom', labels: { color: cc.text, padding: 14, usePointStyle: true } } } }
    });

    // ===== Activity by Hour Chart =====
    const hoursMap = {};
    activityByHour.forEach(d => { hoursMap[d.hour] = d.count; });
    const hourLabels = Array.from({length: 24}, (_, i) => i + ':00');
    const hourData = Array.from({length: 24}, (_, i) => hoursMap[i] || 0);
    const ahCtx = document.getElementById('activityChart');
    let activityChart = new Chart(ahCtx, {
        type: 'bar',
        data: {
            labels: hourLabels,
            datasets: [{ label: 'Activity', data: hourData, backgroundColor: 'rgba(155,89,182,0.6)', hoverBackgroundColor: 'rgba(155,89,182,0.85)', borderRadius: 6, borderSkipped: false }]
        },
        options: { responsive: true, maintainAspectRatio: true, animation: { duration: 1200, easing: 'easeOutQuart' }, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { color: cc.text }, grid: { color: cc.grid } }, x: { ticks: { color: cc.text, maxRotation: 45 }, grid: { display: false } } } }
    });

    // ===== Update Charts on Theme Change =====
    function updateCharts() {
        [financialChart, trendChart, paymentModeChart, topMembersChart, expenseCategoryChart, typeChart, activityChart].forEach(c => {
            if (c && window.ThemeManager) window.ThemeManager.updateChartTheme(c);
        });
    }

    // ===== Chart Type Switching =====
    document.querySelectorAll('.chart-type-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const chartId = this.dataset.chart;
            const newType = this.dataset.type;
            if (!chartId || !newType) return;
            this.parentElement.querySelectorAll('.chart-type-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            const canvas = document.getElementById(chartId);
            if (!canvas) return;
            const chart = Chart.getChart(canvas);
            if (chart) {
                chart.config.type = newType;
                chart.update();
            }
        });
    });

    // ===== Section Toggle =====
    function toggleSection(id) {
        const el = document.getElementById(id);
        const toggle = document.getElementById(id + 'Toggle');
        if (!el) return;
        if (el.style.maxHeight && el.style.maxHeight !== 'none') {
            el.style.maxHeight = '0px';
            el.style.overflow = 'hidden';
            if (toggle) toggle.classList.add('collapsed');
        } else {
            el.style.maxHeight = el.scrollHeight + 'px';
            el.style.overflow = 'visible';
            if (toggle) toggle.classList.remove('collapsed');
        }
    }
    // Init sections as expanded
    document.querySelectorAll('.quick-actions-grouped, #yearComparison').forEach(el => {
        el.style.maxHeight = el.scrollHeight + 'px';
        el.style.overflow = 'visible';
    });

    // ===== Intersection Observer for scroll animations =====
    const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.animationPlayState = 'running';
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);
    document.querySelectorAll('.card, .growth-card, .section-header').forEach(el => {
        observer.observe(el);
    });

    // ===== Animated Number Counter =====
    function animateValue(el, start, end, duration) {
        const startTime = performance.now();
        const prefix = el.dataset.prefix || '';
        function update(currentTime) {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const current = start + (end - start) * eased;
            el.textContent = prefix + current.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            if (progress < 1) requestAnimationFrame(update);
        }
        requestAnimationFrame(update);
    }
    document.querySelectorAll('.hero-value[data-count]').forEach(el => {
        const target = parseFloat(el.dataset.count) || 0;
        if (target > 0) animateValue(el, 0, target, 1800);
    });

    // ===== Toast System =====
    function showToast(title, message, type = 'info') {
        const toast = document.getElementById('toast');
        document.getElementById('toastTitle').textContent = title;
        document.getElementById('toastMsg').textContent = message;
        toast.className = 'toast ' + type;
        toast.style.display = 'block';
        setTimeout(() => { toast.style.display = 'none'; }, 4000);
    }
    setTimeout(() => showToast('Welcome Back!', 'Dashboard loaded successfully', 'success'), 600);

    // ===== Keyboard Shortcuts =====
    function showShortcuts() { document.getElementById('shortcutsOverlay').style.display = 'block'; document.getElementById('shortcutsPanel').style.display = 'block'; }
    function hideShortcuts() { document.getElementById('shortcutsOverlay').style.display = 'none'; document.getElementById('shortcutsPanel').style.display = 'none'; }
    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;
        const key = e.key.toLowerCase();
        if (key === '?') { e.preventDefault(); showShortcuts(); }
        else if (key === 'escape') hideShortcuts();
        else if (key === 'd') { if (window.ThemeManager) window.ThemeManager.toggleTheme(); }
        else if (key === 'r') location.reload();
        else if (key === 'a') window.location.href = 'manage-admins.php';
        else if (key === 'm') window.location.href = 'manage-members.php';
        else if (key === 't') window.location.href = 'manage-transactions.php';
        else if (key === 'p') window.location.href = 'reports.php';
        else if (key === 'e') window.location.href = 'accounting/dashboard.php';
        else if (key === 's') window.location.href = 'settings.php';
        else if (key === 'l') window.location.href = 'audit-logs.php';
    });

    // ===== Live Clock =====
    function updateDashboardTime() {
        const el = document.getElementById('dashboard-time');
        if (el) el.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });
    }
    updateDashboardTime(); setInterval(updateDashboardTime, 1000);

    // ═══════════════════════════════════════════════════════
    //  GOOGLE DRIVE BACKUP WIDGET
    // ═══════════════════════════════════════════════════════
    const GDRIVE_API = 'api/gdrive-sync.php';
    let gdriveCurrentFilter = 'all';
    let gdriveCurrentPage = 1;
    let gdriveSearchTerm = '';
    let gdriveSyncing = false;
    let gdriveStatus = null;

    function gdriveFetch(action, method, body) {
        const url = GDRIVE_API + '?action=' + action;
        const opts = { method: method || 'GET', headers: { 'Content-Type': 'application/json' } };
        if (body) opts.body = JSON.stringify(body);
        return fetch(url, opts).then(r => r.json());
    }

    // Load status + stats
    async function gdriveRefresh() {
        try {
            const data = await gdriveFetch('status');
            gdriveStatus = data;

            // Connection info
            const dot  = document.getElementById('gdrive-dot');
            const text = document.getElementById('gdrive-conn-text');
            if (data.connected && data.account) {
                dot.className = 'gdrive-conn-dot connected';
                text.innerHTML = '<strong>' + (data.account.AccountName || 'Google Drive') + '</strong>'
                    + ' &nbsp;<span style="opacity:.6;font-size:12px;">' + (data.account.AccountEmail || '') + '</span>';
            } else if (data.connected) {
                dot.className = 'gdrive-conn-dot connected';
                text.textContent = 'Google Drive connected';
            } else {
                dot.className = 'gdrive-conn-dot error';
                text.innerHTML = 'Not connected &nbsp;<a href="google-drive-settings.php" style="color:#4285f4;font-size:12px;">Connect now</a>';
            }

            // Auto-sync toggle
            const tog = document.getElementById('gdrive-auto-toggle');
            if (tog) tog.checked = !!data.auto_sync;
            document.getElementById('gdrive-auto-label').textContent = data.auto_sync ? 'On' : 'Off';
            document.getElementById('gdrive-auto-label').style.color = data.auto_sync ? '#10b981' : '';

            // Stats
            const s = data.stats || {};
            document.getElementById('gd-local-count').textContent  = s.local   ?? '—';
            document.getElementById('gd-synced-count').textContent = s.synced  ?? '—';
            document.getElementById('gd-failed-count').textContent = s.failed  ?? '—';
            document.getElementById('gd-total-count').textContent  = s.total   ?? '—';

            // Progress bar
            const pct = s.total > 0 ? Math.round((s.synced / s.total) * 100) : 0;
            document.getElementById('gdrive-prog-fill').style.width = pct + '%';
            document.getElementById('gdrive-prog-label').textContent =
                (s.synced ?? 0) + ' / ' + (s.total ?? 0) + ' files backed up (' + pct + '%)';

            // Last sync
            const ls = document.getElementById('gdrive-last-sync');
            if (data.last_sync) {
                const d = new Date(data.last_sync.replace(' ', 'T'));
                ls.innerHTML = '<i class="fas fa-clock" style="margin-right:4px;"></i>Last sync: ' + d.toLocaleString();
            } else {
                ls.textContent = 'Never synced';
            }

            // Disable sync button if no drive
            document.getElementById('gdrive-sync-all-btn').disabled = !data.connected;

            await gdriveLoadFiles();
        } catch (e) {
            document.getElementById('gdrive-conn-text').textContent = 'Error loading status';
        }
    }

    // Load file list
    async function gdriveLoadFiles() {
        const tbody = document.getElementById('gdrive-tbody');
        tbody.innerHTML = '<tr><td colspan="7" class="gdrive-loading-row"><i class="fas fa-circle-notch fa-spin"></i> Loading…</td></tr>';

        try {
            const data = await gdriveFetch('list_files', 'GET',null);
            // Build URL with filter + page
            const url = GDRIVE_API + '?action=list_files&filter=' + gdriveCurrentFilter + '&page=' + gdriveCurrentPage;
            const res = await fetch(url).then(r => r.json());

            if (!res.success) { tbody.innerHTML = '<tr><td colspan="7" class="gdrive-empty-row">Error: ' + (res.error||'Failed') + '</td></tr>'; return; }

            document.getElementById('gdrive-file-count').textContent = res.total + ' files';

            if (!res.files || !res.files.length) {
                const labels = {all:'No files found',local:'All files are backed up to Drive',synced:'No files in Drive yet',failed:'No failed uploads'};
                tbody.innerHTML = '<tr><td colspan="7" class="gdrive-empty-row"><i class="fas fa-check-circle" style="font-size:24px;display:block;margin-bottom:8px;color:#10b981;"></i>' + (labels[gdriveCurrentFilter]||'No files') + '</td></tr>';
                document.getElementById('gdrive-pagination').innerHTML = '';
                return;
            }

            // Filter by search term client-side
            let files = res.files;
            if (gdriveSearchTerm) {
                files = files.filter(f => (f.OriginalName||'').toLowerCase().includes(gdriveSearchTerm.toLowerCase()));
            }

            tbody.innerHTML = '';
            files.forEach(function(f) {
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + gdriveFileIcon(f) + '</td>' +
                    '<td><div style="font-weight:600;color:var(--text-primary);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escHtml(f.OriginalName||f.FileName) + '">' + escHtml(f.OriginalName||f.FileName) + '</div>' +
                        '<div style="font-size:11px;color:var(--text-secondary);margin-top:2px;">' + f.size_human + '</div></td>' +
                    '<td><span class="gdrive-type-badge ' + (f.EntityType==='expense'?'gdrive-type-expense':'gdrive-type-event') + '">' + (f.EntityType==='expense'?'Expense':'Event') + '</span></td>' +
                    '<td>' + f.size_human + '</td>' +
                    '<td>' + gdriveBadge(f) + '</td>' +
                    '<td style="font-size:11px;color:var(--text-secondary);">' + gdriveDate(f.UploadedAt) + '</td>' +
                    '<td>' + gdriveActions(f) + '</td>';
                tbody.appendChild(tr);
            });

            // Pagination
            gdriveRenderPagination(res.page, res.pages, res.total);
        } catch(e) {
            tbody.innerHTML = '<tr><td colspan="7" class="gdrive-empty-row">Failed to load files</td></tr>';
        }
    }

    function gdriveFileIcon(f) {
        if (f.is_image && f.display_url) {
            return '<img src="' + escHtml(f.display_url) + '" class="gdrive-file-thumb" onerror="this.style.display=\'none\'">';
        }
        const m = f.MimeType || '';
        if (m.includes('image'))   return '<span class="gdrive-file-icon gdrive-icon-img"><i class="fas fa-image"></i></span>';
        if (m.includes('pdf'))     return '<span class="gdrive-file-icon gdrive-icon-pdf"><i class="fas fa-file-pdf"></i></span>';
        if (m.includes('word'))    return '<span class="gdrive-file-icon gdrive-icon-doc"><i class="fas fa-file-word"></i></span>';
        if (m.includes('sheet') || m.includes('excel') || m.includes('csv'))
                                   return '<span class="gdrive-file-icon gdrive-icon-xls"><i class="fas fa-file-excel"></i></span>';
        return '<span class="gdrive-file-icon gdrive-icon-file"><i class="fas fa-file"></i></span>';
    }

    function gdriveBadge(f) {
        if (f.UploadStatus === 'failed')
            return '<span class="gdrive-badge gdrive-badge-failed"><i class="fas fa-times-circle"></i> Failed</span>';
        if (f.GoogleDriveFileID)
            return '<span class="gdrive-badge gdrive-badge-synced"><i class="fab fa-google-drive"></i> In Drive</span>';
        return '<span class="gdrive-badge gdrive-badge-local"><i class="fas fa-hdd"></i> Local Only</span>';
    }

    function gdriveActions(f) {
        let btns = '';
        if (f.display_url) {
            btns += '<a href="' + escHtml(f.display_url) + '" target="_blank" class="gdrive-btn gdrive-btn-secondary" style="padding:5px 10px;font-size:11px;border-radius:6px;" title="View file"><i class="fas fa-eye"></i></a> ';
        }
        if (!f.GoogleDriveFileID && f.UploadStatus !== 'deleted') {
            btns += '<button onclick="gdriveSyncOne(' + f.AttachmentID + ',this)" class="gdrive-btn gdrive-btn-primary" style="padding:5px 10px;font-size:11px;border-radius:6px;" title="Sync to Drive"><i class="fas fa-cloud-upload-alt"></i></button>';
        }
        if (f.GoogleDriveFileID) {
            btns += '<a href="' + escHtml(f.GoogleDriveURL||'#') + '" target="_blank" class="gdrive-btn gdrive-btn-success" style="padding:5px 10px;font-size:11px;border-radius:6px;" title="Open in Google Drive"><i class="fab fa-google-drive"></i></a>';
        }
        if (f.UploadStatus === 'failed') {
            btns += ' <button onclick="gdriveSyncOne(' + f.AttachmentID + ',this)" class="gdrive-btn gdrive-btn-secondary" style="padding:5px 10px;font-size:11px;border-radius:6px;" title="Retry"><i class="fas fa-redo"></i></button>';
        }
        return '<div style="display:flex;gap:5px;align-items:center;">' + btns + '</div>';
    }

    function gdriveDate(ts) {
        if (!ts) return '—';
        return new Date(ts.replace(' ','T')).toLocaleDateString(undefined, {day:'2-digit',month:'short',year:'numeric'});
    }

    function gdriveRenderPagination(page, pages, total) {
        const el = document.getElementById('gdrive-pagination');
        if (pages <= 1) { el.innerHTML = ''; return; }
        let html = '';
        html += '<button class="gdrive-page-btn" onclick="gdrivePage(' + (page-1) + ')" ' + (page<=1?'disabled':'') + '><i class="fas fa-chevron-left"></i></button>';
        for (let i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || (i >= page-2 && i <= page+2)) {
                html += '<button class="gdrive-page-btn' + (i===page?' active':'') + '" onclick="gdrivePage(' + i + ')">' + i + '</button>';
            } else if (i === page-3 || i === page+3) {
                html += '<span style="padding:0 4px;color:var(--text-secondary);">…</span>';
            }
        }
        html += '<button class="gdrive-page-btn" onclick="gdrivePage(' + (page+1) + ')" ' + (page>=pages?'disabled':'') + '><i class="fas fa-chevron-right"></i></button>';
        html += '<span style="font-size:12px;color:var(--text-secondary);margin-left:8px;">' + total + ' total</span>';
        el.innerHTML = html;
    }

    function gdrivePage(p) { gdriveCurrentPage = p; gdriveLoadFiles(); }

    function gdriveFilterFiles(filter) {
        gdriveCurrentFilter = filter;
        gdriveCurrentPage = 1;
        document.getElementById('gdrive-filter-sel').value = filter;
        document.querySelectorAll('.gdrive-stat-card').forEach(c => c.classList.remove('active'));
        const labels = {all:'All Files',local:'Local Only Files',synced:'Files in Google Drive',failed:'Failed Uploads'};
        document.getElementById('gdrive-file-title').textContent = labels[filter] || 'Files';
        gdriveLoadFiles();
    }

    function gdriveSearch(term) {
        gdriveSearchTerm = term;
        gdriveCurrentPage = 1;
        clearTimeout(window._gdriveSearchTimer);
        window._gdriveSearchTimer = setTimeout(gdriveLoadFiles, 300);
    }

    async function gdriveSyncOne(id, btn) {
        if (!gdriveStatus || !gdriveStatus.connected) {
            alert('Google Drive is not connected. Go to Settings → Google Drive to connect your account.');
            return;
        }
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
        try {
            const res = await gdriveFetch('sync_file', 'POST', { attachment_id: id });
            if (res.success) {
                btn.innerHTML = '<i class="fas fa-check" style="color:#10b981;"></i>';
                setTimeout(() => { btn.innerHTML = orig; btn.disabled = false; gdriveRefresh(); }, 1500);
            } else {
                btn.innerHTML = '<i class="fas fa-times" style="color:#ef4444;"></i>';
                setTimeout(() => { btn.innerHTML = orig; btn.disabled = false; }, 2000);
                alert('Sync failed: ' + (res.error || 'Unknown error'));
            }
        } catch(e) {
            btn.innerHTML = orig; btn.disabled = false;
        }
    }

    async function gdriveSyncAll() {
        if (!gdriveStatus || !gdriveStatus.connected) {
            alert('Google Drive is not connected. Go to Settings → Google Drive to connect your account.');
            return;
        }
        if (gdriveSyncing) return;
        gdriveSyncing = true;

        const btn = document.getElementById('gdrive-sync-all-btn');
        btn.disabled = true;
        const prog = document.getElementById('gdrive-sync-progress');
        prog.style.display = 'flex';
        document.getElementById('gdrive-sync-text').textContent = 'Syncing files to Google Drive…';
        document.getElementById('gdrive-sync-sub').textContent = 'This may take a moment depending on file count and size.';

        try {
            const res = await gdriveFetch('sync_all', 'POST', {});
            prog.style.display = 'none';
            if (res.success) {
                const msg = res.synced + ' file(s) synced' + (res.failed > 0 ? ', ' + res.failed + ' failed' : '') + '.';
                document.getElementById('gdrive-sync-text').textContent = '✓ ' + msg;
                prog.style.display = 'flex';
                setTimeout(() => { prog.style.display = 'none'; }, 4000);
                await gdriveRefresh();
            } else {
                alert('Sync error: ' + (res.error || 'Unknown error'));
            }
        } catch(e) {
            prog.style.display = 'none';
            alert('Network error during sync.');
        }
        gdriveSyncing = false;
        btn.disabled = !gdriveStatus?.connected;
    }

    async function gdriveToggleAuto(enabled) {
        document.getElementById('gdrive-auto-label').textContent = enabled ? 'On' : 'Off';
        document.getElementById('gdrive-auto-label').style.color = enabled ? '#10b981' : '';
        try {
            await gdriveFetch('toggle_auto_sync', 'POST', { enabled });
        } catch(e) {}
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    // Init on load
    gdriveRefresh();

    function updateNavbarDT() {
        const d = document.getElementById('dash-navbar-date'), t = document.getElementById('dash-navbar-time');
        if (!d || !t) return;
        const now = new Date(), days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        d.textContent = days[now.getDay()] + ', ' + now.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
        t.textContent = now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
    updateNavbarDT(); setInterval(updateNavbarDT, 1000);
    </script>

    <?php outputLinkTargetScript('superadmin'); ?>
    <?php include '../includes/modal-component.php'; ?>
    <?php include '../includes/gemini-widget.php'; ?>
</body>
</html>
