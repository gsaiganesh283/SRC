<?php
/**
 * Budget Tracking Page
 * Manage and monitor budget limits for expense categories
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Check if expenses table exists
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'expenses'");
if ($tableCheckStmt->rowCount() === 0) {
    header('Location: setup-expenses.php');
    exit;
}

// Check if budget_limits table exists
$budgetTableCheck = $pdo->query("SHOW TABLES LIKE 'budget_limits'");
if ($budgetTableCheck->rowCount() === 0) {
    header('Location: setup-expenses.php');
    exit;
}

$message = '';
$messageType = '';

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected year (default to most recent)
$selectedYear = isset($_GET['year']) ? $_GET['year'] : ($academicYears[0]['YearID'] ?? '');

// Fetch expense categories
$catStmt = $pdo->query("SELECT CategoryID, CategoryName FROM expense_categories WHERE IsActive = 1 ORDER BY SortOrder, CategoryName");
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_budget') {
        $yearId = $_POST['year_id'] ?? '';
        $category = $_POST['category'] ?? '';
        $monthlyLimit = $_POST['monthly_limit'] ?? 0;
        $yearlyLimit = $_POST['yearly_limit'] ?? 0;
        $alertThreshold = $_POST['alert_threshold'] ?? 80;
        
        if ($yearId && $category) {
            try {
                // Check if budget exists
                $checkStmt = $pdo->prepare("SELECT BudgetID FROM budget_limits WHERE YearID = ? AND Category = ?");
                $checkStmt->execute([$yearId, $category]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    // Update
                    $updateStmt = $pdo->prepare("UPDATE budget_limits SET MonthlyLimit = ?, YearlyLimit = ?, AlertThreshold = ?, UpdatedAt = NOW() WHERE BudgetID = ?");
                    $updateStmt->execute([$monthlyLimit, $yearlyLimit, $alertThreshold, $existing['BudgetID']]);
                } else {
                    // Insert
                    $insertStmt = $pdo->prepare("INSERT INTO budget_limits (YearID, Category, MonthlyLimit, YearlyLimit, AlertThreshold, CreatedBy, CreatedAt) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $insertStmt->execute([$yearId, $category, $monthlyLimit, $yearlyLimit, $alertThreshold, $_SESSION['superadmin_id']]);
                }
                
                $message = "Budget for '$category' saved successfully!";
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = "Error saving budget: " . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete_budget') {
        $budgetId = $_POST['budget_id'] ?? '';
        if ($budgetId) {
            $deleteStmt = $pdo->prepare("DELETE FROM budget_limits WHERE BudgetID = ?");
            $deleteStmt->execute([$budgetId]);
            $message = "Budget limit removed successfully!";
            $messageType = 'success';
        }
    }
}

// Fetch current budgets for selected year
$budgetData = [];
if ($selectedYear) {
    $budgetQuery = "
        SELECT 
            bl.BudgetID,
            bl.Category,
            bl.MonthlyLimit,
            bl.YearlyLimit,
            bl.AlertThreshold,
            IFNULL(SUM(e.Amount), 0) as SpentTotal,
            (SELECT IFNULL(SUM(Amount), 0) FROM expenses WHERE YearID = bl.YearID AND Category = bl.Category AND MONTH(ExpenseDate) = MONTH(CURRENT_DATE) AND YEAR(ExpenseDate) = YEAR(CURRENT_DATE)) as SpentThisMonth
        FROM budget_limits bl
        LEFT JOIN expenses e ON bl.YearID = e.YearID AND bl.Category = e.Category
        WHERE bl.YearID = ?
        GROUP BY bl.BudgetID
        ORDER BY bl.Category
    ";
    $budgetStmt = $pdo->prepare($budgetQuery);
    $budgetStmt->execute([$selectedYear]);
    $budgetData = $budgetStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get categories with spending but no budget
$unbugdetedQuery = "
    SELECT 
        Category,
        SUM(Amount) as SpentTotal
    FROM expenses
    WHERE YearID = ?
    GROUP BY Category
    HAVING Category NOT IN (SELECT Category FROM budget_limits WHERE YearID = ?)
";
$unbudgetedStmt = $pdo->prepare($unbugdetedQuery);
$unbudgetedStmt->execute([$selectedYear, $selectedYear]);
$unbudgetedCategories = $unbudgetedStmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Tracking - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../shared-styles.css">
    <style>
        .page-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .page-header h1 {
            font-size: 28px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .page-header h1 i {
            color: #10b981;
        }
        
        .header-actions {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .year-select {
            padding: 12px 20px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
        }
        
        .year-select:focus {
            border-color: #10b981;
            outline: none;
        }
        
        .btn-add {
            padding: 12px 24px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid #10b981;
            color: #10b981;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            color: #ef4444;
        }
        
        .budget-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .budget-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow);
        }
        
        .budget-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .budget-card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .budget-card-title i {
            color: #10b981;
        }
        
        .budget-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .btn-edit {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        .btn-delete {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .budget-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .budget-stat {
            text-align: center;
            padding: 15px;
            background: var(--bg-tertiary);
            border-radius: 8px;
        }
        
        .budget-stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }
        
        .budget-stat-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .progress-section {
            margin-bottom: 15px;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .progress-label {
            color: var(--text-secondary);
        }
        
        .progress-value {
            font-weight: 600;
        }
        
        .progress-bar {
            height: 12px;
            background: var(--bg-tertiary);
            border-radius: 6px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 6px;
            transition: width 0.5s ease;
        }
        
        .progress-fill.safe {
            background: linear-gradient(90deg, #10b981, #34d399);
        }
        
        .progress-fill.warning {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
        }
        
        .progress-fill.danger {
            background: linear-gradient(90deg, #ef4444, #f87171);
        }
        
        .budget-alert {
            padding: 10px 15px;
            border-radius: 8px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .budget-alert.warning {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }
        
        .budget-alert.danger {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .unbudgeted-section {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow);
            border: 2px dashed var(--border-color);
        }
        
        .unbudgeted-section h2 {
            font-size: 18px;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .unbudgeted-section h2 i {
            color: #f59e0b;
        }
        
        .unbudgeted-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .unbudgeted-item {
            padding: 15px;
            background: var(--bg-tertiary);
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .unbudgeted-item .name {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .unbudgeted-item .spent {
            color: #ef4444;
            font-weight: 700;
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
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 30px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .modal-header h2 {
            font-size: 20px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .modal-header h2 i {
            color: #10b981;
        }
        
        .modal-close {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: none;
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 18px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 15px;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            border-color: #10b981;
            outline: none;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            color: #10b981;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-secondary);
            border-radius: 12px;
        }
        
        .empty-state i {
            font-size: 64px;
            color: var(--text-secondary);
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 24px;
            color: var(--text-primary);
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .budget-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar" style="background: linear-gradient(135deg, #10b981, #059669);">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-calculator"></i>
                <span>Accounting Portal</span>
            </div>
            <div class="navbar-user">
                <button id="themeToggle" class="theme-toggle">
                    <i id="themeIcon" class="fas fa-moon"></i>
                    <span id="themeText">Dark</span>
                </button>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_name); ?></div>
                    <div class="user-role">Accounting Manager</div>
                </div>
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                    <span>Dashboard</span>
                </a>
                <a href="../logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
                <?php include "../includes/datetime-widget.php"; ?>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
        </a>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="page-header">
            <div>
                <h1><i class="fas fa-piggy-bank"></i> Budget Tracking</h1>
                <p style="color: var(--text-secondary); margin-top: 8px;">Set and monitor expense budgets for each category</p>
            </div>
            <div class="header-actions">
                <form method="GET" id="yearForm">
                    <select name="year" class="year-select" onchange="document.getElementById('yearForm').submit()">
                        <?php foreach ($academicYears as $year): ?>
                            <option value="<?php echo $year['YearID']; ?>" <?php echo $selectedYear == $year['YearID'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['YearName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <button class="btn-add" onclick="openModal()">
                    <i class="fas fa-plus"></i>
                    Add Budget
                </button>
            </div>
        </div>
        
        <?php if (empty($budgetData)): ?>
            <div class="empty-state">
                <i class="fas fa-piggy-bank"></i>
                <h3>No Budgets Set</h3>
                <p>You haven't set any budget limits for this year yet.</p>
                <button class="btn-add" onclick="openModal()">
                    <i class="fas fa-plus"></i>
                    Set First Budget
                </button>
            </div>
        <?php else: ?>
            <div class="budget-grid">
                <?php foreach ($budgetData as $budget): 
                    $monthlyPercent = $budget['MonthlyLimit'] > 0 ? min(100, ($budget['SpentThisMonth'] / $budget['MonthlyLimit']) * 100) : 0;
                    $yearlyPercent = $budget['YearlyLimit'] > 0 ? min(100, ($budget['SpentTotal'] / $budget['YearlyLimit']) * 100) : 0;
                    
                    $monthlyStatus = $monthlyPercent >= 100 ? 'danger' : ($monthlyPercent >= $budget['AlertThreshold'] ? 'warning' : 'safe');
                    $yearlyStatus = $yearlyPercent >= 100 ? 'danger' : ($yearlyPercent >= $budget['AlertThreshold'] ? 'warning' : 'safe');
                ?>
                    <div class="budget-card">
                        <div class="budget-card-header">
                            <div class="budget-card-title">
                                <i class="fas fa-folder"></i>
                                <?php echo htmlspecialchars($budget['Category']); ?>
                            </div>
                            <div class="budget-actions">
                                <button class="btn-icon btn-edit" onclick="editBudget(<?php echo htmlspecialchars(json_encode($budget)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this budget limit?')">
                                    <input type="hidden" name="action" value="delete_budget">
                                    <input type="hidden" name="budget_id" value="<?php echo $budget['BudgetID']; ?>">
                                    <button type="submit" class="btn-icon btn-delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        
                        <div class="budget-stats">
                            <div class="budget-stat">
                                <div class="budget-stat-label">Monthly Limit</div>
                                <div class="budget-stat-value">₹<?php echo number_format($budget['MonthlyLimit'], 0); ?></div>
                            </div>
                            <div class="budget-stat">
                                <div class="budget-stat-label">Yearly Limit</div>
                                <div class="budget-stat-value">₹<?php echo number_format($budget['YearlyLimit'], 0); ?></div>
                            </div>
                        </div>
                        
                        <div class="progress-section">
                            <div class="progress-header">
                                <span class="progress-label">This Month</span>
                                <span class="progress-value">₹<?php echo number_format($budget['SpentThisMonth'], 0); ?> / ₹<?php echo number_format($budget['MonthlyLimit'], 0); ?></span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill <?php echo $monthlyStatus; ?>" style="width: <?php echo $monthlyPercent; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="progress-section">
                            <div class="progress-header">
                                <span class="progress-label">Year Total</span>
                                <span class="progress-value">₹<?php echo number_format($budget['SpentTotal'], 0); ?> / ₹<?php echo number_format($budget['YearlyLimit'], 0); ?></span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill <?php echo $yearlyStatus; ?>" style="width: <?php echo $yearlyPercent; ?>%"></div>
                            </div>
                        </div>
                        
                        <?php if ($yearlyStatus === 'danger'): ?>
                            <div class="budget-alert danger">
                                <i class="fas fa-exclamation-triangle"></i>
                                Budget exceeded! Yearly spending is over the limit.
                            </div>
                        <?php elseif ($yearlyStatus === 'warning' || $monthlyStatus === 'warning'): ?>
                            <div class="budget-alert warning">
                                <i class="fas fa-exclamation-circle"></i>
                                Approaching budget limit. Review expenses.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($unbudgetedCategories)): ?>
            <div class="unbudgeted-section">
                <h2><i class="fas fa-exclamation-triangle"></i> Categories Without Budgets</h2>
                <p style="color: var(--text-secondary); margin-bottom: 20px;">These categories have expenses but no budget limits set.</p>
                <div class="unbudgeted-list">
                    <?php foreach ($unbudgetedCategories as $cat): ?>
                        <div class="unbudgeted-item">
                            <span class="name"><?php echo htmlspecialchars($cat['Category']); ?></span>
                            <span class="spent">₹<?php echo number_format($cat['SpentTotal'], 0); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Add/Edit Budget Modal -->
    <div class="modal" id="budgetModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-piggy-bank"></i> <span id="modalTitle">Add Budget</span></h2>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" id="budgetForm">
                <input type="hidden" name="action" value="save_budget">
                <input type="hidden" name="year_id" value="<?php echo htmlspecialchars($selectedYear); ?>">
                
                <div class="form-group">
                    <label>Category</label>
                    <select name="category" id="categorySelect" required>
                        <option value="">Select Category</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['CategoryName']); ?>">
                                <?php echo htmlspecialchars($cat['CategoryName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Monthly Limit (₹)</label>
                        <input type="number" name="monthly_limit" id="monthlyLimit" min="0" step="100" required>
                    </div>
                    <div class="form-group">
                        <label>Yearly Limit (₹)</label>
                        <input type="number" name="yearly_limit" id="yearlyLimit" min="0" step="1000" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Alert Threshold (%)</label>
                    <input type="number" name="alert_threshold" id="alertThreshold" min="50" max="100" value="80">
                    <small style="color: var(--text-secondary);">Alert when spending reaches this percentage of limit</small>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i>
                    Save Budget
                </button>
            </form>
        </div>
    </div>
    
    <script src="../theme-manager.js"></script>
    <script>
        function openModal() {
            document.getElementById('budgetModal').classList.add('active');
            document.getElementById('modalTitle').textContent = 'Add Budget';
            document.getElementById('budgetForm').reset();
        }
        
        function closeModal() {
            document.getElementById('budgetModal').classList.remove('active');
        }
        
        function editBudget(budget) {
            document.getElementById('budgetModal').classList.add('active');
            document.getElementById('modalTitle').textContent = 'Edit Budget';
            document.getElementById('categorySelect').value = budget.Category;
            document.getElementById('monthlyLimit').value = budget.MonthlyLimit;
            document.getElementById('yearlyLimit').value = budget.YearlyLimit;
            document.getElementById('alertThreshold').value = budget.AlertThreshold;
        }
        
        // Close modal on outside click
        document.getElementById('budgetModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Auto-calculate yearly from monthly
        document.getElementById('monthlyLimit').addEventListener('input', function() {
            const yearly = document.getElementById('yearlyLimit');
            if (!yearly.value || yearly.value == 0) {
                yearly.value = this.value * 12;
            }
        });
    </script>
</body>
</html>
