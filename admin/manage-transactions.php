<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Permission check - now more permissive
$canViewTransactions = true;
if (isset($_SESSION['admin_id'])) {
    try {
        $canViewTransactions = hasAdminPermission($_SESSION['admin_id'], 'view_transactions');
    } catch (Exception $e) {
        error_log('Permission check error: ' . $e->getMessage());
        $canViewTransactions = true; // Grant access on error
    }
}

// Get filter parameters
$filterYear = isset($_GET['year']) ? intval($_GET['year']) : 0;
$filterMember = isset($_GET['member']) ? intval($_GET['member']) : 0;
$filterMonth = isset($_GET['month']) ? trim($_GET['month']) : '';
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$filterMode = isset($_GET['mode']) ? trim($_GET['mode']) : '';
$filterType = isset($_GET['type']) ? trim($_GET['type']) : '';

// Fetch academic years for filter
try {
    $stmt = $pdo->query("SELECT * FROM academic_years ORDER BY StartDate DESC");
    $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // DO NOT auto-select year - allow "All Years" (0) to remain as is
    // User can manually select a specific year if needed
} catch (Exception $e) {
    $years = [];
}

// Fetch members for filter
try {
    $stmt = $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberName ASC");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $members = [];
}

// Build query with filters
$query = "
    SELECT 
        t.TransactionID,
        t.YearID,
        t.MemberID,
        t.TransactionDate,
        t.TransactionTime,
        t.TransactionMonth,
        t.MonthName,
        t.CollectionAmount,
        t.DistributedAmount,
        t.InterestAmount,
        t.NextMonthDue,
        t.OutstandingPrincipal,
        t.PaymentMode,
        t.ReferenceNumber,
        t.Remarks,
        t.CreatedBy,
        t.CreatedAt,
        t.UpdatedAt,
        COALESCE(t.Verified, 0) as Verified,
        t.VerifiedBy,
        t.VerifiedAt,
        m.MemberName as MemberName,
        ay.YearName,
        a.Username as AdminUsername,
        CASE 
            WHEN t.DistributedAmount > 0 THEN 'Distribution'
            WHEN t.CollectionAmount > 0 THEN 'Collection'
            ELSE 'Unknown'
        END as TransactionType
    FROM transactions t
    JOIN members m ON t.MemberID = m.MemberID
    JOIN academic_years ay ON t.YearID = ay.YearID
    JOIN admins a ON t.CreatedBy = a.AdminID
    WHERE 1=1
";

$params = [];

if ($filterYear > 0) {
    $query .= " AND t.YearID = ?";
    $params[] = $filterYear;
}

if ($filterMember > 0) {
    $query .= " AND t.MemberID = ?";
    $params[] = $filterMember;
}

if (!empty($filterMonth)) {
    $query .= " AND t.MonthName = ?";
    $params[] = $filterMonth;
}

if (!empty($filterDateFrom)) {
    $query .= " AND t.TransactionDate >= ?";
    $params[] = $filterDateFrom;
}

if (!empty($filterDateTo)) {
    $query .= " AND t.TransactionDate <= ?";
    $params[] = $filterDateTo;
}

if (!empty($filterMode)) {
    $query .= " AND t.PaymentMode = ?";
    $params[] = $filterMode;
}

if (!empty($filterType)) {
    $query .= " AND t.TransactionType = ?";
    $params[] = $filterType;
}

$query .= " ORDER BY t.TransactionDate DESC, t.TransactionTime DESC LIMIT 500";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $transactions = [];
    $error = 'Error fetching transactions: ' . $e->getMessage();
}

// Calculate summary statistics
$totalCollection = 0;
$totalDistribution = 0;
$totalInterest = 0;
foreach ($transactions as $trans) {
    $totalCollection += $trans['CollectionAmount'] ?? 0;
    $totalDistribution += $trans['DistributedAmount'] ?? 0;
    $totalInterest += $trans['InterestAmount'] ?? 0;
}

