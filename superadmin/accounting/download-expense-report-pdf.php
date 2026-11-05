<?php
/**
 * Download Expense Report as PDF
 * Generates a feature-rich, high-quality PDF using html2pdf.js
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'session.php';
require_once '../../config/config.php';
require_once '../../includes/logo-helper.php';

$siteName = getSiteName('SR Colony');

// ── Filter parameters ──────────────────────────────────────────────────────
$selectedYear = $_GET['year']       ?? 'all';
$startDate    = $_GET['start_date'] ?? '';
$endDate      = $_GET['end_date']   ?? '';
$category     = $_GET['category']   ?? 'all';
$status       = $_GET['status']     ?? 'all';

// Academic years map
$yearsStmt     = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);
$yearMap = [];
foreach ($academicYears as $y) $yearMap[$y['YearID']] = $y['YearName'];

// ── Build WHERE clause ─────────────────────────────────────────────────────
$where  = [];
$params = [];
if ($selectedYear !== 'all')            { $where[] = "e.YearID = ?";         $params[] = $selectedYear; }
if (!empty($startDate))                 { $where[] = "e.ExpenseDate >= ?";    $params[] = $startDate; }
if (!empty($endDate))                   { $where[] = "e.ExpenseDate <= ?";    $params[] = $endDate; }
if ($category !== 'all' && $category)   { $where[] = "e.Category = ?";       $params[] = $category; }
if ($status   !== 'all' && $status)     { $where[] = "e.Status = ?";         $params[] = $status; }
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Main expense query ─────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT e.ExpenseID,
           ay.YearName                                     AS AcademicYear,
           DATE_FORMAT(e.ExpenseDate,'%d %b %Y')           AS FormattedDate,
           DATE_FORMAT(e.ExpenseDate,'%Y-%m')              AS MonthKey,
           DATE_FORMAT(e.ExpenseDate,'%b %Y')              AS MonthLabel,
           e.ExpenseDate,
           e.Category, e.SubCategory, e.Description,
           e.Amount, e.PaymentMode,
           e.VendorName, e.InvoiceNumber, e.ReceiptNumber,
           e.Remarks, e.Status
    FROM expenses e
    LEFT JOIN academic_years ay ON e.YearID = ay.YearID
    $whereClause
    ORDER BY e.ExpenseDate DESC, e.ExpenseID DESC
");
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalAmount = array_sum(array_column($expenses, 'Amount'));
$totalCount  = count($expenses);
$avgAmount   = $totalCount ? $totalAmount / $totalCount : 0;
$maxAmount   = $totalCount ? max(array_column($expenses, 'Amount')) : 0;
$minAmount   = $totalCount ? min(array_column($expenses, 'Amount')) : 0;

// ── Running total (oldest → newest cumulative) ─────────────────────────────
$rt = 0;
$rev = [];
foreach (array_reverse($expenses) as $exp) {
    $rt += $exp['Amount'];
    $exp['RunningTotal'] = $rt;
    $rev[] = $exp;
}
$expenses = array_reverse($rev);

// ── Category breakdown ─────────────────────────────────────────────────────
$categorySummary = [];
foreach ($expenses as $exp) {
    $c = $exp['Category'];
    if (!isset($categorySummary[$c])) $categorySummary[$c] = ['count' => 0, 'amount' => 0];
    $categorySummary[$c]['count']++;
    $categorySummary[$c]['amount'] += $exp['Amount'];
}
arsort($categorySummary);

// ── Sub-category breakdown ─────────────────────────────────────────────────
$subCatSummary = [];
foreach ($expenses as $exp) {
    if (empty($exp['SubCategory'])) continue;
    $k = $exp['Category'] . ' › ' . $exp['SubCategory'];
    if (!isset($subCatSummary[$k])) $subCatSummary[$k] = ['count' => 0, 'amount' => 0];
    $subCatSummary[$k]['count']++;
    $subCatSummary[$k]['amount'] += $exp['Amount'];
}
arsort($subCatSummary);

// ── Payment mode breakdown ─────────────────────────────────────────────────
$paymentSummary = [];
foreach ($expenses as $exp) {
    $m = $exp['PaymentMode'];
    if (!isset($paymentSummary[$m])) $paymentSummary[$m] = ['count' => 0, 'amount' => 0];
    $paymentSummary[$m]['count']++;
    $paymentSummary[$m]['amount'] += $exp['Amount'];
}
arsort($paymentSummary);

// ── Status breakdown ───────────────────────────────────────────────────────
$statusSummary = [];
foreach ($expenses as $exp) {
    $st = $exp['Status'];
    if (!isset($statusSummary[$st])) $statusSummary[$st] = ['count' => 0, 'amount' => 0];
    $statusSummary[$st]['count']++;
    $statusSummary[$st]['amount'] += $exp['Amount'];
}

// ── Monthly breakdown ──────────────────────────────────────────────────────
$monthlySummary = [];
foreach ($expenses as $exp) {
    $k = $exp['MonthKey'];
    if (!isset($monthlySummary[$k])) $monthlySummary[$k] = ['label' => $exp['MonthLabel'], 'count' => 0, 'amount' => 0];
    $monthlySummary[$k]['count']++;
    $monthlySummary[$k]['amount'] += $exp['Amount'];
}
krsort($monthlySummary);

// ── Top vendors (top 8 by amount) ─────────────────────────────────────────
$vendorSummary = [];
foreach ($expenses as $exp) {
    if (empty($exp['VendorName'])) continue;
    $v = $exp['VendorName'];
    if (!isset($vendorSummary[$v])) $vendorSummary[$v] = ['count' => 0, 'amount' => 0];
    $vendorSummary[$v]['count']++;
    $vendorSummary[$v]['amount'] += $exp['Amount'];
}
arsort($vendorSummary);
$topVendors = array_slice($vendorSummary, 0, 8, true);

// ── Net balance ────────────────────────────────────────────────────────────
if ($selectedYear !== 'all') {
    $s = $pdo->prepare("SELECT IFNULL(SUM(InterestAmount),0) as t FROM transactions WHERE YearID=?");
    $s->execute([$selectedYear]); $totalInterest = $s->fetchColumn();
    $s = $pdo->prepare("SELECT IFNULL(FixedAmount,0) f, IFNULL(ChangeAmount,0) c FROM academic_years WHERE YearID=?");
    $s->execute([$selectedYear]); $yd = $s->fetch(PDO::FETCH_ASSOC);
    $totalFixed = $yd['f'] ?? 0; $totalChange = $yd['c'] ?? 0;
} else {
    $totalInterest = $pdo->query("SELECT IFNULL(SUM(InterestAmount),0) FROM transactions")->fetchColumn();
    $yd = $pdo->query("SELECT IFNULL(SUM(FixedAmount),0) f,IFNULL(SUM(ChangeAmount),0) c FROM academic_years")->fetch(PDO::FETCH_ASSOC);
    $totalFixed = $yd['f']; $totalChange = $yd['c'];
}
$totalIncome = $totalInterest + $totalFixed + $totalChange;
$netBalance  = $totalIncome - $totalAmount;

// ── Filter label ───────────────────────────────────────────────────────────
$fd = [];
if ($selectedYear !== 'all') $fd[] = 'Year: ' . ($yearMap[$selectedYear] ?? $selectedYear);
if (!empty($startDate))      $fd[] = 'From: ' . date('d M Y', strtotime($startDate));
if (!empty($endDate))        $fd[] = 'To: '   . date('d M Y', strtotime($endDate));
if ($category !== 'all')     $fd[] = "Category: $category";
if ($status   !== 'all')     $fd[] = "Status: $status";
$filterText = $fd ? implode('  |  ', $fd) : 'All Records';

$filename = 'Expense-Report-' . date('Y-m-d') . '.pdf';

// ── Palette helpers ────────────────────────────────────────────────────────
$catColors  = ['#6366f1','#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4'];
$modeColors = ['#10b981','#3b82f6','#f59e0b','#ef4444','#8b5cf6','#ec4899'];
function statusColor($s) {
    $map = [
        'approved'  => ['bg'=>'#dcfce7','color'=>'#166534'],
        'pending'   => ['bg'=>'#fef9c3','color'=>'#854d0e'],
        'rejected'  => ['bg'=>'#fee2e2','color'=>'#991b1b'],
        'cancelled' => ['bg'=>'#f1f5f9','color'=>'#475569'],
    ];
    return $map[strtolower($s)] ?? ['bg'=>'#e2e8f0','color'=>'#334155'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Expense Report – <?php echo htmlspecialchars($siteName); ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
/* ── Reset ── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #e8ecf0;
    color: #1e293b;
    font-size: 13px;
    line-height: 1.55;
}

/* ── Overlay ── */
#overlay {
    position: fixed; inset: 0;
    background: #0f172a;
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    z-index: 9999; gap: 18px;
}
#overlay .spinner {
    width: 52px; height: 52px;
    border: 5px solid rgba(16,185,129,0.25);
    border-top-color: #10b981;
    border-radius: 50%;
    animation: spin 0.85s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
