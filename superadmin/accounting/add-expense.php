<?php
/**
 * Add New Expense Page
 * Dedicated page for adding expenses with more detail
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Check if expenses table exists
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'expenses'");
if ($tableCheckStmt->rowCount() === 0) {
    header('Location: setup-expenses.php');
    exit;
}

$success = '';
$error = '';

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName, Status FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get current/active academic year as default
$activeYear = null;
foreach ($academicYears as $year) {
    if ($year['Status'] === 'Active') {
        $activeYear = $year['YearID'];
        break;
    }
}
if (!$activeYear && !empty($academicYears)) {
    $activeYear = $academicYears[0]['YearID'];
}

// Fetch expense categories
$categoriesStmt = $pdo->query("SELECT CategoryID, CategoryName, Icon, Color, Description FROM expense_categories WHERE IsActive = 1 ORDER BY SortOrder, CategoryName");
$expenseCategories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Load proof attachment manager
require_once '../../includes/google-drive-helper.php';
$proofManager = new ProofAttachmentManager($pdo);
$isGDriveEnabled = $proofManager->isGDriveEnabled();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $yearID = intval($_POST['year_id'] ?? 0);
    $expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));
    $category = trim($_POST['category'] ?? 'General');
    $subCategory = trim($_POST['sub_category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $paymentMode = trim($_POST['payment_mode'] ?? 'Cash');
    $vendorName = trim($_POST['vendor_name'] ?? '');
    $invoiceNumber = trim($_POST['invoice_number'] ?? '');
    $receiptNumber = trim($_POST['receipt_number'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    
    // Validation
    if ($yearID <= 0) $error = 'Please select an academic year';
    elseif (empty($expenseDate)) $error = 'Expense date is required';
    elseif (empty($category)) $error = 'Please select a category';
    elseif (empty($description)) $error = 'Description is required';
    elseif ($amount <= 0) $error = 'Amount must be greater than 0';
    
    if (empty($error)) {
        try {
            $pdo->beginTransaction();
            
            // Insert expense record
            $stmt = $pdo->prepare("
                INSERT INTO expenses 
                (YearID, ExpenseDate, Category, SubCategory, Description, Amount, PaymentMode, 
                 VendorName, InvoiceNumber, ReceiptNumber, Remarks, Status, CreatedBy, ApprovedBy, ApprovedAt) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved', ?, ?, NOW())
            ");
            
            $stmt->execute([
                $yearID, $expenseDate, $category, $subCategory ?: null, $description, $amount,
                $paymentMode, $vendorName ?: null, $invoiceNumber ?: null, $receiptNumber ?: null,
                $remarks ?: null, $_SESSION['superadmin_id'], $_SESSION['superadmin_id']
            ]);
            
            $expenseId = $pdo->lastInsertId();
            
            // Handle proof file uploads
            $proofUploadResult = null;
            if (!empty($_FILES['proof_files']) && $_FILES['proof_files']['error'][0] !== UPLOAD_ERR_NO_FILE) {
                $proofUploadResult = $proofManager->uploadProofs(
                    $_FILES['proof_files'],
                    'expense',
                    $expenseId,
                    $_SESSION['superadmin_id']
                );
            }
            
            // Update academic year's TotalExpenses
            $updateYearStmt = $pdo->prepare("
                UPDATE academic_years 
                SET TotalExpenses = TotalExpenses + ?
                WHERE YearID = ?
            ");
            $updateYearStmt->execute([$amount, $yearID]);
            
            // Log the action
            $proofInfo = $proofUploadResult ? ' [' . count($proofUploadResult['success']) . ' proof(s) attached]' : '';
            $logStmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, UserAgent) 
                VALUES ('SuperAdmin', ?, ?, ?, 'expenses', ?, ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['superadmin_id'],
                $_SESSION['superadmin_name'] ?? 'SuperAdmin',
                'Added expense: ' . $description . ' - ₹' . number_format($amount, 2) . ' (' . $category . ')' . $proofInfo,
                $expenseId,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            $pdo->commit();
            
            // Build success message
            $successMsg = 'Expense+%23' . $expenseId . '+added+successfully';
            if ($proofUploadResult && count($proofUploadResult['success']) > 0) {
                $successMsg .= '+with+' . count($proofUploadResult['success']) . '+proof+file(s)';
            }
            
            // Redirect to manage page with success message
            header('Location: manage-expenses.php?success=' . $successMsg);
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error adding expense: ' . $e->getMessage();
            error_log("Add Expense Error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Expense - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../shared-styles.css">
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
            color: #10b981;
        }
        
        .expense-form-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 40px;
            box-shadow: var(--shadow-lg);
            max-width: 900px;
            margin: 0 auto;
        }
        
        .form-section {
            margin-bottom: 35px;
            padding-bottom: 25px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .form-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: #10b981;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }
        
        .form-row.three-col {
            grid-template-columns: repeat(3, 1fr);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 14px;
        }
        
        .form-group label .required {
            color: #ef4444;
        }
        
        .form-group label .hint {
            font-weight: 400;
            color: var(--text-secondary);
            font-size: 12px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 15px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
        }
        
        .form-group input:hover,
        .form-group select:hover,
        .form-group textarea:hover {
            border-color: #10b981;
        }
        
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .amount-input-wrapper {
            position: relative;
        }
        
        .amount-input-wrapper::before {
            content: '₹';
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 16px;
        }
        
        .amount-input-wrapper input {
            padding-left: 35px;
        }
        
        .category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
            margin-top: 10px;
        }
        
        .category-option {
            position: relative;
        }
        
        .category-option input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }
        
        .category-option label {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 0;
        }
        
        .category-option label:hover {
            border-color: #10b981;
            background: var(--bg-tertiary);
        }
        
        .category-option input:checked + label {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.1);
        }
        
        .category-option label i {
            font-size: 18px;
            color: var(--text-secondary);
        }
        
        .category-option input:checked + label i {
            color: #10b981;
        }
        
        .category-option label span {
            font-size: 13px;
            color: var(--text-primary);
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 2px solid var(--border-color);
        }
        
        .btn-cancel {
            padding: 14px 28px;
            background: var(--bg-tertiary);
            border: 2px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-primary);
            cursor: pointer;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-cancel:hover {
            background: var(--bg-secondary);
            border-color: var(--text-secondary);
        }
        
        .btn-submit {
            padding: 14px 28px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 10px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid #ef4444;
            color: var(--text-primary);
        }
        
        .alert-error i {
            color: #ef4444;
            font-size: 20px;
        }
        
        .quick-amount-btns {
            display: flex;
            gap: 8px;
            margin-top: 10px;
        }
        
        .quick-amount-btn {
            padding: 8px 16px;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            color: var(--text-primary);
            transition: all 0.3s ease;
        }
        
        .quick-amount-btn:hover {
            background: rgba(16, 185, 129, 0.1);
            border-color: #10b981;
            color: #10b981;
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
        
        @media (max-width: 768px) {
            .form-row,
            .form-row.three-col {
                grid-template-columns: 1fr;
            }
            
            .expense-form-card {
                padding: 25px;
            }
            
            .category-grid {
                grid-template-columns: 1fr 1fr;
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
        <a href="manage-expenses.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Expenses
        </a>
        
        <div class="page-header">
            <h1><i class="fas fa-plus-circle"></i> Add New Expense</h1>
            <p style="color: var(--text-secondary); margin-top: 8px;">Record a new expense transaction that will be deducted from the net balance</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        
        <div class="expense-form-card">
            <form method="POST" id="expenseForm" enctype="multipart/form-data">
                <!-- Basic Information -->
                <div class="form-section">
                    <h3 class="section-title"><i class="fas fa-info-circle"></i> Basic Information</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Academic Year <span class="required">*</span></label>
                            <select name="year_id" id="yearId" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academicYears as $year): ?>
                                    <option value="<?php echo $year['YearID']; ?>" <?php echo $year['YearID'] == $activeYear ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['YearName']); ?>
                                        <?php if ($year['Status'] === 'Active'): ?> (Active)<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Expense Date <span class="required">*</span></label>
                            <input type="date" name="expense_date" id="expenseDate" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Category <span class="required">*</span></label>
                        <div class="category-grid">
                            <?php foreach ($expenseCategories as $cat): ?>
                                <div class="category-option">
                                    <input type="radio" name="category" id="cat_<?php echo $cat['CategoryID']; ?>" value="<?php echo htmlspecialchars($cat['CategoryName']); ?>" required>
                                    <label for="cat_<?php echo $cat['CategoryID']; ?>">
                                        <i class="fas <?php echo htmlspecialchars($cat['Icon']); ?>"></i>
                                        <span><?php echo htmlspecialchars($cat['CategoryName']); ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Sub-Category <span class="hint">(Optional)</span></label>
                        <input type="text" name="sub_category" id="subCategory" placeholder="E.g., Office Rent, Internet Bill, etc.">
                    </div>
                </div>
                
                <!-- Expense Details -->
                <div class="form-section">
                    <h3 class="section-title"><i class="fas fa-file-alt"></i> Expense Details</h3>
                    
                    <div class="form-group">
                        <label>Description <span class="required">*</span></label>
                        <textarea name="description" id="description" required placeholder="Provide a detailed description of the expense..."></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Amount <span class="required">*</span></label>
                            <div class="amount-input-wrapper">
                                <input type="number" name="amount" id="amount" step="0.01" min="0.01" required placeholder="0.00">
                            </div>
                            <div class="quick-amount-btns">
                                <button type="button" class="quick-amount-btn" onclick="setAmount(100)">₹100</button>
                                <button type="button" class="quick-amount-btn" onclick="setAmount(500)">₹500</button>
                                <button type="button" class="quick-amount-btn" onclick="setAmount(1000)">₹1,000</button>
                                <button type="button" class="quick-amount-btn" onclick="setAmount(5000)">₹5,000</button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Payment Mode</label>
                            <select name="payment_mode" id="paymentMode">
                                <option value="Cash">Cash</option>
                                <option value="Online">Online</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Credit Card">Credit Card</option>
                                <option value="Debit Card">Debit Card</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- Vendor & Reference -->
                <div class="form-section">
                    <h3 class="section-title"><i class="fas fa-store"></i> Vendor & Reference</h3>
                    
                    <div class="form-row three-col">
                        <div class="form-group">
                            <label>Vendor/Supplier Name</label>
                            <input type="text" name="vendor_name" id="vendorName" placeholder="Name of vendor or supplier">
                        </div>
                        <div class="form-group">
                            <label>Invoice Number</label>
                            <input type="text" name="invoice_number" id="invoiceNumber" placeholder="Bill/Invoice reference">
                        </div>
                        <div class="form-group">
                            <label>Receipt Number</label>
                            <input type="text" name="receipt_number" id="receiptNumber" placeholder="Payment receipt number">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Additional Remarks <span class="hint">(Optional)</span></label>
                        <textarea name="remarks" id="remarks" placeholder="Any additional notes or comments..."></textarea>
                    </div>
                </div>
                
                <!-- Proof Attachments -->
                <div class="form-section">
                    <?php
                    $entityType = 'expense';
                    $entityId = 0;
                    $existingAttachments = [];
                    $componentId = 'proof';
                    include '../../includes/proof-upload-component.php';
                    ?>
                </div>
                
                <div class="form-actions">
                    <a href="manage-expenses.php" class="btn-cancel">
                        <i class="fas fa-times"></i>
                        Cancel
                    </a>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i>
                        Add Expense
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="../theme-manager.js"></script>
    <script>
        function setAmount(amount) {
            document.getElementById('amount').value = amount.toFixed(2);
        }
        
        // Form validation
        document.getElementById('expenseForm').addEventListener('submit', function(e) {
            const category = document.querySelector('input[name="category"]:checked');
            if (!category) {
                e.preventDefault();
                alert('Please select a category');
                return false;
            }
        });
    </script>
</body>
</html>
