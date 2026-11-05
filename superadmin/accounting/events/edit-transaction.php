<?php
/**
 * Edit Event Transaction
 * Professional page to edit an event transaction with improved styling
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../session.php';
require_once '../../../config/config.php';
require_once '../../../includes/logo-helper.php';
require_once '../../../includes/google-drive-helper.php';

$proofManager = new ProofAttachmentManager($pdo);
try {
    $gDriveSettings = $pdo->query("SELECT * FROM google_drive_settings WHERE IsActive = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $isGDriveEnabled = !empty($gDriveSettings);
} catch (Exception $e) {
    $isGDriveEnabled = false;
}

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

// Expense categories
$expenseCategories = ['Venue', 'Catering', 'Decoration', 'Sound & Lighting', 'Printing', 'Transportation', 'Gifts', 'Security', 'Photography', 'Miscellaneous'];

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newType = $_POST['transaction_type'] ?? '';
    $newDate = $_POST['transaction_date'] ?? '';
    $newDescription = trim($_POST['description'] ?? '');
    $newAmount = floatval($_POST['amount'] ?? 0);
    $newPaymentMode = $_POST['payment_mode'] ?? 'Cash';
    $newContributorName = isset($_POST['contributor_name']) ? trim($_POST['contributor_name']) : null;
    $newVendorName = isset($_POST['vendor_name']) ? trim($_POST['vendor_name']) : null;
    $newCategory = isset($_POST['category']) ? $_POST['category'] : null;
    $newRemarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;
    
    // Validation
    if (!$newDescription || $newAmount <= 0 || !$newDate) {
        $message = 'Please fill in all required fields.';
        $messageType = 'error';
    } elseif ($newType === 'Collection' && empty($newContributorName)) {
        $message = 'Contributor name is required for collections.';
        $messageType = 'error';
    } elseif ($newType === 'Expense' && empty($newVendorName)) {
        $message = 'Vendor name is required for expenses.';
        $messageType = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Reverse old event totals
            if ($transaction['TransactionType'] === 'Collection') {
                $pdo->prepare("UPDATE events SET TotalCollection = TotalCollection - ?, EventBalance = EventBalance - ?, UpdatedAt = NOW() WHERE EventID = ?")
                    ->execute([$transaction['Amount'], $transaction['Amount'], $transaction['EventID']]);
            } else {
                $pdo->prepare("UPDATE events SET TotalExpense = TotalExpense - ?, EventBalance = EventBalance + ?, UpdatedAt = NOW() WHERE EventID = ?")
                    ->execute([$transaction['Amount'], $transaction['Amount'], $transaction['EventID']]);
            }
            
            // Update the transaction
            $updateStmt = $pdo->prepare("UPDATE event_transactions SET 
                TransactionType = ?, TransactionDate = ?, Description = ?, Amount = ?,
                PaymentMode = ?, ContributorName = ?, VendorName = ?, Category = ?, Remarks = ?, UpdatedAt = NOW()
                WHERE TransactionID = ?");
            $updateStmt->execute([
                $newType, $newDate, $newDescription, $newAmount,
                $newPaymentMode, $newContributorName ?: null, $newVendorName ?: null, $newCategory ?: null, $newRemarks ?: null,
                $txnId
            ]);
            
            // Apply new event totals
            if ($newType === 'Collection') {
                $pdo->prepare("UPDATE events SET TotalCollection = TotalCollection + ?, EventBalance = EventBalance + ?, UpdatedAt = NOW() WHERE EventID = ?")
                    ->execute([$newAmount, $newAmount, $transaction['EventID']]);
            } else {
                $pdo->prepare("UPDATE events SET TotalExpense = TotalExpense + ?, EventBalance = EventBalance - ?, UpdatedAt = NOW() WHERE EventID = ?")
                    ->execute([$newAmount, $newAmount, $transaction['EventID']]);
            }
            
            // Handle proof file uploads
            if (!empty($_FILES['proof_files']['name'][0])) {
                $proofResult = $proofManager->uploadProofs($_FILES['proof_files'], 'event_transaction', $txnId, $superadmin_id);
            }

            $pdo->commit();

            // Proof files in Google Drive follow the new description / date
            try { $proofManager->renameDriveFiles('event_transaction', $txnId); } catch (Exception $e) { error_log('Drive rename: ' . $e->getMessage()); }
            
            $message = "✓ Transaction updated successfully!";
            $messageType = 'success';
            
            // Redirect immediately
            header("Location: event-transactions.php");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = 'Error updating transaction: ' . htmlspecialchars($e->getMessage());
            $messageType = 'error';
        }
    }
}

$superadmin_name = $_SESSION['superadmin_name'] ?? 'Administrator';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Event Transaction - <?php echo htmlspecialchars($siteName); ?></title>
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
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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

        .user-info {
            text-align: right;
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
            max-width: 900px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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

        .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.05));
            color: #059669;
            border-left: 4px solid #10b981;
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

        .form-section {
            margin-bottom: 30px;
        }

        .form-section-title {
            font-size: 16px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-section-title i {
            color: #667eea;
            font-size: 18px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .form-grid.two-col {
            grid-template-columns: repeat(2, 1fr);
        }

        @media (max-width: 768px) {
            .form-grid.two-col {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group.required label::after {
            content: ' *';
            color: #ef4444;
        }

        .form-control {
            padding: 12px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.3s ease;
            background: white;
            color: #2d3748;
        }

        .form-control::placeholder {
            color: #a0aec0;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: #f7fafc;
        }

        .form-control:hover {
            border-color: #cbd5e0;
        }

        select.form-control {
            cursor: pointer;
            appearance: auto;
        }

        textarea.form-control {
            min-height: 90px;
            resize: vertical;
        }

        .toggle-group {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        .toggle-btn {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            background: white;
            color: #2d3748;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .toggle-btn:hover {
            border-color: #667eea;
            color: #667eea;
        }

        .toggle-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: #667eea;
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .hidden-section {
            display: none;
        }

        .hidden-section.active {
            display: block;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            padding-top: 24px;
            border-top: 2px solid #e2e8f0;
        }

        .btn {
            padding: 12px 28px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
            transform: translateY(-2px);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .back-link:hover {
            gap: 12px;
            color: #764ba2;
        }

        .info-text {
            font-size: 12px;
            color: #718096;
            margin-top: 6px;
            line-height: 1.5;
        }

        .transaction-info {
            background: rgba(102, 126, 234, 0.1);
            border-left: 4px solid #667eea;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
        }

        .transaction-info h3 {
            font-size: 14px;
            font-weight: 600;
            color: #667eea;
            margin-bottom: 8px;
        }

        .transaction-info p {
            font-size: 13px;
            color: #4a5568;
            margin: 4px 0;
        }

        @media (max-width: 768px) {
            .page-header {
                padding: 20px;
            }

            .page-header h1 {
                font-size: 22px;
            }

            .form-card {
                padding: 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-grid.two-col {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar" style="background: linear-gradient(135deg, #667eea, #764ba2);">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-edit"></i>
                <span>Edit Transaction</span>
            </div>
            <div class="navbar-user">
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_name); ?></div>
                    <div class="user-role">Event Manager</div>
                </div>
                <a href="event-transactions.php" style="color: white; text-decoration: none; padding: 6px 14px; background: rgba(255,255,255,0.15); border-radius: 6px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 6px;">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <a href="event-transactions.php" class="back-link">
            <i class="fas fa-chevron-left"></i> Back to Transactions
        </a>

        <div class="page-header">
            <h1>
                <i class="fas fa-edit"></i>
                Edit Event Transaction
            </h1>
            <p>Update transaction details for event: <strong><?php echo htmlspecialchars($transaction['EventName']); ?></strong></p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="form-card" enctype="multipart/form-data">
            <!-- Transaction Information -->
            <div class="transaction-info">
                <h3>Current Transaction Information</h3>
                <p><strong>Transaction ID:</strong> #<?php echo $transaction['TransactionID']; ?></p>
                <p><strong>Type:</strong> <span style="color: <?php echo $transaction['TransactionType'] === 'Collection' ? '#10b981' : '#ef4444'; ?>; font-weight: 600;"><?php echo $transaction['TransactionType']; ?></span></p>
                <p><strong>Originally Added:</strong> <?php echo date('M d, Y H:i', strtotime($transaction['CreatedAt'])); ?></p>
            </div>

            <!-- Event Selection -->
            <div class="form-section">
                <div class="form-section-title">
                    <i class="fas fa-calendar-alt"></i>
                    Event & Type
                </div>
                <div class="form-grid">
                    <div class="form-group required">
                        <label>Event</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($transaction['EventName']); ?>" disabled>
                        <div class="info-text">This transaction belongs to this event (cannot be changed)</div>
                    </div>
                </div>
            </div>

            <!-- Transaction Type Toggle -->
            <div class="form-section">
                <div class="form-section-title">
                    <i class="fas fa-exchange-alt"></i>
                    Transaction Type
                </div>
                <div class="toggle-group">
                    <button type="button" class="toggle-btn" data-type="Collection" onclick="selectType('Collection', event)">
                        <i class="fas fa-arrow-down" style="color: #10b981;"></i>
                        Collection (Income)
                    </button>
                    <button type="button" class="toggle-btn" data-type="Expense" onclick="selectType('Expense', event)">
                        <i class="fas fa-arrow-up" style="color: #ef4444;"></i>
                        Expense (Outgoing)
                    </button>
                </div>
                <input type="hidden" id="transaction_type" name="transaction_type" value="<?php echo htmlspecialchars($transaction['TransactionType']); ?>">
            </div>

            <!-- General Information -->
            <div class="form-section">
                <div class="form-section-title">
                    <i class="fas fa-info-circle"></i>
                    Transaction Details
                </div>
                <div class="form-grid two-col">
                    <div class="form-group required">
                        <label for="transaction_date">Transaction Date</label>
                        <input type="date" id="transaction_date" name="transaction_date" class="form-control" required value="<?php echo $transaction['TransactionDate']; ?>">
                    </div>
                    <div class="form-group required">
                        <label for="amount">Amount (₹)</label>
                        <input type="number" id="amount" name="amount" class="form-control" placeholder="0.00" step="0.01" min="0.01" required value="<?php echo $transaction['Amount']; ?>">
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group required">
                        <label for="description">Description</label>
                        <input type="text" id="description" name="description" class="form-control" placeholder="e.g., Venue Rental, Catering Services, Member Contribution" required value="<?php echo htmlspecialchars($transaction['Description']); ?>">
                    </div>
                </div>
                <div class="form-grid two-col">
                    <div class="form-group required">
                        <label for="payment_mode">Payment Mode</label>
                        <select id="payment_mode" name="payment_mode" class="form-control" required>
                            <option value="Cash" <?php echo $transaction['PaymentMode'] === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="UPI" <?php echo $transaction['PaymentMode'] === 'UPI' ? 'selected' : ''; ?>>UPI</option>
                            <option value="Bank Transfer" <?php echo $transaction['PaymentMode'] === 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="Cheque" <?php echo $transaction['PaymentMode'] === 'Cheque' ? 'selected' : ''; ?>>Cheque</option>
                            <option value="Card" <?php echo $transaction['PaymentMode'] === 'Card' ? 'selected' : ''; ?>>Card</option>
                            <option value="Online" <?php echo $transaction['PaymentMode'] === 'Online' ? 'selected' : ''; ?>>Online</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="remarks">Remarks</label>
                        <input type="text" id="remarks" name="remarks" class="form-control" placeholder="Additional notes (optional)" value="<?php echo htmlspecialchars($transaction['Remarks'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Collection Specific Fields -->
            <div id="collection-section" class="form-section active">
                <div class="form-section-title">
                    <i class="fas fa-user"></i>
                    Contributor Information
                </div>
                <div class="form-grid">
                    <div class="form-group required" id="contributor-name-group">
                        <label for="contributor_name">Contributor Name</label>
                        <input type="text" id="contributor_name" name="contributor_name" class="form-control" placeholder="Name of contributor" value="<?php echo htmlspecialchars($transaction['ContributorName'] ?? ''); ?>">
                        <div class="info-text">The person who made the contribution</div>
                    </div>
                </div>
            </div>

            <!-- Expense Specific Fields -->
            <div id="expense-section" class="form-section hidden-section">
                <div class="form-section-title">
                    <i class="fas fa-building"></i>
                    Vendor Information
                </div>
                <div class="form-grid two-col">
                    <div class="form-group required" id="vendor-name-group">
                        <label for="vendor_name">Vendor Name</label>
                        <input type="text" id="vendor_name" name="vendor_name" class="form-control" placeholder="Vendor or service provider name" value="<?php echo htmlspecialchars($transaction['VendorName'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category" class="form-control">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($expenseCategories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $transaction['Category'] === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Proof Files -->
            <div class="form-section">
                <div class="form-section-title">
                    <i class="fas fa-paperclip"></i>
                    Proof Files
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="proof_files">Upload Additional Files</label>
                        <input type="file" id="proof_files" name="proof_files[]" class="form-control" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
                        <div class="info-text">You can upload supporting documents like receipts or invoices. Max 5 files, 10MB each.</div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check-circle"></i>
                    Save Changes
                </button>
                <a href="event-transactions.php" class="btn btn-secondary">
                    <i class="fas fa-times-circle"></i>
                    Cancel
                </a>
            </div>
        </form>
    </div>

    <script>
        function selectType(type, event) {
            event.preventDefault();
            
            // Update hidden input
            document.getElementById('transaction_type').value = type;
            
            // Update toggle buttons
            document.querySelectorAll('.toggle-btn').forEach(btn => {
                if (btn.getAttribute('data-type') === type) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            
            // Show/hide relevant sections
            if (type === 'Collection') {
                document.getElementById('collection-section').classList.add('active');
                document.getElementById('expense-section').classList.remove('active');
                document.getElementById('contributor_name').removeAttribute('required');
                document.getElementById('vendor_name').removeAttribute('required');
            } else {
                document.getElementById('collection-section').classList.remove('active');
                document.getElementById('expense-section').classList.add('active');
                document.getElementById('vendor_name').setAttribute('required', 'required');
                document.getElementById('contributor_name').removeAttribute('required');
            }
        }

        // Initialize toggle buttons on page load
        const currentType = document.getElementById('transaction_type').value;
        selectType(currentType, { preventDefault: () => {} });

        // Handle form submission with loading state
        document.querySelector('form').addEventListener('submit', function(e) {
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalHTML = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.style.opacity = '0.7';
            
            // Reset on error (if form validation fails, re-enable button)
            setTimeout(() => {
                if (submitBtn.disabled) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalHTML;
                    submitBtn.style.opacity = '1';
                }
            }, 3000);
        });
    </script>
</body>
</html>
