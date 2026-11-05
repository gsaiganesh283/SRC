<?php
/**
 * Export Expenses Page
 * Export expense data to CSV/Excel format
 */
ob_start(); // Start output buffering to prevent header issues
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Handle download requests first (before any session output)
if (isset($_POST['export']) || isset($_GET['download'])) {
    require_once '../../config/config.php';
    
    // Simple session check without full session include
    session_start();
    if (!isset($_SESSION['superadmin_id'])) {
        header('Location: ../login.php');
        exit;
    }
    
    // Check if expenses table exists
    $tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'expenses'");
    if ($tableCheckStmt->rowCount() === 0) {
        header('Location: setup-expenses.php');
        exit;
    }
    
    // Clear any output buffer
    ob_end_clean();
    
    // Get filter parameters
    $selectedYear = isset($_REQUEST['year']) ? $_REQUEST['year'] : 'all';
    $startDate = isset($_REQUEST['start_date']) ? $_REQUEST['start_date'] : date('Y-01-01');
    $endDate = isset($_REQUEST['end_date']) ? $_REQUEST['end_date'] : date('Y-m-d');
    $category = isset($_REQUEST['category']) ? $_REQUEST['category'] : 'all';
    $format = isset($_REQUEST['format']) ? $_REQUEST['format'] : 'csv';
    
    // Build query
    $whereConditions = ["e.ExpenseDate BETWEEN ? AND ?"];
    $params = [$startDate, $endDate];
    
    if ($selectedYear !== 'all') {
        $whereConditions[] = "e.YearID = ?";
        $params[] = $selectedYear;
    }
    
    if ($category !== 'all') {
        $whereConditions[] = "e.Category = ?";
        $params[] = $category;
    }
    
    $whereClause = implode(" AND ", $whereConditions);
    
    $query = "SELECT 
        e.ExpenseID,
        ay.YearName as AcademicYear,
        DATE_FORMAT(e.ExpenseDate, '%Y-%m-%d') as ExpenseDate,
        e.Category,
        e.SubCategory,
        e.Description,
        e.Amount,
        e.PaymentMode,
        e.VendorName,
        e.InvoiceNumber,
        e.ReceiptNumber,
        e.Status,
        e.Remarks,
        DATE_FORMAT(e.CreatedAt, '%Y-%m-%d %H:%i') as CreatedAt,
        a.FullName as CreatedByAdmin
    FROM expenses e
    LEFT JOIN academic_years ay ON e.YearID = ay.YearID
    LEFT JOIN admins a ON e.CreatedBy = a.AdminID
    WHERE $whereClause
    ORDER BY e.ExpenseDate DESC, e.ExpenseID DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate filename
    $filename = 'expenses_export_' . date('Y-m-d_His');
    
    if ($format === 'csv') {
        // CSV Export
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // BOM for Excel UTF-8 compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, [
            'ID',
            'Academic Year',
            'Date',
            'Category',
            'Sub-Category',
            'Description',
            'Amount (Rs)',
            'Payment Mode',
            'Vendor',
            'Invoice No.',
            'Receipt No.',
            'Status',
            'Remarks',
            'Created At',
            'Created By'
        ]);
        
        // Data rows
        foreach ($expenses as $expense) {
            fputcsv($output, [
                $expense['ExpenseID'],
                $expense['AcademicYear'],
                $expense['ExpenseDate'],
                $expense['Category'],
                $expense['SubCategory'],
                $expense['Description'],
                $expense['Amount'],
                $expense['PaymentMode'],
                $expense['VendorName'],
                $expense['InvoiceNumber'],
                $expense['ReceiptNumber'],
                $expense['Status'],
                $expense['Remarks'],
                $expense['CreatedAt'],
                $expense['CreatedByAdmin']
            ]);
        }
        
        // Summary row
        $totalAmount = array_sum(array_column($expenses, 'Amount'));
        fputcsv($output, []);
        fputcsv($output, ['', '', '', '', '', 'TOTAL', $totalAmount, '', '', '', '', '', '', '', '']);
        
        fclose($output);
        exit;
        
    } elseif ($format === 'excel') {
        // Excel XML Export
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        echo '<Worksheet ss:Name="Expenses">';
        echo '<Table>';
        
        // Header row
        echo '<Row>';
        $headers = ['ID', 'Academic Year', 'Date', 'Category', 'Sub-Category', 'Description', 'Amount (Rs)', 'Payment Mode', 'Vendor', 'Invoice No.', 'Receipt No.', 'Status', 'Remarks', 'Created At', 'Created By'];
        foreach ($headers as $header) {
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>';
        }
        echo '</Row>';
        
        // Data rows
        foreach ($expenses as $expense) {
            echo '<Row>';
            echo '<Cell><Data ss:Type="Number">' . $expense['ExpenseID'] . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['AcademicYear'] ?? '') . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['ExpenseDate']) . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['Category']) . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['SubCategory'] ?? '') . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['Description']) . '</Data></Cell>';
            echo '<Cell><Data ss:Type="Number">' . $expense['Amount'] . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['PaymentMode']) . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['VendorName'] ?? '') . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['InvoiceNumber'] ?? '') . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['ReceiptNumber'] ?? '') . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['Status']) . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['Remarks'] ?? '') . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['CreatedAt']) . '</Data></Cell>';
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($expense['CreatedByAdmin'] ?? '') . '</Data></Cell>';
            echo '</Row>';
        }
        
        // Total row
        $totalAmount = array_sum(array_column($expenses, 'Amount'));
        echo '<Row>';
        echo '<Cell></Cell><Cell></Cell><Cell></Cell><Cell></Cell><Cell></Cell>';
        echo '<Cell><Data ss:Type="String">TOTAL</Data></Cell>';
        echo '<Cell><Data ss:Type="Number">' . $totalAmount . '</Data></Cell>';
        echo '</Row>';
        
        echo '</Table>';
        echo '</Worksheet>';
        echo '</Workbook>';
        exit;
    }
}

