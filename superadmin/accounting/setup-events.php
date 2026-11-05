<?php
/**
 * Event Management Database Setup
 * Creates tables for event tracking - separate from main expenses/net balance
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

$message = '';
$messageType = '';
$tablesCreated = false;

// Check if tables already exist
$eventsTableExists = false;
$eventTransactionsTableExists = false;

$checkEvents = $pdo->query("SHOW TABLES LIKE 'events'");
if ($checkEvents->rowCount() > 0) {
    $eventsTableExists = true;
}

$checkEventTxn = $pdo->query("SHOW TABLES LIKE 'event_transactions'");
if ($checkEventTxn->rowCount() > 0) {
    $eventTransactionsTableExists = true;
}

// Handle setup action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
    try {
        $pdo->beginTransaction();
        
        // Create events table
        if (!$eventsTableExists) {
            $pdo->exec("CREATE TABLE events (
                EventID INT AUTO_INCREMENT PRIMARY KEY,
                EventName VARCHAR(200) NOT NULL,
                EventDescription TEXT,
                EventDate DATE NOT NULL,
                EventEndDate DATE,
                Venue VARCHAR(255),
                EventType VARCHAR(100) DEFAULT 'General',
                Status ENUM('Planned', 'Ongoing', 'Completed', 'Cancelled') DEFAULT 'Planned',
                BudgetAmount DECIMAL(15,2) DEFAULT 0,
                TotalCollection DECIMAL(15,2) DEFAULT 0,
                TotalExpense DECIMAL(15,2) DEFAULT 0,
                EventBalance DECIMAL(15,2) DEFAULT 0,
                YearID INT,
                Organizer VARCHAR(200),
                ContactNumber VARCHAR(20),
                Notes TEXT,
                CreatedBy INT,
                CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (YearID) REFERENCES academic_years(YearID) ON DELETE SET NULL,
                FOREIGN KEY (CreatedBy) REFERENCES admins(AdminID) ON DELETE SET NULL,
                INDEX idx_event_date (EventDate),
                INDEX idx_event_status (Status),
                INDEX idx_event_year (YearID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $eventsTableExists = true;
        }
        
        // Create event_transactions table
        if (!$eventTransactionsTableExists) {
            $pdo->exec("CREATE TABLE event_transactions (
                TransactionID INT AUTO_INCREMENT PRIMARY KEY,
                EventID INT NOT NULL,
                TransactionType ENUM('Collection', 'Expense') NOT NULL,
                TransactionDate DATE NOT NULL,
                Description VARCHAR(500) NOT NULL,
                Amount DECIMAL(15,2) NOT NULL,
                PaymentMode VARCHAR(50) DEFAULT 'Cash',
                ContributorName VARCHAR(200),
                ContributorContact VARCHAR(20),
                VendorName VARCHAR(200),
                InvoiceNumber VARCHAR(100),
                ReceiptNumber VARCHAR(100),
                Category VARCHAR(100),
                Remarks TEXT,
                Status ENUM('Pending', 'Confirmed', 'Cancelled') DEFAULT 'Confirmed',
                CreatedBy INT,
                CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (EventID) REFERENCES events(EventID) ON DELETE CASCADE,
                FOREIGN KEY (CreatedBy) REFERENCES admins(AdminID) ON DELETE SET NULL,
                INDEX idx_event_txn_event (EventID),
                INDEX idx_event_txn_type (TransactionType),
                INDEX idx_event_txn_date (TransactionDate)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            $eventTransactionsTableExists = true;
        }
        
        $pdo->commit();
        $tablesCreated = true;
        $message = "Event management tables created successfully!";
        $messageType = 'success';
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "Error creating tables: " . $e->getMessage();
        $messageType = 'error';
    }
}

$allTablesExist = $eventsTableExists && $eventTransactionsTableExists;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Event Management - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../shared-styles.css">
    <style>
        .setup-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        
        .setup-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 40px;
            box-shadow: var(--shadow);
            text-align: center;
        }
        
        .setup-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
        }
        
        .setup-icon i {
            font-size: 48px;
            color: white;
        }
        
        .setup-card h1 {
            font-size: 28px;
            color: var(--text-primary);
            margin-bottom: 15px;
        }
        
        .setup-card p {
            color: var(--text-secondary);
            margin-bottom: 30px;
            line-height: 1.6;
        }
        
        .table-status {
            margin: 30px 0;
        }
        
        .status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background: var(--bg-tertiary);
            border-radius: 10px;
            margin-bottom: 12px;
        }
        
        .status-item .name {
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .status-item .name i {
            color: #8b5cf6;
        }
        
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-badge.exists {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        
        .status-badge.missing {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .btn-setup {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 16px 40px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
            text-decoration: none;
        }
        
        .btn-setup:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(139, 92, 246, 0.4);
        }
        
        .btn-setup:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
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
        
        .features-list {
            text-align: left;
            margin: 30px 0;
            padding: 25px;
            background: var(--bg-tertiary);
            border-radius: 12px;
        }
        
        .features-list h3 {
            color: var(--text-primary);
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .features-list ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .features-list li {
            padding: 10px 0;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .features-list li:last-child {
            border-bottom: none;
        }
        
        .features-list li i {
            color: #8b5cf6;
            width: 20px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            margin-bottom: 30px;
        }
        
        .back-link:hover {
            color: #8b5cf6;
        }
    </style>
</head>
<body>
    <nav class="navbar" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-calendar-star"></i>
                <span>Event Management</span>
            </div>
            <div class="navbar-user">
                <button id="themeToggle" class="theme-toggle">
                    <i id="themeIcon" class="fas fa-moon"></i>
                    <span id="themeText">Dark</span>
                </button>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_name); ?></div>
                    <div class="user-role">Event Manager</div>
                </div>
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                    <span>Dashboard</span>
                </a>
                <?php include "../includes/datetime-widget.php"; ?>
            </div>
        </div>
    </nav>
    
    <div class="setup-container">
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Accounting Dashboard
        </a>
        
        <div class="setup-card">
            <div class="setup-icon">
                <i class="fas fa-calendar-check"></i>
            </div>
            
            <h1>Event Management Setup</h1>
            <p>
                Set up event management to track separate event finances.<br>
                Events have their own collections, expenses, and balance - completely separate from main accounts.
            </p>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <div class="table-status">
                <div class="status-item">
                    <span class="name">
                        <i class="fas fa-calendar-alt"></i>
                        events
                    </span>
                    <span class="status-badge <?php echo $eventsTableExists ? 'exists' : 'missing'; ?>">
                        <?php echo $eventsTableExists ? 'Created' : 'Not Created'; ?>
                    </span>
                </div>
                <div class="status-item">
                    <span class="name">
                        <i class="fas fa-exchange-alt"></i>
                        event_transactions
                    </span>
                    <span class="status-badge <?php echo $eventTransactionsTableExists ? 'exists' : 'missing'; ?>">
                        <?php echo $eventTransactionsTableExists ? 'Created' : 'Not Created'; ?>
                    </span>
                </div>
            </div>
            
            <div class="features-list">
                <h3><i class="fas fa-star"></i> Event Management Features</h3>
                <ul>
                    <li><i class="fas fa-check"></i> Track multiple events with separate finances</li>
                    <li><i class="fas fa-check"></i> Record event-specific collections and expenses</li>
                    <li><i class="fas fa-check"></i> Auto-calculate event balance (Collection - Expense)</li>
                    <li><i class="fas fa-check"></i> Events are NOT included in main net balance</li>
                    <li><i class="fas fa-check"></i> Track contributors, vendors, and payment modes</li>
                    <li><i class="fas fa-check"></i> Generate event-specific reports</li>
                </ul>
            </div>
            
            <?php if ($allTablesExist): ?>
                <a href="events/dashboard.php" class="btn-setup">
                    <i class="fas fa-calendar-check"></i>
                    Go to Event Management
                </a>
            <?php else: ?>
                <form method="POST">
                    <button type="submit" name="setup" class="btn-setup">
                        <i class="fas fa-database"></i>
                        Create Event Tables
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="../theme-manager.js"></script>
</body>
</html>
