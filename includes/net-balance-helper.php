<?php
/**
 * Net Balance Calculation Helper Functions
 * Centralized functions for calculating and managing member net balances
 */

require_once __DIR__ . '/change-amount-helper.php';

/**
 * Ensure member_net_balance table exists with correct schema.
 * Auto-creates if missing, adds YearID column if needed, fixes primary key.
 * Safe to call multiple times (uses static flag).
 */
function ensureNetBalanceTableExists() {
    static $checked = false;
    if ($checked) return true;
    global $pdo;
    try {
        // Check if table exists
        $result = $pdo->query("SHOW TABLES LIKE 'member_net_balance'")->fetch();
        if (!$result) {
            // Create the table
            $pdo->exec("
                CREATE TABLE `member_net_balance` (
                    `MemberID` int(11) NOT NULL,
                    `YearID` int(11) NOT NULL,
                    `NetBalance` decimal(15,2) NOT NULL DEFAULT 0.00,
                    `LastUpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `UpdatedBy` int(11) DEFAULT NULL,
                    `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`MemberID`, `YearID`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $checked = true;
            return true;
        }
        // Table exists - check if YearID column exists
        $cols = $pdo->query("SHOW COLUMNS FROM member_net_balance LIKE 'YearID'")->fetch();
        if (!$cols) {
            $pdo->exec("ALTER TABLE member_net_balance ADD COLUMN `YearID` int(11) NOT NULL DEFAULT 1 AFTER `MemberID`");
            // Reset primary key to composite
            try {
                $pdo->exec("ALTER TABLE member_net_balance DROP PRIMARY KEY, ADD PRIMARY KEY (`MemberID`, `YearID`)");
            } catch (Exception $e) {
                // May fail if duplicates exist - clean up first
                $pdo->exec("DELETE t1 FROM member_net_balance t1 INNER JOIN member_net_balance t2 WHERE t1.MemberID = t2.MemberID AND t1.YearID = t2.YearID AND t1.LastUpdatedAt < t2.LastUpdatedAt");
                $pdo->exec("ALTER TABLE member_net_balance DROP PRIMARY KEY, ADD PRIMARY KEY (`MemberID`, `YearID`)");
            }
        }
        $checked = true;
        return true;
    } catch (Exception $e) {
        error_log("ensureNetBalanceTableExists error: " . $e->getMessage());
        return false;
    }
}

/**
 * Safely persist a member's net balance to member_net_balance table.
 * Handles table creation, FK issues, and duplicate key gracefully.
 * Returns true on success, false on failure (never throws).
 *
 * @param int $memberId
 * @param int $yearId
 * @param float|null $netBalance - If null, will be calculated
 * @param int|null $updatedBy - Admin/SuperAdmin ID (nullable to avoid FK issues)
 * @return bool
 */
function persistMemberNetBalance($memberId, $yearId, $netBalance = null, $updatedBy = null) {
    global $pdo;
    try {
        ensureNetBalanceTableExists();
        if ($netBalance === null) {
            $netBalance = calculateMemberNetBalance($memberId, $yearId);
        }
        // Try UPSERT - use NULL for UpdatedBy to avoid FK constraint issues
        $stmt = $pdo->prepare("
            INSERT INTO member_net_balance (MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy)
            VALUES (?, ?, ?, NOW(), NULL)
            ON DUPLICATE KEY UPDATE
                NetBalance = VALUES(NetBalance),
                LastUpdatedAt = NOW()
        ");
        $stmt->execute([$memberId, $yearId, $netBalance]);
        return true;
    } catch (Exception $e) {
        error_log("persistMemberNetBalance error (M:$memberId Y:$yearId): " . $e->getMessage());
        return false;
    }
}

/**
 * Calculate Net Balance for a Member (Accurate Calculation)
 * Net Balance = (DistributedAmount + InterestAmount) - CollectionAmount
 *
 * @param int $memberId - Member ID
 * @param int $yearId - Academic Year ID (optional, null for all years)
 * @return float - Member's net balance
 */
function calculateMemberNetBalance($memberId, $yearId = null) {
    global $pdo;
    try {
        if ($yearId) {
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(DistributedAmount), 0) as total_distributed,
                    COALESCE(SUM(InterestAmount), 0) as total_interest,
                    COALESCE(SUM(CollectionAmount), 0) as total_collection
                FROM transactions 
                WHERE MemberID = ? AND YearID = ?
            ");
            $stmt->execute([$memberId, $yearId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(DistributedAmount), 0) as total_distributed,
                    COALESCE(SUM(InterestAmount), 0) as total_interest,
                    COALESCE(SUM(CollectionAmount), 0) as total_collection
                FROM transactions 
                WHERE MemberID = ?
            ");
            $stmt->execute([$memberId]);
        }
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Member Net Balance = (Distributed + Interest) - Collection ± the member's own change amount entries.
        // General (no member) change amounts are added only at system level in getSystemNetBalanceSummary().
        $netBalance = ($result['total_distributed'] + $result['total_interest']) - $result['total_collection'] + caMemberTotal($pdo, $memberId, $yearId);
        return $netBalance;
    } catch (Exception $e) {
        error_log("Error calculating net balance for member $memberId: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get Current Net Balance for a Member (Unified Across All Portals)
 * Uses manual override from member_net_balance table if available,
 * otherwise calculates from transactions.
 * Formula: Net Balance = (Distribution + Interest) - Collection
 * 
 * @param int $memberId - Member ID
 * @param int $yearId - Academic Year ID (optional, null for all years)
 * @return float - Member's current net balance (respecting manual overrides)
 */
function getCurrentMemberNetBalance($memberId, $yearId = null) {
    global $pdo;
    try {
        // Check for manual override in member_net_balance table
        $checkStmt = $pdo->prepare("
            SELECT NetBalance
            FROM member_net_balance
            WHERE MemberID = ? " . ($yearId ? "AND YearID = ?" : "") . "
            LIMIT 1
        ");
        $params = [$memberId];
        if ($yearId) {
            $params[] = $yearId;
        }
        $checkStmt->execute($params);
        $manualBalance = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // If manual balance exists, use it
        if ($manualBalance !== false && $manualBalance !== null) {
            return floatval($manualBalance['NetBalance']);
        }
        
        // Otherwise, calculate from transactions
        return calculateMemberNetBalance($memberId, $yearId);
    } catch (Exception $e) {
        error_log("Error getting current member net balance for $memberId: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get All Members Net Balance Summary
 * Returns stored balances from member_net_balance table if available,
 * otherwise calculates from transactions
 * 
 * @param int $yearId - Academic Year ID (optional, null for all years)
 * @return array - Array of members with net balance (manual or calculated)
 */
function getAllMembersNetBalance($yearId = null) {
    global $pdo;
    try {
        if ($yearId) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT m.MemberID, m.MemberName
                FROM members m
                INNER JOIN year_members ym ON m.MemberID = ym.MemberID
                WHERE ym.YearID = ? AND ym.Status = 'Active'
                ORDER BY m.MemberID ASC
            ");
            $stmt->execute([$yearId]);
        } else {
            $stmt = $pdo->query("
                SELECT MemberID, MemberName
                FROM members
                WHERE Status = 'Active'
                ORDER BY MemberID ASC
            ");
        }
        
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        
        foreach ($members as $member) {
            if ($yearId) {
                // For specific year: get balance for that year only
                $checkStmt = $pdo->prepare("
                    SELECT NetBalance, LastUpdatedAt
                    FROM member_net_balance
                    WHERE MemberID = ? AND YearID = ?
                    LIMIT 1
                ");
                $checkStmt->execute([$member['MemberID'], $yearId]);
                $manualBalance = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                // Use manual balance if it exists, otherwise calculate from transactions
                if ($manualBalance !== false && $manualBalance !== null) {
                    $netBalance = $manualBalance['NetBalance'];
                } else {
                    $netBalance = calculateMemberNetBalance($member['MemberID'], $yearId);
                }
            } else {
                // For all years: sum all balances across all years
                $sumStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(NetBalance), 0) as total_balance
                    FROM member_net_balance
                    WHERE MemberID = ?
                ");
                $sumStmt->execute([$member['MemberID']]);
                $sumResult = $sumStmt->fetch(PDO::FETCH_ASSOC);
                
                // If we have manual balances recorded, use the sum
                if ($sumResult['total_balance'] != 0) {
                    $netBalance = $sumResult['total_balance'];
                } else {
                    // Otherwise calculate from all transactions
                    $netBalance = calculateMemberNetBalance($member['MemberID'], null);
                }
            }
            
            $result[] = [
                'MemberID' => $member['MemberID'],
                'MemberName' => $member['MemberName'],
                'NetBalance' => $netBalance
            ];
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Error getting all members net balance: " . $e->getMessage());
        return [];
    }
}

/**
 * Get Net Balance Calculation Details for a Member
 * Shows breakdown of all amounts
 * Net Balance = (Distributed + Interest) - Collection
 * 
 * @param int $memberId - Member ID
 * @param int $yearId - Academic Year ID (optional)
 * @return array - Detailed breakdown
 */
function getMemberNetBalanceDetails($memberId, $yearId = null) {
    global $pdo;
    try {
        if ($yearId) {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(CollectionAmount), 0) as total_collection,
                    COALESCE(SUM(DistributedAmount), 0) as total_distributed,
                    COALESCE(SUM(InterestAmount), 0) as total_interest,
                    MIN(TransactionDate) as first_transaction,
                    MAX(TransactionDate) as last_transaction
                FROM transactions 
                WHERE MemberID = ? AND YearID = ?
            ");
            $stmt->execute([$memberId, $yearId]);
        } else {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as transaction_count,
                    COALESCE(SUM(CollectionAmount), 0) as total_collection,
                    COALESCE(SUM(DistributedAmount), 0) as total_distributed,
                    COALESCE(SUM(InterestAmount), 0) as total_interest,
                    MIN(TransactionDate) as first_transaction,
                    MAX(TransactionDate) as last_transaction
                FROM transactions 
                WHERE MemberID = ?
            ");
            $stmt->execute([$memberId]);
        }
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Member Net Balance = (Distributed + Interest) - Collection ± the member's change amount entries
        $data['change_amount'] = caMemberTotal($pdo, $memberId, $yearId);
        $data['net_balance'] = ($data['total_distributed'] + $data['total_interest']) - $data['total_collection'] + $data['change_amount'];
        return $data;
    } catch (Exception $e) {
        error_log("Error getting net balance details for member $memberId: " . $e->getMessage());
        return null;
    }
}

/**
 * Auto-Recalculate All Members Net Balance
 * Recalculates net balance based on transaction data
 * 
 * @param int $yearId - Academic Year ID (optional, null for all years)
 * @return array - Summary of updates
 */
/**
 * Auto Recalculate Net Balances for Selected Members
 * Recalculates net balance only for specified members
 * 
 * @param int $yearId - Academic Year ID (optional)
 * @param array $memberIds - Array of Member IDs to recalculate (optional, if empty will use all)
 * @return array - Result with success status and changes count
 */
function autoRecalculateSelectedNetBalances($yearId = null, $memberIds = null) {
    global $pdo;
    try {
        $updates = [];
        $errors = [];
        $changes = 0;
        
        // Get members to recalculate
        if (!empty($memberIds) && is_array($memberIds)) {
            // Get specific members
            $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
            $stmt = $pdo->prepare("
                SELECT m.MemberID, m.MemberName
                FROM members m
                WHERE m.MemberID IN ($placeholders)
                AND m.Status = 'Active'
                ORDER BY m.MemberID ASC
            ");
            $stmt->execute($memberIds);
        } else {
            // Get all members (fallback if no member IDs provided)
            $members = getAllMembersNetBalance($yearId);
            $stmt = null;
        }
        
        if ($stmt) {
            $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $activeYearId = $yearId;
        if (!$activeYearId) {
            $yearStmt = $pdo->query("SELECT YearID FROM academic_years WHERE Status = 'Active' LIMIT 1");
            $activeYear = $yearStmt->fetch(PDO::FETCH_ASSOC);
            $activeYearId = $activeYear['YearID'] ?? 1;
        }

        ensureNetBalanceTableExists();

        foreach ($members as $member) {
            try {
                $memberId = $member['MemberID'];
                $currentBalance = calculateMemberNetBalance($memberId, $activeYearId);

                persistMemberNetBalance($memberId, $activeYearId, $currentBalance, $_SESSION['superadmin_id'] ?? 0);
                
                // Log the action
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, Details, IPAddress)
                    VALUES ('SuperAdmin', ?, ?, 'Auto Recalculate Net Balance', 'members', ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['superadmin_id'] ?? 0,
                    $_SESSION['superadmin_name'] ?? 'System',
                    $memberId,
                    "Member: {$member['MemberName']} | Calculated Balance: ₹$currentBalance",
                    $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
                ]);
                
                $changes++;
                $updates[] = [
                    'MemberID' => $memberId,
                    'MemberName' => $member['MemberName'],
                    'NetBalance' => $currentBalance,
                    'Status' => 'Success'
                ];
            } catch (Exception $e) {
                $errors[] = "Member {$member['MemberID']}: " . $e->getMessage();
            }
        }
        
        return [
            'success' => true,
            'total_selected' => count($memberIds ?? []),
            'changes' => $changes,
            'errors' => $errors,
            'updates' => $updates
        ];
    } catch (Exception $e) {
        error_log("Error auto-recalculating net balances for selected members: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'changes' => 0
        ];
    }
}

/**
 * Auto Recalculate Net Balances for All Members
 * @deprecated Use autoRecalculateSelectedNetBalances() instead
 * 
 * @param int $yearId - Academic Year ID (optional)
 * @return array - Result with success status and changes count
 */
function autoRecalculateAllNetBalances($yearId = null) {
    global $pdo;
    try {
        $updates = [];
        $errors = [];
        $changes = 0;
        
        $members = getAllMembersNetBalance($yearId);
        
        foreach ($members as $member) {
            try {
                $currentBalance = calculateMemberNetBalance($member['MemberID'], $yearId);
                
                // Log the action
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, Details, IPAddress)
                    VALUES ('SuperAdmin', ?, ?, 'Auto Recalculate Net Balance', 'members', ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['superadmin_id'] ?? 0,
                    $_SESSION['superadmin_name'] ?? 'System',
                    $member['MemberID'],
                    "Member: {$member['MemberName']} | Calculated Balance: ₹$currentBalance",
                    $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
                ]);
                
                $changes++;
                $updates[] = [
                    'MemberID' => $member['MemberID'],
                    'MemberName' => $member['MemberName'],
                    'NetBalance' => $currentBalance,
                    'Status' => 'Success'
                ];
            } catch (Exception $e) {
                $errors[] = "Member {$member['MemberID']}: " . $e->getMessage();
            }
        }
        
        return [
            'success' => true,
            'total_members' => count($members),
            'changes' => $changes,
            'errors' => $errors,
            'updates' => $updates
        ];
    } catch (Exception $e) {
        error_log("Error auto-recalculating net balances: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'changes' => 0
        ];
    }
}

/**
 * Get System-Wide Net Balance Summary
 * Uses stored balances from member_net_balance table (manual or calculated)
 * 
 * @param int $yearId - Academic Year ID (optional)
 * @return array - Total net balance and statistics based on stored member balances
 */
function getSystemNetBalanceSummary($yearId = null) {
    global $pdo;
    try {
        // Get all member balances from member_net_balance table
        $allBalances = getAllMembersNetBalance($yearId);
        
        $totalNetBalance = 0;
        foreach ($allBalances as $member) {
            $totalNetBalance += $member['NetBalance'];
        }
        
        // Also get the transaction totals for reference
        if ($yearId) {
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(SUM(CollectionAmount), 0) as total_collection,
                    COALESCE(SUM(DistributedAmount), 0) as total_distributed,
                    COALESCE(SUM(InterestAmount), 0) as total_interest
                FROM transactions 
                WHERE YearID = ?
            ");
            $stmt->execute([$yearId]);
        } else {
            $stmt = $pdo->query("
                SELECT 
                    COALESCE(SUM(CollectionAmount), 0) as total_collection,
                    COALESCE(SUM(DistributedAmount), 0) as total_distributed,
                    COALESCE(SUM(InterestAmount), 0) as total_interest
                FROM transactions
            ");
        }
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Change amount entries: member entries are already inside the member balances,
        // so only the general (no member) part is added here
        $ca = caTotals($pdo, $yearId);
        $changeAmount = $ca['general'];

        $data['change_amount'] = $changeAmount;
        $data['change_amount_members'] = $ca['members'];
        $data['change_amount_total'] = $ca['total'];
        // System net balance = sum of all member balances + general change amount (non-member adjustment)
        $data['net_balance'] = $totalNetBalance + $changeAmount;
        return $data;
    } catch (Exception $e) {
        error_log("Error getting system net balance summary: " . $e->getMessage());
        return null;
    }
}

/**
 * Update Net Balance After Transaction Change
 * Handles recalculation for all affected members and years when a transaction is modified
 * CRITICAL: Call this after updating a transaction to handle cases where MemberID or YearID changes
 * 
 * @param int $oldMemberID - Member ID before the change
 * @param int $oldYearID - Year ID before the change
 * @param int $newMemberID - Member ID after the change
 * @param int $newYearID - Year ID after the change
 * @param int $updatedByUserID - ID of the admin/user making the change
 * @return array - Result with success status and affected combinations
 */
function updateNetBalanceAfterTransactionChange($oldMemberID, $oldYearID, $newMemberID, $newYearID, $updatedByUserID = 0) {
    global $pdo;
    try {
        $updates = [];
        $membersToRecalculate = [];
        $yearsToRecalculate = [];
        
        // Collect all affected member-year combinations
        // Add new combination
        $membersToRecalculate[$newMemberID] = true;
        $yearsToRecalculate[$newYearID] = true;
        
        // If member changed, also recalculate for old member
        if ($oldMemberID !== $newMemberID) {
            $membersToRecalculate[$oldMemberID] = true;
        }
        
        // If year changed, also recalculate for old year
        if ($oldYearID !== $newYearID) {
            $yearsToRecalculate[$oldYearID] = true;
        }
        
        // Recalculate net balance for all affected combinations
        foreach ($membersToRecalculate as $mID => $true) {
            foreach ($yearsToRecalculate as $yID => $true) {
                $newNetBalance = calculateMemberNetBalance($mID, $yID);

                persistMemberNetBalance($mID, $yID, $newNetBalance, $updatedByUserID);
                
                $updates[] = [
                    'MemberID' => $mID,
                    'YearID' => $yID,
                    'NetBalance' => $newNetBalance
                ];
            }
        }
        
        return [
            'success' => true,
            'updates' => $updates,
            'combinations_updated' => count($updates)
        ];
    } catch (Exception $e) {
        error_log("Error updating net balance after transaction change: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Recalculate All Transactions for a Member
 * Recalculates net balance for a specific member across all years
 * Useful when multiple transactions of a member are modified externally
 * 
 * @param int $memberId - Member ID to recalculate
 * @param int $updatedByUserID - ID of the admin/user making the change
 * @return array - Result with success status and years affected
 */
function recalculateAllTransactionsForMember($memberId, $updatedByUserID = 0) {
    global $pdo;
    try {
        $yearsAffected = [];
        
        // Get all years where this member has transactions (FIX: Use table alias to avoid ambiguous column)
        $stmt = $pdo->prepare("
            SELECT DISTINCT t.YearID FROM transactions t WHERE t.MemberID = ?
        ");
        $stmt->execute([$memberId]);
        $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($years as $year) {
            $yID = intval($year['YearID']);
            $newNetBalance = calculateMemberNetBalance($memberId, $yID);

            persistMemberNetBalance($memberId, $yID, $newNetBalance, $updatedByUserID);
            
            $yearsAffected[] = [
                'YearID' => $yID,
                'NetBalance' => $newNetBalance
            ];
        }
        
        return [
            'success' => true,
            'member_id' => $memberId,
            'years_affected' => count($yearsAffected),
            'details' => $yearsAffected
        ];
    } catch (Exception $e) {
        error_log("Error recalculating all transactions for member $memberId: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * ADVANCED FEATURE: Verify Balance Integrity
 * Compares calculated balance with stored balance to detect discrepancies
 * 
 * @param int $memberId - Member ID to verify
 * @param int $yearId - Year ID (optional, null for all years)
 * @return array - Verification results with discrepancies if any
 */
function verifyBalanceIntegrity($memberId, $yearId = null) {
    global $pdo;
    try {
        $discrepancies = [];
        $verified = [];
        
        if ($yearId) {
            // Single year verification
            $calculated = calculateMemberNetBalance($memberId, $yearId);
            $stored = getCurrentMemberNetBalance($memberId, $yearId);
            
            if (abs($calculated - $stored) > 0.01) {
                $discrepancies[] = [
                    'YearID' => $yearId,
                    'Calculated' => $calculated,
                    'Stored' => $stored,
                    'Difference' => $stored - $calculated
                ];
            } else {
                $verified[] = [
                    'YearID' => $yearId,
                    'Balance' => $calculated,
                    'Status' => 'OK'
                ];
            }
        } else {
            // All years verification
            $stmt = $pdo->prepare("
                SELECT DISTINCT t.YearID FROM transactions t WHERE t.MemberID = ?
            ");
            $stmt->execute([$memberId]);
            $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($years as $year) {
                $yID = intval($year['YearID']);
                $calculated = calculateMemberNetBalance($memberId, $yID);
                $stored = getCurrentMemberNetBalance($memberId, $yID);
                
                if (abs($calculated - $stored) > 0.01) {
                    $discrepancies[] = [
                        'YearID' => $yID,
                        'Calculated' => $calculated,
                        'Stored' => $stored,
                        'Difference' => $stored - $calculated
                    ];
                } else {
                    $verified[] = [
                        'YearID' => $yID,
                        'Balance' => $calculated,
                        'Status' => 'OK'
                    ];
                }
            }
        }
        
        return [
            'success' => true,
            'member_id' => $memberId,
            'verified_count' => count($verified),
            'discrepancies_count' => count($discrepancies),
            'verified' => $verified,
            'discrepancies' => $discrepancies,
            'integrity_status' => count($discrepancies) === 0 ? 'PASS' : 'FAIL'
        ];
    } catch (Exception $e) {
        error_log("Error verifying balance integrity for member $memberId: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * ADVANCED FEATURE: Get Balance Summary with Anomalies
 * Returns summary with alerting for unusual balances
 * 
 * @param int $memberId - Member ID
 * @param int $yearId - Year ID (optional)
 * @return array - Summary with anomaly detection
 */
function getBalanceSummaryWithAnomalies($memberId, $yearId = null) {
    global $pdo;
    try {
        $details = getMemberNetBalanceDetails($memberId, $yearId);
        $currentBalance = getCurrentMemberNetBalance($memberId, $yearId);
        
        // Detect anomalies
        $anomalies = [];
        
        // Check if collection > distribution (unusual pattern)
        if ($details['total_collection'] > ($details['total_distributed'] + $details['total_interest']) * 1.5) {
            $anomalies[] = [
                'type' => 'HIGH_COLLECTION',
                'severity' => 'INFO',
                'message' => 'Collection exceeds distributions significantly',
                'value' => $details['total_collection']
            ];
        }
        
        // Check for negative balance (member overpaid)
        if ($currentBalance < -1000) {
            $anomalies[] = [
                'type' => 'NEGATIVE_BALANCE',
                'severity' => 'INFO',
                'message' => 'Member has overpaid (balance is negative)',
                'value' => $currentBalance
            ];
        }
        
        // Check for very large outstanding balance
        if ($currentBalance > 100000) {
            $anomalies[] = [
                'type' => 'LARGE_OUTSTANDING',
                'severity' => 'WARNING',
                'message' => 'Large outstanding balance detected',
                'value' => $currentBalance
            ];
        }
        
        // No transactions
        if ($details['transaction_count'] === 0) {
            $anomalies[] = [
                'type' => 'NO_TRANSACTIONS',
                'severity' => 'INFO',
                'message' => 'No transactions found for this member in this year',
                'value' => 0
            ];
        }
        
        return [
            'success' => true,
            'member_id' => $memberId,
            'summary' => $details,
            'current_balance' => $currentBalance,
            'anomalies' => $anomalies,
            'anomaly_count' => count($anomalies),
            'status' => count($anomalies) > 0 ? 'REVIEW_REQUIRED' : 'NORMAL'
        ];
    } catch (Exception $e) {
        error_log("Error getting balance summary with anomalies for member $memberId: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * ADVANCED FEATURE: Batch Verify & Fix All Member Balances
 * Scans all members and fixes any discrepancies
 * 
 * @param int $yearId - Year ID (optional, null for all years)
 * @param int $userID - Admin user ID
 * @return array - Results of verification and fixes
 */
function batchVerifyAndFixAllBalances($yearId = null, $userID = 0) {
    global $pdo;
    try {
        $fixed = [];
        $discrepancies = [];
        $verified = [];
        
        // Get all active members
        $stmt = $pdo->query("SELECT MemberID, MemberName FROM members WHERE Status = 'Active' ORDER BY MemberID");
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($members as $member) {
            $memberId = $member['MemberID'];
            
            // Verify integrity
            $result = verifyBalanceIntegrity($memberId, $yearId);
            
            if (!$result['success']) {
                continue; // Skip on error
            }
            
            if ($result['discrepancies_count'] > 0) {
                // Found discrepancies - fix them
                foreach ($result['discrepancies'] as $disc) {
                    $recalcResult = recalculateAllTransactionsForMember($memberId, $userID);
                    if ($recalcResult['success']) {
                        $fixed[] = [
                            'MemberID' => $memberId,
                            'MemberName' => $member['MemberName'],
                            'YearID' => $disc['YearID'],
                            'FixedBalance' => $disc['Calculated'],
                            'PreviousBalance' => $disc['Stored']
                        ];
                    } else {
                        $discrepancies[] = [
                            'MemberID' => $memberId,
                            'MemberName' => $member['MemberName'],
                            'Error' => $recalcResult['error']
                        ];
                    }
                }
            } else {
                $verified[] = $member['MemberID'];
            }
        }
        
        return [
            'success' => true,
            'total_members_checked' => count($members),
            'verified_count' => count($verified),
            'fixed_count' => count($fixed),
            'discrepancies_count' => count($discrepancies),
            'fixed_details' => $fixed,
            'failed_fixes' => $discrepancies,
            'status' => count($discrepancies) > 0 ? 'PARTIAL_SUCCESS' : 'SUCCESS'
        ];
    } catch (Exception $e) {
        error_log("Error batch verifying balances: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * ADVANCED FEATURE: Get Member Balance History
 * Returns balance changes over time for audit trail
 * 
 * @param int $memberId - Member ID
 * @param int $yearId - Year ID (optional)
 * @param int $limit - Maximum number of records to return
 * @return array - Balance history with timestamps
 */
function getMemberBalanceHistory($memberId, $yearId = null, $limit = 50) {
    global $pdo;
    try {
        if ($yearId) {
            $stmt = $pdo->prepare("
                SELECT 
                    MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy,
                    DATE_FORMAT(LastUpdatedAt, '%Y-%m-%d %H:%i:%s') as UpdatedAtFormatted
                FROM member_net_balance
                WHERE MemberID = ? AND YearID = ?
                ORDER BY LastUpdatedAt DESC
                LIMIT ?
            ");
            $stmt->execute([$memberId, $yearId, intval($limit)]);
        } else {
            $stmt = $pdo->prepare("
                SELECT 
                    MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy,
                    DATE_FORMAT(LastUpdatedAt, '%Y-%m-%d %H:%i:%s') as UpdatedAtFormatted
                FROM member_net_balance
                WHERE MemberID = ?
                ORDER BY YearID DESC, LastUpdatedAt DESC
                LIMIT ?
            ");
            $stmt->execute([$memberId, intval($limit)]);
        }
        
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate balance changes
        $balanceChanges = [];
        for ($i = 0; $i < count($history) - 1; $i++) {
            $change = $history[$i]['NetBalance'] - $history[$i + 1]['NetBalance'];
            $balanceChanges[] = array_merge($history[$i], [
                'BalanceChange' => $change,
                'ChangeDirection' => $change > 0 ? 'INCREASE' : ($change < 0 ? 'DECREASE' : 'NO_CHANGE')
            ]);
        }
        
        if (count($history) > 0) {
            $balanceChanges[] = array_merge($history[count($history) - 1], [
                'BalanceChange' => 0,
                'ChangeDirection' => 'INITIAL'
            ]);
        }
        
        return [
            'success' => true,
            'member_id' => $memberId,
            'record_count' => count($balanceChanges),
            'history' => $balanceChanges
        ];
    } catch (Exception $e) {
        error_log("Error getting balance history for member $memberId: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * ADVANCED FEATURE: Generate Balance Report
 * Comprehensive report with statistics and insights
 * 
 * @param int $yearId - Year ID
 * @return array - Detailed balance report with statistics
 */
function generateBalanceReport($yearId) {
    global $pdo;
    try {
        // Get summary statistics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT MemberID) as total_members,
                COUNT(*) as total_records,
                SUM(NetBalance) as total_net_balance,
                AVG(NetBalance) as average_balance,
                MIN(NetBalance) as min_balance,
                MAX(NetBalance) as max_balance,
                STDDEV(NetBalance) as std_deviation
            FROM member_net_balance
            WHERE YearID = ?
        ");
        $stmt->execute([$yearId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get member status distribution
        $statusStmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN NetBalance > 0 THEN 'DUE'
                    WHEN NetBalance < 0 THEN 'PAYABLE'
                    ELSE 'SETTLED'
                END as Status,
                COUNT(*) as Count
            FROM member_net_balance
            WHERE YearID = ?
            GROUP BY Status
        ");
        $statusStmt->execute([$yearId]);
        $statusDistribution = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top 10 outstanding members
        $topStmt = $pdo->prepare("
            SELECT 
                m.MemberID, m.MemberName, mnb.NetBalance
            FROM member_net_balance mnb
            JOIN members m ON mnb.MemberID = m.MemberID
            WHERE mnb.YearID = ?
            ORDER BY mnb.NetBalance DESC
            LIMIT 10
        ");
        $topStmt->execute([$yearId]);
        $topOutstanding = $topStmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'year_id' => $yearId,
            'statistics' => $stats,
            'status_distribution' => $statusDistribution,
            'top_10_outstanding' => $topOutstanding
        ];
    } catch (Exception $e) {
        error_log("Error generating balance report for year $yearId: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
?>
