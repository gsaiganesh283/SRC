<?php
require_once './session.php';
require_once '../config/config.php';
require_once '../includes/functions.php';

$success = '';
$error = '';

// Handle ADD NEW transaction (Superadmin only)
if (isset($_POST['action']) && $_POST['action'] === 'add_new') {
    $yearID = intval($_POST['year_id'] ?? 0);
    $memberID = intval($_POST['member_id'] ?? 0);
    $transactionDate = trim($_POST['transaction_date'] ?? date('Y-m-d'));
    $monthName = trim($_POST['month_name'] ?? '');
    $collectionAmount = floatval($_POST['collection_amount'] ?? 0);
    $distributedAmount = floatval($_POST['distributed_amount'] ?? 0);
    $interestAmount = floatval($_POST['interest_amount'] ?? 0);
    $fixedAmount = floatval($_POST['fixed_amount'] ?? 0);
    $nextMonthDue = !empty($_POST['next_month_due']) ? trim($_POST['next_month_due']) : null;
    $paymentMode = trim($_POST['payment_mode'] ?? 'Cash');
    $remarks = trim($_POST['remarks'] ?? '');
    
    // Get member name
    $memberStmt = $pdo->prepare("SELECT MemberName FROM members WHERE MemberID = ?");
    $memberStmt->execute([$memberID]);
    $memberData = $memberStmt->fetch(PDO::FETCH_ASSOC);
    $memberName = $memberData['MemberName'] ?? 'Unknown';
    
    // Validation
    if ($yearID <= 0) $error = 'Invalid academic year';
    elseif ($memberID <= 0) $error = 'Please select a member';
    elseif (empty($monthName)) $error = 'Month name is required';
    elseif ($collectionAmount == 0 && $distributedAmount == 0) $error = 'Either collection or distributed amount must be provided';
    
    if (empty($error)) {
        try {
            // Extract month-year from transaction date for TransactionMonth field
            $transactionMonth = date('Y-m', strtotime($transactionDate));
            
            $stmt = $pdo->prepare("
                INSERT INTO transactions 
                (YearID, MemberID, TransactionDate, TransactionTime, TransactionMonth, MonthName, CollectionAmount, 
                DistributedAmount, InterestAmount, FixedAmount, NextMonthDue, PaymentMode, Remarks, CreatedBy) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $yearID, $memberID, $transactionDate, date('H:i:s'), $transactionMonth, $monthName,
                $collectionAmount, $distributedAmount, $interestAmount, $fixedAmount, $nextMonthDue,
                $paymentMode, $remarks, $_SESSION['superadmin_id']
            ]);
            
            $transactionId = $pdo->lastInsertId();
            
            // Log the action
            $logStmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, UserAgent) 
                VALUES ('SuperAdmin', ?, ?, ?, 'transactions', ?, ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['superadmin_id'],
                $_SESSION['superadmin_name'] ?? 'SuperAdmin',
                'Added new transaction for Member ID: ' . $memberID . ', Collection: ₹' . $collectionAmount . ', Distribution: ₹' . $distributedAmount,
                $transactionId,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            // Recalculate net balance for the member after adding transaction
            require_once '../includes/net-balance-helper.php';
            persistMemberNetBalance($memberID, $yearID, null, $_SESSION['superadmin_id']);

            // Build detailed success message with transaction details
            $details = [];
            $details[] = "Member: " . htmlspecialchars($memberName);
            $details[] = "Date: " . date('M d, Y', strtotime($transactionDate));
            $details[] = "Month: " . htmlspecialchars($monthName);
            if ($collectionAmount > 0) $details[] = "Collection: ₹" . number_format($collectionAmount, 2);
            if ($distributedAmount > 0) $details[] = "Distribution: ₹" . number_format($distributedAmount, 2);
            if ($interestAmount > 0) $details[] = "Interest: ₹" . number_format($interestAmount, 2);
            $details[] = "Mode: " . htmlspecialchars($paymentMode);

            $success = 'New transaction #' . $transactionId . ' added!|' . implode('; ', $details);
        } catch (Exception $e) {
            $error = 'Error adding transaction: ' . $e->getMessage();
        }
    }
}

