<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireMemberLogin();

$memberID = $_SESSION['member_id'];

// Get member details
try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE MemberID = ?");
    $stmt->execute([$memberID]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die('Error fetching member details');
}

// Get active academic year
try {
    $stmt = $pdo->query("SELECT * FROM academic_years WHERE Status = 'Active' LIMIT 1");
    $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $activeYear = null;
}

// Get payment history for active year
$transactions = [];
$totalPaid = 0;
$totalReceived = 0;
$totalInterest = 0;
$currentDue = 0;

if ($activeYear) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                *,
                CASE 
                    WHEN CollectionAmount > 0 AND DistributedAmount = 0 THEN 'Collection'
                    WHEN DistributedAmount > 0 AND CollectionAmount = 0 THEN 'Distribution'
                    WHEN CollectionAmount > 0 AND DistributedAmount > 0 THEN 'Both'
                    ELSE 'Other'
                END AS TransactionType
            FROM transactions 
            WHERE MemberID = ? AND YearID = ?
            ORDER BY TransactionDate DESC, TransactionTime DESC
        ");
        $stmt->execute([$memberID, $activeYear['YearID']]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        foreach ($transactions as $trans) {
            $totalPaid += $trans['CollectionAmount'];
            $totalReceived += $trans['DistributedAmount'];
            $totalInterest += $trans['InterestAmount'];
        }
        
        // Current Due/Net Balance = (Total Received/Distributed + Total Interest) - Total Paid/Collected
        $currentDue = ($totalReceived + $totalInterest) - $totalPaid;
    } catch (Exception $e) {
        // Tables might not exist yet
    }
}

// Get monthly summary
$monthlySummary = [];
if ($activeYear) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM monthly_summaries 
            WHERE MemberID = ? AND YearID = ?
            ORDER BY MonthYear DESC
        ");
        $stmt->execute([$memberID, $activeYear['YearID']]);
        $monthlySummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist yet
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Payments - <?php echo htmlspecialchars($member['MemberName'] ?? 'Member'); ?></title>
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
        
        .container { max-width: 1400px; margin: 0 auto; }
        
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
        
        .header-actions { display: flex; gap: 12px; }
        
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
        
        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .year-banner {
            background: linear-gradient(135deg, #667eea, #764ba2);
            padding: 20px 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            color: white;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }
        
        .year-banner h3 {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .year-banner h2 {
            font-size: 28px;
            margin: 0;
        }
        
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
        .stat-card.green { border-color: #28a745; }
        .stat-card.orange { border-color: #ffc107; }
        .stat-card.blue { border-color: #667eea; }
        .stat-card.red { border-color: #dc3545; }
        
        .content-section {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            margin-bottom: 25px;
        }
        
        .content-section h2 {
            margin-bottom: 20px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
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
            font-size: 14px;
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
        
        .badge-paid { background: #d4edda; color: #155724; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-partial { background: #d1ecf1; color: #0c5460; }
        .badge-overdue { background: #f8d7da; color: #721c24; }
        
        .badge-collection { background: #d4edda; color: #155724; }
        .badge-distribution { background: #fff3cd; color: #856404; }
        
        .amount-positive { color: #28a745; font-weight: 600; }
        .amount-negative { color: #dc3545; font-weight: 600; }
        
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
            table { font-size: 12px; }
            th, td { padding: 8px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-wallet"></i> My Payments</h1>
            <div class="header-actions">
                <a href="dashboard-new.php" class="btn btn-secondary">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="../auth/logout.php" class="btn btn-secondary">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <?php if ($activeYear): ?>
        <div class="year-banner">
            <h3>Current Academic Year</h3>
            <h2><?php echo htmlspecialchars($activeYear['YearName']); ?></h2>
            <p style="margin-top: 10px; opacity: 0.9;">
                Interest Rate: <strong><?php echo htmlspecialchars($activeYear['InterestRate']); ?>%</strong> per ₹1,000
            </p>
        </div>

        <!-- Payment Statistics -->
        <div class="stats-grid">
            <div class="stat-card green">
                <h3><i class="fas fa-rupee-sign"></i> Total Paid</h3>
                <div class="stat-value">₹<?php echo number_format($totalPaid, 2); ?></div>
            </div>
            <div class="stat-card orange">
                <h3><i class="fas fa-hand-holding-usd"></i> Total Received</h3>
                <div class="stat-value">₹<?php echo number_format($totalReceived, 2); ?></div>
            </div>
            <div class="stat-card blue">
                <h3><i class="fas fa-percentage"></i> Total Interest</h3>
                <div class="stat-value">₹<?php echo number_format($totalInterest, 2); ?></div>
            </div>
            <div class="stat-card <?php echo ($currentDue > 0) ? 'red' : 'green'; ?>">
                <h3><i class="fas fa-balance-scale"></i> Current Due</h3>
                <div class="stat-value">₹<?php echo number_format(abs($currentDue), 2); ?></div>
            </div>
        </div>

        <!-- Monthly Summary -->
        <?php if (!empty($monthlySummary)): ?>
        <div class="content-section">
            <h2><i class="fas fa-calendar-check"></i> Monthly Summary</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Collected</th>
                            <th>Distributed</th>
                            <th>Interest</th>
                            <th>Outstanding</th>
                            <th>Status</th>
                            <th>Last Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlySummary as $summary): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($summary['MonthName']); ?></strong></td>
                            <td class="amount-positive">₹<?php echo number_format($summary['TotalCollected'], 2); ?></td>
                            <td class="amount-negative">₹<?php echo number_format($summary['TotalDistributed'], 2); ?></td>
                            <td>₹<?php echo number_format($summary['TotalInterest'], 2); ?></td>
                            <td>₹<?php echo number_format($summary['OutstandingBalance'], 2); ?></td>
                            <td>
                                <span class="badge badge-<?php echo strtolower($summary['PaymentStatus']); ?>">
                                    <?php echo htmlspecialchars($summary['PaymentStatus']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $summary['LastPaymentDate'] ? date('d M Y', strtotime($summary['LastPaymentDate'])) : '-'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Transaction History -->
        <div class="content-section">
            <h2><i class="fas fa-history"></i> Transaction History</h2>
            
            <?php if (empty($transactions)): ?>
                <div class="no-data">
                    <i class="fas fa-inbox"></i>
                    <h3>No Transactions Yet</h3>
                    <p>Your payment transactions will appear here.</p>
                </div>
            <?php else: ?>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Month</th>
                            <th>Type</th>
                            <th>Collection</th>
                            <th>Distribution</th>
                            <th>Interest</th>
                            <th>Next Due</th>
                            <th>Payment Mode</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $trans): ?>
                        <tr>
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
                            <td><?php echo htmlspecialchars($trans['MonthName']); ?></td>
                            <td>
                                <span class="badge badge-<?php echo strtolower($trans['TransactionType']); ?>">
                                    <?php echo htmlspecialchars($trans['TransactionType']); ?>
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
                                <span class="badge badge-<?php echo strtolower($trans['PaymentMode']); ?>">
                                    <?php echo htmlspecialchars($trans['PaymentMode']); ?>
                                </span>
                            </td>
                            <td>
                                <small><?php echo htmlspecialchars($trans['Remarks'] ?? '-'); ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <div class="content-section">
            <div class="no-data">
                <i class="fas fa-info-circle"></i>
                <h3>No Active Academic Year</h3>
                <p>There is currently no active academic year. Please contact the administrator.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
