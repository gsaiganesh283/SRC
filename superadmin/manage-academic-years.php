<?php
// Academic Year Management - Super Admin
// URL: superadmin/manage-academic-years.php

require_once 'session.php';
require_once '../config/config.php';

$superAdminName = $_SESSION['superadmin_name'] ?? 'Super Admin';

// Handle form submissions
$message = '';
$messageType = '';

// Add new academic year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $yearName = trim($_POST['year_name']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $interestRate = floatval($_POST['interest_rate']);
    $fixedAmount = floatval($_POST['fixed_amount'] ?? 0);
    $status = isset($_POST['is_active']) ? 'Active' : 'Completed';
    $selectedMembers = $_POST['selected_members'] ?? [];
    
    try {
        // If setting as active, deactivate others
        if ($status === 'Active') {
            $pdo->exec("UPDATE academic_years SET Status = 'Completed'");
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO academic_years (YearName, StartDate, EndDate, InterestRate, FixedAmount, Status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([$yearName, $startDate, $endDate, $interestRate, $fixedAmount, $status]);
        $yearId = $pdo->lastInsertId();
        
        // Save year-member associations
        if (!empty($selectedMembers)) {
            $insertStmt = $pdo->prepare("
                INSERT INTO year_members (YearID, MemberID, JoinedDate, Status)
                VALUES (?, ?, ?, 'Active')
            ");
            foreach ($selectedMembers as $memberId) {
                $insertStmt->execute([$yearId, intval($memberId), $startDate]);
            }
        }
        
        $message = "Academic year '$yearName' added successfully with " . count($selectedMembers) . " members!";
        $messageType = 'success';
    } catch (PDOException $e) {
        $message = "Error adding academic year: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Edit academic year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $yearId = intval($_POST['year_id']);
    $yearName = trim($_POST['year_name']);
    $startDate = $_POST['start_date'];
    $endDate = $_POST['end_date'];
    $interestRate = floatval($_POST['interest_rate']);
    $fixedAmount = floatval($_POST['fixed_amount'] ?? 0);
    $changeAmount = floatval($_POST['change_amount'] ?? 0);
    $status = isset($_POST['is_active']) ? 'Active' : 'Completed';
    $selectedMembers = $_POST['selected_members'] ?? [];
    
    try {
        // If setting as active, deactivate others
        if ($status === 'Active') {
            $pdo->exec("UPDATE academic_years SET Status = 'Completed'");
        }
        
        // The Change Amount is the total of the year's change amount entries:
        // a different value typed here is recorded as a general adjustment entry
        require_once '../includes/change-amount-helper.php';
        caEnsureTable($pdo);
        $changeDiff = round($changeAmount - caTotals($pdo, $yearId)['total'], 2);
        if (abs($changeDiff) >= 0.01) {
            $pdo->prepare("INSERT INTO change_amount_transactions (YearID, MemberID, TransactionDate, Amount, Description, Remarks, CreatedBy) VALUES (?, NULL, ?, ?, 'Adjustment from Academic Year edit', ?, ?)")
                ->execute([$yearId, date('Y-m-d'), $changeDiff, 'Change Amount set to ' . number_format($changeAmount, 2, '.', '') . ' on the Academic Years page', $_SESSION['superadmin_id'] ?? null]);
        }

        $stmt = $pdo->prepare("
            UPDATE academic_years
            SET YearName = ?, StartDate = ?, EndDate = ?, InterestRate = ?, FixedAmount = ?, Status = ?
            WHERE YearID = ?
        ");

        $stmt->execute([$yearName, $startDate, $endDate, $interestRate, $fixedAmount, $status, $yearId]);
        caSyncYear($pdo, $yearId);
        
        // Update year-member associations: delete existing and insert new
        $pdo->prepare("DELETE FROM year_members WHERE YearID = ?")->execute([$yearId]);
        
        if (!empty($selectedMembers)) {
            $insertStmt = $pdo->prepare("
                INSERT INTO year_members (YearID, MemberID, JoinedDate, Status)
                VALUES (?, ?, ?, 'Active')
            ");
            foreach ($selectedMembers as $memberId) {
                $insertStmt->execute([$yearId, intval($memberId), $startDate]);
            }
        }
        
        $message = "Academic year updated successfully with " . count($selectedMembers) . " members!";
        $messageType = 'success';
    } catch (PDOException $e) {
        $message = "Error updating academic year: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Delete academic year
if (isset($_GET['delete'])) {
    $yearId = intval($_GET['delete']);
    
    try {
        // Check if year has transactions
        $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM transactions WHERE YearID = ?");
        $checkStmt->execute([$yearId]);
        $transCount = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($transCount > 0) {
            $message = "Cannot delete: This academic year has $transCount associated transactions.";
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("DELETE FROM academic_years WHERE YearID = ?");
            $stmt->execute([$yearId]);
            $message = "Academic year deleted successfully!";
            $messageType = 'success';
        }
    } catch (PDOException $e) {
        $message = "Error deleting academic year: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Set active year
if (isset($_GET['set_active'])) {
    $yearId = intval($_GET['set_active']);
    
    try {
        $pdo->exec("UPDATE academic_years SET Status = 'Completed'");
        $stmt = $pdo->prepare("UPDATE academic_years SET Status = 'Active' WHERE YearID = ?");
        $stmt->execute([$yearId]);
        $message = "Active academic year updated successfully!";
        $messageType = 'success';
    } catch (PDOException $e) {
        $message = "Error setting active year: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch all members for multi-select dropdown
$stmt = $pdo->query("SELECT MemberID, MemberName, Status FROM members ORDER BY MemberName ASC");
$allMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all academic years
$stmt = $pdo->query("
    SELECT 
        y.*,
        COUNT(t.TransactionID) as transaction_count,
        COALESCE(SUM(t.CollectionAmount), 0) as total_collection,
        COALESCE(SUM(t.DistributedAmount), 0) as total_distribution
    FROM academic_years y
    LEFT JOIN transactions t ON y.YearID = t.YearID
    GROUP BY y.YearID
    ORDER BY y.StartDate DESC
");
$years = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch year-member associations for each year
$yearMembers = [];
foreach ($years as $year) {
    $stmt = $pdo->prepare("SELECT MemberID FROM year_members WHERE YearID = ? AND Status = 'Active'");
    $stmt->execute([$year['YearID']]);
    $yearMembers[$year['YearID']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Get statistics
$stats = [
    'total_years' => count($years),
    'active_year' => null,
    'total_transactions' => 0
];

foreach ($years as $year) {
    if (isset($year['IsActive']) && $year['IsActive']) {
        $stats['active_year'] = $year['YearName'];
    }
    $stats['total_transactions'] += $year['transaction_count'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Academic Years - SR Colony</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .page-header {
            background: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .page-title {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-left: 5px solid;
        }
        
        .stat-card.purple { border-left-color: #764ba2; }
        .stat-card.blue { border-left-color: #4facfe; }
        .stat-card.green { border-left-color: #43e97b; }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: #111827;
        }
        
        .content-card {
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            color: #065f46;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .btn-small {
            padding: 8px 16px;
            font-size: 13px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .years-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .years-table th {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            padding: 15px;
            text-align: left;
            font-weight: 700;
            color: #111827;
            border-bottom: 2px solid #d1d5db;
        }
        
        .years-table td {
            padding: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .years-table tr:hover {
            background: #f9fafb;
        }
        
        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            display: inline-block;
        }
        
        .badge-active {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        
        .badge-inactive {
            background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
            color: #6b7280;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 20px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s;
            margin-bottom: 20px;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
        </a>
        
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-calendar-alt"></i>
                Academic Year Management
            </h1>
            <p style="color: #6b7280; margin-top: 5px;">Manage academic years, set active periods, and track financial data</p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card purple">
                <div class="stat-label">Total Academic Years</div>
                <div class="stat-value"><?php echo $stats['total_years']; ?></div>
            </div>
            
            <div class="stat-card blue">
                <div class="stat-label">Active Year</div>
                <div class="stat-value" style="font-size: 20px;"><?php echo $stats['active_year'] ?? 'None'; ?></div>
            </div>
            
            <div class="stat-card green">
                <div class="stat-label">Total Transactions</div>
                <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
            </div>
        </div>
        
        <!-- Academic Years List -->
        <div class="content-card">
            <div class="section-header">
                <h2 class="section-title">Academic Years</h2>
                <button class="btn btn-primary" onclick="openAddModal()">
                    <i class="fas fa-plus"></i>
                    Add New Year
                </button>
            </div>
            
            <table class="years-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Year Name</th>
                        <th>Period</th>
                        <th>Interest Rate</th>
                        <th>Transactions</th>
                        <th>Collection</th>
                        <th>Distribution</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($years as $year): ?>
                        <tr>
                            <td><strong><?php echo $year['YearID']; ?></strong></td>
                            <td><strong><?php echo htmlspecialchars($year['YearName']); ?></strong></td>
                            <td><?php echo date('M d, Y', strtotime($year['StartDate'])); ?> - <?php echo date('M d, Y', strtotime($year['EndDate'])); ?></td>
                            <td><?php echo number_format($year['InterestRate'], 2); ?>%</td>
                            <td><?php echo number_format($year['transaction_count']); ?></td>
                            <td>₹<?php echo number_format($year['total_collection'], 2); ?></td>
                            <td>₹<?php echo number_format($year['total_distribution'], 2); ?></td>
                            <td>
                                <?php if (isset($year['Status']) && $year['Status'] === 'Active'): ?>
                                    <span class="badge badge-active"><i class="fas fa-check-circle"></i> Active</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive"><i class="fas fa-circle"></i> Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if (!isset($year['Status']) || $year['Status'] !== 'Active'): ?>
                                        <a href="?set_active=<?php echo $year['YearID']; ?>" class="btn btn-success btn-small" onclick="return confirm('Set this as the active academic year?')">
                                            <i class="fas fa-check"></i> Set Active
                                        </a>
                                    <?php endif; ?>
                                    <button class="btn btn-primary btn-small" onclick='openEditModal(<?php echo json_encode($year); ?>)'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <?php if ($year['transaction_count'] == 0): ?>
                                        <a href="?delete=<?php echo $year['YearID']; ?>" class="btn btn-danger btn-small" onclick="return confirm('Are you sure you want to delete this academic year?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($years)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px; color: #6b7280;">
                                <i class="fas fa-calendar-times" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                                <p>No academic years found. Click "Add New Year" to create one.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Add Year Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Add New Academic Year</h3>
                <button class="close-modal" onclick="closeAddModal()">&times;</button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label>Year Name *</label>
                    <input type="text" name="year_name" placeholder="e.g., 2024-2025" required>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Start Date *</label>
                        <input type="date" name="start_date" required>
                    </div>
                    
                    <div class="form-group">
                        <label>End Date *</label>
                        <input type="date" name="end_date" required>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Interest Rate (%) *</label>
                        <input type="number" name="interest_rate" step="0.01" min="0" placeholder="5.0" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Fixed Amount (₹)</label>
                        <input type="number" name="fixed_amount" step="0.01" min="0" placeholder="0.00" value="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_active" id="add_is_active">
                        <label for="add_is_active" style="margin: 0;">Set as Active Year</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Select Members for this Academic Year</label>
                    <div style="border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; max-height: 300px; overflow-y: auto; background: #f9fafb;">
                        <div style="margin-bottom: 10px; padding: 8px; background: white; border-radius: 6px; position: sticky; top: 0; z-index: 10;">
                            <input type="text" id="addMemberSearch" placeholder="Search members..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        </div>
                        <div style="display: flex; gap: 10px; margin-bottom: 10px; position: sticky; top: 50px; background: #f9fafb; padding: 5px 0; z-index: 9;">
                            <button type="button" onclick="selectAllAddMembers()" class="btn btn-success btn-small">
                                <i class="fas fa-check-double"></i> Select All
                            </button>
                            <button type="button" onclick="deselectAllAddMembers()" class="btn btn-danger btn-small">
                                <i class="fas fa-times"></i> Deselect All
                            </button>
                            <span id="addSelectedCount" style="padding: 6px 12px; background: #667eea; color: white; border-radius: 6px; font-size: 12px; font-weight: 600;">0 selected</span>
                        </div>
                        <div id="addMemberCheckboxes">
                            <?php foreach ($allMembers as $member): ?>
                            <label style="display: flex; align-items: center; padding: 8px; margin-bottom: 5px; background: white; border-radius: 6px; cursor: pointer; transition: background 0.2s;" 
                                   onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='white'" 
                                   class="add-member-checkbox-label" data-member-name="<?php echo strtolower(htmlspecialchars($member['MemberName'])); ?>">
                                <input type="checkbox" name="selected_members[]" value="<?php echo $member['MemberID']; ?>" 
                                       style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;" 
                                       onchange="updateAddSelectedCount()">
                                <span style="flex: 1; font-weight: 500;"><?php echo htmlspecialchars($member['MemberName']); ?></span>
                                <span style="font-size: 12px; color: #6b7280; padding: 2px 8px; background: #<?php echo $member['Status'] === 'Active' ? 'd1fae5' : 'fee2e2'; ?>; border-radius: 4px;">
                                    <?php echo $member['Status']; ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" style="background: #e5e7eb; color: #374151;" onclick="closeAddModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        Add Academic Year
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Year Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Academic Year</h3>
                <button class="close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="year_id" id="edit_year_id">
                
                <div class="form-group">
                    <label>Year Name *</label>
                    <input type="text" name="year_name" id="edit_year_name" required>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Start Date *</label>
                        <input type="date" name="start_date" id="edit_start_date" required>
                    </div>
                    
                    <div class="form-group">
                        <label>End Date *</label>
                        <input type="date" name="end_date" id="edit_end_date" required>
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Interest Rate (%) *</label>
                        <input type="number" name="interest_rate" id="edit_interest_rate" step="0.01" min="0" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Fixed Amount (₹)</label>
                        <input type="number" name="fixed_amount" id="edit_fixed_amount" step="0.01" min="0" value="0">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Change Amount (₹)</label>
                    <input type="number" name="change_amount" id="edit_change_amount" step="0.01" value="0">
                    <small style="color: #666; font-size: 12px;">Total of this year's <a href="change-amount.php" style="color:#8e44ad;">Change Amount transactions</a>. Typing a different total adds an adjustment entry for the difference.</small>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <div class="checkbox-group">
                        <input type="checkbox" name="is_active" id="edit_is_active">
                        <label for="edit_is_active" style="margin: 0;">Set as Active Year</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Select Members for this Academic Year</label>
                    <div style="border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; max-height: 300px; overflow-y: auto; background: #f9fafb;">
                        <div style="margin-bottom: 10px; padding: 8px; background: white; border-radius: 6px; position: sticky; top: 0; z-index: 10;">
                            <input type="text" id="editMemberSearch" placeholder="Search members..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        </div>
                        <div style="display: flex; gap: 10px; margin-bottom: 10px; position: sticky; top: 50px; background: #f9fafb; padding: 5px 0; z-index: 9;">
                            <button type="button" onclick="selectAllEditMembers()" class="btn btn-success btn-small">
                                <i class="fas fa-check-double"></i> Select All
                            </button>
                            <button type="button" onclick="deselectAllEditMembers()" class="btn btn-danger btn-small">
                                <i class="fas fa-times"></i> Deselect All
                            </button>
                            <span id="editSelectedCount" style="padding: 6px 12px; background: #667eea; color: white; border-radius: 6px; font-size: 12px; font-weight: 600;">0 selected</span>
                        </div>
                        <div id="editMemberCheckboxes">
                            <?php foreach ($allMembers as $member): ?>
                            <label style="display: flex; align-items: center; padding: 8px; margin-bottom: 5px; background: white; border-radius: 6px; cursor: pointer; transition: background 0.2s;" 
                                   onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='white'" 
                                   class="edit-member-checkbox-label" data-member-name="<?php echo strtolower(htmlspecialchars($member['MemberName'])); ?>">
                                <input type="checkbox" name="selected_members[]" value="<?php echo $member['MemberID']; ?>" 
                                       style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;" 
                                       onchange="updateEditSelectedCount()">
                                <span style="flex: 1; font-weight: 500;"><?php echo htmlspecialchars($member['MemberName']); ?></span>
                                <span style="font-size: 12px; color: #6b7280; padding: 2px 8px; background: #<?php echo $member['Status'] === 'Active' ? 'd1fae5' : 'fee2e2'; ?>; border-radius: 4px;">
                                    <?php echo $member['Status']; ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn" style="background: #e5e7eb; color: #374151;" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Update Academic Year
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        const yearMembersData = <?php echo json_encode($yearMembers); ?>;
        
        function openAddModal() {
            document.getElementById('addModal').classList.add('active');
            // Reset all checkboxes
            const checkboxes = document.querySelectorAll('#addMemberCheckboxes input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = false);
            updateAddSelectedCount();
        }
        
        function closeAddModal() {
            document.getElementById('addModal').classList.remove('active');
        }
        
        function openEditModal(year) {
            document.getElementById('edit_year_id').value = year.YearID;
            document.getElementById('edit_year_name').value = year.YearName;
            document.getElementById('edit_start_date').value = year.StartDate;
            document.getElementById('edit_end_date').value = year.EndDate;
            document.getElementById('edit_interest_rate').value = year.InterestRate;
            document.getElementById('edit_fixed_amount').value = year.FixedAmount || 0;
            document.getElementById('edit_change_amount').value = year.ChangeAmount || 0;
            document.getElementById('edit_is_active').checked = year.Status === 'Active';
            
            // Pre-select members for this year
            const checkboxes = document.querySelectorAll('#editMemberCheckboxes input[name="selected_members[]"]');
            const selectedMembers = yearMembersData[year.YearID] || [];
            checkboxes.forEach(cb => {
                cb.checked = selectedMembers.includes(parseInt(cb.value));
            });
            
            updateEditSelectedCount();
            document.getElementById('editModal').classList.add('active');
        }
        
        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }
        
        // Add modal member selection functions
        function selectAllAddMembers() {
            const visibleLabels = Array.from(document.querySelectorAll('.add-member-checkbox-label'))
                .filter(label => label.style.display !== 'none');
            
            visibleLabels.forEach(label => {
                const checkbox = label.querySelector('input[type="checkbox"]');
                if (checkbox) checkbox.checked = true;
            });
            updateAddSelectedCount();
        }
        
        function deselectAllAddMembers() {
            const checkboxes = document.querySelectorAll('#addMemberCheckboxes input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = false);
            updateAddSelectedCount();
        }
        
        function updateAddSelectedCount() {
            const checkboxes = document.querySelectorAll('#addMemberCheckboxes input[name="selected_members[]"]:checked');
            document.getElementById('addSelectedCount').textContent = checkboxes.length + ' selected';
        }
        
        // Edit modal member selection functions
        function selectAllEditMembers() {
            const visibleLabels = Array.from(document.querySelectorAll('.edit-member-checkbox-label'))
                .filter(label => label.style.display !== 'none');
            
            visibleLabels.forEach(label => {
                const checkbox = label.querySelector('input[type="checkbox"]');
                if (checkbox) checkbox.checked = true;
            });
            updateEditSelectedCount();
        }
        
        function deselectAllEditMembers() {
            const checkboxes = document.querySelectorAll('#editMemberCheckboxes input[type="checkbox"]');
            checkboxes.forEach(cb => cb.checked = false);
            updateEditSelectedCount();
        }
        
        function updateEditSelectedCount() {
            const checkboxes = document.querySelectorAll('#editMemberCheckboxes input[name="selected_members[]"]:checked');
            document.getElementById('editSelectedCount').textContent = checkboxes.length + ' selected';
        }
        
        // Member search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const addSearchInput = document.getElementById('addMemberSearch');
            if (addSearchInput) {
                addSearchInput.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.toLowerCase();
                    const labels = document.querySelectorAll('.add-member-checkbox-label');
                    
                    labels.forEach(label => {
                        const memberName = label.getAttribute('data-member-name');
                        if (memberName.includes(searchTerm)) {
                            label.style.display = 'flex';
                        } else {
                            label.style.display = 'none';
                        }
                    });
                });
            }
            
            const editSearchInput = document.getElementById('editMemberSearch');
            if (editSearchInput) {
                editSearchInput.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.toLowerCase();
                    const labels = document.querySelectorAll('.edit-member-checkbox-label');
                    
                    labels.forEach(label => {
                        const memberName = label.getAttribute('data-member-name');
                        if (memberName.includes(searchTerm)) {
                            label.style.display = 'flex';
                        } else {
                            label.style.display = 'none';
                        }
                    });
                });
            }
        });
        
        // Close modals on outside click
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
    
    <?php include '../includes/modal-component.php'; ?>
<?php include '../includes/gemini-widget.php'; ?>
</body>
</html>
