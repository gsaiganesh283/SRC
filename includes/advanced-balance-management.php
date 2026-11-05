<?php
/**
 * Advanced Net Balance Management System
 * Enterprise-grade features for balance management, validation, and reporting
 * 
 * Features:
 * - Balance validation rules and constraints
 * - Anomaly detection and alerts
 * - Transaction limits and thresholds
 * - Balance approval workflow
 * - Recovery and rollback capabilities
 * - Advanced audit trail
 */

require_once 'net-balance-helper.php';

/**
 * ADVANCED FEATURE: Define and Validate Balance Rules
 * Implements business rules for acceptable balance ranges
 */
class BalanceValidationRules {
    
    /**
     * Check if balance violates any rules
     */
    public static function validateBalance($memberId, $yearId, $balance, $transactionType = 'ADJUSTMENT') {
        $violations = [];
        
        // Rule 1: Maximum outstanding allowed
        if ($balance > 250000) {
            $violations[] = [
                'rule' => 'MAX_OUTSTANDING',
                'severity' => 'ERROR',
                'message' => 'Balance exceeds maximum allowed: ₹250,000',
                'current' => $balance,
                'limit' => 250000
            ];
        }
        
        // Rule 2: Minimum negative balance (overpayment limit)
        if ($balance < -100000) {
            $violations[] = [
                'rule' => 'MIN_NEGATIVE',
                'severity' => 'WARNING',
                'message' => 'Member has overpaid beyond limit: -₹100,000',
                'current' => $balance,
                'limit' => -100000
            ];
        }
        
        // Rule 3: Large transaction check
        if ($transactionType === 'SINGLE_TXN' && abs($balance) > 50000) {
            $violations[] = [
                'rule' => 'LARGE_SINGLE_TXN',
                'severity' => 'WARNING',
                'message' => 'Single transaction exceeds ₹50,000 threshold',
                'current' => $balance,
                'threshold' => 50000
            ];
        }
        
        return [
            'valid' => count($violations) === 0,
            'violations' => $violations,
            'violation_count' => count($violations)
        ];
    }
    
