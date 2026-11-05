<?php
/**
 * Delete Event Transaction
 * Professional page to confirm and delete an event transaction with enhanced warning
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../session.php';
require_once '../../../config/config.php';
require_once '../../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Get transaction ID from URL
$txnId = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$txnId) {
    header('Location: event-transactions.php?error=Invalid+transaction');
    exit;
}

// Fetch transaction details
try {
    $stmt = $pdo->prepare("SELECT et.*, e.EventName FROM event_transactions et JOIN events e ON et.EventID = e.EventID WHERE et.TransactionID = ?");
    $stmt->execute([$txnId]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$transaction) {
        header('Location: event-transactions.php?error=Transaction+not+found');
        exit;
    }
} catch (Exception $e) {
    header('Location: event-transactions.php?error=' . urlencode($e->getMessage()));
    exit;
}

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        $pdo->beginTransaction();

        // Reverse event totals
        if ($transaction['TransactionType'] === 'Collection') {
            $pdo->prepare("UPDATE events SET TotalCollection = TotalCollection - ?, EventBalance = EventBalance - ?, UpdatedAt = NOW() WHERE EventID = ?")
                ->execute([$transaction['Amount'], $transaction['Amount'], $transaction['EventID']]);
        } else {
            $pdo->prepare("UPDATE events SET TotalExpense = TotalExpense - ?, EventBalance = EventBalance + ?, UpdatedAt = NOW() WHERE EventID = ?")
                ->execute([$transaction['Amount'], $transaction['Amount'], $transaction['EventID']]);
        }

        // Delete the transaction
        $pdo->prepare("DELETE FROM event_transactions WHERE TransactionID = ?")->execute([$txnId]);

        $pdo->commit();

        // Redirect immediately
        header("Location: event-transactions.php?success=deleted");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Error deleting transaction: ' . htmlspecialchars($e->getMessage());
        $messageType = 'error';
    }
}

$superadmin_name = $_SESSION['superadmin_name'] ?? 'Administrator';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Event Transaction - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../shared-styles.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            padding: 0 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .navbar-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
        }

        .navbar-brand {
            color: white;
            font-size: 22px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .navbar-user {
            display: flex;
            align-items: center;
            gap: 20px;
            color: white;
        }

        .user-name {
            font-weight: 600;
            font-size: 14px;
        }

        .user-role {
            font-size: 12px;
            opacity: 0.8;
        }

        .container {
            max-width: 800px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            color: white;
        }

        .page-header h1 {
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }

        .page-header p {
            opacity: 0.9;
            font-size: 14px;
        }

        .form-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .alert-error {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15), rgba(239, 68, 68, 0.05));
            color: #dc2626;
            border-left: 4px solid #ef4444;
        }

        .alert i {
            font-size: 18px;
            flex-shrink: 0;
        }

        .warning-box {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(239, 68, 68, 0.08) 100%);
            border-left: 4px solid #ef4444;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .warning-box h3 {
            margin: 0 0 12px;
            color: #dc2626;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .warning-box p {
            margin: 0;
            color: #991b1b;
            font-size: 14px;
            line-height: 1.6;
        }

        .transaction-details {
            background: #f9fafb;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 24px;
            border-left: 4px solid #ef4444;
        }

        .transaction-details h4 {
            margin: 0 0 16px;
            color: #1f2937;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 12px;
        }

        .detail-row:last-child {
            margin-bottom: 0;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 4px;
            text-transform: uppercase;
        }

        .detail-value {
            font-size: 15px;
            color: #111827;
            font-weight: 500;
        }

        .amount-display {
            font-size: 18px;
            font-weight: 700;
        }

        .amount-display.collection {
            color: #10b981;
        }

        .amount-display.expense {
            color: #ef4444;
        }

        .info-box {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 13px;
            color: #1e40af;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn:disabled {
            cursor: not-allowed;
            opacity: 0.7;
        }

        .btn-cancel {
            background: #e5e7eb;
            color: #374151;
            flex: 1;
        }

        .btn-cancel:hover:not(:disabled) {
            background: #d1d5db;
        }

        .btn-delete {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            flex: 1;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }

        .btn-delete:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }

        .btn-delete:active:not(:disabled) {
            transform: translateY(0);
        }

        @media (max-width: 600px) {
            .detail-row {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .page-header {
                padding: 20px;
            }

            .page-header h1 {
                font-size: 22px;
            }

            .form-card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-trash-alt"></i>
                <span>Delete Transaction</span>
            </div>
            <div class="navbar-user">
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_name); ?></div>
                    <div class="user-role">Event Manager</div>
                </div>
                <a href="event-transactions.php" class="btn-secondary btn-sm" style="background: rgba(255,255,255,0.2); color: white; padding: 8px 12px; border-radius: 6px; text-decoration: none; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h1>
                <i class="fas fa-trash-alt"></i> Delete Transaction
            </h1>
            <p>Permanently remove this transaction from the system</p>
        </div>

        <div class="form-card">
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'error' ? 'exclamation-circle' : 'check-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Warning Box -->
            <div class="warning-box">
                <h3>
                    <i class="fas fa-exclamation-triangle"></i>
                    This Action Cannot Be Undone
                </h3>
                <p>
                    You are about to permanently delete this transaction from the system. This will remove all associated data and automatically adjust your event's financial balances. Please review the transaction details below carefully before proceeding.
                </p>
            </div>

            <!-- Transaction Details -->
            <div class="transaction-details">
                <h4><i class="fas fa-receipt" style="color: #ef4444; margin-right: 8px;"></i> Transaction Details</h4>
                
                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Transaction ID</span>
                        <span class="detail-value">#<?php echo $transaction['TransactionID']; ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Event Name</span>
                        <span class="detail-value"><?php echo htmlspecialchars($transaction['EventName']); ?></span>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Type</span>
                        <span class="detail-value">
                            <span style="display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 700; <?php echo $transaction['TransactionType'] === 'Collection' ? 'background: #d1fae5; color: #065f46;' : 'background: #fee2e2; color: #991b1b;'; ?>">
                                <?php echo $transaction['TransactionType']; ?>
                            </span>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Amount</span>
                        <span class="amount-display <?php echo strtolower($transaction['TransactionType']); ?>">
                            <?php echo $transaction['TransactionType'] === 'Collection' ? '+' : '-'; ?>₹<?php echo number_format($transaction['Amount'], 2, '.', ','); ?>
                        </span>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-item">
                        <span class="detail-label">Date</span>
                        <span class="detail-value"><?php echo date('M d, Y', strtotime($transaction['TransactionDate'])); ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Payment Mode</span>
                        <span class="detail-value"><?php echo htmlspecialchars($transaction['PaymentMode']); ?></span>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-item" style="grid-column: 1 / -1;">
                        <span class="detail-label">Description</span>
                        <span class="detail-value"><?php echo htmlspecialchars($transaction['Description']); ?></span>
                    </div>
                </div>

                <?php if ($transaction['ContributorName']): ?>
                    <div class="detail-row">
                        <div class="detail-item" style="grid-column: 1 / -1;">
                            <span class="detail-label">Contributor</span>
                            <span class="detail-value"><?php echo htmlspecialchars($transaction['ContributorName']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($transaction['VendorName']): ?>
                    <div class="detail-row">
                        <div class="detail-item" style="grid-column: 1 / -1;">
                            <span class="detail-label">Vendor</span>
                            <span class="detail-value"><?php echo htmlspecialchars($transaction['VendorName']); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Info Box -->
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                Event balance (<?php echo $transaction['TransactionType'] === 'Collection' ? 'Collections' : 'Expenses'; ?>) will automatically be adjusted
            </div>

            <!-- Confirmation Form -->
            <form method="POST" action="delete-transaction.php?id=<?php echo $txnId; ?>">
                <input type="hidden" name="confirm_delete" value="1">
                
                <div class="form-actions">
                    <a href="event-transactions.php" class="btn btn-cancel">
                        <i class="fas fa-times-circle"></i>
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-delete" id="deleteBtn">
                        <i class="fas fa-trash-alt"></i>
                        <span id="deleteBtnText">Delete Permanently</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Handle form submission with loading state
        document.querySelector('form').addEventListener('submit', function(e) {
            const deleteBtn = document.getElementById('deleteBtn');
            const deleteBtnText = document.getElementById('deleteBtnText');
            
            // Show loading state
            deleteBtn.disabled = true;
            deleteBtnText.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            deleteBtn.style.opacity = '0.7';
        });
    </script>
</body>
</html>