// Handle BULK UPDATE transactions
if (isset($_POST['bulk_update']) && isset($_POST['transaction_ids'])) {
    header('Content-Type: application/json');
    
    $transactionIds = $_POST['transaction_ids'];
    $nextDue = isset($_POST['next_due']) ? trim($_POST['next_due']) : null;
    $mode = isset($_POST['mode']) ? trim($_POST['mode']) : null;
    $verified = isset($_POST['verified']) ? intval($_POST['verified']) : null;
    
    if (!is_array($transactionIds) || empty($transactionIds)) {
        echo json_encode(['success' => false, 'message' => 'No transactions selected']);
        exit();
    }
    
    try {
        $updatedCount = 0;
        $pdo->beginTransaction();
        
        foreach ($transactionIds as $tid) {
            $transactionId = intval($tid);
            $updates = [];
            $params = [];
            
            if ($nextDue !== null && !empty($nextDue)) {
                $updates[] = "NextMonthDue = ?";
                $params[] = $nextDue;
            }
            
            if ($mode !== null && !empty($mode)) {
                $updates[] = "PaymentMode = ?";
                $params[] = $mode;
            }
            
            if ($verified !== null) {
                $updates[] = "Verified = ?";
                $params[] = $verified;
            }
            
            if (!empty($updates)) {
                $params[] = $transactionId;
                $sql = "UPDATE transactions SET " . implode(", ", $updates) . " WHERE TransactionID = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                if ($stmt->rowCount() > 0) {
                    $updatedCount++;
                    
                    // Log the action
                    $changes = [];
                    if ($nextDue) $changes[] = "NextDue: $nextDue";
                    if ($mode) $changes[] = "Mode: $mode";
                    if ($verified !== null) $changes[] = "Verified: " . ($verified ? 'Yes' : 'No');
                    
                    $logStmt = $pdo->prepare("
                        INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, UserAgent) 
                        VALUES ('SuperAdmin', ?, ?, ?, 'transactions', ?, ?, ?)
                    ");
                    $logStmt->execute([
                        $_SESSION['superadmin_id'],
                        $_SESSION['superadmin_name'] ?? 'SuperAdmin',
                        'Bulk Update - ' . implode(', ', $changes),
                        $transactionId,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);
                }
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true, 'updated' => $updatedCount]);
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// Handle BULK DELETE transactions
if (isset($_POST['action']) && $_POST['action'] === 'bulk_delete' && isset($_POST['transaction_ids'])) {
    $transactionIds = $_POST['transaction_ids'];
    
    if (!is_array($transactionIds) || empty($transactionIds)) {
        $error = 'No transactions selected for deletion.';
    } else {
        try {
            $deletedCount = 0;
            $totalAmount = 0;
            $affectedMembers = [];
            $affectedMemberYears = []; // Track MemberID+YearID for net balance recalculation
            $pdo->beginTransaction();
            
            foreach ($transactionIds as $tid) {
                $transactionId = intval($tid);
                
                // Get transaction details for audit log
                $stmt = $pdo->prepare("SELECT * FROM transactions WHERE TransactionID = ?");
                $stmt->execute([$transactionId]);
                $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($transaction) {
                    // Get member name
                    $memberStmt = $pdo->prepare("SELECT MemberName FROM members WHERE MemberID = ?");
                    $memberStmt->execute([$transaction['MemberID']]);
                    $memberData = $memberStmt->fetch(PDO::FETCH_ASSOC);
                    $memberName = $memberData['MemberName'] ?? 'Unknown';
                    
                    // Delete the transaction
                    $stmt = $pdo->prepare("DELETE FROM transactions WHERE TransactionID = ?");
                    $stmt->execute([$transactionId]);
                    
                    // Track details
                    $totalAmount += $transaction['CollectionAmount'] + $transaction['DistributedAmount'];
                    if (!in_array($memberName, $affectedMembers)) {
                        $affectedMembers[] = $memberName;
                    }
                    // Track member-year pairs for net balance recalculation
                    $myKey = $transaction['MemberID'] . '-' . $transaction['YearID'];
                    if (!isset($affectedMemberYears[$myKey])) {
                        $affectedMemberYears[$myKey] = ['member_id' => $transaction['MemberID'], 'year_id' => $transaction['YearID']];
                    }
                    
                    // Log the action
                    $logStmt = $pdo->prepare("
                        INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, UserAgent) 
                        VALUES ('SuperAdmin', ?, ?, ?, 'transactions', ?, ?, ?)
                    ");
                    $logStmt->execute([
                        $_SESSION['superadmin_id'],
                        $_SESSION['superadmin_name'] ?? 'SuperAdmin',
                        'Bulk Deleted - Member ID: ' . $transaction['MemberID'] . ', Amount: ₹' . $transaction['CollectionAmount'],
                        $transactionId,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);
                    
                    $deletedCount++;
                }
            }
            
            $pdo->commit();

            // Recalculate net balance for all affected members
            require_once '../includes/net-balance-helper.php';
            foreach ($affectedMemberYears as $my) {
                persistMemberNetBalance($my['member_id'], $my['year_id']);
            }

            // Build detailed success message
            $details = [];
            $details[] = "Transactions: " . $deletedCount;
            $details[] = "Total Amount: ₹" . number_format($totalAmount, 2);
            $details[] = "Members Affected: " . count($affectedMembers);
            
            $success = "Successfully deleted {$deletedCount} transaction(s)!|" . implode('; ', $details);
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error during bulk delete: ' . $e->getMessage();
        }
    }
}

// Handle BULK EXPORT to CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        // Apply same filters as main query
        $query = "
            SELECT 
                t.TransactionID,
                t.TransactionDate,
                m.MemberName,
                y.YearName,
                t.CollectionAmount,
                t.DistributedAmount,
                t.InterestAmount,
                t.FixedAmount,
                t.NextMonthDue,
                t.PaymentMode,
                t.Remarks,
                DATE_FORMAT(t.TransactionDate, '%M %Y') AS MonthName,
                CASE 
                    WHEN t.DistributedAmount > 0 AND t.CollectionAmount = 0 THEN 'Distribution'
                    WHEN t.CollectionAmount > 0 AND t.DistributedAmount = 0 THEN 'Collection'
                    WHEN t.CollectionAmount > 0 AND t.DistributedAmount > 0 THEN 'Both'
                    ELSE 'Other'
                END as TransactionType
            FROM transactions t
            LEFT JOIN members m ON t.MemberID = m.MemberID
            LEFT JOIN academic_years y ON t.YearID = y.YearID
            WHERE 1=1
        ";
        
        $params = [];
        
        if (isset($_GET['year']) && intval($_GET['year']) > 0) {
            $query .= " AND t.YearID = ?";
            $params[] = intval($_GET['year']);
        }
        
        if (isset($_GET['member']) && intval($_GET['member']) > 0) {
            $query .= " AND t.MemberID = ?";
            $params[] = intval($_GET['member']);
        }
        
        if (!empty($_GET['date_from'])) {
            $query .= " AND t.TransactionDate >= ?";
            $params[] = trim($_GET['date_from']);
        }
        
        if (!empty($_GET['date_to'])) {
            $query .= " AND t.TransactionDate <= ?";
            $params[] = trim($_GET['date_to']);
        }
        
        if (!empty($_GET['mode'])) {
            $query .= " AND t.PaymentMode = ?";
            $params[] = trim($_GET['mode']);
        }
        
        $query .= " ORDER BY t.TransactionDate DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $exportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=transactions_' . date('Y-m-d_His') . '.csv');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel UTF-8 support
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Add header row
        fputcsv($output, [
            'Transaction ID',
            'Date',
            'Member Name',
            'Year',
            'Month',
            'Type',
            'Collection Amount',
            'Distribution Amount',
            'Interest Amount',
            'Fixed Amount',
            'Due Date',
            'Payment Mode',
            'Remarks'
        ]);
        
        // Add data rows
        foreach ($exportData as $row) {
            fputcsv($output, [
                $row['TransactionID'],
                $row['TransactionDate'],
                $row['MemberName'],
                $row['YearName'],
                $row['MonthName'],
                $row['TransactionType'],
                $row['CollectionAmount'],
                $row['DistributedAmount'],
                $row['InterestAmount'],
                $row['FixedAmount'] ?? 0,
                !empty($row['NextMonthDue']) ? date('d M Y', strtotime($row['NextMonthDue'])) : '',
                $row['PaymentMode'],
                $row['Remarks']
            ]);
        }
        
        fclose($output);
        exit();
    } catch (Exception $e) {
        die('Export error: ' . $e->getMessage());
    }
}

// Handle VERIFICATION flag toggle
if (isset($_POST['action']) && $_POST['action'] === 'toggle_verify' && isset($_POST['transaction_id'])) {
    $transactionId = intval($_POST['transaction_id']);
    
    try {
        // Check if Verified column exists, if not add it
        $columnCheck = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'Verified'");
        if ($columnCheck->rowCount() === 0) {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN Verified TINYINT(1) DEFAULT 0 AFTER Remarks");
        }
        
        // Get current verification status
        $getStmt = $pdo->prepare("SELECT Verified, TransactionDate, MonthName FROM transactions WHERE TransactionID = ?");
        $getStmt->execute([$transactionId]);
        $transData = $getStmt->fetch(PDO::FETCH_ASSOC);
        $oldStatus = $transData['Verified'] ?? 0;
        
        // Toggle verification status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET Verified = IF(Verified = 1, 0, 1),
                UpdatedAt = NOW()
            WHERE TransactionID = ?
        ");
        $stmt->execute([$transactionId]);
        
        // Get new status
        $getStmt = $pdo->prepare("SELECT Verified FROM transactions WHERE TransactionID = ?");
        $getStmt->execute([$transactionId]);
        $newData = $getStmt->fetch(PDO::FETCH_ASSOC);
        $newStatus = $newData['Verified'] ?? 0;
        
        // Log the action
        $logStmt = $pdo->prepare("
            INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, UserAgent) 
            VALUES ('SuperAdmin', ?, ?, ?, 'transactions', ?, ?, ?)
        ");
        $oldStatusText = $oldStatus ? 'Verified' : 'Pending';
        $newStatusText = $newStatus ? 'Verified' : 'Pending';
        $logStmt->execute([
            $_SESSION['superadmin_id'],
            $_SESSION['superadmin_name'] ?? 'SuperAdmin',
            'Toggled Verification Status for Transaction #' . $transactionId . ': ' . $oldStatusText . ' → ' . $newStatusText,
            $transactionId,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
        
        // Build detailed success message
        $statusChange = ($oldStatus ? 'Verified' : 'Pending') . ' → ' . ($newStatus ? 'Verified' : 'Pending');
        $details = [];
        $details[] = "Date: " . date('M d, Y', strtotime($transData['TransactionDate']));
        $details[] = "Month: " . htmlspecialchars($transData['MonthName']);
        $details[] = "Status: " . $statusChange;
        
        $success = 'Verification status updated!|' . implode('; ', $details);
    } catch (Exception $e) {
        $error = 'Error updating verification: ' . $e->getMessage();
    }
}

// Handle DELETE transaction
if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['transaction_id'])) {
    $transactionId = intval($_POST['transaction_id']);
    
    try {
        // First, get transaction details for audit log
        $stmt = $pdo->prepare("SELECT * FROM transactions WHERE TransactionID = ?");
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($transaction) {
            // Get member name
            $memberStmt = $pdo->prepare("SELECT MemberName FROM members WHERE MemberID = ?");
            $memberStmt->execute([$transaction['MemberID']]);
            $memberData = $memberStmt->fetch(PDO::FETCH_ASSOC);
            $memberName = $memberData['MemberName'] ?? 'Unknown';
            
            // Delete the transaction
            $stmt = $pdo->prepare("DELETE FROM transactions WHERE TransactionID = ?");
            $stmt->execute([$transactionId]);
            
            // Recalculate net balance for the member after deletion
            if ($transaction) {
                require_once '../includes/net-balance-helper.php';
                persistMemberNetBalance($transaction['MemberID'], $transaction['YearID']);
            }
            
            // Log the action in audit_logs
            $logStmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, UserAgent) 
                VALUES ('SuperAdmin', ?, ?, ?, 'transactions', ?, ?, ?)
            ");
            $logStmt->execute([
                $_SESSION['superadmin_id'],
                $_SESSION['superadmin_name'] ?? 'SuperAdmin',
                'Deleted transaction for Member ID: ' . $transaction['MemberID'] . ', Amount: ₹' . $transaction['CollectionAmount'],
                $transactionId,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            // Build detailed deletion message
            $details = [];
            $details[] = "Member: " . htmlspecialchars($memberName);
            $details[] = "Date: " . date('M d, Y', strtotime($transaction['TransactionDate']));
            $details[] = "Month: " . htmlspecialchars($transaction['MonthName']);
            if ($transaction['CollectionAmount'] > 0) $details[] = "Collection: ₹" . number_format($transaction['CollectionAmount'], 2);
            if ($transaction['DistributedAmount'] > 0) $details[] = "Distribution: ₹" . number_format($transaction['DistributedAmount'], 2);
            $details[] = "Mode: " . htmlspecialchars($transaction['PaymentMode']);
            
            $success = 'Transaction #' . $transactionId . ' deleted!|Deleted - ' . implode('; ', $details);
        } else {
            $error = 'Transaction not found.';
        }
    } catch (Exception $e) {
        $error = 'Error deleting transaction: ' . $e->getMessage();
    }
}

// Handle EDIT transaction
if (isset($_POST['action']) && $_POST['action'] === 'edit' && isset($_POST['transaction_id'])) {
    $transactionId = intval($_POST['transaction_id']);
    $collectionAmount = floatval($_POST['collection_amount'] ?? 0);
    $distributedAmount = floatval($_POST['distributed_amount'] ?? 0);
    $interestAmount = floatval($_POST['interest_amount'] ?? 0);
    $fixedAmount = floatval($_POST['fixed_amount'] ?? 0);
    $nextMonthDue = !empty($_POST['next_month_due']) ? trim($_POST['next_month_due']) : null;
    $paymentMode = trim($_POST['payment_mode'] ?? 'Cash');
    $remarks = trim($_POST['remarks'] ?? '');
    $transactionDate = trim($_POST['transaction_date'] ?? date('Y-m-d'));
    
    try {
        // Get the transaction details before update to track what changed
        $getStmt = $pdo->prepare("SELECT * FROM transactions WHERE TransactionID = ?");
        $getStmt->execute([$transactionId]);
        $oldData = $getStmt->fetch(PDO::FETCH_ASSOC);
        $transactionData = $oldData;
        
        // Build change details
        $changes = [];
        if ($oldData['TransactionDate'] != $transactionDate) {
            $changes[] = "Date: " . date('M d, Y', strtotime($oldData['TransactionDate'])) . " → " . date('M d, Y', strtotime($transactionDate));
        }
        if ($oldData['CollectionAmount'] != $collectionAmount) {
            $changes[] = "Collection: ₹" . number_format($oldData['CollectionAmount'], 2) . " → ₹" . number_format($collectionAmount, 2);
        }
        if ($oldData['DistributedAmount'] != $distributedAmount) {
            $changes[] = "Distribution: ₹" . number_format($oldData['DistributedAmount'], 2) . " → ₹" . number_format($distributedAmount, 2);
        }
        if ($oldData['InterestAmount'] != $interestAmount) {
            $changes[] = "Interest: ₹" . number_format($oldData['InterestAmount'], 2) . " → ₹" . number_format($interestAmount, 2);
        }
        if ($oldData['PaymentMode'] != $paymentMode) {
            $changes[] = "Mode: " . $oldData['PaymentMode'] . " → " . $paymentMode;
        }
        if ($oldData['Remarks'] != $remarks) {
            $changes[] = "Remarks updated";
        }
        
        // Update the transaction
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET TransactionDate = ?,
                CollectionAmount = ?, 
                DistributedAmount = ?, 
                InterestAmount = ?, 
                FixedAmount = ?,
                NextMonthDue = ?, 
                PaymentMode = ?, 
                Remarks = ?,
                UpdatedAt = NOW()
            WHERE TransactionID = ?
        ");
        $stmt->execute([
            $transactionDate,
            $collectionAmount,
            $distributedAmount,
            $interestAmount,
            $fixedAmount,
            $nextMonthDue,
            $paymentMode,
            $remarks,
            $transactionId
        ]);
        
        // Recalculate net balance for the member
        if ($transactionData) {
            require_once '../includes/net-balance-helper.php';
            persistMemberNetBalance($transactionData['MemberID'], $transactionData['YearID']);
        }
        
        // Log the action
        $logStmt = $pdo->prepare("
            INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, UserAgent) 
            VALUES ('SuperAdmin', ?, ?, ?, 'transactions', ?, ?, ?)
        ");
        $changesSummary = !empty($changes) ? implode(', ', $changes) : 'Minor updates';
        $logStmt->execute([
            $_SESSION['superadmin_id'],
            $_SESSION['superadmin_name'] ?? 'SuperAdmin',
            'Updated transaction #' . $transactionId . ': ' . $changesSummary,
            $transactionId,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
        
        // Build detailed success message
        if (!empty($changes)) {
            $success = 'Transaction #' . $transactionId . ' updated successfully!|' . implode('; ', $changes);
        } else {
            $success = 'Transaction #' . $transactionId . ' updated successfully!';
        }
    } catch (Exception $e) {
        $error = 'Error updating transaction: ' . $e->getMessage();
    }
}

// Redirect to prevent form resubmission on page refresh
if (isset($_POST['action']) && ($success || $error)) {
    // Preserve success/error message URL or use session
    if ($success) {
        $_SESSION['transaction_message'] = ['type' => 'success', 'message' => $success];
    } elseif ($error) {
        $_SESSION['transaction_message'] = ['type' => 'error', 'message' => $error];
    }
    
    // Remove POST data and redirect
    header('Location: manage-transactions.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit();
}

// Check if Verified column exists, if not create it
try {
    $columnCheck = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'Verified'");
    $verifiedColumnExists = $columnCheck->rowCount() > 0;
    
    if (!$verifiedColumnExists) {
        $pdo->exec("ALTER TABLE transactions ADD COLUMN Verified TINYINT(1) DEFAULT 0 AFTER Remarks");
        $verifiedColumnExists = true;
    }
} catch (Exception $e) {
    $verifiedColumnExists = false;
}

// Get filter parameters
$filterYear = isset($_GET['year']) ? intval($_GET['year']) : 0;
$filterMember = isset($_GET['member']) ? intval($_GET['member']) : 0;
$filterMonth = isset($_GET['month']) ? trim($_GET['month']) : '';
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$filterMode = isset($_GET['mode']) ? trim($_GET['mode']) : '';
$filterType = isset($_GET['type']) ? trim($_GET['type']) : '';
$filterTransactionType = isset($_GET['transaction_type']) ? trim($_GET['transaction_type']) : '';
$filterVerified = isset($_GET['verified']) ? trim($_GET['verified']) : '';
$filterCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$searchType = isset($_GET['search_type']) ? trim($_GET['search_type']) : 'all';
$sortBy = isset($_GET['sort']) ? trim($_GET['sort']) : 'date_desc';
$perPage = isset($_GET['per_page']) ? ($_GET['per_page'] === 'all' ? 1000 : intval($_GET['per_page'])) : 50;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($currentPage - 1) * $perPage;

// Fetch expense categories for filter
$expenseCategoriesList = [];
try {
    $catStmt = $pdo->query("SELECT DISTINCT Category FROM expenses ORDER BY Category");
    $expenseCategoriesList = $catStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $expenseCategoriesList = [];
}

// Define months array for filter dropdown
$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

// Fetch years for filter
try {
    $stmt = $pdo->query("SELECT * FROM academic_years ORDER BY StartDate DESC");
    $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // DO NOT auto-select year - allow "All Years" (0) to remain as is
    // User can manually select a specific year if needed
} catch (Exception $e) {
    $years = [];
}

// SEPARATE MEMBER LISTS FOR ADD TRANSACTION vs FILTER
// 1. Members for ADD TRANSACTION modal - only those with net balance > 0 from ACTIVE year
// 2. Members for FILTER dropdown - ALL members from selected filter year

// Fetch members for ADD TRANSACTION - with net balance > 0 from ACTIVE year
try {
    // Get active academic year
    $activeYearStmt = $pdo->query("SELECT YearID FROM academic_years WHERE Status = 'Active' LIMIT 1");
    $activeYear = $activeYearStmt->fetch(PDO::FETCH_ASSOC);
    $activeYearID = $activeYear['YearID'] ?? 0;
    
    // Fetch members assigned to active year with net balance > 0
    if ($activeYearID > 0) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT 
                m.MemberID, 
                m.MemberName,
                COALESCE(mnb.NetBalance, 
                    (SELECT COALESCE(SUM(COALESCE(DistributedAmount, 0) + COALESCE(InterestAmount, 0) - COALESCE(CollectionAmount, 0)), 0)
                     FROM transactions 
                     WHERE MemberID = m.MemberID AND YearID = ?)) as NetBalance
            FROM members m
            INNER JOIN year_members ym ON m.MemberID = ym.MemberID
            LEFT JOIN member_net_balance mnb ON m.MemberID = mnb.MemberID AND mnb.YearID = ?
            WHERE ym.YearID = ? AND ym.Status = 'Active'
            ORDER BY m.MemberID ASC
        ");
        $stmt->execute([$activeYearID, $activeYearID, $activeYearID]);
        $membersForAddTransaction = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $membersForAddTransaction = [];
    }
} catch (Exception $e) {
    $membersForAddTransaction = [];
}

// Fetch members for FILTER dropdown - ALL members from selected year
try {
    if ($filterYear > 0) {
        // Show only members assigned to the selected year
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.MemberID, m.MemberName
            FROM members m
            INNER JOIN year_members ym ON m.MemberID = ym.MemberID
            WHERE ym.YearID = ? AND ym.Status = 'Active'
            ORDER BY m.MemberID ASC
        ");
        $stmt->execute([$filterYear]);
        $membersForFilter = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // When "All Years" selected, show all members who are assigned to ANY year
        $stmt = $pdo->query("
            SELECT DISTINCT m.MemberID, m.MemberName
            FROM members m
            INNER JOIN year_members ym ON m.MemberID = ym.MemberID
            WHERE ym.Status = 'Active'
            ORDER BY m.MemberID ASC
        ");
        $membersForFilter = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $membersForFilter = [];
}

// Build query with filters
$verifiedSelect = $verifiedColumnExists ? "IFNULL(t.Verified, 0) as Verified," : "0 as Verified,";

$query = "
    SELECT 
        t.TransactionID,
        t.YearID,
        t.MemberID,
        t.TransactionDate,
        t.CollectionAmount,
        t.DistributedAmount,
        t.InterestAmount,
        t.FixedAmount,
        t.NextMonthDue,
        t.PaymentMode,
        t.Remarks,
        t.CreatedAt,
        t.UpdatedAt,
        DATE_FORMAT(t.TransactionDate, '%M %Y') AS MonthName,
        m.MemberName,
        m.Phone as MemberPhone,
        y.YearName,
        $verifiedSelect
        CASE 
            WHEN t.DistributedAmount > 0 AND t.CollectionAmount = 0 THEN 'Distribution'
            WHEN t.CollectionAmount > 0 AND t.DistributedAmount = 0 THEN 'Collection'
            WHEN t.CollectionAmount > 0 AND t.DistributedAmount > 0 THEN 'Both'
            ELSE 'Other'
        END as TransactionType,
        COALESCE((
            SELECT SUM(COALESCE(DistributedAmount, 0) + COALESCE(InterestAmount, 0) - COALESCE(CollectionAmount, 0))
            FROM transactions
            WHERE MemberID = t.MemberID AND YearID = t.YearID AND TransactionDate <= t.TransactionDate
        ), 0) as MemberNetBalance,
        (SELECT COUNT(*) FROM proof_attachments pa WHERE pa.EntityType = 'transaction' AND pa.EntityID = t.TransactionID AND pa.DeletedAt IS NULL) as ProofCount
    FROM transactions t
    LEFT JOIN members m ON t.MemberID = m.MemberID
    LEFT JOIN academic_years y ON t.YearID = y.YearID
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
    $query .= " AND DATE_FORMAT(t.TransactionDate, '%M') = ?";
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

if (!empty($filterVerified) && $verifiedColumnExists) {
    $verifiedValue = ($filterVerified === 'verified') ? 1 : 0;
    $query .= " AND IFNULL(t.Verified, 0) = ?";
    $params[] = $verifiedValue;
}

if (!empty($searchQuery)) {
    if ($searchType === 'member') {
        $query .= " AND m.MemberName LIKE ?";
        $params[] = '%' . $searchQuery . '%';
    } elseif ($searchType === 'phone') {
        $query .= " AND m.Phone LIKE ?";
        $params[] = '%' . $searchQuery . '%';
    } elseif ($searchType === 'remarks') {
        $query .= " AND t.Remarks LIKE ?";
        $params[] = '%' . $searchQuery . '%';
    } else {
        // Search all fields
        $query .= " AND (m.MemberName LIKE ? OR t.Remarks LIKE ? OR m.Phone LIKE ?)";
        $searchParam = '%' . $searchQuery . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
}

if (!empty($filterTransactionType)) {
    if ($filterTransactionType === 'collection') {
        $query .= " AND t.CollectionAmount > 0";
    } elseif ($filterTransactionType === 'distribution') {
        $query .= " AND t.DistributedAmount > 0";
    } elseif ($filterTransactionType === 'both') {
        $query .= " AND t.CollectionAmount > 0 AND t.DistributedAmount > 0";
    }
}

// Amount range filters
if (isset($_GET['amount_min']) && $_GET['amount_min'] !== '') {
    $amountMin = floatval($_GET['amount_min']);
    $query .= " AND (t.CollectionAmount >= ? OR t.DistributedAmount >= ?)";
    $params[] = $amountMin;
    $params[] = $amountMin;
}

if (isset($_GET['amount_max']) && $_GET['amount_max'] !== '') {
    $amountMax = floatval($_GET['amount_max']);
    $query .= " AND (t.CollectionAmount <= ? OR t.DistributedAmount <= ?)";
    $params[] = $amountMax;
    $params[] = $amountMax;
}

// Has interest filter
if (isset($_GET['has_interest']) && $_GET['has_interest'] !== '') {
    if ($_GET['has_interest'] === 'yes') {
        $query .= " AND t.InterestAmount > 0";
    } elseif ($_GET['has_interest'] === 'no') {
        $query .= " AND (t.InterestAmount = 0 OR t.InterestAmount IS NULL)";
    }
}

// The main query's filters (from "WHERE 1=1" on), reused by the pagination count below with the same $params
$whereClause = substr($query, strpos($query, 'WHERE 1=1'));

// Calculate summary statistics from database
try {
    $statsQuery = "
        SELECT 
            SUM(COALESCE(t.CollectionAmount, 0)) as total_collection,
            SUM(COALESCE(t.DistributedAmount, 0)) as total_distribution,
            SUM(COALESCE(t.InterestAmount, 0)) as total_interest,
            SUM(COALESCE(t.FixedAmount, 0)) as total_fixed,
            SUM(COALESCE(t.DistributedAmount, 0) + COALESCE(t.InterestAmount, 0) - COALESCE(t.CollectionAmount, 0)) as total_net_balance,
            SUM(CASE WHEN IFNULL(t.Verified, 0) = 1 THEN 1 ELSE 0 END) as verified_count,
            COUNT(*) as total_records_for_stats
        FROM transactions t
        LEFT JOIN members m ON t.MemberID = m.MemberID
        LEFT JOIN academic_years y ON t.YearID = y.YearID
        WHERE 1=1
    ";
    
    // Build stats params in exact same order as main query, excluding verified
    $statsParams = [];
    
    if ($filterYear > 0) {
        $statsQuery .= " AND t.YearID = ?";
        $statsParams[] = $filterYear;
    }
    if ($filterMember > 0) {
        $statsQuery .= " AND t.MemberID = ?";
        $statsParams[] = $filterMember;
    }
    if (!empty($filterMonth)) {
        $statsQuery .= " AND DATE_FORMAT(t.TransactionDate, '%M') = ?";
        $statsParams[] = $filterMonth;
    }
    if (!empty($filterDateFrom)) {
        $statsQuery .= " AND t.TransactionDate >= ?";
        $statsParams[] = $filterDateFrom;
    }
    if (!empty($filterDateTo)) {
        $statsQuery .= " AND t.TransactionDate <= ?";
        $statsParams[] = $filterDateTo;
    }
    if (!empty($filterMode)) {
        $statsQuery .= " AND t.PaymentMode = ?";
        $statsParams[] = $filterMode;
    }
    // Skip verified filter for stats
    
    if (!empty($searchQuery)) {
        if ($searchType === 'member') {
            $statsQuery .= " AND m.MemberName LIKE ?";
            $statsParams[] = '%' . $searchQuery . '%';
        } elseif ($searchType === 'phone') {
            $statsQuery .= " AND m.Phone LIKE ?";
            $statsParams[] = '%' . $searchQuery . '%';
        } elseif ($searchType === 'remarks') {
            $statsQuery .= " AND t.Remarks LIKE ?";
            $statsParams[] = '%' . $searchQuery . '%';
        } else {
            $statsQuery .= " AND (m.MemberName LIKE ? OR t.Remarks LIKE ? OR m.Phone LIKE ?)";
            $searchParam = '%' . $searchQuery . '%';
            $statsParams[] = $searchParam;
            $statsParams[] = $searchParam;
            $statsParams[] = $searchParam;
        }
    }
    
    if (!empty($filterTransactionType)) {
        if ($filterTransactionType === 'collection') {
            $statsQuery .= " AND t.CollectionAmount > 0";
        } elseif ($filterTransactionType === 'distribution') {
            $statsQuery .= " AND t.DistributedAmount > 0";
        } elseif ($filterTransactionType === 'both') {
            $statsQuery .= " AND t.CollectionAmount > 0 AND t.DistributedAmount > 0";
        }
    }
    
    if (isset($_GET['amount_min']) && $_GET['amount_min'] !== '') {
        $amountMin = floatval($_GET['amount_min']);
        $statsQuery .= " AND (t.CollectionAmount >= ? OR t.DistributedAmount >= ?)";
        $statsParams[] = $amountMin;
        $statsParams[] = $amountMin;
    }
    
    if (isset($_GET['amount_max']) && $_GET['amount_max'] !== '') {
        $amountMax = floatval($_GET['amount_max']);
        $statsQuery .= " AND (t.CollectionAmount <= ? OR t.DistributedAmount <= ?)";
        $statsParams[] = $amountMax;
        $statsParams[] = $amountMax;
    }
    
    if (isset($_GET['has_interest']) && $_GET['has_interest'] !== '') {
        if ($_GET['has_interest'] === 'yes') {
            $statsQuery .= " AND t.InterestAmount > 0";
        } elseif ($_GET['has_interest'] === 'no') {
            $statsQuery .= " AND (t.InterestAmount = 0 OR t.InterestAmount IS NULL)";
        }
    }
    
    $statsStmt = $pdo->prepare($statsQuery);
    $statsStmt->execute($statsParams);
    $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    $totalCollection = floatval($statsRow['total_collection'] ?? 0);
    $totalDistribution = floatval($statsRow['total_distribution'] ?? 0);
    $totalInterest = floatval($statsRow['total_interest'] ?? 0);
    $totalFixedAmount = floatval($statsRow['total_fixed'] ?? 0);
    $totalNetBalance = floatval($statsRow['total_net_balance'] ?? 0);
    $verifiedCount = intval($statsRow['verified_count'] ?? 0);
    $totalRecordsForStats = intval($statsRow['total_records_for_stats'] ?? 0);
    $unverifiedCount = $totalRecordsForStats - $verifiedCount;
} catch (Exception $e) {
    $totalCollection = 0;
    $totalDistribution = 0;
    $totalInterest = 0;
    $totalFixedAmount = 0;
    $totalNetBalance = 0;
    $verifiedCount = 0;
    $unverifiedCount = 0;
    $totalRecordsForStats = 0;
    error_log("Stats Query Error: " . $e->getMessage());
    error_log("Stats Query: " . $statsQuery);
    error_log("Stats Params: " . print_r($statsParams, true));
}

// Count total records for pagination (with all filters including verified)
try {
    $countQuery = "
        SELECT COUNT(*) as total 
        FROM transactions t
        LEFT JOIN members m ON t.MemberID = m.MemberID
        LEFT JOIN academic_years y ON t.YearID = y.YearID
        " . $whereClause;
    
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
    $totalRecords = intval($countResult['total'] ?? 0);
    $totalPages = max(1, ceil($totalRecords / $perPage));
} catch (Exception $e) {
    $totalRecords = 0;
    $totalPages = 1;
    error_log("Count Query Error: " . $e->getMessage());
}

// Add sorting
switch ($sortBy) {
    case 'date_asc':
        $query .= " ORDER BY t.TransactionDate ASC, t.CreatedAt ASC";
        break;
    case 'amount_desc':
        $query .= " ORDER BY (t.CollectionAmount + t.DistributedAmount) DESC";
        break;
    case 'amount_asc':
        $query .= " ORDER BY (t.CollectionAmount + t.DistributedAmount) ASC";
        break;
    case 'member':
        $query .= " ORDER BY m.MemberName ASC";
        break;
    case 'interest_desc':
        $query .= " ORDER BY t.InterestAmount DESC";
        break;
    case 'date_desc':
    default:
        $query .= " ORDER BY t.TransactionDate DESC, t.CreatedAt DESC";
        break;
}

// Add pagination
$query .= " LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

// Check if we're fetching expenses instead of regular transactions
$isExpenseFilter = ($filterTransactionType === 'expense');

if ($isExpenseFilter) {
    // Fetch expenses instead of transactions
    $expenseQuery = "
        SELECT 
            e.ExpenseID as TransactionID,
            e.YearID,
            0 as MemberID,
            e.ExpenseDate as TransactionDate,
            0 as CollectionAmount,
            e.Amount as DistributedAmount,
            0 as InterestAmount,
            0 as FixedAmount,
            NULL as NextMonthDue,
            e.PaymentMode,
            e.Description as Remarks,
            e.CreatedAt,
            e.UpdatedAt,
            DATE_FORMAT(e.ExpenseDate, '%M %Y') AS MonthName,
            e.Category as MemberName,
            CONCAT(e.VendorName, ' - ', e.Description) as MemberPhone,
            ay.YearName,
            0 as Verified,
            'Expense' as TransactionType,
            e.Amount as MemberNetBalance,
            e.VendorName as ExpenseVendor,
            e.Status as ExpenseStatus,
            e.Description as ExpenseDescription,
            (SELECT COUNT(*) FROM proof_attachments pa WHERE pa.EntityType = 'expense' AND pa.EntityID = e.ExpenseID AND pa.DeletedAt IS NULL) as ProofCount
        FROM expenses e
        LEFT JOIN academic_years ay ON e.YearID = ay.YearID
        WHERE 1=1
    ";
    $expenseParams = [];
    
    if ($filterYear > 0) {
        $expenseQuery .= " AND e.YearID = ?";
        $expenseParams[] = $filterYear;
    }
    
    if (!empty($filterDateFrom)) {
        $expenseQuery .= " AND e.ExpenseDate >= ?";
        $expenseParams[] = $filterDateFrom;
    }
    
    if (!empty($filterDateTo)) {
        $expenseQuery .= " AND e.ExpenseDate <= ?";
        $expenseParams[] = $filterDateTo;
    }
    
    if (!empty($filterMode)) {
        $expenseQuery .= " AND e.PaymentMode = ?";
        $expenseParams[] = $filterMode;
    }
    
    if (!empty($filterCategory)) {
        $expenseQuery .= " AND e.Category = ?";
        $expenseParams[] = $filterCategory;
    }
    
    if (!empty($searchQuery)) {
        $expenseQuery .= " AND (e.Category LIKE ? OR e.Description LIKE ? OR e.VendorName LIKE ?)";
        $searchParam = '%' . $searchQuery . '%';
        $expenseParams[] = $searchParam;
        $expenseParams[] = $searchParam;
        $expenseParams[] = $searchParam;
    }
    
    $expenseQuery .= " ORDER BY e.ExpenseDate DESC, e.CreatedAt DESC LIMIT ? OFFSET ?";
    $expenseParams[] = $perPage;
    $expenseParams[] = $offset;
    
    try {
        $stmt = $pdo->prepare($expenseQuery);
        $stmt->execute($expenseParams);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Update stats for expenses
        $totalCollection = 0;
        $totalDistribution = $stats['total_distribution'] ?? 0;
        $totalInterest = 0;
        
        // Get expense statistics
        $expenseStatsQuery = "SELECT SUM(Amount) as total_expense, COUNT(*) as count FROM expenses WHERE 1=1";
        $expenseStatsParams = [];
        if ($filterYear > 0) { $expenseStatsQuery .= " AND YearID = ?"; $expenseStatsParams[] = $filterYear; }
        if (!empty($filterDateFrom)) { $expenseStatsQuery .= " AND ExpenseDate >= ?"; $expenseStatsParams[] = $filterDateFrom; }
        if (!empty($filterDateTo)) { $expenseStatsQuery .= " AND ExpenseDate <= ?"; $expenseStatsParams[] = $filterDateTo; }
        if (!empty($filterCategory)) { $expenseStatsQuery .= " AND Category = ?"; $expenseStatsParams[] = $filterCategory; }
        
        $statsStmt = $pdo->prepare($expenseStatsQuery);
        $statsStmt->execute($expenseStatsParams);
        $expenseStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        $totalDistribution = $expenseStats['total_expense'] ?? 0;
        $totalRecords = $expenseStats['count'] ?? 0;
        $totalPages = ceil($totalRecords / $perPage);
        
    } catch (Exception $e) {
        $transactions = [];
        $error = 'Error fetching expenses: ' . $e->getMessage();
    }
} else {
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $transactions = [];
        $error = 'Error fetching transactions: ' . $e->getMessage();
        // Debug info (remove in production)
        error_log("Transaction Query Error: " . $e->getMessage());
        error_log("Query: " . $query);
        error_log("Params: " . print_r($params, true));
    }
}

// Get FixedAmount from academic years
$academicYearFixedAmount = 0;
try {
    if ($filterYear > 0) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(FixedAmount), 0) FROM academic_years WHERE YearID = ?");
        $stmt->execute([$filterYear]);
    } else {
        $stmt = $pdo->query("SELECT COALESCE(SUM(FixedAmount), 0) FROM academic_years");
    }
    $academicYearFixedAmount = $stmt->fetchColumn();
} catch (Exception $e) {
    $academicYearFixedAmount = 0;
}

// Net Balance = InterestAmount (from members/transactions) + FixedAmount (from academic years)
$netAmount = $totalInterest + $academicYearFixedAmount;
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Transactions - Super Admin</title>
    <link rel="stylesheet" href="shared-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .container {
            max-width: 100%;
            margin: 0 auto;
            padding: 15px;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-lg);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 15px;
        }

        .header h1 {
            color: white;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .header p {
            color: rgba(255, 255, 255, 0.9);
        }

        .header-buttons {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
        }

        .header-buttons .header-btn {
            padding: 10px 18px;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .header-buttons .header-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.25);
            opacity: 0.95;
        }

        .header-btn.btn-add-transaction {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .header-btn.btn-manage-expense {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .header-btn.btn-add-expense {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .header-btn.btn-event-transactions {
            background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
        }

        .header-btn.btn-add-event-txn {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        }

        .header-btn.btn-budget {
            background: linear-gradient(135deg, #ec4899 0%, #db2777 100%);
        }

        .header-btn.btn-change-amount {
            background: linear-gradient(135deg, #a855f7 0%, #8e44ad 100%);
        }

        .header-btn.btn-expense-reports {
            background: linear-gradient(135deg, #14b8a6 0%, #0d9488 100%);
        }

        .header-btn.btn-import {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 15px;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            border-left: 4px solid #667eea;
        }

        .stat-card h3 {
            color: var(--text-secondary);
            font-size: 12px;
            margin-bottom: 8px;
        }

        .stat-card .amount {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stat-card small {
            display: block;
            margin-top: 5px;
            color: var(--text-secondary);
            font-size: 11px;
        }

        .search-group {
            grid-column: span 1;
        }

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

        .form-group small {
            display: block;
            margin-top: 3px;
            font-size: 11px;
            color: var(--text-secondary);
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

        input[type="date"].form-control,
        input[type="number"].form-control {
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

        .transcation-grid-row-4,
        .transcation-grid-row-5,
        .transcation-grid-row-6 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 0;
            margin-bottom: 0;
            align-items: flex-start;
        }

        .form-group.section-separator {
            margin-top: 0;
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

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-warning {
            background: #ffc107;
            color: #000;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .table-container {
            background: var(--card-bg);
            border-radius: 12px;
            overflow-x: auto;
            overflow-y: auto;
            max-height: calc(100vh - 200px);
            box-shadow: var(--shadow-md);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: white;
            border-bottom: 2px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        tr:hover {
            background: var(--bg-secondary);
        }

        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-collection { background: #d4edda; color: #155724; }
        .badge-distribution { background: #fff3cd; color: #856404; }
        .badge-both { background: #d1ecf1; color: #0c5460; }
        .badge-other { background: #e2e3e5; color: #383d41; }
        
        .badge-verified { 
            background: #d4edda; 
            color: #155724; 
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .badge-unverified { 
            background: #fff3cd; 
            color: #856404; 
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .amount-positive { color: #28a745; font-weight: 600; }
        .amount-negative { color: #dc3545; font-weight: 600; }
        
        .text-success { color: #28a745; }
        .text-danger { color: #dc3545; }
        
        .stat-card small {
            display: block;
            margin-top: 5px;
            color: var(--text-secondary);
            font-size: 12px;
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
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--card-bg);
            padding: 0;
            border-radius: 16px;
            max-width: 850px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px 16px 0 0;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .modal-header h2 {
            color: #fff;
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-modal {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.25);
            font-size: 22px;
            cursor: pointer;
            color: #fff;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .close-modal:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 24px 28px;
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .modal-body .form-group {
            margin-bottom: 16px;
        }

        .modal-body .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 13px;
            color: var(--text-secondary);
            letter-spacing: 0.3px;
        }

        .modal-body .form-control {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 14px;
            background: var(--card-bg);
            color: var(--text-primary);
            transition: all 0.3s ease;
            box-sizing: border-box;
        }

        .modal-body .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
            outline: none;
        }

        .modal-body select.form-control {
            cursor: pointer;
            appearance: auto;
        }

        .modal-body textarea.form-control {
            min-height: 70px;
            resize: vertical;
        }

        .modal-body small {
            display: block;
            margin-top: 4px;
            font-size: 11px;
            color: var(--text-secondary);
            opacity: 0.8;
        }

        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding: 16px 28px;
            background: var(--bg-secondary);
            border-top: 1px solid var(--border-color);
            border-radius: 0 0 16px 16px;
        }

        [data-theme="dark"] {
            --card-bg: #1e293b;
            --bg-secondary: #0f172a;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --border-color: #334155;
        }
        
        /* Information Dialog Styles */
        .info-dialog-overlay {
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
        
        .info-dialog-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .info-dialog-modal {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
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
        
        .info-dialog-header {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .info-dialog-header i {
            font-size: 28px;
        }
        
        .info-dialog-header-content h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }
        
        .info-dialog-body {
            padding: 25px;
        }
        
        .info-detail {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-detail:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .info-label {
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }
        
        .info-value {
            color: #1f2937;
            word-break: break-word;
        }
        
        .info-value.amount {
            color: #059669;
            font-weight: 600;
        }
        
        .info-dialog-footer {
            padding: 15px 25px;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            border-top: 1px solid #e0e0e0;
            background: #fafafa;
        }
        
        .btn-info-close {
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
        
        .btn-info-close:hover {
            background: #f0f0f0;
            border-color: #999;
        }
        
        .btn-info-proceed {
            padding: 10px 24px;
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
        }
        
        .btn-info-proceed:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
        }
        
        /* Confirmation Dialog Styles */
        .confirm-dialog-overlay {
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
            z-index: 2001;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .confirm-dialog-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .confirm-dialog-modal {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 450px;
            width: 90%;
            overflow: hidden;
            animation: slideUp 0.3s ease;
        }
        
        .confirm-dialog-header {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .confirm-dialog-header i {
            font-size: 28px;
        }
        
        .confirm-dialog-header-content h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }
        
        .confirm-dialog-body {
            padding: 25px;
            color: #333;
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .confirm-dialog-footer {
            padding: 15px 25px;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            border-top: 1px solid #e0e0e0;
            background: #fafafa;
        }
        
        .btn-confirm-cancel {
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
        
        .btn-confirm-cancel:hover {
            background: #f0f0f0;
            border-color: #999;
        }
        
        .btn-confirm-submit {
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
        
        .btn-confirm-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        
        /* Delete Dialog Styles */
        .delete-dialog-overlay {
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
            z-index: 2002;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .delete-dialog-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .delete-dialog-modal {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            overflow: hidden;
            animation: slideUp 0.3s ease;
        }
        
        .delete-dialog-header {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            padding: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .delete-dialog-header i {
            font-size: 28px;
        }
        
        .delete-dialog-header-content h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }
        
        .delete-dialog-body {
            padding: 25px;
        }
        
        .delete-detail {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .delete-detail:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .delete-label {
            font-weight: 600;
            color: #374151;
            font-size: 13px;
        }
        
        .delete-value {
            color: #1f2937;
            word-break: break-word;
        }
        
        .delete-value.amount {
            color: #dc2626;
            font-weight: 600;
        }
        
        .delete-warning {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0 0 0;
            color: #991b1b;
            font-size: 13px;
            line-height: 1.6;
        }
        
        .delete-dialog-footer {
            padding: 15px 25px;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            border-top: 1px solid #e0e0e0;
            background: #fafafa;
        }
        
        .btn-delete-cancel {
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
        
        .btn-delete-cancel:hover {
            background: #f0f0f0;
            border-color: #999;
        }
        
        .btn-delete-confirm {
            padding: 10px 24px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 14px;
        }
        
        .btn-delete-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
        }

        /* Toast Notification Styles */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            pointer-events: none;
        }

        .toast {
            background: white;
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 10px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: flex-start;
            gap: 12px;
            min-width: 320px;
            max-width: 500px;
            animation: slideInRight 0.3s ease;
            pointer-events: auto;
            border-left: 4px solid;
        }

        .toast.success {
            border-left-color: #28a745;
            background: #f0f9f4;
        }

        .toast.success i {
            color: #28a745;
            font-size: 20px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .toast.success .toast-message {
            color: #155724;
            font-weight: 500;
        }

        .toast.success .toast-details {
            color: #155724;
            font-size: 12px;
            line-height: 1.5;
            opacity: 0.85;
        }

        .toast.error {
            border-left-color: #dc3545;
            background: #fef5f6;
        }

        .toast.error i {
            color: #dc3545;
            font-size: 20px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .toast.error .toast-message {
            color: #721c24;
            font-weight: 500;
        }

        .toast.error .toast-details {
            color: #721c24;
            font-size: 12px;
            line-height: 1.5;
            opacity: 0.85;
        }

        .toast.info {
            border-left-color: #0dcaf0;
            background: #f5f7ff;
        }

        .toast.info i {
            color: #0dcaf0;
            font-size: 20px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .toast.info .toast-message {
            color: #004085;
            font-weight: 500;
        }

        .toast-content {
            flex: 1;
        }

        .toast-message {
            font-size: 14px;
            margin-bottom: 0;
        }

        .toast-details {
            font-size: 12px;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .toast-details-item {
            margin: 3px 0;
            padding-left: 0;
        }

        .toast-close {
            background: none;
            border: none;
            cursor: pointer;
            color: #999;
            font-size: 18px;
            padding: 0;
            margin-left: 10px;
            margin-top: 2px;
            transition: color 0.2s ease;
            flex-shrink: 0;
        }

        .toast-close:hover {
            color: #333;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .toast.removing {
            animation: slideOutRight 0.3s ease;
        }

        /* Dark Theme Styling for Manage Transactions */
        [data-theme="dark"] body {
            background: #0f172a;
            color: #f1f5f9;
        }

        [data-theme="dark"] .container {
            background: #0f172a;
        }

        [data-theme="dark"] .header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        [data-theme="dark"] .header h1,
        [data-theme="dark"] .header p {
            color: #f1f5f9;
        }

        [data-theme="dark"] .stats-grid .stat-card {
            background: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }

        [data-theme="dark"] .stats-grid .stat-card h3 {
            color: #94a3b8;
        }

        [data-theme="dark"] .stats-grid .stat-card .amount {
            color: #f1f5f9;
        }

        [data-theme="dark"] .filter-section {
            background: #1e293b;
            border-color: #334155;
        }

        [data-theme="dark"] .filter-section label {
            color: #f1f5f9;
        }

        [data-theme="dark"] .filter-section input,
        [data-theme="dark"] .filter-section select,
        [data-theme="dark"] .filter-section textarea {
            background: #0f172a;
            color: #f1f5f9;
            border-color: #334155;
        }

        [data-theme="dark"] .filter-section input:focus,
        [data-theme="dark"] .filter-section select:focus,
        [data-theme="dark"] .filter-section textarea:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        [data-theme="dark"] .transactions-table,
        [data-theme="dark"] .table-container {
            background: #1e293b;
            border-color: #334155;
            max-height: 600px;
            display: flex;
            flex-direction: column;
        }

        [data-theme="dark"] .transactions-table h3,
        [data-theme="dark"] .table-container h3 {
            color: #f1f5f9 !important;
            border-bottom-color: #334155;
        }

        [data-theme="dark"] .transactions-table table,
        [data-theme="dark"] .table-container table {
            background: #1e293b;
            color: #f1f5f9;
        }

        [data-theme="dark"] .transactions-table thead,
        [data-theme="dark"] .table-container thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        [data-theme="dark"] .transactions-table th,
        [data-theme="dark"] .table-container th {
            color: white;
            border-color: #334155;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        [data-theme="dark"] .transactions-table td,
        [data-theme="dark"] .table-container td {
            border-color: #334155;
            color: #f1f5f9;
        }

        [data-theme="dark"] .transactions-table tbody tr:hover,
        [data-theme="dark"] .table-container tbody tr:hover {
            background: rgba(102, 126, 234, 0.1);
        }

        [data-theme="dark"] .badge {
            color: #f1f5f9;
        }

        [data-theme="dark"] .badge-success {
            background: #10b981;
            color: white;
        }

        [data-theme="dark"] .badge-danger {
            background: #ef4444;
            color: white;
        }

        [data-theme="dark"] .badge-warning {
            background: #f59e0b;
            color: white;
        }

        [data-theme="dark"] .badge-pending {
            background: #6366f1;
            color: white;
        }

        [data-theme="dark"] .badge-collection {
            background: #10b981;
            color: white;
        }

        [data-theme="dark"] .badge-distribution {
            background: #f59e0b;
            color: white;
        }

        [data-theme="dark"] .badge-both {
            background: #667eea;
            color: white;
        }

        [data-theme="dark"] .badge-other {
            background: #6b7280;
            color: white;
        }

        [data-theme="dark"] .badge-verified {
            background: #10b981;
            color: white;
        }

        [data-theme="dark"] .badge-unverified {
            background: #f59e0b;
            color: white;
        }

        [data-theme="dark"] .amount-positive {
            color: #10b981;
            font-weight: 600;
        }

        [data-theme="dark"] .amount-negative {
            color: #ef4444;
            font-weight: 600;
        }

        [data-theme="dark"] .text-success {
            color: #10b981;
        }

        [data-theme="dark"] .text-danger {
            color: #ef4444;
        }

        [data-theme="dark"] .btn {
            color: white;
        }

        [data-theme="dark"] .btn-primary {
            background: #667eea;
            border-color: #667eea;
        }

        [data-theme="dark"] .btn-primary:hover {
            background: #5568d3;
            border-color: #5568d3;
        }

        [data-theme="dark"] .btn-success {
            background: #10b981;
            border-color: #10b981;
        }

        [data-theme="dark"] .btn-success:hover {
            background: #059669;
            border-color: #059669;
        }

        [data-theme="dark"] .btn-danger {
            background: #ef4444;
            border-color: #ef4444;
        }

        [data-theme="dark"] .btn-danger:hover {
            background: #dc2626;
            border-color: #dc2626;
        }

        [data-theme="dark"] .btn-warning {
            background: #f59e0b;
            border-color: #f59e0b;
        }

        [data-theme="dark"] .btn-warning:hover {
            background: #d97706;
            border-color: #d97706;
        }

        [data-theme="dark"] .modal-content {
            background: var(--card-bg);
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        [data-theme="dark"] .modal-header {
            background: linear-gradient(135deg, #5a67d8 0%, #6b46c1 100%);
            border-color: var(--border-color);
            color: #fff;
        }

        [data-theme="dark"] .modal-body {
            background: var(--card-bg);
            color: var(--text-primary);
        }

        [data-theme="dark"] .modal-body .form-group label {
            color: var(--text-secondary);
        }

        [data-theme="dark"] .modal-body .form-control {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        [data-theme="dark"] .modal-body .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        [data-theme="dark"] .modal-body select.form-control {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        [data-theme="dark"] .modal-body small {
            color: var(--text-secondary);
        }

        [data-theme="dark"] .modal-footer {
            background: var(--bg-secondary);
            border-color: var(--border-color);
        }

        [data-theme="dark"] .close-modal {
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.2);
        }

        [data-theme="dark"] .close-modal:hover {
            background: rgba(255,255,255,0.25);
        }

        [data-theme="dark"] .form-control,
        [data-theme="dark"] .form-select {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        [data-theme="dark"] .form-control:focus,
        [data-theme="dark"] .form-select:focus {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        [data-theme="dark"] .form-label {
            color: var(--text-secondary);
        }

        [data-theme="dark"] .alert {
            border-color: #334155;
            color: #f1f5f9;
        }

        [data-theme="dark"] .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-color: #10b981;
            color: #d1fae5;
        }

        [data-theme="dark"] .alert-danger {
            background: rgba(239, 68, 68, 0.1);
            border-color: #ef4444;
            color: #fecaca;
        }

        [data-theme="dark"] .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            border-color: #f59e0b;
            color: #fed7aa;
        }

        [data-theme="dark"] .alert-info {
            background: rgba(102, 126, 234, 0.1);
            border-color: #667eea;
            color: #c7d2fe;
        }

        [data-theme="dark"] .toast {
            background: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }

        [data-theme="dark"] .toast.success {
            background: #10b981;
            color: white;
        }

        [data-theme="dark"] .toast.error {
            background: #ef4444;
            color: white;
        }

        [data-theme="dark"] .toast.warning {
            background: #f59e0b;
            color: white;
        }

        [data-theme="dark"] .toast.info {
            background: #667eea;
            color: white;
        }

        [data-theme="dark"] .info-dialog-modal {
            background: #1e293b;
            color: #f1f5f9;
        }

        [data-theme="dark"] .info-dialog-modal h2 {
            color: #f1f5f9;
        }

        [data-theme="dark"] .info-dialog-body {
            background: #0f172a;
        }

        [data-theme="dark"] .info-dialog-body p {
            color: #94a3b8;
        }

        [data-theme="dark"] .info-dialog-body strong {
            color: #f1f5f9;
        }

        [data-theme="dark"] input[type="checkbox"],
        [data-theme="dark"] input[type="radio"] {
            accent-color: #667eea;
        }

        [data-theme="dark"] .theme-toggle {
            color: #f1f5f9;
        }

        [data-theme="dark"] .theme-toggle:hover {
            background-color: rgba(102, 126, 234, 0.1);
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .container {
                padding: 12px;
            }

            .header {
                padding: 20px;
                flex-direction: column;
                text-align: center;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                gap: 10px;
                margin-bottom: 20px;
            }

            .stat-card {
                padding: 12px;
            }

            .stat-card h3 {
                font-size: 11px;
            }

            .stat-card .amount {
                font-size: 18px;
            }

            .stat-card small {
                font-size: 10px;
            }

            .filters {
                padding: 15px;
            }

            .form-control {
                padding: 8px 10px;
                font-size: 12px;
            }

            .btn {
                padding: 8px 16px;
                font-size: 12px;
            }

            .table-container {
                max-height: calc(100vh - 500px);
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 10px;
            }

            .btn-sm {
                padding: 4px 8px;
                font-size: 11px;
            }
        }

        @media (max-width: 768px) {
            .container {
                max-width: 100%;
                padding: 8px;
            }

            .header {
                padding: 15px;
                margin-bottom: 15px;
                gap: 10px;
            }

            .header h1 {
                font-size: 20px;
                margin-bottom: 5px;
            }

            .header p {
                font-size: 12px;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
                gap: 8px;
                margin-bottom: 15px;
            }

            .stat-card {
                padding: 10px;
            }

            .stat-card h3 {
                font-size: 10px;
                margin-bottom: 5px;
            }

            .stat-card .amount {
                font-size: 16px;
            }

            .stat-card small {
                font-size: 9px;
                margin-top: 3px;
            }

            .filters {
                padding: 12px;
                margin-bottom: 15px;
            }

            .filter-grid,
            .filter-grid-row-2,
            .filter-grid-row-3,
            .transcation-grid-row-4,
            .transcation-grid-row-5,
            .transcation-grid-row-6 {
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

            .btn-sm {
                padding: 4px 6px;
                font-size: 10px;
            }

            .table-container {
                max-height: calc(100vh - 400px);
                border-radius: 8px;
            }

            table {
                font-size: 11px;
            }

            th, td {
                padding: 8px;
            }

            .badge {
                font-size: 10px;
                padding: 2px 8px;
            }
        }

        /* Collection, Distribution, Interest Amount Colors */
        .collection-amount {
            color: #3498db;
            font-weight: 600;
        }

        .distribution-amount {
            color: #2ecc71;
            font-weight: 600;
        }

        .interest-amount {
            color: #f39c12;
            font-weight: 600;
        }

        /* Table Header Color Classes */
        table thead th.collection-color {
            background-color: rgba(52, 152, 219, 0.7);
            color: white;
        }

        table thead th.distribution-color {
            background-color: rgba(46, 204, 113, 0.7);
            color: white;
        }

        table thead th.interest-color {
            background-color: rgba(243, 156, 18, 0.7);
            color: white;
        }

        /* Table Cell Color Classes */
        td.collection-color,
        td.collection-amount-cell {
            background: rgba(52, 152, 219, 0.05);
        }

        td.distribution-color,
        td.distribution-amount-cell {
            background: rgba(46, 204, 113, 0.05);
        }

        td.interest-color,
        td.interest-amount-cell {
            background: rgba(243, 156, 18, 0.05);
        }

        /* Dark Theme Amount Colors */
        [data-theme="dark"] .collection-amount {
            color: #3498db;
            font-weight: 600;
        }

        [data-theme="dark"] .distribution-amount {
            color: #2ecc71;
            font-weight: 600;
        }

        [data-theme="dark"] .interest-amount {
            color: #f39c12;
            font-weight: 600;
        }

        /* Dark Theme Table Headers */
        [data-theme="dark"] table thead th.collection-color {
            background-color: rgba(52, 152, 219, 0.7);
            color: white;
        }

        [data-theme="dark"] table thead th.distribution-color {
            background-color: rgba(46, 204, 113, 0.7);
            color: white;
        }

        [data-theme="dark"] table thead th.interest-color {
            background-color: rgba(243, 156, 18, 0.7);
            color: white;
        }

        /* Dark Theme Table Cells */
        [data-theme="dark"] td.collection-color,
        [data-theme="dark"] td.collection-amount-cell {
            background: rgba(52, 152, 219, 0.1);
        }

        [data-theme="dark"] td.distribution-color,
        [data-theme="dark"] td.distribution-amount-cell {
            background: rgba(46, 204, 113, 0.1);
        }

        [data-theme="dark"] td.interest-color,
        [data-theme="dark"] td.interest-amount-cell {
            background: rgba(243, 156, 18, 0.1);
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <!-- Toast Container -->
    <div id="toastContainer" class="toast-container"></div>

    <div class="container">
        <div class="header">
            <div>
                <h1>
                    <i class="fas fa-exchange-alt"></i>
                    Manage Transactions
                </h1>
                <p>View, edit, delete, and add transactions with full control</p>
            </div>
            <div class="header-buttons">
                <button class="header-btn btn-add-transaction" onclick="openAddModal()">
                    <i class="fas fa-plus-circle"></i> Add Transaction
                </button>
                <a href="change-amount.php" class="header-btn btn-change-amount" title="Add, deduct and review change amount entries">
                    <i class="fas fa-coins"></i> Change Amount
                </a>
                <a href="accounting/manage-expenses.php" class="header-btn btn-manage-expense">
                    <i class="fas fa-file-invoice-dollar"></i> Manage Expenses
                </a>
                <a href="accounting/add-expense.php" class="header-btn btn-add-expense">
                    <i class="fas fa-plus"></i> Add Expense
                </a>
                <a href="accounting/events/event-transactions.php" class="header-btn btn-event-transactions">
                    <i class="fas fa-calendar-check"></i> Event Transactions
                </a>
                <a href="accounting/events/add-transaction.php" class="header-btn btn-add-event-txn">
                    <i class="fas fa-calendar-plus"></i> Add Event Transaction
                </a>
                <a href="accounting/budget-tracking.php" class="header-btn btn-budget">
                    <i class="fas fa-chart-pie"></i> Budget Tracking
                </a>
                <a href="accounting/expense-reports.php" class="header-btn btn-expense-reports">
                    <i class="fas fa-chart-bar"></i> Expense Reports
                </a>
                <a href="accounting/import-expenses.php" class="header-btn btn-import">
                    <i class="fas fa-file-import"></i> Import Expenses
                </a>
            </div>
        </div>

        <!-- Hidden alert sections - messages will be shown as toast -->
        <?php 
        // Check for messages from session (set after redirect)
        if (isset($_SESSION['transaction_message'])) {
            $msg = $_SESSION['transaction_message'];
            $success = $msg['message'];
            unset($_SESSION['transaction_message']);
        }
        ?>
        <?php if ($success): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo addslashes(htmlspecialchars($success)); ?>', 'success');
            });
        </script>
        <?php endif; ?>

        <?php if ($error): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                showToast('<?php echo addslashes(htmlspecialchars($error)); ?>', 'error');
            });
        </script>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card collection">
                <h3><i class="fas fa-arrow-down"></i> Total Collection</h3>
                <div class="amount">₹<?php echo number_format($totalCollection, 2); ?></div>
                <small><?php echo count(array_filter($transactions, fn($t) => $t['CollectionAmount'] > 0)); ?> transactions</small>
            </div>
            <div class="stat-card distribution">
                <h3><i class="fas fa-arrow-up"></i> Total Distribution</h3>
                <div class="amount">₹<?php echo number_format($totalDistribution, 2); ?></div>
                <small><?php echo count(array_filter($transactions, fn($t) => $t['DistributedAmount'] > 0)); ?> transactions</small>
            </div>
            <div class="stat-card interest">
                <h3><i class="fas fa-percent"></i> Total Interest</h3>
                <div class="amount">₹<?php echo number_format($totalInterest, 2); ?></div>
                <small><?php echo count(array_filter($transactions, fn($t) => $t['InterestAmount'] > 0)); ?> transactions</small>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-balance-scale"></i> Net Balance</h3>
                <div class="amount <?php echo $totalNetBalance >= 0 ? 'text-success' : 'text-danger'; ?>">₹<?php echo number_format(abs($totalNetBalance), 2); ?></div>
                <small><?php echo $totalNetBalance >= 0 ? '(Members Owe)' : '(Colony Owes)'; ?></small>
            </div>
            <div class="stat-card total">
                <h3><i class="fas fa-balance-scale"></i> Net Amount</h3>
                <div class="amount <?php echo $netAmount >= 0 ? 'text-success' : 'text-danger'; ?>">
                    ₹<?php echo number_format(abs($netAmount), 2); ?>
                </div>
                <small>Interest + Total Fixed</small>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-check-circle"></i> Verified</h3>
                <div class="amount"><?php echo $verifiedCount; ?></div>
                <small><?php echo $totalRecords ? round(($verifiedCount/$totalRecords)*100, 1) : 0; ?>% verified</small>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-clock"></i> Unverified</h3>
                <div class="amount"><?php echo $unverifiedCount; ?></div>
                <small>Pending review</small>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <h3 style="margin-bottom: 20px; color: var(--text-primary);">
                <i class="fas fa-filter"></i> Filter & Search Transactions
            </h3>
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Search</label>
                        <div style="display: flex; gap: 0; align-items: stretch;">
                            <select name="search_type" class="form-control" style="width: 130px; border-radius: 8px 0 0 8px; border-right: 1px solid var(--border-color); flex-shrink: 0;">
                                <option value="all" <?php echo ($searchType === 'all') ? 'selected' : ''; ?>>All Fields</option>
                                <option value="member" <?php echo ($searchType === 'member') ? 'selected' : ''; ?>>Member</option>
                                <option value="phone" <?php echo ($searchType === 'phone') ? 'selected' : ''; ?>>Phone</option>
                                <option value="remarks" <?php echo ($searchType === 'remarks') ? 'selected' : ''; ?>>Remarks</option>
                            </select>
                            <input type="text" name="search" class="form-control" placeholder="Enter search term..." value="<?php echo htmlspecialchars($searchQuery); ?>" style="flex: 1; border-radius: 0 8px 8px 0; border-left: none;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Year</label>
                        <select name="year" class="form-control">
                            <option value="0">All Years</option>
                            <?php foreach ($years as $year): ?>
                            <option value="<?php echo $year['YearID']; ?>" <?php echo ($filterYear == $year['YearID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['YearName']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Member</label>
                        <select name="member" class="form-control">
                            <option value="0">All Members</option>
                            <?php foreach ($membersForFilter as $member): ?>
                            <option value="<?php echo $member['MemberID']; ?>" <?php echo ($filterMember == $member['MemberID']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($member['MemberName']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Month</label>
                        <select name="month" class="form-control">
                            <option value="">All Months</option>
                            <?php foreach ($months as $month): ?>
                            <option value="<?php echo $month; ?>" <?php echo ($filterMonth === $month) ? 'selected' : ''; ?>>
                                <?php echo $month; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="filter-grid-row-2">
                    <div class="form-group">
                        <label>Type</label>
                        <select name="transaction_type" class="form-control" id="transactionTypeFilter" onchange="toggleCategoryFilter()">
                            <option value="">All Types</option>
                            <option value="collection" <?php echo ($filterTransactionType === 'collection') ? 'selected' : ''; ?>>Collection Only</option>
                            <option value="distribution" <?php echo ($filterTransactionType === 'distribution') ? 'selected' : ''; ?>>Distribution Only</option>
                            <option value="both" <?php echo ($filterTransactionType === 'both') ? 'selected' : ''; ?>>Both</option>
                            <option value="expense" <?php echo ($filterTransactionType === 'expense') ? 'selected' : ''; ?>>Expense</option>
                        </select>
                    </div>
                    <div class="form-group" id="categoryFilterGroup" style="<?php echo ($filterTransactionType === 'expense') ? '' : 'display: none;'; ?>">
                        <label>Category</label>
                        <select name="category" class="form-control">
                            <option value="">All Categories</option>
                            <?php foreach ($expenseCategoriesList as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($filterCategory === $cat) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($filterDateFrom); ?>" style="margin: auto;">
                    </div>
                    <div class="form-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($filterDateTo); ?>">
                    </div>
                    <div class="form-group">
                        <label>Payment Mode</label>
                        <select name="mode" class="form-control">
                            <option value="">All Modes</option>
                            <option value="Cash" <?php echo ($filterMode == 'Cash') ? 'selected' : ''; ?>>Cash</option>
                            <option value="Online" <?php echo ($filterMode == 'Online') ? 'selected' : ''; ?>>Online</option>
                            <option value="UPI" <?php echo ($filterMode == 'UPI') ? 'selected' : ''; ?>>UPI</option>
                            <option value="Cheque" <?php echo ($filterMode == 'Cheque') ? 'selected' : ''; ?>>Cheque</option>
                            <option value="Bank Transfer" <?php echo ($filterMode == 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="Card" <?php echo ($filterMode == 'Card') ? 'selected' : ''; ?>>Card</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Verification Status</label>
                        <select name="verified" class="form-control">
                            <option value="">All Transactions</option>
                            <option value="verified" <?php echo ($filterVerified == 'verified') ? 'selected' : ''; ?>>✓ Verified Only</option>
                            <option value="unverified" <?php echo ($filterVerified == 'unverified') ? 'selected' : ''; ?>>⏳ Unverified Only</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sort By</label>
                        <select name="sort" class="form-control">
                            <option value="date_desc" <?php echo ($sortBy == 'date_desc') ? 'selected' : ''; ?>>📅 Date (Newest First)</option>
                            <option value="date_asc" <?php echo ($sortBy == 'date_asc') ? 'selected' : ''; ?>>📅 Date (Oldest First)</option>
                            <option value="amount_desc" <?php echo ($sortBy == 'amount_desc') ? 'selected' : ''; ?>>💰 Amount (High to Low)</option>
                            <option value="amount_asc" <?php echo ($sortBy == 'amount_asc') ? 'selected' : ''; ?>>💰 Amount (Low to High)</option>
                            <option value="member" <?php echo ($sortBy == 'member') ? 'selected' : ''; ?>>👤 Member Name (A-Z)</option>
                            <option value="interest_desc" <?php echo ($sortBy == 'interest_desc') ? 'selected' : ''; ?>>📈 Interest (High to Low)</option>
                        </select>
                    </div>
                </div>
                <!-- <div class="filter-grid-row-3">
                    <div class="form-group">
                        <label>Verification Status</label>
                        <select name="verified" class="form-control">
                            <option value="">All Transactions</option>
                            <option value="verified" <?php echo ($filterVerified == 'verified') ? 'selected' : ''; ?>>✓ Verified Only</option>
                            <option value="unverified" <?php echo ($filterVerified == 'unverified') ? 'selected' : ''; ?>>⏳ Unverified Only</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount Range (Min)</label>
                        <input type="number" name="amount_min" class="form-control" placeholder="Min amount" value="<?php echo isset($_GET['amount_min']) ? htmlspecialchars($_GET['amount_min']) : ''; ?>" step="0.01">
                    </div>
                    <div class="form-group">
                        <label>Amount Range (Max)</label>
                        <input type="number" name="amount_max" class="form-control" placeholder="Max amount" value="<?php echo isset($_GET['amount_max']) ? htmlspecialchars($_GET['amount_max']) : ''; ?>" step="0.01">
                    </div>
                    <div class="form-group">
                        <label>Has Interest</label>
                        <select name="has_interest" class="form-control">
                            <option value="">All</option>
                            <option value="yes" <?php echo (isset($_GET['has_interest']) && $_GET['has_interest'] == 'yes') ? 'selected' : ''; ?>>With Interest</option>
                            <option value="no" <?php echo (isset($_GET['has_interest']) && $_GET['has_interest'] == 'no') ? 'selected' : ''; ?>>No Interest</option>
                        </select>
                    </div>
                </div> -->
                <div class="filter-grid-row-3">
                    <!-- <div class="form-group">
                        <label>Sort By</label>
                        <select name="sort" class="form-control">
                            <option value="date_desc" <?php echo ($sortBy == 'date_desc') ? 'selected' : ''; ?>>📅 Date (Newest First)</option>
                            <option value="date_asc" <?php echo ($sortBy == 'date_asc') ? 'selected' : ''; ?>>📅 Date (Oldest First)</option>
                            <option value="amount_desc" <?php echo ($sortBy == 'amount_desc') ? 'selected' : ''; ?>>💰 Amount (High to Low)</option>
                            <option value="amount_asc" <?php echo ($sortBy == 'amount_asc') ? 'selected' : ''; ?>>💰 Amount (Low to High)</option>
                            <option value="member" <?php echo ($sortBy == 'member') ? 'selected' : ''; ?>>👤 Member Name (A-Z)</option>
                            <option value="interest_desc" <?php echo ($sortBy == 'interest_desc') ? 'selected' : ''; ?>>📈 Interest (High to Low)</option>
                        </select>
                    </div> -->
                    <div class="form-group">
                        <label>Results Per Page</label>
                        <select name="per_page" class="form-control">
                            <option value="25" <?php echo ($perPage == 25) ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo ($perPage == 50) ? 'selected' : ''; ?>>50 (Default)</option>
                            <option value="100" <?php echo ($perPage == 100) ? 'selected' : ''; ?>>100</option>
                            <option value="200" <?php echo ($perPage == 200) ? 'selected' : ''; ?>>200</option>
                            <option value="500" <?php echo ($perPage == 500) ? 'selected' : ''; ?>>500</option>
                            <option value="all" <?php echo ($perPage == 1000) ? 'selected' : ''; ?>>📋 All Transactions</option>
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
                        <a href="manage-transactions.php" class="btn btn-secondary" style="width: 90%; height: 20px; display: inline-flex; align-items: center; justify-content: center; text-decoration: none;">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div id="exportActionsBar" style="background: var(--card-bg); padding: 15px; border-radius: 12px; margin-bottom: 20px; box-shadow: var(--shadow-md); display: flex; justify-content: flex-end; gap: 10px; position: sticky; top: 0; z-index: 100;">
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
                <div id="bulkActionsBar" style="display: none; background: var(--card-bg); padding: 20px; border-radius: 12px; margin-bottom: 20px; box-shadow: var(--shadow-md);">
            <div style="margin-bottom: 15px;">
                <strong style="color: var(--text-primary); font-size: 16px;">
                    <i class="fas fa-check-square"></i> <span id="selectedCount">0</span> transaction(s) selected
                </strong>
            </div>
            
            <!-- Bulk Action Options -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 150px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; color: var(--text-secondary); font-size: 13px; font-weight: 600;">
                        <i class="fas fa-calendar-alt"></i> CHANGE NEXT DUE DATE
                    </label>
                    <input type="date" id="bulkNextDue" class="form-control" style="height: 20px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; color: var(--text-secondary); font-size: 13px; font-weight: 600;">
                        <i class="fas fa-money-bill-wave"></i> CHANGE PAYMENT MODE
                    </label>
                    <select id="bulkMode" class="form-control" style="height: 43px;">
                        <option value="">-- Select Mode --</option>
                        <option value="Cash">Cash</option>
                        <option value="Online">Online</option>
                        <option value="UPI">UPI</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Card">Card</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; color: var(--text-secondary); font-size: 13px; font-weight: 600;">
                        <i class="fas fa-check-circle"></i> CHANGE VERIFICATION STATUS
                    </label>
                    <select id="bulkStatus" class="form-control" style="height: 43px;">
                        <option value="">-- Select Status --</option>
                        <option value="1">✓ Verified</option>
                        <option value="0">⏳ Unverified</option>
                    </select>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; padding-top: 15px; border-top: 1px solid var(--border-color);">
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn" style="background: #6c757d; color: white;" onclick="selectAllTransactions()">
                        <i class="fas fa-check-double"></i> Select All
                    </button>
                    <button type="button" class="btn" style="background: #ffc107; color: #000;" onclick="deselectAllTransactions()">
                        <i class="fas fa-times"></i> Deselect All
                    </button>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn" style="background: #17a2b8; color: white;" onclick="applyBulkChanges()">
                        <i class="fas fa-save"></i> Apply Changes
                    </button>
                    <button type="button" class="btn btn-danger" onclick="bulkDelete()">
                        <i class="fas fa-trash"></i> Delete Selected
                    </button>
                    <button type="button" class="btn" style="background: #6c757d; color: white;" onclick="toggleBulkMode()">
                        <i class="fas fa-ban"></i> Cancel
                    </button>
                </div>
            </div>
        </div>
            </form>
        </div>

        <!-- Export and Bulk Actions Bar -->
        <!-- <div id="exportActionsBar" style="background: var(--card-bg); padding: 15px; border-radius: 12px; margin-bottom: 20px; box-shadow: var(--shadow-md); display: flex; justify-content: flex-end; gap: 10px; position: sticky; top: 0; z-index: 100;">
            <button type="button" class="btn" style="background: #dc3545; color: white;" onclick="exportPDF()">
                <i class="fas fa-file-pdf"></i> Export PDF
            </button>
            <button type="button" class="btn" style="background: #28a745; color: white;" onclick="exportCSV()">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
            <button type="button" class="btn" style="background: #17a2b8; color: white;" onclick="toggleBulkMode()">
                <i class="fas fa-check-square"></i> Bulk Select
            </button>
        </div> -->

        <!-- Bulk Actions Bar (Hidden by default) -->
        <!-- <div id="bulkActionsBar" style="display: none; background: var(--card-bg); padding: 20px; border-radius: 12px; margin-bottom: 20px; box-shadow: var(--shadow-md);">
            <div style="margin-bottom: 15px;">
                <strong style="color: var(--text-primary); font-size: 16px;">
                    <i class="fas fa-check-square"></i> <span id="selectedCount">0</span> transaction(s) selected
                </strong>
            </div> -->
            
            <!-- Bulk Action Options -->
            <!-- <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; color: var(--text-secondary); font-size: 13px; font-weight: 600;">
                        <i class="fas fa-calendar-alt"></i> CHANGE NEXT DUE DATE
                    </label>
                    <input type="date" id="bulkNextDue" class="form-control" style="height: 42px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; color: var(--text-secondary); font-size: 13px; font-weight: 600;">
                        <i class="fas fa-money-bill-wave"></i> CHANGE PAYMENT MODE
                    </label>
                    <select id="bulkMode" class="form-control" style="height: 42px;">
                        <option value="">-- Select Mode --</option>
                        <option value="Cash">Cash</option>
                        <option value="Online">Online</option>
                        <option value="UPI">UPI</option>
                        <option value="Cheque">Cheque</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Card">Card</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; color: var(--text-secondary); font-size: 13px; font-weight: 600;">
                        <i class="fas fa-check-circle"></i> CHANGE VERIFICATION STATUS
                    </label>
                    <select id="bulkStatus" class="form-control" style="height: 42px;">
                        <option value="">-- Select Status --</option>
                        <option value="1">✓ Verified</option>
                        <option value="0">⏳ Unverified</option>
                    </select>
                </div>
            </div>

             Action Buttons -->
            <!-- <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; padding-top: 15px; border-top: 1px solid var(--border-color);">
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn" style="background: #6c757d; color: white;" onclick="selectAllTransactions()">
                        <i class="fas fa-check-double"></i> Select All
                    </button>
                    <button type="button" class="btn" style="background: #ffc107; color: #000;" onclick="deselectAllTransactions()">
                        <i class="fas fa-times"></i> Deselect All
                    </button>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="btn" style="background: #17a2b8; color: white;" onclick="applyBulkChanges()">
                        <i class="fas fa-save"></i> Apply Changes
                    </button>
                    <button type="button" class="btn btn-danger" onclick="bulkDelete()">
                        <i class="fas fa-trash"></i> Delete Selected
                    </button>
                    <button type="button" class="btn" style="background: #6c757d; color: white;" onclick="toggleBulkMode()">
                        <i class="fas fa-ban"></i> Cancel
                    </button>
                </div>
            </div> --> 
        </div>

        <!-- Pagination Info -->
        <?php if ($totalRecords > 0): ?>
        <div style="background: var(--card-bg); padding: 15px; border-radius: 12px; margin-bottom: 20px; box-shadow: var(--shadow-md); display: flex; justify-content: space-between; align-items: center;">
            <div style="color: var(--text-primary);">
                <?php if ($perPage === PHP_INT_MAX): ?>
                    Showing <strong>all <?php echo $totalRecords; ?></strong> transactions
                <?php else: ?>
                    Showing <strong><?php echo $offset + 1; ?></strong> to 
                    <strong><?php echo min($offset + $perPage, $totalRecords); ?></strong> of 
                    <strong><?php echo $totalRecords; ?></strong> transactions
                <?php endif; ?>
            </div>
            <?php if ($totalPages > 1): ?>
            <div style="display: flex; gap: 5px;">
                <?php if ($currentPage > 1): ?>
                <a href="?page=<?php echo $currentPage - 1; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>" class="btn btn-sm" style="background: var(--bg-secondary);">
                    <i class="fas fa-chevron-left"></i> Previous
                </a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>" 
                   class="btn btn-sm <?php echo $i == $currentPage ? 'btn-primary' : ''; ?>" 
                   style="<?php echo $i != $currentPage ? 'background: var(--bg-secondary);' : ''; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
                
                <?php if ($currentPage < $totalPages): ?>
                <a href="?page=<?php echo $currentPage + 1; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>" class="btn btn-sm" style="background: var(--bg-secondary);">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Transactions Table -->
        <div class="table-container">
            <?php if (empty($transactions)): ?>
            <div style="padding: 40px; text-align: center; color: var(--text-secondary);">
                <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px;"></i>
                <p><strong>No transactions found.</strong></p>
                <p style="font-size: 14px; margin-top: 10px;">Try adjusting your filters or search criteria.</p>
                
                <!-- Debug Info (helpful for troubleshooting) -->
                <div style="margin-top: 20px; padding: 15px; background: var(--bg-secondary); border-radius: 8px; text-align: left; font-size: 13px;">
                    <strong>Active Filters:</strong><br>
                    <?php if ($filterYear > 0): ?>
                        <span class="badge" style="margin: 5px;">Year: <?php 
                            $selectedYear = array_filter($years, fn($y) => $y['YearID'] == $filterYear);
                            echo $selectedYear ? reset($selectedYear)['YearName'] : $filterYear;
                        ?></span>
                    <?php endif; ?>
                    <?php if ($filterMember > 0): ?>
                        <span class="badge" style="margin: 5px;">Member: <?php 
                            $selectedMember = array_filter($membersForFilter, fn($m) => $m['MemberID'] == $filterMember);
                            echo $selectedMember ? reset($selectedMember)['MemberName'] : $filterMember;
                        ?></span>
                    <?php endif; ?>
                    <?php if (!empty($searchQuery)): ?>
                        <span class="badge" style="margin: 5px;">Search: "<?php echo htmlspecialchars($searchQuery); ?>"</span>
                    <?php endif; ?>
                    <?php if (!empty($filterDateFrom)): ?>
                        <span class="badge" style="margin: 5px;">From: <?php echo $filterDateFrom; ?></span>
                    <?php endif; ?>
                    <?php if (!empty($filterDateTo)): ?>
                        <span class="badge" style="margin: 5px;">To: <?php echo $filterDateTo; ?></span>
                    <?php endif; ?>
                    <?php if (!empty($filterMode)): ?>
                        <span class="badge" style="margin: 5px;">Mode: <?php echo $filterMode; ?></span>
                    <?php endif; ?>
                    <?php if (!empty($filterVerified)): ?>
                        <span class="badge" style="margin: 5px;">Status: <?php echo ucfirst($filterVerified); ?></span>
                    <?php endif; ?>
                    <?php if (empty($filterYear) && empty($filterMember) && empty($searchQuery) && empty($filterDateFrom) && empty($filterDateTo) && empty($filterMode) && empty($filterVerified)): ?>
                        <span style="color: var(--text-secondary);">No filters applied - showing all transactions</span>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 20px;">
                    <a href="manage-transactions.php" class="btn btn-primary">
                        <i class="fas fa-redo"></i> Clear All Filters
                    </a>
                </div>
            </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="bulk-checkbox-col" style="display: none; width: 40px;">
                            <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll()" style="cursor: pointer;">
                        </th>
                        <th>ID</th>
                        <th>Date</th>
                        <th><?php echo ($filterTransactionType === 'expense') ? 'Category' : 'Member'; ?></th>
                        <th>Month</th>
                        <th>Type</th>
                        <th class="collection-color"><?php echo ($filterTransactionType === 'expense') ? 'Description' : 'Collection'; ?></th>
                        <th class="distribution-color"><?php echo ($filterTransactionType === 'expense') ? 'Amount' : 'Distribution'; ?></th>
                        <th class="interest-color"><?php echo ($filterTransactionType === 'expense') ? 'Vendor' : 'Interest'; ?></th>
                        <th><?php echo ($filterTransactionType === 'expense') ? 'Year' : 'Net Balance'; ?></th>
                        <th><?php echo ($filterTransactionType === 'expense') ? 'Status' : 'Next Due'; ?></th>
                        <th>Mode</th>
                        <th><?php echo ($filterTransactionType === 'expense') ? '' : 'Status'; ?></th>
                        <th>Proofs</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $trans): ?>
                    <tr data-transaction-id="<?php echo $trans['TransactionID']; ?>" data-member-id="<?php echo $trans['MemberID']; ?>" data-member-name="<?php echo htmlspecialchars($trans['MemberName'] ?? 'Unknown'); ?>">
                        <td class="bulk-checkbox-col" style="display: none;">
                            <input type="checkbox" class="transaction-checkbox" value="<?php echo $trans['TransactionID']; ?>" onchange="updateSelectedCount()" style="cursor: pointer;">
                        </td>
                        <td><strong>#<?php echo $trans['TransactionID']; ?></strong></td>
                        <td><?php 
                        if (!empty($trans['TransactionDate']) && $trans['TransactionDate'] != '0000-00-00') {
                            echo date('d M Y', strtotime($trans['TransactionDate']));
                        } else {
                            echo date('d M Y', strtotime($trans['CreatedAt']));
                        }
                        ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($trans['MemberName'] ?? 'Unknown'); ?></strong>
                            <?php if (!empty($trans['MemberPhone'])): ?>
                            <br><small style="color: var(--text-secondary);"><?php echo htmlspecialchars($trans['MemberPhone']); ?></small>
                            <?php endif; ?>
                        </td>
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
                        <td class="collection-amount-cell">
                            <?php if ($isExpenseFilter): ?>
                                <span><?php echo htmlspecialchars($trans['ExpenseDescription'] ?? $trans['Remarks'] ?? '-'); ?></span>
                            <?php else: ?>
                                <span class="collection-amount"><?php echo ($trans['CollectionAmount'] > 0) ? '₹' . number_format($trans['CollectionAmount'], 2) : '-'; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="distribution-amount-cell">
                            <?php if ($isExpenseFilter): ?>
                                <span class="distribution-amount"><?php echo ($trans['DistributedAmount'] > 0) ? '₹' . number_format($trans['DistributedAmount'], 2) : '-'; ?></span>
                            <?php else: ?>
                                <span class="distribution-amount"><?php echo ($trans['DistributedAmount'] > 0) ? '₹' . number_format($trans['DistributedAmount'], 2) : '-'; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="interest-amount-cell">
                            <?php if ($isExpenseFilter): ?>
                                <span><?php echo htmlspecialchars($trans['ExpenseVendor'] ?? '-'); ?></span>
                            <?php else: ?>
                                <span class="interest-amount"><?php echo ($trans['InterestAmount'] > 0) ? '₹' . number_format($trans['InterestAmount'], 2) : '-'; ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isExpenseFilter): ?>
                                <span style="font-weight: 600;"><?php echo htmlspecialchars($trans['YearName'] ?? '-'); ?></span>
                            <?php else: ?>
                                <?php 
                                $netBalance = floatval($trans['MemberNetBalance'] ?? 0);
                                $status = $netBalance != 0 ? 'DUE' : 'NEUTRAL';
                                $absBalance = abs($netBalance);
                                $isDue = $netBalance != 0;
                                ?>
                                <span style="color: <?php echo $isDue ? '#ef4444' : '#10b981'; ?>; font-weight: 600;">
                                    ₹<?php echo number_format($absBalance, 2); ?>
                                    <br><small style="font-size: 11px; opacity: 0.8;">(<?php echo $status; ?>)</small>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isExpenseFilter): ?>
                                <?php 
                                $expStatus = $trans['ExpenseStatus'] ?? 'Pending';
                                $statusColors = ['Approved' => '#10b981', 'Pending' => '#f59e0b', 'Rejected' => '#ef4444', 'Cancelled' => '#6b7280'];
                                $statusColor = $statusColors[$expStatus] ?? '#6b7280';
                                ?>
                                <span class="badge" style="background: <?php echo $statusColor; ?>; color: white;">
                                    <?php echo htmlspecialchars($expStatus); ?>
                                </span>
                            <?php else: ?>
                                <?php echo !empty($trans['NextMonthDue']) ? date('d M Y', strtotime($trans['NextMonthDue'])) : '-'; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php $payMode = $trans['PaymentMode'] ?? 'Cash'; ?>
                            <span class="badge">
                                <?php echo htmlspecialchars($payMode); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($isExpenseFilter): ?>
                                <!-- Empty for expenses (header is empty) -->
                            <?php elseif (isset($trans['Verified']) && $trans['Verified'] == 1): ?>
                                <span class="badge badge-verified" title="Verified Transaction">
                                    <i class="fas fa-check-circle"></i> Verified
                                </span>
                            <?php else: ?>
                                <span class="badge badge-unverified" title="Unverified Transaction">
                                    <i class="fas fa-clock"></i> Pending
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            $proofCount = intval($trans['ProofCount'] ?? 0);
                            if ($proofCount > 0): ?>
                                <span onclick="viewProofs(<?php echo $trans['TransactionID']; ?>, '<?php echo $isExpenseFilter ? 'expense' : 'transaction'; ?>')" style="cursor:pointer; background: rgba(139,92,246,0.15); color: #8b5cf6; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                    <i class="fas fa-paperclip"></i> <?php echo $proofCount; ?>
                                </span>
                            <?php else: ?>
                                <span style="color: var(--text-secondary); font-size: 12px;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button 
                                    class="btn btn-sm" 
                                    style="background: <?php echo (isset($trans['Verified']) && $trans['Verified'] == 1) ? '#ffc107' : '#28a745'; ?>; color: white;"
                                    onclick="toggleVerification(<?php echo $trans['TransactionID']; ?>)"
                                    title="<?php echo (isset($trans['Verified']) && $trans['Verified'] == 1) ? 'Mark as Unverified' : 'Mark as Verified'; ?>">
                                    <i class="fas <?php echo (isset($trans['Verified']) && $trans['Verified'] == 1) ? 'fa-times-circle' : 'fa-check-circle'; ?>"></i>
                                </button>
                                <button 
                                    class="btn btn-warning btn-sm" 
                                    onclick="openEditModal(<?php echo htmlspecialchars(json_encode($trans)); ?>)"
                                    title="Edit Transaction">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button 
                                    class="btn btn-danger btn-sm" 
                                    onclick="confirmDelete(<?php echo $trans['TransactionID']; ?>)"
                                    title="Delete Transaction">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            
            <?php if (!empty($transactions) && $totalPages > 1): ?>
            <!-- Pagination Controls -->
            <div style="padding: 20px; text-align: center; background: var(--card-bg); border-top: 1px solid var(--border-color); border-radius: 0 0 12px 12px;">
                <div style="margin-bottom: 15px;">
                    <span style="color: var(--text-secondary); font-size: 14px;">
                        Showing <strong><?php echo (($currentPage - 1) * $perPage) + 1; ?></strong> to 
                        <strong><?php echo min($currentPage * $perPage, $totalRecords); ?></strong> of 
                        <strong><?php echo $totalRecords; ?></strong> transactions
                    </span>
                </div>
                
                <div style="display: flex; justify-content: center; gap: 5px; flex-wrap: wrap;">
                    <?php 
                    // Create base URL with all current filters
                    $filters = array_filter($_GET, function($k) {
                        return $k !== 'page';
                    }, ARRAY_FILTER_USE_KEY);
                    $baseUrl = '?' . http_build_query($filters) . (count($filters) > 0 ? '&' : '');
                    ?>
                    
                    <?php if ($currentPage > 1): ?>
                        <a href="<?php echo $baseUrl; ?>page=1" class="btn" style="padding: 8px 12px; font-size: 12px;">
                            <i class="fas fa-step-backward"></i> First
                        </a>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $currentPage - 1; ?>" class="btn" style="padding: 8px 12px; font-size: 12px;">
                            <i class="fas fa-chevron-left"></i> Prev
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $currentPage + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                        $isActive = ($i == $currentPage);
                    ?>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>" 
                           class="btn" style="padding: 8px 12px; font-size: 12px; <?php echo $isActive ? 'background: #667eea; color: white;' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $currentPage + 1; ?>" class="btn" style="padding: 8px 12px; font-size: 12px;">
                            <i class="fas fa-chevron-right"></i> Next
                        </a>
                        <a href="<?php echo $baseUrl; ?>page=<?php echo $totalPages; ?>" class="btn" style="padding: 8px 12px; font-size: 12px;">
                            <i class="fas fa-step-forward"></i> Last
                        </a>
                    <?php endif; ?>
                </div>
                
                <div style="margin-top: 15px; color: var(--text-secondary); font-size: 13px;">
                    Page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong>
                </div>
            </div>
            <?php endif; ?>
        </div>

    <!-- Add Transaction Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-plus-circle"></i> Add New Transaction</h2>
                <button class="close-modal" onclick="closeAddModal()">&times;</button>
            </div>
            <form id="addForm" method="POST" action="">
                <input type="hidden" name="action" value="add_new">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label>Academic Year <span style="color: red;">*</span></label>
                        <select name="year_id" class="form-control" required onchange="updateFixedAmount()">
                            <?php foreach ($years as $year): ?>
                            <option value="<?php echo $year['YearID']; ?>" <?php echo ($year['Status'] === 'Active') ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['YearName']); ?> (<?php echo $year['Status']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Member <span style="color: red;">*</span></label>
                        <select name="member_id" class="form-control" required>
                            <option value="">-- Select Member --</option>
                            <?php foreach ($membersForAddTransaction as $member): ?>
                            <option value="<?php echo $member['MemberID']; ?>" 
                                    data-net-balance="<?php echo $member['NetBalance']; ?>">
                                <?php echo htmlspecialchars($member['MemberName']); ?> 
                                (Net Balance: ₹<?php echo number_format($member['NetBalance'], 2); ?>)
                                [ID: <?php echo $member['MemberID']; ?>]
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Month <span style="color: red;">*</span></label>
                        <select name="month_name" class="form-control" required>
                            <?php 
                            $months = ['January', 'February', 'March', 'April', 'May', 'June', 
                                      'July', 'August', 'September', 'October', 'November', 'December'];
                            foreach ($months as $month): 
                            ?>
                            <option value="<?php echo $month; ?>" <?php echo (date('F') === $month) ? 'selected' : ''; ?>>
                                <?php echo $month; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="transcation-grid-row-4">
                        <div class="form-group">
                            <label>Transaction Date <span style="color: red;">*</span></label>
                            <input type="date" name="transaction_date" id="add_transaction_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Collection Amount (₹)</label>
                            <input type="number" name="collection_amount" id="add_collection_amount" class="form-control" step="0.01" min="0" value="0" oninput="calculateInterest()">
                            <small style="color: #999;">Amount received from member</small>
                        </div>
                    </div>

                    <!-- <div class="form-group section-separator">
                        <label>Distribution Amount (₹)</label>
                        <input type="number" name="distributed_amount" id="add_distributed_amount" class="form-control" step="0.01" min="0" value="0" oninput="calculateInterest()" >
                        <small style="color: #999;">Amount given to member</small>
                    </div> -->
                    <div class="transcation-grid-row-5">
                        <div class="form-group">
                            <label>Distribution Amount (₹)</label>
                            <input type="number" name="distributed_amount" id="add_distributed_amount" class="form-control" step="0.01" min="0" value="0" oninput="calculateInterest()" >
                            <small style="color: #999;">Amount given to member</small>
                        </div>
                        <div class="form-group">
                            <label>Interest Amount (₹) <span style="font-size: 11px; color: #10b981;">(Auto-calculated @ 2%)</span></label>
                            <input type="number" name="interest_amount" id="add_interest_amount" class="form-control" step="0.01" min="0" value="0">
                            <small style="color: #999;">Auto-calculated, but you can change it manually</small>
                        </div>
                        <!-- <div class="form-group">
                            <label>Fixed Amount (₹) <span style="font-size: 11px; color: #667eea;">(From Academic Year)</span></label>
                            <input type="number" name="fixed_amount" id="add_fixed_amount" class="form-control" step="0.01" min="0" value="0" readonly>
                            <small style="color: #999;">Auto-populated from selected academic year</small>
                        </div> -->
                    </div>
                    <!-- <div class="form-group">
                        <label>Fixed Amount (₹) <span style="font-size: 11px; color: #667eea;">(From Academic Year)</span></label>
                        <input type="number" name="fixed_amount" id="add_fixed_amount" class="form-control" step="0.01" min="0" value="0" readonly>
                        <small style="color: #999;">Auto-populated from selected academic year</small>
                    </div> -->
                    <div class="transcation-grid-row-6">
                        <div class="form-group">
                            <label>Due Date</label>
                            <input type="date" name="next_month_due" class="form-control" value="">
                            <small style="color: #999;">Next payment due date</small>
                        </div>
                        <div class="form-group">
                            <label>Payment Mode</label>
                            <select name="payment_mode" class="form-control">
                                <option value="Cash">Cash</option>
                                <option value="Online">Online</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group section-separator">
                        <label>Remarks</label>
                        <textarea name="remarks" class="form-control" placeholder="Enter any notes or remarks..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeAddModal()">Cancel</button>
                    <button type="button" class="btn btn-secondary" id="autoCalcBtn" onclick="autoCalcAddForm()" title="Fill the fields using the active rules from Transaction Auto-Calc">
                        <i class="fas fa-calculator"></i> Auto-calculate
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Add Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit Transaction</h2>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form id="editForm" method="POST" action="">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="transaction_id" id="edit_transaction_id">
                
                <div class="modal-body">
                    <!-- Row 1: Academic Year -->
                    <div class="form-group">
                        <label>Academic Year <span style="color: red;">*</span></label>
                        <select name="year_id" id="edit_year_id" class="form-control" required>
                            <option value="">Select Year</option>
                            <?php foreach ($years as $year): ?>
                            <option value="<?php echo $year['YearID']; ?>">
                                <?php echo htmlspecialchars($year['YearName']); ?> (<?php echo $year['Status']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Row 2: Member -->
                    <div class="form-group">
                        <label>Member <span style="color: red;">*</span></label>
                        <select name="member_id" id="edit_member_id" class="form-control" required>
                            <option value="">-- Select Member --</option>
                            <?php foreach ($membersForAddTransaction as $member): ?>
                            <option value="<?php echo $member['MemberID']; ?>">
                                <?php echo htmlspecialchars($member['MemberName']); ?> 
                                (Net Balance: ₹<?php echo number_format($member['NetBalance'], 2); ?>)
                                [ID: <?php echo $member['MemberID']; ?>]
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Row 3: Month -->
                    <div class="form-group">
                        <label>Month <span style="color: red;">*</span></label>
                        <select name="month_name" id="edit_month_name" class="form-control" required>
                            <option value="">Select Month</option>
                            <?php 
                            $months_list = ['January', 'February', 'March', 'April', 'May', 'June', 
                                           'July', 'August', 'September', 'October', 'November', 'December'];
                            foreach ($months_list as $month): 
                            ?>
                            <option value="<?php echo $month; ?>">
                                <?php echo $month; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Row 4: Transaction Date and Collection Amount -->
                    <div class="transcation-grid-row-4">
                        <div class="form-group">
                            <label>Transaction Date <span style="color: red;">*</span></label>
                            <input type="date" name="transaction_date" id="edit_transaction_date" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Collection Amount (₹)</label>
                            <input type="number" name="collection_amount" id="edit_collection_amount" class="form-control" step="0.01" min="0">
                            <small style="color: #999;">Amount received from member</small>
                        </div>
                    </div>

                    <!-- Row 5: Distribution Amount -->
                    <div class="form-group section-separator">
                        <label>Distribution Amount (₹)</label>
                        <input type="number" name="distributed_amount" id="edit_distributed_amount" class="form-control" step="0.01" min="0">
                        <small style="color: #999;">Amount given to member</small>
                    </div>

                    <!-- Row 6: Interest Amount and Fixed Amount -->
                    <div class="transcation-grid-row-5">
                        <div class="form-group">
                            <label>Interest Amount (₹) <span style="font-size: 11px; color: #10b981;">(Auto-calculated @ 2%)</span></label>
                            <input type="number" name="interest_amount" id="edit_interest_amount" class="form-control" step="0.01" min="0">
                            <small style="color: #999;">Auto-calculated, but you can change it manually</small>
                        </div>
                        <div class="form-group">
                            <label>Fixed Amount (₹) <span style="font-size: 11px; color: #667eea;">(From Academic Year)</span></label>
                            <input type="number" name="fixed_amount" id="edit_fixed_amount" class="form-control" step="0.01" min="0" readonly>
                            <small style="color: #999;">Auto-populated from selected academic year</small>
                        </div>
                    </div>

                    <!-- Row 7: Due Date and Payment Mode -->
                    <div class="transcation-grid-row-6">
                        <div class="form-group">
                            <label>Due Date</label>
                            <input type="date" name="next_month_due" id="edit_next_month_due" class="form-control">
                            <small style="color: #999;">Next payment due date</small>
                        </div>
                        <div class="form-group">
                            <label>Payment Mode</label>
                            <select name="payment_mode" id="edit_payment_mode" class="form-control">
                                <option value="Cash">Cash</option>
                                <option value="Online">Online</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                    </div>

                    <!-- Row 8: Remarks -->
                    <div class="form-group section-separator">
                        <label>Remarks</label>
                        <textarea name="remarks" id="edit_remarks" class="form-control" placeholder="Enter any notes or remarks..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Form -->
    <form id="deleteForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="transaction_id" id="delete_transaction_id">
    </form>
    
    <!-- Bulk Delete Form -->
    <form id="bulkDeleteForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="bulk_delete">
        <div id="bulkDeleteInputs"></div>
    </form>
    
    <!-- Toggle Verification Form -->
    <form id="verifyForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="toggle_verify">
        <input type="hidden" name="transaction_id" id="verify_transaction_id">
    </form>

    <script src="theme-manager.js"></script>
    <script>
        const PROOF_API_URL = 'api/proof-upload.php';
        
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
                            const thumbUrl = att.thumbnailUrl || att.viewUrl;
                            const gdriveId = att.GoogleDriveFileID || '';
                            const mimeType = att.MimeType || '';
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
            
            // Build preview URL for Google Drive files
            let previewUrl = url;
            if (gdriveId) {
                previewUrl = 'https://drive.google.com/file/d/' + gdriveId + '/preview';
            }
            
            if (isImage) {
                contentArea.innerHTML = `<div style="text-align:center;padding:10px;max-height:calc(80vh - 140px);overflow:auto;">
                    <img src="${url}" style="max-width:100%;max-height:70vh;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.2);" alt="${escapeHtml(fileName)}" onerror="this.parentElement.innerHTML='<div style=\\'padding:40px;color:var(--text-secondary);\\'>Unable to load image. <a href=\\'${url}\\' target=\\'_blank\\' style=\\'color:#3b82f6;\\'>Open in new tab</a></div>'">
                </div>`;
            } else if (isPdf || gdriveId) {
                contentArea.innerHTML = `<iframe src="${previewUrl}" style="width:100%;height:calc(80vh - 140px);border:none;border-radius:0;" allowfullscreen></iframe>`;
            } else {
                // Non-previewable file types
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
            const modal = document.getElementById('documentViewerModal');
            const contentArea = document.getElementById('docViewerContent');
            modal.style.display = 'none';
            contentArea.innerHTML = '';
        }
        
        function closeProofViewer() {
            document.getElementById('proofViewerModal').style.display = 'none';
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Toggle category filter visibility based on expense type selection
        function toggleCategoryFilter() {
            const typeSelect = document.getElementById('transactionTypeFilter');
            const categoryGroup = document.getElementById('categoryFilterGroup');
            if (typeSelect && categoryGroup) {
                if (typeSelect.value === 'expense') {
                    categoryGroup.style.display = '';
                } else {
                    categoryGroup.style.display = 'none';
                }
            }
        }
        
        let bulkModeActive = false;
        
        // Academic years data from PHP
        const academicYears = <?php echo json_encode($years); ?>;
        
        // Auto-populate FixedAmount when year is selected
        function updateFixedAmount() {
            const yearSelect = document.querySelector('select[name="year_id"]');
            const fixedAmountInput = document.getElementById('add_fixed_amount');
            
            if (yearSelect && fixedAmountInput) {
                const selectedYearId = parseInt(yearSelect.value);
                const selectedYear = academicYears.find(year => year.YearID == selectedYearId);
                
                if (selectedYear && selectedYear.FixedAmount) {
                    fixedAmountInput.value = parseFloat(selectedYear.FixedAmount).toFixed(2);
                } else {
                    fixedAmountInput.value = '0.00';
                }
            }
        }
        
        // Auto-calculate interest based on selected year's interest rate and collection model
        function calculateInterest() {
            const collectionAmount = parseFloat(document.getElementById('add_collection_amount').value) || 0;
            const distributedAmount = parseFloat(document.getElementById('add_distributed_amount').value) || 0;
            
            // Get selected year data
            const yearSelect = document.querySelector('select[name="year_id"]');
            const selectedYearId = yearSelect ? parseInt(yearSelect.value) : 0;
            const selectedYear = academicYears.find(year => year.YearID == selectedYearId);
            
            if (!selectedYear) {
                document.getElementById('add_interest_amount').value = '0.00';
                return;
            }
            
            // Get interest rate (convert from percentage if needed)
            let interestRate = parseFloat(selectedYear.InterestRate) || 0;
            if (interestRate > 1) {
                interestRate = interestRate / 100; // Convert percentage to decimal
            }
            
            // Get collection model to determine what to apply interest on
            const collectionModel = selectedYear.CollectionModel || 'FullAmount';
            let baseAmount = 0;
            
            // Determine base amount based on collection model
            if (collectionModel === 'DistributionOnly' || collectionModel === 'DistributionBased') {
                // Interest on distribution amount
                baseAmount = distributedAmount;
            } else if (collectionModel === 'CollectionOnly' || collectionModel === 'CollectionBased') {
                // Interest on collection amount
                baseAmount = collectionAmount;
            } else {
                // FullAmount or default: interest on distribution
                baseAmount = distributedAmount;
            }
            
            const calculatedInterest = baseAmount * interestRate;
            
            // Set the calculated interest (user can still change it manually)
            document.getElementById('add_interest_amount').value = calculatedInterest.toFixed(2);
        }

        // Fill the Add Transaction form from the active "Transaction Auto-Calc" rule-set.
        // Additive helper: it only sets field values, nothing about saving changes.
        // onlyAuto=true (live, on input change) fills just the auto-flagged fields so typed values are kept;
        // onlyAuto=false (the button) fills every enabled field and shows feedback.
        function autoCalcAddForm(onlyAuto) {
            const form = document.getElementById('addForm');
            if (!form) { return; }
            const val = function (name) { const el = form.querySelector('[name="' + name + '"]'); return el ? el.value : ''; };
            const inputs = {
                member_id: val('member_id'), year_id: val('year_id'), month: val('month_name'),
                transaction_date: val('transaction_date'),
                collection_amount: val('collection_amount') || 0, distributed_amount: val('distributed_amount') || 0
            };
            if (!inputs.member_id) { if (!onlyAuto) { alert('Choose a member first.'); } return; }
            const btn = document.getElementById('autoCalcBtn');
            const original = btn ? btn.innerHTML : '';
            if (btn && !onlyAuto) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Calculating…'; }
            fetch('api/auto-calc.php?action=compute', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ inputs: inputs })
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j.error) { if (!onlyAuto) { alert(j.error); } return; }
                const fields = j.fields || {};
                let filled = 0;
                Object.keys(fields).forEach(function (key) {
                    const r = fields[key];
                    if (r.error || r.value === null || r.value === '') { return; }
                    if (onlyAuto && !r.auto) { return; }
                    const el = form.querySelector('[name="' + key + '"]');
                    if (el) { el.value = r.input; filled++; }
                });
                if (btn && !onlyAuto) {
                    btn.innerHTML = '<i class="fas fa-check"></i> Filled ' + filled + ' field(s)';
                    setTimeout(function () { btn.innerHTML = original; btn.disabled = false; }, 1600);
                }
            }).catch(function (e) {
                if (!onlyAuto) { alert('Auto-calculate failed: ' + e.message); if (btn) { btn.innerHTML = original; btn.disabled = false; } }
            });
        }

        // Recompute the auto-fill fields live as the inputs change (debounced).
        let autoCalcTimer = null;
        function liveAutoCalc() {
            if (autoCalcTimer) { clearTimeout(autoCalcTimer); }
            autoCalcTimer = setTimeout(function () { autoCalcAddForm(true); }, 500);
        }
        (function wireLiveAutoCalc() {
            const form = document.getElementById('addForm');
            if (!form) { return; }
            ['member_id', 'year_id', 'month_name', 'transaction_date', 'collection_amount', 'distributed_amount'].forEach(function (name) {
                const el = form.querySelector('[name="' + name + '"]');
                if (!el) { return; }
                const ev = (el.tagName === 'SELECT' || el.type === 'date') ? 'change' : 'input';
                el.addEventListener(ev, liveAutoCalc);
            });
        })();

        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
            // Set today's date
            document.getElementById('add_transaction_date').value = new Date().toISOString().split('T')[0];
            // Reset form and update FixedAmount
            calculateInterest();
            updateFixedAmount();
        }
        
        function closeAddModal() {
            document.getElementById('addModal').classList.remove('active');
        }
        
        function openEditModal(transaction) {
            document.getElementById('edit_transaction_id').value = transaction.TransactionID;
            document.getElementById('edit_year_id').value = transaction.YearID || '';
            document.getElementById('edit_member_id').value = transaction.MemberID || '';
            document.getElementById('edit_month_name').value = transaction.MonthName || '';
            document.getElementById('edit_transaction_date').value = transaction.TransactionDate || new Date().toISOString().split('T')[0];
            document.getElementById('edit_collection_amount').value = transaction.CollectionAmount || 0;
            document.getElementById('edit_distributed_amount').value = transaction.DistributedAmount || 0;
            document.getElementById('edit_interest_amount').value = transaction.InterestAmount || 0;
            document.getElementById('edit_fixed_amount').value = transaction.FixedAmount || 0;
            document.getElementById('edit_next_month_due').value = transaction.NextMonthDue || '';
            document.getElementById('edit_payment_mode').value = transaction.PaymentMode || 'Cash';
            document.getElementById('edit_remarks').value = transaction.Remarks || '';
            
            document.getElementById('editModal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        function confirmDelete(transactionId) {
            // Get transaction data from the row
            const row = document.querySelector(`tr[data-transaction-id="${transactionId}"]`);
            if (!row) return;
            
            // Extract transaction details
            const cells = row.querySelectorAll('td');
            const transId = cells[1].textContent.trim();
            const date = cells[2].textContent.trim();
            
            // Get member info from data attributes
            const memberId = row.getAttribute('data-member-id');
            const memberName = row.getAttribute('data-member-name');
            const member = memberName ? `${memberName} (ID: #${memberId})` : 'Unknown';
            
            const monthName = cells[4].textContent.trim();
            const type = cells[5].textContent.trim();
            const collection = cells[6].textContent.trim();
            const distribution = cells[7].textContent.trim();
            const interest = cells[8].textContent.trim();
            
            // Show delete information dialog first
            showDeleteInfoDialog(
                'Delete Transaction',
                {
                    'Transaction ID': transId,
                    'Date': date,
                    'Member': member,
                    'Month': monthName,
                    'Type': type,
                    'Collection': collection,
                    'Distribution': distribution,
                    'Interest': interest
                },
                function() {
                    closeDeleteInfoDialog();
                    // Show delete confirmation dialog
                    showDeleteConfirmDialog(
                        'Confirm Deletion',
                        'Are you sure you want to delete this transaction?\n\nThis action cannot be undone!',
                        'Delete',
                        'Cancel',
                        function(confirmed) {
                            if (confirmed) {
                                document.getElementById('delete_transaction_id').value = transactionId;
                                document.getElementById('deleteForm').submit();
                            }
                        }
                    );
                }
            );
        }
        
        function showDeleteInfoDialog(title, details, callback) {
            const overlay = document.getElementById('deleteInfoDialogOverlay');
            const existingModal = overlay.querySelector('.delete-dialog-modal');
            if (existingModal) existingModal.remove();
            
            let detailsHtml = '';
            for (const [label, value] of Object.entries(details)) {
                const isAmount = label.toLowerCase().includes('collection') || 
                               label.toLowerCase().includes('distribution') || 
                               label.toLowerCase().includes('interest');
                const valueClass = isAmount ? 'amount' : '';
                detailsHtml += `
                    <div class="delete-detail">
                        <div class="delete-label">${label}</div>
                        <div class="delete-value ${valueClass}">${value}</div>
                    </div>
                `;
            }
            
            const modal = document.createElement('div');
            modal.className = 'delete-dialog-modal';
            modal.innerHTML = `
                <div class="delete-dialog-header">
                    <i class="fas fa-trash-alt"></i>
                    <div class="delete-dialog-header-content">
                        <h2>${title}</h2>
                    </div>
                </div>
                <div class="delete-dialog-body">
                    ${detailsHtml}
                    <div class="delete-warning">
                        <i class="fas fa-exclamation-triangle"></i> This action cannot be undone!
                    </div>
                </div>
                <div class="delete-dialog-footer">
                    <button class="btn-delete-cancel" onclick="closeDeleteInfoDialog()">Cancel</button>
                    <button class="btn-delete-confirm" onclick="proceedToDeleteConfirm()">Delete</button>
                </div>
            `;
            
            overlay.appendChild(modal);
            overlay.classList.add('active');
            
            window.deleteInfoDialogCallback = callback;
            
            const deleteBtn = modal.querySelector('.btn-delete-confirm');
            if (deleteBtn) deleteBtn.focus();
            
            const handleKeydown = (e) => {
                if (e.key === 'Escape') {
                    closeDeleteInfoDialog();
                    document.removeEventListener('keydown', handleKeydown);
                }
            };
            document.addEventListener('keydown', handleKeydown);
        }
        
        function closeDeleteInfoDialog() {
            const overlay = document.getElementById('deleteInfoDialogOverlay');
            overlay.classList.remove('active');
            setTimeout(() => {
                const modal = overlay.querySelector('.delete-dialog-modal');
                if (modal) modal.remove();
            }, 300);
        }
        
        function proceedToDeleteConfirm() {
            if (typeof window.deleteInfoDialogCallback === 'function') {
                window.deleteInfoDialogCallback();
            }
        }
        
        function showDeleteConfirmDialog(title, message, confirmBtnText, cancelBtnText, callback) {
            const overlay = document.getElementById('deleteConfirmDialogOverlay');
            const existingModal = overlay.querySelector('.delete-dialog-modal');
            if (existingModal) existingModal.remove();
            
            const modal = document.createElement('div');
            modal.className = 'delete-dialog-modal';
            modal.innerHTML = `
                <div class="delete-dialog-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div class="delete-dialog-header-content">
                        <h2>${title}</h2>
                    </div>
                </div>
                <div class="delete-dialog-body">
                    <div style="color: #1f2937; line-height: 1.6; white-space: pre-wrap; word-wrap: break-word;">${message}</div>
                    <div class="delete-warning">
                        <i class="fas fa-exclamation-circle"></i> This action cannot be undone!
                    </div>
                </div>
                <div class="delete-dialog-footer">
                    <button class="btn-delete-cancel" onclick="closeDeleteConfirmDialog()">${cancelBtnText}</button>
                    <button class="btn-delete-confirm" onclick="confirmDeleteAction()">${confirmBtnText}</button>
                </div>
            `;
            
            overlay.appendChild(modal);
            overlay.classList.add('active');
            
            window.deleteConfirmDialogCallback = callback;
            
            const confirmBtn = modal.querySelector('.btn-delete-confirm');
            if (confirmBtn) confirmBtn.focus();
            
            const handleKeydown = (e) => {
                if (e.key === 'Escape') {
                    closeDeleteConfirmDialog();
                    document.removeEventListener('keydown', handleKeydown);
                }
            };
            document.addEventListener('keydown', handleKeydown);
        }
        
        function confirmDeleteAction() {
            const overlay = document.getElementById('deleteConfirmDialogOverlay');
            overlay.classList.remove('active');
            setTimeout(() => {
                const modal = overlay.querySelector('.delete-dialog-modal');
                if (modal) modal.remove();
            }, 300);
            if (typeof window.deleteConfirmDialogCallback === 'function') {
                window.deleteConfirmDialogCallback(true);
            }
        }
        
        function closeDeleteConfirmDialog() {
            const overlay = document.getElementById('deleteConfirmDialogOverlay');
            overlay.classList.remove('active');
            setTimeout(() => {
                const modal = overlay.querySelector('.delete-dialog-modal');
                if (modal) modal.remove();
            }, 300);
            if (typeof window.deleteConfirmDialogCallback === 'function') {
                window.deleteConfirmDialogCallback(false);
            }
        }
        
        function toggleVerification(transactionId) {
            // Get transaction data from the row
            const row = document.querySelector(`tr[data-transaction-id="${transactionId}"]`);
            if (!row) return;
            
            // Extract transaction details
            const cells = row.querySelectorAll('td');
            const transId = cells[1].textContent.trim();
            const date = cells[2].textContent.trim();
            
            // Get member info from data attributes
            const memberId = row.getAttribute('data-member-id');
            const memberName = row.getAttribute('data-member-name');
            const member = memberName ? `${memberName} (ID: #${memberId})` : 'Unknown';
            
            const monthName = cells[4].textContent.trim();
            const type = cells[5].textContent.trim();
            const collection = cells[6].textContent.trim();
            const distribution = cells[7].textContent.trim();
            const interest = cells[8].textContent.trim();
            const status = cells[11].textContent.trim();
            
            // Show information dialog first
            showInfoDialog(
                'Transaction Details',
                {
                    'Transaction ID': transId,
                    'Date': date,
                    'Member': member,
                    'Month': monthName,
                    'Type': type,
                    'Collection': collection,
                    'Distribution': distribution,
                    'Interest': interest,
                    'Current Status': status
                },
                'Proceed to Verify',
                function() {
                    closeInfoDialog();
                    // Show confirmation dialog
                    showConfirmDialog(
                        'Confirm Verification',
                        'Toggle verification status for this transaction?',
                        'Confirm',
                        'Cancel',
                        function(confirmed) {
                            if (confirmed === true) {
                                document.getElementById('verify_transaction_id').value = transactionId;
                                document.getElementById('verifyForm').submit();
                            }
                        }
                    );
                }
            );
        }
        
        function showInfoDialog(title, details, proceedBtnText, callback) {
            const overlay = document.getElementById('infoDialogOverlay');
            const existingModal = overlay.querySelector('.info-dialog-modal');
            if (existingModal) existingModal.remove();
            
            let detailsHtml = '';
            for (const [label, value] of Object.entries(details)) {
                const isAmount = label.toLowerCase().includes('collection') || 
                               label.toLowerCase().includes('distribution') || 
                               label.toLowerCase().includes('interest');
                const valueClass = isAmount ? 'amount' : '';
                detailsHtml += `
                    <div class="info-detail">
                        <div class="info-label">${label}</div>
                        <div class="info-value ${valueClass}">${value}</div>
                    </div>
                `;
            }
            
            const modal = document.createElement('div');
            modal.className = 'info-dialog-modal';
            modal.innerHTML = `
                <div class="info-dialog-header">
                    <i class="fas fa-info-circle"></i>
                    <div class="info-dialog-header-content">
                        <h2>${title}</h2>
                    </div>
                </div>
                <div class="info-dialog-body">
                    ${detailsHtml}
                </div>
                <div class="info-dialog-footer">
                    <button class="btn-info-close" onclick="closeInfoDialog()">Close</button>
                    <button class="btn-info-proceed" onclick="proceedFromInfoDialog()">${proceedBtnText}</button>
                </div>
            `;
            
            overlay.appendChild(modal);
            overlay.classList.add('active');
            
            window.infoDialogCallback = callback;
            
            const proceedBtn = modal.querySelector('.btn-info-proceed');
            if (proceedBtn) proceedBtn.focus();
            
            const handleKeydown = (e) => {
                if (e.key === 'Escape') {
                    closeInfoDialog();
                    document.removeEventListener('keydown', handleKeydown);
                }
            };
            document.addEventListener('keydown', handleKeydown);
        }
        
        function closeInfoDialog() {
            const overlay = document.getElementById('infoDialogOverlay');
            overlay.classList.remove('active');
            setTimeout(() => {
                const modal = overlay.querySelector('.info-dialog-modal');
                if (modal) modal.remove();
            }, 300);
        }
        
        function proceedFromInfoDialog() {
            if (typeof window.infoDialogCallback === 'function') {
                window.infoDialogCallback();
            }
        }
        
        function showConfirmDialog(title, message, confirmBtnText, cancelBtnText, callback) {
            const overlay = document.getElementById('confirmDialogOverlay');
            const existingModal = overlay.querySelector('.confirm-dialog-modal');
            if (existingModal) existingModal.remove();
            
            const modal = document.createElement('div');
            modal.className = 'confirm-dialog-modal';
            modal.innerHTML = `
                <div class="confirm-dialog-header">
                    <i class="fas fa-exclamation-circle"></i>
                    <div class="confirm-dialog-header-content">
                        <h2>${title}</h2>
                    </div>
                </div>
                <div class="confirm-dialog-body">${message}</div>
                <div class="confirm-dialog-footer">
                    <button class="btn-confirm-cancel" onclick="closeConfirmDialog()">${cancelBtnText}</button>
                    <button class="btn-confirm-submit" onclick="confirmAction()">${confirmBtnText}</button>
                </div>
            `;
            
            overlay.appendChild(modal);
            overlay.classList.add('active');
            
            window.confirmDialogCallback = callback;
            
            const confirmBtn = modal.querySelector('.btn-confirm-submit');
            if (confirmBtn) confirmBtn.focus();
            
            const handleKeydown = (e) => {
                if (e.key === 'Escape') {
                    closeConfirmDialog();
                    document.removeEventListener('keydown', handleKeydown);
                }
            };
            document.addEventListener('keydown', handleKeydown);
        }
        
        function confirmAction() {
            const overlay = document.getElementById('confirmDialogOverlay');
            overlay.classList.remove('active');
            setTimeout(() => {
                const modal = overlay.querySelector('.confirm-dialog-modal');
                if (modal) modal.remove();
            }, 300);
            if (typeof window.confirmDialogCallback === 'function') {
                window.confirmDialogCallback(true);
            }
        }
        
        function closeConfirmDialog() {
            const overlay = document.getElementById('confirmDialogOverlay');
            overlay.classList.remove('active');
            setTimeout(() => {
                const modal = overlay.querySelector('.confirm-dialog-modal');
                if (modal) modal.remove();
            }, 300);
            // Clear callback on cancel - do NOT execute it
            window.confirmDialogCallback = null;
        }
        
        function exportPDF() {
            // Get current URL parameters
            const params = new URLSearchParams(window.location.search);
            
            // Build export URL
            let exportUrl = 'export-transactions-pdf.php?';
            for (let [key, value] of params.entries()) {
                if (key !== 'page') { // Exclude pagination
                    exportUrl += key + '=' + encodeURIComponent(value) + '&';
                }
            }
            
            // Open in new window/tab
            window.open(exportUrl, '_blank');
        }
        
        function exportCSV() {
            // Get current URL parameters
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.location.href = '?' + params.toString();
        }
        
        function toggleBulkMode() {
            bulkModeActive = !bulkModeActive;
            
            const checkboxCols = document.querySelectorAll('.bulk-checkbox-col');
            const bulkBar = document.getElementById('bulkActionsBar');
            
            if (bulkModeActive) {
                checkboxCols.forEach(col => col.style.display = 'table-cell');
                bulkBar.style.display = 'block';
            } else {
                checkboxCols.forEach(col => col.style.display = 'none');
                bulkBar.style.display = 'none';
                deselectAllTransactions();
            }
        }
        
        function updateSelectedCount() {
            const checked = document.querySelectorAll('.transaction-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = checked;
        }
        
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAllCheckbox').checked;
            document.querySelectorAll('.transaction-checkbox').forEach(cb => {
                cb.checked = selectAll;
            });
            updateSelectedCount();
        }
        
        function selectAllTransactions() {
            document.querySelectorAll('.transaction-checkbox').forEach(cb => {
                cb.checked = true;
            });
            document.getElementById('selectAllCheckbox').checked = true;
            updateSelectedCount();
        }
        
        function deselectAllTransactions() {
            document.querySelectorAll('.transaction-checkbox').forEach(cb => {
                cb.checked = false;
            });
            document.getElementById('selectAllCheckbox').checked = false;
            updateSelectedCount();
        }
        
        function applyBulkChanges() {
            const checked = document.querySelectorAll('.transaction-checkbox:checked');
            
            if (checked.length === 0) {
                showErrorToast('Please select at least one transaction to update.');
                return;
            }
            
            const nextDue = document.getElementById('bulkNextDue').value;
            const mode = document.getElementById('bulkMode').value;
            const status = document.getElementById('bulkStatus').value;
            
            if (!nextDue && !mode && !status) {
                showErrorToast('Please select at least one field to update (Next Due Date, Payment Mode, or Verification Status).');
                return;
            }
            
            let changes = [];
            if (nextDue) changes.push('Next Due Date');
            if (mode) changes.push('Payment Mode to ' + mode);
            if (status) changes.push('Verification Status to ' + (status == '1' ? 'Verified' : 'Unverified'));
            
            const messageLines = [`Update ${checked.length} transaction(s)?`, '', 'Changes:', ...changes.map(c => '• ' + c)];
            
            showConfirmDialog(
                'Confirm Bulk Update',
                messageLines.join('\n'),
                'Update',
                'Cancel',
                function() {
                    // Create form data
                    const formData = new FormData();
                    formData.append('bulk_update', '1');
                    
                    checked.forEach(checkbox => {
                        formData.append('transaction_ids[]', checkbox.value);
                    });
                    
                    if (nextDue) formData.append('next_due', nextDue);
                    if (mode) formData.append('mode', mode);
                    if (status) formData.append('verified', status);
                    
                    // Submit via fetch
                    fetch('manage-transactions.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Build detailed message
                            let details = [];
                            if (nextDue) details.push('Next Due: ' + nextDue);
                            if (mode) details.push('Payment Mode: ' + mode);
                            if (status) details.push('Verification: ' + (status == '1' ? 'Verified' : 'Pending'));
                            
                            const detailMsg = 'Successfully updated ' + data.updated + ' transaction(s)!|' + details.join('; ');
                            showSuccessToast(detailMsg);
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showErrorToast('Error: ' + (data.message || 'Failed to update transactions'));
                        }
                    })
                    .catch(error => {
                        showErrorToast('Error updating transactions: ' + error.message);
                    });
                }
            );
        }
        
        function bulkDelete() {
            const checked = document.querySelectorAll('.transaction-checkbox:checked');
            
            if (checked.length === 0) {
                showErrorToast('Please select at least one transaction to delete.');
                return;
            }
            
            showConfirmDialog(
                'Confirm Delete',
                `Are you sure you want to delete ${checked.length} transaction(s)?\n\nThis action cannot be undone!`,
                'Delete',
                'Cancel',
                function() {
                    const form = document.getElementById('bulkDeleteForm');
                    const container = document.getElementById('bulkDeleteInputs');
                    container.innerHTML = '';
                    
                    checked.forEach(checkbox => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'transaction_ids[]';
                        input.value = checkbox.value;
                        container.appendChild(input);
                    });
                    
                    form.submit();
                }
            );
        }

        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
                if (bulkModeActive) {
                    toggleBulkMode();
                }
            }
            
            // Ctrl/Cmd + E for Export
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                exportCSV();
            }
            
            // Ctrl/Cmd + B for Bulk Mode
            if ((e.ctrlKey || e.metaKey) && e.key === 'b') {
                e.preventDefault();
                toggleBulkMode();
            }
        });
        
        // Auto-submit filter form when year changes to update member list
        document.addEventListener('DOMContentLoaded', function() {
            // Restore scroll position after page load
            const savedScrollPosition = sessionStorage.getItem('transactionPageScrollPosition');
            if (savedScrollPosition !== null) {
                window.scrollTo(0, parseInt(savedScrollPosition));
                sessionStorage.removeItem('transactionPageScrollPosition');
            }
            
            const yearFilter = document.querySelector('select[name="year"]');
            if (yearFilter) {
                yearFilter.addEventListener('change', function() {
                    // Save scroll position before form submit
                    saveScrollPosition();
                    this.form.submit();
                });
            }
        });

        // Function to save current scroll position
        function saveScrollPosition() {
            sessionStorage.setItem('transactionPageScrollPosition', window.scrollY);
        }

        // Save scroll position before delete/edit form submission
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (form.id === 'deleteForm' || form.id === 'verifyForm' || form.id === 'editForm' || form.id === 'addForm' || form.id === 'bulkDeleteForm') {
                saveScrollPosition();
            }
        });

        // Save scroll position before bulk delete via form submission
        function bulkDelete() {
            const checked = document.querySelectorAll('.transaction-checkbox:checked');
            
            if (checked.length === 0) {
                showErrorToast('Please select at least one transaction to delete.');
                return;
            }
            
            showConfirmDialog(
                'Confirm Delete',
                `Are you sure you want to delete ${checked.length} transaction(s)?\n\nThis action cannot be undone!`,
                'Delete',
                'Cancel',
                function() {
                    saveScrollPosition();
                    const form = document.getElementById('bulkDeleteForm');
                    const container = document.getElementById('bulkDeleteInputs');
                    container.innerHTML = '';
                    
                    checked.forEach(checkbox => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'transaction_ids[]';
                        input.value = checkbox.value;
                        container.appendChild(input);
                    });
                    
                    form.submit();
                }
            );
        }
        function showToast(message, type = 'info', duration = 4000) {
            const container = document.getElementById('toastContainer');
            
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            let icon = 'fas fa-info-circle';
            if (type === 'success') {
                icon = 'fas fa-check-circle';
            } else if (type === 'error') {
                icon = 'fas fa-exclamation-circle';
            }
            
            // Parse message and details
            let mainMessage = message;
            let details = '';
            let hasDetails = false;
            let adjustedDuration = duration;
            
            if (message.includes('|')) {
                const parts = message.split('|');
                mainMessage = parts[0];
                const detailsStr = parts[1];
                
                // Create details section with bullet points
                const detailItems = detailsStr.split(';').map(item => item.trim());
                details = '<div class="toast-details">' + 
                    detailItems.map(item => `<div class="toast-details-item">• ${item}</div>`).join('') + 
                    '</div>';
                hasDetails = true;
                // Increase duration for detailed messages
                adjustedDuration = duration + 2000;
            }
            
            toast.innerHTML = `
                <i class="${icon}"></i>
                <div class="toast-content">
                    <span class="toast-message">${mainMessage}</span>
                    ${details}
                </div>
            `;
            
            container.appendChild(toast);
            
            // Function to start auto-dismiss
            function startDismissTimer() {
                if (adjustedDuration > 0) {
                    toast.dismissTimeout = setTimeout(() => {
                        toast.classList.add('removing');
                        setTimeout(() => {
                            if (toast.parentElement) {
                                toast.remove();
                            }
                        }, 300);
                    }, adjustedDuration);
                }
            }
            
            // Add hover listeners to pause/resume auto-dismiss
            toast.addEventListener('mouseenter', function() {
                // Clear the dismiss timeout when hovering
                clearTimeout(toast.dismissTimeout);
            });
            
            toast.addEventListener('mouseleave', function() {
                // Resume auto-dismiss when cursor leaves
                startDismissTimer();
            });
            
            // Start the initial timer
            startDismissTimer();
        }

        // Helper function to show success toast
        function showSuccessToast(message, duration = 4000) {
            showToast(message, 'success', duration);
        }

        // Helper function to show error toast
        function showErrorToast(message, duration = 5000) {
            showToast(message, 'error', duration);
        }

        // Helper function to show info toast
        function showInfoToast(message, duration = 4000) {
            showToast(message, 'info', duration);
        }
    </script>
    
    <?php include '../includes/modal-component.php'; ?>
    <div id="infoDialogOverlay" class="info-dialog-overlay" onclick="if(event.target === this) closeInfoDialog();"></div>
    <div id="confirmDialogOverlay" class="confirm-dialog-overlay" onclick="if(event.target === this) closeConfirmDialog();"></div>
    <div id="deleteInfoDialogOverlay" class="delete-dialog-overlay" onclick="if(event.target === this) closeDeleteInfoDialog();"></div>
    <div id="deleteConfirmDialogOverlay" class="delete-dialog-overlay" onclick="if(event.target === this) closeDeleteConfirmDialog();"></div>
    
    <!-- Proof Viewer Modal -->
    <div id="proofViewerModal" onclick="if(event.target===this)closeProofViewer()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:10000;justify-content:center;align-items:center;backdrop-filter:blur(4px);">
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