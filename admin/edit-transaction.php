<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Permission check
$canEditTransactions = true;
if (isset($_SESSION['admin_id'])) {
    try {
        $canEditTransactions = hasAdminPermission($_SESSION['admin_id'], 'edit_transactions');
    } catch (Exception $e) {
        error_log('Permission check error: ' . $e->getMessage());
        $canEditTransactions = true;
    }
}

$transactionID = isset($_GET['id']) ? intval($_GET['id']) : 0;
$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEditTransactions) {
    try {
        require_once '../includes/net-balance-helper.php';
        
        $memberID = intval($_POST['member_id']);
        $yearID = intval($_POST['year_id']);
        $transactionDate = trim($_POST['transaction_date']);
        $collectionAmount = floatval($_POST['collection_amount'] ?? 0);
        $distributedAmount = floatval($_POST['distributed_amount'] ?? 0);
        $interestAmount = floatval($_POST['interest_amount'] ?? 0);
        $paymentMode = trim($_POST['payment_mode']);
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        
        // IMPORTANT: Fetch the OLD transaction data BEFORE updating
        // This is needed to detect if YearID or MemberID changed
        $oldTransStmt = $pdo->prepare("SELECT MemberID, YearID FROM transactions WHERE TransactionID = ?");
        $oldTransStmt->execute([$transactionID]);
        $oldTransaction = $oldTransStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$oldTransaction) {
            throw new Exception('Original transaction not found');
        }
        
        $oldMemberID = intval($oldTransaction['MemberID']);
        $oldYearID = intval($oldTransaction['YearID']);
        
        // Calculate month from date
        $monthNum = date('n', strtotime($transactionDate));
        $monthName = date('F', strtotime($transactionDate));
        
        // Update the transaction
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET 
                MemberID = ?,
                YearID = ?,
                TransactionDate = ?,
                TransactionMonth = ?,
                MonthName = ?,
                CollectionAmount = ?,
                DistributedAmount = ?,
                InterestAmount = ?,
                PaymentMode = ?,
                ReferenceNumber = ?,
                Remarks = ?,
                UpdatedAt = NOW()
            WHERE TransactionID = ?
        ");
        
        $stmt->execute([
            $memberID,
            $yearID,
            $transactionDate,
            $monthNum,
            $monthName,
            $collectionAmount,
            $distributedAmount,
            $interestAmount,
            $paymentMode,
            $referenceNumber,
            $remarks,
            $transactionID
        ]);
        
        // CRITICAL FIX: Recalculate net balance for AFFECTED members and years
        // If MemberID changed: recalculate for both old and new member
        // If YearID changed: recalculate for both old and new year
        
        $membersToRecalculate = [];
        $yearsToRecalculate = [];
        
        // Add the new member/year combination
        $membersToRecalculate[$memberID] = true;
        $yearsToRecalculate[$yearID] = true;
        
        // If member changed, also recalculate for old member
        if ($oldMemberID !== $memberID) {
            $membersToRecalculate[$oldMemberID] = true;
        }
        
        // If year changed, also recalculate for old year
        if ($oldYearID !== $yearID) {
            $yearsToRecalculate[$oldYearID] = true;
        }
        
        // Handle each combination of member and year that needs recalculation
        foreach ($membersToRecalculate as $mID => $true) {
            foreach ($yearsToRecalculate as $yID => $true) {
                $newNetBalance = calculateMemberNetBalance($mID, $yID);
                
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
                    $mID,
                    $yID,
                    $newNetBalance,
                    $_SESSION['admin_id'] ?? 0
                ]);
            }
        }
        
        $success = 'Transaction updated successfully! Net balance recalculated for affected members and years.';
        
        // Redirect after 2 seconds
        header("refresh:2;url=manage-transactions.php");
        
    } catch (Exception $e) {
        $error = 'Error updating transaction: ' . $e->getMessage();
    }
}

