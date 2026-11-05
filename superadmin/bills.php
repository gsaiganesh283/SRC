<?php
// Super Admin - Bill Generator: due bills, receipts, statements, event receipts and expense vouchers
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/logo-helper.php';
require_once 'fpdf.php';
require_once 'includes/bill-lib.php';

if (empty($_SESSION['rb_token'])) {
    $_SESSION['rb_token'] = bin2hex(random_bytes(16));
}

$success = $error = '';
if (!empty($_SESSION['bills_success'])) {
    $success = $_SESSION['bills_success'];
    unset($_SESSION['bills_success']);
}

try {
    billEnsureTables($pdo);
} catch (Exception $e) {
    $error = 'The bill tables could not be created: ' . $e->getMessage();
}

/** bill_ids[] or "1,2,3" from the form */
function billsPostedIds() {
    $ids = $_POST['bill_ids'] ?? [];
    if (is_string($ids)) $ids = preg_split('/[\s,]+/', $ids);
    return array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
}

// ── Actions ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['rb_token'], (string)($_POST['rb_token'] ?? ''))) {
        $error = 'The page expired. Please reload it and try again.';
    } else {
        try {
            if ($action === 'save_settings') {
                $raw = [
                    'number_format' => $_POST['number_format'] ?? '',
                    'seq_digits' => $_POST['seq_digits'] ?? 4,
                    'prefixes' => (array)($_POST['prefixes'] ?? []),
                    'terms' => (array)($_POST['terms'] ?? []),
                    'letterhead_id' => $_POST['letterhead_id'] ?? 0,
                    'due_days' => $_POST['due_days'] ?? 10,
                    'sign_left' => $_POST['sign_left'] ?? '',
                    'sign_right' => $_POST['sign_right'] ?? '',
                    'show_words' => $_POST['show_words'] ?? '0',
                    'show_signature' => $_POST['show_signature'] ?? '0',
                ];
                $format = trim((string)$raw['number_format']);
                if (strpos($format, '{SEQ}') === false) {
                    $error = 'The bill number format must contain {SEQ} (the running number).';
                } elseif (billNormalizeSettings($raw)['number_format'] !== $format) {
                    $error = 'The bill number format may only use letters, numbers, / - _ . and the {PREFIX} {FY} {YYYY} {YY} {MM} {SEQ} codes (up to 60 characters).';
                } else {
                    billSaveSettings($pdo, $raw, $_SESSION['superadmin_id']);
                    createAuditLog($_SESSION['superadmin_id'], 'SuperAdmin', 'Bills', 'bill_settings', 'Bill Generator settings saved', 'system_settings');
                    $success = 'Bill settings saved.';
                }
            } elseif ($action === 'auto_paid') {
                $n = billAutoMarkPaid($pdo);
                createAuditLog($_SESSION['superadmin_id'], 'SuperAdmin', 'Bills', 'bills_auto_paid', $n . ' due bill(s) marked Paid from received payments', 'bills');
                $success = $n ? $n . ' due bill(s) were paid in full and are now marked Paid.' : 'No unpaid due bill has been paid in full yet.';
            } elseif (in_array($action, ['mark_paid', 'mark_unpaid', 'cancel', 'delete'], true)) {
                $ids = billsPostedIds();
                if (!$ids) {
                    $error = 'Select one or more bills first.';
                } else {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    if ($action === 'mark_paid') {
                        $s = $pdo->prepare("UPDATE bills SET Status = 'Paid', PaidAt = NOW() WHERE BillID IN ($in) AND BillType = 'due' AND Status = 'Unpaid'");
                        $s->execute($ids);
                        $success = $s->rowCount() . ' bill(s) marked Paid.' . ($s->rowCount() < count($ids) ? ' Only unpaid due bills can be marked Paid.' : '');
                    } elseif ($action === 'mark_unpaid') {
                        $s = $pdo->prepare("UPDATE bills SET Status = 'Unpaid', PaidAt = NULL WHERE BillID IN ($in) AND BillType = 'due' AND Status = 'Paid'");
                        $s->execute($ids);
                        $success = $s->rowCount() . ' bill(s) marked Unpaid.';
                    } elseif ($action === 'cancel') {
                        $reason = mb_substr(trim((string)($_POST['cancel_reason'] ?? '')), 0, 255) ?: 'Cancelled';
                        $s = $pdo->prepare("UPDATE bills SET Status = 'Cancelled', CancelledAt = NOW(), CancelReason = ? WHERE BillID IN ($in) AND Status <> 'Cancelled'");
                        $s->execute(array_merge([$reason], $ids));
                        $success = $s->rowCount() . ' bill(s) cancelled. Their numbers stay used, so the bill series has no gaps in the history.';
                    } else {
                        $s = $pdo->prepare("DELETE FROM bills WHERE BillID IN ($in) AND Status = 'Cancelled'");
                        $s->execute($ids);
                        $success = $s->rowCount() . ' cancelled bill(s) deleted.' . ($s->rowCount() < count($ids) ? ' Only cancelled bills can be deleted - cancel a bill first.' : '');
                    }
                    createAuditLog($_SESSION['superadmin_id'], 'SuperAdmin', 'Bills', 'bills_' . $action, $success . ' IDs: ' . implode(', ', array_slice($ids, 0, 50)), 'bills');
                }
            }
        } catch (Exception $e) {
            $error = 'The action failed: ' . $e->getMessage();
        }
    }
}