#overlay .overlay-title { color: #f1f5f9; font-size: 18px; font-weight: 700; }
#overlay .overlay-sub   { color: #64748b; font-size: 13px; }

/* ── Report wrapper ── */
#report {
    width: 277mm;
    background: #ffffff;
    margin: 0 auto;
}

/* ════════════════════════════════
   HEADER
════════════════════════════════ */
.rpt-header {
    background: #0f172a;
    padding: 0;
    position: relative;
    overflow: hidden;
}
.rpt-header-accent {
    position: absolute; top: 0; left: 0; right: 0; height: 4px;
    background: linear-gradient(90deg, #10b981, #3b82f6, #8b5cf6);
}
.rpt-header-body {
    padding: 26px 36px 18px;
    display: flex; justify-content: space-between; align-items: flex-start;
}
.rpt-header-left h1 {
    font-size: 24px; font-weight: 800; color: #f8fafc;
    letter-spacing: -0.5px; margin-bottom: 4px;
}
.rpt-header-left .dept {
    font-size: 13px; color: #94a3b8; font-weight: 500;
}
.rpt-header-right { text-align: right; }
.rpt-badge {
    display: inline-block;
    background: rgba(16,185,129,0.18);
    color: #34d399;
    border: 1px solid rgba(52,211,153,0.35);
    padding: 5px 16px; border-radius: 20px;
    font-size: 11px; font-weight: 700; letter-spacing: 1.2px;
    text-transform: uppercase; margin-bottom: 8px;
}
.rpt-date { color: #64748b; font-size: 11.5px; }

.rpt-filter-bar {
    background: rgba(255,255,255,0.05);
    border-top: 1px solid rgba(255,255,255,0.07);
    padding: 9px 36px;
    color: #64748b; font-size: 11px;
}
.rpt-filter-bar strong { color: #cbd5e1; }

/* ════════════════════════════════
   SECTION WRAPPER
════════════════════════════════ */
.section {
    padding: 20px 36px;
    border-bottom: 1px solid #e2e8f0;
}
.section:last-of-type { border-bottom: none; }

.section-title {
    font-size: 13px; font-weight: 700; color: #0f172a;
    text-transform: uppercase; letter-spacing: 0.6px;
    margin-bottom: 14px;
    display: flex; align-items: center; gap: 8px;
}
.section-title .dot {
    width: 8px; height: 8px; border-radius: 50%;
    display: inline-block; flex-shrink: 0;
}

/* ════════════════════════════════
   SUMMARY CARDS
════════════════════════════════ */
.summary-bg { background: #f8fafc; }

.kpi-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px;
}
.kpi-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    text-align: center;
    position: relative; overflow: hidden;
}
.kpi-card::after {
    content: '';
    position: absolute; bottom: 0; left: 0; right: 0; height: 3px;
}
.kpi-card.kpi-exp::after    { background: linear-gradient(90deg,#ef4444,#f97316); }
.kpi-card.kpi-cnt::after    { background: linear-gradient(90deg,#3b82f6,#6366f1); }
.kpi-card.kpi-avg::after    { background: linear-gradient(90deg,#10b981,#14b8a6); }
.kpi-card.kpi-max::after    { background: linear-gradient(90deg,#f59e0b,#ef4444); }
.kpi-card.kpi-bal::after    { background: linear-gradient(90deg,#8b5cf6,#a855f7); }

.kpi-label {
    font-size: 9.5px; color: #64748b;
    text-transform: uppercase; letter-spacing: 0.5px;
    font-weight: 700; margin-bottom: 7px;
}
.kpi-value {
    font-size: 19px; font-weight: 800; color: #0f172a; line-height: 1.2;
}
.kpi-sub {
    font-size: 10px; color: #94a3b8; margin-top: 3px;
}
.kpi-card.kpi-exp .kpi-value { color: #dc2626; }
.kpi-card.kpi-bal .kpi-value.pos { color: #059669; }
.kpi-card.kpi-bal .kpi-value.neg { color: #dc2626; }

/* ════════════════════════════════
   TWO-COLUMN GRID
════════════════════════════════ */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.three-col { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }

/* ════════════════════════════════
   INNER TABLES (breakdown)
════════════════════════════════ */
.inner-heading {
    font-size: 11.5px; font-weight: 700; color: #334155;
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
.inner-heading .chip {
    width: 20px; height: 20px; border-radius: 5px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 10px; flex-shrink: 0;
}

.bk-table { width: 100%; border-collapse: collapse; font-size: 11px; }
.bk-table thead th {
    background: #f1f5f9;
    padding: 7px 10px;
    text-align: left; font-size: 10px; font-weight: 700;
    color: #475569; text-transform: uppercase; letter-spacing: 0.3px;
    border-bottom: 2px solid #e2e8f0;
}
.bk-table thead th:last-child,
.bk-table tbody td:last-child { text-align: right; }
.bk-table tbody td {
    padding: 7px 10px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
}
.bk-table tbody tr:last-child td { border-bottom: none; }
.bk-table .mono { font-weight: 700; }

.bar-wrap { height: 4px; background: #e2e8f0; border-radius: 4px; margin-top: 4px; overflow: hidden; }
.bar-inner { height: 100%; border-radius: 4px; }

/* ════════════════════════════════
   MONTHLY TABLE (full width)
════════════════════════════════ */
.full-table { width: 100%; border-collapse: collapse; font-size: 11px; }
.full-table thead th {
    background: #1e293b; color: #e2e8f0;
    padding: 9px 12px; text-align: left;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.4px;
}
.full-table thead th:last-child,
.full-table tbody td:last-child { text-align: right; }
.full-table tbody tr:nth-child(even) { background: #f8fafc; }
.full-table tbody td {
    padding: 8px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
}
.full-table .mono { font-weight: 700; }

/* ════════════════════════════════
   MAIN EXPENSE TABLE
════════════════════════════════ */
.table-header-row {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 12px;
}
.table-header-row h3 { font-size: 14px; font-weight: 700; color: #0f172a; }
.rec-badge {
    background: #f1f5f9; color: #64748b;
    padding: 3px 12px; border-radius: 12px;
    font-size: 11px; font-weight: 600;
}

.exp-table { width: 100%; border-collapse: collapse; font-size: 11px; }
.exp-table thead th {
    background: #0f172a; color: #e2e8f0;
    padding: 10px 9px;
    text-align: left; font-size: 9.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
}
.exp-table thead th.r { text-align: right; }
.exp-table tbody tr:nth-child(even) { background: #f8fafc; }
.exp-table tbody tr { border-bottom: 1px solid #f0f4f8; }
.exp-table td {
    padding: 8px 9px; color: #334155; vertical-align: top;
}

.td-num { color: #94a3b8; font-weight: 600; text-align: center; width: 28px; }
.td-date { white-space: nowrap; font-weight: 600; color: #1e293b; }
.td-desc { max-width: 170px; font-weight: 500; color: #0f172a; }
.td-desc .meta { font-size: 9px; color: #64748b; margin-top: 2px; }
.td-cat  { }
.cat-pill {
    display: inline-block;
    padding: 2px 9px; border-radius: 10px;
    font-size: 9.5px; font-weight: 700;
    background: #ede9fe; color: #5b21b6;
    white-space: nowrap;
}
.td-amt  { text-align: right; font-weight: 700; color: #0f172a; white-space: nowrap; }
.td-run  { text-align: right; font-weight: 600; color: #7c3aed; white-space: nowrap; font-size: 10.5px; }
.td-mode { color: #64748b; font-size: 10px; }
.td-yr   { color: #64748b; font-size: 10px; }
.td-rem  { max-width: 110px; font-size: 10px; color: #64748b; }

.st-pill {
    display: inline-block;
    padding: 2px 8px; border-radius: 8px;
    font-size: 9.5px; font-weight: 700; text-transform: uppercase;
}

.exp-table tfoot td {
    background: #0f172a; color: #e2e8f0;
    padding: 11px 9px; font-weight: 800; font-size: 12px;
}
.tfoot-total { text-align: right; color: #fbbf24; font-size: 15px; }

/* ════════════════════════════════
   FOOTER
════════════════════════════════ */
.rpt-footer {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 18px 36px;
    display: flex; justify-content: space-between; align-items: center;
}
.rpt-footer .fl { font-size: 10.5px; color: #64748b; line-height: 1.8; }
.rpt-footer .fr { font-size: 10.5px; color: #64748b; text-align: right; line-height: 1.8; }
.rpt-footer strong { color: #334155; }

/* ── Divider ── */
.divider {
    height: 1px; background: #e2e8f0;
    margin: 0 36px;
}
</style>
</head>
<body>

<!-- ── Loading overlay ── -->
<div id="overlay">
    <div class="spinner"></div>
    <div class="overlay-title">Generating PDF…</div>
    <div class="overlay-sub">Please wait — your download will start automatically.</div>
</div>

<!-- ════════════════════════════════════════════════════════════
     REPORT
════════════════════════════════════════════════════════════ -->
<div id="report">

    <!-- ── Header ── -->
    <div class="rpt-header">
        <div class="rpt-header-accent"></div>
        <div class="rpt-header-body">
            <div class="rpt-header-left">
                <h1><?php echo htmlspecialchars($siteName); ?></h1>
                <div class="dept">Expense Management Report</div>
            </div>
            <div class="rpt-header-right">
                <div class="rpt-badge">Expense Report</div>
                <div class="rpt-date">Generated: <?php echo date('d M Y, h:i A'); ?></div>
            </div>
        </div>
        <div class="rpt-filter-bar">
            <strong>Filters:</strong>&nbsp; <?php echo htmlspecialchars($filterText); ?>
        </div>
    </div>

    <!-- ── KPI Summary ── -->
    <div class="section summary-bg">
        <div class="kpi-grid">
            <div class="kpi-card kpi-exp">
                <div class="kpi-label">Total Expenses</div>
                <div class="kpi-value">&#x20B9;<?php echo number_format($totalAmount, 2); ?></div>
                <div class="kpi-sub"><?php echo $totalCount; ?> transactions</div>
            </div>
            <div class="kpi-card kpi-cnt">
                <div class="kpi-label">Total Records</div>
                <div class="kpi-value"><?php echo number_format($totalCount); ?></div>
                <div class="kpi-sub"><?php echo count($categorySummary); ?> categories</div>
            </div>
            <div class="kpi-card kpi-avg">
                <div class="kpi-label">Average Expense</div>
                <div class="kpi-value">&#x20B9;<?php echo number_format($avgAmount, 2); ?></div>
                <div class="kpi-sub">per transaction</div>
            </div>
            <div class="kpi-card kpi-max">
                <div class="kpi-label">Highest Expense</div>
                <div class="kpi-value">&#x20B9;<?php echo number_format($maxAmount, 2); ?></div>
                <div class="kpi-sub">Min: &#x20B9;<?php echo number_format($minAmount, 2); ?></div>
            </div>
            <!-- <div class="kpi-card kpi-bal">
                <div class="kpi-label">Net Balance</div>
                <div class="kpi-value <?php echo $netBalance >= 0 ? 'pos' : 'neg'; ?>">
                    &#x20B9;<?php echo number_format(abs($netBalance), 2); ?>
                </div>
                <div class="kpi-sub"><?php echo $netBalance >= 0 ? 'Surplus' : 'Deficit'; ?></div>
            </div> -->
        </div>
    </div>

    <!-- ── Category  +  Payment Mode ── -->
    <div class="section">
        <div class="two-col">

            <!-- Category -->
            <div>
                <div class="inner-heading">
                    <span class="chip" style="background:#6366f1;color:white;">&#128194;</span>
                    Category Breakdown
                </div>
                <table class="bk-table">
                    <thead>
                        <tr><th>Category</th><th>Txns</th><th>Amount (&#x20B9;)</th><th>Share</th></tr>
                    </thead>
                    <tbody>
                        <?php $ci = 0; foreach ($categorySummary as $cat => $d):
                            $pct = $totalAmount > 0 ? round($d['amount']/$totalAmount*100, 1) : 0;
                            $col = $catColors[$ci % count($catColors)]; $ci++;
                        ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($cat); ?>
                                <div class="bar-wrap">
                                    <div class="bar-inner" style="width:<?php echo $pct; ?>%;background:<?php echo $col; ?>;"></div>
                                </div>
                            </td>
                            <td><?php echo $d['count']; ?></td>
                            <td class="mono"><?php echo number_format($d['amount'], 2); ?></td>
                            <td><?php echo $pct; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Payment mode -->
            <div>
                <div class="inner-heading">
                    <span class="chip" style="background:#10b981;color:white;">&#128179;</span>
                    Payment Mode Breakdown
                </div>
                <table class="bk-table">
                    <thead>
                        <tr><th>Mode</th><th>Txns</th><th>Amount (&#x20B9;)</th><th>Share</th></tr>
                    </thead>
                    <tbody>
                        <?php $mi = 0; foreach ($paymentSummary as $mode => $d):
                            $pct = $totalAmount > 0 ? round($d['amount']/$totalAmount*100, 1) : 0;
                            $col = $modeColors[$mi % count($modeColors)]; $mi++;
                        ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($mode); ?>
                                <div class="bar-wrap">
                                    <div class="bar-inner" style="width:<?php echo $pct; ?>%;background:<?php echo $col; ?>;"></div>
                                </div>
                            </td>
                            <td><?php echo $d['count']; ?></td>
                            <td class="mono"><?php echo number_format($d['amount'], 2); ?></td>
                            <td><?php echo $pct; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>

    <!-- ── Status  +  Sub-category  +  Top Vendors ── -->
    <div class="section">
        <div class="three-col">

            <!-- Status -->
            <div>
                <div class="inner-heading">
                    <span class="chip" style="background:#f59e0b;color:white;">&#9989;</span>
                    Approval Status
                </div>
                <table class="bk-table">
                    <thead>
                        <tr><th>Status</th><th>Count</th><th>Amount (&#x20B9;)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statusSummary as $st => $d):
                            $sc = statusColor($st);
                        ?>
                        <tr>
                            <td>
                                <span class="st-pill" style="background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['color']; ?>;">
                                    <?php echo htmlspecialchars($st); ?>
                                </span>
                            </td>
                            <td><?php echo $d['count']; ?></td>
                            <td class="mono"><?php echo number_format($d['amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Sub-category -->
            <div>
                <div class="inner-heading">
                    <span class="chip" style="background:#8b5cf6;color:white;">&#128204;</span>
                    Sub-Category Breakdown
                </div>
                <?php if (!empty($subCatSummary)): ?>
                <table class="bk-table">
                    <thead>
                        <tr><th>Sub-Category</th><th>Cnt</th><th>Amount (&#x20B9;)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subCatSummary as $sc => $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sc); ?></td>
                            <td><?php echo $d['count']; ?></td>
                            <td class="mono"><?php echo number_format($d['amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p style="color:#94a3b8;font-size:11px;padding:12px 0;">No sub-categories recorded.</p>
                <?php endif; ?>
            </div>

            <!-- Top Vendors -->
            <div>
                <div class="inner-heading">
                    <span class="chip" style="background:#06b6d4;color:white;">&#127978;</span>
                    Top Vendors
                </div>
                <?php if (!empty($topVendors)): ?>
                <table class="bk-table">
                    <thead>
                        <tr><th>Vendor</th><th>Cnt</th><th>Amount (&#x20B9;)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topVendors as $vn => $d): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($vn); ?></td>
                            <td><?php echo $d['count']; ?></td>
                            <td class="mono"><?php echo number_format($d['amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p style="color:#94a3b8;font-size:11px;padding:12px 0;">No vendor data available.</p>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- ── Monthly Breakdown ── -->
    <?php if (!empty($monthlySummary)): ?>
    <div class="section">
        <div class="section-title">
            <span class="dot" style="background:#3b82f6;"></span>
            Monthly Expense Breakdown
        </div>
        <table class="full-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Transactions</th>
                    <th>Total Amount (&#x20B9;)</th>
                    <th>Average (&#x20B9;)</th>
                    <th>Share of Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlySummary as $mk => $md):
                    $mpct = $totalAmount > 0 ? round($md['amount']/$totalAmount*100, 1) : 0;
                    $mavg = $md['count'] ? $md['amount']/$md['count'] : 0;
                ?>
                <tr>
                    <td style="font-weight:600;color:#0f172a;"><?php echo htmlspecialchars($md['label']); ?></td>
                    <td><?php echo $md['count']; ?></td>
                    <td class="mono">&#x20B9;<?php echo number_format($md['amount'], 2); ?></td>
                    <td class="mono">&#x20B9;<?php echo number_format($mavg, 2); ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;height:5px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
                                <div style="height:100%;width:<?php echo $mpct; ?>%;background:#3b82f6;border-radius:4px;"></div>
                            </div>
                            <span style="font-size:10.5px;color:#64748b;min-width:34px;text-align:right;"><?php echo $mpct; ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── Expense Transactions ── -->
    <div class="section">
        <div class="table-header-row">
            <h3>Expense Transactions</h3>
            <span class="rec-badge"><?php echo $totalCount; ?> records</span>
        </div>

        <?php if ($totalCount > 0): ?>
        <table class="exp-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Category</th>
                    <th class="r">Amount (&#x20B9;)</th>
                    <th class="r">Running Total</th>
                    <th>Mode</th>
                    <th>Year</th>
                    <th>Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; foreach ($expenses as $exp):
                    $sc2 = statusColor($exp['Status']);
                ?>
                <tr>
                    <td class="td-num"><?php echo $i++; ?></td>
                    <td class="td-date"><?php echo htmlspecialchars($exp['FormattedDate']); ?></td>
                    <td class="td-desc">
                        <?php echo htmlspecialchars($exp['Description']); ?>
                        <?php if (!empty($exp['VendorName'])): ?>
                            <div class="meta">Vendor: <?php echo htmlspecialchars($exp['VendorName']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($exp['InvoiceNumber'])): ?>
                            <div class="meta">Inv: <?php echo htmlspecialchars($exp['InvoiceNumber']); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($exp['ReceiptNumber'])): ?>
                            <div class="meta">Rcpt: <?php echo htmlspecialchars($exp['ReceiptNumber']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="td-cat">
                        <span class="cat-pill"><?php echo htmlspecialchars($exp['Category']); ?></span>
                        <?php if (!empty($exp['SubCategory'])): ?>
                            <div style="font-size:9px;color:#64748b;margin-top:2px;"><?php echo htmlspecialchars($exp['SubCategory']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="td-amt">&#x20B9;<?php echo number_format($exp['Amount'], 2); ?></td>
                    <td class="td-run">&#x20B9;<?php echo number_format($exp['RunningTotal'], 2); ?></td>
                    <td class="td-mode"><?php echo htmlspecialchars($exp['PaymentMode']); ?></td>
                    <td class="td-yr"><?php echo htmlspecialchars($exp['AcademicYear'] ?? '—'); ?></td>
                    <td>
                        <span class="st-pill" style="background:<?php echo $sc2['bg']; ?>;color:<?php echo $sc2['color']; ?>;">
                            <?php echo htmlspecialchars($exp['Status']); ?>
                        </span>
                    </td>
                    <td class="td-rem"><?php echo htmlspecialchars($exp['Remarks'] ?? '—'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right;letter-spacing:0.5px;">GRAND TOTAL</td>
                    <td class="tfoot-total">&#x20B9;<?php echo number_format($totalAmount, 2); ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>
        </table>
        <?php else: ?>
            <p style="text-align:center;padding:36px;color:#94a3b8;font-size:13px;">
                No expense records found for the selected filters.
            </p>
        <?php endif; ?>
    </div>

    <!-- ── Footer ── -->
    <div class="rpt-footer">
        <div class="fl">
            <div>Report generated on <strong><?php echo date('d M Y \a\t h:i A'); ?></strong></div>
            <div>Prepared by: <strong><?php echo htmlspecialchars($superadmin_name ?? 'Administrator'); ?></strong></div>
        </div>
        <div class="fr">
            <div><strong><?php echo htmlspecialchars($siteName); ?></strong></div>
            <div>Accounting Department</div>
        </div>
    </div>

</div><!-- #report -->

<script>
window.addEventListener('load', function () {
    var opt = {
        margin:      [6, 6, 6, 6],
        filename:    <?php echo json_encode($filename); ?>,
        image:       { type: 'jpeg', quality: 1 },
        html2canvas: {
            scale:      3,
            useCORS:    true,
            logging:    false,
            letterRendering: true
        },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
        pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
    };

    html2pdf()
        .set(opt)
        .from(document.getElementById('report'))
        .save()
        .then(function () {
            document.getElementById('overlay').innerHTML =
                '<div style="text-align:center;">' +
                '  <div style="font-size:48px;margin-bottom:14px;">&#10003;</div>' +
                '  <div style="color:#34d399;font-size:20px;font-weight:700;margin-bottom:8px;">PDF Downloaded!</div>' +
                '  <div style="color:#64748b;font-size:13px;margin-bottom:22px;">Check your downloads folder for <em><?php echo addslashes($filename); ?></em></div>' +
                '  <button onclick="window.close()" style="padding:11px 32px;background:#10b981;color:white;border:none;border-radius:8px;font-size:14px;cursor:pointer;font-weight:700;">Close Tab</button>' +
                '</div>';
        });
});
</script>
</body>
</html>