// Fetch transaction details
try {
    $stmt = $pdo->prepare("
        SELECT 
            t.*,
            m.MemberName,
            ay.YearName
        FROM transactions t
        JOIN members m ON t.MemberID = m.MemberID
        JOIN academic_years ay ON t.YearID = ay.YearID
        WHERE t.TransactionID = ?
    ");
    $stmt->execute([$transactionID]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        header('Location: manage-transactions.php?error=Transaction not found');
        exit;
    }
} catch (Exception $e) {
    header('Location: manage-transactions.php?error=' . urlencode($e->getMessage()));
    exit;
}

// Fetch members for dropdown
$stmt = $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberName ASC");
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch years for dropdown
$stmt = $pdo->query("SELECT * FROM academic_years ORDER BY StartDate DESC");
$years = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Transaction - Admin Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f3f4f6;
            margin: 0;
            padding: 0;
        }
        
        .container {
            margin-left: 280px;
            padding: 30px;
            max-width: 1000px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
        }
        
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
        
        .info-section {
            background: #f9fafb;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #6b7280;
        }
        
        .info-value {
            color: #111827;
        }
        
        @media (max-width: 768px) {
            .container {
                margin-left: 0;
                padding: 15px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-edit"></i>
                Edit Transaction #<?php echo $transactionID; ?>
            </h1>
            <a href="manage-transactions.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Transactions
            </a>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="info-section">
                <h3 style="margin: 0 0 15px 0; color: #111827;">Transaction Information</h3>
                <div class="info-row">
                    <span class="info-label">Transaction ID:</span>
                    <span class="info-value">#<?php echo $transaction['TransactionID']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Created By:</span>
                    <span class="info-value"><?php echo htmlspecialchars($transaction['CreatedBy'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Created At:</span>
                    <span class="info-value"><?php echo date('d M Y, h:i A', strtotime($transaction['CreatedAt'])); ?></span>
                </div>
                <?php if ($transaction['UpdatedAt']): ?>
                <div class="info-row">
                    <span class="info-label">Last Updated:</span>
                    <span class="info-value"><?php echo date('d M Y, h:i A', strtotime($transaction['UpdatedAt'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Member *</label>
                        <select name="member_id" required>
                            <option value="">Select Member</option>
                            <?php foreach ($members as $member): ?>
                                <option value="<?php echo $member['MemberID']; ?>" 
                                    <?php echo ($member['MemberID'] == $transaction['MemberID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($member['MemberName']); ?> (ID: <?php echo $member['MemberID']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Academic Year *</label>
                        <select name="year_id" required>
                            <option value="">Select Year</option>
                            <?php foreach ($years as $year): ?>
                                <option value="<?php echo $year['YearID']; ?>"
                                    <?php echo ($year['YearID'] == $transaction['YearID']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['YearName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> Transaction Date *</label>
                        <input type="date" name="transaction_date" 
                            value="<?php echo $transaction['TransactionDate']; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-credit-card"></i> Payment Mode *</label>
                        <select name="payment_mode" required>
                            <option value="">Select Mode</option>
                            <option value="Cash" <?php echo ($transaction['PaymentMode'] == 'Cash') ? 'selected' : ''; ?>>Cash</option>
                            <option value="Online" <?php echo ($transaction['PaymentMode'] == 'Online') ? 'selected' : ''; ?>>Online</option>
                            <option value="Cheque" <?php echo ($transaction['PaymentMode'] == 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                            <option value="UPI" <?php echo ($transaction['PaymentMode'] == 'UPI') ? 'selected' : ''; ?>>UPI</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-arrow-down"></i> Collection Amount (₹)</label>
                        <input type="number" step="0.01" name="collection_amount" 
                            value="<?php echo $transaction['CollectionAmount']; ?>" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-arrow-up"></i> Distribution Amount (₹)</label>
                        <input type="number" step="0.01" name="distributed_amount" 
                            value="<?php echo $transaction['DistributedAmount']; ?>" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-percent"></i> Interest Amount (₹)</label>
                        <input type="number" step="0.01" name="interest_amount" 
                            value="<?php echo $transaction['InterestAmount']; ?>" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Reference Number</label>
                        <input type="text" name="reference_number" 
                            value="<?php echo htmlspecialchars($transaction['ReferenceNumber'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group full-width">
                        <label><i class="fas fa-comment"></i> Remarks</label>
                        <textarea name="remarks"><?php echo htmlspecialchars($transaction['Remarks'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <div class="btn-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Transaction
                    </button>
                    <a href="manage-transactions.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
