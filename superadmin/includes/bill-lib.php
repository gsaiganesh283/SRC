<?php
/**
 * Bill Generator engine: member due bills, payment receipts, member statements,
 * event contribution receipts and expense vouchers.
 *
 * Every bill is calculated from the existing records, saved in `bills` with its own number
 * (the printed content is stored too, so a re-print is identical) and printed on the shared letterhead.
 *
 * Requires: config.php ($pdo), includes/functions.php, includes/logo-helper.php, superadmin/fpdf.php
 */

require_once __DIR__ . '/letterhead-pdf.php';
require_once dirname(__DIR__, 2) . '/includes/change-amount-helper.php';

/** type => [menu label, printed title, default number prefix, icon] */
const BILL_TYPES = [
    'due' => ['Member Due Bill', 'Due Bill', 'BILL', 'fa-file-invoice-dollar'],
    'receipt' => ['Payment Receipt', 'Payment Receipt', 'RCPT', 'fa-receipt'],
    'statement' => ['Member Statement', 'Member Account Statement', 'STMT', 'fa-file-lines'],
    'event' => ['Event Contribution Receipt', 'Contribution Receipt', 'EVT', 'fa-calendar-check'],
    'expense' => ['Expense Voucher', 'Expense Voucher', 'VCH', 'fa-file-invoice'],
    'change' => ['Change Amount Voucher', 'Change Amount Voucher', 'CHG', 'fa-coins'],
];
const BILL_STATUSES = ['Unpaid', 'Paid', 'Issued', 'Cancelled'];
const BILL_PAYMENT_MODES = ['Cash', 'Online', 'UPI', 'Bank Transfer', 'Cheque'];

// ── Tables ────────────────────────────────────────────────────────────────
function billEnsureTables(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bills (
            BillID INT AUTO_INCREMENT PRIMARY KEY,
            BillNo VARCHAR(60) NOT NULL,
            BillType VARCHAR(20) NOT NULL,
            BillDate DATE NOT NULL,
            DueDate DATE DEFAULT NULL,
            PeriodFrom DATE DEFAULT NULL,
            PeriodTo DATE DEFAULT NULL,
            MemberID INT DEFAULT NULL,
            YearID INT DEFAULT NULL,
            EventID INT DEFAULT NULL,
            RefType VARCHAR(30) DEFAULT NULL COMMENT 'transaction | event_transaction | expense',
            RefID INT DEFAULT NULL,
            PartyName VARCHAR(200) NOT NULL DEFAULT '',
            SubTotal DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            Adjustments DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            Total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            Status VARCHAR(20) NOT NULL DEFAULT 'Issued',
            Content MEDIUMTEXT NOT NULL COMMENT 'Printed content (JSON), so re-prints are identical',
            Notes TEXT DEFAULT NULL,
            BatchNo VARCHAR(40) DEFAULT NULL,
            PrintCount INT NOT NULL DEFAULT 0,
            CreatedBy INT DEFAULT NULL,
            CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PaidAt DATETIME DEFAULT NULL,
            CancelledAt DATETIME DEFAULT NULL,
            CancelReason VARCHAR(255) DEFAULT NULL,
            UNIQUE KEY uq_bill_no (BillNo),
            KEY idx_bill_type (BillType),
            KEY idx_bill_member (MemberID),
            KEY idx_bill_status (Status),
            KEY idx_bill_date (BillDate),
            KEY idx_bill_ref (RefType, RefID),
            KEY idx_bill_batch (BatchNo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bill_counters (
            CounterKey VARCHAR(120) NOT NULL PRIMARY KEY,
            LastNo INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $done = true;
}

/** True when both bill tables exist (used by the Database Update page) */
function billTablesExist(PDO $pdo) {
    $s = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('bills', 'bill_counters')");
    return (int)$s->fetchColumn() === 2;
}

// ── Settings (system_settings: bill_settings, JSON) ───────────────────────
function billDefaultSettings() {
    $prefixes = $terms = [];
    foreach (BILL_TYPES as $type => $info) {
        $prefixes[$type] = $info[2];
        $terms[$type] = '';
    }
    $terms['due'] = 'Please pay the amount on or before the due date. Ignore if already paid.';
    $terms['receipt'] = 'Thank you for your payment.';
    $terms['event'] = 'Thank you for your contribution.';
    return [
        'number_format' => '{PREFIX}/{FY}/{SEQ}',
        'seq_digits' => 4,
        'prefixes' => $prefixes,
        'terms' => $terms,
        'letterhead_id' => 0,
        'due_days' => 10,
        'sign_left' => 'Prepared By',
        'sign_right' => 'Authorised Signatory',
        'show_words' => true,
        'show_signature' => true,
    ];
}

function billNormalizeSettings($raw) {
    $d = billDefaultSettings();
    $raw = is_array($raw) ? $raw : [];
    $out = $d;
    $format = is_string($raw['number_format'] ?? null) ? trim($raw['number_format']) : '';
    $rest = str_replace(['{PREFIX}', '{FY}', '{YYYY}', '{YY}', '{MM}', '{SEQ}'], '', $format);
    if (strpos($format, '{SEQ}') !== false && strlen($format) <= 60 && preg_match('/^[A-Za-z0-9\/_\-.]*$/', $rest)) {
        $out['number_format'] = $format;
    }
    $out['seq_digits'] = max(1, min(8, (int)($raw['seq_digits'] ?? $d['seq_digits'])));
    foreach (BILL_TYPES as $type => $info) {
        $p = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', (string)($raw['prefixes'][$type] ?? '')));
        $out['prefixes'][$type] = $p !== '' ? substr($p, 0, 12) : $d['prefixes'][$type];
        if (isset($raw['terms'][$type]) && is_string($raw['terms'][$type])) {
            $out['terms'][$type] = mb_substr(trim($raw['terms'][$type]), 0, 1000);
        }
    }
    $out['letterhead_id'] = max(0, (int)($raw['letterhead_id'] ?? 0));
    $out['due_days'] = max(0, min(365, (int)($raw['due_days'] ?? $d['due_days'])));
    foreach (['sign_left', 'sign_right'] as $k) {
        if (isset($raw[$k]) && is_string($raw[$k])) $out[$k] = mb_substr(trim($raw[$k]), 0, 60);
    }
    foreach (['show_words', 'show_signature'] as $k) {
        if (array_key_exists($k, $raw)) $out[$k] = billOn($raw[$k]);
    }
    return $out;
}

function billSettings(PDO $pdo) {
    try {
        $s = $pdo->prepare("SELECT SettingValue FROM system_settings WHERE SettingKey = 'bill_settings' LIMIT 1");
        $s->execute();
        $raw = json_decode((string)$s->fetchColumn(), true);
    } catch (Exception $e) {
        $raw = null;
    }
    return billNormalizeSettings($raw);
}

function billSaveSettings(PDO $pdo, array $settings, $by = null) {
    $json = json_encode(billNormalizeSettings($settings), JSON_UNESCAPED_UNICODE);
    $s = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE SettingKey = 'bill_settings'");
    $s->execute();
    if ($s->fetchColumn()) {
        $pdo->prepare("UPDATE system_settings SET SettingValue = ?, UpdatedBy = COALESCE(?, UpdatedBy), UpdatedAt = NOW() WHERE SettingKey = 'bill_settings'")->execute([$json, $by]);
    } else {
        $pdo->prepare("INSERT INTO system_settings (SettingKey, SettingValue, UpdatedBy, UpdatedAt) VALUES ('bill_settings', ?, ?, NOW())")->execute([$json, $by]);
    }
}

// ── Small helpers ─────────────────────────────────────────────────────────
function billOn($v) {
    return $v === true || in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on'], true);
}

/** A valid Y-m-d date, or $default */
function billDateOr($value, $default) {
    $value = trim((string)$value);
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : $default;
}

function billShowDate($ymd) {
    return ($ymd && $ymd > '1970-01-01') ? date('d-m-Y', strtotime($ymd)) : '-';
}

function billMoney($amount) {
    return formatIndianAmount($amount);
}

/** Indian financial year of a date: 2026-10-19 -> "2026-27" */
function billFinancialYear(DateTime $d) {
    $y = (int)$d->format('Y');
    $start = (int)$d->format('n') >= 4 ? $y : $y - 1;
    return $start . '-' . substr((string)($start + 1), -2);
}

/** 1234.5 -> "Rupees One Thousand Two Hundred Thirty Four and Fifty Paise Only" */
function billAmountInWords($amount) {
    $amount = round(abs((float)$amount), 2);
    $rupees = (int)floor($amount);
    $paise = (int)round(($amount - $rupees) * 100);
    $text = 'Rupees ' . ($rupees ? billNumberWords($rupees) : 'Zero');
    if ($paise) $text .= ' and ' . billNumberWords($paise) . ' Paise';
    return $text . ' Only';
}

function billNumberWords($n) {
    static $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
        'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    static $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $n = (int)$n;
    if ($n < 20) return $ones[$n];
    if ($n < 100) return trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    if ($n < 1000) return trim($ones[intdiv($n, 100)] . ' Hundred ' . billNumberWords($n % 100));
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$size, $name]) {
        if ($n >= $size) return trim(billNumberWords(intdiv($n, $size)) . ' ' . $name . ' ' . billNumberWords($n % $size));
    }
    return '';
}

/** "Maintenance = 100" lines -> [['label' => , 'amount' => ], ...] */
function billParseCharges($text) {
    $out = [];
    foreach (preg_split('/\r?\n/', (string)$text) as $line) {
        if (preg_match('/^\s*(.+?)\s*[=:,]\s*(-?[\d,]+(?:\.\d+)?)\s*$/', $line, $m)) {
            $amount = (float)str_replace(',', '', $m[2]);
            if ($amount != 0) $out[] = ['label' => mb_substr($m[1], 0, 100), 'amount' => $amount];
        }
    }
    return array_slice($out, 0, 20);
}

// ── Generation options (from the form / the desktop app) ─────────────────
function billOptions(array $src, array $settings) {
    $type = isset(BILL_TYPES[$src['bill_type'] ?? '']) ? $src['bill_type'] : 'due';
    $from = billDateOr($src['period_from'] ?? '', date('Y-m-01'));
    $to = billDateOr($src['period_to'] ?? '', date('Y-m-t'));
    if ($from > $to) {
        throw new InvalidArgumentException('The From date must be on or before the To date.');
    }
    $pick = function ($key, array $allowed, $default) use ($src) {
        $v = (string)($src[$key] ?? '');
        return in_array($v, $allowed, true) ? $v : $default;
    };
    $ids = $src['member_ids'] ?? [];
    if (is_string($ids)) $ids = preg_split('/[\s,]+/', $ids);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids), function ($id) { return $id > 0; })));
    $billIds = $src['bill_ids'] ?? [];
    if (is_string($billIds)) $billIds = preg_split('/[\s,]+/', $billIds);
    return [
        'type' => $type,
        'year_id' => (int)($src['year_id'] ?? 0),
        'member_ids' => $ids,
        'bill_ids' => array_values(array_unique(array_filter(array_map('intval', (array)$billIds)))),
        'from' => $from,
        'to' => $to,
        'bill_date' => billDateOr($src['bill_date'] ?? '', date('Y-m-d')),
        'due_mode' => $pick('due_mode', ['auto', 'fixed', 'days'], 'auto'),
        'due_date' => billDateOr($src['due_date'] ?? '', ''),
        'due_days' => isset($src['due_days']) && $src['due_days'] !== '' ? max(0, min(365, (int)$src['due_days'])) : $settings['due_days'],
        'include_zero' => billOn($src['include_zero'] ?? ''),
        'charges' => billParseCharges($src['charges'] ?? ''),
        'late_fee_type' => $pick('late_fee_type', ['none', 'fixed', 'percent'], 'none'),
        'late_fee_value' => max(0, (float)($src['late_fee_value'] ?? 0)),
        'discount_type' => $pick('discount_type', ['none', 'fixed', 'percent'], 'none'),
        'discount_value' => max(0, (float)($src['discount_value'] ?? 0)),
        'round' => $pick('round', ['none', '1', '10'], 'none'),
        'receipt_mode' => $pick('receipt_mode', ['each', 'combined'], 'each'),
        'payment_mode' => $pick('payment_mode', BILL_PAYMENT_MODES, ''),
        'event_id' => (int)($src['event_id'] ?? 0),
        'expense_category' => mb_substr(trim((string)($src['expense_category'] ?? '')), 0, 100),
        'letterhead_id' => isset($src['letterhead_id']) && $src['letterhead_id'] !== '' ? max(0, (int)$src['letterhead_id']) : $settings['letterhead_id'],
        'copies' => $pick('copies', ['1', '2'], '1'),
        'show_words' => array_key_exists('show_words', $src) ? billOn($src['show_words']) : $settings['show_words'],
        'show_signature' => array_key_exists('show_signature', $src) ? billOn($src['show_signature']) : $settings['show_signature'],
        'show_balance' => billOn($src['show_balance'] ?? ''),
        'terms' => array_key_exists('terms', $src) ? mb_substr(trim((string)$src['terms']), 0, 1000) : ($settings['terms'][$type] ?? ''),
        'notes' => mb_substr(trim((string)($src['notes'] ?? '')), 0, 1000),
        'existing' => $pick('existing', ['reprint', 'skip', 'replace'], 'reprint'),
        'sort' => $pick('sort', ['id', 'name'], 'id'),
        'output' => $pick('output', ['pdf', 'zip'], 'pdf'),
    ];
}