// Regular page load - include session
// If not exporting, show the export form
require_once '../../includes/logo-helper.php';
$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch categories
$catStmt = $pdo->query("SELECT DISTINCT Category FROM expenses ORDER BY Category");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Get expense count preview
$countQuery = "SELECT COUNT(*) as count, IFNULL(SUM(Amount), 0) as total FROM expenses";
$countStmt = $pdo->query($countQuery);
$previewData = $countStmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Expenses - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../shared-styles.css">
    <style>
        .page-header {
            margin-bottom: 30px;
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
        
        .export-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 30px;
        }
        
        .export-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 30px;
            box-shadow: var(--shadow);
        }
        
        .export-card h2 {
            font-size: 18px;
            color: var(--text-primary);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .export-card h2 i {
            color: #10b981;
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
        
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 15px;
        }
        
        .form-group select:focus,
        .form-group input:focus {
            border-color: #10b981;
            outline: none;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .format-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .format-option {
            padding: 20px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .format-option:hover {
            border-color: #10b981;
        }
        
        .format-option.selected {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.1);
        }
        
        .format-option i {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .format-option.csv i {
            color: #3b82f6;
        }
        
        .format-option.excel i {
            color: #10b981;
        }
        
        .format-option .name {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 5px;
        }
        
        .format-option .desc {
            font-size: 12px;
            color: var(--text-secondary);
        }
        
        .btn-export {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 10px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 25px;
        }
        
        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }
        
        .preview-stats {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 25px;
            background: var(--bg-tertiary);
            border-radius: 10px;
        }
        
        .stat-item i {
            font-size: 36px;
            color: #10b981;
            margin-bottom: 15px;
        }
        
        .stat-item .value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 5px;
        }
        
        .stat-item .label {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .notes-section {
            margin-top: 25px;
            padding: 20px;
            background: rgba(59, 130, 246, 0.1);
            border-radius: 10px;
            border-left: 4px solid #3b82f6;
        }
        
        .notes-section h3 {
            font-size: 14px;
            color: #3b82f6;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .notes-section ul {
            margin: 0;
            padding-left: 20px;
            color: var(--text-secondary);
            font-size: 13px;
        }
        
        .notes-section li {
            margin-bottom: 5px;
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
        
        .quick-export {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .quick-export a {
            flex: 1;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        
        .quick-export a.csv {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border: 2px solid rgba(59, 130, 246, 0.3);
        }
        
        .quick-export a.excel {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 2px solid rgba(16, 185, 129, 0.3);
        }
        
        @media (max-width: 1024px) {
            .export-container {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .format-options {
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
        
        <div class="page-header">
            <h1><i class="fas fa-file-export"></i> Export Expenses</h1>
            <p style="color: var(--text-secondary); margin-top: 8px;">Download expense data in CSV or Excel format</p>
        </div>
        
        <div class="export-container">
            <div class="export-card">
                <h2><i class="fas fa-filter"></i> Export Options</h2>
                
                <form method="POST" id="exportForm">
                    <input type="hidden" name="export" value="1">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Academic Year</label>
                            <select name="year">
                                <option value="all">All Years</option>
                                <?php foreach ($academicYears as $year): ?>
                                    <option value="<?php echo $year['YearID']; ?>">
                                        <?php echo htmlspecialchars($year['YearName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select name="category">
                                <option value="all">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>">
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" value="<?php echo date('Y-01-01'); ?>">
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Export Format</label>
                        <div class="format-options">
                            <div class="format-option csv selected" onclick="selectFormat('csv')">
                                <i class="fas fa-file-csv"></i>
                                <div class="name">CSV</div>
                                <div class="desc">Compatible with all spreadsheets</div>
                            </div>
                            <div class="format-option excel" onclick="selectFormat('excel')">
                                <i class="fas fa-file-excel"></i>
                                <div class="name">Excel</div>
                                <div class="desc">Microsoft Excel format</div>
                            </div>
                        </div>
                        <input type="hidden" name="format" id="formatInput" value="csv">
                    </div>
                    
                    <button type="submit" class="btn-export">
                        <i class="fas fa-download"></i>
                        Download Export
                    </button>
                </form>
            </div>
            
            <div class="export-card">
                <h2><i class="fas fa-chart-bar"></i> Data Overview</h2>
                
                <div class="preview-stats">
                    <div class="stat-item">
                        <i class="fas fa-receipt"></i>
                        <div class="value"><?php echo number_format($previewData['count']); ?></div>
                        <div class="label">Total Expenses</div>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-rupee-sign"></i>
                        <div class="value"><?php echo number_format($previewData['total'], 0); ?></div>
                        <div class="label">Total Amount</div>
                    </div>
                </div>
                
                <div class="quick-export">
                    <a href="?download=1&format=csv&year=all&start_date=<?php echo date('Y-01-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="csv">
                        <i class="fas fa-file-csv"></i> Quick CSV
                    </a>
                    <a href="?download=1&format=excel&year=all&start_date=<?php echo date('Y-01-01'); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="excel">
                        <i class="fas fa-file-excel"></i> Quick Excel
                    </a>
                </div>
                
                <div class="notes-section">
                    <h3><i class="fas fa-info-circle"></i> Export Notes</h3>
                    <ul>
                        <li>CSV format works with Google Sheets, Excel, Numbers</li>
                        <li>Excel format preserves number formatting</li>
                        <li>Large exports may take a few seconds</li>
                        <li>Date filtering applies to expense dates</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <script src="../theme-manager.js"></script>
    <script>
        function selectFormat(format) {
            document.querySelectorAll('.format-option').forEach(el => {
                el.classList.remove('selected');
            });
            document.querySelector('.format-option.' + format).classList.add('selected');
            document.getElementById('formatInput').value = format;
        }
    </script>
</body>
</html>