    /**
     * Get allowed transaction limits for a member
     */
    public static function getTransactionLimits($memberId, $yearId) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN CollectionAmount > 0 THEN CollectionAmount ELSE 0 END) as total_collections,
                    SUM(CASE WHEN DistributedAmount > 0 THEN DistributedAmount ELSE 0 END) as total_distributions,
                    COUNT(*) as transaction_count
                FROM transactions
                WHERE MemberID = ? AND YearID = ?
            ");
            $stmt->execute([$memberId, $yearId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Calculate limits based on history
            $monthsActive = max((time() - strtotime('-1 year')) / (30 * 86400), 1);
            $avgMonthlyCollection = $stats['total_collections'] / $monthsActive;
            $avgMonthlyDistribution = $stats['total_distributions'] / $monthsActive;
            
            return [
                'member_id' => $memberId,
                'max_single_transaction' => min($avgMonthlyCollection * 2, 100000),
                'max_daily_transactions' => $avgMonthlyCollection * 3,
                'max_monthly_transactions' => $avgMonthlyCollection * 10,
                'daily_transaction_count' => 0, // Would need to track separately
                'monthly_transaction_count' => $stats['transaction_count'],
                'status' => 'NORMAL'
            ];
        } catch (Exception $e) {
            error_log("Error getting transaction limits: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * ADVANCED FEATURE: Balance Recovery and Rollback System
 */
class BalanceRecovery {
    
    /**
     * Create a recovery checkpoint (snapshot current state)
     */
    public static function createCheckpoint($memberId, $yearId, $reason, $userId) {
        global $pdo;
        try {
            $balance = calculateMemberNetBalance($memberId, $yearId);
            
            // Create checkpoint table if not exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS balance_checkpoints (
                    CheckpointID INT AUTO_INCREMENT PRIMARY KEY,
                    MemberID INT(11),
                    YearID INT(11),
                    SnapshotBalance DECIMAL(15,2),
                    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CreatedBy INT(11),
                    Reason VARCHAR(255),
                    Status VARCHAR(50) DEFAULT 'ACTIVE',
                    UNIQUE KEY uk_member_year_time (MemberID, YearID, CreatedAt)
                )
            ");
            
            $stmt = $pdo->prepare("
                INSERT INTO balance_checkpoints (MemberID, YearID, SnapshotBalance, CreatedBy, Reason)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$memberId, $yearId, $balance, $userId, $reason]);
            
            return [
                'success' => true,
                'checkpoint_id' => $pdo->lastInsertId(),
                'member_id' => $memberId,
                'snapshot_balance' => $balance,
                'created_at' => date('Y-m-d H:i:s')
            ];
        } catch (Exception $e) {
            error_log("Error creating checkpoint: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get all checkpoints for a member
     */
    public static function getCheckpoints($memberId, $yearId = null) {
        global $pdo;
        try {
            if ($yearId) {
                $stmt = $pdo->prepare("
                    SELECT * FROM balance_checkpoints
                    WHERE MemberID = ? AND YearID = ?
                    ORDER BY CreatedAt DESC
                    LIMIT 20
                ");
                $stmt->execute([$memberId, $yearId]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT * FROM balance_checkpoints
                    WHERE MemberID = ?
                    ORDER BY CreatedAt DESC
                    LIMIT 50
                ");
                $stmt->execute([$memberId]);
            }
            
            return [
                'success' => true,
                'checkpoints' => $stmt->fetchAll(PDO::FETCH_ASSOC),
                'count' => $stmt->rowCount()
            ];
        } catch (Exception $e) {
            error_log("Error getting checkpoints: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * ADVANCED FEATURE: Balance Approval Workflow
 */
class BalanceApprovalWorkflow {
    
    /**
     * Request manual balance override with approval
     */
    public static function requestBalanceOverride($memberId, $yearId, $proposedBalance, $reason, $requestedBy) {
        global $pdo;
        try {
            $currentBalance = getCurrentMemberNetBalance($memberId, $yearId);
            
            // Create approval request
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS balance_override_requests (
                    RequestID INT AUTO_INCREMENT PRIMARY KEY,
                    MemberID INT(11),
                    YearID INT(11),
                    CurrentBalance DECIMAL(15,2),
                    ProposedBalance DECIMAL(15,2),
                    Reason VARCHAR(500),
                    RequestedBy INT(11),
                    RequestedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    Status VARCHAR(50) DEFAULT 'PENDING',
                    ApprovedBy INT(11),
                    ApprovedAt TIMESTAMP NULL,
                    ApprovalNotes VARCHAR(500),
                    UNIQUE KEY uk_member_pending (MemberID, YearID, Status)
                )
            ");
            
            $stmt = $pdo->prepare("
                INSERT INTO balance_override_requests 
                (MemberID, YearID, CurrentBalance, ProposedBalance, Reason, RequestedBy)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$memberId, $yearId, $currentBalance, $proposedBalance, $reason, $requestedBy]);
            
            return [
                'success' => true,
                'request_id' => $pdo->lastInsertId(),
                'status' => 'PENDING_APPROVAL',
                'current_balance' => $currentBalance,
                'proposed_balance' => $proposedBalance,
                'difference' => $proposedBalance - $currentBalance
            ];
        } catch (Exception $e) {
            error_log("Error requesting balance override: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Approve balance override request
     */
    public static function approveOverrideRequest($requestId, $approvedBy, $notes = '') {
        global $pdo;
        try {
            // Get request details
            $stmt = $pdo->prepare("SELECT * FROM balance_override_requests WHERE RequestID = ? AND Status = 'PENDING'");
            $stmt->execute([$requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                return ['success' => false, 'error' => 'Request not found or already processed'];
            }
            
            // Update request status
            $updateStmt = $pdo->prepare("
                UPDATE balance_override_requests
                SET Status = 'APPROVED', ApprovedBy = ?, ApprovedAt = NOW(), ApprovalNotes = ?
                WHERE RequestID = ?
            ");
            $updateStmt->execute([$approvedBy, $notes, $requestId]);
            
            // Apply the balance override
            $overrideStmt = $pdo->prepare("
                INSERT INTO member_net_balance (MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy)
                VALUES (?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE 
                    NetBalance = VALUES(NetBalance),
                    LastUpdatedAt = NOW(),
                    UpdatedBy = VALUES(UpdatedBy)
            ");
            $overrideStmt->execute([
                $request['MemberID'],
                $request['YearID'],
                $request['ProposedBalance'],
                $approvedBy
            ]);
            
            // Log in audit
            $auditStmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, Details, IPAddress)
                VALUES ('Admin', ?, ?, 'Balance Override Approved', 'member_net_balance', ?, ?, ?)
            ");
            $auditStmt->execute([
                $approvedBy,
                'Admin',
                $request['MemberID'],
                "Approved override from ₹{$request['CurrentBalance']} to ₹{$request['ProposedBalance']}: {$notes}",
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
            ]);
            
            return [
                'success' => true,
                'request_id' => $requestId,
                'applied_balance' => $request['ProposedBalance'],
                'previous_balance' => $request['CurrentBalance']
            ];
        } catch (Exception $e) {
            error_log("Error approving override request: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Reject balance override request
     */
    public static function rejectOverrideRequest($requestId, $rejectedBy, $reason) {
        global $pdo;
        try {
            $stmt = $pdo->prepare("
                UPDATE balance_override_requests
                SET Status = 'REJECTED', ApprovedBy = ?, ApprovedAt = NOW(), ApprovalNotes = ?
                WHERE RequestID = ?
            ");
            $stmt->execute([$rejectedBy, $reason, $requestId]);
            
            return [
                'success' => true,
                'request_id' => $requestId,
                'status' => 'REJECTED'
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * ADVANCED FEATURE: Balance Dashboard Analytics
 */
class BalanceAnalytics {
    
    /**
     * Get comprehensive dashboard analytics
     */
    public static function getDashboardAnalytics($yearId) {
        global $pdo;
        try {
            $data = [];
            
            // Total members analysis
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT m.MemberID) as total_members,
                    COUNT(DISTINCT CASE WHEN mnb.NetBalance > 0 THEN mnb.MemberID END) as members_due,
                    COUNT(DISTINCT CASE WHEN mnb.NetBalance < 0 THEN mnb.MemberID END) as members_payable,
                    COUNT(DISTINCT CASE WHEN mnb.NetBalance = 0 THEN mnb.MemberID END) as members_settled
                FROM members m
                LEFT JOIN member_net_balance mnb ON m.MemberID = mnb.MemberID AND mnb.YearID = ?
            ");
            $stmt->execute([$yearId]);
            $data['member_analysis'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Transaction volume analysis
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_transactions,
                    COUNT(CASE WHEN CollectionAmount > 0 THEN 1 END) as collection_count,
                    COUNT(CASE WHEN DistributedAmount > 0 THEN 1 END) as distribution_count,
                    SUM(CollectionAmount) as total_collections,
                    SUM(DistributedAmount) as total_distributions,
                    SUM(InterestAmount) as total_interest
                FROM transactions WHERE YearID = ?
            ");
            $stmt->execute([$yearId]);
            $data['transaction_analysis'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Monthly trend
            $stmt = $pdo->prepare("
                SELECT 
                    MonthName,
                    COUNT(*) as txn_count,
                    SUM(CollectionAmount) as monthly_collection,
                    SUM(DistributedAmount) as monthly_distribution
                FROM transactions
                WHERE YearID = ?
                GROUP BY TransactionMonth, MonthName
                ORDER BY TransactionMonth
            ");
            $stmt->execute([$yearId]);
            $data['monthly_trend'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Top debtors
            $stmt = $pdo->prepare("
                SELECT 
                    m.MemberID, m.MemberName, mnb.NetBalance,
                    COUNT(t.TransactionID) as transaction_count
                FROM members m
                LEFT JOIN member_net_balance mnb ON m.MemberID = mnb.MemberID AND mnb.YearID = ?
                LEFT JOIN transactions t ON m.MemberID = t.MemberID AND t.YearID = ?
                WHERE mnb.NetBalance > 0
                ORDER BY mnb.NetBalance DESC
                LIMIT 10
            ");
            $stmt->execute([$yearId, $yearId]);
            $data['top_debtors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'analytics' => $data,
                'year_id' => $yearId
            ];
        } catch (Exception $e) {
            error_log("Error getting dashboard analytics: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

/**
 * ADVANCED FEATURE: Transaction Reconciliation
 */
class TransactionReconciliation {
    
    /**
     * Compare member ledger with calculated balance
     */
    public static function reconcileMemberAccount($memberId, $yearId) {
        global $pdo;
        try {
            // Get all transactions
            $stmt = $pdo->prepare("
                SELECT 
                    TransactionID, TransactionDate, CollectionAmount, DistributedAmount, 
                    InterestAmount, Remarks
                FROM transactions
                WHERE MemberID = ? AND YearID = ?
                ORDER BY TransactionDate, TransactionTime
            ");
            $stmt->execute([$memberId, $yearId]);
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate running balance
            $runningBalance = 0;
            $ledger = [];
            
            foreach ($transactions as $txn) {
                $txnBalance = ($txn['DistributedAmount'] + $txn['InterestAmount']) - $txn['CollectionAmount'];
                $runningBalance += $txnBalance;
                
                $ledger[] = [
                    'date' => $txn['TransactionDate'],
                    'id' => $txn['TransactionID'],
                    'distribution' => $txn['DistributedAmount'],
                    'interest' => $txn['InterestAmount'],
                    'collection' => $txn['CollectionAmount'],
                    'impact' => $txnBalance,
                    'running_balance' => $runningBalance,
                    'remarks' => $txn['Remarks']
                ];
            }
            
            // Get stored balance
            $storedBalance = getCurrentMemberNetBalance($memberId, $yearId);
            
            // Compare
            $reconciliation_status = abs($runningBalance - $storedBalance) < 0.01 ? 'RECONCILED' : 'MISMATCH';
            
            return [
                'success' => true,
                'member_id' => $memberId,
                'year_id' => $yearId,
                'calculated_balance' => $runningBalance,
                'stored_balance' => $storedBalance,
                'difference' => $storedBalance - $runningBalance,
                'status' => $reconciliation_status,
                'transaction_count' => count($ledger),
                'ledger' => $ledger
            ];
        } catch (Exception $e) {
            error_log("Error reconciling member account: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
?>