<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Permission check
$canVerifyTransactions = true;
if (isset($_SESSION['admin_id'])) {
    try {
        $canVerifyTransactions = hasAdminPermission($_SESSION['admin_id'], 'verify_transactions');
    } catch (Exception $e) {
        error_log('Permission check error: ' . $e->getMessage());
        $canVerifyTransactions = true;
    }
}

$transactionID = isset($_GET['id']) ? intval($_GET['id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'verify' && $canVerifyTransactions && $transactionID > 0) {
    try {
        // Check if Verified column exists, if not add it
        $checkColumn = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'Verified'");
        if ($checkColumn->rowCount() == 0) {
            $pdo->exec("ALTER TABLE transactions ADD COLUMN Verified TINYINT(1) DEFAULT 0 AFTER Remarks");
            $pdo->exec("ALTER TABLE transactions ADD COLUMN VerifiedBy INT NULL AFTER Verified");
            $pdo->exec("ALTER TABLE transactions ADD COLUMN VerifiedAt DATETIME NULL AFTER VerifiedBy");
        }
        
        // Update verification status
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET 
                Verified = 1,
                VerifiedBy = ?,
                VerifiedAt = NOW()
            WHERE TransactionID = ?
        ");
        $stmt->execute([$_SESSION['admin_id'], $transactionID]);
        
        header('Location: manage-transactions.php?success=Transaction verified successfully');
        exit;
        
    } catch (Exception $e) {
        header('Location: manage-transactions.php?error=' . urlencode($e->getMessage()));
        exit;
    }
} elseif ($action === 'unverify' && $canVerifyTransactions && $transactionID > 0) {
    try {
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET 
                Verified = 0,
                VerifiedBy = NULL,
                VerifiedAt = NULL
            WHERE TransactionID = ?
        ");
        $stmt->execute([$transactionID]);
        
        header('Location: manage-transactions.php?success=Transaction verification removed');
        exit;
        
    } catch (Exception $e) {
        header('Location: manage-transactions.php?error=' . urlencode($e->getMessage()));
        exit;
    }
} else {
    header('Location: manage-transactions.php?error=Invalid request');
    exit;
}
?>
