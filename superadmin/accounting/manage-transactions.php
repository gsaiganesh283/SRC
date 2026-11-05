<?php
/**
 * Accounting Manage Transactions Page
 * View all financial transactions including expenses and member payments
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Get filter parameters
$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';
$transactionType = isset($_GET['type']) ? $_GET['type'] : 'all';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 25;
$offset = ($page - 1) * $limit;

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Check if expenses table exists
$expensesTableExists = false;
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'expenses'");
if ($tableCheckStmt->rowCount() > 0) {
    $expensesTableExists = true;
}

// Build unified transactions query
// We'll combine member transactions (collections/distributions) with expenses
$memberTransactionsQuery = "
    SELECT 
        t.TransactionID as ID,
        'member' as SourceType,
        t.YearID,
        ay.YearName,
        t.MemberID,
        CONCAT(m.MemberID, ' - ', m.MemberName) as EntityName,
        CASE 
            WHEN t.CollectionAmount > 0 AND t.DistributedAmount > 0 THEN 'Collection & Distribution'
            WHEN t.CollectionAmount > 0 THEN 'Collection'
            WHEN t.DistributedAmount > 0 THEN 'Distribution'
            ELSE 'Transaction'
        END as TransactionType,
        CASE WHEN t.CollectionAmount > 0 THEN 'Credit' ELSE 'Debit' END as Direction,
        GREATEST(COALESCE(t.CollectionAmount, 0), COALESCE(t.DistributedAmount, 0)) as Amount,
        t.PaymentMode,
        t.TransactionDate as TransactionDate,
        DATE_FORMAT(t.TransactionDate, '%d %b %Y') as DisplayDate,
        t.Remarks,
        t.CreatedAt,
        a.FullName as CreatedBy,
        (SELECT COUNT(*) FROM proof_attachments pa WHERE pa.EntityType = 'transaction' AND pa.EntityID = t.TransactionID AND pa.DeletedAt IS NULL) as ProofCount
    FROM transactions t
    LEFT JOIN academic_years ay ON t.YearID = ay.YearID
    LEFT JOIN members m ON t.MemberID = m.MemberID
    LEFT JOIN admins a ON t.CreatedBy = a.AdminID
";

$expenseTransactionsQuery = "";
if ($expensesTableExists) {
    $expenseTransactionsQuery = "
        UNION ALL
        SELECT 
            e.ExpenseID as ID,
            'expense' as SourceType,
            e.YearID,
            ay.YearName,
            NULL as MemberID,
            CONCAT(e.Category, ': ', e.Description) as EntityName,
            e.Category as TransactionType,
            'Debit' as Direction,
            e.Amount,
            e.PaymentMode,
            e.ExpenseDate as TransactionDate,
            DATE_FORMAT(e.ExpenseDate, '%b %Y') as DisplayDate,
            e.Remarks,
            e.CreatedAt,
            a.FullName as CreatedBy,
            (SELECT COUNT(*) FROM proof_attachments pa WHERE pa.EntityType = 'expense' AND pa.EntityID = e.ExpenseID AND pa.DeletedAt IS NULL) as ProofCount
        FROM expenses e
        LEFT JOIN academic_years ay ON e.YearID = ay.YearID
        LEFT JOIN admins a ON e.CreatedBy = a.AdminID
    ";
}

// Build WHERE conditions
$whereConditions = [];
$params = [];

if ($selectedYear !== 'all') {
    $whereConditions[] = "YearID = ?";
    $params[] = $selectedYear;
}

if ($startDate) {
    $whereConditions[] = "TransactionDate >= ?";
    $params[] = $startDate;
}

if ($endDate) {
    $whereConditions[] = "TransactionDate <= ?";
    $params[] = $endDate;
}

if ($transactionType === 'member') {
    $whereConditions[] = "SourceType = 'member'";
} elseif ($transactionType === 'expense') {
    $whereConditions[] = "SourceType = 'expense'";
} elseif ($transactionType === 'credit') {
    $whereConditions[] = "Direction = 'Credit'";
} elseif ($transactionType === 'debit') {
    $whereConditions[] = "Direction = 'Debit'";
}

if ($search) {
    $whereConditions[] = "(EntityName LIKE ? OR TransactionType LIKE ? OR Remarks LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Combined query wrapped in subquery
$fullQuery = "
    SELECT * FROM (
        $memberTransactionsQuery
        $expenseTransactionsQuery
    ) as combined_transactions
    $whereClause
    ORDER BY CreatedAt DESC, ID DESC
    LIMIT $limit OFFSET $offset
";

$stmt = $pdo->prepare($fullQuery);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count query for pagination
$countQuery = "
    SELECT COUNT(*) as total FROM (
        $memberTransactionsQuery
        $expenseTransactionsQuery
    ) as combined_transactions
    $whereClause
";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRecords / $limit);

// Summary stats
$summaryQuery = "
    SELECT 
        SUM(CASE WHEN Direction = 'Credit' THEN Amount ELSE 0 END) as total_credits,
        SUM(CASE WHEN Direction = 'Debit' THEN Amount ELSE 0 END) as total_debits,
        COUNT(CASE WHEN SourceType = 'member' THEN 1 END) as member_count,
        COUNT(CASE WHEN SourceType = 'expense' THEN 1 END) as expense_count
    FROM (
        $memberTransactionsQuery
        $expenseTransactionsQuery
    ) as combined_transactions
    $whereClause
";
$summaryStmt = $pdo->prepare($summaryQuery);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Transactions - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../shared-styles.css">
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
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: var(--bg-secondary);
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            text-align: center;
        }
        
        .summary-card.credit { border-top: 4px solid #10b981; }
        .summary-card.debit { border-top: 4px solid #ef4444; }
        .summary-card.net { border-top: 4px solid #3b82f6; }
        .summary-card.count { border-top: 4px solid #f59e0b; }
        
        .summary-card h3 {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }
        
        .summary-card .value {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .summary-card .value.credit { color: #10b981; }
        .summary-card .value.debit { color: #ef4444; }
        
        .filters-card {
            background: var(--bg-secondary);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
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
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .transactions-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .transactions-table th,
        .transactions-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .transactions-table th {
            background: var(--bg-tertiary);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-secondary);
        }
        
        .transactions-table tr:hover td {
            background: var(--bg-tertiary);
        }
        
        .source-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .source-badge.member {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }
        
        .source-badge.expense {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .direction-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .direction-badge.credit {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        
        .direction-badge.debit {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .amount {
            font-weight: 700;
            font-size: 15px;
        }
        
        .amount.credit {
            color: #10b981;
        }
        
        .amount.debit {
            color: #ef4444;
        }
        
        .entity-name {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        
        .pagination a {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }
        
        .pagination a:hover {
            background: #10b981;
            color: white;
        }
        
        .pagination .current {
            background: #10b981;
            color: white;
        }
        
        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 64px;
            color: var(--text-secondary);
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 20px;
            color: var(--text-primary);
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: var(--text-secondary);
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
        
        .results-info {
            padding: 15px 20px;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            font-size: 14px;
            border-bottom: 1px solid var(--border-color);
        }
        
        @media (max-width: 1024px) {
            .transactions-table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 768px) {
            .filters-grid {
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
                <h1><i class="fas fa-exchange-alt"></i> All Financial Transactions</h1>
                <p style="color: var(--text-secondary); margin-top: 8px;">View member payments and expense transactions</p>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card credit">
                <h3>Total Credits</h3>
                <div class="value credit">₹<?php echo number_format($summary['total_credits'] ?? 0, 0); ?></div>
            </div>
            <div class="summary-card debit">
                <h3>Total Debits</h3>
                <div class="value debit">₹<?php echo number_format($summary['total_debits'] ?? 0, 0); ?></div>
            </div>
            <div class="summary-card net">
                <h3>Net Balance</h3>
                <div class="value">₹<?php echo number_format(($summary['total_credits'] ?? 0) - ($summary['total_debits'] ?? 0), 0); ?></div>
            </div>
            <div class="summary-card count">
                <h3>Total Records</h3>
                <div class="value"><?php echo number_format($totalRecords); ?></div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" id="filterForm">
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
                        <label>Transaction Type</label>
                        <select name="type">
                            <option value="all" <?php echo $transactionType === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="member" <?php echo $transactionType === 'member' ? 'selected' : ''; ?>>Member Payments</option>
                            <option value="expense" <?php echo $transactionType === 'expense' ? 'selected' : ''; ?>>Expenses Only</option>
                            <option value="credit" <?php echo $transactionType === 'credit' ? 'selected' : ''; ?>>Credits Only</option>
                            <option value="debit" <?php echo $transactionType === 'debit' ? 'selected' : ''; ?>>Debits Only</option>
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
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Name, type, remarks..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn-filter">
                            <i class="fas fa-filter"></i>
                            Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Transactions Table -->
        <div class="transactions-card">
            <div class="results-info">
                Showing <?php echo count($transactions); ?> of <?php echo number_format($totalRecords); ?> transactions
                (Member: <?php echo number_format($summary['member_count'] ?? 0); ?>, Expense: <?php echo number_format($summary['expense_count'] ?? 0); ?>)
            </div>
            
            <?php if (empty($transactions)): ?>
                <div class="empty-state">
                    <i class="fas fa-exchange-alt"></i>
                    <h3>No Transactions Found</h3>
                    <p>No transactions match your filter criteria.</p>
                </div>
            <?php else: ?>
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Source</th>
                            <th>Year</th>
                            <th>Entity / Description</th>
                            <th>Type</th>
                            <th>Direction</th>
                            <th>Amount</th>
                            <th>Payment Mode</th>
                            <th>Date</th>
                            <th>Proofs</th>
                            <th>Created By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $txn): ?>
                            <tr>
                                <td>#<?php echo $txn['ID']; ?></td>
                                <td>
                                    <span class="source-badge <?php echo $txn['SourceType']; ?>">
                                        <i class="fas fa-<?php echo $txn['SourceType'] === 'member' ? 'user' : 'receipt'; ?>"></i>
                                        <?php echo ucfirst($txn['SourceType']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($txn['YearName'] ?? 'N/A'); ?></td>
                                <td class="entity-name" title="<?php echo htmlspecialchars($txn['EntityName']); ?>">
                                    <?php echo htmlspecialchars($txn['EntityName']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($txn['TransactionType']); ?></td>
                                <td>
                                    <span class="direction-badge <?php echo strtolower($txn['Direction']); ?>">
                                        <i class="fas fa-arrow-<?php echo $txn['Direction'] === 'Credit' ? 'down' : 'up'; ?>"></i>
                                        <?php echo $txn['Direction']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="amount <?php echo strtolower($txn['Direction']); ?>">
                                        <?php echo $txn['Direction'] === 'Credit' ? '+' : '-'; ?>₹<?php echo number_format($txn['Amount'], 0); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($txn['PaymentMode']); ?></td>
                                <td><?php echo htmlspecialchars($txn['DisplayDate']); ?></td>
                                <td>
                                    <?php 
                                    $proofCount = intval($txn['ProofCount'] ?? 0);
                                    $proofEntityType = ($txn['SourceType'] === 'expense') ? 'expense' : 'transaction';
                                    if ($proofCount > 0): ?>
                                        <span onclick="viewProofs(<?php echo $txn['ID']; ?>, '<?php echo $proofEntityType; ?>')" style="cursor:pointer; background: rgba(139,92,246,0.15); color: #8b5cf6; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                            <i class="fas fa-paperclip"></i> <?php echo $proofCount; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-secondary); font-size: 12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($txn['CreatedBy'] ?? 'System'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i> Prev
                            </a>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if ($startPage > 1) {
                            echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a>';
                            if ($startPage > 2) echo '<span>...</span>';
                        }
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) echo '<span>...</span>';
                            echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $totalPages])) . '">' . $totalPages . '</a>';
                        }
                        ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="../theme-manager.js"></script>
    <script>
        const PROOF_API_URL = '../api/proof-upload.php';
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function viewProofs(entityId, entityType) {
            const modal = document.getElementById('proofViewerModal');
            const content = document.getElementById('proofViewerContent');
            content.innerHTML = '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:#8b5cf6;"></i><p style="margin-top:10px;color:var(--text-secondary);">Loading proofs...</p></div>';
            modal.style.display = 'flex';
            
            fetch(PROOF_API_URL + '?action=list&entity_type=' + entityType + '&entity_id=' + entityId)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    if (data.success && data.attachments.length > 0) {
                        data.attachments.forEach(att => {
                            const isImage = att.MimeType && att.MimeType.startsWith('image/');
                            const storageLabel = att.GoogleDriveFileID 
                                ? '<span style="font-size:11px;color:#4285f4;"><i class="fab fa-google-drive"></i> Google Drive</span>'
                                : '<span style="font-size:11px;color:#94a3b8;"><i class="fas fa-hdd"></i> Local Storage</span>';
                            const viewUrl = escapeHtml(att.viewUrl || '');
                            const gdriveId = att.GoogleDriveFileID || '';
                            const mimeType = att.MimeType || '';
                            const thumbUrl = att.thumbnailUrl || att.viewUrl;
                            html += `<div onclick="openDocumentViewer('${viewUrl}', '${escapeHtml(att.OriginalName)}', '${mimeType}', '${gdriveId}')" style="display:flex;align-items:center;gap:12px;background:var(--bg-tertiary,#f3f4f6);padding:15px;border-radius:10px;margin-bottom:10px;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.transform='translateX(4px)';this.style.boxShadow='0 2px 8px rgba(139,92,246,0.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                                ${isImage ? '<img src="' + thumbUrl + '" style="width:60px;height:60px;object-fit:cover;border-radius:8px;">' : '<div style="width:60px;height:60px;display:flex;align-items:center;justify-content:center;background:rgba(139,92,246,0.15);border-radius:8px;font-size:24px;color:#8b5cf6;"><i class="fas ' + att.iconClass + '"></i></div>'}
                                <div style="flex:1;">
                                    <div style="font-weight:600;color:var(--text-primary);">${escapeHtml(att.OriginalName)}</div>
                                    <div style="font-size:12px;color:var(--text-secondary);">${att.formattedSize} &bull; ${att.UploadedAt || ''}</div>
                                    <div style="margin-top:3px;">${storageLabel}</div>
                                </div>
                                <div style="color:#8b5cf6;font-size:18px;"><i class="fas fa-eye"></i></div>
                            </div>`;
                        });
                    } else {
                        html = '<p style="color:var(--text-secondary);text-align:center;padding:20px;">No proof files attached.</p>';
                    }
                    content.innerHTML = html;
                })
                .catch(() => { content.innerHTML = '<p style="color:#ef4444;text-align:center;">Failed to load proofs.</p>'; });
        }
        
        function openDocumentViewer(url, fileName, mimeType, gdriveId) {
            const modal = document.getElementById('documentViewerModal');
            const contentArea = document.getElementById('docViewerContent');
            const titleEl = document.getElementById('docViewerTitle');
            const downloadBtn = document.getElementById('docViewerDownload');
            const openFullBtn = document.getElementById('docViewerOpenFull');
            
            titleEl.textContent = fileName;
            downloadBtn.onclick = function() { window.open(url, '_blank'); };
            openFullBtn.onclick = function() { window.open(url, '_blank'); };
            
            const isImage = mimeType && mimeType.startsWith('image/');
            const isPdf = mimeType === 'application/pdf';
            let previewUrl = url;
            if (gdriveId) previewUrl = 'https://drive.google.com/file/d/' + gdriveId + '/preview';
            
            if (isImage) {
                contentArea.innerHTML = `<div style="text-align:center;padding:10px;max-height:calc(80vh - 140px);overflow:auto;">
                    <img src="${url}" style="max-width:100%;max-height:70vh;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.2);" alt="${escapeHtml(fileName)}">
                </div>`;
            } else if (isPdf || gdriveId) {
                contentArea.innerHTML = `<iframe src="${previewUrl}" style="width:100%;height:calc(80vh - 140px);border:none;" allowfullscreen></iframe>`;
            } else {
                contentArea.innerHTML = `<div style="text-align:center;padding:60px 20px;">
                    <i class="fas fa-file" style="font-size:64px;color:#8b5cf6;margin-bottom:16px;display:block;"></i>
                    <h4 style="margin:0 0 8px;color:var(--text-primary);">${escapeHtml(fileName)}</h4>
                    <p style="color:var(--text-secondary);margin:0 0 20px;">This file type cannot be previewed inline.</p>
                    <a href="${url}" target="_blank" style="display:inline-block;padding:10px 24px;background:#8b5cf6;color:white;border-radius:8px;text-decoration:none;font-weight:600;"><i class="fas fa-download"></i> Download File</a>
                </div>`;
            }
            modal.style.display = 'flex';
        }
        
        function closeDocumentViewer() {
            document.getElementById('documentViewerModal').style.display = 'none';
            document.getElementById('docViewerContent').innerHTML = '';
        }
        
        function closeProofViewer() {
            document.getElementById('proofViewerModal').style.display = 'none';
        }
        
        document.getElementById('proofViewerModal').addEventListener('click', function(e) {
            if (e.target === this) closeProofViewer();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { closeProofViewer(); closeDocumentViewer(); }
        });
    </script>

    <!-- Proof Viewer Modal -->
    <div id="proofViewerModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:10000;justify-content:center;align-items:center;backdrop-filter:blur(4px);">
        <div style="background:var(--bg-primary,#fff);border-radius:16px;width:90%;max-width:600px;max-height:80vh;overflow-y:auto;box-shadow:0 25px 50px rgba(0,0,0,0.25);">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid var(--border-color,#e5e7eb);">
                <h3 style="margin:0;font-size:16px;"><i class="fas fa-paperclip" style="color:#8b5cf6;"></i> Proof Files</h3>
                <button onclick="closeProofViewer()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-secondary);padding:4px 8px;border-radius:6px;">&times;</button>
            </div>
            <div id="proofViewerContent" style="padding:20px 24px;"></div>
        </div>
    </div>

    <!-- Document Viewer Modal -->
    <div id="documentViewerModal" onclick="if(event.target===this)closeDocumentViewer()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.75);z-index:10001;justify-content:center;align-items:center;backdrop-filter:blur(6px);">
        <div style="background:var(--bg-primary,#1a1a2e);border-radius:16px;width:94%;max-width:900px;max-height:90vh;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,0.4);display:flex;flex-direction:column;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 24px;border-bottom:1px solid var(--border-color,#2d2d44);">
                <h3 style="margin:0;font-size:15px;color:var(--text-primary,#fff);display:flex;align-items:center;gap:8px;"><i class="fas fa-eye" style="color:#8b5cf6;"></i> <span id="docViewerTitle">View Document</span></h3>
                <button onclick="closeDocumentViewer()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-secondary);padding:4px 8px;border-radius:6px;line-height:1;">&times;</button>
            </div>
            <div id="docViewerContent" style="flex:1;overflow:auto;background:#000;min-height:300px;"></div>
            <div style="display:flex;justify-content:center;gap:12px;padding:14px 24px;border-top:1px solid var(--border-color,#2d2d44);">
                <button id="docViewerDownload" style="display:flex;align-items:center;gap:6px;padding:8px 20px;background:#ef4444;color:white;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;"><i class="fas fa-download"></i> Download</button>
                <button id="docViewerOpenFull" style="display:flex;align-items:center;gap:6px;padding:8px 20px;background:var(--bg-tertiary,#2d2d44);color:var(--text-primary,#fff);border:1px solid var(--border-color,#3d3d5c);border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;"><i class="fas fa-external-link-alt"></i> Open Full</button>
                <button onclick="closeDocumentViewer()" style="display:flex;align-items:center;gap:6px;padding:8px 20px;background:var(--bg-tertiary,#2d2d44);color:var(--text-primary,#fff);border:1px solid var(--border-color,#3d3d5c);border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;">Close</button>
            </div>
        </div>
    </div>
</body>
</html>
