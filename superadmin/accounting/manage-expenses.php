<?php
/**
 * Manage Expenses Page
 * Add, edit, delete, and view all expenses
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/link-target-helper.php';
require_once '../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

$success = '';
$error = '';

// Load proof attachment manager
require_once '../../includes/google-drive-helper.php';
$proofManager = new ProofAttachmentManager($pdo);
$isGDriveEnabled = $proofManager->isGDriveEnabled();

// Check if expenses table exists
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'expenses'");
if ($tableCheckStmt->rowCount() === 0) {
    header('Location: setup-expenses.php');
    exit;
}

// Get selected academic year filter
$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';

// Fetch academic years for filter dropdown
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch expense categories
$categoriesStmt = $pdo->query("SELECT CategoryID, CategoryName, Icon, Color FROM expense_categories WHERE IsActive = 1 ORDER BY SortOrder, CategoryName");
$expenseCategories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle ADD expense
if (isset($_POST['action']) && $_POST['action'] === 'add') {
    $yearID = intval($_POST['year_id'] ?? 0);
    $expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));
    $category = trim($_POST['category'] ?? 'General');
    $subCategory = trim($_POST['sub_category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $paymentMode = trim($_POST['payment_mode'] ?? 'Cash');
    $vendorName = trim($_POST['vendor_name'] ?? '');
    $invoiceNumber = trim($_POST['invoice_number'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    
    // Validation
    if ($yearID <= 0) $error = 'Please select an academic year';
    elseif (empty($description)) $error = 'Description is required';
    elseif ($amount <= 0) $error = 'Amount must be greater than 0';
    
    if (empty($error)) {
        try {
            $pdo->beginTransaction();
            
            // Insert expense record
            $stmt = $pdo->prepare("
                INSERT INTO expenses 
                (YearID, ExpenseDate, Category, SubCategory, Description, Amount, PaymentMode, 
                 VendorName, InvoiceNumber, Remarks, Status, CreatedBy, ApprovedBy, ApprovedAt) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved', ?, ?, NOW())
            ");
            
            $stmt->execute([
                $yearID, $expenseDate, $category, $subCategory ?: null, $description, $amount,
                $paymentMode, $vendorName ?: null, $invoiceNumber ?: null, $remarks ?: null,
                $_SESSION['superadmin_id'], $_SESSION['superadmin_id']
            ]);
            
            $expenseId = $pdo->lastInsertId();
            
            // Handle proof file uploads
            $proofUploadResult = null;
            if (!empty($_FILES['proof_files']) && isset($_FILES['proof_files']['error'])) {
                $hasFiles = is_array($_FILES['proof_files']['error']) 
                    ? $_FILES['proof_files']['error'][0] !== UPLOAD_ERR_NO_FILE 
                    : $_FILES['proof_files']['error'] !== UPLOAD_ERR_NO_FILE;
                    
                if ($hasFiles) {
                    $proofUploadResult = $proofManager->uploadProofs(
                        $_FILES['proof_files'],
                        'expense',
                        $expenseId,
                        $_SESSION['superadmin_id']
                    );
                }
            }
            
            // Update academic year's TotalExpenses
            $updateYearStmt = $pdo->prepare("
                UPDATE academic_years 
                SET TotalExpenses = TotalExpenses + ?, 
                    BalanceAmount = TotalCollection - (TotalExpenses + ?)
                WHERE YearID = ?
            ");
            $updateYearStmt->execute([$amount, $amount, $yearID]);
            
            // Log the action
            $logStmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, UserAgent) 
                VALUES ('SuperAdmin', ?, ?, ?, 'expenses', ?, ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['superadmin_id'],
                $_SESSION['superadmin_name'] ?? 'SuperAdmin',
                'Added expense: ' . $description . ' - ₹' . number_format($amount, 2) . ' (' . $category . ')',
                $expenseId,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            // Also add a corresponding transaction record if needed for tracking
            // This links expenses to the transaction system
            $transactionStmt = $pdo->prepare("
                INSERT INTO transactions 
                (YearID, MemberID, TransactionDate, TransactionTime, TransactionMonth, MonthName, 
                 CollectionAmount, DistributedAmount, InterestAmount, PaymentMode, Remarks, CreatedBy)
                VALUES (?, 0, ?, ?, ?, 'Expense: ' || ?, 0, ?, 0, ?, ?, ?)
            ");
            
            // Note: MemberID 0 indicates a system/expense transaction
            // We'll create a special "expense" entry - this is commented out for now
            // as it may need adjustments based on how the system handles transactions
            
            $pdo->commit();
            
            $proofInfo = ($proofUploadResult && count($proofUploadResult['success']) > 0) 
                ? ' with ' . count($proofUploadResult['success']) . ' proof file(s)' : '';
            $success = 'Expense #' . $expenseId . ' added successfully! Amount: ₹' . number_format($amount, 2) . $proofInfo;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error adding expense: ' . $e->getMessage();
            error_log("Add Expense Error: " . $e->getMessage());
        }
    }
}

// Handle EDIT expense
if (isset($_POST['action']) && $_POST['action'] === 'edit') {
    $expenseId = intval($_POST['expense_id'] ?? 0);
    $yearID = intval($_POST['year_id'] ?? 0);
    $expenseDate = trim($_POST['expense_date'] ?? '');
    $category = trim($_POST['category'] ?? 'General');
    $subCategory = trim($_POST['sub_category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $paymentMode = trim($_POST['payment_mode'] ?? 'Cash');
    $vendorName = trim($_POST['vendor_name'] ?? '');
    $invoiceNumber = trim($_POST['invoice_number'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');
    $status = trim($_POST['status'] ?? 'Approved');
    
    if ($expenseId <= 0) $error = 'Invalid expense ID';
    elseif ($yearID <= 0) $error = 'Please select an academic year';
    elseif (empty($description)) $error = 'Description is required';
    elseif ($amount <= 0) $error = 'Amount must be greater than 0';
    
    if (empty($error)) {
        try {
            $pdo->beginTransaction();
            
            // Get old expense data for adjustment
            $oldStmt = $pdo->prepare("SELECT YearID, Amount, Status FROM expenses WHERE ExpenseID = ?");
            $oldStmt->execute([$expenseId]);
            $oldExpense = $oldStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$oldExpense) {
                throw new Exception("Expense not found");
            }
            
            // Update expense record
            $stmt = $pdo->prepare("
                UPDATE expenses SET
                YearID = ?, ExpenseDate = ?, Category = ?, SubCategory = ?, Description = ?,
                Amount = ?, PaymentMode = ?, VendorName = ?, InvoiceNumber = ?, Remarks = ?, Status = ?
                WHERE ExpenseID = ?
            ");
            
            $stmt->execute([
                $yearID, $expenseDate, $category, $subCategory ?: null, $description, $amount,
                $paymentMode, $vendorName ?: null, $invoiceNumber ?: null, $remarks ?: null, $status,
                $expenseId
            ]);
            
            // Adjust academic year's TotalExpenses
            if ($oldExpense['YearID'] == $yearID) {
                // Same year - just adjust the difference
                $diff = $amount - $oldExpense['Amount'];
                $updateYearStmt = $pdo->prepare("
                    UPDATE academic_years 
                    SET TotalExpenses = TotalExpenses + ?
                    WHERE YearID = ?
                ");
                $updateYearStmt->execute([$diff, $yearID]);
            } else {
                // Different year - subtract from old, add to new
                $pdo->prepare("UPDATE academic_years SET TotalExpenses = TotalExpenses - ? WHERE YearID = ?")
                    ->execute([$oldExpense['Amount'], $oldExpense['YearID']]);
                $pdo->prepare("UPDATE academic_years SET TotalExpenses = TotalExpenses + ? WHERE YearID = ?")
                    ->execute([$amount, $yearID]);
            }
            
            // Log the action
            $logStmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, UserAgent) 
                VALUES ('SuperAdmin', ?, ?, ?, 'expenses', ?, ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['superadmin_id'],
                $_SESSION['superadmin_name'] ?? 'SuperAdmin',
                'Updated expense #' . $expenseId . ': ' . $description . ' - ₹' . number_format($amount, 2),
                $expenseId,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            $pdo->commit();
            
            // Handle proof file uploads on edit
            $proofUploadResult = null;
            if (!empty($_FILES['proof_files']) && isset($_FILES['proof_files']['error'])) {
                $hasFiles = is_array($_FILES['proof_files']['error']) 
                    ? $_FILES['proof_files']['error'][0] !== UPLOAD_ERR_NO_FILE 
                    : $_FILES['proof_files']['error'] !== UPLOAD_ERR_NO_FILE;
                    
                if ($hasFiles) {
                    $proofUploadResult = $proofManager->uploadProofs(
                        $_FILES['proof_files'],
                        'expense',
                        $expenseId,
                        $_SESSION['superadmin_id']
                    );
                }
            }
            
            // Proof files in Google Drive follow the new description / date
            try { $proofManager->renameDriveFiles('expense', $expenseId); } catch (Exception $e) { error_log('Drive rename: ' . $e->getMessage()); }

            $proofInfo = ($proofUploadResult && count($proofUploadResult['success']) > 0)
                ? ' with ' . count($proofUploadResult['success']) . ' new proof file(s)' : '';
            $success = 'Expense #' . $expenseId . ' updated successfully!' . $proofInfo;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error updating expense: ' . $e->getMessage();
            error_log("Update Expense Error: " . $e->getMessage());
        }
    }
}

// Handle DELETE expense
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $expenseId = intval($_POST['expense_id'] ?? 0);
    
    if ($expenseId <= 0) {
        $error = 'Invalid expense ID';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get expense data for reversal
            $stmt = $pdo->prepare("SELECT YearID, Amount, Description, Category FROM expenses WHERE ExpenseID = ?");
            $stmt->execute([$expenseId]);
            $expense = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$expense) {
                throw new Exception("Expense not found");
            }
            
            // Delete the expense
            $deleteStmt = $pdo->prepare("DELETE FROM expenses WHERE ExpenseID = ?");
            $deleteStmt->execute([$expenseId]);
            
            // Reverse the expense from academic year totals
            $updateYearStmt = $pdo->prepare("
                UPDATE academic_years 
                SET TotalExpenses = TotalExpenses - ?
                WHERE YearID = ?
            ");
            $updateYearStmt->execute([$expense['Amount'], $expense['YearID']]);
            
            // Log the action
            $logStmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, UserAgent) 
                VALUES ('SuperAdmin', ?, ?, ?, 'expenses', ?, ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['superadmin_id'],
                $_SESSION['superadmin_name'] ?? 'SuperAdmin',
                'Deleted expense #' . $expenseId . ': ' . $expense['Description'] . ' - ₹' . number_format($expense['Amount'], 2),
                $expenseId,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            $pdo->commit();
            
            $success = 'Expense deleted successfully! Amount ₹' . number_format($expense['Amount'], 2) . ' has been reversed.';
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error deleting expense: ' . $e->getMessage();
            error_log("Delete Expense Error: " . $e->getMessage());
        }
    }
}

// Fetch net balance data for selected year
$netBalanceData = null;
if ($selectedYear !== 'all') {
    $balanceStmt = $pdo->prepare("
        SELECT YearID, YearName, TotalCollection, TotalExpenses, BalanceAmount 
        FROM academic_years WHERE YearID = ?
    ");
    $balanceStmt->execute([$selectedYear]);
    $netBalanceData = $balanceStmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Get summary of all years
    $balanceStmt = $pdo->query("
        SELECT 
            IFNULL(SUM(TotalCollection), 0) as TotalCollection,
            IFNULL(SUM(TotalExpenses), 0) as TotalExpenses,
            IFNULL(SUM(BalanceAmount), 0) as BalanceAmount
        FROM academic_years
    ");
    $netBalanceData = $balanceStmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch expenses with filters
$expenseQuery = "SELECT e.*, ay.YearName,
    (SELECT COUNT(*) FROM proof_attachments pa WHERE pa.EntityType = 'expense' AND pa.EntityID = e.ExpenseID AND pa.DeletedAt IS NULL) as ProofCount
    FROM expenses e 
    LEFT JOIN academic_years ay ON e.YearID = ay.YearID";

$whereConditions = [];
$params = [];

if ($selectedYear !== 'all') {
    $whereConditions[] = "e.YearID = ?";
    $params[] = $selectedYear;
}

if (isset($_GET['category']) && !empty($_GET['category'])) {
    $whereConditions[] = "e.Category = ?";
    $params[] = $_GET['category'];
}

if (isset($_GET['status']) && !empty($_GET['status'])) {
    $whereConditions[] = "e.Status = ?";
    $params[] = $_GET['status'];
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $whereConditions[] = "(e.Description LIKE ? OR e.VendorName LIKE ? OR e.InvoiceNumber LIKE ?)";
    $searchTerm = '%' . $_GET['search'] . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Date range filters
if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $whereConditions[] = "e.ExpenseDate >= ?";
    $params[] = $_GET['date_from'];
}

if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $whereConditions[] = "e.ExpenseDate <= ?";
    $params[] = $_GET['date_to'];
}

if (!empty($whereConditions)) {
    $expenseQuery .= " WHERE " . implode(" AND ", $whereConditions);
}

$expenseQuery .= " ORDER BY e.ExpenseDate DESC, e.CreatedAt DESC LIMIT 100";

$stmt = $pdo->prepare($expenseQuery);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get totals for current filter
$totalQuery = "SELECT 
    IFNULL(SUM(Amount), 0) as total_amount,
    COUNT(*) as total_count
    FROM expenses e";
    
if (!empty($whereConditions)) {
    $totalQuery .= " WHERE " . implode(" AND ", $whereConditions);
}

$totalStmt = $pdo->prepare($totalQuery);
$totalStmt->execute($params);
$totals = $totalStmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Expenses - <?php echo htmlspecialchars($siteName); ?></title>
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
        
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-box {
            background: var(--bg-secondary);
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid;
            box-shadow: var(--shadow);
        }
        
        .stat-box.expense { border-color: #ef4444; }
        .stat-box.count { border-color: #3b82f6; }
        .stat-box.collection { border-color: #10b981; }
        .stat-box.balance { border-color: #8b5cf6; }
        
        .stat-box h3 {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }
        
        .stat-box .value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .filters-section {
            background: var(--bg-secondary);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
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
        
        .expenses-table {
            width: 100%;
            background: var(--bg-secondary);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        
        .expenses-table th {
            background: var(--bg-tertiary);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            border-bottom: 2px solid var(--border-color);
        }
        
        .expenses-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        
        .expenses-table tr:hover td {
            background: var(--bg-tertiary);
        }
        
        .amount-cell {
            font-weight: 700;
            color: #ef4444;
        }
        
        .category-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: var(--bg-tertiary);
        }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .status-badge.approved { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .status-badge.pending { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .status-badge.rejected { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .status-badge.cancelled { background: rgba(107, 114, 128, 0.2); color: #6b7280; }
        
        .action-btns {
            display: flex;
            gap: 8px;
        }
        
        .action-btns button {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .btn-edit {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
        }
        
        .btn-edit:hover {
            background: #3b82f6;
            color: white;
        }
        
        .btn-delete {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }
        
        .btn-delete:hover {
            background: #ef4444;
            color: white;
        }
        
        .btn-add {
            padding: 12px 24px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }
        
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 30px;
            max-width: 700px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
        }
        
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .modal-header h2 {
            font-size: 24px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-header h2 i {
            color: #10b981;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .form-group label .required {
            color: #ef4444;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
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
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid var(--border-color);
        }
        
        .btn-cancel {
            padding: 12px 24px;
            background: var(--bg-tertiary);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn-submit {
            padding: 12px 24px;
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
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 2px solid #10b981;
            color: var(--text-primary);
        }
        
        .alert-success i { color: #10b981; }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid #ef4444;
            color: var(--text-primary);
        }
        
        .alert-error i { color: #ef4444; }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: var(--text-primary);
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .header-actions {
                width: 100%;
                flex-wrap: wrap;
            }
            
            .expenses-table {
                display: block;
                overflow-x: auto;
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
        <div class="page-header">
            <div>
                <h1><i class="fas fa-receipt"></i> Manage Expenses</h1>
                <p style="color: var(--text-secondary); margin-top: 8px;">Track and manage all organizational expenses</p>
            </div>
            <div class="header-actions">
                <a href="import-expenses.php" class="btn-import" style="padding: 12px 20px; background: rgba(59, 130, 246, 0.2); border: none; border-radius: 8px; color: #3b82f6; font-weight: 600; display: flex; align-items: center; gap: 8px; text-decoration: none; transition: all 0.3s ease;">
                    <i class="fas fa-file-import"></i>
                    Import
                </a>
                <a href="export-expenses.php" class="btn-export" style="padding: 12px 20px; background: rgba(16, 185, 129, 0.2); border: none; border-radius: 8px; color: #10b981; font-weight: 600; display: flex; align-items: center; gap: 8px; text-decoration: none; transition: all 0.3s ease;">
                    <i class="fas fa-file-export"></i>
                    Export
                </a>
                <button class="btn-add" onclick="openAddModal()">
                    <i class="fas fa-plus"></i>
                    Add New Expense
                </button>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Stats Summary -->
        <div class="stats-summary">
            <div class="stat-box expense">
                <h3>Total Expenses (Filtered)</h3>
                <div class="value">₹<?php echo number_format($totals['total_amount'], 2); ?></div>
            </div>
            <div class="stat-box count">
                <h3>Total Records</h3>
                <div class="value"><?php echo number_format($totals['total_count']); ?></div>
            </div>
            <?php if ($netBalanceData): ?>
            <div class="stat-box collection">
                <h3>Total Collection</h3>
                <div class="value">₹<?php echo number_format($netBalanceData['TotalCollection'], 2); ?></div>
            </div>
            <div class="stat-box balance" style="border-color: <?php echo $netBalanceData['BalanceAmount'] >= 0 ? '#10b981' : '#ef4444'; ?>;">
                <h3>Net Balance</h3>
                <div class="value" style="color: <?php echo $netBalanceData['BalanceAmount'] >= 0 ? '#10b981' : '#ef4444'; ?>;">
                    ₹<?php echo number_format($netBalanceData['BalanceAmount'], 2); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Academic Year</label>
                        <select name="year" onchange="this.form.submit()">
                            <option value="all" <?php echo $selectedYear === 'all' ? 'selected' : ''; ?>>All Years</option>
                            <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['YearID']; ?>" <?php echo $selectedYear == $year['YearID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['YearName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Category</label>
                        <select name="category" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php foreach ($expenseCategories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['CategoryName']); ?>" 
                                    <?php echo (isset($_GET['category']) && $_GET['category'] === $cat['CategoryName']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['CategoryName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" onchange="this.form.submit()">
                            <option value="">All Status</option>
                            <option value="Approved" <?php echo (isset($_GET['status']) && $_GET['status'] === 'Approved') ? 'selected' : ''; ?>>Approved</option>
                            <option value="Pending" <?php echo (isset($_GET['status']) && $_GET['status'] === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="Rejected" <?php echo (isset($_GET['status']) && $_GET['status'] === 'Rejected') ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>From Date</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>" onchange="this.form.submit()">
                    </div>
                    <div class="filter-group">
                        <label>To Date</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>" onchange="this.form.submit()">
                    </div>
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Description, vendor, invoice..." 
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Expenses Table -->
        <?php if (empty($expenses)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Expenses Found</h3>
                <p>No expense records match your current filters. Try adjusting your filters or add a new expense.</p>
            </div>
        <?php else: ?>
            <?php 
            // Calculate running balance (cumulative expense total)
            $runningTotal = 0;
            $expensesWithRunning = [];
            // Reverse to calculate from oldest to newest, then reverse back
            $reversedExpenses = array_reverse($expenses);
            foreach ($reversedExpenses as $expense) {
                $runningTotal += $expense['Amount'];
                $expense['RunningTotal'] = $runningTotal;
                $expensesWithRunning[] = $expense;
            }
            $expenses = array_reverse($expensesWithRunning);
            ?>
            <table class="expenses-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Amount</th>
                        <th>Running Total</th>
                        <th>Mode</th>
                        <th>Proofs</th>
                        <th>Year</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td><?php echo date('M d, Y', strtotime($expense['ExpenseDate'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($expense['Description']); ?></strong>
                                <?php if ($expense['VendorName']): ?>
                                    <br><small style="color: var(--text-secondary);">Vendor: <?php echo htmlspecialchars($expense['VendorName']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="category-badge">
                                    <i class="fas fa-folder"></i>
                                    <?php echo htmlspecialchars($expense['Category']); ?>
                                </span>
                            </td>
                            <td class="amount-cell">₹<?php echo number_format($expense['Amount'], 2); ?></td>
                            <td class="running-total-cell" style="color: #8b5cf6; font-weight: 600;">₹<?php echo number_format($expense['RunningTotal'], 2); ?></td>
                            <td><?php echo htmlspecialchars($expense['PaymentMode']); ?></td>
                            <td>
                                <?php 
                                $proofCount = intval($expense['ProofCount'] ?? 0);
                                if ($proofCount > 0): ?>
                                    <span class="proof-count-badge" style="background: rgba(139, 92, 246, 0.15); color: #8b5cf6; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; cursor: pointer;"
                                          onclick="viewProofs(<?php echo $expense['ExpenseID']; ?>, 'expense')"
                                          title="Click to view proof files">
                                        <i class="fas fa-paperclip"></i> <?php echo $proofCount; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: var(--text-secondary); font-size: 12px;">
                                        <i class="fas fa-minus"></i> None
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($expense['YearName'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="status-badge <?php echo strtolower($expense['Status']); ?>">
                                    <?php echo htmlspecialchars($expense['Status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <button class="btn-edit" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($expense, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, "UTF-8"); ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-delete" onclick="confirmDelete(<?php echo $expense['ExpenseID']; ?>, <?php echo htmlspecialchars(json_encode($expense['Description'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- Add/Edit Modal -->
    <div id="expenseModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-receipt"></i> <span id="modalTitle">Add Expense</span></h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="expenseForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="expense_id" id="expenseId">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Academic Year <span class="required">*</span></label>
                        <select name="year_id" id="yearId" required>
                            <option value="">Select Year</option>
                            <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['YearID']; ?>">
                                    <?php echo htmlspecialchars($year['YearName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Expense Date <span class="required">*</span></label>
                        <input type="date" name="expense_date" id="expenseDate" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Category <span class="required">*</span></label>
                        <select name="category" id="category" required>
                            <option value="">Select Category</option>
                            <?php foreach ($expenseCategories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['CategoryName']); ?>">
                                    <?php echo htmlspecialchars($cat['CategoryName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sub-Category</label>
                        <input type="text" name="sub_category" id="subCategory" placeholder="Optional sub-category">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Description <span class="required">*</span></label>
                    <textarea name="description" id="description" required placeholder="Enter expense description"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Amount (₹) <span class="required">*</span></label>
                        <input type="number" name="amount" id="amount" step="0.01" min="0.01" required placeholder="0.00">
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
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Vendor Name</label>
                        <input type="text" name="vendor_name" id="vendorName" placeholder="Vendor/Supplier name">
                    </div>
                    <div class="form-group">
                        <label>Invoice Number</label>
                        <input type="text" name="invoice_number" id="invoiceNumber" placeholder="Bill/Invoice reference">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Remarks</label>
                    <textarea name="remarks" id="remarks" placeholder="Additional notes (optional)"></textarea>
                </div>
                
                <div class="form-group" id="statusGroup" style="display: none;">
                    <label>Status</label>
                    <select name="status" id="status">
                        <option value="Approved">Approved</option>
                        <option value="Pending">Pending</option>
                        <option value="Rejected">Rejected</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
                
                <!-- Proof Upload Section in Modal -->
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label style="display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-paperclip" style="color: #8b5cf6;"></i> 
                        <strong>Proof Files</strong>
                        <span style="font-weight: 400; font-size: 12px; color: var(--text-secondary);">(Max 5 files, 10MB each)</span>
                    </label>
                    <div id="modalDropZone" style="border: 2px dashed var(--border-color, #475569); border-radius: 10px; padding: 24px; text-align: center; cursor: pointer; transition: all 0.3s; background: var(--bg-tertiary, rgba(0,0,0,0.1));" onclick="document.getElementById('modalProofFiles').click()">
                        <i class="fas fa-cloud-upload-alt" style="font-size: 32px; color: #8b5cf6; margin-bottom: 8px; display: block;"></i>
                        <p style="margin: 0; color: var(--text-secondary); font-size: 13px; font-weight: 500;">Drop files here or click to browse</p>
                        <p style="margin: 4px 0 0; color: var(--text-secondary); font-size: 11px;">Max 5 files, 10MB each. Images, PDFs, Docs, Excel</p>
                        <?php if ($isGDriveEnabled): ?>
                        <p style="margin: 8px 0 0; font-size: 12px; color: #4285f4;"><i class="fab fa-google-drive"></i> Files will be uploaded to Google Drive</p>
                        <?php else: ?>
                        <p style="margin: 8px 0 0; font-size: 12px; color: var(--text-secondary);"><i class="fas fa-hdd"></i> Files will be stored locally</p>
                        <?php endif; ?>
                    </div>
                    <input type="file" name="proof_files[]" id="modalProofFiles" multiple 
                           accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"
                           style="display: none;" onchange="showModalSelectedFiles(this)">
                    <div id="modalSelectedFiles" style="margin-top: 10px;"></div>
                    <!-- Existing proofs will be shown here during edit -->
                    <div id="modalExistingProofs" style="margin-top: 10px;"></div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i>
                        <span id="submitBtnText">Add Expense</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Proof Viewer Modal -->
    <div id="proofViewerModal" class="modal">
        <div class="modal-content" style="max-width: 650px;">
            <div class="modal-header">
                <h2><i class="fas fa-paperclip" style="color: #8b5cf6;"></i> <span>Proof Files</span></h2>
                <button class="modal-close" onclick="closeProofViewer()">&times;</button>
            </div>
            <div id="proofViewerContent" style="min-height: 100px;">
                <div style="text-align: center; padding: 30px; color: var(--text-secondary);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i>
                    <p>Loading proof files...</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Form -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="expense_id" id="deleteExpenseId">
    </form>
    
    <script src="../theme-manager.js"></script>
    <script>
        const PROOF_API_URL = '../api/proof-upload.php';
        
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Expense';
            document.getElementById('formAction').value = 'add';
            document.getElementById('expenseId').value = '';
            document.getElementById('expenseForm').reset();
            document.getElementById('expenseDate').value = '<?php echo date('Y-m-d'); ?>';
            document.getElementById('statusGroup').style.display = 'none';
            document.getElementById('submitBtnText').textContent = 'Add Expense';
            document.getElementById('modalExistingProofs').innerHTML = '';
            document.getElementById('modalSelectedFiles').innerHTML = '';
            document.getElementById('modalProofFiles').value = '';
            document.getElementById('expenseModal').classList.add('active');
        }
        
        function openEditModal(expense) {
            document.getElementById('modalTitle').textContent = 'Edit Expense';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('expenseId').value = expense.ExpenseID;
            document.getElementById('yearId').value = expense.YearID;
            document.getElementById('expenseDate').value = expense.ExpenseDate;
            document.getElementById('category').value = expense.Category;
            document.getElementById('subCategory').value = expense.SubCategory || '';
            document.getElementById('description').value = expense.Description;
            document.getElementById('amount').value = expense.Amount;
            document.getElementById('paymentMode').value = expense.PaymentMode;
            document.getElementById('vendorName').value = expense.VendorName || '';
            document.getElementById('invoiceNumber').value = expense.InvoiceNumber || '';
            document.getElementById('remarks').value = expense.Remarks || '';
            document.getElementById('status').value = expense.Status;
            document.getElementById('statusGroup').style.display = 'block';
            document.getElementById('submitBtnText').textContent = 'Update Expense';
            document.getElementById('modalProofFiles').value = '';
            document.getElementById('modalSelectedFiles').innerHTML = '';
            
            // Load existing proof attachments
            loadExistingProofs(expense.ExpenseID);
            
            document.getElementById('expenseModal').classList.add('active');
        }
        
        function loadExistingProofs(expenseId) {
            const container = document.getElementById('modalExistingProofs');
            container.innerHTML = '<small style="color: var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Loading proofs...</small>';
            
            fetch(PROOF_API_URL + '?action=list&entity_type=expense&entity_id=' + expenseId)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.attachments.length > 0) {
                        let html = '<div style="display: flex; flex-wrap: wrap; gap: 10px;">';
                        data.attachments.forEach(att => {
                            const isImage = att.MimeType && att.MimeType.startsWith('image/');
                            const storageIcon = att.GoogleDriveFileID 
                                ? '<i class="fab fa-google-drive" style="color: #4285f4; font-size: 11px;" title="Stored on Google Drive"></i>' 
                                : '<i class="fas fa-hdd" style="color: #6b7280; font-size: 11px;" title="Stored locally"></i>';
                            html += `
                                <div id="modal_att_${att.AttachmentID}" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: var(--bg-tertiary, #f3f4f6); border-radius: 8px; font-size: 13px;">
                                    ${isImage ? '<img src="' + (att.thumbnailUrl || att.viewUrl) + '" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:4px;">' : '<i class="fas ' + att.iconClass + '" style="color: #8b5cf6;"></i>'}
                                    <span style="max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtml(att.OriginalName)}">${escapeHtml(att.OriginalName)}</span>
                                    <span style="color: var(--text-secondary); font-size: 11px;">${att.formattedSize}</span>
                                    ${storageIcon}
                                    <a href="${att.viewUrl}" target="_blank" style="color: #3b82f6; font-size: 12px;" title="View"><i class="fas fa-external-link-alt"></i></a>
                                    <button type="button" onclick="deleteModalProof(${att.AttachmentID})" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 12px;" title="Delete"><i class="fas fa-trash"></i></button>
                                </div>
                            `;
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<small style="color: var(--text-secondary);">No proofs attached yet</small>';
                    }
                })
                .catch(() => {
                    container.innerHTML = '<small style="color: #ef4444;">Failed to load proofs</small>';
                });
        }
        
        function deleteModalProof(attachmentId) {
            if (!confirm('Delete this proof file?')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('attachment_id', attachmentId);
            
            fetch(PROOF_API_URL, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const el = document.getElementById('modal_att_' + attachmentId);
                        if (el) el.remove();
                    } else {
                        alert('Delete failed: ' + data.message);
                    }
                });
        }
        
        function viewProofs(entityId, entityType) {
            const modal = document.getElementById('proofViewerModal');
            const content = document.getElementById('proofViewerContent');
            content.innerHTML = '<div style="text-align: center; padding: 30px; color: var(--text-secondary);"><i class="fas fa-spinner fa-spin" style="font-size: 24px;"></i><p>Loading proof files...</p></div>';
            modal.classList.add('active');
            
            fetch(PROOF_API_URL + '?action=list&entity_type=' + entityType + '&entity_id=' + entityId)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    if (data.success && data.attachments.length > 0) {
                        data.attachments.forEach(att => {
                            const isImage = att.MimeType && att.MimeType.startsWith('image/');
                            const storageIcon = att.GoogleDriveFileID 
                                ? '<span style="font-size:11px;color:#4285f4;"><i class="fab fa-google-drive"></i> Google Drive</span>' 
                                : '<span style="font-size:11px;color:#6b7280;"><i class="fas fa-hdd"></i> Local Storage</span>';
                            const viewUrl = escapeHtml(att.viewUrl || '');
                            const gdriveId = att.GoogleDriveFileID || '';
                            const mimeType = att.MimeType || '';
                            const thumbUrl = att.thumbnailUrl || att.viewUrl;
                            html += `<div onclick="openDocumentViewer('${viewUrl}', '${escapeHtml(att.OriginalName)}', '${mimeType}', '${gdriveId}')" style="display:flex;align-items:center;gap:14px;background:var(--bg-tertiary, #f3f4f6);padding:14px 18px;border-radius:12px;margin-bottom:10px;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.transform='translateX(4px)';this.style.boxShadow='0 2px 8px rgba(139,92,246,0.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                                ${isImage 
                                    ? '<img src="' + thumbUrl + '" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:8px;border:2px solid var(--border-color);">' 
                                    : '<div style="width:56px;height:56px;display:flex;align-items:center;justify-content:center;background:rgba(139,92,246,0.1);border-radius:8px;font-size:22px;color:#8b5cf6;"><i class="fas ' + att.iconClass + '"></i></div>'}
                                <div style="flex:1;min-width:0;">
                                    <div style="font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${escapeHtml(att.OriginalName)}">${escapeHtml(att.OriginalName)}</div>
                                    <div style="font-size:12px;color:var(--text-secondary);margin-top:3px;">${att.formattedSize} &bull; ${att.UploadedAt || 'N/A'}</div>
                                    <div style="margin-top:3px;">${storageIcon}</div>
                                </div>
                                <div style="color:#8b5cf6;font-size:18px;"><i class="fas fa-eye"></i></div>
                            </div>`;
                        });
                    } else {
                        html = '<div style="text-align:center;padding:40px;color:var(--text-secondary);"><i class="fas fa-folder-open" style="font-size:40px;opacity:0.4;display:block;margin-bottom:12px;"></i><p>No proof files attached.</p></div>';
                    }
                    content.innerHTML = html;
                });
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
            document.getElementById('proofViewerModal').classList.remove('active');
        }
        
        // Show selected files preview
        function showModalSelectedFiles(input) {
            const list = document.getElementById('modalSelectedFiles');
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
                        <span style="color:#10b981;font-size:11px;"><i class="fas fa-check"></i> Ready</span>
                    </div>`;
                });
            }
        }
        
        // Drag and drop for modal
        const modalDrop = document.getElementById('modalDropZone');
        if (modalDrop) {
            ['dragenter', 'dragover'].forEach(evt => {
                modalDrop.addEventListener(evt, e => { e.preventDefault(); modalDrop.style.borderColor = '#8b5cf6'; modalDrop.style.background = 'rgba(139,92,246,0.05)'; });
            });
            ['dragleave', 'drop'].forEach(evt => {
                modalDrop.addEventListener(evt, e => { e.preventDefault(); modalDrop.style.borderColor = ''; modalDrop.style.background = ''; });
            });
            modalDrop.addEventListener('drop', e => {
                document.getElementById('modalProofFiles').files = e.dataTransfer.files;
                showModalSelectedFiles(document.getElementById('modalProofFiles'));
            });
        }
        
        function closeModal() {
            document.getElementById('expenseModal').classList.remove('active');
        }
        
        function confirmDelete(expenseId, description) {
            if (confirm('Are you sure you want to delete this expense?\n\n' + description + '\n\nThis action cannot be undone and will reverse the expense amount from the net balance.')) {
                document.getElementById('deleteExpenseId').value = expenseId;
                document.getElementById('deleteForm').submit();
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Close modals when clicking outside
        document.querySelectorAll('.modal').forEach(m => {
            m.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        // Close modals on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeProofViewer();
                closeDocumentViewer();
            }
        });
    </script>

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
