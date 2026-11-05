<?php
/**
 * Import Event Transactions from Excel
 * Import collection and expense data for events via .xlsx/.xls or .csv files
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['superadmin_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Get database connection
require_once '../../../config/config.php';

$success = '';
$error = '';
$importResults = [];
$faviconHtml = '';
$siteName = 'SR Colony';
$preSelectedEventId = intval($_GET['event'] ?? 0);

// Try to get logo-helper functions
try {
    require_once '../../../includes/logo-helper.php';
    $faviconHtml = renderFavicon();
    $siteName = getSiteName('SR Colony');
} catch (Exception $e) {
    // Continue without logo support
}

// Load PhpSpreadsheet for .xlsx / .xls files. The bundled version needs PHP 8.3+ (and the zip extension for .xlsx);
// on servers without them the page still works with CSV files, which is also what "Download Template" gives.
$vendorPath = __DIR__ . '/../../../vendor/autoload.php';
$excelSupported = PHP_VERSION_ID >= 80300 && file_exists($vendorPath);
if ($excelSupported) {
    require_once $vendorPath;
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Fetch events for dropdown
$eventsStmt = $pdo->query("SELECT EventID, EventName, EventDate, Status FROM events ORDER BY EventDate DESC");
$events = $eventsStmt ? $eventsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt ? $yearsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// ─── HANDLE TEMPLATE DOWNLOAD ───────────────────────────────────────────────
if (isset($_GET['download_template']) && !$error) {
    try {
        // Create a simple CSV template instead of complex Excel
        $templateData  = "Transaction Type *,Date *,Description *,Amount *,Payment Mode,Contributor / Donor Name,Contributor Contact,Vendor Name,Invoice Number,Receipt Number,Category,Remarks\n";
        $templateData .= "Collection," . date('Y-m-d') . ",Donation from Mr. Sharma,5000,Cash,Mr. Raj Sharma,9876543210,,,RCP-001,General Donation,Voluntary contribution\n";
        $templateData .= "Expense," . date('Y-m-d') . ",Catering Services,15000,Bank Transfer,,,Good Food Caterers,INV-2024-001,,Food & Beverages,Event day catering\n";

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="event_transactions_template.csv"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        
        echo $templateData;
        exit;

    } catch (Exception $e) {
        $error = 'Error generating template: ' . $e->getMessage();
    }
}

// ─── HANDLE IMPORT ──────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'import') {
    $selectedEventId = intval($_POST['event_id'] ?? 0);
    $selectedYearId = intval($_POST['year_id'] ?? 0);

    if ($selectedEventId <= 0) {
        $error = 'Please select an event to import transactions into.';
    } elseif (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        ];
        $code = $_FILES['excel_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $error = 'Upload error: ' . ($uploadErrors[$code] ?? 'Unknown error');
    } else {
        $allowedExtensions = ['xlsx', 'xls', 'csv'];
        $fileExt = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));

        if (!in_array($fileExt, $allowedExtensions)) {
            $error = 'Only .xlsx, .xls and .csv files are allowed.';
        } elseif ($fileExt !== 'csv' && !$excelSupported) {
            $error = 'This server cannot read Excel files (it needs PHP 8.3 or newer). Please save the sheet as CSV (File → Save As → CSV) and import the .csv file.';
        } else {
            try {
                // Rows of cells A..L, keyed by the sheet's row number (row 1 is the header)
                $sheetRows = [];
                if ($fileExt === 'csv') {
                    $fh = fopen($_FILES['excel_file']['tmp_name'], 'r');
                    if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh); // skip an Excel UTF-8 BOM
                    $n = 0;
                    while (($line = fgetcsv($fh)) !== false) {
                        $n++;
                        if ($n > 1) $sheetRows[$n] = array_pad(array_slice($line, 0, 12), 12, null);
                    }
                    fclose($fh);
                } else {
                    $sheet = IOFactory::load($_FILES['excel_file']['tmp_name'])->getActiveSheet();
                    for ($r = 2, $last = $sheet->getHighestRow(); $r <= $last; $r++) {
                        $sheetRows[$r] = [];
                        foreach (range('A', 'L') as $col) $sheetRows[$r][] = $sheet->getCell("$col$r")->getValue();
                    }
                }

                if (!$sheetRows) {
                    $error = 'The file has no data rows. Please add data below the header row.';
                } else {
                    $imported = 0;
                    $skipped = 0;
                    $errors = [];
                    $collectionTotal = 0;
                    $expenseTotal = 0;

                    $pdo->beginTransaction();

                    foreach ($sheetRows as $row => $cells) {
                        [$type, $dateRaw, $description, $amount, $paymentMode, $contributor, $contContact, $vendor, $invoice, $receipt, $category, $remarks] = $cells;
                        foreach (['type', 'description', 'paymentMode', 'contributor', 'contContact', 'vendor', 'invoice', 'receipt', 'category', 'remarks'] as $v) {
                            $$v = trim((string)($$v ?? ''));
                        }

                        // Skip completely empty rows
                        if (empty($type) && empty($description) && empty($amount)) {
                            continue;
                        }

                        // ── Validate Type ────────────────────────────────────
                        $type = ucfirst(strtolower($type));
                        if (!in_array($type, ['Collection', 'Expense'])) {
                            $errors[] = "Row $row: Invalid type '$type'. Must be Collection or Expense.";
                            $skipped++;
                            continue;
                        }

                        // ── Validate Description ─────────────────────────────
                        if (empty($description)) {
                            $errors[] = "Row $row: Description is required.";
                            $skipped++;
                            continue;
                        }

                        // ── Validate Amount ──────────────────────────────────
                        $amount = floatval(str_replace([',', '₹', '$', ' '], '', (string)($amount ?? '')));
                        if ($amount <= 0) {
                            $errors[] = "Row $row: Amount must be greater than 0.";
                            $skipped++;
                            continue;
                        }

                        // ── Parse Date ───────────────────────────────────────
                        $date = null;
                        if (is_numeric($dateRaw)) {
                            // Excel serial date number (days since 1899-12-30)
                            $date = gmdate('Y-m-d', (int)round(((float)$dateRaw - 25569) * 86400));
                        } elseif (!empty($dateRaw)) {
                            $parsed = strtotime($dateRaw);
                            $date = $parsed ? date('Y-m-d', $parsed) : null;
                        }
                        if (!$date) {
                            $errors[] = "Row $row: Invalid or missing date.";
                            $skipped++;
                            continue;
                        }

                        // ── Validate Payment Mode ────────────────────────────
                        $validModes = ['Cash', 'Bank Transfer', 'UPI', 'Cheque', 'Card', 'Online'];
                        if (empty($paymentMode) || !in_array($paymentMode, $validModes)) {
                            $paymentMode = 'Cash';
                        }

                        // ── Insert ───────────────────────────────────────────
                        $stmt = $pdo->prepare("INSERT INTO event_transactions
                            (EventID, TransactionType, TransactionDate, Description, Amount,
                             PaymentMode, ContributorName, ContributorContact, VendorName,
                             InvoiceNumber, ReceiptNumber, Category, Remarks, Status, CreatedBy)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Confirmed', ?)");

                        $stmt->execute([
                            $selectedEventId, $type, $date, $description, $amount,
                            $paymentMode,
                            $contributor ?: null,
                            $contContact ?: null,
                            $vendor ?: null,
                            $invoice ?: null,
                            $receipt ?: null,
                            $category ?: null,
                            $remarks ?: null,
                            $_SESSION['superadmin_id']
                        ]);

                        if ($type === 'Collection') {
                            $collectionTotal += $amount;
                        } else {
                            $expenseTotal += $amount;
                        }
                        $imported++;
                    }

                    // Update event totals
                    if ($imported > 0) {
                        $updateStmt = $pdo->prepare("UPDATE events SET
                            TotalCollection = TotalCollection + ?,
                            TotalExpense    = TotalExpense + ?,
                            EventBalance    = EventBalance + ? - ?,
                            UpdatedAt       = NOW()
                            WHERE EventID = ?");
                        $updateStmt->execute([$collectionTotal, $expenseTotal, $collectionTotal, $expenseTotal, $selectedEventId]);

                        // If year was selected, also update year totals
                        if ($selectedYearId > 0) {
                            // Collections to event don't affect main year totals,
                            // but expenses from events might. Only update if explicitly linked.
                        }
                    }

                    $pdo->commit();

                    // Audit log
                    try {
                        $logStmt = $pdo->prepare("INSERT INTO audit_logs
                            (UserType, UserID, Username, Action, TableName, IPAddress, UserAgent)
                            VALUES ('SuperAdmin', ?, ?, ?, 'event_transactions', ?, ?)");
                        $logStmt->execute([
                            $_SESSION['superadmin_id'],
                            $_SESSION['superadmin_name'] ?? 'SuperAdmin',
                            "Imported $imported event transactions (₹" . number_format($collectionTotal, 2) . " collection, ₹" . number_format($expenseTotal, 2) . " expense) via Excel",
                            $_SERVER['REMOTE_ADDR'],
                            $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                        ]);
                    } catch (Exception $logErr) {
                        // Non-critical – continue
                    }

                    $importResults = [
                        'imported'    => $imported,
                        'skipped'     => $skipped,
                        'collections' => $collectionTotal,
                        'expenses'    => $expenseTotal,
                        'errors'      => $errors
                    ];

                    if ($imported > 0) {
                        $success = "Successfully imported $imported transaction(s)! Collections: ₹" . number_format($collectionTotal, 2) . " | Expenses: ₹" . number_format($expenseTotal, 2);
                        if ($skipped > 0) {
                            $success .= " ($skipped row(s) skipped)";
                        }
                    } else {
                        $error = 'No transactions were imported. Please check the Excel format and data.';
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Import failed: ' . $e->getMessage();
                error_log("Import Event Transaction Error: " . $e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Event Transactions - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../shared-styles.css">
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

        .page-header h1 i { color: #8b5cf6; }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            margin-bottom: 20px;
            font-weight: 600;
            transition: color 0.2s;
        }
        .back-link:hover { color: #8b5cf6; }

        .import-container {
            max-width: 920px;
            margin: 0 auto;
        }

        .import-card {
            background: var(--bg-secondary);
            padding: 30px;
            border-radius: 14px;
            box-shadow: var(--shadow);
            margin-bottom: 28px;
        }

        .import-card h2 {
            font-size: 20px;
            color: var(--text-primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .import-card h2 i { color: #8b5cf6; }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
        }

        .form-group select:focus {
            border-color: #8b5cf6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        /* File upload */
        .file-upload-area {
            border: 2px dashed var(--border-color);
            border-radius: 14px;
            padding: 45px 30px;
            text-align: center;
            background: var(--bg-tertiary);
            transition: all 0.3s ease;
            cursor: pointer;
            margin-bottom: 20px;
        }

        .file-upload-area:hover,
        .file-upload-area.dragover {
            border-color: #8b5cf6;
            background: rgba(139, 92, 246, 0.06);
        }

        .file-upload-area .upload-icon {
            font-size: 50px;
            color: #8b5cf6;
            margin-bottom: 14px;
        }

        .file-upload-area h3 {
            font-size: 18px;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .file-upload-area p {
            color: var(--text-secondary);
            font-size: 14px;
        }

        .file-upload-area input[type="file"] { display: none; }

        .selected-file {
            display: none;
            margin-top: 14px;
            padding: 10px 16px;
            background: rgba(139, 92, 246, 0.1);
            border-radius: 8px;
            color: #8b5cf6;
            font-weight: 600;
            font-size: 14px;
        }

        /* Template section */
        .template-section {
            background: var(--bg-tertiary);
            padding: 22px;
            border-radius: 10px;
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
            margin-bottom: 14px;
        }

        .template-columns {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .column-tag {
            padding: 5px 12px;
            background: var(--bg-secondary);
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .column-tag.required {
            background: rgba(139, 92, 246, 0.15);
            color: #8b5cf6;
        }

        .btn-download-template {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 22px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-download-template:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(139, 92, 246, 0.35);
        }

        /* Import button */
        .btn-import {
            width: 100%;
            padding: 15px 30px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
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
            transition: all 0.3s ease;
        }

        .btn-import:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 18px rgba(139, 92, 246, 0.35);
        }

        .btn-import:disabled {
            background: var(--border-color);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* Results */
        .import-results {
            background: var(--bg-secondary);
            padding: 25px;
            border-radius: 14px;
            box-shadow: var(--shadow);
            margin-top: 24px;
        }

        .import-results h3 {
            font-size: 18px;
            color: var(--text-primary);
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .result-stat {
            background: var(--bg-tertiary);
            padding: 18px 14px;
            border-radius: 10px;
            text-align: center;
        }

        .result-stat .value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .result-stat .label {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .result-stat.success .value { color: #10b981; }
        .result-stat.collection .value { color: #3b82f6; }
        .result-stat.expense .value { color: #ef4444; }
        .result-stat.skipped .value { color: #f59e0b; }

        .error-list {
            max-height: 220px;
            overflow-y: auto;
            background: var(--bg-tertiary);
            padding: 14px;
            border-radius: 10px;
            list-style: none;
            margin: 0;
        }

        .error-list li {
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
            color: var(--text-secondary);
        }

        .error-list li:last-child { border-bottom: none; }

        .excel-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <main class="main-content">
        <a href="event-transactions.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Transactions
        </a>

        <div class="page-header">
            <h1>
                <i class="fas fa-file-import"></i>
                Import Event Transactions
                <span class="excel-badge"><i class="fas fa-file-excel"></i> Excel</span>
            </h1>
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

            <!-- Step 1: Download Template -->
            <div class="import-card">
                <h2><i class="fas fa-download"></i> Step 1: Download Template</h2>

                <div class="template-section">
                    <h3>Required Columns</h3>
                    <p>Your Excel file (.xlsx or .xls) or CSV must contain the following columns in order. Required fields are highlighted.</p>
                    <div class="template-columns">
                        <span class="column-tag required">Transaction Type *</span>
                        <span class="column-tag required">Date *</span>
                        <span class="column-tag required">Description *</span>
                        <span class="column-tag required">Amount *</span>
                        <span class="column-tag">Payment Mode</span>
                        <span class="column-tag">Contributor Name</span>
                        <span class="column-tag">Contributor Contact</span>
                        <span class="column-tag">Vendor Name</span>
                        <span class="column-tag">Invoice Number</span>
                        <span class="column-tag">Receipt Number</span>
                        <span class="column-tag">Category</span>
                        <span class="column-tag">Remarks</span>
                    </div>
                    <a href="?download_template=1" class="btn-download-template">
                        <i class="fas fa-download"></i> Download Template (.csv)
                    </a>
                </div>
            </div>

            <!-- Step 2: Select Event & Year -->
            <div class="import-card">
                <h2><i class="fas fa-upload"></i> Step 2: Upload & Import</h2>

                <form method="POST" enctype="multipart/form-data" id="importForm">
                    <input type="hidden" name="action" value="import">

                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Select Event <span style="color:#ef4444">*</span></label>
                            <select name="event_id" id="eventSelect" required>
                                <option value="">-- Choose Event --</option>
                                <?php foreach ($events as $evt): ?>
                                    <option value="<?php echo $evt['EventID']; ?>"
                                        <?php echo ((isset($_POST['event_id']) && $_POST['event_id'] == $evt['EventID']) || ($preSelectedEventId > 0 && $preSelectedEventId == $evt['EventID'])) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($evt['EventName']); ?>
                                        (<?php echo date('M Y', strtotime($evt['EventDate'])); ?> – <?php echo $evt['Status']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-graduation-cap"></i> Academic Year</label>
                            <select name="year_id">
                                <option value="">-- Optional --</option>
                                <?php foreach ($academicYears as $yr): ?>
                                    <option value="<?php echo $yr['YearID']; ?>"
                                        <?php echo (isset($_POST['year_id']) && $_POST['year_id'] == $yr['YearID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($yr['YearName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="file-upload-area" id="dropZone" onclick="document.getElementById('excelFile').click()">
                        <div class="upload-icon"><i class="fas fa-file-excel"></i></div>
                        <h3>Drop Excel or CSV file here or click to browse</h3>
                        <p>Accepts <strong>.xlsx</strong>, <strong>.xls</strong> and <strong>.csv</strong> files (max 5 MB)<?php if (!$excelSupported): ?><br><small>This server reads CSV only – save Excel sheets as CSV first.</small><?php endif; ?></p>
                        <input type="file" name="excel_file" id="excelFile" <?php echo $excelSupported ? 'accept=".xlsx,.xls,.csv"' : 'accept=".csv"'; ?> required>
                        <div class="selected-file" id="selectedFile"></div>
                    </div>

                    <button type="submit" class="btn-import" id="importBtn" disabled>
                        <i class="fas fa-file-import"></i> Import Transactions
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
                        <div class="result-stat collection">
                            <div class="value">₹<?php echo number_format($importResults['collections'], 0); ?></div>
                            <div class="label">Collections</div>
                        </div>
                        <div class="result-stat expense">
                            <div class="value">₹<?php echo number_format($importResults['expenses'], 0); ?></div>
                            <div class="label">Expenses</div>
                        </div>
                        <div class="result-stat skipped">
                            <div class="value"><?php echo $importResults['skipped']; ?></div>
                            <div class="label">Skipped</div>
                        </div>
                    </div>

                    <?php if (!empty($importResults['errors'])): ?>
                        <h4 style="color: var(--text-secondary); margin-bottom: 10px; font-size: 14px;">
                            <i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i> Validation Errors:
                        </h4>
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

    <script src="../../theme-manager.js"></script>
    <script>
        const dropZone  = document.getElementById('dropZone');
        const fileInput = document.getElementById('excelFile');
        const selected  = document.getElementById('selectedFile');
        const importBtn = document.getElementById('importBtn');

        // Drag & drop
        dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
        dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
        dropZone.addEventListener('drop', e => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            const f = e.dataTransfer.files;
            if (f.length && /\.(xlsx|xls|csv)$/i.test(f[0].name)) {
                fileInput.files = f;
                showFile(f[0]);
            } else {
                alert('Please drop an Excel or CSV file (.xlsx, .xls or .csv)');
            }
        });

        fileInput.addEventListener('change', function () {
            if (this.files.length) showFile(this.files[0]);
        });

        function showFile(file) {
            const size = file.size < 1024 ? file.size + ' B'
                       : file.size < 1048576 ? (file.size / 1024).toFixed(1) + ' KB'
                       : (file.size / 1048576).toFixed(1) + ' MB';
            selected.innerHTML = '<i class="fas fa-file-excel"></i> ' + file.name + ' (' + size + ')';
            selected.style.display = 'block';
            importBtn.disabled = false;
        }

        document.getElementById('importForm').addEventListener('submit', function (e) {
            if (!document.getElementById('eventSelect').value) {
                e.preventDefault();
                alert('Please select an event first.');
                return;
            }
            if (!fileInput.files.length) {
                e.preventDefault();
                alert('Please select an Excel or CSV file.');
                return;
            }
            importBtn.disabled = true;
            importBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
        });
    </script>
</body>
</html>
