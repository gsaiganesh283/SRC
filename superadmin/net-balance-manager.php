<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/net-balance-helper.php';
require_once '../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName, StartDate, EndDate FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get current/active academic year as default
$currentYearStmt = $pdo->query("SELECT YearID FROM academic_years WHERE Status = 'Active' LIMIT 1");
$currentYr = $currentYearStmt->fetch(PDO::FETCH_ASSOC);
$currentYearId = $currentYr['YearID'] ?? ($academicYears[0]['YearID'] ?? 1);

// Build year range string for display (e.g., "2023-2026")
$yearRange = '';
if (!empty($academicYears)) {
    $years = [];
    foreach ($academicYears as $year) {
        // Extract ALL year numbers from YearName (e.g., "2024-2025" -> ["2024", "2025"])
        preg_match_all('/(\d{4})/', $year['YearName'], $matches);
        if (!empty($matches[1])) {
            // Add both starting and ending years
            foreach ($matches[1] as $yearNum) {
                if (!in_array($yearNum, $years)) {
                    $years[] = $yearNum;
                }
            }
        }
    }
    if (!empty($years)) {
        sort($years);
        $yearRange = reset($years) . '-' . end($years);
    }
}

// Get selected year from URL or use current/active year as default
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : $currentYearId;

// Determine which year to calculate for:
// - If year=0 (All Years), pass null to functions to get aggregated data from all years
// - Otherwise pass the specific year ID
$yearForCalculation = ($selectedYear == 0) ? null : $selectedYear;

// Get system summary
if (function_exists('getSystemNetBalanceSummary')) {
    $systemSummary = getSystemNetBalanceSummary($yearForCalculation);
} else {
    // Fallback if function is not defined
    $systemSummary = [
        'total_collection' => 0,
        'total_distributed' => 0,
        'total_interest' => 0,
        'net_balance' => 0
    ];
}

// Get initial members list
if (function_exists('getAllMembersNetBalance')) {
    $membersBalances = getAllMembersNetBalance($yearForCalculation);
} else {
    // Fallback if function is not defined
    $membersBalances = [];
}

// Get collection data
$collectionData = [];
$distData = [];
$collectionMap = [];
$distMap = [];

if (!empty($membersBalances)) {
    // Fetch collection data from transactions table
    $transQuery = "SELECT MemberID, SUM(CollectionAmount) as total_collection 
                   FROM transactions 
                   WHERE CollectionAmount > 0";
    if ($yearForCalculation !== null) {
        $transQuery .= " AND YearID = " . intval($yearForCalculation);
    }
    $transQuery .= " GROUP BY MemberID ORDER BY total_collection DESC";
    try {
        $transStmt = $pdo->query($transQuery);
        $collectionData = $transStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Create lookup map for quick access
        foreach ($collectionData as $rec) {
            $collectionMap[$rec['MemberID']] = floatval($rec['total_collection']);
        }
    } catch (Exception $e) {
        error_log("Collection query error: " . $e->getMessage());
        $collectionData = [];
    }
    
    // Distribution data
    $distQuery = "SELECT MemberID, SUM(DistributedAmount) as total_distribution 
                  FROM transactions 
                  WHERE DistributedAmount > 0";
    if ($yearForCalculation !== null) {
        $distQuery .= " AND YearID = " . intval($yearForCalculation);
    }
    $distQuery .= " GROUP BY MemberID ORDER BY total_distribution DESC";
    try {
        $distStmt = $pdo->query($distQuery);
        $distData = $distStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Create lookup map for quick access
        foreach ($distData as $rec) {
            $distMap[$rec['MemberID']] = floatval($rec['total_distribution']);
        }
    } catch (Exception $e) {
        error_log("Distribution query error: " . $e->getMessage());
        $distData = [];
    }
    
    // Fetch interest data
    $interestMap = [];
    $interestQuery = "SELECT MemberID, SUM(InterestAmount) as total_interest 
                      FROM transactions 
                      WHERE InterestAmount > 0";
    if ($yearForCalculation !== null) {
        $interestQuery .= " AND YearID = " . intval($yearForCalculation);
    }
    $interestQuery .= " GROUP BY MemberID";
    try {
        $interestStmt = $pdo->query($interestQuery);
        $interestData = $interestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($interestData as $rec) {
            $interestMap[$rec['MemberID']] = floatval($rec['total_interest']);
        }
    } catch (Exception $e) {
        error_log("Interest query error: " . $e->getMessage());
        $interestMap = [];
    }

    // Change amount entries of each member (part of the member's net balance)
    $changeMap = caMemberTotals($pdo, $yearForCalculation);
}
$changeMap = $changeMap ?? [];

