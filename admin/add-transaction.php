<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Check permission to add transactions
requireAdminPermission('add_transactions', 'You do not have permission to add transactions');

$errors = [];
$success = '';

// Fetch active academic year
try {
    $stmt = $pdo->query("SELECT * FROM academic_years WHERE Status = 'Active' ORDER BY StartDate DESC LIMIT 1");
    $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$activeYear) {
        $errors[] = 'No active academic year found. Please create and activate an academic year first.';
    }
} catch (Exception $e) {
    $errors[] = 'Error fetching academic year: ' . $e->getMessage();
}

// Fetch all members with their outstanding balance and yearly total
try {
    $yearID = $activeYear['YearID'] ?? 0;
    $interestType = $activeYear['InterestType'] ?? 'Monthly';
    $interestRate = floatval($activeYear['InterestRate'] ?? 0);
    $includeInterestInTotal = $activeYear['IncludeInterestInTotal'] ?? 'Yes';
    $collectionModel = $activeYear['CollectionModel'] ?? 'FullAmount';
    $standingMonthlyInterest = floatval($activeYear['StandingMonthlyInterest'] ?? 100);
    
    // Auto-convert if rate is stored as percentage (> 1)
    if ($interestRate > 1) {
        $interestRate = $interestRate / 100;
    }
    
    // Use a simpler query without subqueries that might cause duplicates
    $stmt = $pdo->query("SELECT DISTINCT MemberID, MemberName, Status FROM members ORDER BY MemberName ASC");
    $allMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Use associative array with MemberID as key to guarantee uniqueness
    $membersAssoc = [];
    foreach ($allMembers as $member) {
        $memberId = $member['MemberID'];
        
        // Skip if we've already processed this member ID
        if (isset($membersAssoc[$memberId])) {
            continue;
        }
        
        // Get outstanding balance
        $stmtBalance = $pdo->prepare("
            SELECT 
                COALESCE(SUM(DistributedAmount + InterestAmount - CollectionAmount), 0) as OutstandingBalance,
                COALESCE(SUM(DistributedAmount), 0) as TotalDistributed,
                COALESCE(SUM(InterestAmount), 0) as TotalInterest,
                COALESCE(SUM(CollectionAmount), 0) as TotalCollected,
                COUNT(CASE WHEN DistributedAmount > 0 THEN 1 END) as DistributionCount
            FROM transactions 
            WHERE MemberID = ? AND YearID = ?
        ");
        $stmtBalance->execute([$memberId, $yearID]);
        $balance = $stmtBalance->fetch(PDO::FETCH_ASSOC);
        
        $member['OutstandingBalance'] = $balance['OutstandingBalance'];
        $member['TotalDistributed'] = $balance['TotalDistributed'];
        $member['TotalInterest'] = $balance['TotalInterest'];
        $member['TotalCollected'] = $balance['TotalCollected'];
        $member['DistributionCount'] = $balance['DistributionCount'];
        
        // Get last (most recent) distribution amount for this member
        $stmtLastMonth = $pdo->prepare("
            SELECT DistributedAmount 
            FROM transactions 
            WHERE MemberID = ? AND YearID = ? 
            AND DistributedAmount > 0 
            ORDER BY TransactionDate DESC, TransactionTime DESC
            LIMIT 1
        ");
        $stmtLastMonth->execute([$memberId, $yearID]);
        $lastMonthDist = $stmtLastMonth->fetch(PDO::FETCH_ASSOC);
        $member['LastMonthDistribution'] = $lastMonthDist ? floatval($lastMonthDist['DistributedAmount']) : 0;
        
        // Store in associative array with MemberID as key
        $membersAssoc[$memberId] = $member;
    }
    
    // Convert back to indexed array for rendering
    $members = array_values($membersAssoc);
    
    // Simply show outstanding balance - no automatic calculations
    foreach ($members as &$member) {
        // Just show the outstanding balance, superadmin sets custom amounts
        $member['YearlyTotal'] = $member['OutstandingBalance'];
    }
    unset($member); // CRITICAL: Destroy the reference to prevent bugs!
    
    // IMPORTANT: Filter members to show only those with net balance > 0 for add transaction form
    // Note: manage-transactions.php filter shows ALL members regardless of balance for search/filtering
    $members = array_filter($members, function($member) {
        return floatval($member['OutstandingBalance']) > 0;
    });
    
    // Re-index array after filtering
    $members = array_values($members);
} catch (Exception $e) {
    $members = [];
    $errors[] = 'Error fetching members: ' . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $yearID = intval($_POST['year_id'] ?? 0);
    $memberID = intval($_POST['member_id'] ?? 0);
    $transactionDate = trim($_POST['transaction_date'] ?? date('Y-m-d'));
    $transactionTime = trim($_POST['transaction_time'] ?? date('H:i:s'));
    $monthName = trim($_POST['month_name'] ?? '');
    $collectionAmount = floatval($_POST['collection_amount'] ?? 0);
    $distributedAmount = floatval($_POST['distributed_amount'] ?? 0);
    $paymentMode = trim($_POST['payment_mode'] ?? 'UPI');
    $transactionType = trim($_POST['transaction_type'] ?? 'Collection');
    $referenceNumber = trim($_POST['reference_number'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $nextMonthDue = floatval($_POST['next_month_due'] ?? 0);
    
    // Interest amount should be entered manually by superadmin only
    // Admin can only work with existing amounts, not calculate new ones
    $interestAmount = floatval($_POST['interest_amount'] ?? 0);
    
    // Validation
    if ($yearID <= 0) $errors[] = 'Invalid academic year';
    if ($memberID <= 0) $errors[] = 'Please select a member';
    if (empty($monthName)) $errors[] = 'Month name is required';
    if ($collectionAmount == 0 && $distributedAmount == 0) $errors[] = 'Either collection or distributed amount must be provided';
    
    if (empty($errors)) {
        try {
            // Extract month-year from transaction date for TransactionMonth field
            $transactionMonth = date('Y-m', strtotime($transactionDate));
            
            $stmt = $pdo->prepare("
                INSERT INTO transactions 
                (YearID, MemberID, TransactionDate, TransactionTime, TransactionMonth, MonthName, CollectionAmount, 
                DistributedAmount, InterestAmount, NextMonthDue, PaymentMode, 
                ReferenceNumber, Remarks, CreatedBy) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $yearID, $memberID, $transactionDate, $transactionTime, $transactionMonth, $monthName,
                $collectionAmount, $distributedAmount, $interestAmount, $nextMonthDue,
                $paymentMode, $referenceNumber, $remarks, $_SESSION['admin_id']
            ]);
            
            $transactionID = $pdo->lastInsertId();
            
            // Get member name for audit log
            $memberStmt = $pdo->prepare("SELECT MemberName FROM members WHERE MemberID = ?");
            $memberStmt->execute([$memberID]);
            $memberName = $memberStmt->fetchColumn();
            
            // Create audit log for transaction creation
            createAuditLog(
                $_SESSION['admin_id'],
                'Admin',
                'Transactions',
                'Create Transaction',
                "Created transaction for $memberName: Collection=₹$collectionAmount, Distribution=₹$distributedAmount, Month=$monthName",
                'transactions',
                $transactionID,
                null,
                json_encode([
                    'MemberID' => $memberID,
                    'MemberName' => $memberName,
                    'MonthName' => $monthName,
                    'CollectionAmount' => $collectionAmount,
                    'DistributedAmount' => $distributedAmount,
                    'PaymentMode' => $paymentMode
                ]),
                'Medium'
            );
            
            $success = 'Transaction added successfully!';
            
            // Update monthly summary
            updateMonthlySummary($pdo, $yearID, $memberID, $monthName, date('Y-m', strtotime($transactionDate)));
            
            // Optionally redirect
            // header("refresh:2;url=manage-transactions.php");
        } catch (Exception $e) {
            $errors[] = 'Error adding transaction: ' . $e->getMessage();
        }
    }
}

function updateMonthlySummary($pdo, $yearID, $memberID, $monthName, $monthYear) {
    try {
        // Calculate totals from transactions
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CollectionAmount), 0) as TotalCollected,
                COALESCE(SUM(DistributedAmount), 0) as TotalDistributed,
                COALESCE(SUM(InterestAmount), 0) as TotalInterest,
                MAX(TransactionDate) as LastPaymentDate
            FROM transactions
            WHERE YearID = ? AND MemberID = ? AND MonthName = ?
        ");
        $stmt->execute([$yearID, $memberID, $monthName]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $outstandingBalance = $summary['TotalDistributed'] + $summary['TotalInterest'] - $summary['TotalCollected'];
        $paymentStatus = 'Pending';
        if ($summary['TotalCollected'] >= ($summary['TotalDistributed'] + $summary['TotalInterest'])) {
            $paymentStatus = 'Paid';
        } elseif ($summary['TotalCollected'] > 0) {
            $paymentStatus = 'Partial';
        }
        
        // Insert or update monthly summary
        $stmt = $pdo->prepare("
            INSERT INTO monthly_summaries 
            (YearID, MemberID, MonthName, MonthYear, TotalCollected, TotalDistributed, 
            TotalInterest, OutstandingBalance, PaymentStatus, LastPaymentDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            TotalCollected = VALUES(TotalCollected),
            TotalDistributed = VALUES(TotalDistributed),
            TotalInterest = VALUES(TotalInterest),
            OutstandingBalance = VALUES(OutstandingBalance),
            PaymentStatus = VALUES(PaymentStatus),
            LastPaymentDate = VALUES(LastPaymentDate)
        ");
        
        $stmt->execute([
            $yearID, $memberID, $monthName, $monthYear,
            $summary['TotalCollected'], $summary['TotalDistributed'],
            $summary['TotalInterest'], $outstandingBalance,
            $paymentStatus, $summary['LastPaymentDate']
        ]);
        
        // Recalculate and update net balance for the member
        require_once '../includes/net-balance-helper.php';
        $newNetBalance = calculateMemberNetBalance($memberID, $yearID);
        
        // Update the member_net_balance table
        $balanceStmt = $pdo->prepare("
            INSERT INTO member_net_balance (MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy)
            VALUES (?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE 
                NetBalance = VALUES(NetBalance),
                LastUpdatedAt = NOW(),
                UpdatedBy = VALUES(UpdatedBy)
        ");
        $balanceStmt->execute([
            $memberID,
            $yearID,
            $newNetBalance,
            $_SESSION['admin_id'] ?? $_SESSION['superadmin_id'] ?? 0
        ]);
    } catch (Exception $e) {
        // Log error but don't fail the transaction
        error_log('Error updating monthly summary: ' . $e->getMessage());
    }
}

$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Transaction - Admin</title>
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
        
        .container { max-width: 1000px; margin: 0 auto; }
        
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
        
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6); }
        
        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn-secondary:hover { background: #f0f4ff; }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        
        .alert-success { background: #d4edda; color: #155724; border-left: 5px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 5px solid #dc3545; }
        
        .form-container {
            background: white;
            padding: 35px;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        }
        
        .form-intro {
            background: linear-gradient(135deg, #e0f7fa 0%, #e1bee7 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 5px solid #667eea;
        }
        
        .form-intro h3 { color: #333; margin-bottom: 8px; font-size: 18px; }
        .form-intro p { color: #666; font-size: 14px; }
        
        .year-info {
            background: linear-gradient(135deg, #fff9e6 0%, #ffe0b2 100%);
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 5px solid #ffa801;
        }
        
        .year-info strong { color: #333; }
        .year-info .interest-rate { color: #d84315; font-weight: bold; }
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .required { color: #dc3545; }
        
        .form-input, .form-select, .form-textarea {
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .interest-calculator {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-top: 15px;
        }
        
        .interest-calculator h4 {
            color: #333;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .calc-result {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .calc-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .calc-item label { font-size: 12px; color: #666; display: block; margin-bottom: 5px; }
        .calc-item .value { font-size: 20px; font-weight: bold; color: #333; }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e0e0e0;
        }
        
        .payment-mode-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 10px;
        }
        
        .payment-mode-option {
            position: relative;
        }
        
        .payment-mode-option input[type="radio"] {
            position: absolute;
            opacity: 0;
        }
        
        .payment-mode-option label {
            display: block;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 13px;
        }
        
        .payment-mode-option input[type="radio"]:checked + label {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-color: #667eea;
        }
        
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .calc-result { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-plus-circle"></i> Add New Transaction</h1>
            <div class="header-actions">
                <a href="manage-transactions.php" class="btn btn-secondary">
                    <i class="fas fa-list"></i> View All Transactions
                </a>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
        </div>

        <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php foreach ($errors as $error): ?>
                <div><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($activeYear): ?>
        <div class="year-info">
            <strong><i class="fas fa-calendar-check"></i> Active Year:</strong> <?php echo htmlspecialchars($activeYear['YearName']); ?> 
            | <strong>Interest Rate:</strong> <span class="interest-rate"><?php echo htmlspecialchars($activeYear['InterestRate']); ?> 
            <?php 
                $interestType = $activeYear['InterestType'] ?? 'Monthly';
                echo '(' . htmlspecialchars($interestType) . ')'; 
            ?></span>
            <small style="opacity: 0.8;"> • All amounts set by Super Admin</small>
        </div>

        <div class="form-container">
            <div class="alert" style="background: #fff3cd; border-left: 4px solid #ff9800; color: #856404; padding: 20px; margin-bottom: 25px; border-radius: 8px;">
                <h3 style="margin-bottom: 10px;"><i class="fas fa-exclamation-triangle"></i> Important Notice</h3>
                <p style="margin-bottom: 10px;"><strong>Admin users can only edit existing transactions.</strong></p>
                <p style="margin-bottom: 10px;">Only Super Admin can add new transactions with custom amounts. All payment calculations have been removed - amounts must be set manually by Super Admin.</p>
                <p style="margin: 0;">To add a new transaction, please contact your Super Admin or <a href="manage-transactions.php" style="color: #667eea; text-decoration: underline;">edit an existing transaction</a>.</p>
            </div>
            
            <div class="form-intro" style="opacity: 0.6; pointer-events: none;">
                <h3><i class="fas fa-info-circle"></i> Transaction Information (Read-Only for Admin)</h3>
                <p>Only Super Admin can add new transactions. Admin can edit existing ones.</p>
            </div>

            <form method="POST" id="transactionForm" style="opacity: 0.6; pointer-events: none;" disabled>
                <input type="hidden" name="year_id" value="<?php echo $activeYear['YearID']; ?>">
                <input type="hidden" name="next_month_due" id="nextMonthDueInput" value="0">
                
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-user"></i> Member & Date Information
                    </h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Select Member <span class="required">*</span></label>
                            <select name="member_id" id="memberID" class="form-select" required>
                                <option value="">-- Select Member --</option>
                                <?php 
                                $renderedCount = 0;
                                foreach ($members as $member): 
                                    $renderedCount++;
                                ?>
                                <option value="<?php echo $member['MemberID']; ?>" 
                                        data-outstanding="<?php echo $member['OutstandingBalance']; ?>"
                                        data-yearly-total="<?php echo $member['YearlyTotal']; ?>"
                                        data-total-distributed="<?php echo $member['TotalDistributed']; ?>"
                                        data-total-interest="<?php echo $member['TotalInterest']; ?>"
                                        data-total-collected="<?php echo $member['TotalCollected']; ?>"
                                        data-last-month-distributed="<?php echo $member['LastMonthDistribution']; ?>">
                                    <?php echo htmlspecialchars($member['MemberName']); ?> 
                                    (<?php echo htmlspecialchars($member['Status']); ?>) 
                                    [ID: <?php echo $member['MemberID']; ?>]
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small id="memberDueInfo" style="color: #667eea; font-weight: 600; display: none; margin-top: 5px;"></small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                Month <span class="required">*</span>
                                <button type="button" onclick="toggleDefaultDate()" id="toggleDateBtn" class="btn btn-sm" style="margin-left: 10px; padding: 4px 12px; font-size: 12px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                    <i class="fas fa-calendar-alt"></i> <span id="toggleDateText">Use System Date</span>
                                </button>
                            </label>
                            <select name="month_name" id="monthName" class="form-select" required>
                                <option value="">-- Select Month --</option>
                                <?php 
                                $defaultMonth = $activeYear['DefaultMonth'] ?? '';
                                foreach ($months as $month): 
                                    $selected = '';
                                    if ($defaultMonth && $month === $defaultMonth) {
                                        $selected = 'selected';
                                    } elseif (!$defaultMonth && date('F') === $month) {
                                        $selected = 'selected';
                                    }
                                ?>
                                <option value="<?php echo $month; ?>" <?php echo $selected; ?>>
                                    <?php echo $month; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Transaction Date <span class="required">*</span></label>
                            <?php
                            $defaultTransactionDate = $activeYear['DefaultTransactionDate'] ?? null;
                            $defaultDate = '';
                            
                            // Debug: Show what we got from database
                            // echo "<!-- DEBUG: DefaultTransactionDate from DB: " . var_export($defaultTransactionDate, true) . " -->";
                            
                            if (!empty($defaultTransactionDate) && preg_match('/^\d{2}-\d{2}-\d{4}$/', $defaultTransactionDate)) {
                                // Use default date from academic year (DD-MM-YYYY format)
                                // Convert DD-MM-YYYY to YYYY-MM-DD for HTML5 date input
                                list($day, $month, $year) = explode('-', $defaultTransactionDate);
                                $defaultDate = sprintf('%s-%s-%s', $year, $month, $day);
                                // echo "<!-- DEBUG: Using default date: $defaultDate -->";
                            } else {
                                // Use system date
                                $defaultDate = date('Y-m-d');
                                // echo "<!-- DEBUG: Using system date: $defaultDate -->";
                            }
                            ?>
                            <input type="date" name="transaction_date" id="transactionDate" class="form-input" value="<?php echo $defaultDate; ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Transaction Time <span class="required">*</span></label>
                            <input type="time" name="transaction_time" class="form-input" value="<?php echo date('H:i'); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-money-bill-wave"></i> Transaction Details
                    </h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Transaction Type <span class="required">*</span></label>
                            <select name="transaction_type" id="transactionType" class="form-select" required>
                                <option value="Collection">Collection (Member Payment)</option>
                                <option value="Distribution">Distribution (Amount Given)</option>
                                <option value="Interest">Interest Payment</option>
                                <option value="Penalty">Penalty</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Collection Amount (₹)</label>
                            <input type="number" name="collection_amount" id="collectionAmount" class="form-input" step="0.01" value="0.00" min="0">
                            <small style="color: #666; margin-top: 5px;">Amount received from member</small>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Distributed Amount (₹)</label>
                            <input type="number" name="distributed_amount" id="distributedAmount" class="form-input" step="0.01" value="0.00" min="0">
                            <small style="color: #666; margin-top: 5px;">Amount given to member</small>
                        </div>
                    </div>
                    
                    <!-- Interest Calculator -->
                    <div class="interest-calculator">
                        <h4><i class="fas fa-calculator"></i> Interest Calculator</h4>
                        <p style="color: #666; font-size: 14px; margin-bottom: 10px;">
                            Based on distributed amount, interest will be calculated automatically.
                        </p>
                        <div class="calc-result">
                            <div class="calc-item">
                                <label>Interest on New Distribution</label>
                                <div class="value" id="calculatedInterest">₹0.00</div>
                                <small style="color: #999; font-size: 11px;">Interest for amount given to member</small>
                            </div>
                            <div class="calc-item">
                                <label>Standing Monthly Interest</label>
                                <div class="value" id="standingInterest">₹0.00</div>
                                <small style="color: #999; font-size: 11px;">From previous distributions</small>
                            </div>
                            <div class="calc-item">
                                <label>Next Month Due</label>
                                <div class="value" id="nextMonthDue">₹0.00</div>
                                <small style="color: #999; font-size: 11px;">Total interest due next month</small>
                            </div>
                            <div class="calc-item" style="background: #f0f4ff; border: 2px solid #667eea; padding: 12px; border-radius: 6px;">
                                <label style="color: #667eea; font-weight: 600;">Total Amount to Collect</label>
                                <div class="value" id="totalAmount" style="color: #667eea; font-size: 24px; font-weight: 700;">₹0.00</div>
                                <small style="color: #667eea; font-size: 11px;">Principal + All Interest</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-credit-card"></i> Payment Mode
                    </h3>
                    <div class="payment-mode-grid">
                        <div class="payment-mode-option">
                            <input type="radio" name="payment_mode" value="Cash" id="modeCash">
                            <label for="modeCash"><i class="fas fa-money-bill"></i> Cash</label>
                        </div>
                        <div class="payment-mode-option">
                            <input type="radio" name="payment_mode" value="Online" id="modeOnline">
                            <label for="modeOnline"><i class="fas fa-laptop"></i> Online</label>
                        </div>
                        <div class="payment-mode-option">
                            <input type="radio" name="payment_mode" value="UPI" id="modeUPI" checked>
                            <label for="modeUPI"><i class="fas fa-mobile-alt"></i> UPI</label>
                        </div>
                        <div class="payment-mode-option">
                            <input type="radio" name="payment_mode" value="Bank Transfer" id="modeBank">
                            <label for="modeBank"><i class="fas fa-university"></i> Bank</label>
                        </div>
                        <div class="payment-mode-option">
                            <input type="radio" name="payment_mode" value="Cheque" id="modeCheque">
                            <label for="modeCheque"><i class="fas fa-file-invoice"></i> Cheque</label>
                        </div>
                    </div>
                    
                    <div class="form-group" style="margin-top: 20px;">
                        <label class="form-label">Reference Number / Transaction ID</label>
                        <input type="text" name="reference_number" class="form-input" placeholder="Enter UPI ID, Transaction ID, or Cheque Number">
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-sticky-note"></i> Additional Information
                    </h3>
                    <div class="form-group">
                        <label class="form-label">Remarks / Notes</label>
                        <textarea name="remarks" class="form-textarea" placeholder="Enter any additional notes or remarks about this transaction"></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Transaction
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <script>
        let interestRate = <?php echo $activeYear ? $activeYear['InterestRate'] : 0.20; ?>;
        const interestType = '<?php echo $activeYear ? ($activeYear['InterestType'] ?? 'Monthly') : 'Monthly'; ?>';
        const collectionModel = '<?php echo $activeYear ? ($activeYear['CollectionModel'] ?? 'FullAmount') : 'FullAmount'; ?>';
        const defaultMonth = '<?php echo $activeYear ? ($activeYear['DefaultMonth'] ?? '') : ''; ?>';
        const defaultTransactionDate = '<?php echo $activeYear ? ($activeYear['DefaultTransactionDate'] ?? '') : ''; ?>'; // DD-MM-YYYY format
        const academicStartDate = '<?php echo $activeYear ? ($activeYear['StartDate'] ?? '') : ''; ?>'; // YYYY-MM-DD format
        const academicEndDate = '<?php echo $activeYear ? ($activeYear['EndDate'] ?? '') : ''; ?>'; // YYYY-MM-DD format
        const lastMonthToCollect = '<?php echo $activeYear ? ($activeYear['LastMonthToCollect'] ?? '') : ''; ?>'; // Month name (e.g., "August")
        const deferredPrincipalAmount = <?php echo $activeYear ? floatval($activeYear['DeferredPrincipalAmount'] ?? 0) : 0; ?>; // Deferred principal
        const standingMonthlyInterest = <?php echo $activeYear ? floatval($activeYear['StandingMonthlyInterest'] ?? 100) : 100; ?>; // Standing monthly interest
        
        let useDefaultDate = <?php echo ($activeYear && !empty($activeYear['DefaultTransactionDate'])) ? 'true' : 'false'; ?>;
        
        // Auto-convert if rate is stored as percentage (> 1)
        // If someone stored 20 instead of 0.20, convert it
        if (interestRate > 1) {
            interestRate = interestRate / 100;
        }
        
        // Get the first month of academic year (month number 0-11)
        const academicFirstMonth = academicStartDate ? new Date(academicStartDate).getMonth() : -1;
        
        // Check if current month is the last month of academic year
        function isLastMonth() {
            if (!academicEndDate) return false;
            
            const endDate = new Date(academicEndDate);
            const endMonth = endDate.getMonth(); // 0-11
            const endYear = endDate.getFullYear();
            
            const currentDate = new Date();
            const currentMonth = currentDate.getMonth();
            const currentYear = currentDate.getFullYear();
            
            return (endMonth === currentMonth && endYear === currentYear);
        }
        
        // Check if current month is one month before the last collection month
        function isMonthBeforeLastCollection() {
            if (!lastMonthToCollect) return false;
            
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                               'July', 'August', 'September', 'October', 'November', 'December'];
            const lastCollectionMonthIndex = monthNames.indexOf(lastMonthToCollect);
            
            if (lastCollectionMonthIndex === -1) return false;
            
            const currentDate = new Date();
            const currentMonth = currentDate.getMonth(); // 0-11
            
            // Get the month before last collection month
            const monthBefore = (lastCollectionMonthIndex === 0) ? 11 : lastCollectionMonthIndex - 1;
            
            return currentMonth === monthBefore;
        }
        
        const isLastMonthOfYear = isLastMonth();
        const shouldAddDeferredPrincipal = isMonthBeforeLastCollection();
        
        // Toggle between default and system date
        function toggleDefaultDate() {
            useDefaultDate = !useDefaultDate;
            const toggleText = document.getElementById('toggleDateText');
            const monthSelect = document.getElementById('monthName');
            const dateInput = document.getElementById('transactionDate');
            
            if (useDefaultDate && defaultTransactionDate) {
                // Use default from academic year (DD-MM-YYYY format)
                toggleText.textContent = 'Use System Date';
                
                // Parse DD-MM-YYYY format
                const dateParts = defaultTransactionDate.split('-');
                if (dateParts.length === 3) {
                    const day = dateParts[0];
                    const month = dateParts[1];
                    const year = dateParts[2];
                    
                    // Set month dropdown (convert month number to name)
                    const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                                   'July', 'August', 'September', 'October', 'November', 'December'];
                    const monthIndex = parseInt(month) - 1;
                    if (monthIndex >= 0 && monthIndex < 12) {
                        monthSelect.value = months[monthIndex];
                    }
                    
                    // Set date input (YYYY-MM-DD format for HTML5 date input)
                    dateInput.value = year + '-' + month + '-' + day;
                }
            } else {
                // Use system date
                toggleText.textContent = 'Use Default Date';
                
                const today = new Date();
                const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                               'July', 'August', 'September', 'October', 'November', 'December'];
                
                // Set month to current month
                monthSelect.value = months[today.getMonth()];
                
                // Set date to today (YYYY-MM-DD format)
                const year = today.getFullYear();
                const month = String(today.getMonth() + 1).padStart(2, '0');
                const day = String(today.getDate()).padStart(2, '0');
                dateInput.value = year + '-' + month + '-' + day;
            }
        }
        
        // Simplified - no automatic calculations
        const transactionTypeSelect = document.getElementById('transactionType');
        const memberSelect = document.getElementById('memberID');
        const memberDueInfo = document.getElementById('memberDueInfo');
        
        // Show member outstanding balance info only
        function showMemberInfo() {
            const selectedOption = memberSelect.options[memberSelect.selectedIndex];
            
            if (!memberSelect.value || !selectedOption) {
                memberDueInfo.style.display = 'none';
                return;
            }
            
            const outstandingBalance = parseFloat(selectedOption.getAttribute('data-outstanding')) || 0;
            const totalDistributed = parseFloat(selectedOption.getAttribute('data-total-distributed')) || 0;
            const totalCollected = parseFloat(selectedOption.getAttribute('data-total-collected')) || 0;
            
            memberDueInfo.style.display = 'block';
            
            if (outstandingBalance <= 0) {
                memberDueInfo.innerHTML = '<i class="fas fa-check-circle"></i> No due - Payment completed';
                memberDueInfo.style.color = '#28a745';
            } else {
                memberDueInfo.innerHTML = '<i class="fas fa-info-circle"></i> Outstanding: ₹' + outstandingBalance.toFixed(2) + 
                                        ' | Distributed: ₹' + totalDistributed.toFixed(2) + 
                                        ' | Collected: ₹' + totalCollected.toFixed(2);
                memberDueInfo.style.color = '#667eea';
            }
        }
        
        // Clear all calculation displays - admin cannot calculate, only superadmin sets amounts
        document.getElementById('calculatedInterest').textContent = '₹0.00';
        document.getElementById('standingInterest').textContent = '₹0.00';
        document.getElementById('nextMonthDue').textContent = '₹0.00';
        document.getElementById('totalAmount').textContent = '₹0.00';
        
        memberSelect.addEventListener('change', showMemberInfo);
        showMemberInfo();
    </script>
</body>
</html>