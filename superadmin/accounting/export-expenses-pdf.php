<?php
/**
 * Export Expenses PDF
 * Generates a beautifully styled, print-ready HTML page for PDF export
 * Uses browser print (Ctrl+P / Cmd+P) for PDF generation
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/logo-helper.php';

$siteName = getSiteName('SR Colony');

// Get filter parameters
$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$category = isset($_GET['category']) ? $_GET['category'] : 'all';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);
$yearMap = [];
foreach ($academicYears as $y) $yearMap[$y['YearID']] = $y['YearName'];

// Build query
$whereConditions = [];
$params = [];

if ($selectedYear !== 'all') {
    $whereConditions[] = "e.YearID = ?";
    $params[] = $selectedYear;
}

if (!empty($startDate)) {
    $whereConditions[] = "e.ExpenseDate >= ?";
    $params[] = $startDate;
}

if (!empty($endDate)) {
    $whereConditions[] = "e.ExpenseDate <= ?";
    $params[] = $endDate;
}

if ($category !== 'all' && !empty($category)) {
    $whereConditions[] = "e.Category = ?";
    $params[] = $category;
}

if ($status !== 'all' && !empty($status)) {
    $whereConditions[] = "e.Status = ?";
    $params[] = $status;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$query = "SELECT 
    e.ExpenseID,
    ay.YearName as AcademicYear,
    DATE_FORMAT(e.ExpenseDate, '%d %b %Y') as FormattedDate,
    e.ExpenseDate,
    e.Category,
    e.SubCategory,
    e.Description,
    e.Amount,
    e.PaymentMode,
    e.VendorName,
    e.InvoiceNumber,
    e.Status,
    e.Remarks
FROM expenses e
LEFT JOIN academic_years ay ON e.YearID = ay.YearID
$whereClause
ORDER BY e.ExpenseDate DESC, e.ExpenseID DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalAmount = array_sum(array_column($expenses, 'Amount'));
$totalCount = count($expenses);

// Category-wise summary
$categorySummary = [];
foreach ($expenses as $exp) {
    $cat = $exp['Category'];
    if (!isset($categorySummary[$cat])) {
        $categorySummary[$cat] = ['count' => 0, 'amount' => 0];
    }
    $categorySummary[$cat]['count']++;
    $categorySummary[$cat]['amount'] += $exp['Amount'];
}
arsort(array_column($categorySummary, 'amount'));

// Payment mode summary
$paymentSummary = [];
foreach ($expenses as $exp) {
    $mode = $exp['PaymentMode'];
    if (!isset($paymentSummary[$mode])) {
        $paymentSummary[$mode] = ['count' => 0, 'amount' => 0];
    }
    $paymentSummary[$mode]['count']++;
    $paymentSummary[$mode]['amount'] += $exp['Amount'];
}

// Status summary
$statusSummary = [];
foreach ($expenses as $exp) {
    $st = $exp['Status'];
    if (!isset($statusSummary[$st])) {
        $statusSummary[$st] = ['count' => 0, 'amount' => 0];
    }
    $statusSummary[$st]['count']++;
    $statusSummary[$st]['amount'] += $exp['Amount'];
}

// Net balance calculation (same as dashboard)
if ($selectedYear !== 'all') {
    $stmt2 = $pdo->prepare("SELECT IFNULL(SUM(InterestAmount), 0) as total FROM transactions WHERE YearID = ?");
    $stmt2->execute([$selectedYear]);
    $totalInterest = $stmt2->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt2 = $pdo->prepare("SELECT IFNULL(FixedAmount, 0) as FixedAmount, IFNULL(ChangeAmount, 0) as ChangeAmount FROM academic_years WHERE YearID = ?");
    $stmt2->execute([$selectedYear]);
    $yearData = $stmt2->fetch(PDO::FETCH_ASSOC);
    $totalFixedAmount = $yearData['FixedAmount'] ?? 0;
    $totalChangeAmount = $yearData['ChangeAmount'] ?? 0;

    $stmt2 = $pdo->prepare("SELECT IFNULL(SUM(CollectionAmount), 0) as total FROM transactions WHERE YearID = ?");
    $stmt2->execute([$selectedYear]);
    $totalCollection = $stmt2->fetch(PDO::FETCH_ASSOC)['total'];
} else {
    $stmt2 = $pdo->query("SELECT IFNULL(SUM(InterestAmount), 0) as total FROM transactions");
    $totalInterest = $stmt2->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt2 = $pdo->query("SELECT IFNULL(SUM(FixedAmount), 0) as FixedAmount, IFNULL(SUM(ChangeAmount), 0) as ChangeAmount FROM academic_years");
    $yearData = $stmt2->fetch(PDO::FETCH_ASSOC);
    $totalFixedAmount = $yearData['FixedAmount'];
    $totalChangeAmount = $yearData['ChangeAmount'];

    $stmt2 = $pdo->query("SELECT IFNULL(SUM(CollectionAmount), 0) as total FROM transactions");
    $totalCollection = $stmt2->fetch(PDO::FETCH_ASSOC)['total'];
}

$netBalance = ($totalInterest + $totalFixedAmount + $totalChangeAmount) - $totalAmount;

// Filter description
$filterDesc = [];
if ($selectedYear !== 'all') $filterDesc[] = 'Year: ' . ($yearMap[$selectedYear] ?? $selectedYear);
if (!empty($startDate)) $filterDesc[] = 'From: ' . date('d M Y', strtotime($startDate));
if (!empty($endDate)) $filterDesc[] = 'To: ' . date('d M Y', strtotime($endDate));
if ($category !== 'all') $filterDesc[] = 'Category: ' . $category;
if ($status !== 'all') $filterDesc[] = 'Status: ' . $status;
$filterText = !empty($filterDesc) ? implode(' | ', $filterDesc) : 'All Records';

// Running total
$runningTotal = 0;
$reversedExpenses = array_reverse($expenses);
$expensesWithRunning = [];
foreach ($reversedExpenses as $exp) {
    $runningTotal += $exp['Amount'];
    $exp['RunningTotal'] = $runningTotal;
    $expensesWithRunning[] = $exp;
}
$expenses = array_reverse($expensesWithRunning);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Expense Report - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        /* ===== RESET & BASE ===== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1e293b;
            background: #f8fafc;
            line-height: 1.5;
            font-size: 13px;
        }

        /* ===== SCREEN-ONLY CONTROLS ===== */
        .no-print {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #0f172a, #1e293b);
            padding: 14px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }

        .no-print .title {
            color: #e2e8f0;
            font-size: 16px;
            font-weight: 600;
        }

        .no-print .actions {
            display: flex;
            gap: 12px;
        }

        .no-print button {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-print {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-print:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }

        .btn-back {
            background: rgba(255,255,255,0.1);
            color: #e2e8f0;
            border: 1px solid rgba(255,255,255,0.2) !important;
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.2);
        }

        /* ===== REPORT CONTAINER ===== */
        .report {
            max-width: 1050px;
            margin: 80px auto 40px;
            background: white;
            box-shadow: 0 4px 30px rgba(0,0,0,0.08);
            border-radius: 12px;
            overflow: hidden;
        }

        /* ===== HEADER ===== */
        .report-header {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #0f172a 100%);
            color: white;
            padding: 35px 40px;
            position: relative;
            overflow: hidden;
        }

        .report-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(16,185,129,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }

        .report-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -5%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(59,130,246,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .header-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .header-left h1 {
            font-size: 26px;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 4px;
        }

        .header-left .org-name {
            font-size: 15px;
            color: #94a3b8;
            font-weight: 500;
        }

        .header-right {
            text-align: right;
        }

        .header-right .report-type {
            display: inline-block;
            background: rgba(16, 185, 129, 0.2);
            color: #6ee7b7;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .header-right .report-date {
            color: #94a3b8;
            font-size: 12px;
        }

        .filter-bar {
            background: rgba(255,255,255,0.06);
            padding: 12px 40px;
            border-top: 1px solid rgba(255,255,255,0.08);
            color: #94a3b8;
            font-size: 12px;
            position: relative;
            z-index: 1;
        }

        .filter-bar span {
            color: #e2e8f0;
            font-weight: 600;
        }

        /* ===== SUMMARY CARDS ===== */
        .summary-section {
            padding: 30px 40px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }

        .summary-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }

        .summary-card.expenses::before { background: linear-gradient(90deg, #ef4444, #f97316); }
        .summary-card.records::before { background: linear-gradient(90deg, #3b82f6, #6366f1); }
        .summary-card.collection::before { background: linear-gradient(90deg, #10b981, #14b8a6); }
        .summary-card.balance::before { background: linear-gradient(90deg, #8b5cf6, #a855f7); }

        .summary-card .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .summary-card .value {
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
        }

        .summary-card.expenses .value { color: #ef4444; }
        .summary-card.balance .value.positive { color: #10b981; }
        .summary-card.balance .value.negative { color: #ef4444; }

        /* ===== BREAKDOWN SECTION ===== */
        .breakdown-section {
            padding: 25px 40px;
            border-bottom: 1px solid #e2e8f0;
        }

        .breakdown-section h3 {
            font-size: 14px;
            color: #0f172a;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .breakdown-section h3 .icon {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
        }

        .breakdown-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .breakdown-table th {
            background: #f1f5f9;
            padding: 8px 12px;
            text-align: left;
            font-weight: 700;
            color: #475569;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border-bottom: 2px solid #e2e8f0;
        }

        .breakdown-table th:last-child,
        .breakdown-table td:last-child {
            text-align: right;
        }

        .breakdown-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }

        .breakdown-table tr:last-child td {
            border-bottom: none;
        }

        .breakdown-table .amount {
            font-weight: 700;
            font-family: 'Courier New', monospace;
        }

        .bar-container {
            height: 4px;
            background: #e2e8f0;
            border-radius: 4px;
            margin-top: 4px;
            overflow: hidden;
        }

        .bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s;
        }

        .bar-fill.cat { background: linear-gradient(90deg, #6366f1, #8b5cf6); }
        .bar-fill.mode { background: linear-gradient(90deg, #10b981, #14b8a6); }

        /* ===== EXPENSES TABLE ===== */
        .table-section {
            padding: 0 40px 30px;
        }

        .table-header {
            padding: 25px 0 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            font-size: 16px;
            color: #0f172a;
            font-weight: 700;
        }

        .table-header .record-count {
            font-size: 12px;
            color: #64748b;
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 12px;
        }

        .expense-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }

        .expense-table thead th {
            background: #0f172a;
            color: #e2e8f0;
            padding: 12px 10px;
            text-align: left;
            font-weight: 700;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .expense-table thead th:first-child {
            border-radius: 8px 0 0 0;
        }

        .expense-table thead th:last-child {
            border-radius: 0 8px 0 0;
        }

        .expense-table tbody tr {
            border-bottom: 1px solid #f1f5f9;
        }

        .expense-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        .expense-table tbody tr:hover {
            background: #eff6ff;
        }

        .expense-table td {
            padding: 10px;
            color: #334155;
            vertical-align: top;
        }

        .expense-table .sr-no {
            color: #94a3b8;
            font-weight: 600;
            text-align: center;
            width: 40px;
        }

        .expense-table .date-cell {
            white-space: nowrap;
            font-weight: 500;
            color: #1e293b;
        }

        .expense-table .desc-cell {
            max-width: 220px;
            font-weight: 500;
            color: #0f172a;
        }

        .expense-table .desc-cell .vendor {
            font-size: 10px;
            color: #64748b;
            font-weight: 400;
            margin-top: 2px;
        }

        .expense-table .cat-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            background: #ede9fe;
            color: #6d28d9;
            white-space: nowrap;
        }

        .expense-table .amount-cell {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            color: #0f172a;
            text-align: right;
            white-space: nowrap;
        }

        .expense-table .running-cell {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: #7c3aed;
            text-align: right;
            white-space: nowrap;
            font-size: 11px;
        }

        .expense-table .mode-cell {
            font-size: 11px;
            color: #64748b;
        }

        .expense-table .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-badge.approved { background: #dcfce7; color: #166534; }
        .status-badge.pending { background: #fef3c7; color: #92400e; }
        .status-badge.rejected { background: #fee2e2; color: #991b1b; }
        .status-badge.cancelled { background: #f1f5f9; color: #475569; }

        /* Total row */
        .expense-table tfoot td {
            padding: 14px 10px;
            background: #0f172a;
            color: #e2e8f0;
            font-weight: 800;
            font-size: 13px;
        }

        .expense-table tfoot .total-amount {
            font-family: 'Courier New', monospace;
            color: #fbbf24;
            font-size: 15px;
            text-align: right;
        }

        /* ===== FOOTER ===== */
        .report-footer {
            padding: 25px 40px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .footer-left {
            font-size: 11px;
            color: #94a3b8;
        }

        .footer-left .generated {
            margin-bottom: 3px;
        }

        .footer-right {
            text-align: right;
            font-size: 11px;
            color: #94a3b8;
        }

        .watermark {
            text-align: center;
            padding: 15px;
            font-size: 10px;
            color: #94a3b8;
        }

        /* ===== PRINT STYLES ===== */
        @media print {
            body {
                background: white;
                font-size: 11px;
            }

            .no-print {
                display: none !important;
            }

            .report {
                margin: 0;
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
            }

            .report-header {
                padding: 25px 30px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .summary-section {
                padding: 20px 30px;
            }

            .breakdown-section {
                padding: 20px 30px;
            }

            .table-section {
                padding: 0 30px 20px;
            }

            .summary-card {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .summary-card::before {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .expense-table thead th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .expense-table tfoot td {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .expense-table tbody tr:nth-child(even) {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .status-badge, .cat-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .bar-fill, .bar-container {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .report-footer {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .expense-table {
                page-break-inside: auto;
            }

            .expense-table tr {
                page-break-inside: avoid;
            }

            .expense-table thead {
                display: table-header-group;
            }

            .expense-table tfoot {
                display: table-footer-group;
            }

            @page {
                size: A4 landscape;
                margin: 10mm;
            }
        }

        /* ===== RESPONSIVE ===== */
        @media screen and (max-width: 768px) {
            .report { margin: 70px 10px 20px; }
            .summary-grid { grid-template-columns: repeat(2, 1fr); }
            .breakdown-grid { grid-template-columns: 1fr; }
            .header-content { flex-direction: column; gap: 15px; }
            .header-right { text-align: left; }
        }
    </style>
</head>
<body>
    <!-- Screen-only toolbar -->
    <div class="no-print">
        <div class="title">📊 Expense Report Preview</div>
        <div class="actions">
            <button class="btn-back" onclick="window.history.back()">← Back</button>
            <button class="btn-print" onclick="window.print()">🖨️ Print / Save PDF</button>
        </div>
    </div>

    <div class="report">
        <!-- ===== HEADER ===== -->
        <div class="report-header">
            <div class="header-content">
                <div class="header-left">
                    <h1><?php echo htmlspecialchars($siteName); ?></h1>
                    <div class="org-name">Expense Management Report</div>
                </div>
                <div class="header-right">
                    <div class="report-type">Expense Report</div>
                    <div class="report-date">
                        Generated: <?php echo date('d M Y, h:i A'); ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="filter-bar">
            <span>Filters:</span> <?php echo htmlspecialchars($filterText); ?>
        </div>

        <!-- ===== SUMMARY CARDS ===== -->
        <div class="summary-section">
            <div class="summary-grid">
                <div class="summary-card expenses">
                    <div class="label">Total Expenses</div>
                    <div class="value">₹<?php echo number_format($totalAmount, 2); ?></div>
                </div>
                <div class="summary-card records">
                    <div class="label">Total Records</div>
                    <div class="value"><?php echo number_format($totalCount); ?></div>
                </div>
                <!-- <div class="summary-card collection">
                    <div class="label">Total Collection</div>
                    <div class="value">₹<?php echo number_format($totalCollection, 2); ?></div>
                </div> -->
                <div class="summary-card balance">
                    <div class="label">Net Balance</div>
                    <div class="value <?php echo $netBalance >= 0 ? 'positive' : 'negative'; ?>">
                        ₹<?php echo number_format($netBalance, 2); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== CATEGORY & PAYMENT BREAKDOWN ===== -->
        <div class="breakdown-section">
            <div class="breakdown-grid">
                <div>
                    <h3><span class="icon" style="background: #6366f1;">📂</span> Category Breakdown</h3>
                    <table class="breakdown-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Count</th>
                                <th>Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categorySummary as $cat => $data): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($cat); ?>
                                    <div class="bar-container">
                                        <div class="bar-fill cat" style="width: <?php echo $totalAmount > 0 ? round(($data['amount'] / $totalAmount) * 100) : 0; ?>%;"></div>
                                    </div>
                                </td>
                                <td><?php echo $data['count']; ?></td>
                                <td class="amount"><?php echo number_format($data['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3><span class="icon" style="background: #10b981;">💳</span> Payment Mode Breakdown</h3>
                    <table class="breakdown-table">
                        <thead>
                            <tr>
                                <th>Mode</th>
                                <th>Count</th>
                                <th>Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paymentSummary as $mode => $data): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($mode); ?>
                                    <div class="bar-container">
                                        <div class="bar-fill mode" style="width: <?php echo $totalAmount > 0 ? round(($data['amount'] / $totalAmount) * 100) : 0; ?>%;"></div>
                                    </div>
                                </td>
                                <td><?php echo $data['count']; ?></td>
                                <td class="amount"><?php echo number_format($data['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ===== EXPENSE TABLE ===== -->
        <div class="table-section">
            <div class="table-header">
                <h3>Expense Transactions</h3>
                <span class="record-count"><?php echo $totalCount; ?> records</span>
            </div>

            <?php if ($totalCount > 0): ?>
            <table class="expense-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Amount (₹)</th>
                        <th>Running Total</th>
                        <th>Mode</th>
                        <th>Year</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ($expenses as $exp): ?>
                    <tr>
                        <td class="sr-no"><?php echo $i++; ?></td>
                        <td class="date-cell"><?php echo $exp['FormattedDate']; ?></td>
                        <td class="desc-cell">
                            <?php echo htmlspecialchars($exp['Description']); ?>
                            <?php if (!empty($exp['VendorName'])): ?>
                                <div class="vendor">Vendor: <?php echo htmlspecialchars($exp['VendorName']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($exp['InvoiceNumber'])): ?>
                                <div class="vendor">Invoice: <?php echo htmlspecialchars($exp['InvoiceNumber']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="cat-badge"><?php echo htmlspecialchars($exp['Category']); ?></span></td>
                        <td class="amount-cell">₹<?php echo number_format($exp['Amount'], 2); ?></td>
                        <td class="running-cell">₹<?php echo number_format($exp['RunningTotal'], 2); ?></td>
                        <td class="mode-cell"><?php echo htmlspecialchars($exp['PaymentMode']); ?></td>
                        <td style="font-size: 11px;"><?php echo htmlspecialchars($exp['AcademicYear'] ?? 'N/A'); ?></td>
                        <td><span class="status-badge <?php echo strtolower($exp['Status']); ?>"><?php echo htmlspecialchars($exp['Status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="text-align: right; border-radius: 0 0 0 8px;">GRAND TOTAL</td>
                        <td class="total-amount">₹<?php echo number_format($totalAmount, 2); ?></td>
                        <td colspan="4" style="border-radius: 0 0 8px 0;"></td>
                    </tr>
                </tfoot>
            </table>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #94a3b8;">
                    <p style="font-size: 18px;">No expense records found for the selected filters.</p>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($statusSummary) && count($statusSummary) > 1): ?>
        <!-- Status Summary -->
        <div class="breakdown-section" style="border-top: 1px solid #e2e8f0;">
            <h3><span class="icon" style="background: #f59e0b;">📋</span> Status Summary</h3>
            <table class="breakdown-table" style="max-width: 400px;">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Count</th>
                        <th>Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statusSummary as $st => $data): ?>
                    <tr>
                        <td><span class="status-badge <?php echo strtolower($st); ?>"><?php echo htmlspecialchars($st); ?></span></td>
                        <td><?php echo $data['count']; ?></td>
                        <td class="amount"><?php echo number_format($data['amount'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ===== FOOTER ===== -->
        <div class="report-footer">
            <div class="footer-left">
                <div class="generated">Report generated on <?php echo date('d M Y \a\t h:i A'); ?></div>
                <div>By: <?php echo htmlspecialchars($superadmin_name ?? 'Administrator'); ?></div>
            </div>
            <div class="footer-right">
                <div><?php echo htmlspecialchars($siteName); ?></div>
                <div>Accounting Department</div>
            </div>
        </div>
    </div>

    <div class="watermark no-print">
        Use <strong>Ctrl + P</strong> (or <strong>Cmd + P</strong> on Mac) → Select "Save as PDF" as destination
    </div>
</body>
</html>