$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Transactions - Admin</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container { max-width: 1600px; margin: 0 auto; }
        
        .header {
            background: rgba(255, 255, 255, 0.98);
            padding: 25px 30px;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            font-size: 28px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header-actions { display: flex; gap: 12px; flex-wrap: wrap; }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6); }
        
        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn-secondary:hover { background: #f0f4ff; }
        
        .btn-sm { padding: 8px 16px; font-size: 13px; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 5px solid;
        }
        
        .stat-card h3 { font-size: 14px; color: #666; margin-bottom: 10px; }
        .stat-card .stat-value { font-size: 28px; font-weight: bold; color: #333; }
        .stat-card.blue { border-color: #667eea; }
        .stat-card.green { border-color: #28a745; }
        .stat-card.orange { border-color: #ffc107; }
        .stat-card.purple { border-color: #764ba2; }
        
        .filter-container {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            margin-bottom: 25px;
        }
        
        .filter-container h2 {
            margin-bottom: 20px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
            font-size: 13px;
        }
        
        .filter-input, .filter-select {
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .transactions-table-container {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        }
        
        .transactions-table-container h2 {
            margin-bottom: 20px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: space-between;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
            font-size: 13px;
        }
        
        th { font-weight: 600; }
        tbody tr:hover { background: #f8f9fa; }
        
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-collection { background: #d4edda; color: #155724; }
        .badge-distribution { background: #fff3cd; color: #856404; }
        .badge-interest { background: #d1ecf1; color: #0c5460; }
        .badge-penalty { background: #f8d7da; color: #721c24; }
        
        .badge-cash { background: #e0e0e0; color: #333; }
        .badge-online { background: #cfe2ff; color: #084298; }
        .badge-upi { background: #d1e7dd; color: #0f5132; }
        .badge-cheque { background: #f8d7da; color: #721c24; }
        
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        
        .amount-positive { color: #28a745; font-weight: 600; }
        .amount-negative { color: #dc3545; font-weight: 600; }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .no-data i {
            font-size: 64px;
            display: block;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .filter-grid { grid-template-columns: 1fr; }
            table { font-size: 11px; }
            th, td { padding: 8px; }
        }
        
        /* Verification Dialog Styles */
        .verify-dialog-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .verify-dialog-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .verify-dialog-modal {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 450px;
            width: 90%;
            overflow: hidden;
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .verify-dialog-header {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .verify-dialog-header i {
            font-size: 28px;
        }
        
        .verify-dialog-header-content h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }
        
        .verify-dialog-body {
            padding: 25px;
            color: #333;
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .verify-dialog-footer {
            padding: 15px 25px;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            border-top: 1px solid #e0e0e0;
            background: #fafafa;
        }
        
        .btn-verify-cancel {
            padding: 10px 24px;
            border: 2px solid #d0d0d0;
            background: white;
            color: #333;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
        }
        
        .btn-verify-cancel:hover {
            background: #f0f0f0;
            border-color: #999;
        }
        
        .btn-verify-submit {
            padding: 10px 24px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
        }
        
        .btn-verify-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="container" style="margin-left: 280px; padding: 30px;">
        <div class="header">
            <h1><i class="fas fa-exchange-alt"></i> Transaction Management</h1>
            <div class="header-actions">
                <?php if (hasAdminPermission($_SESSION['admin_id'], 'add_transactions')): ?>
                <!-- <a href="add-transaction.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Transaction
                </a> -->
                <?php endif; ?>
                <?php if (hasAdminPermission($_SESSION['admin_id'], 'manage_years')): ?>
                <a href="manage-years.php" class="btn btn-secondary">
                    <i class="fas fa-calendar"></i> Manage Years
                </a>
                <?php endif; ?>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Error Display -->
        <?php if (isset($error)): ?>
        <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 20px; border-radius: 10px; margin-bottom: 25px;">
            <h3 style="margin: 0 0 10px 0;"><i class="fas fa-exclamation-triangle"></i> Error</h3>
            <p style="margin: 0;"><?php echo htmlspecialchars($error); ?></p>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <h3><i class="fas fa-list"></i> Total Transactions</h3>
                <div class="stat-value"><?php echo count($transactions); ?></div>
            </div>
            <div class="stat-card green">
                <h3><i class="fas fa-arrow-down"></i> Total Collections</h3>
                <div class="stat-value">₹<?php echo number_format($totalCollection, 2); ?></div>
            </div>
            <div class="stat-card orange">
                <h3><i class="fas fa-arrow-up"></i> Total Distributions</h3>
                <div class="stat-value">₹<?php echo number_format($totalDistribution, 2); ?></div>
            </div>
            <div class="stat-card purple">
                <h3><i class="fas fa-percentage"></i> Total Interest</h3>
                <div class="stat-value">₹<?php echo number_format($totalInterest, 2); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-container">
            <h2><i class="fas fa-filter"></i> Filter Transactions</h2>
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Academic Year</label>
                        <select name="year" class="filter-select">
                            <option value="0">All Years</option>
                            <?php foreach ($years as $year): ?>
                            <option value="<?php echo $year['YearID']; ?>" <?php echo ($filterYear == $year['YearID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['YearName']); ?>
                                <?php if ($year['Status'] === 'Active'): ?>(Active)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Member</label>
                        <select name="member" class="filter-select">
                            <option value="0">All Members</option>
                            <?php foreach ($members as $member): ?>
                            <option value="<?php echo $member['MemberID']; ?>" <?php echo ($filterMember == $member['MemberID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($member['MemberName']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Month</label>
                        <select name="month" class="filter-select">
                            <option value="">All Months</option>
                            <?php foreach ($months as $month): ?>
                            <option value="<?php echo $month; ?>" <?php echo ($filterMonth === $month) ? 'selected' : ''; ?>>
                                <?php echo $month; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Date From</label>
                        <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Date To</label>
                        <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($filterDateTo); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Payment Mode</label>
                        <select name="mode" class="filter-select">
                            <option value="">All Modes</option>
                            <option value="Cash" <?php echo ($filterMode === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                            <option value="Online" <?php echo ($filterMode === 'Online') ? 'selected' : ''; ?>>Online</option>
                            <option value="UPI" <?php echo ($filterMode === 'UPI') ? 'selected' : ''; ?>>UPI</option>
                            <option value="Bank Transfer" <?php echo ($filterMode === 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="Cheque" <?php echo ($filterMode === 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Transaction Type</label>
                        <select name="type" class="filter-select">
                            <option value="">All Types</option>
                            <option value="Collection" <?php echo ($filterType === 'Collection') ? 'selected' : ''; ?>>Collection</option>
                            <option value="Distribution" <?php echo ($filterType === 'Distribution') ? 'selected' : ''; ?>>Distribution</option>
                            <option value="Interest" <?php echo ($filterType === 'Interest') ? 'selected' : ''; ?>>Interest</option>
                            <option value="Penalty" <?php echo ($filterType === 'Penalty') ? 'selected' : ''; ?>>Penalty</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="manage-transactions.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="transactions-table-container">
            <h2>
                <span><i class="fas fa-table"></i> Transactions List</span>
                <span style="font-size: 14px; font-weight: normal; color: #666;">
                    Showing <?php echo count($transactions); ?> results
                </span>
            </h2>
            
            <?php if (empty($transactions)): ?>
                <div class="no-data">
                    <i class="fas fa-inbox"></i>
                    <h3>No Transactions Found</h3>
                    <p>Try adjusting your filters or add a new transaction.</p>
                </div>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date & Time</th>
                            <th>Member</th>
                            <th>Month</th>
                            <th>Type</th>
                            <th>Collection</th>
                            <th>Distribution</th>
                            <th>Interest</th>
                            <th>Next Due</th>
                            <th>Mode</th>
                            <th>Status</th>
                            <th>Added By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $trans): ?>
                        <tr>
                            <td><strong>#<?php echo $trans['TransactionID']; ?></strong></td>
                            <td>
                                <?php 
                                if (!empty($trans['TransactionDate']) && $trans['TransactionDate'] != '0000-00-00') {
                                    echo date('d M Y', strtotime($trans['TransactionDate']));
                                } else {
                                    echo date('d M Y', strtotime($trans['CreatedAt']));
                                }
                                ?><br>
                                <small style="color: #666;"><?php echo date('h:i A', strtotime($trans['TransactionTime'])); ?></small>
                            </td>
                            <td><strong><?php echo htmlspecialchars($trans['MemberName']); ?></strong></td>
                            <td><?php echo htmlspecialchars($trans['MonthName'] ?? '-'); ?></td>
                            <td>
                                <?php 
                                $transType = $trans['TransactionType'];
                                $badgeClass = strtolower($transType);
                                ?>
                                <span class="badge badge-<?php echo $badgeClass; ?>">
                                    <?php echo htmlspecialchars($transType); ?>
                                </span>
                            </td>
                            <td class="amount-positive">
                                <?php echo ($trans['CollectionAmount'] > 0) ? '₹' . number_format($trans['CollectionAmount'], 2) : '-'; ?>
                            </td>
                            <td class="amount-negative">
                                <?php echo ($trans['DistributedAmount'] > 0) ? '₹' . number_format($trans['DistributedAmount'], 2) : '-'; ?>
                            </td>
                            <td><?php echo ($trans['InterestAmount'] > 0) ? '₹' . number_format($trans['InterestAmount'], 2) : '-'; ?></td>
                            <td><?php echo ($trans['NextMonthDue'] > 0) ? '₹' . number_format($trans['NextMonthDue'], 2) : '-'; ?></td>
                            <td>
                                <?php $payMode = $trans['PaymentMode'] ?? 'N/A'; ?>
                                <span class="badge badge-<?php echo strtolower($payMode); ?>">
                                    <?php echo htmlspecialchars($payMode); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($trans['Verified'] == 1): ?>
                                    <span class="badge badge-success" title="Verified on <?php echo date('d M Y', strtotime($trans['VerifiedAt'])); ?>">
                                        <i class="fas fa-check-circle"></i> Verified
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-warning">
                                        <i class="fas fa-clock"></i> Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($trans['AdminUsername'] ?? 'Unknown'); ?></small>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px;">
                                    <a href="edit-transaction.php?id=<?php echo $trans['TransactionID']; ?>" 
                                       class="btn btn-sm" style="background: #3b82f6; color: white; padding: 6px 12px;"
                                       title="Edit Transaction">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($trans['Verified'] == 0): ?>
                                        <button type="button"
                                           class="btn btn-sm" style="background: #10b981; color: white; padding: 6px 12px;"
                                           title="Verify Transaction"
                                           onclick="toggleVerificationStatus(<?php echo $trans['TransactionID']; ?>, 'verify')">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button"
                                           class="btn btn-sm" style="background: #f59e0b; color: white; padding: 6px 12px;"
                                           title="Unverify Transaction"
                                           onclick="toggleVerificationStatus(<?php echo $trans['TransactionID']; ?>, 'unverify')">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php include '../includes/modal-component.php'; ?>
    
    <div id="verifyDialogOverlay" class="verify-dialog-overlay" onclick="if(event.target === this) closeVerifyDialog();"></div>
    
    <script>
        function toggleVerificationStatus(transactionId, action) {
            const actionText = action === 'verify' ? 'verify' : 'remove verification from';
            const message = 'Toggle verification status for this transaction?';
            
            showVerifyDialog('Confirm Action', message, action === 'verify' ? 'Verify' : 'Unverify', 'Cancel', function(confirmed) {
                if (!confirmed) return;
                
                // Redirect to verify-transaction.php
                window.location.href = 'verify-transaction.php?id=' + transactionId + '&action=' + action;
            });
        }
        
        function showVerifyDialog(title, message, confirmBtnText, cancelBtnText, callback) {
            const overlay = document.getElementById('verifyDialogOverlay');
            const existingModal = overlay.querySelector('.verify-dialog-modal');
            if (existingModal) existingModal.remove();
            
            const modal = document.createElement('div');
            modal.className = 'verify-dialog-modal';
            modal.innerHTML = `
                <div class="verify-dialog-header">
                    <i class="fas fa-exclamation-circle"></i>
                    <div class="verify-dialog-header-content">
                        <h2>${title}</h2>
                    </div>
                </div>
                <div class="verify-dialog-body">${message}</div>
                <div class="verify-dialog-footer">
                    <button class="btn-verify-cancel" onclick="closeVerifyDialog()">${cancelBtnText}</button>
                    <button class="btn-verify-submit" onclick="confirmVerifyDialog()">${confirmBtnText}</button>
                </div>
            `;
            
            overlay.appendChild(modal);
            overlay.classList.add('active');
            
            // Store callback in window scope
            window.verifyDialogCallback = callback;
            
            // Auto-focus confirm button
            const confirmBtn = modal.querySelector('.btn-verify-submit');
            if (confirmBtn) confirmBtn.focus();
            
            // Keyboard support
            const handleKeydown = (e) => {
                if (e.key === 'Escape') {
                    closeVerifyDialog();
                    document.removeEventListener('keydown', handleKeydown);
                }
                if (e.key === 'Enter' && document.activeElement === confirmBtn) {
                    confirmVerifyDialog();
                    document.removeEventListener('keydown', handleKeydown);
                }
            };
            document.addEventListener('keydown', handleKeydown);
        }
        
        function confirmVerifyDialog() {
            const overlay = document.getElementById('verifyDialogOverlay');
            overlay.classList.remove('active');
            setTimeout(() => {
                const modal = overlay.querySelector('.verify-dialog-modal');
                if (modal) modal.remove();
            }, 300);
            if (typeof window.verifyDialogCallback === 'function') {
                window.verifyDialogCallback(true);
            }
        }
        
        function closeVerifyDialog() {
            const overlay = document.getElementById('verifyDialogOverlay');
            overlay.classList.remove('active');
            setTimeout(() => {
                const modal = overlay.querySelector('.verify-dialog-modal');
                if (modal) modal.remove();
            }, 300);
            if (typeof window.verifyDialogCallback === 'function') {
                window.verifyDialogCallback(false);
            }
        }
    </script>
</body>
</html>
