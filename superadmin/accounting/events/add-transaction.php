<?php
/**
 * Add Event Transaction
 * Add collection or expense to an event
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

// Check if tables exist
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'event_transactions'");
if ($tableCheckStmt->rowCount() === 0) {
    header('Location: ../setup-events.php');
    exit;
}

// Get event ID if provided
$selectedEventId = isset($_GET['event']) ? intval($_GET['event']) : null;
$preselectedType = isset($_GET['type']) ? $_GET['type'] : null;

// Fetch active events
$eventsStmt = $pdo->query("SELECT EventID, EventName, EventDate, Status FROM events WHERE Status IN ('Planned', 'Ongoing', 'Completed', 'Active') ORDER BY EventDate DESC");
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eventId = intval($_POST['event_id']);
    $transactionType = $_POST['transaction_type'];
    $transactionDate = $_POST['transaction_date'];
    $description = trim($_POST['description']);
    $amount = floatval($_POST['amount']);
    $paymentMode = $_POST['payment_mode'];
    $contributorName = isset($_POST['contributor_name']) ? trim($_POST['contributor_name']) : null;
    $contributorContact = isset($_POST['contributor_contact']) ? trim($_POST['contributor_contact']) : null;
    $vendorName = isset($_POST['vendor_name']) ? trim($_POST['vendor_name']) : null;
    $invoiceNumber = isset($_POST['invoice_number']) ? trim($_POST['invoice_number']) : null;
    $receiptNumber = isset($_POST['receipt_number']) ? trim($_POST['receipt_number']) : null;
    $category = isset($_POST['category']) ? $_POST['category'] : null;
    $remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : null;
    
    // Validation
    if (!$eventId || !$transactionType || !$transactionDate || !$description || $amount <= 0) {
        $message = 'Please fill in all required fields.';
        $messageType = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Insert transaction
            $insertStmt = $pdo->prepare("INSERT INTO event_transactions (
                EventID, TransactionType, TransactionDate, Description, Amount,
                PaymentMode, ContributorName, ContributorContact, VendorName,
                InvoiceNumber, ReceiptNumber, Category, Remarks, Status, CreatedBy
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Confirmed', ?)");
            
            $insertStmt->execute([
                $eventId, $transactionType, $transactionDate, $description, $amount,
                $paymentMode, $contributorName, $contributorContact, $vendorName,
                $invoiceNumber, $receiptNumber, $category, $remarks, $superadmin_id
            ]);
            
            // Update event totals
            if ($transactionType === 'Collection') {
                $updateStmt = $pdo->prepare("UPDATE events SET 
                    TotalCollection = TotalCollection + ?,
                    EventBalance = EventBalance + ?,
                    UpdatedAt = NOW()
                    WHERE EventID = ?");
            } else {
                $updateStmt = $pdo->prepare("UPDATE events SET 
                    TotalExpense = TotalExpense + ?,
                    EventBalance = EventBalance - ?,
                    UpdatedAt = NOW()
                    WHERE EventID = ?");
            }
            $updateStmt->execute([$amount, $amount, $eventId]);
            
            $newTransactionId = $pdo->lastInsertId();
            
            $pdo->commit();
            
            // Handle proof file uploads
            $proofMsg = '';
            if (!empty($_FILES['proof_files']['name'][0])) {
                $proofResult = $proofManager->uploadProofs($_FILES['proof_files'], 'event_transaction', $newTransactionId, $superadmin_id);
                if ($proofResult['uploaded'] > 0) {
                    $proofMsg = '&proofs=' . $proofResult['uploaded'];
                }
            }
            
            // Redirect back to event if came from there
            if ($selectedEventId) {
                header("Location: view-event.php?id=$eventId&success=transaction_added" . $proofMsg);
                exit;
            } else {
                header("Location: event-transactions.php?success=1" . $proofMsg);
                exit;
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = 'Error adding transaction: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Categories for expenses
$expenseCategories = ['Venue', 'Catering', 'Decoration', 'Sound & Lighting', 'Printing', 'Transportation', 'Gifts', 'Security', 'Photography', 'Miscellaneous'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Transaction - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../shared-styles.css">
    <style>
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-header h1 {
            font-size: 28px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .page-header h1 i {
            color: #8b5cf6;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            margin-bottom: 20px;
        }
        
        .back-link:hover {
            color: #8b5cf6;
        }
        
        .form-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
            max-width: 800px;
            margin: 0 auto;
        }
        
        .type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            border-bottom: 2px solid var(--border-color);
        }
        
        .type-option {
            padding: 25px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            background: transparent;
            color: var(--text-secondary);
        }
        
        .type-option:first-child {
            border-right: 2px solid var(--border-color);
        }
        
        .type-option.active {
            color: white;
        }
        
        .type-option.collection.active {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .type-option.expense.active {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        .type-option i {
            font-size: 32px;
            display: block;
            margin-bottom: 10px;
        }
        
        .type-option span {
            font-size: 16px;
            font-weight: 600;
        }
        
        .form-body {
            padding: 30px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-group.full-width {
            grid-column: span 2;
        }
        
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .form-group label .required {
            color: #ef4444;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px 14px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 15px;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #8b5cf6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }
        
        .amount-input {
            position: relative;
        }
        
        .amount-input span {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-weight: 600;
        }
        
        .amount-input input {
            padding-left: 30px;
        }
        
        .btn-submit {
            padding: 14px 30px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }
        
        .btn-submit.collection {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        .btn-submit.expense {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }
        
        .conditional-fields {
            display: none;
        }
        
        .conditional-fields.active {
            display: contents;
        }
        
        .alert {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert.error {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .alert.success {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-group.full-width {
                grid-column: span 1;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-calendar-star"></i>
                <span>Event Management</span>
            </div>
            <div class="navbar-user">
                <button id="themeToggle" class="theme-toggle">
                    <i id="themeIcon" class="fas fa-moon"></i>
                    <span id="themeText">Dark</span>
                </button>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_name); ?></div>
                    <div class="user-role">Event Manager</div>
                </div>
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                    <span>Dashboard</span>
                </a>
                <?php include "../../includes/datetime-widget.php"; ?>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <a href="<?php echo $selectedEventId ? "view-event.php?id=$selectedEventId" : 'event-transactions.php'; ?>" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to <?php echo $selectedEventId ? 'Event' : 'Transactions'; ?>
        </a>
        
        <div class="page-header">
            <h1><i class="fas fa-plus-circle"></i> Add Event Transaction</h1>
            <p style="color: var(--text-secondary); margin-top: 8px;">Record a collection or expense for an event</p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert <?php echo $messageType; ?>">
                <i class="fas <?php echo $messageType === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="form-card">
            <form method="POST" id="transactionForm" enctype="multipart/form-data">
                <!-- Type Selector -->
                <div class="type-selector">
                    <button type="button" class="type-option collection <?php echo (!$preselectedType || $preselectedType === 'Collection') ? 'active' : ''; ?>" onclick="selectType('Collection')">
                        <i class="fas fa-arrow-down"></i>
                        <span>Collection</span>
                    </button>
                    <button type="button" class="type-option expense <?php echo $preselectedType === 'Expense' ? 'active' : ''; ?>" onclick="selectType('Expense')">
                        <i class="fas fa-arrow-up"></i>
                        <span>Expense</span>
                    </button>
                </div>
                <input type="hidden" name="transaction_type" id="transactionType" value="<?php echo $preselectedType ?: 'Collection'; ?>">
                
                <div class="form-body">
                    <div class="form-grid">
                        <!-- Event Selection -->
                        <div class="form-group full-width">
                            <label>Select Event <span class="required">*</span></label>
                            <select name="event_id" required>
                                <option value="">-- Select Event --</option>
                                <?php foreach ($events as $event): ?>
                                    <option value="<?php echo $event['EventID']; ?>" <?php echo $selectedEventId == $event['EventID'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($event['EventName']); ?> 
                                        (<?php echo date('M d, Y', strtotime($event['EventDate'])); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Date -->
                        <div class="form-group">
                            <label>Transaction Date <span class="required">*</span></label>
                            <input type="date" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <!-- Amount -->
                        <div class="form-group">
                            <label>Amount <span class="required">*</span></label>
                            <div class="amount-input">
                                <span>₹</span>
                                <input type="number" name="amount" step="0.01" min="0.01" placeholder="0.00" required>
                            </div>
                        </div>
                        
                        <!-- Description -->
                        <div class="form-group full-width">
                            <label>Description <span class="required">*</span></label>
                            <input type="text" name="description" placeholder="Brief description of transaction" required>
                        </div>
                        
                        <!-- Payment Mode -->
                        <div class="form-group">
                            <label>Payment Mode <span class="required">*</span></label>
                            <select name="payment_mode" required>
                                <option value="Cash">Cash</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Card">Card</option>
                            </select>
                        </div>
                        
                        <!-- Collection Fields -->
                        <div class="conditional-fields collection-fields <?php echo (!$preselectedType || $preselectedType === 'Collection') ? 'active' : ''; ?>">
                            <div class="form-group">
                                <label>Contributor Name</label>
                                <input type="text" name="contributor_name" placeholder="Name of contributor">
                            </div>
                            <div class="form-group">
                                <label>Contributor Contact</label>
                                <input type="tel" name="contributor_contact" placeholder="Phone number">
                            </div>
                            <div class="form-group">
                                <label>Receipt Number</label>
                                <input type="text" name="receipt_number" placeholder="Receipt/Reference #">
                            </div>
                        </div>
                        
                        <!-- Expense Fields -->
                        <div class="conditional-fields expense-fields <?php echo $preselectedType === 'Expense' ? 'active' : ''; ?>">
                            <div class="form-group">
                                <label>Vendor Name</label>
                                <input type="text" name="vendor_name" placeholder="Vendor/Shop name">
                            </div>
                            <div class="form-group">
                                <label>Category</label>
                                <select name="category">
                                    <option value="">-- Select Category --</option>
                                    <?php foreach ($expenseCategories as $cat): ?>
                                        <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Invoice Number</label>
                                <input type="text" name="invoice_number" placeholder="Invoice/Bill #">
                            </div>
                        </div>
                        
                        <!-- Remarks -->
                        <div class="form-group full-width">
                            <label>Remarks</label>
                            <textarea name="remarks" rows="3" placeholder="Additional notes..."></textarea>
                        </div>
                        
                        <!-- Proof File Upload -->
                        <div class="form-group full-width">
                            <label><i class="fas fa-paperclip"></i> Proof Files</label>
                            <div style="border: 2px dashed var(--border-color); border-radius: 10px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s;" onclick="document.getElementById('proofFiles').click()" id="dropZone">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 28px; color: #8b5cf6; margin-bottom: 8px; display: block;"></i>
                                <p style="margin: 0; color: var(--text-secondary); font-size: 13px;">Drop files here or click to browse</p>
                                <p style="margin: 4px 0 0; color: var(--text-secondary); font-size: 11px;">Max 5 files, 10MB each. Images, PDFs, Docs, Excel</p>
                                <?php if ($isGDriveEnabled): ?>
                                <p style="margin: 6px 0 0; font-size: 11px; color: #4285f4;"><i class="fab fa-google-drive"></i> Files will also be uploaded to Google Drive</p>
                                <?php endif; ?>
                            </div>
                            <input type="file" name="proof_files[]" id="proofFiles" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" style="display: none;" onchange="showSelectedFiles(this)">
                            <div id="selectedFilesList" style="margin-top: 10px;"></div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-submit" id="submitBtn">
                        <i class="fas fa-save"></i>
                        Add Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="../../theme-manager.js"></script>
    <script>
        function selectType(type) {
            document.getElementById('transactionType').value = type;
            
            // Update buttons
            document.querySelectorAll('.type-option').forEach(btn => btn.classList.remove('active'));
            document.querySelector('.type-option.' + type.toLowerCase()).classList.add('active');
            
            // Toggle fields
            document.querySelector('.collection-fields').classList.toggle('active', type === 'Collection');
            document.querySelector('.expense-fields').classList.toggle('active', type === 'Expense');
            
            // Update submit button
            const btn = document.getElementById('submitBtn');
            btn.className = 'btn-submit ' + type.toLowerCase();
        }
        
        // Initialize
        selectType(document.getElementById('transactionType').value || 'Collection');
        
        // Proof file handling
        function showSelectedFiles(input) {
            const list = document.getElementById('selectedFilesList');
            list.innerHTML = '';
            if (input.files.length > 0) {
                Array.from(input.files).forEach((file, i) => {
                    const isImage = file.type.startsWith('image/');
                    const icon = isImage ? 'fa-image' : (file.type === 'application/pdf' ? 'fa-file-pdf' : 'fa-file');
                    const size = (file.size / 1024 / 1024).toFixed(2);
                    list.innerHTML += `<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg-tertiary,#f3f4f6);border-radius:8px;font-size:13px;margin-bottom:6px;">
                        <i class="fas ${icon}" style="color:#8b5cf6;"></i>
                        <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${file.name}</span>
                        <span style="color:var(--text-secondary);font-size:11px;">${size} MB</span>
                    </div>`;
                });
            }
        }
        
        // Drag and drop
        const dropZone = document.getElementById('dropZone');
        if (dropZone) {
            ['dragenter', 'dragover'].forEach(evt => {
                dropZone.addEventListener(evt, e => { e.preventDefault(); dropZone.style.borderColor = '#8b5cf6'; dropZone.style.background = 'rgba(139,92,246,0.05)'; });
            });
            ['dragleave', 'drop'].forEach(evt => {
                dropZone.addEventListener(evt, e => { e.preventDefault(); dropZone.style.borderColor = ''; dropZone.style.background = ''; });
            });
            dropZone.addEventListener('drop', e => {
                document.getElementById('proofFiles').files = e.dataTransfer.files;
                showSelectedFiles(document.getElementById('proofFiles'));
            });
        }
    </script>
</body>
</html>
