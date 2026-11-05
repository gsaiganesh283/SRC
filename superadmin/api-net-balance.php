<?php
/**
 * Net Balance Management API Handler
 * Handles automatic and manual net balance recalculation
 */

session_start();
require_once '../config/config.php';
require_once '../includes/net-balance-helper.php';

// Check if user is super admin
if (!isset($_SESSION['superadmin_id'])) {
    error_log("API Access Denied: superadmin_id not set in session");
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access',
        'error' => 'Session not found - please log in first'
    ]);
    exit();
}

header('Content-Type: application/json');

// Log API request for debugging
error_log("API Request - Action: " . ($_GET['action'] ?? $_POST['action'] ?? 'unknown') . 
          " | Method: " . $_SERVER['REQUEST_METHOD'] . 
          " | Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'unknown'));

try {
    // Handle JSON payload from modern clients
    $input = null;
    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
    }
    
    // Get action from JSON payload or form data
    $action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'auto_recalculate':
            // Auto-recalculate net balance for selected members
            error_log("Processing auto_recalculate - Raw input: " . json_encode($input ?? []));
            
            $yearId = $input['year_id'] ?? $_POST['year_id'] ?? null;
            if ($yearId) {
                $yearId = intval($yearId);
                $yearId = ($yearId > 0) ? $yearId : null;
            }
            
            // If no year provided, use active year
            if (!$yearId) {
                $stmt = $pdo->query("SELECT YearID FROM academic_years WHERE Status = 'Active' LIMIT 1");
                $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($activeYear) {
                    $yearId = $activeYear['YearID'];
                }
            }
            
            error_log("Calculated yearId: " . ($yearId ?? 'null'));
            
            $memberIds = $input['member_ids'] ?? $_POST['member_ids'] ?? null;
            if ($memberIds && !is_array($memberIds)) {
                $memberIds = json_decode($memberIds, true);
            }
            
            error_log("Raw memberIds: " . json_encode($memberIds ?? []));
            
            // Filter valid member IDs
            if (is_array($memberIds)) {
                $memberIds = array_filter(array_map('intval', $memberIds), fn($id) => $id > 0);
                $memberIds = array_values($memberIds); // Re-index array
            } else {
                $memberIds = [];
            }
            
            error_log("Filtered memberIds: " . json_encode($memberIds));
            
            // Validate we have members to recalculate
            if (empty($memberIds)) {
                error_log("No valid member IDs provided for auto_recalculate");
                echo json_encode([
                    'success' => false,
                    'message' => 'No members selected for recalculation',
                    'error' => 'No valid member IDs provided'
                ]);
                break;
            }
            
            error_log("Calling autoRecalculateSelectedNetBalances with yearId=$yearId, memberIds=" . json_encode($memberIds));
            $result = autoRecalculateSelectedNetBalances($yearId, $memberIds);
            error_log("Result from helper function: " . json_encode($result));
            
            // Ensure response is valid JSON
            if (is_array($result)) {
                echo json_encode($result);
            } else {
                error_log("WARNING: autoRecalculateSelectedNetBalances returned non-array: " . gettype($result));
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid response from helper function',
                    'error' => 'Non-array result'
                ]);
            }
            break;
            
        case 'get_member_details':
            // Get detailed breakdown for a specific member
            $memberId = intval($_POST['member_id'] ?? 0);
            $yearId = $_POST['year_id'] ?? null;
            if ($yearId) {
                $yearId = intval($yearId);
                $yearId = ($yearId > 0) ? $yearId : null;
            }
            
            if ($memberId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid member ID']);
                break;
            }
            
            $details = getMemberNetBalanceDetails($memberId, $yearId);
            $memberName = '';
            
            // Get member name
            $stmt = $pdo->prepare("SELECT MemberName FROM members WHERE MemberID = ?");
            $stmt->execute([$memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($member) {
                $memberName = $member['MemberName'];
            }
            
            echo json_encode([
                'success' => true,
                'member_id' => $memberId,
                'member_name' => $memberName,
                'details' => $details
            ]);
            break;
            
        case 'get_all_balances':
            // Get all members net balances
            $yearId = $_POST['year_id'] ?? null;
            if ($yearId) {
                $yearId = intval($yearId);
                $yearId = ($yearId > 0) ? $yearId : null;
            }
            
            $balances = getAllMembersNetBalance($yearId);
            echo json_encode([
                'success' => true,
                'members' => $balances,
                'total_members' => count($balances)
            ]);
            break;
            
        case 'get_system_summary':
            // Get system-wide net balance summary
            $yearId = $_POST['year_id'] ?? null;
            if ($yearId) {
                $yearId = intval($yearId);
                $yearId = ($yearId > 0) ? $yearId : null;
            }
            
            $summary = getSystemNetBalanceSummary($yearId);
            echo json_encode([
                'success' => true,
                'summary' => $summary
            ]);
            break;
            
        case 'export_report':
            // Export net balance report
            $yearId = $_POST['year_id'] ?? null;
            if ($yearId) {
                $yearId = intval($yearId);
                $yearId = ($yearId > 0) ? $yearId : null;
            }
            
            $balances = getAllMembersNetBalance($yearId);
            $summary = getSystemNetBalanceSummary($yearId);
            
            $report = [
                'generated_at' => date('Y-m-d H:i:s'),
                'generated_by' => $_SESSION['superadmin_name'] ?? 'System',
                'year_id' => $yearId,
                'system_summary' => $summary,
                'member_balances' => $balances,
                'total_members' => count($balances)
            ];
            
            // Log the export
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, NewData, Severity, IPAddress)
                VALUES ('SuperAdmin', ?, ?, 'Export Net Balance Report', 'member_net_balance', ?, 'Info', ?)
            ");
            $stmt->execute([
                $_SESSION['superadmin_id'],
                $_SESSION['superadmin_name'] ?? 'System',
                "Exported net balance report for " . ($yearId ? "Year ID: $yearId" : "All Years"),
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
            ]);
            
            echo json_encode([
                'success' => true,
                'report' => $report
            ]);
            break;
            
        case 'manual_update':
            // Manually update member net balance
            $memberId = intval($_POST['member_id'] ?? 0);
            $newBalance = floatval($_POST['new_balance'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $yearId = intval($_POST['year_id'] ?? 0);
            $yearId = ($yearId > 0) ? $yearId : null;
            
            // If yearId is not provided, use the active year
            if (!$yearId) {
                $stmt = $pdo->query("SELECT YearID FROM academic_years WHERE Status = 'Active' LIMIT 1");
                $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($activeYear) {
                    $yearId = $activeYear['YearID'];
                } else {
                    echo json_encode(['success' => false, 'message' => 'No active academic year found']);
                    break;
                }
            }
            
            // Validation
            if ($memberId <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid member ID']);
                break;
            }
            
            if (empty($reason)) {
                echo json_encode(['success' => false, 'message' => 'Reason is required']);
                break;
            }
            
            // Get current balance
            $stmt = $pdo->prepare("
                SELECT m.MemberID, m.MemberName, mnb.NetBalance 
                FROM members m 
                LEFT JOIN member_net_balance mnb ON m.MemberID = mnb.MemberID AND mnb.YearID = ?
                WHERE m.MemberID = ?
            ");
            $stmt->execute([$yearId, $memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$member) {
                echo json_encode(['success' => false, 'message' => 'Member not found']);
                break;
            }
            
            $oldBalance = $member['NetBalance'] ?? 0;
            $memberName = $member['MemberName'];
            
            // Update the net balance with YearID (always provided now)
            $stmt = $pdo->prepare("
                INSERT INTO member_net_balance (MemberID, YearID, NetBalance, LastUpdatedAt, UpdatedBy)
                VALUES (?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE 
                    NetBalance = VALUES(NetBalance),
                    LastUpdatedAt = NOW(),
                    UpdatedBy = VALUES(UpdatedBy)
            ");
            $stmt->execute([$memberId, $yearId, $newBalance, $_SESSION['superadmin_id']]);
            
            // Log the manual update
            $oldDataLog = json_encode([
                'MemberID' => $memberId,
                'MemberName' => $memberName,
                'YearID' => $yearId,
                'NetBalance' => $oldBalance
            ]);
            $newDataLog = json_encode([
                'MemberID' => $memberId,
                'MemberName' => $memberName,
                'YearID' => $yearId,
                'NetBalance' => $newBalance,
                'Reason' => $reason,
                'Difference' => $newBalance - $oldBalance
            ]);
            
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, OldData, NewData, Severity, IPAddress)
                VALUES ('SuperAdmin', ?, ?, 'Manual Net Balance Update', 'member_net_balance', ?, ?, ?, 'Warning', ?)
            ");
            $stmt->execute([
                $_SESSION['superadmin_id'],
                $_SESSION['superadmin_name'] ?? 'System',
                $memberId,
                $oldDataLog,
                $newDataLog,
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Net balance updated successfully',
                'member_id' => $memberId,
                'member_name' => $memberName,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'difference' => $newBalance - $oldBalance
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("API Error in api-net-balance.php: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}
?>