// ── Data for the page (and the desktop app) ───────────────────────────────
$billTypes = [];
foreach (BILL_TYPES as $key => $info) {
    $billTypes[$key] = ['label' => $info[0], 'title' => $info[1], 'icon' => $info[3]];
}
$billStatuses = BILL_STATUSES;
$paymentModes = BILL_PAYMENT_MODES;
$billSettings = billSettings($pdo);
$academicYears = $pdo->query('SELECT YearID, YearName, StartDate, EndDate, Status FROM academic_years ORDER BY StartDate DESC')->fetchAll(PDO::FETCH_ASSOC);
$currentYearId = 0;
foreach ($academicYears as $y) {
    if ($y['Status'] === 'Active') { $currentYearId = (int)$y['YearID']; break; }
}
if (!$currentYearId && $academicYears) $currentYearId = (int)$academicYears[0]['YearID'];
$members = $pdo->query("SELECT MemberID, MemberName, Phone, Status FROM members ORDER BY MemberName")->fetchAll(PDO::FETCH_ASSOC);
$yearMemberIds = [];
foreach ($pdo->query("SELECT DISTINCT YearID, MemberID FROM transactions UNION SELECT YearID, MemberID FROM year_members WHERE Status = 'Active'")->fetchAll(PDO::FETCH_ASSOC) as $ym) {
    $yearMemberIds[(int)$ym['YearID']][] = (int)$ym['MemberID'];
}
try {
    $events = $pdo->query('SELECT EventID, EventName, EventDate, Status FROM events ORDER BY EventDate DESC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $events = [];
}
try {
    $expenseCategories = $pdo->query("SELECT DISTINCT Category FROM expenses WHERE Category <> '' ORDER BY Category")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $expenseCategories = [];
}
$letterheads = array_map(function ($lh) { return ['id' => $lh['id'], 'name' => $lh['name'], 'is_default' => $lh['is_default']]; }, rbListLetterheads($pdo));
$zipAvailable = class_exists('ZipArchive');
$tab = in_array($_GET['tab'] ?? '', ['generate', 'history', 'settings'], true) ? $_GET['tab'] : 'generate';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') $tab = 'settings';
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') $tab = 'history';

$billStats = $error && strpos($error, 'bill tables') !== false ? [] : billStats($pdo);
$filters = billListFilters($_GET);
$listing = $error && strpos($error, 'bill tables') !== false ? ['rows' => [], 'total' => 0, 'sum' => 0, 'page' => 1, 'pages' => 1] : billList($pdo, $filters, (int)($_GET['p'] ?? 1));
$bills = $listing['rows'];
$totalBills = $listing['total'];
$billSum = $listing['sum'];
$pageNo = $listing['page'];
$totalPages = $listing['pages'];
$page_title = 'Bill Generator';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function billsPageUrl(array $over) {
    $q = array_merge($_GET, $over);
    return 'bills.php?' . http_build_query(array_filter($q, function ($v) { return $v !== '' && $v !== null; }));
}
$statusBadge = ['Unpaid' => 'badge-warning', 'Paid' => 'badge-success', 'Issued' => 'badge-info', 'Cancelled' => 'badge-danger'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bill Generator - Super Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
    <script src="theme-manager.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .bg-btn-ghost { background: var(--bg-tertiary); color: var(--text-primary); border: 1px solid var(--border-color); }
        .bg-btn-ghost:hover { border-color: var(--gradient-1); }
        .bg-page { max-width: 1280px; margin: 0 auto; padding: 20px; }
        .bg-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .bg-head h1 { font-size: 26px; color: var(--text-primary); display: flex; align-items: center; gap: 10px; margin: 0 0 4px; }
        .bg-head p { color: var(--text-secondary); margin: 0; }
        .bg-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 18px; }
        .bg-stat { background: var(--bg-secondary); border-radius: 12px; padding: 14px 16px; box-shadow: var(--shadow); border-left: 4px solid var(--gradient-1); }
        .bg-stat .l { font-size: 12px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .4px; }
        .bg-stat .v { font-size: 22px; font-weight: 700; color: var(--text-primary); margin-top: 4px; }
        .bg-stat.warn { border-left-color: #f59e0b; } .bg-stat.ok { border-left-color: #10b981; } .bg-stat.bad { border-left-color: #ef4444; }
        .bg-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
        .bg-tab { padding: 10px 18px; border-radius: 10px; background: var(--bg-secondary); color: var(--text-primary); text-decoration: none; font-weight: 600; box-shadow: var(--shadow); border: 2px solid transparent; cursor: pointer; }
        .bg-tab.active { background: var(--gradient-primary); color: #fff; }
        .bg-panel { display: none; } .bg-panel.active { display: block; }
        .bg-card { background: var(--bg-secondary); border-radius: 12px; box-shadow: var(--shadow); padding: 18px 20px; margin-bottom: 16px; }
        .bg-card h3 { margin: 0 0 14px; font-size: 16px; color: var(--text-primary); display: flex; gap: 8px; align-items: center; }
        .bg-card h3 .hint { font-weight: 400; font-size: 12px; color: var(--text-secondary); margin-left: 6px; }
        .bg-types { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; }
        .bg-type { position: relative; border: 2px solid var(--border-color); border-radius: 12px; padding: 14px; cursor: pointer; background: var(--bg-tertiary); text-align: center; transition: all .2s; }
        .bg-type input { position: absolute; opacity: 0; }
        .bg-type i { font-size: 24px; color: var(--gradient-1); display: block; margin-bottom: 6px; }
        .bg-type span { font-weight: 600; font-size: 13px; color: var(--text-primary); }
        .bg-type small { display: block; color: var(--text-secondary); font-size: 11px; margin-top: 4px; }
        .bg-type:has(input:checked) { border-color: var(--gradient-1); background: var(--bg-secondary); box-shadow: 0 0 0 3px var(--focus-shadow); }
        .bg-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px 16px; }
        .bg-grid .span2 { grid-column: span 2; } .bg-grid .span4 { grid-column: 1 / -1; }
        .bg-field label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
        .bg-field input[type=text], .bg-field input[type=number], .bg-field input[type=date], .bg-field select, .bg-field textarea {
            width: 100%; padding: 9px 10px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-tertiary); color: var(--text-primary); font: inherit; box-sizing: border-box; }
        .bg-field textarea { min-height: 64px; resize: vertical; }
        .bg-field .help { font-size: 11px; color: var(--text-secondary); margin-top: 3px; }
        .bg-check { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--text-primary); padding-top: 22px; }
        .bg-quick { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
        .bg-quick button { border: 1px solid var(--border-color); background: var(--bg-tertiary); color: var(--text-primary); border-radius: 6px; padding: 4px 9px; font-size: 12px; cursor: pointer; }
        .bg-quick button:hover { border-color: var(--gradient-1); }
        .bg-members-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
        .bg-members-bar input { flex: 1; min-width: 200px; padding: 8px 10px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-tertiary); color: var(--text-primary); }
        .bg-members { max-height: 230px; overflow: auto; border: 1px solid var(--border-color); border-radius: 8px; padding: 8px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px 10px; background: var(--bg-tertiary); }
        .bg-members label { font-size: 13px; color: var(--text-primary); display: flex; gap: 6px; align-items: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bg-members label.other { opacity: .55; }
        .bg-link { background: none; border: none; color: var(--primary-color); cursor: pointer; font-size: 13px; padding: 0; }
        .bg-actions { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; align-items: center; }
        .bg-actions .note { color: var(--text-secondary); font-size: 13px; margin-right: auto; }
        .bg-table-wrap { overflow-x: auto; }
        .bg-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .bg-table th { text-align: left; padding: 10px 8px; background: var(--header-bg); color: var(--text-secondary); font-size: 12px; text-transform: uppercase; white-space: nowrap; }
        .bg-table td { padding: 9px 8px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); vertical-align: middle; }
        .bg-table tr:hover td { background: var(--hover-bg); }
        .bg-table .num { text-align: right; white-space: nowrap; }
        .bg-table .muted { color: var(--text-secondary); font-size: 12px; }
        .bg-filter { display: grid; grid-template-columns: repeat(7, auto); gap: 10px; align-items: end; }
        .bg-bulk { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 12px 0; }
        .bg-pages { display: flex; gap: 6px; justify-content: center; margin-top: 14px; flex-wrap: wrap; }
        .bg-pages a, .bg-pages span { padding: 6px 12px; border-radius: 6px; background: var(--bg-tertiary); color: var(--text-primary); text-decoration: none; font-size: 13px; }
        .bg-pages span.cur { background: var(--gradient-primary); color: #fff; }
        .bg-btn-sm { padding: 6px 10px !important; font-size: 12px !important; }
        .bg-example { font-family: Consolas, monospace; background: var(--bg-tertiary); padding: 6px 10px; border-radius: 6px; display: inline-block; margin-top: 6px; }
        .bg-terms { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px 16px; }
        @media (max-width: 1000px) { .bg-types { grid-template-columns: repeat(3, 1fr); } .bg-grid { grid-template-columns: repeat(2, 1fr); } .bg-members { grid-template-columns: repeat(2, 1fr); } .bg-stats { grid-template-columns: repeat(2, 1fr); } .bg-filter { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .bg-types, .bg-grid, .bg-members, .bg-terms { grid-template-columns: 1fr; } .bg-grid .span2 { grid-column: auto; } }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="bg-page">
    <div class="bg-head">
        <div>
            <h1><i class="fas fa-file-invoice-dollar"></i> Bill Generator</h1>
            <p>Due bills, payment receipts, member statements, event receipts and expense vouchers on your letterhead - saved with their own numbers.</p>
        </div>
        <form method="POST" action="bills.php?tab=history">
            <input type="hidden" name="rb_token" value="<?php echo h($_SESSION['rb_token']); ?>">
            <button class="btn btn-success" name="action" value="auto_paid" title="Marks unpaid due bills Paid when the member has since paid at least the bill total"><i class="fas fa-check-double"></i> Check Payments</button>
        </form>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo h($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo h($error); ?></div><?php endif; ?>

    <div class="bg-stats">
        <div class="bg-stat"><div class="l">Bills Generated</div><div class="v"><?php echo (int)($billStats['total'] ?? 0); ?></div></div>
        <div class="bg-stat"><div class="l">This Month</div><div class="v"><?php echo (int)($billStats['this_month'] ?? 0); ?></div></div>
        <div class="bg-stat warn"><div class="l">Unpaid Due Bills</div><div class="v"><?php echo (int)($billStats['unpaid'] ?? 0); ?></div></div>
        <div class="bg-stat warn"><div class="l">Unpaid Amount</div><div class="v">&#8377;<?php echo formatIndianAmount($billStats['unpaid_amount'] ?? 0); ?></div></div>
        <div class="bg-stat ok"><div class="l">Paid / Cancelled</div><div class="v"><?php echo (int)($billStats['paid'] ?? 0); ?> / <?php echo (int)($billStats['cancelled'] ?? 0); ?></div></div>
    </div>

    <div class="bg-tabs">
        <a class="bg-tab <?php echo $tab === 'generate' ? 'active' : ''; ?>" data-tab="generate" href="bills.php?tab=generate"><i class="fas fa-magic"></i> Generate Bills</a>
        <a class="bg-tab <?php echo $tab === 'history' ? 'active' : ''; ?>" data-tab="history" href="bills.php?tab=history"><i class="fas fa-history"></i> Bill History (<?php echo (int)($billStats['total'] ?? 0); ?>)</a>
        <a class="bg-tab <?php echo $tab === 'settings' ? 'active' : ''; ?>" data-tab="settings" href="bills.php?tab=settings"><i class="fas fa-sliders-h"></i> Settings</a>
    </div>

    <!-- ═══ Generate ═══ -->
    <div class="bg-panel <?php echo $tab === 'generate' ? 'active' : ''; ?>" id="panel-generate">
        <form method="POST" action="generate-bills.php" target="_blank" id="genForm">
            <input type="hidden" name="rb_token" value="<?php echo h($_SESSION['rb_token']); ?>">

            <div class="bg-card">
                <h3><i class="fas fa-layer-group"></i> 1. Type of bill</h3>
                <div class="bg-types">
                    <?php $typeHelp = ['due' => 'Amount payable with arrears, charges and due date', 'receipt' => 'For payments received from members', 'statement' => 'Ledger with running balance',
                        'event' => 'For contributions to an event', 'expense' => 'Payment voucher for each expense', 'change' => 'Voucher for each change amount entry'];
                    foreach ($billTypes as $key => $t): ?>
                        <label class="bg-type">
                            <input type="radio" name="bill_type" value="<?php echo h($key); ?>" <?php echo $key === 'due' ? 'checked' : ''; ?>>
                            <i class="fas <?php echo h($t['icon']); ?>"></i><span><?php echo h($t['label']); ?></span><small><?php echo h($typeHelp[$key]); ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-card">
                <h3><i class="fas fa-filter"></i> 2. What to bill</h3>
                <div class="bg-grid">
                    <div class="bg-field" data-types="due receipt statement expense change">
                        <label>Academic Year <span data-types="expense change">(optional)</span></label>
                        <select name="year_id" id="yearSel">
                            <option value="0" data-types="expense change">All years</option>
                            <?php foreach ($academicYears as $y): ?>
                                <option value="<?php echo (int)$y['YearID']; ?>" data-start="<?php echo h($y['StartDate']); ?>" data-end="<?php echo h($y['EndDate']); ?>" <?php echo (int)$y['YearID'] === $currentYearId ? 'selected' : ''; ?>>
                                    <?php echo h($y['YearName'] . ($y['Status'] === 'Active' ? ' (Active)' : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bg-field" data-types="event">
                        <label>Event</label>
                        <select name="event_id">
                            <option value="0">All events</option>
                            <?php foreach ($events as $ev): ?><option value="<?php echo (int)$ev['EventID']; ?>"><?php echo h($ev['EventName'] . ' (' . billShowDate($ev['EventDate']) . ')'); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bg-field">
                        <label>Period From</label>
                        <input type="date" name="period_from" id="pFrom" value="<?php echo date('Y-m-01'); ?>" required>
                    </div>
                    <div class="bg-field">
                        <label>Period To</label>
                        <input type="date" name="period_to" id="pTo" value="<?php echo date('Y-m-t'); ?>" required>
                    </div>
                    <div class="bg-field">
                        <label>Bill Date</label>
                        <input type="date" name="bill_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="bg-field span4" style="margin-top:-6px">
                        <div class="bg-quick">
                            <button type="button" data-period="this">This month</button>
                            <button type="button" data-period="last">Last month</button>
                            <button type="button" data-period="quarter">Last 3 months</button>
                            <button type="button" data-period="year">Whole academic year</button>
                            <button type="button" data-period="today">Today</button>
                        </div>
                    </div>
                    <div class="bg-field" data-types="expense">
                        <label>Expense Category</label>
                        <select name="expense_category">
                            <option value="">All categories</option>
                            <?php foreach ($expenseCategories as $cat): ?><option><?php echo h($cat); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bg-field" data-types="receipt event expense change">
                        <label>Payment Mode</label>
                        <select name="payment_mode">
                            <option value="">All modes</option>
                            <?php foreach ($paymentModes as $pm): ?><option><?php echo h($pm); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bg-field" data-types="receipt">
                        <label>Receipts</label>
                        <select name="receipt_mode">
                            <option value="each">One receipt per payment</option>
                            <option value="combined">One receipt per member for the period</option>
                        </select>
                    </div>
                    <div class="bg-field">
                        <label>Order</label>
                        <select name="sort">
                            <option value="id">By Member ID / date</option>
                            <option value="name">By name</option>
                        </select>
                    </div>
                </div>

                <div data-types="due receipt statement change" style="margin-top:16px">
                    <div class="bg-members-bar">
                        <strong style="color:var(--text-primary)"><i class="fas fa-users"></i> Members</strong>
                        <input type="text" id="memberSearch" placeholder="Search members by name or ID…">
                        <button type="button" class="bg-link" id="selYear">Select this year's members</button>
                        <button type="button" class="bg-link" id="selAll">Select all shown</button>
                        <button type="button" class="bg-link" id="selNone">Clear</button>
                        <span id="memberCount" class="muted" style="color:var(--text-secondary);font-size:13px"></span>
                    </div>
                    <div class="bg-members" id="memberList">
                        <?php foreach ($members as $m): ?>
                            <label data-name="<?php echo h(strtolower($m['MemberName'] . ' ' . $m['MemberID'])); ?>" data-id="<?php echo (int)$m['MemberID']; ?>">
                                <input type="checkbox" name="member_ids[]" value="<?php echo (int)$m['MemberID']; ?>">
                                <?php echo h($m['MemberID'] . ' - ' . $m['MemberName']); ?><?php echo $m['Status'] !== 'Active' ? ' <small>(' . h($m['Status']) . ')</small>' : ''; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="help" style="font-size:12px;color:var(--text-secondary);margin-top:6px">Leave all unticked to bill every member of the selected year. Faded names have no entries in that year.</div>
                </div>
            </div>

            <div class="bg-card" data-types="due">
                <h3><i class="fas fa-calculator"></i> 3. Due date &amp; adjustments <span class="hint">Due amount = previous balance + distribution + interest &plusmn; change amount - payments in the period</span></h3>
                <div class="bg-grid">
                    <div class="bg-field">
                        <label>Due Date</label>
                        <select name="due_mode" id="dueMode">
                            <option value="auto">Automatic (next due date of the member)</option>
                            <option value="days">Days after bill date</option>
                            <option value="fixed">Fixed date</option>
                        </select>
                    </div>
                    <div class="bg-field" id="dueDaysBox">
                        <label>Days after bill date</label>
                        <input type="number" name="due_days" min="0" max="365" value="<?php echo (int)$billSettings['due_days']; ?>">
                        <div class="help">Also used when a member has no next due date</div>
                    </div>
                    <div class="bg-field" id="dueDateBox" style="display:none">
                        <label>Fixed due date</label>
                        <input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+' . (int)$billSettings['due_days'] . ' days')); ?>">
                    </div>
                    <div class="bg-field">
                        <label>Round Off</label>
                        <select name="round">
                            <option value="none">No rounding</option>
                            <option value="1">Nearest rupee</option>
                            <option value="10">Nearest 10 rupees</option>
                        </select>
                    </div>
                    <div class="bg-field">
                        <label>Late Fee on Arrears</label>
                        <select name="late_fee_type">
                            <option value="none">No late fee</option>
                            <option value="fixed">Fixed amount (&#8377;)</option>
                            <option value="percent">Percent of arrears (%)</option>
                        </select>
                    </div>
                    <div class="bg-field">
                        <label>Late Fee Value</label>
                        <input type="number" name="late_fee_value" min="0" step="0.01" value="0">
                        <div class="help">Only for members with a previous balance</div>
                    </div>
                    <div class="bg-field">
                        <label>Discount</label>
                        <select name="discount_type">
                            <option value="none">No discount</option>
                            <option value="fixed">Fixed amount (&#8377;)</option>
                            <option value="percent">Percent (%)</option>
                        </select>
                    </div>
                    <div class="bg-field">
                        <label>Discount Value</label>
                        <input type="number" name="discount_value" min="0" step="0.01" value="0">
                    </div>
                    <div class="bg-field span2">
                        <label>Other Charges (added to every bill)</label>
                        <textarea name="charges" placeholder="One per line, e.g.&#10;Maintenance = 100&#10;Function fund = 50"></textarea>
                        <div class="help">Format: <b>Description = Amount</b>. A negative amount gives a credit.</div>
                    </div>
                </div>
            </div>

            <div class="bg-card">
                <h3><i class="fas fa-print"></i> <span data-num></span>Print options</h3>
                <div class="bg-grid">
                    <div class="bg-field">
                        <label>Letterhead</label>
                        <select name="letterhead_id">
                            <?php foreach ($letterheads as $lh): ?><option value="<?php echo (int)$lh['id']; ?>" <?php echo (int)$lh['id'] === (int)$billSettings['letterhead_id'] ? 'selected' : ''; ?>><?php echo h($lh['name'] . ($lh['is_default'] ? ' (default)' : '')); ?></option><?php endforeach; ?>
                        </select>
                        <div class="help"><a href="report-builder.php" style="color:var(--primary-color)">Edit letterheads</a> in the Report Builder</div>
                    </div>
                    <div class="bg-field">
                        <label>Copies</label>
                        <select name="copies">
                            <option value="1">One copy</option>
                            <option value="2">Two copies (Member + Office)</option>
                        </select>
                    </div>
                    <div class="bg-field">
                        <label>Already billed?</label>
                        <select name="existing">
                            <option value="reprint">Re-print the saved bill (same number)</option>
                            <option value="skip">Skip it</option>
                            <option value="replace">Cancel it and make a new bill</option>
                        </select>
                        <div class="help">When the same member and period, or the same payment, already has a bill</div>
                    </div>
                    <div class="bg-field">
                        <label>Output</label>
                        <select name="output">
                            <option value="pdf">One PDF (each bill on its own page)</option>
                            <option value="zip" <?php echo $zipAvailable ? '' : 'disabled'; ?>>ZIP - a separate PDF per bill<?php echo $zipAvailable ? '' : ' (needs PHP zip)'; ?></option>
                        </select>
                    </div>
                    <input type="hidden" name="show_words" value="0">
                    <label class="bg-check"><input type="checkbox" name="show_words" value="1" <?php echo $billSettings['show_words'] ? 'checked' : ''; ?>> Amount in words</label>
                    <input type="hidden" name="show_signature" value="0">
                    <label class="bg-check"><input type="checkbox" name="show_signature" value="1" <?php echo $billSettings['show_signature'] ? 'checked' : ''; ?>> Signature lines</label>
                    <label class="bg-check" data-types="receipt"><input type="checkbox" name="show_balance" value="1" checked> Show balance outstanding</label>
                    <label class="bg-check" data-types="due statement"><input type="checkbox" name="include_zero" value="1"> Include zero balances</label>
                    <div class="bg-field span2">
                        <label>Terms / message printed on the bill</label>
                        <textarea name="terms" id="termsBox"></textarea>
                    </div>
                    <div class="bg-field span2">
                        <label>Notes (printed on every bill of this batch)</label>
                        <textarea name="notes" placeholder="Optional"></textarea>
                    </div>
                </div>
            </div>

            <div class="bg-card bg-actions">
                <span class="note"><i class="fas fa-info-circle"></i> Preview shows the bills without saving. Generate saves them with bill numbers and opens the PDF.</span>
                <button type="submit" class="btn bg-btn-ghost" name="action" value="preview"><i class="fas fa-eye"></i> Preview</button>
                <button type="submit" class="btn btn-primary" name="action" value="generate" id="genBtn"><i class="fas fa-file-invoice"></i> Generate &amp; Save</button>
            </div>
        </form>
    </div>

    <!-- ═══ History ═══ -->
    <div class="bg-panel <?php echo $tab === 'history' ? 'active' : ''; ?>" id="panel-history">
        <div class="bg-card">
            <form method="GET" class="bg-filter">
                <input type="hidden" name="tab" value="history">
                <div class="bg-field"><label>Type</label><select name="f_type"><option value="">All types</option>
                    <?php foreach ($billTypes as $k => $t): ?><option value="<?php echo h($k); ?>" <?php echo $filters['type'] === $k ? 'selected' : ''; ?>><?php echo h($t['label']); ?></option><?php endforeach; ?></select></div>
                <div class="bg-field"><label>Status</label><select name="f_status"><option value="">All</option>
                    <?php foreach ($billStatuses as $st): ?><option <?php echo $filters['status'] === $st ? 'selected' : ''; ?>><?php echo h($st); ?></option><?php endforeach; ?></select></div>
                <div class="bg-field"><label>Member</label><select name="f_member"><option value="0">All members</option>
                    <?php foreach ($members as $m): ?><option value="<?php echo (int)$m['MemberID']; ?>" <?php echo $filters['member'] === (int)$m['MemberID'] ? 'selected' : ''; ?>><?php echo h($m['MemberID'] . ' - ' . $m['MemberName']); ?></option><?php endforeach; ?></select></div>
                <div class="bg-field"><label>Bill date from</label><input type="date" name="f_from" value="<?php echo h($filters['from']); ?>"></div>
                <div class="bg-field"><label>to</label><input type="date" name="f_to" value="<?php echo h($filters['to']); ?>"></div>
                <div class="bg-field"><label>Search</label><input type="text" name="f_q" value="<?php echo h($filters['q']); ?>" placeholder="Bill no, name, notes"></div>
                <div style="display:flex;gap:6px"><button class="btn btn-primary bg-btn-sm"><i class="fas fa-search"></i> Filter</button><a class="btn bg-btn-ghost bg-btn-sm" href="bills.php?tab=history">Reset</a></div>
            </form>
        </div>

        <form method="POST" class="bg-card" id="bulkForm" action="bills.php?<?php echo h(http_build_query(array_merge($_GET, ['tab' => 'history']))); ?>">
            <input type="hidden" name="rb_token" value="<?php echo h($_SESSION['rb_token']); ?>">
            <input type="hidden" name="cancel_reason" id="cancelReason">
            <input type="hidden" name="copies" value="1">
            <div class="bg-bulk">
                <strong style="color:var(--text-primary)"><?php echo (int)$totalBills; ?> bill(s)</strong>
                <span style="color:var(--text-secondary)">&middot; total &#8377;<?php echo formatIndianAmount($billSum); ?><?php echo $filters['batch'] ? ' &middot; batch ' . h($filters['batch']) : ''; ?></span>
                <span style="flex:1"></span>
                <button class="btn btn-info bg-btn-sm" name="action" value="print" formaction="generate-bills.php" formtarget="_blank" data-need="1"><i class="fas fa-print"></i> Print Selected</button>
                <button class="btn btn-success bg-btn-sm" name="action" value="mark_paid" data-need="1"><i class="fas fa-check"></i> Mark Paid</button>
                <button class="btn btn-warning bg-btn-sm" name="action" value="mark_unpaid" data-need="1"><i class="fas fa-undo"></i> Mark Unpaid</button>
                <button class="btn btn-danger bg-btn-sm" name="action" value="cancel" data-need="1" data-cancel="1"><i class="fas fa-ban"></i> Cancel</button>
                <button class="btn bg-btn-ghost bg-btn-sm" name="action" value="delete" data-need="1" data-confirm="Delete the selected cancelled bills permanently?"><i class="fas fa-trash"></i> Delete Cancelled</button>
            </div>
            <div class="bg-table-wrap">
                <table class="bg-table">
                    <thead><tr>
                        <th><input type="checkbox" id="checkAll"></th><th>Bill No</th><th>Type</th><th>Date</th><th>Party</th><th>Period</th><th>Due Date</th><th class="num">Total (&#8377;)</th><th>Status</th><th>Printed</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$bills): ?>
                        <tr><td colspan="11" style="text-align:center;padding:30px;color:var(--text-secondary)"><i class="fas fa-inbox"></i> No bills yet. Generate some from the first tab.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($bills as $b): ?>
                        <tr>
                            <td><input type="checkbox" name="bill_ids[]" value="<?php echo (int)$b['BillID']; ?>" class="rowCheck"></td>
                            <td><strong><?php echo h($b['BillNo']); ?></strong><?php if ($b['BatchNo']): ?><div class="muted"><a href="<?php echo h(billsPageUrl(['tab' => 'history', 'f_batch' => $b['BatchNo'], 'p' => null])); ?>" style="color:var(--text-secondary)">batch <?php echo h($b['BatchNo']); ?></a></div><?php endif; ?></td>
                            <td><?php echo h($billTypes[$b['BillType']]['label'] ?? $b['BillType']); ?></td>
                            <td><?php echo h(billShowDate($b['BillDate'])); ?></td>
                            <td><?php echo h($b['PartyName']); ?><?php echo $b['MemberID'] ? '<div class="muted">Member #' . (int)$b['MemberID'] . '</div>' : ''; ?></td>
                            <td class="muted"><?php echo $b['PeriodFrom'] === $b['PeriodTo'] ? h(billShowDate($b['PeriodFrom'])) : h(billShowDate($b['PeriodFrom']) . ' - ' . billShowDate($b['PeriodTo'])); ?></td>
                            <td><?php echo h(billShowDate($b['DueDate'])); ?></td>
                            <td class="num"><strong><?php echo formatIndianAmount($b['Total']); ?></strong></td>
                            <td><span class="badge <?php echo $statusBadge[$b['Status']] ?? 'badge-info'; ?>"><?php echo h($b['Status']); ?></span>
                                <?php if ($b['Status'] === 'Cancelled' && $b['CancelReason']): ?><div class="muted"><?php echo h($b['CancelReason']); ?></div><?php endif; ?></td>
                            <td class="muted"><?php echo (int)$b['PrintCount']; ?>&times;</td>
                            <td><button type="button" class="btn btn-info bg-btn-sm" data-print="<?php echo (int)$b['BillID']; ?>" title="Print this bill"><i class="fas fa-print"></i></button></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="bg-pages">
                    <?php for ($i = max(1, $pageNo - 4); $i <= min($totalPages, $pageNo + 4); $i++): ?>
                        <?php if ($i === $pageNo): ?><span class="cur"><?php echo $i; ?></span><?php else: ?><a href="<?php echo h(billsPageUrl(['tab' => 'history', 'p' => $i])); ?>"><?php echo $i; ?></a><?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ═══ Settings ═══ -->
    <div class="bg-panel <?php echo $tab === 'settings' ? 'active' : ''; ?>" id="panel-settings">
        <form method="POST" action="bills.php?tab=settings">
            <input type="hidden" name="rb_token" value="<?php echo h($_SESSION['rb_token']); ?>">
            <div class="bg-card">
                <h3><i class="fas fa-hashtag"></i> Bill numbering</h3>
                <div class="bg-grid">
                    <div class="bg-field span2">
                        <label>Number format</label>
                        <input type="text" name="number_format" id="numFormat" value="<?php echo h($billSettings['number_format']); ?>" maxlength="60" required>
                        <div class="help">Codes: <b>{PREFIX}</b> type prefix, <b>{FY}</b> financial year (2026-27), <b>{YYYY}</b> <b>{YY}</b> year, <b>{MM}</b> month, <b>{SEQ}</b> running number. The number starts again from 1 whenever the rest of the number changes (e.g. each financial year).</div>
                        <div class="bg-example" id="numExample"></div>
                    </div>
                    <div class="bg-field">
                        <label>Digits in running number</label>
                        <input type="number" name="seq_digits" id="seqDigits" min="1" max="8" value="<?php echo (int)$billSettings['seq_digits']; ?>">
                    </div>
                    <div class="bg-field">
                        <label>Default due days</label>
                        <input type="number" name="due_days" min="0" max="365" value="<?php echo (int)$billSettings['due_days']; ?>">
                    </div>
                    <?php foreach ($billTypes as $k => $t): ?>
                        <div class="bg-field">
                            <label>Prefix - <?php echo h($t['label']); ?></label>
                            <input type="text" name="prefixes[<?php echo h($k); ?>]" value="<?php echo h($billSettings['prefixes'][$k]); ?>" maxlength="12" class="prefixBox" data-type="<?php echo h($k); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="bg-card">
                <h3><i class="fas fa-file-signature"></i> Printing</h3>
                <div class="bg-grid">
                    <div class="bg-field">
                        <label>Default letterhead</label>
                        <select name="letterhead_id">
                            <?php foreach ($letterheads as $lh): ?><option value="<?php echo (int)$lh['id']; ?>" <?php echo (int)$lh['id'] === (int)$billSettings['letterhead_id'] ? 'selected' : ''; ?>><?php echo h($lh['name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="bg-field"><label>Left signature label</label><input type="text" name="sign_left" value="<?php echo h($billSettings['sign_left']); ?>" maxlength="60"></div>
                    <div class="bg-field"><label>Right signature label</label><input type="text" name="sign_right" value="<?php echo h($billSettings['sign_right']); ?>" maxlength="60"></div>
                    <div>
                        <input type="hidden" name="show_words" value="0"><label class="bg-check" style="padding-top:0"><input type="checkbox" name="show_words" value="1" <?php echo $billSettings['show_words'] ? 'checked' : ''; ?>> Amount in words</label>
                        <input type="hidden" name="show_signature" value="0"><label class="bg-check" style="padding-top:6px"><input type="checkbox" name="show_signature" value="1" <?php echo $billSettings['show_signature'] ? 'checked' : ''; ?>> Signature lines</label>
                    </div>
                </div>
            </div>
            <div class="bg-card">
                <h3><i class="fas fa-align-left"></i> Default terms / message per bill type</h3>
                <div class="bg-terms">
                    <?php foreach ($billTypes as $k => $t): ?>
                        <div class="bg-field"><label><?php echo h($t['label']); ?></label><textarea name="terms[<?php echo h($k); ?>]" maxlength="1000"><?php echo h($billSettings['terms'][$k]); ?></textarea></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="bg-actions"><button class="btn btn-primary" name="action" value="save_settings"><i class="fas fa-save"></i> Save Settings</button></div>
        </form>
    </div>
</div>

<script>
(function () {
    const settings = <?php echo json_encode(['terms' => $billSettings['terms']], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    const yearMembers = <?php echo json_encode($yearMemberIds); ?>;
    const form = document.getElementById('genForm');
    const typeOf = () => (form.querySelector('input[name=bill_type]:checked') || {}).value || 'due';

    // Tabs without reloading
    document.querySelectorAll('.bg-tab').forEach(t => t.addEventListener('click', e => {
        e.preventDefault();
        document.querySelectorAll('.bg-tab').forEach(x => x.classList.toggle('active', x === t));
        document.querySelectorAll('.bg-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-' + t.dataset.tab));
        history.replaceState(null, '', 'bills.php?tab=' + t.dataset.tab);
    }));

    // Show the fields of the chosen bill type
    let termsTouched = false;
    const terms = document.getElementById('termsBox');
    terms.addEventListener('input', () => termsTouched = true);
    function applyType() {
        const type = typeOf();
        form.querySelectorAll('[data-types]').forEach(el => {
            const on = el.dataset.types.split(' ').includes(type);
            if (el.tagName === 'OPTION') { el.hidden = !on; el.disabled = !on; }
            else el.style.display = on ? '' : 'none';
            el.querySelectorAll('input, select, textarea').forEach(i => { if (!i.closest('[data-types]') || i.closest('[data-types]') === el) i.disabled = !on; });
        });
        const ys = document.getElementById('yearSel');
        if (ys.selectedOptions[0] && ys.selectedOptions[0].disabled) ys.value = ys.querySelector('option:not([disabled])').value;
        if (!termsTouched) terms.value = settings.terms[type] || '';
        let n = 3;
        form.querySelectorAll('.bg-card h3 [data-num]').forEach(s => s.textContent = (type === 'due' ? 4 : n) + '. ');
        markYear();
    }
    form.querySelectorAll('input[name=bill_type]').forEach(r => r.addEventListener('change', applyType));

    // Due date mode
    const dueMode = document.getElementById('dueMode');
    dueMode.addEventListener('change', () => {
        document.getElementById('dueDateBox').style.display = dueMode.value === 'fixed' ? '' : 'none';
    });

    // Quick periods
    const pad = n => String(n).padStart(2, '0');
    const ymd = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    document.querySelectorAll('[data-period]').forEach(b => b.addEventListener('click', () => {
        const now = new Date(), from = document.getElementById('pFrom'), to = document.getElementById('pTo');
        const p = b.dataset.period;
        if (p === 'this') { from.value = ymd(new Date(now.getFullYear(), now.getMonth(), 1)); to.value = ymd(new Date(now.getFullYear(), now.getMonth() + 1, 0)); }
        if (p === 'last') { from.value = ymd(new Date(now.getFullYear(), now.getMonth() - 1, 1)); to.value = ymd(new Date(now.getFullYear(), now.getMonth(), 0)); }
        if (p === 'quarter') { from.value = ymd(new Date(now.getFullYear(), now.getMonth() - 2, 1)); to.value = ymd(new Date(now.getFullYear(), now.getMonth() + 1, 0)); }
        if (p === 'today') { from.value = to.value = ymd(now); }
        if (p === 'year') {
            const o = document.getElementById('yearSel').selectedOptions[0];
            if (o && o.dataset.start) { from.value = o.dataset.start; to.value = o.dataset.end; }
        }
    }));

    // Members
    const list = document.getElementById('memberList');
    const labels = [...list.querySelectorAll('label')];
    const count = () => document.getElementById('memberCount').textContent = list.querySelectorAll('input:checked').length + ' selected';
    function markYear() {
        const ids = yearMembers[document.getElementById('yearSel').value] || [];
        labels.forEach(l => l.classList.toggle('other', !ids.includes(+l.dataset.id)));
    }
    document.getElementById('yearSel').addEventListener('change', markYear);
    document.getElementById('memberSearch').addEventListener('input', e => {
        const q = e.target.value.toLowerCase().trim();
        labels.forEach(l => l.style.display = !q || l.dataset.name.includes(q) ? '' : 'none');
    });
    document.getElementById('selAll').onclick = () => { labels.forEach(l => { if (l.style.display !== 'none') l.querySelector('input').checked = true; }); count(); };
    document.getElementById('selNone').onclick = () => { labels.forEach(l => l.querySelector('input').checked = false); count(); };
    document.getElementById('selYear').onclick = () => {
        const ids = yearMembers[document.getElementById('yearSel').value] || [];
        labels.forEach(l => l.querySelector('input').checked = ids.includes(+l.dataset.id)); count();
    };
    list.addEventListener('change', count);

    // After saving bills, show them in the history
    form.addEventListener('submit', e => {
        if (e.submitter && e.submitter.value === 'generate') {
            setTimeout(() => { location.href = 'bills.php?tab=history'; }, 2500);
        }
    });

    // History bulk actions
    const bulk = document.getElementById('bulkForm');
    document.getElementById('checkAll').addEventListener('change', e => bulk.querySelectorAll('.rowCheck').forEach(c => c.checked = e.target.checked));
    bulk.querySelectorAll('[data-print]').forEach(b => b.addEventListener('click', () => {
        const f = document.createElement('form');
        f.method = 'POST'; f.action = 'generate-bills.php'; f.target = '_blank';
        [['rb_token', bulk.querySelector('[name=rb_token]').value], ['action', 'print'], ['bill_ids', b.dataset.print]].forEach(([n, v]) => {
            const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i);
        });
        document.body.appendChild(f); f.submit(); f.remove();
    }));
    bulk.addEventListener('submit', e => {
        const b = e.submitter;
        if (!b) return;
        if (b.dataset.need && !bulk.querySelector('.rowCheck:checked')) { e.preventDefault(); alert('Select one or more bills first.'); return; }
        if (b.dataset.cancel) {
            const r = prompt('Reason for cancelling the selected bills:', 'Cancelled');
            if (r === null) { e.preventDefault(); return; }
            document.getElementById('cancelReason').value = r;
        }
        if (b.dataset.confirm && !confirm(b.dataset.confirm)) e.preventDefault();
    });

    // Settings: example number
    const fmt = document.getElementById('numFormat'), digits = document.getElementById('seqDigits');
    function example() {
        const d = new Date(), fyStart = d.getMonth() >= 3 ? d.getFullYear() : d.getFullYear() - 1;
        const prefix = (document.querySelector('.prefixBox[data-type=due]') || {}).value || 'BILL';
        document.getElementById('numExample').textContent = 'Example: ' + fmt.value
            .replaceAll('{PREFIX}', prefix).replaceAll('{FY}', fyStart + '-' + String(fyStart + 1).slice(-2))
            .replaceAll('{YYYY}', d.getFullYear()).replaceAll('{YY}', String(d.getFullYear()).slice(-2)).replaceAll('{MM}', pad(d.getMonth() + 1))
            .replaceAll('{SEQ}', '1'.padStart(Math.max(1, Math.min(8, +digits.value || 4)), '0'));
    }
    [fmt, digits, ...document.querySelectorAll('.prefixBox')].forEach(i => i.addEventListener('input', example));
    example();
    applyType();
    count();
})();
</script>
</body>
</html>
