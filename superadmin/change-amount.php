<?php
// Super Admin - Change Amount transactions (opened from Manage Transactions)
// Member entries change that member's net balance; entries without a member are colony-level money.
// The year's ChangeAmount (used by dashboards and accounting) is kept equal to the total of its entries.
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/net-balance-helper.php';

if (empty($_SESSION['rb_token'])) {
    $_SESSION['rb_token'] = bin2hex(random_bytes(16));
}
caEnsureTable($pdo);

$success = $error = '';
$paymentModes = ['Cash', 'Online', 'UPI', 'Bank Transfer', 'Cheque'];

/** Recalculate what an entry change affects: the year totals and the members' stored balances */
function caAfterChange(PDO $pdo, array $yearIds, array $memberYears) {
    foreach (array_unique(array_filter($yearIds)) as $y) caSyncYear($pdo, $y);
    foreach ($memberYears as [$m, $y]) {
        if ($m && $y) updateNetBalanceAfterTransactionChange((int)$m, (int)$y, (int)$m, (int)$y, $_SESSION['superadmin_id'] ?? 0);
    }
}

function caGetEntry(PDO $pdo, $id) {
    $s = $pdo->prepare('SELECT * FROM change_amount_transactions WHERE ChangeID = ?');
    $s->execute([(int)$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Save / delete ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['rb_token'], (string)($_POST['rb_token'] ?? ''))) {
        $error = 'The page expired. Please reload it and try again.';
    } elseif ($action === 'save') {
        $id = (int)($_POST['change_id'] ?? 0);
        $yearId = (int)($_POST['year_id'] ?? 0);
        $memberId = (int)($_POST['member_id'] ?? 0) ?: null;
        $date = trim((string)($_POST['transaction_date'] ?? ''));
        $amount = round(abs((float)str_replace(',', '', (string)($_POST['amount'] ?? 0))), 2);
        $direction = ($_POST['direction'] ?? 'add') === 'deduct' ? -1 : 1;
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);
        $mode = in_array($_POST['payment_mode'] ?? '', $paymentModes, true) ? $_POST['payment_mode'] : null;
        $ref = mb_substr(trim((string)($_POST['reference_number'] ?? '')), 0, 60) ?: null;
        $remarks = trim((string)($_POST['remarks'] ?? '')) ?: null;
        $d = DateTime::createFromFormat('!Y-m-d', $date);
        $yearOk = $pdo->prepare('SELECT COUNT(*) FROM academic_years WHERE YearID = ?');
        $yearOk->execute([$yearId]);
        $memberOk = true;
        if ($memberId) {
            $m = $pdo->prepare('SELECT COUNT(*) FROM members WHERE MemberID = ?');
            $m->execute([$memberId]);
            $memberOk = (bool)$m->fetchColumn();
        }
        $old = $id ? caGetEntry($pdo, $id) : null;
        if (!$yearOk->fetchColumn()) {
            $error = 'Please choose an academic year.';
        } elseif (!$d || $d->format('Y-m-d') !== $date) {
            $error = 'Please enter a valid date.';
        } elseif ($amount <= 0) {
            $error = 'The amount must be more than zero.';
        } elseif ($description === '') {
            $error = 'Please enter a description (reason) for the change amount.';
        } elseif (!$memberOk) {
            $error = 'The selected member was not found.';
        } elseif ($id && !$old) {
            $error = 'That change amount entry no longer exists.';
        } else {
            try {
                $pdo->beginTransaction();
                $signed = $amount * $direction;
                if ($old) {
                    $pdo->prepare('UPDATE change_amount_transactions SET YearID = ?, MemberID = ?, TransactionDate = ?, Amount = ?, Description = ?, PaymentMode = ?, ReferenceNumber = ?, Remarks = ?, UpdatedBy = ? WHERE ChangeID = ?')
                        ->execute([$yearId, $memberId, $date, $signed, $description, $mode, $ref, $remarks, $_SESSION['superadmin_id'], $id]);
                } else {
                    $pdo->prepare('INSERT INTO change_amount_transactions (YearID, MemberID, TransactionDate, Amount, Description, PaymentMode, ReferenceNumber, Remarks, CreatedBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$yearId, $memberId, $date, $signed, $description, $mode, $ref, $remarks, $_SESSION['superadmin_id']]);
                    $id = (int)$pdo->lastInsertId();
                }
                $pdo->commit();
                caAfterChange($pdo, [$yearId, $old['YearID'] ?? null], [[$memberId, $yearId], [$old['MemberID'] ?? null, $old['YearID'] ?? null]]);
                $newData = ['ChangeID' => $id, 'YearID' => $yearId, 'MemberID' => $memberId, 'Date' => $date, 'Amount' => $signed, 'Description' => $description];
                createAuditLog($_SESSION['superadmin_id'], 'SuperAdmin', 'Change Amount', $old ? 'update_change_amount' : 'add_change_amount',
                    ($old ? 'Updated' : 'Added') . ' change amount #' . $id . ': ' . ($signed >= 0 ? '+' : '') . number_format($signed, 2) . ' (' . $description . ')',
                    'change_amount_transactions', $id, $old ? json_encode($old) : null, json_encode($newData));
                $success = ($old ? 'Change amount entry updated' : 'Change amount entry added') . ' (' . ($signed >= 0 ? '+' : '-') . '₹' . number_format(abs($signed), 2) . ').';
                $_POST = [];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Could not save: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $ids = $_POST['change_ids'] ?? ($_POST['change_id'] ?? []);
        if (is_string($ids) || is_int($ids)) $ids = preg_split('/[\s,]+/', (string)$ids);
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (!$ids) {
            $error = 'Select one or more entries first.';
        } else {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s = $pdo->prepare("SELECT * FROM change_amount_transactions WHERE ChangeID IN ($in)");
            $s->execute($ids);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare("DELETE FROM change_amount_transactions WHERE ChangeID IN ($in)")->execute($ids);
            caAfterChange($pdo, array_column($rows, 'YearID'), array_map(function ($r) { return [$r['MemberID'], $r['YearID']]; }, $rows));
            createAuditLog($_SESSION['superadmin_id'], 'SuperAdmin', 'Change Amount', 'delete_change_amount', 'Deleted ' . count($rows) . ' change amount entr' . (count($rows) === 1 ? 'y' : 'ies') . ': #' . implode(', #', array_column($rows, 'ChangeID')),
                'change_amount_transactions', count($rows) === 1 ? $rows[0]['ChangeID'] : null, json_encode($rows), null, 'Warning');
            $success = count($rows) . ' change amount entr' . (count($rows) === 1 ? 'y' : 'ies') . ' deleted.';
        }
    }
}

// ── Data for the page (and the desktop app) ───────────────────────────────
$academicYears = $pdo->query('SELECT YearID, YearName, StartDate, EndDate, Status, ChangeAmount FROM academic_years ORDER BY StartDate DESC')->fetchAll(PDO::FETCH_ASSOC);
$currentYearId = 0;
foreach ($academicYears as $y) {
    if ($y['Status'] === 'Active') { $currentYearId = (int)$y['YearID']; break; }
}
if (!$currentYearId && $academicYears) $currentYearId = (int)$academicYears[0]['YearID'];
$members = $pdo->query('SELECT MemberID, MemberName, Status FROM members ORDER BY MemberName')->fetchAll(PDO::FETCH_ASSOC);

$filters = [
    'year' => isset($_GET['year']) ? (int)$_GET['year'] : $currentYearId,
    'member' => (string)($_GET['member'] ?? ''),          // '' all, 'general', or a MemberID
    'direction' => in_array($_GET['direction'] ?? '', ['add', 'deduct'], true) ? $_GET['direction'] : '',
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
    'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
];
$where = ['1=1'];
$args = [];
if ($filters['year'] > 0) { $where[] = 'c.YearID = ?'; $args[] = $filters['year']; }
if ($filters['member'] === 'general') { $where[] = 'c.MemberID IS NULL'; }
elseif ((int)$filters['member'] > 0) { $where[] = 'c.MemberID = ?'; $args[] = (int)$filters['member']; }
if ($filters['direction'] === 'add') $where[] = 'c.Amount > 0';
if ($filters['direction'] === 'deduct') $where[] = 'c.Amount < 0';
if ($filters['from'] !== '') { $where[] = 'c.TransactionDate >= ?'; $args[] = $filters['from']; }
if ($filters['to'] !== '') { $where[] = 'c.TransactionDate <= ?'; $args[] = $filters['to']; }
if ($filters['q'] !== '') { $where[] = '(c.Description LIKE ? OR c.ReferenceNumber LIKE ? OR c.Remarks LIKE ? OR m.MemberName LIKE ?)'; array_push($args, ...array_fill(0, 4, '%' . $filters['q'] . '%')); }
$sqlWhere = implode(' AND ', $where);

$cnt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(c.Amount), 0), COALESCE(SUM(CASE WHEN c.Amount > 0 THEN c.Amount END), 0), COALESCE(SUM(CASE WHEN c.Amount < 0 THEN c.Amount END), 0)
    FROM change_amount_transactions c LEFT JOIN members m ON m.MemberID = c.MemberID WHERE $sqlWhere");
$cnt->execute($args);
[$totalEntries, $listTotal, $listAdded, $listDeducted] = $cnt->fetch(PDO::FETCH_NUM);
$totalEntries = (int)$totalEntries;
$perPage = 50;
$totalPages = max(1, (int)ceil($totalEntries / $perPage));
$pageNo = max(1, min($totalPages, (int)($_GET['p'] ?? 1)));

$export = isset($_GET['export']) && $_GET['export'] === 'csv';
$s = $pdo->prepare("SELECT c.*, m.MemberName, ay.YearName FROM change_amount_transactions c
    LEFT JOIN members m ON m.MemberID = c.MemberID LEFT JOIN academic_years ay ON ay.YearID = c.YearID
    WHERE $sqlWhere ORDER BY c.TransactionDate DESC, c.ChangeID DESC" . ($export ? '' : ' LIMIT ' . $perPage . ' OFFSET ' . (($pageNo - 1) * $perPage)));
$s->execute($args);
$entries = $s->fetchAll(PDO::FETCH_ASSOC);

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="change-amount-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Date', 'Academic Year', 'Member ID', 'Member', 'Type', 'Amount', 'Description', 'Payment Mode', 'Reference', 'Remarks']);
    foreach ($entries as $e) {
        fputcsv($out, [$e['ChangeID'], $e['TransactionDate'], $e['YearName'], $e['MemberID'], $e['MemberID'] ? $e['MemberName'] : 'General', $e['Amount'] >= 0 ? 'Add' : 'Deduct',
            $e['Amount'], $e['Description'], $e['PaymentMode'], $e['ReferenceNumber'], $e['Remarks']]);
    }
    fclose($out);
    exit;
}

$yearTotals = caTotals($pdo, $filters['year'] > 0 ? $filters['year'] : null);
$editEntry = isset($_GET['edit']) ? caGetEntry($pdo, (int)$_GET['edit']) : null;
$form = $editEntry ?: [];
if ($error && ($_POST['action'] ?? '') === 'save') {
    // Keep what was typed
    $form = ['ChangeID' => $_POST['change_id'] ?? '', 'YearID' => $_POST['year_id'] ?? '', 'MemberID' => $_POST['member_id'] ?? '', 'TransactionDate' => $_POST['transaction_date'] ?? '',
        'Amount' => (($_POST['direction'] ?? 'add') === 'deduct' ? -1 : 1) * abs((float)($_POST['amount'] ?? 0)), 'Description' => $_POST['description'] ?? '',
        'PaymentMode' => $_POST['payment_mode'] ?? '', 'ReferenceNumber' => $_POST['reference_number'] ?? '', 'Remarks' => $_POST['remarks'] ?? ''];
}
$page_title = 'Change Amount';

function cah($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function caUrl(array $over) {
    $q = array_merge($_GET, $over);
    return 'change-amount.php?' . http_build_query(array_filter($q, function ($v) { return $v !== '' && $v !== null; }));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Amount - Super Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
    <script src="theme-manager.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .ca-page { max-width: 1280px; margin: 0 auto; padding: 20px; }
        .ca-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .ca-head h1 { font-size: 26px; color: var(--text-primary); display: flex; align-items: center; gap: 10px; margin: 0 0 4px; }
        .ca-head p { color: var(--text-secondary); margin: 0; max-width: 780px; }
        .ca-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
        .ca-stat { background: var(--bg-secondary); border-radius: 12px; padding: 14px 16px; box-shadow: var(--shadow); border-left: 4px solid #8e44ad; }
        .ca-stat .l { font-size: 12px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .4px; }
        .ca-stat .v { font-size: 22px; font-weight: 700; color: var(--text-primary); margin-top: 4px; }
        .ca-stat .s { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }
        .ca-card { background: var(--bg-secondary); border-radius: 12px; box-shadow: var(--shadow); padding: 18px 20px; margin-bottom: 16px; }
        .ca-card h3 { margin: 0 0 14px; font-size: 16px; color: var(--text-primary); display: flex; gap: 8px; align-items: center; }
        .ca-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px 16px; }
        .ca-grid .span2 { grid-column: span 2; }
        .ca-field label { display: block; font-size: 12px; font-weight: 600; color: var(--text-secondary); margin-bottom: 4px; }
        .ca-field input, .ca-field select, .ca-field textarea { width: 100%; padding: 9px 10px; border: 1px solid var(--border-color); border-radius: 8px; background: var(--bg-tertiary); color: var(--text-primary); font: inherit; box-sizing: border-box; }
        .ca-field textarea { min-height: 42px; resize: vertical; }
        .ca-field .help { font-size: 11px; color: var(--text-secondary); margin-top: 3px; }
        .ca-dir { display: flex; gap: 8px; }
        .ca-dir label { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 9px; border: 2px solid var(--border-color); border-radius: 8px; cursor: pointer; font-weight: 600; color: var(--text-primary); font-size: 13px; margin: 0; }
        .ca-dir input { width: auto; }
        .ca-dir label:has(input:checked).add { border-color: #10b981; background: rgba(16,185,129,.1); }
        .ca-dir label:has(input:checked).deduct { border-color: #ef4444; background: rgba(239,68,68,.1); }
        .ca-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 14px; align-items: center; }
        .ca-actions .note { margin-right: auto; color: var(--text-secondary); font-size: 13px; }
        .ca-filter { display: grid; grid-template-columns: repeat(7, auto); gap: 10px; align-items: end; }
        .ca-table-wrap { overflow-x: auto; }
        .ca-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .ca-table th { text-align: left; padding: 10px 8px; background: var(--header-bg); color: var(--text-secondary); font-size: 12px; text-transform: uppercase; white-space: nowrap; }
        .ca-table td { padding: 9px 8px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); vertical-align: middle; }
        .ca-table tr:hover td { background: var(--hover-bg); }
        .ca-table .num { text-align: right; white-space: nowrap; font-weight: 700; }
        .ca-table .plus { color: #059669; } .ca-table .minus { color: #dc2626; }
        .ca-table .muted { color: var(--text-secondary); font-size: 12px; }
        .ca-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; background: rgba(142,68,173,.12); color: #8e44ad; }
        .ca-btn-ghost { background: var(--bg-tertiary); color: var(--text-primary); border: 1px solid var(--border-color); }
        .ca-btn-sm { padding: 6px 10px !important; font-size: 12px !important; }
        .ca-pages { display: flex; gap: 6px; justify-content: center; margin-top: 14px; flex-wrap: wrap; }
        .ca-pages a, .ca-pages span { padding: 6px 12px; border-radius: 6px; background: var(--bg-tertiary); color: var(--text-primary); text-decoration: none; font-size: 13px; }
        .ca-pages span.cur { background: var(--gradient-primary); color: #fff; }
        @media (max-width: 1000px) { .ca-grid, .ca-stats { grid-template-columns: repeat(2, 1fr); } .ca-filter { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .ca-grid, .ca-stats, .ca-filter { grid-template-columns: 1fr; } .ca-grid .span2 { grid-column: auto; } }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="ca-page">
    <div class="ca-head">
        <div>
            <h1><i class="fas fa-coins"></i> Change Amount Transactions</h1>
            <p>Add or deduct change amounts. An entry for a <b>member</b> changes that member's net balance (and their due bills).
                An entry <b>without a member</b> is colony money. The year's total is used on the dashboards, Net Balance, accounting and reports.</p>
        </div>
        <a href="manage-transactions.php" class="btn ca-btn-ghost"><i class="fas fa-arrow-left"></i> Manage Transactions</a>
    </div>

    <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo cah($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo cah($error); ?></div><?php endif; ?>

    <div class="ca-stats">
        <div class="ca-stat"><div class="l">Total Change Amount</div><div class="v">&#8377;<?php echo number_format($yearTotals['total'], 2); ?></div>
            <div class="s"><?php echo $filters['year'] > 0 ? 'Selected year' : 'All years'; ?> &middot; <?php echo (int)$yearTotals['count']; ?> entries</div></div>
        <div class="ca-stat"><div class="l">General (Colony)</div><div class="v">&#8377;<?php echo number_format($yearTotals['general'], 2); ?></div><div class="s">Added to the system net balance</div></div>
        <div class="ca-stat"><div class="l">Members</div><div class="v">&#8377;<?php echo number_format($yearTotals['members'], 2); ?></div><div class="s">Included in member balances</div></div>
        <div class="ca-stat"><div class="l">Shown Below</div><div class="v">&#8377;<?php echo number_format((float)$listTotal, 2); ?></div>
            <div class="s">+<?php echo number_format((float)$listAdded, 2); ?> / <?php echo number_format((float)$listDeducted, 2); ?></div></div>
    </div>

    <!-- Add / edit -->
    <form method="POST" class="ca-card" id="caForm" action="<?php echo cah(caUrl(['edit' => null])); ?>">
        <h3><i class="fas <?php echo !empty($form['ChangeID']) ? 'fa-edit' : 'fa-plus-circle'; ?>"></i> <?php echo !empty($form['ChangeID']) ? 'Edit Change Amount #' . (int)$form['ChangeID'] : 'Add Change Amount'; ?></h3>
        <input type="hidden" name="rb_token" value="<?php echo cah($_SESSION['rb_token']); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="change_id" value="<?php echo (int)($form['ChangeID'] ?? 0); ?>">
        <div class="ca-grid">
            <div class="ca-field">
                <label>Academic Year *</label>
                <select name="year_id" required>
                    <?php $fy = (int)($form['YearID'] ?? ($filters['year'] ?: $currentYearId)); foreach ($academicYears as $y): ?>
                        <option value="<?php echo (int)$y['YearID']; ?>" <?php echo (int)$y['YearID'] === $fy ? 'selected' : ''; ?>><?php echo cah($y['YearName'] . ($y['Status'] === 'Active' ? ' (Active)' : '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ca-field">
                <label>Date *</label>
                <input type="date" name="transaction_date" value="<?php echo cah($form['TransactionDate'] ?? date('Y-m-d')); ?>" required>
            </div>
            <div class="ca-field span2">
                <label>Member</label>
                <select name="member_id">
                    <option value="">&mdash; General (colony, no member) &mdash;</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?php echo (int)$m['MemberID']; ?>" <?php echo (int)($form['MemberID'] ?? 0) === (int)$m['MemberID'] ? 'selected' : ''; ?>><?php echo cah($m['MemberID'] . ' - ' . $m['MemberName'] . ($m['Status'] !== 'Active' ? ' (' . $m['Status'] . ')' : '')); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="help">With a member, the amount is added to (or taken off) that member's net balance.</div>
            </div>
            <div class="ca-field">
                <label>Type *</label>
                <?php $isDeduct = (float)($form['Amount'] ?? 0) < 0; ?>
                <div class="ca-dir">
                    <label class="add"><input type="radio" name="direction" value="add" <?php echo $isDeduct ? '' : 'checked'; ?>> <i class="fas fa-plus"></i> Add</label>
                    <label class="deduct"><input type="radio" name="direction" value="deduct" <?php echo $isDeduct ? 'checked' : ''; ?>> <i class="fas fa-minus"></i> Deduct</label>
                </div>
            </div>
            <div class="ca-field">
                <label>Amount (&#8377;) *</label>
                <input type="number" name="amount" min="0.01" step="0.01" value="<?php echo isset($form['Amount']) ? cah(number_format(abs((float)$form['Amount']), 2, '.', '')) : ''; ?>" required>
            </div>
            <div class="ca-field span2">
                <label>Description / Reason *</label>
                <input type="text" name="description" maxlength="255" value="<?php echo cah($form['Description'] ?? ''); ?>" placeholder="e.g. Change returned, rounding difference, cash short" required>
            </div>
            <div class="ca-field">
                <label>Payment Mode</label>
                <select name="payment_mode">
                    <option value="">&mdash;</option>
                    <?php foreach ($paymentModes as $pm): ?><option <?php echo ($form['PaymentMode'] ?? '') === $pm ? 'selected' : ''; ?>><?php echo cah($pm); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="ca-field">
                <label>Reference No.</label>
                <input type="text" name="reference_number" maxlength="60" value="<?php echo cah($form['ReferenceNumber'] ?? ''); ?>">
            </div>
            <div class="ca-field span2">
                <label>Remarks</label>
                <textarea name="remarks"><?php echo cah($form['Remarks'] ?? ''); ?></textarea>
            </div>
        </div>
        <div class="ca-actions">
            <span class="note"><i class="fas fa-info-circle"></i> Saving updates the year total and the member's net balance automatically.</span>
            <?php if (!empty($form['ChangeID'])): ?><a href="<?php echo cah(caUrl(['edit' => null])); ?>" class="btn ca-btn-ghost">Cancel Edit</a><?php endif; ?>
            <button class="btn btn-primary"><i class="fas fa-save"></i> <?php echo !empty($form['ChangeID']) ? 'Update Entry' : 'Add Entry'; ?></button>
        </div>
    </form>

    <!-- Filters -->
    <div class="ca-card">
        <form method="GET" class="ca-filter">
            <div class="ca-field"><label>Academic Year</label><select name="year"><option value="0">All years</option>
                <?php foreach ($academicYears as $y): ?><option value="<?php echo (int)$y['YearID']; ?>" <?php echo $filters['year'] === (int)$y['YearID'] ? 'selected' : ''; ?>><?php echo cah($y['YearName']); ?></option><?php endforeach; ?></select></div>
            <div class="ca-field"><label>Member</label><select name="member"><option value="">All entries</option><option value="general" <?php echo $filters['member'] === 'general' ? 'selected' : ''; ?>>General only</option>
                <?php foreach ($members as $m): ?><option value="<?php echo (int)$m['MemberID']; ?>" <?php echo $filters['member'] === (string)$m['MemberID'] ? 'selected' : ''; ?>><?php echo cah($m['MemberID'] . ' - ' . $m['MemberName']); ?></option><?php endforeach; ?></select></div>
            <div class="ca-field"><label>Type</label><select name="direction"><option value="">Add &amp; Deduct</option><option value="add" <?php echo $filters['direction'] === 'add' ? 'selected' : ''; ?>>Add only</option><option value="deduct" <?php echo $filters['direction'] === 'deduct' ? 'selected' : ''; ?>>Deduct only</option></select></div>
            <div class="ca-field"><label>From</label><input type="date" name="from" value="<?php echo cah($filters['from']); ?>"></div>
            <div class="ca-field"><label>To</label><input type="date" name="to" value="<?php echo cah($filters['to']); ?>"></div>
            <div class="ca-field"><label>Search</label><input type="text" name="q" value="<?php echo cah($filters['q']); ?>" placeholder="Description, reference, member"></div>
            <div style="display:flex;gap:6px">
                <button class="btn btn-primary ca-btn-sm"><i class="fas fa-search"></i> Filter</button>
                <a class="btn ca-btn-ghost ca-btn-sm" href="change-amount.php">Reset</a>
                <a class="btn btn-success ca-btn-sm" href="<?php echo cah(caUrl(['export' => 'csv', 'edit' => null, 'p' => null])); ?>"><i class="fas fa-file-csv"></i> CSV</a>
            </div>
        </form>
    </div>

    <!-- List -->
    <form method="POST" class="ca-card" id="caList" action="<?php echo cah(caUrl(['edit' => null])); ?>">
        <input type="hidden" name="rb_token" value="<?php echo cah($_SESSION['rb_token']); ?>">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap">
            <strong style="color:var(--text-primary)"><?php echo $totalEntries; ?> entr<?php echo $totalEntries === 1 ? 'y' : 'ies'; ?></strong>
            <span style="flex:1"></span>
            <button class="btn btn-danger ca-btn-sm" name="action" value="delete" onclick="return caConfirmDelete()"><i class="fas fa-trash"></i> Delete Selected</button>
        </div>
        <div class="ca-table-wrap">
            <table class="ca-table">
                <thead><tr><th><input type="checkbox" onchange="document.querySelectorAll('.caRow').forEach(c => c.checked = this.checked)"></th>
                    <th>Date</th><th>Year</th><th>Member</th><th>Description</th><th>Mode / Ref.</th><th class="num">Amount (&#8377;)</th><th></th></tr></thead>
                <tbody>
                <?php if (!$entries): ?><tr><td colspan="8" style="text-align:center;padding:30px;color:var(--text-secondary)"><i class="fas fa-inbox"></i> No change amount entries.</td></tr><?php endif; ?>
                <?php foreach ($entries as $e): ?>
                    <tr>
                        <td><input type="checkbox" class="caRow" name="change_ids[]" value="<?php echo (int)$e['ChangeID']; ?>"></td>
                        <td><?php echo cah(date('d-m-Y', strtotime($e['TransactionDate']))); ?><div class="muted">#<?php echo (int)$e['ChangeID']; ?></div></td>
                        <td><?php echo cah($e['YearName']); ?></td>
                        <td><?php echo $e['MemberID'] ? cah($e['MemberID'] . ' - ' . $e['MemberName']) : '<span class="ca-tag">General</span>'; ?></td>
                        <td><?php echo cah($e['Description']); ?><?php if ($e['Remarks']): ?><div class="muted"><?php echo cah($e['Remarks']); ?></div><?php endif; ?></td>
                        <td class="muted"><?php echo cah(trim(($e['PaymentMode'] ?? '') . ($e['ReferenceNumber'] ? ' · ' . $e['ReferenceNumber'] : ''), ' ·') ?: '-'); ?></td>
                        <td class="num <?php echo $e['Amount'] >= 0 ? 'plus' : 'minus'; ?>"><?php echo ($e['Amount'] >= 0 ? '+' : '-') . number_format(abs($e['Amount']), 2); ?></td>
                        <td style="white-space:nowrap"><a class="btn btn-warning ca-btn-sm" href="<?php echo cah(caUrl(['edit' => $e['ChangeID']])); ?>#caForm" title="Edit"><i class="fas fa-edit"></i></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="ca-pages">
                <?php for ($i = max(1, $pageNo - 4); $i <= min($totalPages, $pageNo + 4); $i++): ?>
                    <?php if ($i === $pageNo): ?><span class="cur"><?php echo $i; ?></span><?php else: ?><a href="<?php echo cah(caUrl(['p' => $i, 'edit' => null])); ?>"><?php echo $i; ?></a><?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </form>
</div>
<script>
function caConfirmDelete() {
    const n = document.querySelectorAll('.caRow:checked').length;
    if (!n) { alert('Select one or more entries first.'); return false; }
    return confirm('Delete ' + n + ' change amount entr' + (n === 1 ? 'y' : 'ies') + '? Year totals and member balances will be recalculated.');
}
</script>
</body>
</html>