// ── Month of the selected academic year ───────────────────────────────────
// The month's collection / distribution / interest / change amount, and each member's
// net balance at the end of that month (everything in the year up to the month's last day).
$monthOptions = [];
$selectedMonth = '';
$monthLabel = '';
if ($yearForCalculation !== null) {
    foreach ($academicYears as $y) {
        if ((int)$y['YearID'] !== (int)$yearForCalculation) continue;
        $cur = new DateTime(date('Y-m-01', strtotime($y['StartDate'])));
        $last = new DateTime(date('Y-m-01', strtotime($y['EndDate'])));
        for ($i = 0; $cur <= $last && $i < 36; $i++, $cur->modify('+1 month')) {
            $monthOptions[] = ['value' => $cur->format('Y-m'), 'label' => $cur->format('F Y')];
        }
    }
    $m = (string)($_GET['month'] ?? '');
    if (in_array($m, array_column($monthOptions, 'value'), true)) {
        $selectedMonth = $m;
    }
}
if ($selectedMonth !== '') {
    $monthStart = $selectedMonth . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $monthLabel = date('F Y', strtotime($monthStart));
    $y = (int)$yearForCalculation;
    $sum = function ($col) use ($pdo, $y, $monthStart, $monthEnd) {
        $s = $pdo->prepare("SELECT MemberID, SUM($col) FROM transactions WHERE YearID = ? AND TransactionDate BETWEEN ? AND ? AND $col > 0 GROUP BY MemberID");
        $s->execute([$y, $monthStart, $monthEnd]);
        return array_map('floatval', $s->fetchAll(PDO::FETCH_KEY_PAIR));
    };
    $collectionMap = $sum('CollectionAmount');
    $distMap = $sum('DistributedAmount');
    $interestMap = $sum('InterestAmount');
    $changeMap = caMemberTotals($pdo, $y, null, null, $monthStart, $monthEnd);

    // Balance of each member at the end of the month
    $s = $pdo->prepare('SELECT MemberID, SUM(COALESCE(DistributedAmount, 0) + COALESCE(InterestAmount, 0) - COALESCE(CollectionAmount, 0))
        FROM transactions WHERE YearID = ? AND TransactionDate <= ? GROUP BY MemberID');
    $s->execute([$y, $monthEnd]);
    $balanceAtEnd = array_map('floatval', $s->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach (caMemberTotals($pdo, $y, null, date('Y-m-d', strtotime($monthEnd . ' +1 day'))) as $mid => $chg) {
        $balanceAtEnd[$mid] = ($balanceAtEnd[$mid] ?? 0) + $chg;
    }
    $total = 0;
    foreach ($membersBalances as &$mb) {
        $mb['NetBalance'] = round($balanceAtEnd[$mb['MemberID']] ?? 0, 2);
        $total += $mb['NetBalance'];
    }
    unset($mb);
    $monthCa = caTotals($pdo, $y, $monthStart, $monthEnd);
    $generalToEnd = caTotals($pdo, $y, null, $monthEnd)['general'];
    $systemSummary = array_merge($systemSummary ?: [], [
        'total_collection' => array_sum($collectionMap),
        'total_distributed' => array_sum($distMap),
        'total_interest' => array_sum($interestMap),
        'change_amount' => $monthCa['general'],
        'change_amount_members' => $monthCa['members'],
        'change_amount_total' => $monthCa['total'],
        'net_balance' => round($total + $generalToEnd, 2),
        'month' => $selectedMonth,
        'month_label' => $monthLabel,
    ]);
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Net Balance Management - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
    <style>
        :root {
            --primary-color: #3498db;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --dark-bg: #1a1a1a;
            --card-bg: #ffffff;
            --text-dark: #2c3e50;
            --border-light: #ecf0f1;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.15);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.2);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        .net-balance-container {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            background-attachment: fixed;
            min-height: 100vh;
            padding: 30px 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }
        
        .header-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
        }
        
        .header-section h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
            font-weight: 700;
            color: white;
            position: relative;
            z-index: 1;
        }
        
        .header-section p {
            margin: 0;
            opacity: 0.95;
            color: rgba(255,255,255,0.9);
            position: relative;
            z-index: 1;
        }
        
        .controls-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-light);
        }
        
        .controls-section .row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .controls-section .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .controls-section label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
        }
        
        .controls-section select,
        .controls-section input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
            background: white;
            color: var(--text-dark);
            transition: all 0.3s ease;
        }
        
        .controls-section select:hover,
        .controls-section input:hover {
            border-color: var(--primary-color);
        }
        
        .controls-section select:focus,
        .controls-section input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: nowrap;
            flex: 0 1 auto;
        }
        
        .action-buttons .btn {
            flex: 1;
            min-width: 140px;
            justify-content: center;
            padding: 12px 20px;
            height: 44px;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
            color: white;
        }
        
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
            color: white;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        /* Small button variant */
        .btn-sm {
            padding: 6px 12px !important;
            font-size: 12px !important;
            gap: 5px;
        }
        
        /* Advanced Tab System */
        .tab-navigation {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            background: white;
            padding: 15px;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            flex-wrap: wrap;
        }
        
        .tab-button {
            padding: 12px 24px;
            border: 2px solid var(--border-light);
            background: white;
            color: var(--text-dark);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .tab-button:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .tab-button.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: transparent;
            box-shadow: var(--shadow-md);
        }
        
        .tab-button i {
            margin-right: 6px;
        }
        
        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            border-top: 4px solid;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }
        
        .summary-card:hover::before {
            opacity: 1;
        }
        
        .summary-card h3 {
            margin: 0 0 12px 0;
            font-size: 13px;
            color: var(--text-dark);
            text-transform: uppercase;
            letter-spacing: 1.2px;
            font-weight: 600;
            opacity: 0.7;
        }
        
        .summary-card .amount {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
            color: var(--text-dark);
            position: relative;
            z-index: 1;
        }
        
        .summary-card .amount.positive {
            color: var(--success-color);
        }
        
        .summary-card .amount.negative {
            color: var(--danger-color);
        }
        
        .summary-card-net {
            border-top-color: #667eea;
        }
        
        .summary-card-collection {
            border-top-color: #3498db;
        }
        
        .summary-card-distribution {
            border-top-color: #2ecc71;
        }
        
        /* Tab Content */
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Table Styling */
        .members-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-light);
            margin-bottom: 20px;
            max-height: 600px;
            display: flex;
            flex-direction: column;
        }
        
        .members-table h3 {
            padding: 20px 20px 15px 20px;
            margin: 0;
            border-bottom: 1px solid var(--border-light);
            flex-shrink: 0;
        }
        
        .members-table > div {
            overflow-y: auto;
            overflow-x: auto;
            flex: 1;
        }
        
        .members-table table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .members-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: white;
            border-bottom: 2px solid var(--border-light);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .members-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-light);
            color: var(--text-dark);
        }
        
        .members-table tbody tr:hover {
            background: rgba(102, 126, 234, 0.05);
            cursor: pointer;
        }
        
        .members-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-positive {
            background: rgba(46, 204, 113, 0.2);
            color: var(--success-color);
        }
        
        .badge-negative {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger-color);
        }
        
        .badge-neutral {
            background: rgba(149, 165, 166, 0.2);
            color: #7f8c8d;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
            border-left: 4px solid;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert.show {
            display: block;
        }
        
        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success-color);
            border-left-color: var(--success-color);
        }
        
        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger-color);
            border-left-color: var(--danger-color);
        }
        
        .warning-box {
            background: rgba(243, 156, 18, 0.1);
            border-left: 4px solid var(--warning-color);
            padding: 15px;
            border-radius: 8px;
            color: var(--text-dark);
            font-size: 13px;
            margin-bottom: 20px;
        }
        
        .warning-box i {
            margin-right: 8px;
            color: var(--warning-color);
        }
        
        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            justify-content: center;
            align-items: center;
        }
        
        .loading.show {
            display: flex;
            flex-direction: column;
        }
        
        .spinner {
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
        
        .loading p {
            color: white;
            margin-top: 15px;
            font-size: 16px;
            font-weight: 600;
        }
        
        .year-dropdown {
            width: 100%;
            padding: 12px 15px;
            margin-top: 10px;
            border: 2px solid var(--border-light);
            border-radius: 8px;
            background: white;
            color: var(--text-dark);
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .year-dropdown:hover {
            border-color: var(--primary-color);
            box-shadow: var(--shadow-sm);
        }
        
        .year-dropdown:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .year-dropdown option {
            padding: 8px;
            background: white;
            color: var(--text-dark);
        }
        
        .current-year-info {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border-left: 4px solid var(--primary-color);
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: 500;
            color: var(--text-dark);
        }
        
        .export-dialog-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            animation: fadeIn 0.3s ease-in-out;
        }
        
        .export-dialog-modal {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            max-width: 550px;
            width: 90%;
            overflow: hidden;
            animation: slideUp 0.3s ease-out;
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .export-dialog-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .export-dialog-header i {
            font-size: 24px;
        }
        
        .export-dialog-body {
            padding: 30px;
            color: var(--text-dark);
        }
        
        .export-dialog-body p {
            margin: 0 0 20px 0;
            font-size: 15px;
            color: #666;
        }
        
        .export-option-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .export-option {
            display: flex;
            align-items: flex-start;
            padding: 18px;
            border: 2px solid var(--border-light);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }
        
        .export-option:hover {
            border-color: var(--primary-color);
            background: rgba(102, 126, 234, 0.05);
        }
        
        .export-option input[type="radio"] {
            margin-right: 12px;
            margin-top: 3px;
            cursor: pointer;
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
        }
        
        .export-option label {
            flex: 1;
            cursor: pointer;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .export-option label strong {
            font-size: 15px;
            color: var(--text-dark);
            display: block;
        }
        
        .option-desc {
            font-size: 13px;
            color: #999;
            font-weight: normal;
        }
        
        .export-dialog-footer {
            display: flex;
            gap: 12px;
            padding: 20px 30px;
            background: #f8f9fa;
            border-top: 1px solid var(--border-light);
            justify-content: flex-end;
        }
        
        .btn-export-cancel,
        .btn-export-submit {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-export-cancel {
            background: white;
            color: var(--text-dark);
            border: 2px solid var(--border-light);
        }
        
        .btn-export-cancel:hover {
            background: #f8f9fa;
            border-color: var(--text-dark);
        }
        
        .btn-export-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-export-submit:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .btn-export-submit:active {
            transform: translateY(0);
        }
        
        .year-wise-label {
            flex-direction: column;
            align-items: flex-start;
        }

        .statement-date-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 15px;
        }

        .statement-field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 6px;
        }

        .statement-field input,
        .statement-field select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--border-light);
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .statement-field input:focus,
        .statement-field select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .statement-columns-note {
            margin-top: 18px;
            font-size: 12px;
            color: #888;
            line-height: 1.6;
        }

        .statement-error {
            display: none;
            margin-top: 12px;
            color: #dc2626;
            font-size: 13px;
            font-weight: 600;
        }

        @media (max-width: 520px) {
            .statement-date-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .net-balance-container {
                padding: 15px;
            }
            
            .header-section {
                padding: 25px;
            }
            
            .header-section h1 {
                font-size: 24px;
            }
            
            .summary-cards {
                grid-template-columns: 1fr;
            }
            
            .controls-section .row {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-direction: row;
                width: 100%;
            }
            
            .action-buttons .btn {
                flex: 1;
                width: 100%;
                justify-content: center;
            }
        }
        
        .badge-neutral {
            background: var(--badge-neutral-bg, #e0e0e0);
            color: var(--badge-neutral-text, #666);
        }
        
        /* Checkbox styling */
        .members-table input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--gradient-start, #667eea);
        }
        
        .members-table th input[type="checkbox"] {
            cursor: pointer;
        }
        
        .members-table td {
            text-align: left;
        }
        
        .members-table td:first-child {
            text-align: center;
        }
        .badge-neutral {
            background: var(--badge-neutral-bg);
            color: var(--badge-neutral-text);
        }
        
        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #666;
        }
        
        .loading.show {
            display: block;
        }
        
        .spinner {
            border: 4px solid var(--spinner-bg);
            border-top: 4px solid var(--spinner-color);
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        
        .alert {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: none;
        }
        
        .alert.show {
            display: block;
        }
        
        .alert-success {
            background: var(--alert-success-bg);
            color: var(--alert-success-text);
            border-left: 4px solid var(--alert-success-border);
        }
        
        .alert-error {
            background: var(--alert-error-bg);
            color: var(--alert-error-text);
            border-left: 4px solid var(--alert-error-border);
        }
        
        .alert-warning {
            background: var(--alert-warning-bg);
            color: var(--alert-warning-text);
            border-left: 4px solid var(--alert-warning-border);
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        
        .pagination a:hover {
            background: var(--gradient-primary);
            color: white;
            border-color: var(--gradient-primary);
        }
        
        .pagination .active {
            background: var(--gradient-primary);
            color: white;
            border-color: var(--gradient-primary);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        
        .modal-content {
            background-color: var(--bg-secondary-solid);
            padding: 0;
            border-radius: 10px;
            box-shadow: var(--shadow-lg);
            width: 90%;
            max-width: 500px;
            max-height: 85vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header {
            background: var(--gradient-primary);
            color: white;
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 20px;
        }
        
        .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .close-btn:hover {
            opacity: 0.8;
        }
        
        .modal-body {
            padding: 25px;
            max-height: calc(85vh - 140px);
            overflow-y: auto;
        }
        
        .modal-footer {
            padding: 15px 25px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            background: var(--hover-bg);
        }
        
        .edit-form .form-group {
            margin-bottom: 20px;
        }
        
        .edit-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 14px;
        }
        
        .edit-form input[type="text"],
        .edit-form input[type="number"],
        .edit-form textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
            background: var(--bg-secondary-solid);
            color: var(--text-primary);
        }
        
        .edit-form input[type="text"]:focus,
        .edit-form input[type="number"]:focus,
        .edit-form textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px var(--focus-shadow);
        }
        
        .edit-form textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .edit-form small {
            display: block;
            margin-top: 5px;
            font-size: 12px;
            color: var(--text-secondary);
        }
        
        .warning-box {
            background: var(--alert-warning-bg);
            border-left: 4px solid var(--alert-warning-border);
            padding: 12px;
            border-radius: 5px;
            color: var(--alert-warning-text);
            font-size: 13px;
            margin-top: 15px;
        }
        
        .warning-box i {
            margin-right: 8px;
            color: var(--alert-warning-border);
        }
        
        /* Member Details Dialog Styles */
        .member-details-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            animation: fadeIn 0.3s ease-in-out;
            backdrop-filter: blur(2px);
        }
        
        .member-details-modal {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            overflow: hidden;
            animation: slideUp 0.3s ease-out;
        }
        
        .member-details-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 3px solid rgba(255,255,255,0.2);
        }
        
        .member-details-header i {
            font-size: 24px;
        }
        
        .member-details-body {
            padding: 30px;
        }
        
        .member-info {
            background: linear-gradient(135deg, #667eea15 0%, #764ba215 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            border-left: 4px solid #667eea;
        }
        
        .member-name {
            font-size: 18px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .member-id {
            font-size: 14px;
            color: #7f8c8d;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 15px;
        }
        
        .detail-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #ecf0f1;
            transition: all 0.3s ease;
        }
        
        .detail-item:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-2px);
            border-color: #667eea;
        }
        
        .detail-item.full-width {
            grid-column: 1 / -1;
            background: linear-gradient(135deg, #667eea08 0%, #764ba208 100%);
        }
        
        .detail-label {
            font-size: 12px;
            font-weight: 600;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .detail-value {
            font-size: 18px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .detail-value.collection-color {
            color: #3498db;
        }
        
        .detail-value.distribution-color {
            color: #2ecc71;
        }
        
        .detail-value.interest-color {
            color: #f39c12;
        }
        
        .detail-value.net-balance-value {
            font-size: 20px;
        }
        
        .detail-value.net-balance-value.positive {
            color: #27ae60;
            background: rgba(39, 174, 96, 0.1);
            padding: 10px 15px;
            border-radius: 6px;
            display: inline-block;
        }
        
        .detail-value.net-balance-value.negative {
            color: #e74c3c;
            background: rgba(231, 76, 60, 0.1);
            padding: 10px 15px;
            border-radius: 6px;
            display: inline-block;
        }
        
        .member-details-footer {
            padding: 20px 30px;
            background: #f8f9fa;
            border-top: 1px solid #ecf0f1;
            display: flex;
            justify-content: flex-end;
        }
        
        .btn-member-close {
            background: #495057;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-member-close:hover {
            background: #3a434b;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        
        .btn-member-close:active {
            transform: translateY(0);
        }
        
        /* Confirm Dialog Styles */
        .confirm-dialog-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            animation: fadeIn 0.3s ease-in-out;
            backdrop-filter: blur(3px);
        }
        
        .confirm-dialog-modal {
            background: white;
            border-radius: 12px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.4);
            max-width: 450px;
            width: 90%;
            overflow: hidden;
            animation: slideUp 0.3s ease-out;
        }
        
        .confirm-dialog-header {
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
            color: white;
            padding: 25px;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 3px solid rgba(255,255,255,0.2);
        }
        
        .confirm-dialog-header i {
            font-size: 24px;
        }
        
        .confirm-dialog-body {
            padding: 30px;
            color: #2c3e50;
            font-size: 15px;
            line-height: 1.6;
        }
        
        .confirm-dialog-body p {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .confirm-dialog-footer {
            padding: 20px 30px;
            background: #f8f9fa;
            border-top: 1px solid #ecf0f1;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        .btn-confirm-cancel,
        .btn-confirm-submit {
            padding: 11px 25px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-confirm-cancel {
            background: #ecf0f1;
            color: #2c3e50;
            border: 1px solid #bdc3c7;
        }
        
        .btn-confirm-cancel:hover {
            background: #d5dbdb;
            border-color: #95a5a6;
        }
        
        .btn-confirm-submit {
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            color: white;
        }
        
        .btn-confirm-submit:hover {
            box-shadow: 0 5px 18px rgba(46, 204, 113, 0.4);
            transform: translateY(-2px);
        }
        
        .btn-confirm-submit:active {
            transform: translateY(0);
        }
        
        /* Comprehensive Table Styling */
        .comprehensive-table {
            margin-top: 30px;
        }
        
        .comprehensive-table table thead tr {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .comprehensive-table table thead th {
            color: white;
            font-weight: 600;
            padding: 15px;
            text-align: left;
        }
        
        .comprehensive-table table thead th.collection-color {
            background-color: rgba(52, 152, 219, 0.7);
        }
        
        .comprehensive-table table thead th.distribution-color {
            background-color: rgba(46, 204, 113, 0.7);
        }
        
        .comprehensive-table table thead th.interest-color {
            background-color: rgba(243, 156, 18, 0.7);
        }
        
        .comprehensive-table table thead th.net-balance-color {
            background-color: rgba(102, 126, 234, 0.7);
        }
        
        .comprehensive-table table tbody tr {
            border-bottom: 1px solid #ecf0f1;
            transition: all 0.3s ease;
        }
        
        .comprehensive-table table tbody tr:hover {
            background-color: #f8f9fa;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .comprehensive-table table tbody td {
            padding: 15px;
            vertical-align: middle;
        }
        
        .comprehensive-table .amount-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
            background: rgba(0,0,0,0.05);
        }
        
        .comprehensive-table td.collection-color .amount-badge {
            color: #3498db;
            background: rgba(52, 152, 219, 0.1);
        }
        
        .comprehensive-table td.distribution-color .amount-badge {
            color: #2ecc71;
            background: rgba(46, 204, 113, 0.1);
        }
        
        .comprehensive-table td.interest-color .amount-badge {
            color: #f39c12;
            background: rgba(243, 156, 18, 0.1);
        }
        
        .comprehensive-table .member-row td:first-child {
            text-align: center;
        }
        
        /* Action Cards */
        .action-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        .action-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            transform: translateY(-2px);
            border-left-color: #764ba2;
        }
        
        .action-card h4 {
            margin: 0 0 10px 0;
            color: #2c3e50;
            font-size: 16px;
        }
        
        .action-card p {
            margin: 0;
            color: #7f8c8d;
            font-size: 14px;
            line-height: 1.5;
        }

        /* Dark Theme Styles */
        [data-theme="dark"] {
            --primary-color: #667eea;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --dark-bg: #0f172a;
            --card-bg: #1e293b;
            --text-dark: #f1f5f9;
            --border-light: #334155;
        }

        [data-theme="dark"] .net-balance-container {
            background: linear-gradient(135deg, #0f172a 0%, #1a1a2e 100%);
        }

        [data-theme="dark"] .controls-section {
            background: #1e293b;
            border-color: #334155;
        }

        [data-theme="dark"] .controls-section select,
        [data-theme="dark"] .controls-section input {
            background: #0f172a;
            color: #f1f5f9;
            border-color: #334155;
        }

        [data-theme="dark"] .controls-section select:focus,
        [data-theme="dark"] .controls-section input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        [data-theme="dark"] .stats-grid .stat-card {
            background: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }

        [data-theme="dark"] .stats-grid .stat-card h3 {
            color: #94a3b8;
        }

        [data-theme="dark"] .table-container table {
            background: #1e293b;
            color: #f1f5f9;
        }

        [data-theme="dark"] .table-container table thead {
            background: #0f172a;
            border-color: #334155;
        }

        [data-theme="dark"] .table-container table td,
        [data-theme="dark"] .table-container table th {
            border-color: #334155;
            color: #f1f5f9;
        }

        [data-theme="dark"] .table-container table tbody tr:hover {
            background: #293548;
        }

        [data-theme="dark"] .summary-card {
            background: #1e293b;
            border-color: #334155;
            color: #f1f5f9;
        }

        [data-theme="dark"] .summary-card h3 {
            color: #94a3b8;
        }

        [data-theme="dark"] .summary-card .amount {
            color: #f1f5f9;
        }

        [data-theme="dark"] .summary-card .amount.positive {
            color: #10b981;
        }

        [data-theme="dark"] .summary-card .amount.negative {
            color: #ef4444;
        }

        [data-theme="dark"] .members-table h3,
        [data-theme="dark"] h3[style*="color: #2c3e50"] {
            color: #f1f5f9 !important;
        }

        [data-theme="dark"] .comprehensive-table {
            background: #1e293b;
        }

        [data-theme="dark"] [style*="background: #f8f9fa"] {
            background: #1e293b !important;
            border-color: #334155 !important;
        }

        [data-theme="dark"] .header-section {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        }

        [data-theme="dark"] .controls-section {
            background: #1e293b;
            border-color: #334155;
        }

        [data-theme="dark"] .member-section h3 {
            color: #f1f5f9;
        }

        [data-theme="dark"] .info-box {
            background: #0f172a;
            border-color: #334155;
        }

        [data-theme="dark"] .info-box label {
            color: #94a3b8;
        }

        [data-theme="dark"] .info-value {
            color: #f1f5f9;
        }

        [data-theme="dark"] .action-card {
            background: #1e293b;
            border-color: #334155;
        }

        [data-theme="dark"] .action-card h4 {
            color: #f1f5f9;
        }

        [data-theme="dark"] .action-card p {
            color: #94a3b8;
        }

        [data-theme="dark"] .btn {
            color: white;
        }

        [data-theme="dark"] .modal-content {
            background: #1e293b;
            color: #f1f5f9;
        }

        [data-theme="dark"] .form-control {
            background: #0f172a;
            color: #f1f5f9;
            border-color: #334155;
        }

        [data-theme="dark"] .form-control:focus {
            border-color: #667eea;
        }

        [data-theme="dark"] .theme-toggle {
            color: #f1f5f9;
        }

        [data-theme="dark"] .theme-toggle:hover {
            background-color: rgba(102, 126, 234, 0.1);
        }

        [data-theme="dark"] .current-year-info {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-left-color: #667eea;
            color: #f1f5f9;
        }

        [data-theme="dark"] .members-table {
            background: #1e293b;
        }

        [data-theme="dark"] .members-table h3 {
            color: #f1f5f9 !important;
            border-bottom-color: #334155;
        }

        [data-theme="dark"] .members-table > div {
            background: #1e293b;
        }

        [data-theme="dark"] .members-table table {
            background: #1e293b;
            color: #f1f5f9;
        }

        [data-theme="dark"] .members-table th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        [data-theme="dark"] .members-table td {
            border-bottom-color: #334155;
            color: #f1f5f9;
        }

        [data-theme="dark"] .members-table tbody tr:hover {
            background: rgba(102, 126, 234, 0.1);
        }
    </style>
    <script src="theme-manager.js"></script>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="net-balance-container">
        <div class="header-section">
            <h1><i class="fas fa-balance-scale"></i> Net Balance Management</h1>
            <p>View and manage member net balances. Formula: Net Balance = (Distribution + Interest) - Collection</p>
        </div>
        
        <div class="controls-section">
            <div class="row">
                <div class="form-group">
                    <label for="yearFilter">Academic Year:</label>
                    <select id="yearFilter" onchange="filterByYear()">
                        <!-- All Years Option -->
                        <option value="0" <?php echo ($selectedYear == 0) ? 'selected' : ''; ?>>
                            All Years<?php echo !empty($yearRange) ? ' (' . htmlspecialchars($yearRange) . ')' : ''; ?>
                        </option>
                        <!-- Individual Years -->
                        <?php foreach ($academicYears as $year): ?>
                        <option value="<?php echo $year['YearID']; ?>" <?php echo ($selectedYear == $year['YearID']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($year['YearName']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="monthFilter">Month:</label>
                    <select id="monthFilter" onchange="filterByYear(true)" <?php echo $monthOptions ? '' : 'disabled title="Choose one academic year first"'; ?>>
                        <option value="">Whole year</option>
                        <?php foreach ($monthOptions as $mo): ?>
                        <option value="<?php echo htmlspecialchars($mo['value']); ?>" <?php echo $selectedMonth === $mo['value'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($mo['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="action-buttons">
                    <button class="btn btn-success" onclick="autoRecalculate()">
                        <i class="fas fa-redo"></i> Auto-Recalculate
                    </button>
                    <button class="btn btn-warning" onclick="exportReport()">
                        <i class="fas fa-download"></i> Export Report
                    </button>
                    <button class="btn btn-primary" onclick="openDistributionStatement()">
                        <i class="fas fa-file-signature"></i> Distribution Statement
                    </button>
                    <!-- <button class="btn btn-secondary" onclick="goBack()">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </button> -->
                </div>
            </div>
        </div>
        
        <div id="statusAlert" class="alert"></div>
        
        <!-- Current Year Info -->
        <div class="current-year-info">
            <i class="fas fa-calendar-alt" style="margin-right: 8px;"></i>
            <strong>Currently Viewing:</strong> 
            <span id="currentYearDisplay">
                <?php 
                if ($selectedYear == 0) {
                    echo 'All Years' . (!empty($yearRange) ? ' (' . htmlspecialchars($yearRange) . ')' : '');
                } else {
                    $displayYear = '';
                    foreach ($academicYears as $year) {
                        if ($year['YearID'] == $selectedYear) {
                            $displayYear = htmlspecialchars($year['YearName']);
                            break;
                        }
                    }
                    echo $displayYear;
                }
                if ($monthLabel !== '') {
                    echo ' &middot; ' . htmlspecialchars($monthLabel);
                }
                ?>
            </span>
            <?php if ($monthLabel !== ''): ?>
            <div style="margin-top:6px;font-size:13px;opacity:.85;">
                Collection, distribution, interest and change amount are for <?php echo htmlspecialchars($monthLabel); ?> only.
                Net balance is each member's balance at the end of <?php echo htmlspecialchars($monthLabel); ?>.
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Tab Navigation -->
        <!-- <div class="tab-navigation">
            <button class="tab-button active" data-tab="net-balance" onclick="switchTab('net-balance')">
                <i class="fas fa-balance-scale"></i> Net Balance
            </button>
            <button class="tab-button" data-tab="collection" onclick="switchTab('collection')">
                <i class="fas fa-inbox"></i> Collection
            </button>
            <button class="tab-button" data-tab="distribution" onclick="switchTab('distribution')">
                <i class="fas fa-arrow-right"></i> Distribution
            </button>
        </div> -->
        
        <!-- NET BALANCE TAB CONTENT -->
        <div id="net-balance" class="tab-content active">
            <div class="summary-cards">
                <div class="summary-card summary-card-net">
                    <h3>Total Collection</h3>
                    <p class="amount">₹<?php echo number_format($systemSummary['total_collection'] ?? 0, 2); ?></p>
                </div>
                <div class="summary-card summary-card-net">
                    <h3>Total Distribution</h3>
                    <p class="amount">₹<?php echo number_format($systemSummary['total_distributed'] ?? 0, 2); ?></p>
                </div>
                <div class="summary-card summary-card-net">
                    <h3>Total Interest</h3>
                    <p class="amount">₹<?php echo number_format($systemSummary['total_interest'] ?? 0, 2); ?></p>
                </div>
                <?php if (($systemSummary['change_amount'] ?? 0) != 0): ?>
                <div class="summary-card summary-card-net" style="border-left: 4px solid #8e44ad;">
                    <h3>Change Amount</h3>
                    <p class="amount" style="color: #8e44ad;">₹<?php echo number_format($systemSummary['change_amount'] ?? 0, 2); ?></p>
                    <small style="color: #95a5a6; font-size: 0.75em;">General (non-member) entries &middot; <a href="change-amount.php" style="color:#8e44ad;">manage</a></small>
                </div>
                <?php endif; ?>
                <?php if (($systemSummary['change_amount_members'] ?? 0) != 0): ?>
                <div class="summary-card summary-card-net" style="border-left: 4px solid #8e44ad;">
                    <h3>Member Change Amount</h3>
                    <p class="amount" style="color: #8e44ad;">₹<?php echo number_format($systemSummary['change_amount_members'] ?? 0, 2); ?></p>
                    <small style="color: #95a5a6; font-size: 0.75em;">Included in member balances</small>
                </div>
                <?php endif; ?>
                <div class="summary-card summary-card-net">
                    <h3>Net Balance (All Members)</h3>
                    <p class="amount <?php echo ($systemSummary['net_balance'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                        Rs. <?php echo number_format(abs($systemSummary['net_balance'] ?? 0), 2); ?>
                    </p>
                    <?php if (($systemSummary['change_amount'] ?? 0) != 0): ?>
                    <small style="color: #95a5a6; font-size: 0.75em;">Includes ₹<?php echo number_format($systemSummary['change_amount'] ?? 0, 2); ?> change amount</small>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Comprehensive Members Table with All Fields -->
            <div class="members-table comprehensive-table">
                <h3 style="color: #2c3e50;"><i class="fas fa-table"></i> Member Financial Summary</h3>
                <div style="overflow-y: auto; overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)" title="Select all members"></th>
                            <th>Member ID</th>
                            <th>Member Name</th>
                            <th class="collection-color"><i class="fas fa-arrow-down"></i> Collection</th>
                            <th class="distribution-color"><i class="fas fa-arrow-up"></i> Distribution</th>
                            <th class="interest-color"><i class="fas fa-percent"></i> Interest</th>
                            <th style="color:#8e44ad;"><i class="fas fa-coins"></i> Change Amt</th>
                            <th class="net-balance-color"><i class="fas fa-balance-scale"></i> Net Balance</th>
                            <th style="width: 100px;">Status</th>
                            <th style="width: 150px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="membersTableBody">
                        <?php foreach ($membersBalances as $member): 
                            $collAmount = $collectionMap[$member['MemberID']] ?? 0;
                            $distAmount = $distMap[$member['MemberID']] ?? 0;
                            $interestAmount = $interestMap[$member['MemberID']] ?? 0;
                        ?>
                        <tr class="member-row">
                            <td><input type="checkbox" class="member-checkbox" value="<?php echo $member['MemberID']; ?>" onchange="updateSelectAllCheckbox()"></td>
                            <td><strong><?php echo $member['MemberID']; ?></strong></td>
                            <td><?php echo htmlspecialchars($member['MemberName']); ?></td>
                            <td class="collection-color"><span class="amount-badge">Rs. <?php echo number_format($collAmount, 2); ?></span></td>
                            <td class="distribution-color"><span class="amount-badge">Rs. <?php echo number_format($distAmount, 2); ?></span></td>
                            <td class="interest-color"><span class="amount-badge">Rs. <?php echo number_format($interestAmount, 2); ?></span></td>
                            <td style="color:#8e44ad;"><span class="amount-badge">Rs. <?php echo number_format($changeMap[$member['MemberID']] ?? 0, 2); ?></span></td>
                            <td class="<?php echo ($member['NetBalance'] >= 0) ? 'positive' : 'negative'; ?>">
                                <strong>Rs. <?php echo number_format(abs($member['NetBalance']), 2); ?></strong>
                            </td>
                            <td>
                                <span class="badge <?php echo ($member['NetBalance'] > 0) ? 'badge-positive' : (($member['NetBalance'] < 0) ? 'badge-negative' : 'badge-neutral'); ?>">
                                    <?php echo ($member['NetBalance'] > 0) ? 'Due' : (($member['NetBalance'] < 0) ? 'Payable' : 'Neutral'); ?>
                                </span>
                            </td>
                            <td style="white-space: nowrap;">
                                <button class="btn btn-primary btn-sm" onclick="viewDetails(<?php echo $member['MemberID']; ?>)" title="View member details">
                                    <i class="fas fa-eye"></i> Details
                                </button>
                                <button class="btn btn-warning btn-sm" onclick="editBalance(<?php echo $member['MemberID']; ?>, '<?php echo htmlspecialchars($member['MemberName']); ?>', <?php echo $member['NetBalance']; ?>)" title="Edit member balance">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- Quick Actions Section -->
            <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 8px;">
                <h3 style="margin-bottom: 15px; color: #2c3e50;"><i class="fas fa-tools"></i> Quick Actions</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                    <div class="action-card">
                        <h4>View Member Details</h4>
                        <p>Click <strong>Details</strong> button in each row to view comprehensive member information</p>
                    </div>
                    <div class="action-card">
                        <h4>Edit Net Balance</h4>
                        <p>Click <strong>Edit</strong> button to manually adjust a member's net balance with audit trail</p>
                    </div>
                    <div class="action-card">
                        <h4>Auto-Recalculate</h4>
                        <p>Select members and click <strong>Auto-Recalculate</strong> to refresh balances from transactions</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- COLLECTION TAB CONTENT -->
        <div id="collection" class="tab-content">
            <div class="summary-cards">
                <div class="summary-card summary-card-collection">
                    <h3>Total Collection</h3>
                    <p class="amount positive">₹<?php echo number_format($systemSummary['total_collection'] ?? 0, 2); ?></p>
                </div>
            </div>
            
            <div class="members-table">
                <table>
                    <thead>
                        <tr>
                            <th>Member ID</th>
                            <th>Member Name</th>
                            <th>Collection Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (!empty($collectionData)) {
                            foreach ($collectionData as $rec) {
                                $memberName = '';
                                foreach ($membersBalances as $m) {
                                    if ($m['MemberID'] == $rec['MemberID']) {
                                        $memberName = $m['MemberName'];
                                        break;
                                    }
                                }
                                echo '<tr>';
                                echo '<td>' . $rec['MemberID'] . '</td>';
                                echo '<td>' . htmlspecialchars($memberName) . '</td>';
                                echo '<td class="positive">Rs. ' . number_format($rec['total_collection'] ?? 0, 2) . '</td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="3" style="text-align: center; padding: 30px; color: #999;">No collection data available</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- DISTRIBUTION TAB CONTENT -->
        <div id="distribution" class="tab-content">
            <div class="summary-cards">
                <div class="summary-card summary-card-distribution">
                    <h3>Total Distribution</h3>
                    <p class="amount positive">₹<?php echo number_format($systemSummary['total_distributed'] ?? 0, 2); ?></p>
                </div>
            </div>
            
            <div class="members-table">
                <table>
                    <thead>
                        <tr>
                            <th>Member ID</th>
                            <th>Member Name</th>
                            <th>Distribution Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (!empty($distData)) {
                            foreach ($distData as $rec) {
                                $memberName = '';
                                foreach ($membersBalances as $m) {
                                    if ($m['MemberID'] == $rec['MemberID']) {
                                        $memberName = $m['MemberName'];
                                        break;
                                    }
                                }
                                echo '<tr>';
                                echo '<td>' . $rec['MemberID'] . '</td>';
                                echo '<td>' . htmlspecialchars($memberName) . '</td>';
                                echo '<td class="positive">Rs. ' . number_format($rec['total_distribution'] ?? 0, 2) . '</td>';
                                echo '</tr>';
                            }
                        } else {
                            echo '<tr><td colspan="3" style="text-align: center; padding: 30px; color: #999;">No distribution data available</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="loading" id="loadingSpinner">
            <div class="spinner"></div>
            <p>Processing...</p>
        </div>
    </div>
    
    <script>
        // Expose academic years data to JavaScript
        const academicYearsData = <?php echo json_encode($academicYears); ?>;
        
        function filterByYear(keepMonth) {
            const yearId = document.getElementById('yearFilter').value;
            const month = keepMonth ? document.getElementById('monthFilter').value : '';
            window.location.href = '?year=' + yearId + (month ? '&month=' + encodeURIComponent(month) : '');
        }
        
        // Tab switching function
        function switchTab(tabName) {
            // Hide all tabs
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(tab => tab.classList.remove('active'));
            
            // Show selected tab
            const selectedTab = document.getElementById(tabName);
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
            
            // Update button states
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(btn => {
                if (btn.dataset.tab === tabName) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }
        
        function toggleSelectAll(checkbox) {
            const memberCheckboxes = document.querySelectorAll('.member-checkbox');
            memberCheckboxes.forEach(cb => cb.checked = checkbox.checked);
        }
        
        function updateSelectAllCheckbox() {
            const memberCheckboxes = document.querySelectorAll('.member-checkbox');
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            const allChecked = Array.from(memberCheckboxes).every(cb => cb.checked);
            const someChecked = Array.from(memberCheckboxes).some(cb => cb.checked);
            
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = someChecked && !allChecked;
        }
        
        function getSelectedMemberIds() {
            const memberCheckboxes = document.querySelectorAll('.member-checkbox:checked');
            return Array.from(memberCheckboxes).map(cb => cb.value);
        }
        
        function autoRecalculate() {
            const selectedMembers = getSelectedMemberIds();
            
            if (selectedMembers.length === 0) {
                showAlert('⚠ Please select at least one member to recalculate', 'warning');
                return;
            }
            
            const message = selectedMembers.length === 1 
                ? 'This will recalculate the net balance for 1 selected member. Continue?'
                : 'This will recalculate net balances for ' + selectedMembers.length + ' selected members. Continue?';
            
            showConfirmDialog('Confirm Recalculation', message, 'Recalculate', 'Cancel', function(confirmed) {
                if (!confirmed) return;
                
                showLoading(true);
                const yearId = document.getElementById('yearFilter').value;
                
                const requestData = {
                    action: 'auto_recalculate',
                    year_id: yearId,
                    member_ids: selectedMembers
                };
                
                console.log('Sending auto-recalculate request:', requestData);
                
                fetch('api-net-balance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(requestData)
                })
                .then(response => {
                    console.log('Response status:', response.status);
                    console.log('Response headers:', response.headers);
                    return response.text();
                })
                .then(text => {
                    console.log('Response text:', text);
                    try {
                        const data = JSON.parse(text);
                        showLoading(false);
                        if (data.success) {
                            showAlert('✓ Successfully recalculated net balances for ' + data.changes + ' member(s)!', 'success');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            showAlert('✗ Error: ' + (data.message || data.error || 'Unknown error'), 'error');
                        }
                    } catch (e) {
                        showLoading(false);
                        console.error('JSON parse error:', e);
                        showAlert('✗ Server error: ' + text, 'error');
                    }
                })
                .catch(error => {
                    showLoading(false);
                    console.error('Fetch error:', error);
                    showAlert('✗ Network error: ' + error.message, 'error');
                });
            });
        }
        
        function viewDetails(memberId) {
            const yearId = document.getElementById('yearFilter').value;
            
            fetch('api-net-balance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'action=get_member_details&member_id=' + memberId + '&year_id=' + yearId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const details = data.details;
                    showMemberDetailsDialog(data.member_name, data.member_id, details);
                } else {
                    showAlert('Error: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Error: ' + error.message, 'error');
            });
        }
        
        function showConfirmDialog(title, message, confirmBtnText, cancelBtnText, callback) {
            // Create modal overlay
            const overlay = document.createElement('div');
            overlay.className = 'confirm-dialog-overlay';
            overlay.id = 'confirmDialogOverlay';
            
            // Create modal container
            const modal = document.createElement('div');
            modal.className = 'confirm-dialog-modal';
            
            // Modal header
            const header = document.createElement('div');
            header.className = 'confirm-dialog-header';
            header.innerHTML = `<i class="fas fa-info-circle"></i> ${title}`;
            
            // Modal body
            const body = document.createElement('div');
            body.className = 'confirm-dialog-body';
            body.innerHTML = `<p>${message.replace(/\n/g, '<br>')}</p>`;
            
            // Modal footer
            const footer = document.createElement('div');
            footer.className = 'confirm-dialog-footer';
            
            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn-confirm-cancel';
            cancelBtn.innerHTML = `<i class="fas fa-times"></i> ${cancelBtnText}`;
            cancelBtn.onclick = function() {
                document.body.removeChild(overlay);
                callback(false);
            };
            
            const confirmBtn = document.createElement('button');
            confirmBtn.className = 'btn-confirm-submit';
            confirmBtn.innerHTML = `<i class="fas fa-check"></i> ${confirmBtnText}`;
            confirmBtn.onclick = function() {
                document.body.removeChild(overlay);
                callback(true);
            };
            
            footer.appendChild(cancelBtn);
            footer.appendChild(confirmBtn);
            
            // Assemble modal
            modal.appendChild(header);
            modal.appendChild(body);
            modal.appendChild(footer);
            overlay.appendChild(modal);
            
            // Add to DOM
            document.body.appendChild(overlay);
            
            // Auto-focus on confirm button
            confirmBtn.focus();
            
            // Allow Escape key to cancel
            const escapeHandler = function(e) {
                if (e.key === 'Escape') {
                    document.body.removeChild(overlay);
                    document.removeEventListener('keydown', escapeHandler);
                    callback(false);
                }
            };
            document.addEventListener('keydown', escapeHandler);
            
            // Close on background click
            overlay.onclick = function(e) {
                if (e.target === overlay) {
                    document.body.removeChild(overlay);
                    document.removeEventListener('keydown', escapeHandler);
                    callback(false);
                }
            };
        }
        
        function showMemberDetailsDialog(memberName, memberId, details) {
            // Create modal overlay
            const overlay = document.createElement('div');
            overlay.className = 'member-details-overlay';
            overlay.id = 'memberDetailsOverlay';
            
            // Create modal container
            const modal = document.createElement('div');
            modal.className = 'member-details-modal';
            
            // Modal header
            const header = document.createElement('div');
            header.className = 'member-details-header';
            header.innerHTML = `<i class="fas fa-user-circle"></i> Member Details`;
            
            // Modal body with details
            const body = document.createElement('div');
            body.className = 'member-details-body';
            
            const netBalanceClass = parseFloat(details.net_balance) >= 0 ? 'positive' : 'negative';
            
            body.innerHTML = `
                <div class="member-info">
                    <div class="member-name">${memberName}</div>
                    <div class="member-id">ID: ${memberId}</div>
                </div>
                
                <div class="details-grid">
                    <div class="detail-item">
                        <div class="detail-label">Transactions</div>
                        <div class="detail-value">${details.transaction_count}</div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Collection</div>
                        <div class="detail-value collection-color">Rs. ${parseFloat(details.total_collection).toFixed(2)}</div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Distribution</div>
                        <div class="detail-value distribution-color">Rs. ${parseFloat(details.total_distributed).toFixed(2)}</div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Interest</div>
                        <div class="detail-value interest-color">Rs. ${parseFloat(details.total_interest).toFixed(2)}</div>
                    </div>
                    
                    <div class="detail-item full-width">
                        <div class="detail-label">Net Balance</div>
                        <div class="detail-value net-balance-value ${netBalanceClass}">Rs. ${Math.abs(parseFloat(details.net_balance)).toFixed(2)}</div>
                    </div>
                </div>
            `;
            
            // Modal footer
            const footer = document.createElement('div');
            footer.className = 'member-details-footer';
            
            const closeBtn = document.createElement('button');
            closeBtn.className = 'btn-member-close';
            closeBtn.innerHTML = '<i class="fas fa-times"></i> Close';
            closeBtn.onclick = function() {
                document.body.removeChild(overlay);
            };
            
            footer.appendChild(closeBtn);
            
            // Assemble modal
            modal.appendChild(header);
            modal.appendChild(body);
            modal.appendChild(footer);
            overlay.appendChild(modal);
            
            // Add to DOM and focus
            document.body.appendChild(overlay);
            document.querySelector('.member-details-modal').focus();
            
            // Close on background click
            overlay.onclick = function(e) {
                if (e.target === overlay) {
                    document.body.removeChild(overlay);
                }
            };
        }
        
        function exportReport() {
            const currentYearId = document.getElementById('yearFilter').value;
            const currentYearName = document.getElementById('currentYearDisplay').textContent;
            
            // Show custom dialog asking for export type
            showExportDialog(currentYearId, academicYearsData, function(selectedYear, exportType) {
                if (exportType === null) {
                    return; // User cancelled
                }
                
                // Show loading
                showLoading(true);
                
                // Request PDF export
                fetch('export-net-balance-pdf.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'year_id=' + encodeURIComponent(selectedYear) + '&export_type=' + encodeURIComponent(exportType)
                })
                .then(response => {
                    if (!response.ok) {
                        // Try to read error message
                        return response.text().then(text => {
                            console.error('Response status:', response.status);
                            console.error('Response text:', text);
                            throw new Error('HTTP ' + response.status + ': ' + text);
                        });
                    }
                    return response.blob();
                })
                .then(blob => {
                    // Check if blob is empty or is error
                    if (blob.size === 0) {
                        throw new Error('Empty response received');
                    }
                    
                    // Check if it's actually a PDF (should start with %PDF)
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const header = String.fromCharCode.apply(null, new Uint8Array(e.target.result).slice(0, 4));
                        if (!header.includes('%PDF')) {
                            throw new Error('Invalid PDF file received');
                        }
                        
                        showLoading(false);
                        
                        // Create download link
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        
                        const filename = exportType === 'all-years' 
                            ? 'net-balance-report-all-years-' + new Date().toISOString().split('T')[0] + '.pdf'
                            : 'net-balance-report-' + new Date().toISOString().split('T')[0] + '.pdf';
                        
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                        document.body.removeChild(a);
                        
                        showAlert('✓ PDF Report exported successfully!', 'success');
                    };
                    reader.readAsArrayBuffer(blob);
                })
                .catch(error => {
                    showLoading(false);
                    console.error('Export error:', error);
                    showAlert('✗ Error: ' + error.message, 'error');
                });
            });
        }
        
        function showExportDialog(currentYearId, yearsData, callback) {
            // Create modal overlay
            const overlay = document.createElement('div');
            overlay.className = 'export-dialog-overlay';
            overlay.id = 'exportDialogOverlay';
            
            // Create modal container
            const modal = document.createElement('div');
            modal.className = 'export-dialog-modal';
            
            // Modal header
            const header = document.createElement('div');
            header.className = 'export-dialog-header';
            header.innerHTML = '<i class="fas fa-download"></i> Choose Export Type';
            
            // Build year options
            let yearOptionsHTML = '';
            yearsData.forEach(year => {
                const selected = year.YearID == currentYearId ? 'selected' : '';
                yearOptionsHTML += `<option value="${year.YearID}" ${selected}>${year.YearName}</option>`;
            });
            
            // Modal body
            const body = document.createElement('div');
            body.className = 'export-dialog-body';
            body.innerHTML = `
                <p>Select how you want to export the net balance report:</p>
                <div class="export-option-container">
                    <div class="export-option" data-type="year-wise">
                        <input type="radio" id="yearWiseOption" name="exportType" value="year-wise" checked>
                        <label for="yearWiseOption" class="year-wise-label">
                            <strong>Year-wise Report</strong>
                            <span class="option-desc">For the selected year</span>
                            <select id="yearDropdown" class="year-dropdown">
                                ${yearOptionsHTML}
                            </select>
                        </label>
                    </div>
                    <div class="export-option" data-type="all-years">
                        <input type="radio" id="allYearsOption" name="exportType" value="all-years">
                        <label for="allYearsOption">
                            <strong>All Years Report</strong>
                            <span class="option-desc">Aggregate data from all academic years</span>
                        </label>
                    </div>
                </div>
            `;
            
            // Modal footer
            const footer = document.createElement('div');
            footer.className = 'export-dialog-footer';
            
            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn-export-cancel';
            cancelBtn.innerHTML = 'Cancel';
            cancelBtn.onclick = function() {
                document.body.removeChild(overlay);
                callback(null, null);
            };
            
            const exportBtn = document.createElement('button');
            exportBtn.className = 'btn-export-submit';
            exportBtn.innerHTML = '<i class="fas fa-download"></i> Export PDF';
            exportBtn.onclick = function() {
                const exportType = document.querySelector('input[name="exportType"]:checked').value;
                let selectedYear = currentYearId;
                
                if (exportType === 'year-wise') {
                    selectedYear = document.getElementById('yearDropdown').value;
                } else {
                    selectedYear = 0; // All years
                }
                
                document.body.removeChild(overlay);
                callback(selectedYear, exportType);
            };
            
            footer.appendChild(cancelBtn);
            footer.appendChild(exportBtn);
            
            // Assemble modal
            modal.appendChild(header);
            modal.appendChild(body);
            modal.appendChild(footer);
            overlay.appendChild(modal);
            
            // Add to DOM
            document.body.appendChild(overlay);
            
            // Focus on dialog
            document.querySelector('.export-dialog-modal').focus();
        }
        
        function openDistributionStatement() {
            const escapeHtml = s => String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
            const toIso = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            const today = new Date();

            // Default to the selected academic year's dates, else 1 Jan to today
            const currentYearId = document.getElementById('yearFilter').value;
            const selectedYear = academicYearsData.find(y => y.YearID == currentYearId);
            const defaultFrom = selectedYear ? selectedYear.StartDate : toIso(new Date(today.getFullYear(), 0, 1));
            const defaultTo = selectedYear ? selectedYear.EndDate : toIso(today);

            let yearOptionsHTML = '<option value="">Custom range</option>';
            academicYearsData.forEach(year => {
                const selected = selectedYear && year.YearID == selectedYear.YearID ? 'selected' : '';
                yearOptionsHTML += `<option value="${escapeHtml(year.YearID)}" ${selected}>${escapeHtml(year.YearName)}</option>`;
            });

            const overlay = document.createElement('div');
            overlay.className = 'export-dialog-overlay';
            overlay.innerHTML = `
                <div class="export-dialog-modal" tabindex="-1">
                    <div class="export-dialog-header">
                        <i class="fas fa-file-signature"></i> Distribution &amp; Interest Statement
                    </div>
                    <div class="export-dialog-body">
                        <p>Generate a PDF on letterhead listing every distribution and interest entry between two dates.</p>
                        <div class="statement-field">
                            <label for="statementYear">Fill dates from academic year</label>
                            <select id="statementYear">${yearOptionsHTML}</select>
                        </div>
                        <div class="statement-date-grid">
                            <div class="statement-field">
                                <label for="statementFrom">From date</label>
                                <input type="date" id="statementFrom" value="${escapeHtml(defaultFrom)}">
                            </div>
                            <div class="statement-field">
                                <label for="statementTo">To date</label>
                                <input type="date" id="statementTo" value="${escapeHtml(defaultTo)}">
                            </div>
                        </div>
                        <div class="statement-error" id="statementError"></div>
                        <div class="statement-columns-note">
                            <strong>Columns:</strong> Member ID · Date · Member Name · Distributed Amount · Interest Amount · Due Date · Remark
                        </div>
                    </div>
                    <div class="export-dialog-footer">
                        <button type="button" class="btn-export-cancel" id="statementCancel">Cancel</button>
                        <button type="button" class="btn-export-submit" id="statementGenerate"><i class="fas fa-file-pdf"></i> Generate PDF</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);
            overlay.querySelector('.export-dialog-modal').focus();

            const close = () => overlay.remove();
            const fromInput = overlay.querySelector('#statementFrom');
            const toInput = overlay.querySelector('#statementTo');
            const errorBox = overlay.querySelector('#statementError');

            overlay.querySelector('#statementYear').onchange = function() {
                const year = academicYearsData.find(y => y.YearID == this.value);
                if (year) {
                    fromInput.value = year.StartDate;
                    toInput.value = year.EndDate;
                }
            };
            [fromInput, toInput].forEach(input => input.addEventListener('input', () => {
                overlay.querySelector('#statementYear').value = '';
            }));
            overlay.querySelector('#statementCancel').onclick = close;
            overlay.onclick = e => { if (e.target === overlay) close(); };

            overlay.querySelector('#statementGenerate').onclick = function() {
                const from = fromInput.value;
                const to = toInput.value;
                if (!from || !to) {
                    errorBox.textContent = 'Please choose both a From date and a To date.';
                    errorBox.style.display = 'block';
                    return;
                }
                if (from > to) {
                    errorBox.textContent = 'The From date must be on or before the To date.';
                    errorBox.style.display = 'block';
                    return;
                }

                // Open the PDF in a new tab so it can be viewed, printed or saved
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'export-distribution-statement-pdf.php';
                form.target = '_blank';
                [['start_date', from], ['end_date', to]].forEach(([name, value]) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = value;
                    form.appendChild(input);
                });
                document.body.appendChild(form);
                form.submit();
                form.remove();
                close();
                showAlert('✓ Distribution statement opened in a new tab.', 'success');
            };
        }

        function showLoading(show) {
            document.getElementById('loadingSpinner').classList.toggle('show', show);
        }
        
        function showAlert(message, type) {
            const alert = document.getElementById('statusAlert');
            alert.innerHTML = message;
            alert.className = 'alert show alert-' + type;
            setTimeout(() => alert.classList.remove('show'), 5000);
        }
        
        function goBack() {
            window.location.href = 'dashboard.php';
        }
        
        function editBalance(memberId, memberName, currentBalance) {
            document.getElementById('editMemberId').value = memberId;
            document.getElementById('editMemberName').textContent = memberName + ' (ID: ' + memberId + ')';
            document.getElementById('editCurrentBalance').value = currentBalance.toFixed(2);
            document.getElementById('editNewBalance').value = currentBalance.toFixed(2);
            document.getElementById('editReason').value = '';
            document.getElementById('editModal').style.display = 'flex';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        function saveBalanceEdit() {
            const memberId = document.getElementById('editMemberId').value;
            const newBalance = document.getElementById('editNewBalance').value;
            const reason = document.getElementById('editReason').value.trim();
            const yearId = document.getElementById('yearFilter').value;
            
            if (!newBalance || isNaN(newBalance)) {
                alert('Please enter a valid balance amount');
                return;
            }
            
            if (!reason) {
                alert('Please provide a reason for the change');
                return;
            }
            
            const confirmMessage = 'Are you sure you want to manually update this balance?\n\nMember ID: ' + memberId + '\nNew Balance: Rs. ' + parseFloat(newBalance).toFixed(2) + '\nReason: ' + reason;
            
            closeEditModal();
            
            showConfirmDialog('Confirm Balance Update', confirmMessage, 'Update', 'Cancel', function(confirmed) {
                if (!confirmed) return;
                
                showLoading(true);
                
                fetch('api-net-balance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=manual_update&member_id=' + memberId + '&new_balance=' + encodeURIComponent(newBalance) + '&reason=' + encodeURIComponent(reason) + '&year_id=' + encodeURIComponent(yearId)
                })
                .then(response => response.json())
                .then(data => {
                    showLoading(false);
                    if (data.success) {
                        showAlert('✓ Net balance updated successfully! Change logged in audit trail.', 'success');
                        closeEditModal();
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showAlert('✗ Error: ' + (data.message || 'Unknown error'), 'error');
                    }
                })
                .catch(error => {
                    showLoading(false);
                    showAlert('✗ Error: ' + error.message, 'error');
                });
            });
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
    </script>
    
    <!-- Manual Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Manual Net Balance Edit</h2>
                <button class="close-btn" onclick="closeEditModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="edit-form">
                    <input type="hidden" id="editMemberId">
                    
                    <div class="form-group">
                        <label>Member:</label>
                        <p id="editMemberName" style="font-weight: 600; color: #333; margin: 5px 0;"></p>
                    </div>
                    
                    <div class="form-group">
                        <label>Current Net Balance:</label>
                        <input type="text" id="editCurrentBalance" readonly style="background: #f0f0f0; cursor: not-allowed;">
                    </div>
                    
                    <div class="form-group">
                        <label>New Net Balance (₹): <span style="color: red;">*</span></label>
                        <input type="number" id="editNewBalance" step="0.01" placeholder="Enter new net balance amount" required>
                        <small style="color: #666;">Formula: (Distribution + Interest) - Collection</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Reason for Change: <span style="color: red;">*</span></label>
                        <textarea id="editReason" placeholder="Explain why this balance is being manually adjusted" rows="4" required></textarea>
                        <small style="color: #666;">This will be logged in the audit trail for compliance</small>
                    </div>
                    
                    <div class="warning-box">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning:</strong> Manual balance edits are logged and auditable. Ensure the adjustment is justified.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveBalanceEdit()">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</body>
</html>
