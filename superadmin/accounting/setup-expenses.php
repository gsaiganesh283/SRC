<?php
/**
 * Accounting System Setup
 * Creates the expenses table and initializes the accounting system
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

$success = '';
$error = '';

// Handle setup action
if (isset($_POST['action']) && $_POST['action'] === 'setup') {
    try {
        // Create expenses table
        $createExpensesTable = "
            CREATE TABLE IF NOT EXISTS `expenses` (
                `ExpenseID` int(11) NOT NULL AUTO_INCREMENT,
                `YearID` int(11) NOT NULL,
                `ExpenseDate` date NOT NULL,
                `Category` varchar(100) NOT NULL DEFAULT 'General',
                `SubCategory` varchar(100) DEFAULT NULL,
                `Description` text NOT NULL,
                `Amount` decimal(12,2) NOT NULL DEFAULT 0.00,
                `PaymentMode` enum('Cash','Online','UPI','Bank Transfer','Cheque','Credit Card','Debit Card') DEFAULT 'Cash',
                `VendorName` varchar(200) DEFAULT NULL,
                `InvoiceNumber` varchar(100) DEFAULT NULL,
                `ReceiptNumber` varchar(100) DEFAULT NULL,
                `BillAttachment` varchar(500) DEFAULT NULL,
                `Remarks` text DEFAULT NULL,
                `Status` enum('Pending','Approved','Rejected','Cancelled') DEFAULT 'Approved',
                `ApprovedBy` int(11) DEFAULT NULL,
                `ApprovedAt` datetime DEFAULT NULL,
                `CreatedBy` int(11) DEFAULT NULL,
                `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`ExpenseID`),
                KEY `idx_expense_year` (`YearID`),
                KEY `idx_expense_date` (`ExpenseDate`),
                KEY `idx_expense_category` (`Category`),
                KEY `idx_expense_status` (`Status`),
                KEY `idx_expense_created_by` (`CreatedBy`),
                CONSTRAINT `fk_expense_year` FOREIGN KEY (`YearID`) REFERENCES `academic_years` (`YearID`) ON DELETE CASCADE,
                CONSTRAINT `fk_expense_created_by` FOREIGN KEY (`CreatedBy`) REFERENCES `admins` (`AdminID`) ON DELETE SET NULL,
                CONSTRAINT `fk_expense_approved_by` FOREIGN KEY (`ApprovedBy`) REFERENCES `admins` (`AdminID`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";
        
        $pdo->exec($createExpensesTable);
        
        // Create expense_categories table for predefined categories
        $createCategoriesTable = "
            CREATE TABLE IF NOT EXISTS `expense_categories` (
                `CategoryID` int(11) NOT NULL AUTO_INCREMENT,
                `CategoryName` varchar(100) NOT NULL,
                `Description` text DEFAULT NULL,
                `Icon` varchar(50) DEFAULT 'fa-folder',
                `Color` varchar(20) DEFAULT '#10b981',
                `IsActive` tinyint(1) DEFAULT 1,
                `SortOrder` int(11) DEFAULT 0,
                `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`CategoryID`),
                UNIQUE KEY `unique_category_name` (`CategoryName`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";
        
        $pdo->exec($createCategoriesTable);
        
        // Insert default expense categories
        $defaultCategories = [
            ['Administrative', 'Office supplies, stationery, etc.', 'fa-folder', '#3b82f6'],
            ['Utilities', 'Electricity, water, internet, phone bills', 'fa-bolt', '#f59e0b'],
            ['Transportation', 'Travel, fuel, vehicle maintenance', 'fa-car', '#8b5cf6'],
            ['Food & Refreshments', 'Meeting refreshments, events catering', 'fa-utensils', '#ec4899'],
            ['Maintenance', 'Building maintenance, repairs', 'fa-tools', '#14b8a6'],
            ['Events', 'Event organization, celebrations', 'fa-calendar-star', '#f97316'],
            ['Donations', 'Charitable donations, community support', 'fa-hand-holding-heart', '#ef4444'],
            ['Professional Fees', 'Legal, accounting, consulting fees', 'fa-briefcase', '#6366f1'],
            ['Insurance', 'Insurance premiums', 'fa-shield-alt', '#10b981'],
            ['Miscellaneous', 'Other uncategorized expenses', 'fa-ellipsis-h', '#64748b']
        ];
        
        $insertCategoryStmt = $pdo->prepare("
            INSERT IGNORE INTO expense_categories (CategoryName, Description, Icon, Color, SortOrder) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($defaultCategories as $index => $category) {
            $insertCategoryStmt->execute([$category[0], $category[1], $category[2], $category[3], $index + 1]);
        }
        
        // Create budget_limits table for budget tracking
        $createBudgetTable = "
            CREATE TABLE IF NOT EXISTS `budget_limits` (
                `BudgetID` int(11) NOT NULL AUTO_INCREMENT,
                `YearID` int(11) NOT NULL,
                `Category` varchar(100) NOT NULL,
                `MonthlyLimit` decimal(12,2) DEFAULT 0.00,
                `YearlyLimit` decimal(12,2) DEFAULT 0.00,
                `AlertThreshold` decimal(5,2) DEFAULT 80.00 COMMENT 'Percentage when to alert',
                `IsActive` tinyint(1) DEFAULT 1,
                `CreatedBy` int(11) DEFAULT NULL,
                `CreatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `UpdatedAt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`BudgetID`),
                UNIQUE KEY `unique_year_category` (`YearID`, `Category`),
                KEY `idx_budget_year` (`YearID`),
                CONSTRAINT `fk_budget_year` FOREIGN KEY (`YearID`) REFERENCES `academic_years` (`YearID`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";
        
        $pdo->exec($createBudgetTable);
        
        // Log the setup action
        $logStmt = $pdo->prepare("
            INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, IPAddress, UserAgent) 
            VALUES ('SuperAdmin', ?, ?, 'Initialized Accounting System - Created expenses, expense_categories, and budget_limits tables', 'system', ?, ?)
        ");
        $logStmt->execute([
            $_SESSION['superadmin_id'],
            $_SESSION['superadmin_name'] ?? 'SuperAdmin',
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
        
        $success = 'Accounting system has been successfully set up! The expenses table and related tables have been created.';
        
    } catch (PDOException $e) {
        $error = 'Error setting up accounting system: ' . $e->getMessage();
        error_log("Accounting Setup Error: " . $e->getMessage());
    }
}

// Check current status
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'expenses'");
$expensesTableExists = $tableCheckStmt->rowCount() > 0;

$categoriesCheckStmt = $pdo->query("SHOW TABLES LIKE 'expense_categories'");
$categoriesTableExists = $categoriesCheckStmt->rowCount() > 0;

$budgetCheckStmt = $pdo->query("SHOW TABLES LIKE 'budget_limits'");
$budgetTableExists = $budgetCheckStmt->rowCount() > 0;

$allTablesExist = $expensesTableExists && $categoriesTableExists && $budgetTableExists;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Accounting System - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../shared-styles.css">
    <style>
        .setup-container {
            max-width: 800px;
            margin: 50px auto;
        }
        
        .setup-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 40px;
            box-shadow: var(--shadow-lg);
        }
        
        .setup-header {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .setup-header i {
            font-size: 64px;
            color: #10b981;
            margin-bottom: 20px;
        }
        
        .setup-header h1 {
            font-size: 28px;
            color: var(--text-primary);
            margin-bottom: 10px;
        }
        
        .setup-header p {
            color: var(--text-secondary);
            font-size: 16px;
        }
        
        .setup-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: var(--bg-tertiary);
            border-radius: 12px;
            border-left: 4px solid #10b981;
        }
        
        .feature-item i {
            font-size: 24px;
            color: #10b981;
        }
        
        .feature-item span {
            color: var(--text-primary);
            font-weight: 500;
        }
        
        .status-grid {
            display: grid;
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .status-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            background: var(--bg-tertiary);
            border-radius: 8px;
        }
        
        .status-item .name {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-primary);
            font-weight: 500;
        }
        
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-badge.exists {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }
        
        .status-badge.missing {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }
        
        .setup-actions {
            text-align: center;
        }
        
        .setup-btn {
            padding: 16px 40px;
            font-size: 18px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 12px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
        }
        
        .setup-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
        }
        
        .setup-btn:disabled {
            background: #64748b;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .alert {
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 2px solid #10b981;
            color: var(--text-primary);
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid #ef4444;
            color: var(--text-primary);
        }
        
        .alert i {
            font-size: 24px;
        }
        
        .alert-success i { color: #10b981; }
        .alert-error i { color: #ef4444; }
        
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
                    <div class="user-role">Super Administrator</div>
                </div>
                <a href="../logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
                <?php include "../includes/datetime-widget.php"; ?>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <div class="setup-container">
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-arrow-left"></i>
                Back to Dashboard
            </a>
            
            <div class="setup-card">
                <div class="setup-header">
                    <i class="fas fa-database"></i>
                    <h1>Setup Accounting System</h1>
                    <p>This will create the necessary database tables for expense tracking and budget management.</p>
                </div>
                
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <strong>Success!</strong><br>
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <div>
                            <strong>Error!</strong><br>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <h3 style="margin-bottom: 15px; color: var(--text-primary);">Features to be Enabled:</h3>
                <div class="setup-features">
                    <div class="feature-item">
                        <i class="fas fa-receipt"></i>
                        <span>Expense Tracking</span>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-tags"></i>
                        <span>Category Management</span>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-piggy-bank"></i>
                        <span>Budget Limits</span>
                    </div>
                    <div class="feature-item">
                        <i class="fas fa-chart-bar"></i>
                        <span>Expense Reports</span>
                    </div>
                </div>
                
                <h3 style="margin-bottom: 15px; color: var(--text-primary);">Database Tables Status:</h3>
                <div class="status-grid">
                    <div class="status-item">
                        <span class="name">
                            <i class="fas fa-table"></i>
                            expenses
                        </span>
                        <span class="status-badge <?php echo $expensesTableExists ? 'exists' : 'missing'; ?>">
                            <?php echo $expensesTableExists ? 'Created' : 'Not Created'; ?>
                        </span>
                    </div>
                    <div class="status-item">
                        <span class="name">
                            <i class="fas fa-table"></i>
                            expense_categories
                        </span>
                        <span class="status-badge <?php echo $categoriesTableExists ? 'exists' : 'missing'; ?>">
                            <?php echo $categoriesTableExists ? 'Created' : 'Not Created'; ?>
                        </span>
                    </div>
                    <div class="status-item">
                        <span class="name">
                            <i class="fas fa-table"></i>
                            budget_limits
                        </span>
                        <span class="status-badge <?php echo $budgetTableExists ? 'exists' : 'missing'; ?>">
                            <?php echo $budgetTableExists ? 'Created' : 'Not Created'; ?>
                        </span>
                    </div>
                </div>
                
                <div class="setup-actions">
                    <?php if ($allTablesExist): ?>
                        <a href="dashboard.php" class="setup-btn" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                            <i class="fas fa-arrow-right"></i>
                            Go to Dashboard
                        </a>
                    <?php else: ?>
                        <form method="POST" onsubmit="return confirm('This will create the accounting tables in your database. Continue?');">
                            <input type="hidden" name="action" value="setup">
                            <button type="submit" class="setup-btn">
                                <i class="fas fa-database"></i>
                                Setup Accounting System
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../theme-manager.js"></script>
</body>
</html>
