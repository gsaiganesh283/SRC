<?php
// Redirect to new modern dashboard
// This file is kept for backward compatibility
require_once '../includes/session.php';

// Verify admin is logged in
if (!isset($_SESSION['admin_id'])) {
    header('Location: ../auth/admin-login.php');
    exit;
}

// Redirect to the new enhanced dashboard
header('Location: dashboard-new.php');
exit;

// Determine which year to display
$selectedYearID = null;
if (isset($_GET['year_id']) && !empty($_GET['year_id'])) {
    // User selected a specific year
    $selectedYearID = intval($_GET['year_id']);
} else {
    // Default to active year
    try {
        $stmt = $pdo->query("SELECT YearID FROM academic_years WHERE Status = 'Active' LIMIT 1");
        $activeYearData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($activeYearData) {
            $selectedYearID = $activeYearData['YearID'];
        } elseif (!empty($allYears)) {
            // If no active year, use the most recent year
            $selectedYearID = $allYears[0]['YearID'];
        }
    } catch (Exception $e) {
        if (!empty($allYears)) {
            $selectedYearID = $allYears[0]['YearID'];
        }
    }
}

// Get statistics
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM members");
    $totalMembers = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM members WHERE Status = 'Active'");
    $activeMembers = $stmt->fetchColumn();
    
    // Get selected academic year statistics
    $activeYear = null;
    if ($selectedYearID) {
        $stmt = $pdo->prepare("SELECT * FROM academic_years WHERE YearID = ?");
        $stmt->execute([$selectedYearID]);
        $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    $totalCollection = 0;
    $totalDistribution = 0;
    $totalTransactions = 0;
    $totalInterest = 0;
    $avgDistribution = 0;
    $membersWithOutstanding = 0;
    $totalOutstanding = 0;
    $thisMonthCollection = 0;
    $collectionRate = 0;
    $inactiveMembers = 0;
    
    if ($activeYear) {
        // Basic statistics
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CollectionAmount), 0) as TotalCollection,
                COALESCE(SUM(DistributedAmount), 0) as TotalDistribution,
                COALESCE(SUM(InterestAmount), 0) as TotalInterest,
                COUNT(*) as TotalTransactions
            FROM transactions 
            WHERE YearID = ?
        ");
        $stmt->execute([$activeYear['YearID']]);
        $yearStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $totalCollection = $yearStats['TotalCollection'];
        $totalDistribution = $yearStats['TotalDistribution'];
        $totalInterest = $yearStats['TotalInterest'];
        $totalTransactions = $yearStats['TotalTransactions'];
        
        // Average distribution per active member
        if ($activeMembers > 0) {
            $avgDistribution = $totalDistribution / $activeMembers;
        }
        
        // Members with outstanding balance (using unified net balance system)
        // Check member_net_balance table first, fallback to transaction calculations
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT COALESCE(mnb.MemberID, t.MemberID)) as MembersWithOutstanding,
                COALESCE(SUM(mnb.NetBalance), 0) as TotalOutstanding
            FROM (
                SELECT MemberID, NetBalance FROM member_net_balance WHERE YearID = ? AND NetBalance > 0
                UNION
                SELECT MemberID, (SUM(DistributedAmount + InterestAmount - CollectionAmount)) as NetBalance
                FROM transactions 
                WHERE YearID = ? AND MemberID NOT IN (SELECT MemberID FROM member_net_balance WHERE YearID = ?)
                GROUP BY MemberID
                HAVING NetBalance > 0
            ) t
            LEFT JOIN member_net_balance mnb ON t.MemberID = mnb.MemberID AND mnb.YearID = ?
        ");
        $stmt->execute([$activeYear['YearID'], $activeYear['YearID'], $activeYear['YearID'], $activeYear['YearID']]);
        $outstandingStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($outstandingStats) {
            $membersWithOutstanding = $outstandingStats['MembersWithOutstanding'];
        }
        
        // Total outstanding across all members (using unified net balance system)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(NetBalance), 0) as TotalOutstanding
            FROM (
                SELECT MemberID, NetBalance FROM member_net_balance WHERE YearID = ?
                UNION ALL
                SELECT MemberID, (SUM(DistributedAmount + InterestAmount - CollectionAmount)) as NetBalance
                FROM transactions 
                WHERE YearID = ? AND MemberID NOT IN (SELECT MemberID FROM member_net_balance WHERE YearID = ?)
                GROUP BY MemberID
            ) combined_balances
        ");
        $stmt->execute([$activeYear['YearID'], $activeYear['YearID'], $activeYear['YearID']]);
        $totalOutstanding = $stmt->fetchColumn();
        
        // This month's collections
        $currentMonth = date('Y-m');
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(CollectionAmount), 0) as ThisMonthCollection
            FROM transactions 
            WHERE YearID = ? 
            AND DATE_FORMAT(TransactionDate, '%Y-%m') = ?
        ");
        $stmt->execute([$activeYear['YearID'], $currentMonth]);
        $thisMonthCollection = $stmt->fetchColumn();
        
        // Collection rate (percentage of distributed amount that has been collected)
        if ($totalDistribution + $totalInterest > 0) {
            $collectionRate = ($totalCollection / ($totalDistribution + $totalInterest)) * 100;
        }
    }
    
    // Inactive members count
    $stmt = $pdo->query("SELECT COUNT(*) FROM members WHERE Status = 'Inactive'");
    $inactiveMembers = $stmt->fetchColumn();
    
} catch (Exception $e) {
    // If tables don't exist yet, set defaults
    $totalMembers = 0;
    $activeMembers = 0;
    $activeYear = null;
    $totalCollection = 0;
    $totalDistribution = 0;
    $totalTransactions = 0;
    $totalInterest = 0;
    $avgDistribution = 0;
    $membersWithOutstanding = 0;
    $totalOutstanding = 0;
    $thisMonthCollection = 0;
    $collectionRate = 0;
    $inactiveMembers = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - SR Colony</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/admin-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .year-selector-form select {
            width: 100%;
            padding: 10px 15px;
            border-radius: 8px;
            border: 2px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.2);
            color: white;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }
        
        .year-selector-form select:hover {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.5);
        }
        
        .year-selector-form select option {
            background: #667eea;
            color: white;
            padding: 10px;
        }
        
        .reset-year-btn {
            display: inline-block;
            margin-top: 8px;
            padding: 6px 15px;
            background: rgba(255,255,255,0.2);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 12px;
            border: 1px solid rgba(255,255,255,0.3);
            transition: all 0.3s ease;
        }
        
        .reset-year-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        @media (max-width: 768px) {
            .year-banner-content {
                flex-direction: column !important;
                text-align: center !important;
            }
            
            .year-selector-wrapper {
                min-width: 100% !important;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-tachometer-alt"></i> Admin Dashboard</h1>
                <p>Welcome, <?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?>!</p>
            </div>
            <div class="header-actions">
                <a href="view-profile.php" class="btn btn-secondary">
                    <i class="fas fa-user-circle"></i> My Profile
                </a>
                <a href="settings.php" class="btn btn-secondary">
                    <i class="fas fa-cog"></i> Settings
                </a>
                <a href="../auth/logout.php" class="btn btn-danger">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- Active Year Banner -->
        <?php if ($activeYear): ?>
        <div style="background: linear-gradient(135deg, #667eea, #764ba2); padding: 20px 30px; border-radius: 15px; margin-bottom: 25px; color: white; box-shadow: 0 8px 30px rgba(0,0,0,0.2);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div style="flex: 1;">
                    <h3 style="font-size: 16px; opacity: 0.9; margin-bottom: 5px;">
                        <?php echo $activeYear['Status'] === 'Active' ? 'Active Academic Year' : 'Academic Year'; ?>
                    </h3>
                    <h2 style="font-size: 28px; margin: 0;"><?php echo htmlspecialchars($activeYear['YearName']); ?></h2>
                    <?php if ($activeYear['Status'] !== 'Active'): ?>
                    <small style="opacity: 0.8; font-size: 12px;">⚠️ This year is not currently active</small>
                    <?php endif; ?>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 14px; opacity: 0.9;">Interest Rate</div>
                    <div style="font-size: 24px; font-weight: bold;"><?php echo htmlspecialchars($activeYear['InterestRate']); ?>%</div>
                </div>
                <?php if (count($allYears) > 1): ?>
                <div class="year-selector-wrapper" style="min-width: 250px;">
                    <form method="GET" action="dashboard.php" class="year-selector-form" style="margin: 0;">
                        <label style="font-size: 13px; opacity: 0.9; display: block; margin-bottom: 5px;">
                            <i class="fas fa-calendar-alt"></i> Select Year to View:
                        </label>
                        <select name="year_id" onchange="this.form.submit()">
                            <?php foreach ($allYears as $year): ?>
                            <option value="<?php echo $year['YearID']; ?>" 
                                    <?php echo ($year['YearID'] == $selectedYearID) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['YearName']); ?>
                                <?php echo $year['Status'] === 'Active' ? ' ⭐ (Active)' : ''; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php 
                    // Check if viewing non-active year
                    $isViewingNonActive = false;
                    if (isset($_GET['year_id'])) {
                        foreach ($allYears as $year) {
                            if ($year['YearID'] == $selectedYearID && $year['Status'] !== 'Active') {
                                $isViewingNonActive = true;
                                break;
                            }
                        }
                    }
                    if ($isViewingNonActive):
                    ?>
                    <a href="dashboard.php" class="reset-year-btn">
                        <i class="fas fa-undo"></i> Reset to Active Year
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <!-- No Academic Year Available -->
        <div style="background: linear-gradient(135deg, #e74c3c, #c0392b); padding: 25px 30px; border-radius: 15px; margin-bottom: 25px; color: white; box-shadow: 0 8px 30px rgba(0,0,0,0.2); text-align: center;">
            <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px; opacity: 0.9;"></i>
            <h2 style="font-size: 24px; margin: 10px 0;">No Academic Year Available</h2>
            <p style="opacity: 0.9; margin-bottom: 20px;">Please create an academic year to view statistics.</p>
            <a href="manage-years.php" class="btn" style="background: white; color: #e74c3c; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">
                <i class="fas fa-plus-circle"></i> Create Academic Year
            </a>
        </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <!-- Row 1: Basic Stats -->
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $totalMembers; ?></h3>
                    <p>Total Members</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon active">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $activeMembers; ?></h3>
                    <p>Active Members</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #6c757d, #5a6268);">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $inactiveMembers; ?></h3>
                    <p>Inactive Members</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon inactive">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $totalTransactions; ?></h3>
                    <p>Total Transactions</p>
                </div>
            </div>

            <!-- Row 2: Financial Stats -->
            <div class="stat-card">
                <div class="stat-icon total" style="background: linear-gradient(135deg, #28a745, #20c997);">
                    <i class="fas fa-arrow-down"></i>
                </div>
                <div class="stat-info">
                    <h3>₹<?php echo number_format($totalCollection, 0); ?></h3>
                    <p>Total Collection</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon active" style="background: linear-gradient(135deg, #ffc107, #ff9800);">
                    <i class="fas fa-arrow-up"></i>
                </div>
                <div class="stat-info">
                    <h3>₹<?php echo number_format($totalDistribution, 0); ?></h3>
                    <p>Total Distribution</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-info">
                    <h3>₹<?php echo number_format($totalInterest, 0); ?></h3>
                    <p>Total Interest</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon inactive" style="background: linear-gradient(135deg, #17a2b8, #0d6efd);">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="stat-info">
                    <h3>₹<?php echo number_format($totalCollection - $totalDistribution, 0); ?></h3>
                    <p>Current Balance</p>
                </div>
            </div>

            <!-- Row 3: Advanced Stats -->
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #9b59b6, #8e44ad);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-info">
                    <h3>₹<?php echo number_format($totalOutstanding, 0); ?></h3>
                    <p>Total Outstanding</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #e67e22, #d35400);">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $membersWithOutstanding; ?></h3>
                    <p>Members w/ Outstanding</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #16a085, #1abc9c);">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-info">
                    <h3>₹<?php echo number_format($avgDistribution, 0); ?></h3>
                    <p>Avg Distribution/Member</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #3498db, #2980b9);">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-info">
                    <h3>₹<?php echo number_format($thisMonthCollection, 0); ?></h3>
                    <p>This Month's Collection</p>
                </div>
            </div>

            <!-- Row 4: Performance Metrics -->
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #27ae60, #229954);">
                    <i class="fas fa-percent"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($collectionRate, 1); ?>%</h3>
                    <p>Collection Rate</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f39c12, #e67e22);">
                    <i class="fas fa-coins"></i>
                </div>
                <div class="stat-info">
                    <h3>₹<?php echo $activeMembers > 0 ? number_format($totalCollection / $activeMembers, 0) : 0; ?></h3>
                    <p>Avg Collection/Member</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #8e44ad, #9b59b6);">
                    <i class="fas fa-hand-holding-usd"></i>
                </div>
                <div class="stat-info">
                    <h3>₹<?php echo $totalTransactions > 0 ? number_format($totalCollection / $totalTransactions, 0) : 0; ?></h3>
                    <p>Avg per Transaction</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #c0392b, #e74c3c);">
                    <i class="fas fa-balance-scale"></i>
                </div>
                <div class="stat-info">
                    <h3>₹<?php echo number_format(($totalDistribution + $totalInterest) - $totalCollection, 0); ?></h3>
                    <p>Pending Collections</p>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="members-section">
            <div style="padding: 30px;">
                <h2 style="margin-bottom: 20px; color: #333;">
                    <i class="fas fa-bolt"></i> Quick Actions
                </h2>
                <div class="stats-grid">
                    <a href="manage-members.php" class="stat-card" style="text-decoration: none; cursor: pointer;">
                        <div class="stat-icon total">
                            <i class="fas fa-users-cog"></i>
                        </div>
                        <div class="stat-info">
                            <h3 style="font-size: 18px;">Manage Members</h3>
                            <p>View, edit, and manage all members</p>
                        </div>
                    </a>
                    <a href="add-member.php" class="stat-card" style="text-decoration: none; cursor: pointer;">
                        <div class="stat-icon active">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="stat-info">
                            <h3 style="font-size: 18px;">Add New Member</h3>
                            <p>Register a new colony member</p>
                        </div>
                    </a>
                    <a href="manage-years.php" class="stat-card" style="text-decoration: none; cursor: pointer;">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-info">
                            <h3 style="font-size: 18px;">Academic Years</h3>
                            <p>Manage years & interest rates</p>
                        </div>
                    </a>
                    <a href="add-transaction.php" class="stat-card" style="text-decoration: none; cursor: pointer;">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #28a745, #20c997);">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <div class="stat-info">
                            <h3 style="font-size: 18px;">Add Transaction</h3>
                            <p>Record payment or distribution</p>
                        </div>
                    </a>
                    <a href="manage-transactions.php" class="stat-card" style="text-decoration: none; cursor: pointer;">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #ffc107, #ff9800);">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <div class="stat-info">
                            <h3 style="font-size: 18px;">View Transactions</h3>
                            <p>View & filter all transactions</p>
                        </div>
                    </a>
                    <a href="view-profile.php" class="stat-card" style="text-decoration: none; cursor: pointer;">
                        <div class="stat-icon inactive">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="stat-info">
                            <h3 style="font-size: 18px;">My Profile</h3>
                            <p>View and update your profile</p>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
<?php include '../includes/modal-component.php'; ?>
</html>
