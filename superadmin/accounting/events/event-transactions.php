<?php
/**
 * Event Transactions Page
 * View all transactions across all events
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

$error = '';
$success = '';

// Categories for expenses
$expenseCategories = ['Venue', 'Catering', 'Decoration', 'Sound & Lighting', 'Printing', 'Transportation', 'Gifts', 'Security', 'Photography', 'Miscellaneous'];

// Handle success message from redirect
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'updated') $success = 'Transaction updated successfully.';
    if ($_GET['success'] === 'deleted') $success = 'Transaction deleted successfully.';
    if ($_GET['success'] === 'bulk_deleted') {
        $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
        $success = $count . ' transaction(s) deleted successfully.';
    }
}

// Handle Edit Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'edit' && isset($_POST['transaction_id'])) {
        try {
            $txnId = intval($_POST['transaction_id']);
            $newType = $_POST['transaction_type'];
            $newDate = $_POST['transaction_date'];
            $newDescription = trim($_POST['description']);
            $newAmount = floatval($_POST['amount']);
            $newPaymentMode = $_POST['payment_mode'];
            $newContributorName = trim($_POST['contributor_name'] ?? '');
            $newVendorName = trim($_POST['vendor_name'] ?? '');
            $newCategory = $_POST['category'] ?? null;
            $newRemarks = trim($_POST['remarks'] ?? '');

            if (!$newDescription || $newAmount <= 0 || !$newDate) {
                $error = 'Please fill in all required fields.';
            } else {
                // Get old transaction for balance adjustment
                $oldStmt = $pdo->prepare("SELECT * FROM event_transactions WHERE TransactionID = ?");
                $oldStmt->execute([$txnId]);
                $oldTxn = $oldStmt->fetch(PDO::FETCH_ASSOC);

                if ($oldTxn) {
                    $pdo->beginTransaction();

                    // Reverse old event totals
                    if ($oldTxn['TransactionType'] === 'Collection') {
                        $pdo->prepare("UPDATE events SET TotalCollection = TotalCollection - ?, EventBalance = EventBalance - ?, UpdatedAt = NOW() WHERE EventID = ?")
                            ->execute([$oldTxn['Amount'], $oldTxn['Amount'], $oldTxn['EventID']]);
                    } else {
                        $pdo->prepare("UPDATE events SET TotalExpense = TotalExpense - ?, EventBalance = EventBalance + ?, UpdatedAt = NOW() WHERE EventID = ?")
                            ->execute([$oldTxn['Amount'], $oldTxn['Amount'], $oldTxn['EventID']]);
                    }

                    // Update the transaction
                    $updateStmt = $pdo->prepare("UPDATE event_transactions SET 
                        TransactionType = ?, TransactionDate = ?, Description = ?, Amount = ?,
                        PaymentMode = ?, ContributorName = ?, VendorName = ?, Category = ?, Remarks = ?
                        WHERE TransactionID = ?");
                    $updateStmt->execute([
                        $newType, $newDate, $newDescription, $newAmount,
                        $newPaymentMode, $newContributorName ?: null, $newVendorName ?: null, $newCategory ?: null, $newRemarks ?: null,
                        $txnId
                    ]);

                    // Apply new event totals
                    if ($newType === 'Collection') {
                        $pdo->prepare("UPDATE events SET TotalCollection = TotalCollection + ?, EventBalance = EventBalance + ?, UpdatedAt = NOW() WHERE EventID = ?")
                            ->execute([$newAmount, $newAmount, $oldTxn['EventID']]);
                    } else {
                        $pdo->prepare("UPDATE events SET TotalExpense = TotalExpense + ?, EventBalance = EventBalance - ?, UpdatedAt = NOW() WHERE EventID = ?")
                            ->execute([$newAmount, $newAmount, $oldTxn['EventID']]);
                    }

                    $pdo->commit();

                    // Handle proof file uploads for edit
                    if (!empty($_FILES['proof_files']['name'][0])) {
                        $proofResult = $proofManager->uploadProofs($_FILES['proof_files'], 'event_transaction', $txnId, $superadmin_id);
                    }
                    // Proof files in Google Drive follow the new description / date
                    try { $proofManager->renameDriveFiles('event_transaction', $txnId); } catch (Exception $e) { error_log('Drive rename: ' . $e->getMessage()); }

                    // Redirect to avoid resubmission
                    $redirectParams = $_GET;
                    $redirectParams['success'] = 'updated';
                    header('Location: event-transactions.php?' . http_build_query($redirectParams));
                    exit;
                } else {
                    $error = 'Transaction not found.';
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Error updating transaction: ' . $e->getMessage();
        }
    }

    if ($_POST['action'] === 'delete' && isset($_POST['transaction_id'])) {
        try {
            $txnId = intval($_POST['transaction_id']);

            // Get transaction for balance adjustment
            $oldStmt = $pdo->prepare("SELECT * FROM event_transactions WHERE TransactionID = ?");
            $oldStmt->execute([$txnId]);
            $oldTxn = $oldStmt->fetch(PDO::FETCH_ASSOC);

            if ($oldTxn) {
                $pdo->beginTransaction();

                // Reverse event totals
                if ($oldTxn['TransactionType'] === 'Collection') {
                    $pdo->prepare("UPDATE events SET TotalCollection = TotalCollection - ?, EventBalance = EventBalance - ?, UpdatedAt = NOW() WHERE EventID = ?")
                        ->execute([$oldTxn['Amount'], $oldTxn['Amount'], $oldTxn['EventID']]);
                } else {
                    $pdo->prepare("UPDATE events SET TotalExpense = TotalExpense - ?, EventBalance = EventBalance + ?, UpdatedAt = NOW() WHERE EventID = ?")
                        ->execute([$oldTxn['Amount'], $oldTxn['Amount'], $oldTxn['EventID']]);
                }

                // Delete the transaction
                $pdo->prepare("DELETE FROM event_transactions WHERE TransactionID = ?")->execute([$txnId]);

                $pdo->commit();

                $redirectParams = $_GET;
                $redirectParams['success'] = 'deleted';
                header('Location: event-transactions.php?' . http_build_query($redirectParams));
                exit;
            } else {
                $error = 'Transaction not found.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Error deleting transaction: ' . $e->getMessage();
        }
    }

    // Bulk Delete
    if ($_POST['action'] === 'bulk_delete' && isset($_POST['transaction_ids']) && is_array($_POST['transaction_ids'])) {
        try {
            $pdo->beginTransaction();
            $deletedCount = 0;

            foreach ($_POST['transaction_ids'] as $txnId) {
                $txnId = intval($txnId);
                $oldStmt = $pdo->prepare("SELECT * FROM event_transactions WHERE TransactionID = ?");
                $oldStmt->execute([$txnId]);
                $oldTxn = $oldStmt->fetch(PDO::FETCH_ASSOC);

                if ($oldTxn) {
                    // Reverse event totals
                    if ($oldTxn['TransactionType'] === 'Collection') {
                        $pdo->prepare("UPDATE events SET TotalCollection = TotalCollection - ?, EventBalance = EventBalance - ?, UpdatedAt = NOW() WHERE EventID = ?")
                            ->execute([$oldTxn['Amount'], $oldTxn['Amount'], $oldTxn['EventID']]);
                    } else {
                        $pdo->prepare("UPDATE events SET TotalExpense = TotalExpense - ?, EventBalance = EventBalance + ?, UpdatedAt = NOW() WHERE EventID = ?")
                            ->execute([$oldTxn['Amount'], $oldTxn['Amount'], $oldTxn['EventID']]);
                    }

                    $pdo->prepare("DELETE FROM event_transactions WHERE TransactionID = ?")->execute([$txnId]);
                    $deletedCount++;
                }
            }

            $pdo->commit();

            $redirectParams = $_GET;
            $redirectParams['success'] = 'bulk_deleted';
            $redirectParams['count'] = $deletedCount;
            header('Location: event-transactions.php?' . http_build_query($redirectParams));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Error deleting transactions: ' . $e->getMessage();
        }
    }
}

// Check if tables exist
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'event_transactions'");
if ($tableCheckStmt->rowCount() === 0) {
    header('Location: ../setup-events.php');
    exit;
}

// Get filter parameters
$eventId = isset($_GET['event']) ? $_GET['event'] : 'all';
$transactionType = isset($_GET['type']) ? $_GET['type'] : 'all';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$searchType = isset($_GET['search_type']) ? $_GET['search_type'] : 'all';
$paymentMode = isset($_GET['payment_mode']) ? $_GET['payment_mode'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$categoryFilter = isset($_GET['category']) ? $_GET['category'] : '';
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';
$perPage = isset($_GET['per_page']) ? $_GET['per_page'] : 50;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

if ($perPage === 'all') {
    $limit = 100000;
    $perPage = 'all';
} else {
    $limit = max(1, intval($perPage));
    $perPage = $limit;
}
$offset = ($page - 1) * $limit;

// Fetch events for dropdown
$eventsStmt = $pdo->query("SELECT EventID, EventName FROM events ORDER BY EventDate DESC");
$eventsList = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build query
$whereConditions = ["1=1"];
$params = [];

if ($startDate) {
    $whereConditions[] = "et.TransactionDate >= ?";
    $params[] = $startDate;
}

if ($endDate) {
    $whereConditions[] = "et.TransactionDate <= ?";
    $params[] = $endDate;
}

if ($eventId !== 'all') {
    $whereConditions[] = "et.EventID = ?";
    $params[] = $eventId;
}

if ($transactionType !== 'all') {
    $whereConditions[] = "et.TransactionType = ?";
    $params[] = $transactionType;
}

if ($paymentMode) {
    $whereConditions[] = "et.PaymentMode = ?";
    $params[] = $paymentMode;
}

if ($statusFilter) {
    $whereConditions[] = "et.Status = ?";
    $params[] = $statusFilter;
}

if ($categoryFilter) {
    $whereConditions[] = "et.Category = ?";
    $params[] = $categoryFilter;
}

if ($search) {
    $searchParam = "%$search%";
    if ($searchType === 'description') {
        $whereConditions[] = "et.Description LIKE ?";
        $params[] = $searchParam;
    } elseif ($searchType === 'contributor') {
        $whereConditions[] = "et.ContributorName LIKE ?";
        $params[] = $searchParam;
    } elseif ($searchType === 'vendor') {
        $whereConditions[] = "et.VendorName LIKE ?";
        $params[] = $searchParam;
    } elseif ($searchType === 'event') {
        $whereConditions[] = "e.EventName LIKE ?";
        $params[] = $searchParam;
    } else {
        $whereConditions[] = "(et.Description LIKE ? OR et.ContributorName LIKE ? OR et.VendorName LIKE ? OR e.EventName LIKE ?)";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
}

$whereClause = "WHERE " . implode(" AND ", $whereConditions);

// Sort order
switch ($sortBy) {
    case 'date_asc':
        $orderClause = "ORDER BY et.TransactionDate ASC, et.TransactionID ASC";
        break;
    case 'amount_desc':
        $orderClause = "ORDER BY et.Amount DESC";
        break;
    case 'amount_asc':
        $orderClause = "ORDER BY et.Amount ASC";
        break;
    case 'event':
        $orderClause = "ORDER BY e.EventName ASC, et.TransactionDate DESC";
        break;
    case 'type':
        $orderClause = "ORDER BY et.TransactionType ASC, et.TransactionDate DESC";
        break;
    default:
        $orderClause = "ORDER BY et.TransactionDate DESC, et.TransactionID DESC";
        break;
}

// Count total
$countQuery = "SELECT COUNT(*) FROM event_transactions et JOIN events e ON et.EventID = e.EventID $whereClause";

try {
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalRecords = $countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);
} catch (PDOException $e) {
    error_log("Event Transactions Count Query Error: " . $e->getMessage());
    $totalRecords = 0;
    $totalPages = 1;
}

// Fetch transactions
$query = "SELECT 
    et.*,
    e.EventName,
    (SELECT COUNT(*) FROM proof_attachments pa WHERE pa.EntityType = 'event_transaction' AND pa.EntityID = et.TransactionID AND pa.DeletedAt IS NULL) as ProofCount
FROM event_transactions et
JOIN events e ON et.EventID = e.EventID
$whereClause
$orderClause
LIMIT $limit OFFSET $offset";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Event Transactions Query Error: " . $e->getMessage());
    $transactions = [];
    $error = "Error fetching transactions: " . htmlspecialchars($e->getMessage());
}

// Summary (filtered)
$summaryQuery = "SELECT 
    IFNULL(SUM(CASE WHEN et.TransactionType = 'Collection' THEN et.Amount ELSE 0 END), 0) as total_collections,
    IFNULL(SUM(CASE WHEN et.TransactionType = 'Expense' THEN et.Amount ELSE 0 END), 0) as total_expenses,
    COUNT(*) as total_count
FROM event_transactions et
JOIN events e ON et.EventID = e.EventID
$whereClause";

try {
    $summaryStmt = $pdo->prepare($summaryQuery);
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Event Transactions Summary Query Error: " . $e->getMessage());
    $summary = ['total_collections' => 0, 'total_expenses' => 0, 'total_count' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Transactions - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../shared-styles.css">
    <style>
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 25px 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .page-header h1 {
            font-size: 28px;
            color: white;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
        }
        
        .page-header h1 i {
            color: #fff;
        }
        
        .btn-primary {
            padding: 12px 24px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: var(--card-bg, #1e293b);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
            text-align: center;
        }
        
        .summary-card.collections { 
            border-left-color: #10b981; 
        }
        .summary-card.expenses { 
            border-left-color: #ef4444; 
        }
        .summary-card.net { 
            border-left-color: #3b82f6; 
        }
        
        .summary-card h3 {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .summary-card .value {
            font-size: 26px;
            font-weight: 700;
        }
        
        .summary-card.collections .value { color: #10b981; }
        .summary-card.expenses .value { color: #ef4444; }
        .summary-card.net .value { color: var(--text-primary); }
        
        .form-group {
            margin-bottom: 0;
            margin-top: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 12px;
        }

        .form-control {
            width: 100%;
            padding: 9px 11px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 13px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            transition: all 0.3s ease;
            appearance: auto;
        }

        .form-control::placeholder {
            color: var(--text-secondary);
            opacity: 0.6;
        }

        select.form-control {
            cursor: pointer;
        }

        input[type="date"].form-control {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .filters {
            padding: 15px 0;
            margin: 0;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
            align-items: end;
        }

        .filter-grid-row-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 30px;
            margin-bottom: 12px;
            align-items: end;
        }

        .filter-grid-row-3 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
            align-items: end;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }
        
        .bulk-bar {
            display: none;
            background: var(--bg-secondary);
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 16px;
            box-shadow: var(--shadow);
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .bulk-bar.active {
            display: flex;
        }
        
        .bulk-bar .bulk-count {
            font-weight: 700;
            color: #8b5cf6;
        }
        
        .bulk-bar .btn-bulk-action {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .bulk-bar .btn-bulk-delete {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .bulk-bar .btn-bulk-cancel {
            background: var(--bg-tertiary);
            color: var(--text-primary);
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: white;
        }
        
        .transactions-table tr:hover td {
            background: var(--bg-tertiary);
        }
        
        .type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .type-badge.collection {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        
        .type-badge.expense {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .amount-cell {
            font-weight: 700;
            font-size: 15px;
        }
        
        .amount-cell.positive { color: #10b981; }
        .amount-cell.negative { color: #ef4444; }
        
        .action-btns {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }
        
        .btn-action.edit {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }
        
        .btn-action.edit:hover {
            background: rgba(59, 130, 246, 0.3);
        }
        
        .btn-action.delete {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .btn-action.delete:hover {
            background: rgba(239, 68, 68, 0.3);
        }
        
        /* Action Buttons Group Styles */
        .action-buttons-group {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: center;
        }
        
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        
        .action-btn-edit {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }
        
        .action-btn-edit:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.4);
        }
        
        .action-btn-edit:active {
            transform: translateY(0);
        }
        
        .action-btn-delete {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .action-btn-delete:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }
        
        .action-btn-delete:active {
            transform: translateY(0);
        }
        
        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: #1e293b;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            width: 90%;
            max-width: 650px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            z-index: 1001;
        }
        
        .modal-header {
            padding: 20px 25px;
            border-bottom: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px 16px 0 0;
        }
        
        .modal-header h3 {
            font-size: 18px;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: #94a3b8;
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            transition: all 0.2s;
        }
        
        .modal-close:hover {
            background: rgba(148,163,184,0.1);
            color: #cbd5e1;
        }
        
        .modal-body {
            padding: 25px;
            background: #1e293b;
            color: #f1f5f9;
        }
        
        .modal-body .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }
        
        .modal-body .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .modal-body .form-group.full-width {
            grid-column: span 2;
        }
        
        .modal-body .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #e2e8f0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .modal-body .form-group input,
        .modal-body .form-group select,
        .modal-body .form-group textarea {
            padding: 10px 12px;
            border: 2px solid #334155;
            border-radius: 8px;
            background: #0f172a;
            color: #f1f5f9;
            font-size: 14px;
            font-family: inherit;
        }
        
        .modal-body .form-group input::placeholder,
        .modal-body .form-group textarea::placeholder {
            color: #64748b;
        }
        
        .modal-body .form-group input:focus,
        .modal-body .form-group select:focus,
        .modal-body .form-group textarea:focus {
            border-color: #8b5cf6;
            outline: none;
            background: #1e293b;
            box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
        }
        
        /* Override form-control styling in modals for maximum visibility */
        .modal-body .form-control {
            padding: 10px 12px !important;
            border: 2px solid #475569 !important;
            border-radius: 8px !important;
            background: #0f172a !important;
            color: #f1f5f9 !important;
            font-size: 14px !important;
            font-family: inherit !important;
            width: 100% !important;
            box-sizing: border-box !important;
            display: block !important;
        }
        
        .modal-body .form-control:hover {
            border-color: #64748b !important;
        }
        
        .modal-body .form-control:focus {
            border-color: #8b5cf6 !important;
            outline: none !important;
            background: #1e293b !important;
            box-shadow: 0 0 0 3px rgba(139,92,246,0.2) !important;
        }
        
        .modal-body .form-control::placeholder {
            color: #64748b !important;
            opacity: 1 !important;
        }
        
        /* Ensure labels are visible in modal */
        .modal-body label {
            display: block !important;
            margin-bottom: 8px !important;
            color: #cbd5e1 !important;
            font-weight: 600 !important;
            font-size: 13px !important;
        }
        
        /* Ensure form groups have proper spacing */
        .modal-body .form-group {
            margin-bottom: 16px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 6px !important;
        }
        
        /* Fix textarea specifically */
        .modal-body textarea.form-control {
            resize: vertical !important;
            min-height: 80px !important;
        }
        
        /* Fix select dropdown visibility */
        .modal-body select.form-control {
            appearance: auto !important;
            cursor: pointer !important;
        }
        
        .modal-body select.form-control option {
            background: #1e293b !important;
            color: #f1f5f9 !important;
        }
        
        .modal-footer {
            padding: 18px 25px;
            border-top: 1px solid #334155;
            display: flex;
            justify-content: flex-end;
            background: #0f172a;
            border-radius: 0 0 16px 16px;
            gap: 12px;
        }
        
        .btn-modal {
            padding: 10px 22px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-modal.cancel {
            background: #334155;
            color: #f1f5f9;
        }
        
        .btn-modal.cancel:hover {
            background: #475569;
            transform: translateY(-2px);
        }
        
        .btn-modal.save {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-modal.save:hover {
            background: linear-gradient(135deg, #764ba2 0%, #6b42a7 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-modal.danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }
        
        .btn-modal.danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }
        
        .delete-info {
            text-align: center;
            padding: 30px 20px;
            background: rgba(239, 68, 68, 0.05);
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .delete-info i {
            font-size: 48px;
            color: #ef4444;
            margin-bottom: 15px;
            display: block;
        }
        
        .delete-info p {
            color: #cbd5e1;
            line-height: 1.6;
            margin: 0 0 15px 0;
        }
        
        .delete-info h3 {
            color: #f1f5f9;
            margin: 15px 0 10px 0;
            font-size: 16px;
        }
        
        .delete-info .txn-amount {
            font-size: 26px;
            font-weight: 700;
            color: #f1f5f9;
            margin: 15px 0;
        }
        
        .alert-success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.05));
            color: #10b981;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid #10b981;
            border-radius: 8px;
        }
        
        .alert-success i {
            font-size: 20px;
        }
        
        @media (max-width: 768px) {
            .modal-body .form-grid {
                grid-template-columns: 1fr;
            }
            .modal-body .form-group.full-width {
                grid-column: span 1;
            }
        }
        
        .event-link {
            color: #8b5cf6;
            text-decoration: none;
        }
        
        .event-link:hover {
            text-decoration: underline;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 20px;
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
            background: #8b5cf6;
            color: white;
        }
        
        .pagination .current {
            background: #8b5cf6;
            color: white;
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
        
        .results-info {
            padding: 15px 20px;
            background: var(--bg-tertiary);
            font-size: 14px;
            color: var(--text-secondary);
        }
        
        @media (max-width: 1024px) {
            .transactions-table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 768px) {
            .filters {
                padding: 12px;
                margin-bottom: 15px;
            }
            .filter-grid,
            .filter-grid-row-2,
            .filter-grid-row-3 {
                grid-template-columns: 1fr;
                gap: 8px;
                margin-bottom: 8px;
            }
            .form-control {
                padding: 8px;
                font-size: 12px;
            }
            .btn {
                padding: 6px 12px;
                font-size: 11px;
                gap: 4px;
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
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Event Dashboard
        </a>
        
        <div class="page-header">
            <div>
                <h1><i class="fas fa-exchange-alt"></i> Event Transactions</h1>
                <p style="color: var(--text-secondary); margin-top: 8px;">Track all collections and expenses across events</p>
            </div>
            <a href="add-transaction.php" class="btn-primary">
                <i class="fas fa-plus"></i>
                Add Transaction
            </a>
            <a href="import-event-transactions.php" class="btn-primary" style="background: rgba(59, 130, 246, 0.2); color: #3b82f6; border: 2px solid rgba(59, 130, 246, 0.4);">
                <i class="fas fa-file-import"></i>
                Import
            </a>
        </div>
        
        <?php if ($success): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.2); color: #ef4444; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ef4444;">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card collections">
                <h3>Total Collections</h3>
                <div class="value">₹<?php echo number_format($summary['total_collections'], 0); ?></div>
            </div>
            <div class="summary-card expenses">
                <h3>Total Expenses</h3>
                <div class="value">₹<?php echo number_format($summary['total_expenses'], 0); ?></div>
            </div>
            <div class="summary-card net">
                <h3>Net Balance</h3>
                <div class="value">₹<?php echo number_format($summary['total_collections'] - $summary['total_expenses'], 0); ?></div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters">
            <h3 style="margin-bottom: 20px; color: var(--text-primary);">
                <i class="fas fa-filter"></i> Filter & Search Transactions
            </h3>
            <form method="GET" id="filterForm">
                <!-- Row 1: Search, Event, Type -->
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <div style="display: flex; gap: 0; align-items: stretch;">
                            <select name="search_type" class="form-control" style="width: 130px; border-radius: 8px 0 0 8px; border-right: 1px solid var(--border-color); flex-shrink: 0;">
                                <option value="all" <?php echo $searchType === 'all' ? 'selected' : ''; ?>>All Fields</option>
                                <option value="description" <?php echo $searchType === 'description' ? 'selected' : ''; ?>>Description</option>
                                <option value="contributor" <?php echo $searchType === 'contributor' ? 'selected' : ''; ?>>Contributor</option>
                                <option value="vendor" <?php echo $searchType === 'vendor' ? 'selected' : ''; ?>>Vendor</option>
                                <option value="event" <?php echo $searchType === 'event' ? 'selected' : ''; ?>>Event Name</option>
                            </select>
                            <input type="text" name="search" class="form-control" placeholder="Enter search term..." value="<?php echo htmlspecialchars($search); ?>" style="flex: 1; border-radius: 0 8px 8px 0; border-left: none;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Event</label>
                        <select name="event" class="form-control">
                            <option value="all">All Events</option>
                            <?php foreach ($eventsList as $evt): ?>
                                <option value="<?php echo $evt['EventID']; ?>" <?php echo $eventId == $evt['EventID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($evt['EventName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type" id="typeFilter" class="form-control" onchange="toggleCategoryFilter()">
                            <option value="all" <?php echo $transactionType === 'all' ? 'selected' : ''; ?>>All Types</option>
                            <option value="Collection" <?php echo $transactionType === 'Collection' ? 'selected' : ''; ?>>Collections</option>
                            <option value="Expense" <?php echo $transactionType === 'Expense' ? 'selected' : ''; ?>>Expenses</option>
                        </select>
                    </div>
                </div>
                
                <!-- Row 2: Date From, Date To, Payment Mode, Category, Status, Sort -->
                <div class="filter-grid-row-2">
                    <div class="form-group">
                        <label>Date From</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($startDate); ?>">
                    </div>
                    <div class="form-group">
                        <label>Date To</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($endDate); ?>">
                    </div>
                    <div class="form-group">
                        <label>Payment Mode</label>
                        <select name="payment_mode" class="form-control">
                            <option value="">All Modes</option>
                            <option value="Cash" <?php echo $paymentMode === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="UPI" <?php echo $paymentMode === 'UPI' ? 'selected' : ''; ?>>UPI</option>
                            <option value="Bank Transfer" <?php echo $paymentMode === 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="Cheque" <?php echo $paymentMode === 'Cheque' ? 'selected' : ''; ?>>Cheque</option>
                            <option value="Card" <?php echo $paymentMode === 'Card' ? 'selected' : ''; ?>>Card</option>
                        </select>
                    </div>
                    <div class="form-group" id="categoryFilterGroup" style="<?php echo $transactionType === 'Expense' ? '' : 'display:none;'; ?>">
                        <label>Category</label>
                        <select name="category" class="form-control">
                            <option value="">All Categories</option>
                            <?php foreach ($expenseCategories as $cat): ?>
                                <option value="<?php echo $cat; ?>" <?php echo $categoryFilter === $cat ? 'selected' : ''; ?>><?php echo $cat; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="">All Statuses</option>
                            <option value="Confirmed" <?php echo $statusFilter === 'Confirmed' ? 'selected' : ''; ?>>✓ Confirmed</option>
                            <option value="Pending" <?php echo $statusFilter === 'Pending' ? 'selected' : ''; ?>>⏳ Pending</option>
                            <option value="Cancelled" <?php echo $statusFilter === 'Cancelled' ? 'selected' : ''; ?>>✗ Cancelled</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sort By</label>
                        <select name="sort" class="form-control">
                            <option value="date_desc" <?php echo $sortBy === 'date_desc' ? 'selected' : ''; ?>>📅 Date (Newest First)</option>
                            <option value="date_asc" <?php echo $sortBy === 'date_asc' ? 'selected' : ''; ?>>📅 Date (Oldest First)</option>
                            <option value="amount_desc" <?php echo $sortBy === 'amount_desc' ? 'selected' : ''; ?>>💰 Amount (High to Low)</option>
                            <option value="amount_asc" <?php echo $sortBy === 'amount_asc' ? 'selected' : ''; ?>>💰 Amount (Low to High)</option>
                            <option value="event" <?php echo $sortBy === 'event' ? 'selected' : ''; ?>>🎪 Event Name (A-Z)</option>
                            <option value="type" <?php echo $sortBy === 'type' ? 'selected' : ''; ?>>📋 Type</option>
                        </select>
                    </div>
                </div>
                
                <!-- Row 3: Results Per Page, Apply, Reset -->
                <div class="filter-grid-row-3">
                    <div class="form-group">
                        <label>Results Per Page</label>
                        <select name="per_page" class="form-control">
                            <option value="25" <?php echo $perPage == 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $perPage == 50 ? 'selected' : ''; ?>>50 (Default)</option>
                            <option value="100" <?php echo $perPage == 100 ? 'selected' : ''; ?>>100</option>
                            <option value="200" <?php echo $perPage == 200 ? 'selected' : ''; ?>>200</option>
                            <option value="500" <?php echo $perPage == 500 ? 'selected' : ''; ?>>500</option>
                            <option value="all" <?php echo $perPage === 'all' ? 'selected' : ''; ?>>📋 All Transactions</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary" style="width: 90%; height: 44px; background: #059669;">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <a href="event-transactions.php" class="btn btn-secondary" style="width: 90%; height: 20px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none;">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>
                
                <!-- Export & Bulk Actions Bar -->
                <div id="exportActionsBar" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 12px; flex-wrap: wrap;">
                    <button type="button" class="btn" style="background: #dc3545; color: white;" onclick="exportPDF()">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                    <button type="button" class="btn" style="background: #28a745; color: white;" onclick="exportCSV()">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                    <button type="button" class="btn" style="background: #17a2b8; color: white;" onclick="toggleBulkMode()">
                        <i class="fas fa-check-square"></i> Bulk Select
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Bulk Actions Bar -->
        <div class="bulk-bar" id="bulkBar">
            <span><span class="bulk-count" id="bulkCount">0</span> transaction(s) selected</span>
            <button type="button" class="btn-bulk-action btn-bulk-delete" onclick="bulkDelete()">
                <i class="fas fa-trash"></i> Delete Selected
            </button>
            <button type="button" class="btn-bulk-action btn-bulk-cancel" onclick="toggleBulkMode()">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
        
        <!-- Transactions Table -->
        <div class="transactions-card">
            <div class="results-info">
                Showing <?php echo count($transactions); ?> of <?php echo number_format($totalRecords); ?> transactions
            </div>
            
            <?php if (empty($transactions)): ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <h3>No Transactions Found</h3>
                    <p>No transactions match your filter criteria.</p>
                </div>
            <?php else: ?>
                <table class="transactions-table" id="transactionsTable">
                    <thead>
                        <tr>
                            <th class="bulk-col" style="display:none; width: 40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                            <th>ID</th>
                            <th>Event</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Person/Vendor</th>
                            <th>Payment Mode</th>
                            <th>Amount</th>
                            <th>Proofs</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $txn): ?>
                            <tr data-id="<?php echo $txn['TransactionID']; ?>">
                                <td class="bulk-col" style="display:none;"><input type="checkbox" class="row-check" value="<?php echo $txn['TransactionID']; ?>" onchange="updateBulkCount()"></td>
                                <td>#<?php echo $txn['TransactionID']; ?></td>
                                <td>
                                    <a href="view-event.php?id=<?php echo $txn['EventID']; ?>" class="event-link">
                                        <?php echo htmlspecialchars($txn['EventName']); ?>
                                    </a>
                                </td>
                                <td>
                                    <span class="type-badge <?php echo strtolower($txn['TransactionType']); ?>">
                                        <?php echo $txn['TransactionType']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($txn['TransactionDate'])); ?></td>
                                <td><?php echo htmlspecialchars($txn['Description']); ?></td>
                                <td>
                                    <?php 
                                    if ($txn['TransactionType'] === 'Collection') {
                                        echo htmlspecialchars($txn['ContributorName'] ?: '-');
                                    } else {
                                        echo htmlspecialchars($txn['VendorName'] ?: '-');
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($txn['PaymentMode']); ?></td>
                                <td class="amount-cell <?php echo $txn['TransactionType'] === 'Collection' ? 'positive' : 'negative'; ?>">
                                    <?php echo $txn['TransactionType'] === 'Collection' ? '+' : '-'; ?>₹<?php echo number_format($txn['Amount'], 0); ?>
                                </td>
                                <td>
                                    <?php 
                                    $proofCount = isset($txn['ProofCount']) ? intval($txn['ProofCount']) : 0;
                                    if ($proofCount > 0): ?>
                                        <span onclick="viewProofs(<?php echo $txn['TransactionID']; ?>, 'event_transaction')" style="cursor:pointer; background: rgba(139,92,246,0.15); color: #8b5cf6; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                            <i class="fas fa-paperclip"></i> <?php echo $proofCount; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-secondary); font-size: 12px;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons-group">
                                        <a href="edit-transaction.php?id=<?php echo $txn['TransactionID']; ?>" class="action-btn action-btn-edit" title="Edit Transaction">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="delete-transaction.php?id=<?php echo $txn['TransactionID']; ?>" class="action-btn action-btn-delete" title="Delete Transaction">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                <i class="fas fa-chevron-left"></i> Prev
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
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
    
    <!-- Edit Transaction Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-edit" style="color: #3b82f6;"></i> Edit Transaction</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" action="event-transactions.php" id="editForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="transaction_id" id="editTxnId">
                <!-- Preserve filter parameters -->
                <?php foreach ($_GET as $key => $value): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                <?php endforeach; ?>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Transaction Type</label>
                            <select name="transaction_type" id="editType" class="form-control" onchange="toggleEditFields()">
                                <option value="Collection">Collection</option>
                                <option value="Expense">Expense</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Transaction Date</label>
                            <input type="date" name="transaction_date" id="editDate" class="form-control" required>
                        </div>
                        <div class="form-group full-width">
                            <label>Description</label>
                            <input type="text" name="description" id="editDescription" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Amount (₹)</label>
                            <input type="number" name="amount" id="editAmount" class="form-control" step="0.01" min="0.01" required>
                        </div>
                        <div class="form-group">
                            <label>Payment Mode</label>
                            <select name="payment_mode" id="editPaymentMode" class="form-control">
                                <option value="Cash">Cash</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Card">Card</option>
                            </select>
                        </div>
                        <div class="form-group edit-collection-field">
                            <label>Contributor Name</label>
                            <input type="text" name="contributor_name" id="editContributorName" class="form-control">
                        </div>
                        <div class="form-group edit-expense-field">
                            <label>Vendor Name</label>
                            <input type="text" name="vendor_name" id="editVendorName" class="form-control">
                        </div>
                        <div class="form-group edit-expense-field">
                            <label>Category</label>
                            <select name="category" id="editCategory" class="form-control">
                                <option value="">-- Select --</option>
                                <?php foreach ($expenseCategories as $cat): ?>
                                    <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label>Remarks</label>
                            <textarea name="remarks" id="editRemarks" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="form-group full-width">
                            <label><i class="fas fa-paperclip" style="color: #8b5cf6;"></i> <strong>Proof Files</strong></label>
                            <div id="editExistingProofs" style="margin-bottom: 10px;"></div>
                            <div id="editDropZone" style="border: 2px dashed var(--border-color, #475569); border-radius: 10px; padding: 18px; text-align: center; cursor: pointer; transition: all 0.3s; background: var(--bg-tertiary, rgba(0,0,0,0.1));" onclick="document.getElementById('editProofFiles').click()">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 24px; color: #8b5cf6; margin-bottom: 6px; display: block;"></i>
                                <p style="margin: 0; color: var(--text-secondary); font-size: 12px;">Drop files here or click to browse</p>
                                <p style="margin: 3px 0 0; color: var(--text-secondary); font-size: 11px;">Max 5 files, 10MB each</p>
                                <?php if ($isGDriveEnabled): ?>
                                <p style="margin: 6px 0 0; font-size: 11px; color: #4285f4;"><i class="fab fa-google-drive"></i> Files will be uploaded to Google Drive</p>
                                <?php else: ?>
                                <p style="margin: 6px 0 0; font-size: 11px; color: var(--text-secondary);"><i class="fas fa-hdd"></i> Files will be stored locally</p>
                                <?php endif; ?>
                            </div>
                            <input type="file" name="proof_files[]" id="editProofFiles" class="form-control" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" style="display: none;" onchange="showEditSelectedFiles(this)">
                            <div id="editSelectedFiles" style="margin-top: 8px;"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal cancel" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn-modal save"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal" style="max-width: 500px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; border-radius: 15px 15px 0 0;">
                <h3 style="color: white;"><i class="fas fa-exclamation-circle"></i> Delete Transaction</h3>
                <button class="modal-close" onclick="closeDeleteModal()" style="color: white;">&times;</button>
            </div>
            <form method="POST" action="event-transactions.php" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="transaction_id" id="deleteTxnId">
                <!-- Preserve filter parameters -->
                <?php foreach ($_GET as $key => $value): ?>
                    <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>">
                <?php endforeach; ?>
                <div class="modal-body" style="padding: 30px;">
                    <!-- Warning Alert -->
                    <div style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(239, 68, 68, 0.08) 100%); border-left: 4px solid #ef4444; border-radius: 10px; padding: 16px; margin-bottom: 24px;">
                        <div style="display: flex; gap: 12px; align-items: flex-start;">
                            <i class="fas fa-exclamation-triangle" style="color: #ef4444; font-size: 18px; margin-top: 2px; flex-shrink: 0;"></i>
                            <div>
                                <h4 style="margin: 0 0 6px; color: #dc2626; font-size: 14px; font-weight: 600;">⚠️ Warning: This Action Cannot Be Undone</h4>
                                <p style="margin: 0; color: #991b1b; font-size: 13px; line-height: 1.5;">Deleting this transaction will permanently remove it from the system and adjust all related event balances.</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Transaction Details -->
                    <div style="background: #f9fafb; border-radius: 10px; padding: 16px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 12px; color: #1f2937; font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Transaction to Delete:</h4>
                        <div style="border-left: 3px solid #8b5cf6; padding-left: 12px;">
                            <p style="margin: 0 0 8px; color: #111827;"><strong id="deleteTxnDesc" style="font-size: 15px;"></strong></p>
                            <p style="margin: 0; font-size: 13px; color: #6b7280;">
                                <span id="deleteTxnAmount" style="font-weight: 700; font-size: 16px;"></span>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Confirmation Message -->
                    <div style="background: #eff6ff; border-radius: 8px; padding: 12px; text-align: center; margin-bottom: 20px;">
                        <p style="margin: 0; color: #1e40af; font-size: 13px;">
                            <i class="fas fa-info-circle"></i> Event totals will be automatically adjusted
                        </p>
                    </div>
                </div>
                <div class="modal-footer" style="padding: 20px 30px; background: #f9fafb; border-top: 1px solid #e5e7eb;">
                    <button type="button" class="btn-modal cancel" onclick="closeDeleteModal()" style="flex: 1;">Cancel</button>
                    <button type="submit" class="btn-modal danger" id="deleteConfirmBtn" style="flex: 1; background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); margin-left: 12px;">
                        <i class="fas fa-trash"></i> <span id="deleteBtnText">Delete Permanently</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="../../theme-manager.js"></script>
    <script>
        // Category filter toggle
        function toggleCategoryFilter() {
            const type = document.getElementById('typeFilter').value;
            document.getElementById('categoryFilterGroup').style.display = type === 'Expense' ? '' : 'none';
        }
        
        // Bulk select mode
        let bulkMode = false;
        
        function toggleBulkMode() {
            bulkMode = !bulkMode;
            document.querySelectorAll('.bulk-col').forEach(el => {
                el.style.display = bulkMode ? '' : 'none';
            });
            document.getElementById('bulkBar').classList.toggle('active', bulkMode);
            if (!bulkMode) {
                document.querySelectorAll('.row-check').forEach(cb => cb.checked = false);
                document.getElementById('selectAll').checked = false;
                updateBulkCount();
            }
        }
        
        function toggleSelectAll() {
            const checked = document.getElementById('selectAll').checked;
            document.querySelectorAll('.row-check').forEach(cb => cb.checked = checked);
            updateBulkCount();
        }
        
        function updateBulkCount() {
            const count = document.querySelectorAll('.row-check:checked').length;
            document.getElementById('bulkCount').textContent = count;
        }
        
        function bulkDelete() {
            const ids = Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);
            if (ids.length === 0) {
                alert('Please select at least one transaction.');
                return;
            }
            if (!confirm('Are you sure you want to delete ' + ids.length + ' transaction(s)? This cannot be undone.')) {
                return;
            }
            // Submit via form
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="bulk_delete">';
            ids.forEach(id => {
                form.innerHTML += '<input type="hidden" name="transaction_ids[]" value="' + id + '">';
            });
            document.body.appendChild(form);
            form.submit();
        }
        
        // Export PDF
        function exportPDF() {
            const table = document.getElementById('transactionsTable');
            if (!table) { alert('No transactions to export.'); return; }
            
            let content = '<html><head><title>Event Transactions Report</title>';
            content += '<style>body{font-family:Arial,sans-serif;padding:20px;} h1{color:#333;text-align:center;} ';
            content += 'table{width:100%;border-collapse:collapse;margin-top:20px;} ';
            content += 'th,td{border:1px solid #ddd;padding:10px 12px;text-align:left;font-size:13px;} ';
            content += 'th{background:#8b5cf6;color:white;font-weight:bold;} ';
            content += 'tr:nth-child(even){background:#f9f9f9;} ';
            content += '.positive{color:#10b981;} .negative{color:#ef4444;} ';
            content += '.summary{margin-top:10px;font-size:14px;}';
            content += '</style></head><body>';
            content += '<h1>Event Transactions Report</h1>';
            content += '<p class="summary">Generated: ' + new Date().toLocaleDateString() + ' | ';
            content += 'Total Records: <?php echo $totalRecords; ?> | ';
            content += 'Collections: ₹<?php echo number_format($summary["total_collections"], 0); ?> | ';
            content += 'Expenses: ₹<?php echo number_format($summary["total_expenses"], 0); ?></p>';
            content += '<table><thead><tr>';
            
            // Headers (skip bulk col and actions)
            const headers = table.querySelectorAll('thead th');
            headers.forEach((th, i) => {
                if (th.classList.contains('bulk-col') || th.textContent.trim() === 'Actions') return;
                content += '<th>' + th.textContent.trim() + '</th>';
            });
            content += '</tr></thead><tbody>';
            
            // Rows
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                content += '<tr>';
                row.querySelectorAll('td').forEach((td, i) => {
                    if (td.classList.contains('bulk-col')) return;
                    // Skip last column (Actions)
                    const allTds = row.querySelectorAll('td');
                    const visibleTds = Array.from(allTds).filter(t => !t.classList.contains('bulk-col'));
                    if (td === visibleTds[visibleTds.length - 1]) return;
                    const cls = td.classList.contains('positive') ? ' class="positive"' : (td.classList.contains('negative') ? ' class="negative"' : '');
                    content += '<td' + cls + '>' + td.textContent.trim() + '</td>';
                });
                content += '</tr>';
            });
            content += '</tbody></table></body></html>';
            
            const printWin = window.open('', '_blank');
            printWin.document.write(content);
            printWin.document.close();
            setTimeout(() => { printWin.print(); }, 500);
        }
        
        // Export CSV
        function exportCSV() {
            const table = document.getElementById('transactionsTable');
            if (!table) { alert('No transactions to export.'); return; }
            
            let csv = [];
            
            // Headers
            const headerCols = [];
            table.querySelectorAll('thead th').forEach(th => {
                if (th.classList.contains('bulk-col') || th.textContent.trim() === 'Actions') return;
                headerCols.push('"' + th.textContent.trim().replace(/"/g, '""') + '"');
            });
            csv.push(headerCols.join(','));
            
            // Rows
            table.querySelectorAll('tbody tr').forEach(row => {
                const rowData = [];
                const allTds = Array.from(row.querySelectorAll('td')).filter(t => !t.classList.contains('bulk-col'));
                allTds.forEach((td, i) => {
                    if (i === allTds.length - 1) return; // Skip Actions
                    rowData.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
                });
                csv.push(rowData.join(','));
            });
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'event_transactions_' + new Date().toISOString().split('T')[0] + '.csv';
            link.click();
        }
        
        // Edit modal
        const PROOF_API_URL = '../../api/proof-upload.php';
        
        // Wrapper functions to extract data from attributes
        function openEditModalFromData(button) {
            console.log('openEditModalFromData called', button);
            try {
                const txnData = button.getAttribute('data-txn');
                console.log('txnData:', txnData);
                if (!txnData) {
                    console.error('No transaction data found');
                    alert('Error: No transaction data found');
                    return;
                }
                const txn = JSON.parse(txnData);
                console.log('Parsed transaction:', txn);
                openEditModal(txn);
            } catch (e) {
                console.error('Error parsing transaction data:', e);
                alert('Error opening edit form: ' + e.message);
            }
        }
        
        function openDeleteModalFromData(button) {
            console.log('openDeleteModalFromData called');
            try {
                const id = button.getAttribute('data-txn-id');
                const desc = button.getAttribute('data-txn-desc');
                const amount = button.getAttribute('data-txn-amount');
                const type = button.getAttribute('data-txn-type');
                console.log('Delete data:', {id, desc, amount, type});
                openDeleteModal(id, desc, amount, type);
            } catch (e) {
                console.error('Error opening delete modal:', e);
                alert('Error opening delete confirmation: ' + e.message);
            }
        }
        
        function openEditModal(txn) {
            console.log('openEditModal called with:', txn);
            try {
                const editModal = document.getElementById('editModal');
                console.log('editModal element:', editModal);
                if (!editModal) {
                    alert('Error: Modal element not found');
                    return;
                }
                
                try {
                    document.getElementById('editTxnId').value = txn.TransactionID;
                    document.getElementById('editType').value = txn.TransactionType;
                    document.getElementById('editDate').value = txn.TransactionDate;
                    document.getElementById('editDescription').value = txn.Description || '';
                    document.getElementById('editAmount').value = txn.Amount;
                    document.getElementById('editPaymentMode').value = txn.PaymentMode || 'Cash';
                    document.getElementById('editContributorName').value = txn.ContributorName || '';
                    document.getElementById('editVendorName').value = txn.VendorName || '';
                    document.getElementById('editCategory').value = txn.Category || '';
                    document.getElementById('editRemarks').value = txn.Remarks || '';
                    document.getElementById('editProofFiles').value = '';
                    console.log('✓ Form fields populated');
                } catch (fieldError) {
                    console.error('Error populating form fields:', fieldError);
                    alert('Error: Some form fields are missing. ' + fieldError.message);
                    return;
                }
                
                toggleEditFields();
                console.log('✓ toggleEditFields called');
                
                try {
                    loadEditProofs(txn.TransactionID);
                    console.log('✓ loadEditProofs called');
                } catch (proofError) {
                    console.error('Error loading proofs:', proofError);
                    document.getElementById('editExistingProofs').innerHTML = '<small style="color:#ef4444;">Error loading proofs</small>';
                }
                
                console.log('Adding active class to modal');
                editModal.classList.add('active');
                editModal.style.display = 'flex';
                console.log('Modal classes after add:', editModal.className);
            } catch (e) {
                console.error('Error in openEditModal:', e);
                alert('Error opening modal: ' + e.message);
            }
        }
        
        function loadEditProofs(txnId) {
            const container = document.getElementById('editExistingProofs');
            if (!container) {
                console.error('editExistingProofs container not found');
                return;
            }
            container.innerHTML = '<small style="color:var(--text-secondary);"><i class="fas fa-spinner fa-spin"></i> Loading...</small>';
            
            fetch(PROOF_API_URL + '?action=list&entity_type=event_transaction&entity_id=' + txnId)
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.attachments.length > 0) {
                        let html = '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
                        data.attachments.forEach(att => {
                            const isImage = att.MimeType && att.MimeType.startsWith('image/');
                            const thumbSrc = att.thumbnailUrl || att.viewUrl;
                            const storageIcon = att.GoogleDriveFileID 
                                ? '<i class="fab fa-google-drive" style="color:#4285f4;" title="Google Drive"></i>' 
                                : '<i class="fas fa-hdd" style="color:#94a3b8;" title="Local Storage"></i>';
                            html += `<div id="eatt_${att.AttachmentID}" style="display:flex;align-items:center;gap:6px;padding:6px 10px;background:var(--bg-tertiary,#f3f4f6);border-radius:6px;font-size:12px;">
                                ${isImage ? '<img src="' + thumbSrc + '" style="width:32px;height:32px;object-fit:cover;border-radius:4px;">' : '<i class="fas ' + att.iconClass + '" style="color:#8b5cf6;font-size:16px;"></i>'}
                                <span style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeHtml(att.OriginalName)}">${escapeHtml(att.OriginalName)}</span>
                                <span style="color:var(--text-secondary);font-size:10px;">${att.formattedSize}</span>
                                ${storageIcon}
                                <a href="${att.viewUrl}" target="_blank" style="color:#3b82f6;font-size:11px;"><i class="fas fa-external-link-alt"></i></a>
                                <button type="button" onclick="deleteEditProof(${att.AttachmentID})" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:11px;"><i class="fas fa-trash"></i></button>
                            </div>`;
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<small style="color:var(--text-secondary);">No proofs attached</small>';
                    }
                })
                .catch(() => { container.innerHTML = ''; });
        }
        
        function deleteEditProof(attachmentId) {
            if (!confirm('Delete this proof?')) return;
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('attachment_id', attachmentId);
            fetch(PROOF_API_URL, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const el = document.getElementById('eatt_' + attachmentId);
                        if (el) el.remove();
                    } else {
                        alert('Delete failed: ' + data.message);
                    }
                });
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
        
        function showEditSelectedFiles(input) {
            const container = document.getElementById('editSelectedFiles');
            if (!input.files || input.files.length === 0) { container.innerHTML = ''; return; }
            let html = '';
            for (let i = 0; i < input.files.length; i++) {
                const f = input.files[i];
                const sizeStr = f.size < 1024 ? f.size + ' B' : f.size < 1048576 ? (f.size/1024).toFixed(1) + ' KB' : (f.size/1048576).toFixed(1) + ' MB';
                html += `<div style="display:flex;align-items:center;gap:6px;padding:4px 8px;background:var(--bg-tertiary,#f3f4f6);border-radius:6px;font-size:11px;margin-bottom:4px;">
                    <i class="fas fa-file" style="color:#8b5cf6;"></i>
                    <span>${escapeHtml(f.name)}</span>
                    <span style="color:var(--text-secondary);">(${sizeStr})</span>
                </div>`;
            }
            container.innerHTML = html;
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }
        
        function toggleEditFields() {
            const type = document.getElementById('editType').value;
            document.querySelectorAll('.edit-collection-field').forEach(el => {
                el.style.display = type === 'Collection' ? '' : 'none';
            });
            document.querySelectorAll('.edit-expense-field').forEach(el => {
                el.style.display = type === 'Expense' ? '' : 'none';
            });
        }
        
        // Delete modal
        function openDeleteModal(id, desc, amount, type) {
            try {
                const deleteModal = document.getElementById('deleteModal');
                if (!deleteModal) {
                    alert('Error: Delete modal not found');
                    return;
                }
                
                document.getElementById('deleteTxnId').value = id;
                document.getElementById('deleteTxnDesc').textContent = desc;
                const sign = type === 'Collection' ? '+' : '-';
                document.getElementById('deleteTxnAmount').textContent = sign + '₹' + Number(amount).toLocaleString('en-IN');
                document.getElementById('deleteTxnAmount').style.color = type === 'Collection' ? '#10b981' : '#ef4444';
                deleteModal.classList.add('active');
                deleteModal.style.display = 'flex';
                console.log('✓ Delete modal opened');
            } catch (e) {
                console.error('Error opening delete modal:', e);
                alert('Error: ' + e.message);
            }
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }
        
        // Handle delete form submission with loading state
        document.getElementById('deleteForm').addEventListener('submit', function(e) {
            const deleteBtn = document.getElementById('deleteConfirmBtn');
            const deleteBtnText = document.getElementById('deleteBtnText');
            const originalHTML = deleteBtnText.innerHTML;
            const originalText = deleteBtnText.textContent;
            
            // Show loading state
            deleteBtn.disabled = true;
            deleteBtnText.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
            deleteBtn.style.opacity = '0.7';
            deleteBtn.style.cursor = 'not-allowed';
        });
        
        // Close modals on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        // Close proof viewer on overlay click
        const proofViewerModal = document.getElementById('proofViewerModal');
        if (proofViewerModal) {
            proofViewerModal.addEventListener('click', function(e) {
                if (e.target === this) closeProofViewer();
            });
        }
        
        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
                closeDeleteModal();
                closeProofViewer();
                closeDocumentViewer();
            }
        });
        
        // Drag-drop handlers for edit modal
        (function() {
            const dropZone = document.getElementById('editDropZone');
            if (!dropZone) return;
            const fileInput = document.getElementById('editProofFiles');
            ['dragenter','dragover'].forEach(evt => {
                dropZone.addEventListener(evt, function(e) { e.preventDefault(); e.stopPropagation(); dropZone.style.borderColor = '#8b5cf6'; dropZone.style.background = 'rgba(139,92,246,0.08)'; });
            });
            ['dragleave','drop'].forEach(evt => {
                dropZone.addEventListener(evt, function(e) { e.preventDefault(); e.stopPropagation(); dropZone.style.borderColor = ''; dropZone.style.background = ''; });
            });
            dropZone.addEventListener('drop', function(e) {
                if (e.dataTransfer.files.length > 0) {
                    fileInput.files = e.dataTransfer.files;
                    showEditSelectedFiles(fileInput);
                }
            });
        })();
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
