<?php
// Super Admin - Academic Year Management
require_once 'session.php';
require_once '../config/config.php';

$success = $error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create') {
            $yearName = trim($_POST['year_name']);
            $startDate = $_POST['start_date'];
            $endDate = $_POST['end_date'];
            $interestRate = floatval($_POST['interest_rate']);
            $interestType = trim($_POST['interest_type'] ?? 'Monthly');
            $includeInterestInTotal = trim($_POST['include_interest_in_total'] ?? 'Yes');
            $collectionModel = trim($_POST['collection_model'] ?? 'FullAmount');
            $defaultMonth = trim($_POST['default_month'] ?? '');
            $defaultTransactionDate = trim($_POST['default_transaction_date'] ?? '');
            $lastMonthToCollect = trim($_POST['last_month_to_collect'] ?? '');
            $deferredPrincipalAmount = floatval($_POST['deferred_principal_amount'] ?? 0);
            $standingMonthlyInterest = floatval($_POST['standing_monthly_interest'] ?? 100);
            $totalCollection = floatval($_POST['total_collection'] ?? 0);
            $totalExpenses = floatval($_POST['total_expenses'] ?? 0);
            $balanceAmount = floatval($_POST['balance_amount'] ?? 0);
            $status = $_POST['status'];
            $selectedMembers = $_POST['selected_members'] ?? [];
            
            // If setting as active, deactivate others
            if ($status === 'Active') {
                $pdo->query("UPDATE academic_years SET Status = 'Inactive'");
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO academic_years (YearName, StartDate, EndDate, InterestRate, InterestType, IncludeInterestInTotal, CollectionModel, DefaultMonth, DefaultTransactionDate, LastMonthToCollect, DeferredPrincipalAmount, StandingMonthlyInterest, TotalCollection, TotalExpenses, BalanceAmount, Status, CreatedBy)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$yearName, $startDate, $endDate, $interestRate, $interestType, $includeInterestInTotal, $collectionModel, $defaultMonth, $defaultTransactionDate, $lastMonthToCollect, $deferredPrincipalAmount, $standingMonthlyInterest, $totalCollection, $totalExpenses, $balanceAmount, $status, $_SESSION['superadmin_id']]);
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
            
            $success = "Academic year created successfully with " . count($selectedMembers) . " members!";
            
        } elseif ($action === 'edit') {
            $yearId = intval($_POST['year_id']);
            $yearName = trim($_POST['year_name']);
            $startDate = $_POST['start_date'];
            $endDate = $_POST['end_date'];
            $interestRate = floatval($_POST['interest_rate']);
            $interestType = trim($_POST['interest_type'] ?? 'Monthly');
            $includeInterestInTotal = trim($_POST['include_interest_in_total'] ?? 'Yes');
            $collectionModel = trim($_POST['collection_model'] ?? 'FullAmount');
            $defaultMonth = trim($_POST['default_month'] ?? '');
            $defaultTransactionDate = trim($_POST['default_transaction_date'] ?? '');
            $lastMonthToCollect = trim($_POST['last_month_to_collect'] ?? '');
            $deferredPrincipalAmount = floatval($_POST['deferred_principal_amount'] ?? 0);
            $standingMonthlyInterest = floatval($_POST['standing_monthly_interest'] ?? 100);
            $totalCollection = floatval($_POST['total_collection'] ?? 0);
            $totalExpenses = floatval($_POST['total_expenses'] ?? 0);
            $balanceAmount = floatval($_POST['balance_amount'] ?? 0);
            $status = $_POST['status'];
            $selectedMembers = $_POST['selected_members'] ?? [];
            
            if ($status === 'Active') {
                $pdo->query("UPDATE academic_years SET Status = 'Inactive'");
            }
            
            $stmt = $pdo->prepare("
                UPDATE academic_years 
                SET YearName = ?, StartDate = ?, EndDate = ?, InterestRate = ?, InterestType = ?, IncludeInterestInTotal = ?, CollectionModel = ?, DefaultMonth = ?, DefaultTransactionDate = ?, LastMonthToCollect = ?, DeferredPrincipalAmount = ?, StandingMonthlyInterest = ?, TotalCollection = ?, TotalExpenses = ?, BalanceAmount = ?, Status = ?
                WHERE YearID = ?
            ");
            $stmt->execute([$yearName, $startDate, $endDate, $interestRate, $interestType, $includeInterestInTotal, $collectionModel, $defaultMonth, $defaultTransactionDate, $lastMonthToCollect, $deferredPrincipalAmount, $standingMonthlyInterest, $totalCollection, $totalExpenses, $balanceAmount, $status, $yearId]);
            
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
            
            $success = "Academic year updated successfully with " . count($selectedMembers) . " members!";
            
        } elseif ($action === 'delete') {
            $yearId = intval($_POST['year_id']);
            
            // Check if year has transactions
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE YearID = ?");
            $stmt->execute([$yearId]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $error = "Cannot delete year with existing transactions!";
            } else {
                $stmt = $pdo->prepare("DELETE FROM academic_years WHERE YearID = ?");
                $stmt->execute([$yearId]);
                $success = "Academic year deleted successfully!";
            }
            
        } elseif ($action === 'activate') {
            $yearId = intval($_POST['year_id']);
            $pdo->query("UPDATE academic_years SET Status = 'Inactive'");
            $stmt = $pdo->prepare("UPDATE academic_years SET Status = 'Active' WHERE YearID = ?");
            $stmt->execute([$yearId]);
            $success = "Academic year activated successfully!";
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch all members for multi-select dropdown
$stmt = $pdo->query("SELECT MemberID, MemberName, Status FROM members ORDER BY MemberName ASC");
$allMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all academic years
$stmt = $pdo->query("
    SELECT y.*, 
           COUNT(DISTINCT t.MemberID) as MemberCount,
           COUNT(t.TransactionID) as TransactionCount,
           COALESCE(SUM(t.CollectionAmount), 0) as TotalCollection,
           COALESCE(SUM(t.DistributedAmount), 0) as TotalDistribution
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Academic Years - Super Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f3f4f6; }
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .header h1 { font-size: 2rem; color: #111827; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: 500; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: #6366f1; color: white; }
        .btn-primary:hover { background: #4f46e5; }
        .btn-success { background: #10b981; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-sm { padding: 0.5rem 1rem; font-size: 0.875rem; }
        .card { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; color: #374151; font-weight: 500; }
        .form-control { width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; }
        th { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 1rem; text-align: left; font-weight: 600; }
        td { padding: 0.875rem 1rem; border-bottom: 1px solid #e5e7eb; }
        tr:hover { background: #f9fafb; }
        .badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-danger { background: #fee2e2; color: #991b1b; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal.active { display: flex; align-items: center; justify-content: center; }
        .modal-content { background: white; padding: 2rem; border-radius: 12px; max-width: 900px; width: 90%; max-height: 90vh; overflow-y: auto; }
        .stats { display: flex; gap: 1rem; margin-top: 0.5rem; font-size: 0.875rem; color: #6b7280; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-calendar-alt"></i> Manage Academic Years</h1>
            <div>
                <button onclick="showCreateModal()" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create New Year
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Year Name</th>
                        <th>Period</th>
                        <th>Interest Rate</th>
                        <th>Status</th>
                        <th>Statistics</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($years as $year): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($year['YearName']); ?></strong></td>
                            <td><?php echo date('M d, Y', strtotime($year['StartDate'])); ?> - <?php echo date('M d, Y', strtotime($year['EndDate'])); ?></td>
                            <td><?php echo $year['InterestRate']; ?>%</td>
                            <td>
                                <span class="badge badge-<?php echo $year['Status'] === 'Active' ? 'success' : 'warning'; ?>">
                                    <?php echo $year['Status']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="stats">
                                    <span><i class="fas fa-users"></i> <?php echo $year['MemberCount']; ?> members</span>
                                    <span><i class="fas fa-exchange-alt"></i> <?php echo $year['TransactionCount']; ?> txns</span>
                                </div>
                                <div class="stats">
                                    <span><i class="fas fa-rupee-sign"></i> ₹<?php echo number_format($year['TotalCollection'], 0); ?> collected</span>
                                </div>
                            </td>
                            <td>
                                <?php if ($year['Status'] !== 'Active'): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Set this as active year?');">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="year_id" value="<?php echo $year['YearID']; ?>">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-check"></i> Activate
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <button onclick="showEditModal(<?php echo htmlspecialchars(json_encode($year)); ?>)" class="btn btn-primary btn-sm">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <?php if ($year['TransactionCount'] == 0): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this year?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="year_id" value="<?php echo $year['YearID']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create/Edit Modal -->
    <div id="yearModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle">Create Academic Year</h2>
            <form method="POST" id="yearForm">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="year_id" id="yearId">
                
                <div class="form-group">
                    <label>Year Name *</label>
                    <input type="text" name="year_name" id="yearName" class="form-control" required placeholder="e.g., 2024-2025">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Start Date *</label>
                        <input type="date" name="start_date" id="startDate" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>End Date *</label>
                        <input type="date" name="end_date" id="endDate" class="form-control" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Interest Rate *</label>
                        <input type="number" name="interest_rate" id="interestRate" class="form-control" step="0.01" required placeholder="e.g., 0.20 for 20%">
                        <small style="color: #666; font-size: 12px;">Enter as decimal (e.g., 0.20 = 20%, 0.15 = 15%)</small>
                    </div>
                    <div class="form-group">
                        <label>Interest Type *</label>
                        <select name="interest_type" id="interestType" class="form-control" required>
                            <option value="Monthly">Monthly Interest</option>
                            <option value="Yearly">Yearly Interest</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Include Interest in Total? *</label>
                    <select name="include_interest_in_total" id="includeInterestInTotal" class="form-control" required>
                        <option value="Yes">Yes - Add interest to distributed amount</option>
                        <option value="No">No - Interest separate from distributed amount</option>
                    </select>
                    <small style="color: #666; font-size: 12px;">
                        <strong>Yes:</strong> Total = Distribution + Interest (e.g., ₹500 + ₹100 = ₹600)<br>
                        <strong>No:</strong> Total = Distribution only (e.g., ₹500, Interest ₹100 shown separately)
                    </small>
                </div>
                
                <div class="form-group">
                    <label>Collection Model *</label>
                    <select name="collection_model" id="collectionModel" class="form-control" required onchange="updateCollectionModelInfo()">
                        <option value="FullAmount">Full Amount - Pay everything each month</option>
                        <option value="InterestOnly">Interest Only - Pay interest monthly, principal on new distribution</option>
                        <option value="FlexiblePayment">Flexible Payment - Choose amount each month</option>
                    </select>
                    <div id="collectionModelInfo" style="margin-top: 10px; padding: 12px; background: #f0f7ff; border-left: 4px solid #6366f1; border-radius: 4px; display: none;">
                        <small style="color: #333; font-size: 12px; line-height: 1.6;"></small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Default Transaction Month</label>
                        <select name="default_month" id="defaultMonth" class="form-control">
                            <option value="">-- Use System Month --</option>
                            <option value="January">January</option>
                            <option value="February">February</option>
                            <option value="March">March</option>
                            <option value="April">April</option>
                            <option value="May">May</option>
                            <option value="June">June</option>
                            <option value="July">July</option>
                            <option value="August">August</option>
                            <option value="September">September</option>
                            <option value="October">October</option>
                            <option value="November">November</option>
                            <option value="December">December</option>
                        </select>
                        <small style="color: #666; font-size: 12px;">Leave empty to use current system month</small>
                    </div>
                    <div class="form-group">
                        <label>Default Transaction Date</label>
                        <input type="text" name="default_transaction_date" id="defaultTransactionDate" class="form-control" placeholder="DD-MM-YYYY (e.g., 01-10-2024)" pattern="\d{2}-\d{2}-\d{4}" maxlength="10">
                        <small style="color: #666; font-size: 12px;">Date in DD-MM-YYYY format. Leave empty to use system date.</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Last Month to Collect Principal</label>
                        <select name="last_month_to_collect" id="lastMonthToCollect" class="form-control">
                            <option value="">Not Set</option>
                            <option value="January">January</option>
                            <option value="February">February</option>
                            <option value="March">March</option>
                            <option value="April">April</option>
                            <option value="May">May</option>
                            <option value="June">June</option>
                            <option value="July">July</option>
                            <option value="August">August</option>
                            <option value="September">September</option>
                            <option value="October">October</option>
                            <option value="November">November</option>
                            <option value="December">December</option>
                        </select>
                        <small style="color: #666; font-size: 12px;">Month when deferred principal should be collected</small>
                    </div>
                    <div class="form-group">
                        <label>Deferred Principal Amount</label>
                        <input type="number" name="deferred_principal_amount" id="deferredPrincipalAmount" class="form-control" step="0.01" min="0" value="0" placeholder="0.00">
                        <small style="color: #666; font-size: 12px;">Principal amount deferred to last month</small>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Standing Monthly Interest</label>
                        <input type="number" name="standing_monthly_interest" id="standingMonthlyInterest" class="form-control" step="0.01" min="0" value="100" placeholder="100.00">
                        <small style="color: #666; font-size: 12px;">Fixed monthly interest amount (default: 100.00)</small>
                    </div>
                    <div class="form-group">
                        <label>Status *</label>
                        <select name="status" id="status" class="form-control" required>
                            <option value="Upcoming">Upcoming</option>
                            <option value="Active">Active</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Total Collection (₹)</label>
                        <input type="number" name="total_collection" id="totalCollection" class="form-control" step="0.01" value="0.00">
                    </div>
                    <div class="form-group">
                        <label>Total Expenses (₹)</label>
                        <input type="number" name="total_expenses" id="totalExpenses" class="form-control" step="0.01" value="0.00">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Balance Amount (₹)</label>
                    <input type="number" name="balance_amount" id="balanceAmount" class="form-control" step="0.01" value="0.00">
                </div>
                
                <div class="form-group">
                    <label>Select Members for this Academic Year</label>
                    <div style="border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; max-height: 300px; overflow-y: auto; background: #f9fafb;">
                        <div style="margin-bottom: 10px; padding: 8px; background: white; border-radius: 6px; position: sticky; top: 0; z-index: 10;">
                            <input type="text" id="memberSearch" placeholder="Search members..." style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px;">
                        </div>
                        <div style="display: flex; gap: 10px; margin-bottom: 10px; position: sticky; top: 50px; background: #f9fafb; padding: 5px 0; z-index: 9;">
                            <button type="button" onclick="selectAllMembers()" class="btn btn-sm" style="padding: 6px 12px; background: #10b981; color: white; font-size: 12px;">
                                <i class="fas fa-check-double"></i> Select All
                            </button>
                            <button type="button" onclick="deselectAllMembers()" class="btn btn-sm" style="padding: 6px 12px; background: #ef4444; color: white; font-size: 12px;">
                                <i class="fas fa-times"></i> Deselect All
                            </button>
                            <span id="selectedCount" style="padding: 6px 12px; background: #6366f1; color: white; border-radius: 6px; font-size: 12px; font-weight: 600;">0 selected</span>
                        </div>
                        <div id="memberCheckboxes">
                            <?php foreach ($allMembers as $member): ?>
                            <label style="display: flex; align-items: center; padding: 8px; margin-bottom: 5px; background: white; border-radius: 6px; cursor: pointer; transition: background 0.2s;" 
                                   onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='white'" 
                                   class="member-checkbox-label" data-member-name="<?php echo strtolower(htmlspecialchars($member['MemberName'])); ?>">
                                <input type="checkbox" name="selected_members[]" value="<?php echo $member['MemberID']; ?>" 
                                       style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;" 
                                       onchange="updateSelectedCount()">
                                <span style="flex: 1; font-weight: 500;"><?php echo htmlspecialchars($member['MemberName']); ?></span>
                                <span style="font-size: 12px; color: #6b7280; padding: 2px 8px; background: #<?php echo $member['Status'] === 'Active' ? 'd1fae5' : 'fee2e2'; ?>; border-radius: 4px;">
                                    <?php echo $member['Status']; ?>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button type="button" onclick="closeModal()" class="btn btn-secondary">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const yearMembersData = <?php echo json_encode($yearMembers); ?>;
        
        function showCreateModal() {
            document.getElementById('modalTitle').textContent = 'Create Academic Year';
            document.getElementById('formAction').value = 'create';
            document.getElementById('yearForm').reset();
            updateSelectedCount();
            document.getElementById('yearModal').classList.add('active');
        }

        function showEditModal(year) {
            document.getElementById('modalTitle').textContent = 'Edit Academic Year';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('yearId').value = year.YearID;
            document.getElementById('yearName').value = year.YearName;
            document.getElementById('startDate').value = year.StartDate;
            document.getElementById('endDate').value = year.EndDate;
            document.getElementById('interestRate').value = year.InterestRate;
            document.getElementById('interestType').value = year.InterestType || 'Monthly';
            document.getElementById('includeInterestInTotal').value = year.IncludeInterestInTotal || 'Yes';
            document.getElementById('collectionModel').value = year.CollectionModel || 'FullAmount';
            document.getElementById('defaultMonth').value = year.DefaultMonth || '';
            document.getElementById('defaultTransactionDate').value = year.DefaultTransactionDate || '';
            document.getElementById('lastMonthToCollect').value = year.LastMonthToCollect || '';
            document.getElementById('deferredPrincipalAmount').value = year.DeferredPrincipalAmount || '0';
            document.getElementById('standingMonthlyInterest').value = year.StandingMonthlyInterest || '100';
            document.getElementById('totalCollection').value = year.TotalCollection || '0.00';
            document.getElementById('totalExpenses').value = year.TotalExpenses || '0.00';
            document.getElementById('balanceAmount').value = year.BalanceAmount || '0.00';
            document.getElementById('status').value = year.Status;
            updateCollectionModelInfo(); // Update the info text
            
            // Pre-select members for this year
            const checkboxes = document.querySelectorAll('input[name="selected_members[]"]');
            const selectedMembers = yearMembersData[year.YearID] || [];
            checkboxes.forEach(cb => {
                cb.checked = selectedMembers.includes(parseInt(cb.value));
            });
            
            updateSelectedCount();
            document.getElementById('yearModal').classList.add('active');
        }
        
        function updateCollectionModelInfo() {
            const model = document.getElementById('collectionModel').value;
            const infoDiv = document.getElementById('collectionModelInfo');
            const infoText = infoDiv.querySelector('small');
            
            const messages = {
                'FullAmount': '<strong>Full Amount Model:</strong><br>Members pay the complete distributed amount plus interest each month. This is the traditional payment model where all dues are collected monthly.',
                'InterestOnly': '<strong>Interest Only Model:</strong><br>Members pay only the monthly interest (typically ₹100). The principal amount is deferred and collected when a new distribution happens or at year-end.',
                'FlexiblePayment': '<strong>Flexible Payment Model:</strong><br>Members can choose how much to pay each month. The system tracks their balance, and they can pay in installments based on their convenience.'
            };
            
            if (messages[model]) {
                infoText.innerHTML = messages[model];
                infoDiv.style.display = 'block';
            } else {
                infoDiv.style.display = 'none';
            }
        }
        
        function selectAllMembers() {
            const checkboxes = document.querySelectorAll('input[name="selected_members[]"]');
            const visibleLabels = Array.from(document.querySelectorAll('.member-checkbox-label'))
                .filter(label => label.style.display !== 'none');
            
            visibleLabels.forEach(label => {
                const checkbox = label.querySelector('input[type="checkbox"]');
                if (checkbox) checkbox.checked = true;
            });
            updateSelectedCount();
        }
        
        function deselectAllMembers() {
            const checkboxes = document.querySelectorAll('input[name="selected_members[]"]');
            checkboxes.forEach(cb => cb.checked = false);
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('input[name="selected_members[]"]:checked');
            document.getElementById('selectedCount').textContent = checkboxes.length + ' selected';
        }
        
        // Member search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('memberSearch');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    const searchTerm = e.target.value.toLowerCase();
                    const labels = document.querySelectorAll('.member-checkbox-label');
                    
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

        function closeModal() {
            document.getElementById('yearModal').classList.remove('active');
        }

        // Close modal on outside click
        window.onclick = function(event) {
            const modal = document.getElementById('yearModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
<?php include '../includes/gemini-widget.php'; ?>
</body>
</html>
