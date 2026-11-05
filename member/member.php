<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireMemberLogin();

$memberId = isset($_GET['id']) ? intval($_GET['id']) : $_SESSION['member_id'];

// Security check: Members can only view their own profile
if ($memberId != $_SESSION['member_id']) {
    header('Location: member.php?id=' . $_SESSION['member_id']);
    exit();
}

// Handle academic year selection
$selectedYearId = null;
if (isset($_GET['year'])) {
    $selectedYearId = intval($_GET['year']);
    $_SESSION['member_selected_year'] = $selectedYearId;
} elseif (isset($_SESSION['member_selected_year'])) {
    $selectedYearId = $_SESSION['member_selected_year'];
}

// Fetch member details
try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE MemberID = ?");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        die("Member not found");
    }
    
    // Get all academic years for dropdown
    $stmt = $pdo->query("SELECT * FROM academic_years ORDER BY YearName DESC");
    $allYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get active academic year
    $stmt = $pdo->query("SELECT * FROM academic_years WHERE Status = 'Active' LIMIT 1");
    $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Determine which year to display
    $displayYear = null;
    if ($selectedYearId) {
        // Find selected year
        foreach ($allYears as $year) {
            if ($year['YearID'] == $selectedYearId) {
                $displayYear = $year;
                break;
            }
        }
    }
    // If no valid selection, use active year
    if (!$displayYear) {
        $displayYear = $activeYear;
    }
    
    // Get transaction statistics for current year
    $totalPaid = 0;
    $totalReceived = 0;
    $totalInterest = 0;
    $currentDue = 0;
    $recentTransactions = [];
    
    if ($displayYear) {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CollectionAmount), 0) as TotalPaid,
                COALESCE(SUM(DistributedAmount), 0) as TotalReceived,
                COALESCE(SUM(InterestAmount), 0) as TotalInterest,
                COUNT(*) as TotalTransactions
            FROM transactions 
            WHERE MemberID = ? AND YearID = ?
        ");
        $stmt->execute([$memberId, $displayYear['YearID']]);
        $yearStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $totalPaid = $yearStats['TotalPaid'];
        $totalReceived = $yearStats['TotalReceived'];
        $totalInterest = $yearStats['TotalInterest'];
        $totalTransactions = $yearStats['TotalTransactions'];
        
        // Calculate current due
        $currentDue = $totalInterest - ($totalPaid - $totalReceived);
        
        // Get recent transactions
        $stmt = $pdo->prepare("
            SELECT 
                *,
                DATE_FORMAT(TransactionDate, '%M %Y') AS MonthName,
                CASE 
                    WHEN CollectionAmount > 0 AND DistributedAmount = 0 THEN 'Collection'
                    WHEN DistributedAmount > 0 AND CollectionAmount = 0 THEN 'Distribution'
                    WHEN CollectionAmount > 0 AND DistributedAmount > 0 THEN 'Both'
                    ELSE 'Other'
                END AS TransactionType
            FROM transactions 
            WHERE MemberID = ? AND YearID = ?
            ORDER BY TransactionDate DESC, TransactionTime DESC 
            LIMIT 5
        ");
        $stmt->execute([$memberId, $displayYear['YearID']]);
        $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Legacy: Try to fetch old payment data if it exists
    $payments = [];
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM payments 
            WHERE MemberID = ? 
            ORDER BY PaymentDate DESC 
            LIMIT 10
        ");
        $stmt->execute([$memberId]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // payments table might not exist
    }
    
} catch (Exception $e) {
    // If new tables don't exist, set defaults
    $activeYear = null;
    $displayYear = null;
    $allYears = [];
    $totalPaid = 0;
    $totalReceived = 0;
    $totalInterest = 0;
    $currentDue = 0;
    $recentTransactions = [];
    $payments = [];
    $totalTransactions = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Dashboard - <?php echo htmlspecialchars($member['MemberName'] ?? 'Member'); ?></title>
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/styles.css">
</head>
<body>
    <div class="particles"></div>
    
    <div class="dashboard-container">
        <div class="dashboard-header">
            <div class="member-info">
                <?php if (!empty($member['Photo']) && file_exists($member['Photo'])): ?>
                    <img src="<?php echo htmlspecialchars($member['Photo']); ?>" alt="Profile Photo" class="member-photo">
                <?php else: ?>
                    <div class="member-photo-placeholder">
                        <?php echo strtoupper(substr($member['MemberName'] ?? 'M', 0, 1)); ?>
                    </div>
                <?php endif; ?>
                
                <div class="member-details">
                    <h2>👋 Welcome, <?php echo htmlspecialchars($member['MemberName'] ?? 'Member'); ?>!</h2>
                    <p>📱 <?php echo htmlspecialchars($member['Phone']); ?></p>
                    <?php if (!empty($member['Email'])): ?>
                        <p>📧 <?php echo htmlspecialchars($member['Email']); ?></p>
                    <?php endif; ?>
                    <p>🆔 Member ID: <?php echo htmlspecialchars($member['MemberID']); ?></p>
                </div>
            </div>
            
            <div class="dashboard-actions">
                <a href="dashboard-new.php" class="btn btn-primary">💰 My Payments</a>
                <a href="../auth/change-password.php" class="btn btn-secondary">🔒 Change Password</a>
                <a href="profile.php" class="btn btn-secondary">✏️ Edit Profile</a>
                <a href="../auth/logout.php" class="btn btn-danger">🚪 Logout</a>
            </div>
        </div>
        
        <?php if ($displayYear): ?>
        <!-- Academic Year Selection and Info -->
        <div style="background: linear-gradient(135deg, #667eea, #764ba2); padding: 20px 30px; border-radius: 15px; margin-bottom: 25px; color: white; box-shadow: 0 8px 30px rgba(0,0,0,0.2);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div style="flex: 1; min-width: 200px;">
                    <h3 style="font-size: 14px; opacity: 0.9; margin-bottom: 10px;">Select Academic Year</h3>
                    <select id="yearSelector" onchange="changeYear()" style="width: 100%; max-width: 300px; padding: 10px 15px; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; background: white; color: #333; cursor: pointer;">
                        <?php foreach ($allYears as $year): ?>
                            <option value="<?php echo $year['YearID']; ?>" 
                                <?php if ($displayYear && $year['YearID'] == $displayYear['YearID']) echo 'selected'; ?>>
                                <?php 
                                    echo htmlspecialchars($year['YearName']);
                                    if ($activeYear && $year['YearID'] == $activeYear['YearID']) echo ' (Active)';
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 12px; opacity: 0.9;">Interest Rate</div>
                    <div style="font-size: 20px; font-weight: bold;"><?php echo htmlspecialchars($displayYear['InterestRate']); ?>%</div>
                    <?php if (isset($displayYear['InterestType'])): ?>
                        <div style="font-size: 11px; opacity: 0.8; margin-top: 2px;"><?php echo htmlspecialchars($displayYear['InterestType']); ?> Interest</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-hand-holding-usd"></i></div>
                <div class="stat-value">₹<?php echo number_format($totalPaid, 2); ?></div>
                <div class="stat-label">Total Paid This Year</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-value">₹<?php echo number_format($totalReceived, 2); ?></div>
                <div class="stat-label">Total Received This Year</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-percentage"></i></div>
                <div class="stat-value">₹<?php echo number_format($totalInterest, 2); ?></div>
                <div class="stat-label">Total Interest</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-value" style="color: <?php echo ($currentDue > 0) ? '#ff4757' : '#28a745'; ?>">
                    ₹<?php echo number_format(abs($currentDue), 2); ?>
                </div>
                <div class="stat-label"><?php echo ($currentDue > 0) ? 'Amount Due' : 'Balance Clear'; ?></div>
            </div>
        </div>
        
        <div class="payments-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h3 style="margin: 0;">📋 Recent Transactions</h3>
                <a href="my-payments.php" class="btn btn-primary" style="padding: 8px 16px; font-size: 13px;">
                    View Full History →
                </a>
            </div>
            
            <?php if (!empty($recentTransactions)): ?>
                <div style="overflow-x: auto;">
                    <table class="payments-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Month</th>
                                <th>Type</th>
                                <th>Collection</th>
                                <th>Distribution</th>
                                <th>Interest</th>
                                <th>Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $trans): 
                                // Determine badge style based on transaction type
                                $badgeStyle = '';
                                switch($trans['TransactionType']) {
                                    case 'Collection':
                                        $badgeStyle = 'background: #d4edda; color: #155724;';
                                        break;
                                    case 'Distribution':
                                        $badgeStyle = 'background: #fff3cd; color: #856404;';
                                        break;
                                    case 'Both':
                                        $badgeStyle = 'background: #d1ecf1; color: #0c5460;';
                                        break;
                                    default:
                                        $badgeStyle = 'background: #e2e3e5; color: #383d41;';
                                }
                            ?>
                                <tr>
                                    <td><?php 
                                    if (!empty($trans['TransactionDate']) && $trans['TransactionDate'] != '0000-00-00') {
                                        echo date('d M Y', strtotime($trans['TransactionDate']));
                                    } else {
                                        echo date('d M Y', strtotime($trans['CreatedAt']));
                                    }
                                    ?></td>
                                    <td><?php echo htmlspecialchars($trans['MonthName']); ?></td>
                                    <td>
                                        <span style="padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; <?php echo $badgeStyle; ?>">
                                            <?php echo htmlspecialchars($trans['TransactionType']); ?>
                                        </span>
                                    </td>
                                    <td style="color: #28a745; font-weight: 600;">
                                        <?php echo ($trans['CollectionAmount'] > 0) ? '₹' . number_format($trans['CollectionAmount'], 2) : '-'; ?>
                                    </td>
                                    <td style="color: #dc3545; font-weight: 600;">
                                        <?php echo ($trans['DistributedAmount'] > 0) ? '₹' . number_format($trans['DistributedAmount'], 2) : '-'; ?>
                                    </td>
                                    <td>
                                        <?php echo ($trans['InterestAmount'] > 0) ? '₹' . number_format($trans['InterestAmount'], 2) : '-'; ?>
                                    </td>
                                    <td>
                                        <span style="padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; background: #e0e0e0; color: #333;">
                                            <?php echo htmlspecialchars($trans['PaymentMode']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 20px; text-align: center;">
                    <a href="my-payments.php" class="btn btn-secondary">View All Transactions & Monthly Summaries</a>
                </div>
            <?php else: ?>
                <div class="no-payments">
                    <p style="font-size: 48px; margin-bottom: 15px;">📭</p>
                    <p style="font-size: 16px; color: #666; margin-bottom: 20px;">No transactions yet for the current academic year</p>
                    <?php if (!$activeYear): ?>
                        <p style="font-size: 14px; color: #999;">There is no active academic year. Please contact the administrator.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function changeYear() {
            const yearId = document.getElementById('yearSelector').value;
            const memberId = <?php echo $memberId; ?>;
            window.location.href = 'dashboard-new.php?year=' + yearId;
        }
    </script>
    <script src="<?php echo ASSETS_URL; ?>/js/script.js"></script>
</body>
</html>