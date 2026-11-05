<?php
/**
 * Import Expenses Page
 * Import expense data from CSV file
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/link-target-helper.php';
require_once '../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

$success = '';
$error = '';
$importResults = [];

// Check if expenses table exists
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'expenses'");
if ($tableCheckStmt->rowCount() === 0) {
    header('Location: setup-expenses.php');
    exit;
}

// Fetch academic years for mapping
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);
$yearMap = [];
foreach ($academicYears as $year) {
    $yearMap[strtolower(trim($year['YearName']))] = $year['YearID'];
}

// Fetch expense categories
$categoriesStmt = $pdo->query("SELECT CategoryID, CategoryName FROM expense_categories WHERE IsActive = 1 ORDER BY SortOrder, CategoryName");
$expenseCategories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="expense_import_template.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Headers
    fputcsv($output, [
        'Date',
        'Description',
        'Category',
        'SubCategory',
        'Amount',
        'Payment_Mode',
        'Vendor',
        'Invoice',
        'Remarks',
        'Year'
    ]);
    
    // Sample rows
    fputcsv($output, [
        '2024-01-15',
        'Office Supplies',
        'Administrative',
        'Stationery',
        '1500.00',
        'Cash',
        'Local Store',
        'INV-001',
        'Monthly supplies',
        '2024-2025'
    ]);
    
    fputcsv($output, [
        '2024-01-20',
        'Electrical Repair',
        'Maintenance',
        'Repairs',
        '3500.00',
        'Bank Transfer',
        'ABC Electricals',
        'INV-002',
        'Common area lights',
        '2024-2025'
    ]);
    
    fclose($output);
    exit;
}

// Handle import
if (isset($_POST['action']) && $_POST['action'] === 'import') {
    $defaultYearId = intval($_POST['default_year_id'] ?? 0);
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please select a valid CSV file to import';
    } elseif ($defaultYearId <= 0) {
        $error = 'Please select a default academic year';
    } else {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');
        
        if ($handle === false) {
            $error = 'Unable to read the uploaded file';
        } else {
            // Skip BOM if present
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            
            // Read header row
            $headers = fgetcsv($handle);
            if ($headers === false) {
                $error = 'Unable to read CSV headers';
            } else {
                // Map headers to expected columns
                $headerMap = [];
                $expectedHeaders = [
                    'date' => ['date', 'expense_date', 'expensedate', 'expense date'],
                    'category' => ['category', 'expense_category', 'type'],
                    'subcategory' => ['subcategory', 'sub_category', 'sub category'],
                    'description' => ['description', 'desc', 'details', 'expense_description'],
                    'amount' => ['amount', 'expense_amount', 'value', 'total'],
                    'payment_mode' => ['payment_mode', 'paymentmode', 'payment mode', 'mode', 'payment'],
                    'vendor' => ['vendor', 'vendor_name', 'vendorname', 'supplier', 'vendor name'],
                    'invoice' => ['invoice', 'invoice_number', 'invoicenumber', 'invoice number', 'invoice no'],
                    'remarks' => ['remarks', 'notes', 'comment', 'comments'],
                    'year' => ['year', 'academic_year', 'academicyear', 'academic year']
                ];
                
                foreach ($headers as $index => $header) {
                    $normalizedHeader = strtolower(trim($header));
                    foreach ($expectedHeaders as $key => $variations) {
                        if (in_array($normalizedHeader, $variations)) {
                            $headerMap[$key] = $index;
                            break;
                        }
                    }
                }
                
                // Check for required columns
                if (!isset($headerMap['description']) || !isset($headerMap['amount'])) {
                    $error = 'CSV must contain at least "Description" and "Amount" columns';
                } else {
                    $rowNumber = 1;
                    $imported = 0;
                    $skipped = 0;
                    $errors = [];
                    
                    try {
                        $pdo->beginTransaction();
                        
                        while (($row = fgetcsv($handle)) !== false) {
                            $rowNumber++;
                            
                            // Skip empty rows
                            if (empty(array_filter($row))) {
                                continue;
                            }
                            
                            // Extract values
                            $description = trim($row[$headerMap['description']] ?? '');
                            $amount = floatval(str_replace([',', '₹', '$'], '', $row[$headerMap['amount']] ?? 0));
                            $date = isset($headerMap['date']) ? trim($row[$headerMap['date']] ?? '') : date('Y-m-d');
                            $category = isset($headerMap['category']) ? trim($row[$headerMap['category']] ?? '') : 'General';
                            $subcategory = isset($headerMap['subcategory']) ? trim($row[$headerMap['subcategory']] ?? '') : '';
                            $paymentMode = isset($headerMap['payment_mode']) ? trim($row[$headerMap['payment_mode']] ?? '') : 'Cash';
                            $vendor = isset($headerMap['vendor']) ? trim($row[$headerMap['vendor']] ?? '') : '';
                            $invoice = isset($headerMap['invoice']) ? trim($row[$headerMap['invoice']] ?? '') : '';
                            $remarks = isset($headerMap['remarks']) ? trim($row[$headerMap['remarks']] ?? '') : '';
                            $yearName = isset($headerMap['year']) ? strtolower(trim($row[$headerMap['year']] ?? '')) : '';
                            
                            // Determine year ID
                            $yearId = $defaultYearId;
                            if (!empty($yearName) && isset($yearMap[$yearName])) {
                                $yearId = $yearMap[$yearName];
                            }
                            
                            // Validate
                            if (empty($description)) {
                                $errors[] = "Row $rowNumber: Description is required";
                                $skipped++;
                                continue;
                            }
                            
                            if ($amount <= 0) {
                                $errors[] = "Row $rowNumber: Amount must be greater than 0";
                                $skipped++;
                                continue;
                            }
                            
                            // Parse date
                            $parsedDate = strtotime($date);
                            if ($parsedDate === false) {
                                $date = date('Y-m-d');
                            } else {
                                $date = date('Y-m-d', $parsedDate);
                            }
                            
                            // Default category if empty
                            if (empty($category)) {
                                $category = 'General';
                            }
                            
                            // Validate payment mode
                            $validPaymentModes = ['Cash', 'Bank Transfer', 'UPI', 'Cheque', 'Card', 'Online'];
                            if (!in_array($paymentMode, $validPaymentModes)) {
                                $paymentMode = 'Cash';
                            }
                            
                            // Insert expense
                            $stmt = $pdo->prepare("
                                INSERT INTO expenses 
                                (YearID, ExpenseDate, Category, SubCategory, Description, Amount, PaymentMode, 
                                 VendorName, InvoiceNumber, Remarks, Status, CreatedBy, ApprovedBy, ApprovedAt) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved', ?, ?, NOW())
                            ");
                            
                            $stmt->execute([
                                $yearId, $date, $category, $subcategory ?: null, $description, $amount,
                                $paymentMode, $vendor ?: null, $invoice ?: null, $remarks ?: null,
                                $_SESSION['superadmin_id'], $_SESSION['superadmin_id']
                            ]);
                            
                            // Update academic year totals
                            $updateYearStmt = $pdo->prepare("
                                UPDATE academic_years 
                                SET TotalExpenses = TotalExpenses + ?, 
                                    BalanceAmount = TotalCollection - (TotalExpenses + ?)
                                WHERE YearID = ?
                            ");
                            $updateYearStmt->execute([$amount, $amount, $yearId]);
                            
                            $imported++;
                        }
                        
                        // Commit transaction
                        $pdo->commit();
                        
                        // Log the import
                        $logStmt = $pdo->prepare("
                            INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, IPAddress, UserAgent) 
                            VALUES ('SuperAdmin', ?, ?, ?, 'expenses', ?, ?)
                        ");
                        $logStmt->execute([
                            $_SESSION['superadmin_id'],
                            $_SESSION['superadmin_name'] ?? 'SuperAdmin',
                            "Imported $imported expenses from CSV file",
                            $_SERVER['REMOTE_ADDR'],
                            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                        ]);
                        
                        $importResults = [
                            'imported' => $imported,
                            'skipped' => $skipped,
                            'errors' => $errors
                        ];
                        
                        if ($imported > 0) {
                            $success = "Successfully imported $imported expense(s)!";
                            if ($skipped > 0) {
                                $success .= " ($skipped row(s) skipped due to validation errors)";
                            }
                        } else {
                            $error = 'No expenses were imported. Please check the CSV format.';
                        }
                        
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = 'Import failed: ' . $e->getMessage();
                        error_log("Import Expense Error: " . $e->getMessage());
                    }
                }
            }
            
            fclose($handle);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Expenses - <?php echo htmlspecialchars($siteName); ?></title>
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
        
        .import-container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .import-card {
            background: var(--bg-secondary);
            padding: 30px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
        }
        
        .import-card h2 {
            font-size: 20px;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .import-card h2 i {
            color: #10b981;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }
        
        .form-group select,
        .form-group input[type="file"] {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
        }
        
        .form-group select:focus,
        .form-group input:focus {
            border-color: #10b981;
            outline: none;
        }
        
        .file-upload-area {
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            background: var(--bg-tertiary);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .file-upload-area:hover,
        .file-upload-area.dragover {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.1);
        }
        
        .file-upload-area i {
            font-size: 48px;
            color: #10b981;
            margin-bottom: 15px;
        }
        
        .file-upload-area h3 {
            font-size: 18px;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .file-upload-area p {
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .file-upload-area input[type="file"] {
            display: none;
        }
        
        .selected-file {
            display: none;
            margin-top: 15px;
            padding: 10px 15px;
            background: rgba(16, 185, 129, 0.1);
            border-radius: 8px;
            color: #10b981;
            font-weight: 600;
        }
        
        .btn-import {
            width: 100%;
            padding: 15px 30px;
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            border-radius: 8px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        
        .btn-import:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }
        
        .btn-import:disabled {
            background: var(--border-color);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--bg-tertiary);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-back:hover {
            border-color: #10b981;
            color: #10b981;
        }
        
        .template-section {
            background: var(--bg-tertiary);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .template-section h3 {
            font-size: 16px;
            color: var(--text-primary);
            margin-bottom: 10px;
        }
        
        .template-section p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .template-columns {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .column-tag {
            padding: 6px 12px;
            background: var(--bg-secondary);
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .column-tag.required {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
        }
        
        .btn-download-template {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: rgba(59, 130, 246, 0.2);
            border: none;
            border-radius: 8px;
            color: #3b82f6;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .btn-download-template:hover {
            background: #3b82f6;
            color: white;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.2);
            color: #10b981;
            border-left: 4px solid #10b981;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            border-left: 4px solid #ef4444;
        }
        
        .import-results {
            background: var(--bg-secondary);
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
        }
        
        .import-results h3 {
            font-size: 18px;
            color: var(--text-primary);
            margin-bottom: 15px;
        }
        
        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .result-stat {
            background: var(--bg-tertiary);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .result-stat .value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .result-stat .label {
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .result-stat.success .value { color: #10b981; }
        .result-stat.skipped .value { color: #f59e0b; }
        .result-stat.error .value { color: #ef4444; }
        
        .error-list {
            max-height: 200px;
            overflow-y: auto;
            background: var(--bg-tertiary);
            padding: 15px;
            border-radius: 8px;
        }
        
        .error-list li {
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .error-list li:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>
    <?php include '../sidebar.php'; ?>
    
    <main class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-file-import"></i> Import Expenses</h1>
            <a href="manage-expenses.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Expenses
            </a>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <div class="import-container">
            <!-- Template Information -->
            <div class="import-card">
                <h2><i class="fas fa-info-circle"></i> CSV Format Requirements</h2>
                
                <div class="template-section">
                    <h3>Expected Columns</h3>
                    <p>Your CSV file should include the following columns. Required columns are highlighted.</p>
                    <div class="template-columns">
                        <span class="column-tag required">Description *</span>
                        <span class="column-tag required">Amount *</span>
                        <span class="column-tag">Date</span>
                        <span class="column-tag">Category</span>
                        <span class="column-tag">SubCategory</span>
                        <span class="column-tag">Payment_Mode</span>
                        <span class="column-tag">Vendor</span>
                        <span class="column-tag">Invoice</span>
                        <span class="column-tag">Remarks</span>
                        <span class="column-tag">Year</span>
                    </div>
                </div>
                
                <div class="template-section">
                    <h3>Download Template</h3>
                    <p>Download a sample CSV template to ensure your data is formatted correctly.</p>
                    <a href="?download_template=1" class="btn-download-template">
                        <i class="fas fa-download"></i> Download CSV Template
                    </a>
                </div>
            </div>
            
            <!-- Import Form -->
            <div class="import-card">
                <h2><i class="fas fa-upload"></i> Upload CSV File</h2>
                
                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <input type="hidden" name="action" value="import">
                    
                    <div class="form-group">
                        <label>Default Academic Year</label>
                        <select name="default_year_id" required>
                            <option value="">Select Academic Year</option>
                            <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['YearID']; ?>">
                                    <?php echo htmlspecialchars($year['YearName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-secondary); display: block; margin-top: 5px;">
                            Used if the Year column is empty or missing in CSV
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label>CSV File</label>
                        <div class="file-upload-area" onclick="document.getElementById('csvFile').click()" id="dropZone">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <h3>Drop CSV file here or click to browse</h3>
                            <p>Accepts .csv files up to 5MB</p>
                            <input type="file" name="csv_file" id="csvFile" accept=".csv" required>
                            <div class="selected-file" id="selectedFile"></div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-import" id="importBtn" disabled>
                        <i class="fas fa-file-import"></i> Import Expenses
                    </button>
                </form>
            </div>
            
            <?php if (!empty($importResults)): ?>
                <div class="import-results">
                    <h3><i class="fas fa-chart-bar"></i> Import Results</h3>
                    
                    <div class="results-grid">
                        <div class="result-stat success">
                            <div class="value"><?php echo $importResults['imported']; ?></div>
                            <div class="label">Imported</div>
                        </div>
                        <div class="result-stat skipped">
                            <div class="value"><?php echo $importResults['skipped']; ?></div>
                            <div class="label">Skipped</div>
                        </div>
                    </div>
                    
                    <?php if (!empty($importResults['errors'])): ?>
                        <h4 style="color: var(--text-secondary); margin-bottom: 10px;">Validation Errors:</h4>
                        <ul class="error-list">
                            <?php foreach ($importResults['errors'] as $err): ?>
                                <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
    
    <script>
        // File upload handling
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('csvFile');
        const selectedFile = document.getElementById('selectedFile');
        const importBtn = document.getElementById('importBtn');
        
        // Drag and drop
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });
        
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0 && files[0].name.endsWith('.csv')) {
                fileInput.files = files;
                updateSelectedFile(files[0]);
            }
        });
        
        // File input change
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                updateSelectedFile(this.files[0]);
            }
        });
        
        function updateSelectedFile(file) {
            selectedFile.textContent = '📄 ' + file.name + ' (' + formatFileSize(file.size) + ')';
            selectedFile.style.display = 'block';
            importBtn.disabled = false;
        }
        
        function formatFileSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }
        
        // Form validation
        document.getElementById('importForm').addEventListener('submit', function(e) {
            const yearSelect = document.querySelector('select[name="default_year_id"]');
            if (!yearSelect.value) {
                e.preventDefault();
                alert('Please select a default academic year');
                return;
            }
            
            if (!fileInput.files.length) {
                e.preventDefault();
                alert('Please select a CSV file');
                return;
            }
            
            importBtn.disabled = true;
            importBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
        });
    </script>
</body>
</html>