// ── Data loaders ──────────────────────────────────────────────────────────
function billYear(PDO $pdo, $yearId) {
    if ($yearId <= 0) return null;
    $s = $pdo->prepare('SELECT * FROM academic_years WHERE YearID = ?');
    $s->execute([$yearId]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The chosen members, or (none chosen) everyone taking part in the year */
function billMembers(PDO $pdo, $yearId, array $ids, $sort) {
    $order = $sort === 'name' ? 'MemberName, MemberID' : 'MemberID';
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT MemberID, MemberName, Phone, Address, Email FROM members WHERE MemberID IN ($in) ORDER BY $order");
        $s->execute($ids);
    } else {
        $s = $pdo->prepare("SELECT MemberID, MemberName, Phone, Address, Email FROM members
            WHERE MemberID IN (SELECT MemberID FROM transactions WHERE YearID = ?)
               OR MemberID IN (SELECT MemberID FROM year_members WHERE YearID = ? AND Status = 'Active')
            ORDER BY $order");
        $s->execute([$yearId, $yearId]);
    }
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/** Member balance (Distributed + Interest - Collection ± change amount) before a date, per member */
function billOpeningBalances(PDO $pdo, $yearId, $before, array $ids) {
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT MemberID, SUM(COALESCE(DistributedAmount, 0) + COALESCE(InterestAmount, 0) - COALESCE(CollectionAmount, 0))
        FROM transactions WHERE YearID = ? AND TransactionDate < ? AND MemberID IN ($in) GROUP BY MemberID");
    $s->execute(array_merge([$yearId, $before], $ids));
    $out = array_map('floatval', $s->fetchAll(PDO::FETCH_KEY_PAIR));
    foreach (caMemberTotals($pdo, $yearId, $ids, $before) as $id => $chg) {
        $out[$id] = ($out[$id] ?? 0) + $chg;
    }
    return $out;
}

/** Transactions and change amount entries of the members in a period, grouped by member, in date order */
function billPeriodEntries(PDO $pdo, $yearId, $from, $to, array $ids) {
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT TransactionID, MemberID, TransactionDate, COALESCE(DistributedAmount, 0) AS Dist, COALESCE(InterestAmount, 0) AS Intr,
               COALESCE(CollectionAmount, 0) AS Coll, 0 AS Chg, PaymentMode, ReferenceNumber, NextMonthDue, Remarks, Notes
        FROM transactions WHERE YearID = ? AND TransactionDate BETWEEN ? AND ? AND MemberID IN ($in)
        ORDER BY TransactionDate, TransactionID");
    $s->execute(array_merge([$yearId, $from, $to], $ids));
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['MemberID']][] = $r;
    }
    foreach (caMemberEntries($pdo, $yearId, $from, $to, $ids) as $memberId => $list) {
        foreach ($list as $c) {
            $out[$memberId][] = ['TransactionID' => 0, 'MemberID' => $memberId, 'TransactionDate' => $c['TransactionDate'], 'Dist' => 0, 'Intr' => 0, 'Coll' => 0,
                'Chg' => (float)$c['Amount'], 'PaymentMode' => $c['PaymentMode'], 'ReferenceNumber' => $c['ReferenceNumber'], 'NextMonthDue' => null,
                'Remarks' => $c['Description'], 'Notes' => ''];
        }
        usort($out[$memberId], function ($a, $b) { return strcmp($a['TransactionDate'], $b['TransactionDate']); });
    }
    return $out;
}

function billShort($text, $max = 48) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

/** Remark of a transaction worth printing (legacy rows sometimes hold only a month name there) */
function billRemark(array $t) {
    $r = trim((string)($t['Remarks'] ?? '')) ?: trim((string)($t['Notes'] ?? ''));
    return in_array(ucfirst(strtolower($r)), ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'], true) ? '' : $r;
}

// ── Builders: each returns ['drafts' => [...], 'skipped' => n] ─────────────
function billBuild(PDO $pdo, array $o) {
    switch ($o['type']) {
        case 'receipt': return billBuildReceipts($pdo, $o);
        case 'statement': return billBuildStatements($pdo, $o);
        case 'event': return billBuildEventReceipts($pdo, $o);
        case 'expense': return billBuildVouchers($pdo, $o);
        case 'change': return billBuildChangeVouchers($pdo, $o);
        default: return billBuildDue($pdo, $o);
    }
}

function billNeedYear(PDO $pdo, array $o) {
    $year = billYear($pdo, $o['year_id']);
    if (!$year) throw new InvalidArgumentException('Please choose an academic year.');
    return $year;
}

/** Base draft every builder fills in */
function billDraft(array $o, array $fields) {
    return array_merge([
        'type' => $o['type'],
        'bill_date' => $o['bill_date'],
        'due_date' => null,
        'period_from' => $o['from'],
        'period_to' => $o['to'],
        'member_id' => null,
        'year_id' => $o['year_id'] ?: null,
        'event_id' => null,
        'ref_type' => null,
        'ref_id' => null,
        'party' => '',
        'subtotal' => 0,
        'adjust' => 0,
        'total' => 0,
        'status' => 'Issued',
        'notes' => $o['notes'],
        'content' => [],
    ], $fields);
}

/** Member due bill: balance brought forward + this period's entries + charges, late fee, discount, round off */
function billBuildDue(PDO $pdo, array $o) {
    $year = billNeedYear($pdo, $o);
    $members = billMembers($pdo, $o['year_id'], $o['member_ids'], $o['sort']);
    $ids = array_map('intval', array_column($members, 'MemberID'));
    $opening = billOpeningBalances($pdo, $o['year_id'], $o['from'], $ids);
    $entries = billPeriodEntries($pdo, $o['year_id'], $o['from'], $o['to'], $ids);
    $lastDue = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = $pdo->prepare("SELECT MemberID, MAX(NextMonthDue) FROM transactions WHERE YearID = ? AND TransactionDate <= ? AND NextMonthDue > '1970-01-01' AND MemberID IN ($in) GROUP BY MemberID");
        $s->execute(array_merge([$o['year_id'], $o['to']], $ids));
        $lastDue = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    $drafts = [];
    $skipped = 0;
    foreach ($members as $m) {
        $id = (int)$m['MemberID'];
        $open = round($opening[$id] ?? 0, 2);
        $rows = [];
        $n = 0;
        $balance = $open;
        $paidInPeriod = 0;
        if (abs($open) >= 0.005) {
            $rows[] = [++$n, $open >= 0 ? 'Previous balance brought forward' : 'Previous credit (advance) brought forward', 'before ' . billShowDate($o['from']), billMoney($open)];
        }
        foreach ($entries[$id] ?? [] as $t) {
            $date = billShowDate($t['TransactionDate']);
            if ($t['Dist'] > 0) { $rows[] = [++$n, 'Distribution', $date, billMoney($t['Dist'])]; $balance += $t['Dist']; }
            if ($t['Intr'] > 0) { $rows[] = [++$n, 'Interest', $date, billMoney($t['Intr'])]; $balance += $t['Intr']; }
            if ($t['Coll'] > 0) {
                $how = trim(($t['PaymentMode'] ?? '') . (trim((string)$t['ReferenceNumber']) !== '' ? ', Ref ' . trim($t['ReferenceNumber']) : ''), ', ');
                $rows[] = [++$n, 'Less: Payment received' . ($how !== '' ? ' (' . $how . ')' : ''), $date, '-' . billMoney($t['Coll'])];
                $balance -= $t['Coll'];
                $paidInPeriod += $t['Coll'];
            }
            if ($t['Chg'] != 0) {
                $rows[] = [++$n, ($t['Chg'] > 0 ? 'Change amount' : 'Less: Change amount') . ($t['Remarks'] !== '' ? ' - ' . billShort($t['Remarks'], 60) : ''), $date,
                    ($t['Chg'] < 0 ? '-' : '') . billMoney(abs($t['Chg']))];
                $balance += $t['Chg'];
            }
        }
        $charges = 0;
        foreach ($o['charges'] as $c) {
            $rows[] = [++$n, $c['label'], '', billMoney($c['amount'])];
            $charges += $c['amount'];
        }
        $balance = round($balance, 2);
        $subtotal = round($balance + $charges, 2);
        $summary = [['Balance as per account', $balance]];
        if ($charges != 0) $summary[] = ['Other charges', $charges];
        $adjust = 0;
        // Late fee only on arrears still unpaid after this period's payments
        $arrears = round($open - $paidInPeriod, 2);
        if ($o['late_fee_type'] !== 'none' && $o['late_fee_value'] > 0 && $arrears > 0) {
            $late = round($o['late_fee_type'] === 'percent' ? $arrears * $o['late_fee_value'] / 100 : $o['late_fee_value'], 2);
            $summary[] = ['Late fee on arrears' . ($o['late_fee_type'] === 'percent' ? ' (' . rtrim(rtrim(number_format($o['late_fee_value'], 2), '0'), '.') . '%)' : ''), $late];
            $adjust += $late;
        }
        if ($o['discount_type'] !== 'none' && $o['discount_value'] > 0 && $subtotal + $adjust > 0) {
            $disc = round($o['discount_type'] === 'percent' ? ($subtotal + $adjust) * $o['discount_value'] / 100 : min($o['discount_value'], $subtotal + $adjust), 2);
            $summary[] = ['Less: Discount' . ($o['discount_type'] === 'percent' ? ' (' . rtrim(rtrim(number_format($o['discount_value'], 2), '0'), '.') . '%)' : ''), -$disc];
            $adjust -= $disc;
        }
        $total = round($subtotal + $adjust, 2);
        if ($o['round'] !== 'none') {
            $step = (int)$o['round'];
            $rounded = round($total / $step) * $step;
            if (abs($rounded - $total) >= 0.005) {
                $summary[] = ['Round off', round($rounded - $total, 2)];
                $adjust += $rounded - $total;
                $total = $rounded;
            }
        }
        if ($total < 0.005 && !$o['include_zero']) {
            $skipped++;
            continue;
        }
        if ($o['due_mode'] === 'fixed' && $o['due_date'] !== '') {
            $due = $o['due_date'];
        } elseif ($o['due_mode'] === 'auto' && !empty($lastDue[$id]) && $lastDue[$id] >= $o['bill_date']) {
            $due = $lastDue[$id];
        } else {
            $due = date('Y-m-d', strtotime($o['bill_date'] . ' +' . $o['due_days'] . ' days'));
        }
        $drafts[] = billDraft($o, [
            'due_date' => $due,
            'member_id' => $id,
            'party' => $m['MemberName'],
            'subtotal' => $subtotal,
            'adjust' => round($adjust, 2),
            'total' => $total,
            'status' => $total >= 0.005 ? 'Unpaid' : 'Issued',
            'content' => [
                'details' => [
                    ['Member:', billShort($m['MemberName']), 'Due Date:', billShowDate($due)],
                    ['Member ID:', (string)$id, 'Period:', billShowDate($o['from']) . ' to ' . billShowDate($o['to'])],
                    ['Phone:', $m['Phone'] ?: '-', 'Year:', $year['YearName']],
                ],
                'columns' => [
                    ['label' => 'S.No', 'width' => 12, 'align' => 'C'],
                    ['label' => 'Particulars', 'width' => 104, 'align' => 'L'],
                    ['label' => 'Date', 'width' => 34, 'align' => 'C'],
                    ['label' => 'Amount (Rs.)', 'width' => 40, 'align' => 'R'],
                ],
                'rows' => $rows,
                'empty' => 'No entries for this period.',
                'summary' => $summary,
                'total_label' => $total >= 0 ? 'Total Amount Payable' : 'Credit Balance (Advance)',
                'terms' => $o['terms'],
                'sign_left' => null,
            ],
        ]);
    }
    return ['drafts' => $drafts, 'skipped' => $skipped];
}

/** Payment receipts: one per collection, or one per member for the whole period */
function billBuildReceipts(PDO $pdo, array $o) {
    $year = billNeedYear($pdo, $o);
    $where = 't.YearID = ? AND t.TransactionDate BETWEEN ? AND ? AND COALESCE(t.CollectionAmount, 0) > 0';
    $args = [$o['year_id'], $o['from'], $o['to']];
    if ($o['member_ids']) {
        $where .= ' AND t.MemberID IN (' . implode(',', array_fill(0, count($o['member_ids']), '?')) . ')';
        $args = array_merge($args, $o['member_ids']);
    }
    if ($o['payment_mode'] !== '') {
        $where .= ' AND t.PaymentMode = ?';
        $args[] = $o['payment_mode'];
    }
    $order = $o['sort'] === 'name' ? 'm.MemberName, t.MemberID' : 't.MemberID';
    $s = $pdo->prepare("SELECT t.TransactionID, t.MemberID, t.TransactionDate, t.CollectionAmount, t.PaymentMode, t.ReferenceNumber, t.ReceiptNumber, t.Remarks, t.Notes,
               COALESCE(m.MemberName, CONCAT('Member #', t.MemberID)) AS MemberName, m.Phone
        FROM transactions t LEFT JOIN members m ON m.MemberID = t.MemberID
        WHERE $where ORDER BY $order, t.TransactionDate, t.TransactionID");
    $s->execute($args);
    $payments = $s->fetchAll(PDO::FETCH_ASSOC);

    $balanceAfter = function ($memberId, $date, $txnId) use ($pdo, $o) {
        $b = $pdo->prepare('SELECT SUM(COALESCE(DistributedAmount, 0) + COALESCE(InterestAmount, 0) - COALESCE(CollectionAmount, 0)) FROM transactions
            WHERE MemberID = ? AND YearID = ? AND (TransactionDate < ? OR (TransactionDate = ? AND TransactionID <= ?))');
        $b->execute([$memberId, $o['year_id'], $date, $date, $txnId]);
        return round((float)$b->fetchColumn(), 2);
    };
    $columns = [
        ['label' => 'S.No', 'width' => 12, 'align' => 'C'],
        ['label' => 'Date', 'width' => 24, 'align' => 'C'],
        ['label' => 'Particulars', 'width' => 72, 'align' => 'L'],
        ['label' => 'Mode', 'width' => 26, 'align' => 'C'],
        ['label' => 'Reference', 'width' => 26, 'align' => 'C'],
        ['label' => 'Amount (Rs.)', 'width' => 30, 'align' => 'R'],
    ];
    $line = function ($n, $p) {
        $remark = billRemark($p);
        return [$n, billShowDate($p['TransactionDate']), 'Payment towards chitti account - ' . date('F Y', strtotime($p['TransactionDate'])) . ($remark !== '' ? ' (' . billShort($remark, 60) . ')' : ''),
            $p['PaymentMode'] ?: '-', trim((string)$p['ReferenceNumber']) ?: (trim((string)$p['ReceiptNumber']) ?: '-'), billMoney($p['CollectionAmount'])];
    };

    $drafts = [];
    if ($o['receipt_mode'] === 'each') {
        foreach ($payments as $p) {
            $amount = round((float)$p['CollectionAmount'], 2);
            $summary = [];
            if ($o['show_balance']) $summary[] = ['Balance outstanding after this payment', $balanceAfter($p['MemberID'], $p['TransactionDate'], $p['TransactionID']), 'info'];
            $drafts[] = billDraft($o, [
                'period_from' => $p['TransactionDate'],
                'period_to' => $p['TransactionDate'],
                'member_id' => (int)$p['MemberID'],
                'ref_type' => 'transaction',
                'ref_id' => (int)$p['TransactionID'],
                'party' => $p['MemberName'],
                'subtotal' => $amount,
                'total' => $amount,
                'content' => [
                    'details' => [
                        ['Received from:', billShort($p['MemberName']), 'Payment Date:', billShowDate($p['TransactionDate'])],
                        ['Member ID:', (string)$p['MemberID'], 'Mode:', $p['PaymentMode'] ?: '-'],
                        ['Phone:', $p['Phone'] ?: '-', 'Year:', $year['YearName']],
                    ],
                    'columns' => $columns,
                    'rows' => [$line(1, $p)],
                    'summary' => $summary,
                    'total_label' => 'Total Amount Received',
                    'terms' => $o['terms'],
                    'sign_left' => 'Received By',
                ],
            ]);
        }
        return ['drafts' => $drafts, 'skipped' => 0];
    }

    $byMember = [];
    foreach ($payments as $p) $byMember[$p['MemberID']][] = $p;
    foreach ($byMember as $memberId => $list) {
        $rows = [];
        $total = 0;
        foreach ($list as $i => $p) {
            $rows[] = $line($i + 1, $p);
            $total += $p['CollectionAmount'];
        }
        $total = round($total, 2);
        $last = end($list);
        $summary = [['Number of payments', count($list), 'count']];
        if ($o['show_balance']) $summary[] = ['Balance outstanding after these payments', $balanceAfter($memberId, $last['TransactionDate'], $last['TransactionID']), 'info'];
        $drafts[] = billDraft($o, [
            'member_id' => (int)$memberId,
            'party' => $list[0]['MemberName'],
            'subtotal' => $total,
            'total' => $total,
            'content' => [
                'details' => [
                    ['Received from:', billShort($list[0]['MemberName']), 'Period:', billShowDate($o['from']) . ' to ' . billShowDate($o['to'])],
                    ['Member ID:', (string)$memberId, 'Year:', $year['YearName']],
                    ['Phone:', $list[0]['Phone'] ?: '-', 'Payments:', (string)count($list)],
                ],
                'columns' => $columns,
                'rows' => $rows,
                'summary' => $summary,
                'total_label' => 'Total Amount Received',
                'terms' => $o['terms'],
                'sign_left' => 'Received By',
            ],
        ]);
    }
    return ['drafts' => $drafts, 'skipped' => 0];
}

/** Member account statement with running balance */
function billBuildStatements(PDO $pdo, array $o) {
    $year = billNeedYear($pdo, $o);
    $members = billMembers($pdo, $o['year_id'], $o['member_ids'], $o['sort']);
    $ids = array_map('intval', array_column($members, 'MemberID'));
    $opening = billOpeningBalances($pdo, $o['year_id'], $o['from'], $ids);
    $entries = billPeriodEntries($pdo, $o['year_id'], $o['from'], $o['to'], $ids);
    $drafts = [];
    $skipped = 0;
    foreach ($members as $m) {
        $id = (int)$m['MemberID'];
        $open = round($opening[$id] ?? 0, 2);
        $list = $entries[$id] ?? [];
        if (!$list && abs($open) < 0.005 && !$o['include_zero']) {
            $skipped++;
            continue;
        }
        $bal = $open;
        $rows = [[billShowDate($o['from']), 'Opening balance', '', '', '', '', billMoney($open)]];
        $td = $ti = $tc = $tch = 0;
        foreach ($list as $t) {
            $bal += $t['Dist'] + $t['Intr'] - $t['Coll'] + $t['Chg'];
            $td += $t['Dist']; $ti += $t['Intr']; $tc += $t['Coll']; $tch += $t['Chg'];
            $what = [];
            if ($t['Dist'] > 0) $what[] = 'Distribution';
            if ($t['Intr'] > 0) $what[] = 'Interest';
            if ($t['Coll'] > 0) $what[] = 'Payment' . ($t['PaymentMode'] ? ' (' . $t['PaymentMode'] . ')' : '');
            if ($t['Chg'] != 0) $what[] = 'Change amount';
            $remark = billRemark($t);
            $rows[] = [billShowDate($t['TransactionDate']), implode(' + ', $what) . ($remark !== '' ? ' - ' . billShort($remark, 40) : ''),
                $t['Dist'] > 0 ? billMoney($t['Dist']) : '-', $t['Intr'] > 0 ? billMoney($t['Intr']) : '-', $t['Chg'] != 0 ? billMoney($t['Chg']) : '-',
                $t['Coll'] > 0 ? billMoney($t['Coll']) : '-', billMoney($bal)];
        }
        $bal = round($bal, 2);
        $drafts[] = billDraft($o, [
            'member_id' => $id,
            'party' => $m['MemberName'],
            'subtotal' => $bal,
            'total' => $bal,
            'content' => [
                'details' => [
                    ['Member:', billShort($m['MemberName']), 'Period:', billShowDate($o['from']) . ' to ' . billShowDate($o['to'])],
                    ['Member ID:', (string)$id, 'Year:', $year['YearName']],
                    ['Phone:', $m['Phone'] ?: '-', 'Entries:', (string)count($list)],
                ],
                'columns' => [
                    ['label' => 'Date', 'width' => 21, 'align' => 'C'],
                    ['label' => 'Particulars', 'width' => 53, 'align' => 'L'],
                    ['label' => 'Distributed', 'width' => 24, 'align' => 'R'],
                    ['label' => 'Interest', 'width' => 21, 'align' => 'R'],
                    ['label' => 'Change', 'width' => 21, 'align' => 'R'],
                    ['label' => 'Collection', 'width' => 24, 'align' => 'R'],
                    ['label' => 'Balance', 'width' => 26, 'align' => 'R'],
                ],
                'rows' => $rows,
                'summary' => array_merge([['Opening balance', $open], ['Add: Distributed', round($td, 2)], ['Add: Interest', round($ti, 2)]],
                    abs($tch) >= 0.005 ? [['Change amount', round($tch, 2)]] : [], [['Less: Collection', -round($tc, 2)]]),
                'total_label' => $bal >= 0 ? 'Closing Balance (Payable)' : 'Closing Balance (Advance)',
                'terms' => $o['terms'],
                'sign_left' => null,
            ],
        ]);
    }
    return ['drafts' => $drafts, 'skipped' => $skipped];
}

/** One receipt per event collection */
function billBuildEventReceipts(PDO $pdo, array $o) {
    $where = "et.TransactionType = 'Collection' AND COALESCE(et.Status, 'Confirmed') <> 'Cancelled' AND et.TransactionDate BETWEEN ? AND ?";
    $args = [$o['from'], $o['to']];
    if ($o['event_id'] > 0) {
        $where .= ' AND et.EventID = ?';
        $args[] = $o['event_id'];
    }
    if ($o['payment_mode'] !== '') {
        $where .= ' AND et.PaymentMode = ?';
        $args[] = $o['payment_mode'];
    }
    $order = $o['sort'] === 'name' ? 'et.ContributorName, et.TransactionDate' : 'et.TransactionDate, et.TransactionID';
    $s = $pdo->prepare("SELECT et.*, e.EventName, e.EventDate, e.Venue FROM event_transactions et LEFT JOIN events e ON e.EventID = et.EventID WHERE $where ORDER BY $order");
    $s->execute($args);
    $drafts = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $amount = round((float)$t['Amount'], 2);
        $who = trim((string)$t['ContributorName']) ?: 'Well-wisher';
        $drafts[] = billDraft($o, [
            'period_from' => $t['TransactionDate'],
            'period_to' => $t['TransactionDate'],
            'year_id' => null,
            'event_id' => (int)$t['EventID'],
            'ref_type' => 'event_transaction',
            'ref_id' => (int)$t['TransactionID'],
            'party' => $who,
            'subtotal' => $amount,
            'total' => $amount,
            'content' => [
                'details' => [
                    ['Received from:', billShort($who), 'Paid On:', billShowDate($t['TransactionDate'])],
                    ['Contact:', $t['ContributorContact'] ?: '-', 'Mode:', $t['PaymentMode'] ?: '-'],
                    ['Event:', billShort($t['EventName'] ?: ('Event #' . $t['EventID'])), 'Event Date:', billShowDate($t['EventDate'])],
                ],
                'columns' => [
                    ['label' => 'S.No', 'width' => 12, 'align' => 'C'],
                    ['label' => 'Date', 'width' => 24, 'align' => 'C'],
                    ['label' => 'Description', 'width' => 82, 'align' => 'L'],
                    ['label' => 'Receipt / Ref.', 'width' => 36, 'align' => 'C'],
                    ['label' => 'Amount (Rs.)', 'width' => 36, 'align' => 'R'],
                ],
                'rows' => [[1, billShowDate($t['TransactionDate']), billShort($t['Description'] ?: 'Contribution', 120) . ($t['Venue'] ? ' - ' . billShort($t['Venue'], 40) : ''),
                    trim((string)$t['ReceiptNumber']) ?: '-', billMoney($amount)]],
                'summary' => [],
                'total_label' => 'Total Amount Received',
                'terms' => $o['terms'],
                'sign_left' => 'Received By',
            ],
        ]);
    }
    return ['drafts' => $drafts, 'skipped' => 0];
}

/** One payment voucher per expense */
function billBuildVouchers(PDO $pdo, array $o) {
    $where = "e.ExpenseDate BETWEEN ? AND ? AND COALESCE(e.Status, 'Approved') NOT IN ('Rejected', 'Cancelled')";
    $args = [$o['from'], $o['to']];
    if ($o['year_id'] > 0) {
        $where .= ' AND e.YearID = ?';
        $args[] = $o['year_id'];
    }
    if ($o['expense_category'] !== '') {
        $where .= ' AND e.Category = ?';
        $args[] = $o['expense_category'];
    }
    if ($o['payment_mode'] !== '') {
        $where .= ' AND e.PaymentMode = ?';
        $args[] = $o['payment_mode'];
    }
    $order = $o['sort'] === 'name' ? 'e.VendorName, e.ExpenseDate' : 'e.ExpenseDate, e.ExpenseID';
    $s = $pdo->prepare("SELECT e.* FROM expenses e WHERE $where ORDER BY $order");
    $s->execute($args);
    $drafts = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $amount = round((float)$e['Amount'], 2);
        $vendor = trim((string)$e['VendorName']) ?: '-';
        $drafts[] = billDraft($o, [
            'period_from' => $e['ExpenseDate'],
            'period_to' => $e['ExpenseDate'],
            'year_id' => $e['YearID'] ? (int)$e['YearID'] : null,
            'ref_type' => 'expense',
            'ref_id' => (int)$e['ExpenseID'],
            'party' => $vendor,
            'subtotal' => $amount,
            'total' => $amount,
            'content' => [
                'details' => [
                    ['Paid to:', billShort($vendor), 'Expense Date:', billShowDate($e['ExpenseDate'])],
                    ['Category:', billShort($e['Category'] . ($e['SubCategory'] ? ' / ' . $e['SubCategory'] : '')), 'Mode:', $e['PaymentMode'] ?: '-'],
                    ['Invoice No:', trim((string)$e['InvoiceNumber']) ?: '-', 'Expense ID:', (string)$e['ExpenseID']],
                ],
                'columns' => [
                    ['label' => 'S.No', 'width' => 12, 'align' => 'C'],
                    ['label' => 'Date', 'width' => 24, 'align' => 'C'],
                    ['label' => 'Description', 'width' => 92, 'align' => 'L'],
                    ['label' => 'Receipt No', 'width' => 28, 'align' => 'C'],
                    ['label' => 'Amount (Rs.)', 'width' => 34, 'align' => 'R'],
                ],
                'rows' => [[1, billShowDate($e['ExpenseDate']), billShort($e['Description'], 200) . (trim((string)$e['Remarks']) !== '' ? "\n" . billShort($e['Remarks'], 120) : ''),
                    trim((string)$e['ReceiptNumber']) ?: '-', billMoney($amount)]],
                'summary' => [],
                'total_label' => 'Total Amount Paid',
                'terms' => $o['terms'],
                'sign_left' => "Receiver's Signature",
                'sign_left_name' => $vendor !== '-' ? $vendor : '',
            ],
        ]);
    }
    return ['drafts' => $drafts, 'skipped' => 0];
}

/** One voucher per change amount entry (members and general) */
function billBuildChangeVouchers(PDO $pdo, array $o) {
    caEnsureTable($pdo);
    $where = 'c.TransactionDate BETWEEN ? AND ?';
    $args = [$o['from'], $o['to']];
    if ($o['year_id'] > 0) {
        $where .= ' AND c.YearID = ?';
        $args[] = $o['year_id'];
    }
    if ($o['member_ids']) {
        $where .= ' AND c.MemberID IN (' . implode(',', array_fill(0, count($o['member_ids']), '?')) . ')';
        $args = array_merge($args, $o['member_ids']);
    }
    if ($o['payment_mode'] !== '') {
        $where .= ' AND c.PaymentMode = ?';
        $args[] = $o['payment_mode'];
    }
    $order = $o['sort'] === 'name' ? 'm.MemberName, c.TransactionDate' : 'c.TransactionDate, c.ChangeID';
    $s = $pdo->prepare("SELECT c.*, m.MemberName, m.Phone, ay.YearName FROM change_amount_transactions c
        LEFT JOIN members m ON m.MemberID = c.MemberID LEFT JOIN academic_years ay ON ay.YearID = c.YearID WHERE $where ORDER BY $order");
    $s->execute($args);
    $drafts = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $amount = round((float)$c['Amount'], 2);
        $party = $c['MemberID'] ? ($c['MemberName'] ?: 'Member #' . $c['MemberID']) : 'General (Colony)';
        $drafts[] = billDraft($o, [
            'period_from' => $c['TransactionDate'],
            'period_to' => $c['TransactionDate'],
            'member_id' => $c['MemberID'] ? (int)$c['MemberID'] : null,
            'year_id' => (int)$c['YearID'],
            'ref_type' => 'change_amount',
            'ref_id' => (int)$c['ChangeID'],
            'party' => $party,
            'subtotal' => $amount,
            'total' => $amount,
            'content' => [
                'details' => [
                    [$c['MemberID'] ? 'Member:' : 'Account:', billShort($party), 'Entry Date:', billShowDate($c['TransactionDate'])],
                    ['Member ID:', $c['MemberID'] ? (string)$c['MemberID'] : '-', 'Type:', $amount >= 0 ? 'Add (+)' : 'Deduct (-)'],
                    ['Phone:', $c['Phone'] ?: '-', 'Year:', $c['YearName'] ?: '-'],
                ],
                'columns' => [
                    ['label' => 'S.No', 'width' => 12, 'align' => 'C'],
                    ['label' => 'Date', 'width' => 24, 'align' => 'C'],
                    ['label' => 'Description', 'width' => 88, 'align' => 'L'],
                    ['label' => 'Mode / Ref.', 'width' => 32, 'align' => 'C'],
                    ['label' => 'Amount (Rs.)', 'width' => 34, 'align' => 'R'],
                ],
                'rows' => [[1, billShowDate($c['TransactionDate']), billShort($c['Description'], 200) . (trim((string)$c['Remarks']) !== '' ? "\n" . billShort($c['Remarks'], 120) : ''),
                    trim(($c['PaymentMode'] ?? '') . ($c['ReferenceNumber'] ? ' ' . $c['ReferenceNumber'] : '')) ?: '-', ($amount < 0 ? '-' : '') . billMoney(abs($amount))]],
                'summary' => [],
                'total_label' => $amount >= 0 ? 'Change Amount Added' : 'Change Amount Deducted',
                'terms' => $o['terms'],
                'sign_left' => null,
            ],
        ]);
    }
    return ['drafts' => $drafts, 'skipped' => 0];
}

// ── Saving ────────────────────────────────────────────────────────────────
/** Next bill number for a type and date, e.g. BILL/2026-27/0001 (atomic per number stem) */
function billNextNumber(PDO $pdo, $type, $date, array $settings) {
    $d = new DateTime($date);
    $stem = strtr($settings['number_format'], [
        '{PREFIX}' => $settings['prefixes'][$type] ?? BILL_TYPES[$type][2],
        '{FY}' => billFinancialYear($d),
        '{YYYY}' => $d->format('Y'),
        '{YY}' => $d->format('y'),
        '{MM}' => $d->format('m'),
    ]);
    $key = mb_substr($stem, 0, 120);
    $exists = $pdo->prepare('SELECT COUNT(*) FROM bills WHERE BillNo = ?');
    for ($i = 0; $i < 1000; $i++) {
        $pdo->prepare('INSERT INTO bill_counters (CounterKey, LastNo) VALUES (?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE LastNo = LAST_INSERT_ID(LastNo + 1)')->execute([$key]);
        $n = (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
        $no = str_replace('{SEQ}', str_pad((string)$n, $settings['seq_digits'], '0', STR_PAD_LEFT), $stem);
        $exists->execute([$no]);
        if (!$exists->fetchColumn()) return $no;
    }
    throw new RuntimeException('Could not find a free bill number for ' . $stem);
}

/** The newest live bill for the same record (or member and period) */
function billFindExisting(PDO $pdo, array $draft) {
    if ($draft['ref_type']) {
        $s = $pdo->prepare("SELECT * FROM bills WHERE BillType = ? AND RefType = ? AND RefID = ? AND Status <> 'Cancelled' ORDER BY BillID DESC LIMIT 1");
        $s->execute([$draft['type'], $draft['ref_type'], $draft['ref_id']]);
    } else {
        $s = $pdo->prepare("SELECT * FROM bills WHERE BillType = ? AND RefType IS NULL AND MemberID <=> ? AND YearID <=> ? AND PeriodFrom = ? AND PeriodTo = ? AND Status <> 'Cancelled' ORDER BY BillID DESC LIMIT 1");
        $s->execute([$draft['type'], $draft['member_id'], $draft['year_id'], $draft['period_from'], $draft['period_to']]);
    }
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function billInsert(PDO $pdo, array $draft, array $settings, $batch, $userId) {
    $no = billNextNumber($pdo, $draft['type'], $draft['bill_date'], $settings);
    $pdo->prepare('INSERT INTO bills (BillNo, BillType, BillDate, DueDate, PeriodFrom, PeriodTo, MemberID, YearID, EventID, RefType, RefID, PartyName,
            SubTotal, Adjustments, Total, Status, Content, Notes, BatchNo, CreatedBy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$no, $draft['type'], $draft['bill_date'], $draft['due_date'], $draft['period_from'], $draft['period_to'], $draft['member_id'], $draft['year_id'],
            $draft['event_id'], $draft['ref_type'], $draft['ref_id'], mb_substr($draft['party'], 0, 200), $draft['subtotal'], $draft['adjust'], $draft['total'],
            $draft['status'], json_encode($draft['content'], JSON_UNESCAPED_UNICODE), $draft['notes'] !== '' ? $draft['notes'] : null, $batch, $userId]);
    return (int)$pdo->lastInsertId();
}

/**
 * Save the drafts as bills, following the "already billed" choice.
 * Returns ['ids' => bill ids to print, 'created' => n, 'reused' => n, 'skipped' => n, 'replaced' => n, 'batch' => batch no]
 */
function billSaveDrafts(PDO $pdo, array $drafts, array $o, array $settings, $userId) {
    billEnsureTables($pdo);
    $batch = 'B' . date('YmdHis') . substr(bin2hex(random_bytes(2)), 0, 3);
    $out = ['ids' => [], 'created' => 0, 'reused' => 0, 'skipped' => 0, 'replaced' => 0, 'batch' => $batch];
    foreach ($drafts as $draft) {
        $pdo->beginTransaction();
        try {
            $old = billFindExisting($pdo, $draft);
            if ($old && $o['existing'] === 'skip') {
                $pdo->commit();
                $out['skipped']++;
                continue;
            }
            if ($old && $o['existing'] === 'reprint') {
                $pdo->commit();
                $out['ids'][] = (int)$old['BillID'];
                $out['reused']++;
                continue;
            }
            $id = billInsert($pdo, $draft, $settings, $batch, $userId);
            if ($old) {
                $newNo = $pdo->query('SELECT BillNo FROM bills WHERE BillID = ' . $id)->fetchColumn();
                $pdo->prepare("UPDATE bills SET Status = 'Cancelled', CancelledAt = NOW(), CancelReason = ? WHERE BillID = ?")->execute(['Replaced by ' . $newNo, $old['BillID']]);
                $out['replaced']++;
            }
            $pdo->commit();
            $out['ids'][] = $id;
            $out['created']++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    return $out;
}

/** Bills by id, in the order given */
function billLoad(PDO $pdo, array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    billEnsureTables($pdo);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT * FROM bills WHERE BillID IN ($in)");
    $s->execute($ids);
    $byId = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $byId[(int)$r['BillID']] = $r;
    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) $out[] = $byId[$id];
    }
    return $out;
}

/** Drafts shown as bills without saving (preview) */
function billPreviewRows(array $drafts) {
    return array_map(function ($d) {
        return [
            'BillID' => 0, 'BillNo' => 'PREVIEW', 'BillType' => $d['type'], 'BillDate' => $d['bill_date'], 'DueDate' => $d['due_date'],
            'PartyName' => $d['party'], 'Total' => $d['total'], 'Status' => 'Preview', 'Content' => json_encode($d['content']), 'Notes' => $d['notes'],
        ];
    }, $drafts);
}

function billMarkPrinted(PDO $pdo, array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return;
    $pdo->prepare('UPDATE bills SET PrintCount = PrintCount + 1 WHERE BillID IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
}

// ── History ───────────────────────────────────────────────────────────────
function billListFilters(array $src) {
    return [
        'type' => isset(BILL_TYPES[$src['f_type'] ?? '']) ? $src['f_type'] : '',
        'status' => in_array($src['f_status'] ?? '', BILL_STATUSES, true) ? $src['f_status'] : '',
        'member' => max(0, (int)($src['f_member'] ?? 0)),
        'from' => billDateOr($src['f_from'] ?? '', ''),
        'to' => billDateOr($src['f_to'] ?? '', ''),
        'q' => mb_substr(trim((string)($src['f_q'] ?? '')), 0, 100),
        'batch' => preg_replace('/[^A-Za-z0-9]/', '', (string)($src['f_batch'] ?? '')),
    ];
}

/** One page of the bill history (without the stored content) */
function billList(PDO $pdo, array $f, $page = 1, $perPage = 25) {
    billEnsureTables($pdo);
    $where = ['1=1'];
    $args = [];
    if ($f['type'] !== '') { $where[] = 'BillType = ?'; $args[] = $f['type']; }
    if ($f['status'] !== '') { $where[] = 'Status = ?'; $args[] = $f['status']; }
    if ($f['member'] > 0) { $where[] = 'MemberID = ?'; $args[] = $f['member']; }
    if ($f['from'] !== '') { $where[] = 'BillDate >= ?'; $args[] = $f['from']; }
    if ($f['to'] !== '') { $where[] = 'BillDate <= ?'; $args[] = $f['to']; }
    if ($f['batch'] !== '') { $where[] = 'BatchNo = ?'; $args[] = $f['batch']; }
    if ($f['q'] !== '') { $where[] = '(BillNo LIKE ? OR PartyName LIKE ? OR Notes LIKE ?)'; $like = '%' . $f['q'] . '%'; array_push($args, $like, $like, $like); }
    $sqlWhere = implode(' AND ', $where);
    $c = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(Total), 0) FROM bills WHERE $sqlWhere");
    $c->execute($args);
    [$count, $sum] = $c->fetch(PDO::FETCH_NUM);
    $pages = max(1, (int)ceil($count / $perPage));
    $page = max(1, min($pages, (int)$page));
    $s = $pdo->prepare("SELECT BillID, BillNo, BillType, BillDate, DueDate, PeriodFrom, PeriodTo, MemberID, YearID, EventID, RefType, RefID, PartyName,
            SubTotal, Adjustments, Total, Status, Notes, BatchNo, PrintCount, CreatedAt, PaidAt, CancelledAt, CancelReason
        FROM bills WHERE $sqlWhere ORDER BY BillID DESC LIMIT " . (int)$perPage . ' OFFSET ' . (int)(($page - 1) * $perPage));
    $s->execute($args);
    return ['rows' => $s->fetchAll(PDO::FETCH_ASSOC), 'total' => (int)$count, 'sum' => (float)$sum, 'page' => $page, 'pages' => $pages];
}

function billStats(PDO $pdo) {
    billEnsureTables($pdo);
    $r = $pdo->query("SELECT COUNT(*) AS total,
            SUM(Status = 'Unpaid') AS unpaid, COALESCE(SUM(CASE WHEN Status = 'Unpaid' THEN Total END), 0) AS unpaid_amount,
            SUM(Status = 'Paid') AS paid, SUM(Status = 'Cancelled') AS cancelled,
            SUM(BillDate >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS this_month
        FROM bills")->fetch(PDO::FETCH_ASSOC);
    return array_map(function ($v) { return $v === null ? 0 : (float)$v; }, $r);
}

/** Due bills whose member has since paid at least the bill total are marked Paid; returns how many */
function billAutoMarkPaid(PDO $pdo) {
    billEnsureTables($pdo);
    $rows = $pdo->query("SELECT b.BillID, b.Total,
            (SELECT COALESCE(SUM(t.CollectionAmount), 0) FROM transactions t WHERE t.MemberID = b.MemberID AND t.YearID = b.YearID AND t.TransactionDate >= b.BillDate) AS paid
        FROM bills b WHERE b.BillType = 'due' AND b.Status = 'Unpaid'")->fetchAll(PDO::FETCH_ASSOC);
    $n = 0;
    $u = $pdo->prepare("UPDATE bills SET Status = 'Paid', PaidAt = NOW() WHERE BillID = ? AND Status = 'Unpaid'");
    foreach ($rows as $r) {
        if ((float)$r['paid'] + 0.005 >= (float)$r['Total']) {
            $u->execute([$r['BillID']]);
            $n += $u->rowCount();
        }
    }
    return $n;
}

// ── PDF ───────────────────────────────────────────────────────────────────
class BillPDF extends LetterheadPDF
{
    /** First page of the bill being printed (the full letterhead goes there) */
    public $billStart = 1;
    /** Header values of the next bill, applied when its first page starts */
    protected $pending = null;
    public $showWords = true;
    public $showSignature = true;
    public $signRight = 'Authorised Signatory';
    public $signLeft = 'Prepared By';
    public $preparedBy = '';

    function startBill(array $meta)
    {
        $this->repeatTableHeader = false;
        $this->pending = $meta;
        $this->AddPage();
    }

    function Header()
    {
        if ($this->pending !== null) {
            foreach ($this->pending as $k => $v) $this->$k = $v;
            $this->billStart = $this->PageNo();
            $this->pending = null;
        }
        if ($this->PageNo() == $this->billStart) {
            $this->letterhead();
        } else {
            $this->compactHeader();
        }
        if ($this->repeatTableHeader) {
            $this->tableHeader();
        }
    }

    function Footer()
    {
        $left = $this->lMargin;
        $right = $this->w - $this->rMargin;
        $this->SetY(-17);
        $this->SetDrawColor(...$this->accent);
        $this->SetLineWidth(0.3);
        $this->Line($left, $this->GetY(), $right, $this->GetY());
        $this->Ln(1.5);
        $this->SetFont('Helvetica', 'I', 7.5);
        $this->SetTextColor(...self::MUTED);
        $this->Cell($this->contentWidth() - 40, 5, pdfText($this->footerNote . '  |  No: ' . $this->refNo . '  |  Generated: ' . $this->generatedAt), 0, 0, 'L');
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->Cell(40, 5, 'Page ' . ($this->PageNo() - $this->billStart + 1), 0, 0, 'R');
        if ($this->opt('bands', true)) {
            $this->SetFillColor(...$this->accent);
            $this->Rect(0, $this->h - 5, $this->w, 0.8, 'F');
            $this->SetFillColor(...$this->primary);
            $this->Rect(0, $this->h - 4.2, $this->w, 4.2, 'F');
        }
    }

    /** Print one bill row from `bills` (or a preview row) */
    function renderBill(array $bill, $copyLabel = '')
    {
        $c = json_decode($bill['Content'], true) ?: [];
        $type = $bill['BillType'];
        $title = BILL_TYPES[$type][1] ?? 'Bill';
        $details = $c['details'] ?? [];
        $numberLabel = in_array($type, ['receipt', 'event'], true) ? 'Receipt No:' : (in_array($type, ['expense', 'change'], true) ? 'Voucher No:' : ($type === 'statement' ? 'Statement No:' : 'Bill No:'));
        array_unshift($details, [$numberLabel, $bill['BillNo'], 'Date:', billShowDate($bill['BillDate'])]);
        $this->startBill([
            'docTitle' => $title . ($copyLabel !== '' ? '  -  ' . $copyLabel : ''),
            'compactTitle' => $title . '  ' . $bill['BillNo'],
            'periodText' => billShort($bill['PartyName'], 40),
            'details' => $details,
            'refNo' => $bill['BillNo'],
            'footerNote' => $bill['Status'] === 'Preview' ? 'PREVIEW - not saved' : 'Computer-generated ' . strtolower($title),
            'columns' => $c['columns'] ?? [],
        ]);

        // Items
        $this->tableHeader();
        $this->repeatTableHeader = true;
        $rows = $c['rows'] ?? [];
        if (!$rows) {
            $this->SetFont('Helvetica', 'I', 9);
            $this->SetTextColor(...self::MUTED);
            $this->SetDrawColor(...self::BORDER);
            $this->Cell($this->contentWidth(), 10, pdfText($c['empty'] ?? 'No entries.'), 1, 1, 'C');
        }
        foreach ($rows as $i => $row) {
            $this->dataRow(array_map(function ($v) { return pdfText((string)$v); }, $row), $i % 2 === 1);
        }
        $this->repeatTableHeader = false;

        // Summary lines and total, on the right; status stamp on the left
        $summary = $c['summary'] ?? [];
        $need = 12 + 7 * (count($summary) + 1) + ($this->showWords ? 8 : 0);
        if ($this->GetY() + $need > $this->PageBreakTrigger) {
            $this->AddPage();
        }
        $this->Ln(4);
        $top = $this->GetY();
        $boxX = $this->lMargin + $this->contentWidth() - 105;
        foreach ($summary as $line) {
            [$label, $value] = $line;
            $kind = $line[2] ?? '';
            $this->SetX($boxX);
            $this->SetFont('Helvetica', $kind === 'info' ? 'I' : '', 9);
            $this->SetTextColor(...($kind === 'info' ? self::MUTED : self::INK));
            $this->SetDrawColor(...self::BORDER);
            $this->Cell(65, 6.5, '  ' . pdfText($label), 'B', 0, 'L');
            $this->SetFont('Helvetica', 'B', 9);
            $this->Cell(40, 6.5, ($kind === 'count' ? (string)$value : billMoney($value)) . '  ', 'B', 1, 'R');
        }
        $this->SetX($boxX);
        $this->SetFont('Helvetica', 'B', 10.5);
        $this->SetFillColor(...$this->primarySoft);
        $this->SetDrawColor(...$this->primary);
        $this->SetTextColor(...$this->primary);
        $this->Cell(65, 9, '  ' . pdfText($c['total_label'] ?? 'Total'), 'TB', 0, 'L', true);
        $this->Cell(40, 9, 'Rs. ' . billMoney(abs((float)$bill['Total'])) . '  ', 'TB', 1, 'R', true);
        $after = $this->GetY();

        $stamp = ['Paid' => [[22, 163, 74], 'PAID'], 'Cancelled' => [[220, 38, 38], 'CANCELLED'], 'Preview' => [[217, 119, 6], 'PREVIEW'], 'Unpaid' => [[217, 119, 6], 'UNPAID']][$bill['Status']] ?? null;
        if ($stamp) {
            $this->SetXY($this->lMargin + 4, $top + 2);
            $this->SetDrawColor(...$stamp[0]);
            $this->SetTextColor(...$stamp[0]);
            $this->SetLineWidth(0.8);
            $this->SetFont('Helvetica', 'B', 16);
            $this->Cell(52, 13, $stamp[1], 1, 2, 'C');
            $this->SetFont('Helvetica', '', 7.5);
            $sub = $bill['Status'] === 'Paid' && !empty($bill['PaidAt']) ? 'on ' . date('d-m-Y', strtotime($bill['PaidAt']))
                : ($bill['Status'] === 'Cancelled' ? billShort($bill['CancelReason'] ?? '', 40) : ($bill['Status'] === 'Unpaid' && !empty($bill['DueDate']) ? 'Due by ' . billShowDate($bill['DueDate']) : ''));
            if ($sub !== '') $this->Cell(52, 5, pdfText($sub), 0, 1, 'C');
            $this->SetLineWidth(0.2);
            $after = max($after, $this->GetY());
        }
        $this->SetXY($this->lMargin, $after + 3);

        if ($this->showWords) {
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetTextColor(...self::INK);
            $this->Cell(30, 6, 'Amount in words:', 0, 0, 'L');
            $this->SetFont('Helvetica', 'I', 9);
            $this->MultiCell(0, 6, pdfText(billAmountInWords($bill['Total'])), 0, 'L');
        }
        foreach ([['Notes', $bill['Notes'] ?? ''], ['Terms', $c['terms'] ?? '']] as [$label, $text]) {
            if (trim((string)$text) === '') continue;
            $this->Ln(1.5);
            $this->SetFont('Helvetica', 'B', 8.5);
            $this->SetTextColor(...$this->primary);
            $this->Cell(0, 5, $label, 0, 1, 'L');
            $this->SetFont('Helvetica', '', 8.5);
            $this->SetTextColor(...self::MUTED);
            $this->MultiCell(0, 4.5, pdfText($text), 0, 'L');
        }
        if ($this->showSignature) {
            $this->Ln(2);
            $this->signatureBlock(($c['sign_left'] ?? null) ?: $this->signLeft, $c['sign_left_name'] ?? $this->preparedBy, $this->signRight, 'For ' . ($this->org['name'] ?? ''));
        }
    }
}

/**
 * The PDF for a list of bill rows. $o: letterhead_id, copies, show_words, show_signature
 */
function billMakePdf(PDO $pdo, array $bills, array $o, array $settings, $preparedBy = '') {
    $pdf = new BillPDF('P', 'mm', 'A4');
    $pdf->applyLetterhead(rbGetLetterhead($pdo, (int)($o['letterhead_id'] ?? $settings['letterhead_id'])));
    $pdf->generatedAt = date('d-m-Y h:i A');
    $pdf->showWords = (bool)($o['show_words'] ?? $settings['show_words']);
    $pdf->showSignature = (bool)($o['show_signature'] ?? $settings['show_signature']);
    $pdf->signLeft = $settings['sign_left'];
    $pdf->signRight = $settings['sign_right'];
    $pdf->preparedBy = $preparedBy;
    $pdf->SetTitle(count($bills) === 1 ? $bills[0]['BillNo'] : 'Bills');
    $pdf->SetAuthor(pdfText($pdf->org['name']));
    $pdf->SetCreator('SR Colony Chitti Management System');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 22);
    $labels = ($o['copies'] ?? '1') === '2' ? ['Member Copy', 'Office Copy'] : [''];
    foreach ($bills as $bill) {
        foreach ($labels as $label) {
            $copy = $label;
            if ($label === 'Member Copy' && in_array($bill['BillType'], ['event', 'expense'], true)) {
                $copy = $bill['BillType'] === 'expense' ? 'Payee Copy' : 'Contributor Copy';
            }
            $pdf->renderBill($bill, $copy);
        }
    }
    return $pdf;
}

/** Safe file name for a bill number */
function billFileName($text) {
    return trim(preg_replace('/[^A-Za-z0-9\-]+/', '-', (string)$text), '-') ?: 'bill';
}
