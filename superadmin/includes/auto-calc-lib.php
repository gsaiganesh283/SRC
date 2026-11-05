<?php
/**
 * Auto-Calc — a multi-module field-calculation engine.
 *
 * A super admin picks a module (Transactions, Change Amount, Expenses, Event Transactions) and writes
 * a formula for each editable field of that module's form. The same safe formula interpreter as the
 * Report Builder evaluates the formulas (report-formula.php) — nothing is ever passed to eval() or raw
 * SQL. Every value a formula can see is built here from whitelisted, parameterised SQL (acBuildContext),
 * and formulas may also reference OTHER modules' fields as {module.Field} (resolved via the Report
 * Builder's foreign-row loader).
 *
 * Rule-sets are stored as JSON in transaction_calc_rulesets (one per module can be IsActive).
 *
 * Requires: config.php ($pdo), includes/functions.php (createAuditLog),
 *   superadmin/includes/report-formula.php, report-format.php, report-builder-lib.php,
 *   includes/net-balance-helper.php (getCurrentMemberNetBalance + change-amount helpers).
 */

require_once __DIR__ . '/report-formula.php';
require_once __DIR__ . '/report-format.php';
require_once __DIR__ . '/report-builder-lib.php';
require_once __DIR__ . '/../../includes/net-balance-helper.php';

/* ────────────────────────────────────────────────────────────────────────────
 * Module registry — the single source of truth for every module Auto-Calc covers.
 * Each module: label, icon, inputs (calculator selectors), fields (rule targets),
 * variables (what {Name} can reference), default rules, and a context builder.
 * ──────────────────────────────────────────────────────────────────────────── */
function acModules() {
    static $m = null;
    if ($m !== null) return $m;
    $blank = ['formula' => '', 'enabled' => false, 'auto' => false];
    $m = [
        // ── Transactions ─────────────────────────────────────────────────
        'transactions' => [
            'label' => 'Transactions', 'icon' => 'fa-exchange-alt', 'ref' => 'transactions',
            'inputs' => [
                ['key' => 'member_id', 'label' => 'Member', 'type' => 'member'],
                ['key' => 'year_id', 'label' => 'Academic year', 'type' => 'year'],
                ['key' => 'month', 'label' => 'Month', 'type' => 'month'],
                ['key' => 'transaction_date', 'label' => 'Transaction date', 'type' => 'date'],
                ['key' => 'collection_amount', 'label' => 'Collection (₹)', 'type' => 'number'],
                ['key' => 'distributed_amount', 'label' => 'Distributed (₹)', 'type' => 'number'],
            ],
            'fields' => [
                'month_name' => ['Month Name', 'text'], 'transaction_date' => ['Transaction Date', 'date'],
                'payment_mode' => ['Payment Mode', 'text'], 'collection_amount' => ['Collection Amount', 'amount'],
                'distributed_amount' => ['Distributed Amount', 'amount'], 'interest_amount' => ['Interest Amount', 'amount'],
                'fixed_amount' => ['Fixed Amount', 'amount'], 'next_month_due' => ['Next Month Due', 'date'],
                'remarks' => ['Remarks', 'text'],
            ],
            'variables' => [
                'MemberID' => ['Member ID', 'number', 'This record'], 'YearID' => ['Academic year ID', 'number', 'This record'],
                'Month' => ['Month name', 'text', 'This record'], 'MonthNum' => ['Month number (1-12)', 'number', 'This record'],
                'TransactionDate' => ['Transaction date', 'date', 'This record'],
                'CollectionAmount' => ['Collection (typed)', 'amount', 'This record'], 'DistributedAmount' => ['Distributed (typed)', 'amount', 'This record'],
                'InterestAmount' => ['Interest (typed)', 'amount', 'This record'], 'FixedAmount' => ['Fixed (typed)', 'amount', 'This record'],
                'MemberName' => ['Member name', 'text', 'Member'], 'NetBalance' => ['Net balance (this year)', 'amount', 'Member'],
                'NetBalanceAllYears' => ['Net balance (all years)', 'amount', 'Member'],
                'TotalCollection' => ['Total collected (year)', 'amount', 'Member'], 'TotalDistributed' => ['Total distributed (year)', 'amount', 'Member'],
                'TotalInterest' => ['Total interest (year)', 'amount', 'Member'], 'TxnCount' => ['No. of transactions (year)', 'number', 'Member'],
                'LastCollection' => ['Last collection', 'amount', 'Last transaction'], 'LastDistributed' => ['Last distributed', 'amount', 'Last transaction'],
                'LastInterest' => ['Last interest', 'amount', 'Last transaction'], 'LastFixed' => ['Last fixed', 'amount', 'Last transaction'],
                'LastNextMonthDue' => ['Last next-due date', 'date', 'Last transaction'], 'LastTransactionDate' => ['Last transaction date', 'date', 'Last transaction'],
                'LastOutstandingPrincipal' => ['Last outstanding principal', 'amount', 'Last transaction'],
                'YearFixedAmount' => ['Year fixed amount', 'amount', 'Academic year'], 'InterestRate' => ['Interest rate (fraction)', 'number', 'Academic year'],
                'InterestRatePercent' => ['Interest rate (percent)', 'number', 'Academic year'], 'InterestType' => ['Interest type', 'text', 'Academic year'],
                'StandingMonthlyInterest' => ['Standing monthly interest', 'amount', 'Academic year'], 'CollectionModel' => ['Collection model', 'text', 'Academic year'],
                'DeferredPrincipalAmount' => ['Deferred principal', 'amount', 'Academic year'], 'DefaultTransactionDate' => ['Default day of month', 'text', 'Academic year'],
            ],
            'default' => ['fields' => [
                'month_name' => $blank, 'transaction_date' => $blank, 'payment_mode' => $blank,
                'collection_amount' => $blank, 'distributed_amount' => $blank,
                'interest_amount' => ['formula' => '{StandingMonthlyInterest}', 'enabled' => true, 'auto' => true],
                'fixed_amount' => ['formula' => '{YearFixedAmount}', 'enabled' => true, 'auto' => true],
                'next_month_due' => ['formula' => 'ADDMONTHS({TransactionDate}, 1)', 'enabled' => true, 'auto' => true],
                'remarks' => $blank,
            ]],
            'context' => 'acCtxTransactions',
        ],
        // ── Change Amount ────────────────────────────────────────────────
        'change_amount' => [
            'label' => 'Change Amount', 'icon' => 'fa-wallet', 'ref' => 'change_amount',
            'inputs' => [
                ['key' => 'member_id', 'label' => 'Member (blank = general)', 'type' => 'member'],
                ['key' => 'year_id', 'label' => 'Academic year', 'type' => 'year'],
                ['key' => 'transaction_date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'amount', 'label' => 'Amount (₹)', 'type' => 'number'],
            ],
            'fields' => [
                'transaction_date' => ['Transaction Date', 'date'], 'direction' => ['Direction (add/deduct)', 'text'],
                'amount' => ['Amount', 'amount'], 'description' => ['Description', 'text'],
                'payment_mode' => ['Payment Mode', 'text'], 'reference_number' => ['Reference Number', 'text'],
                'remarks' => ['Remarks', 'text'],
            ],
            'variables' => [
                'MemberID' => ['Member ID', 'number', 'This record'], 'YearID' => ['Academic year ID', 'number', 'This record'],
                'TransactionDate' => ['Date', 'date', 'This record'], 'Amount' => ['Amount (typed)', 'amount', 'This record'],
                'MemberName' => ['Member name', 'text', 'Member'], 'NetBalance' => ['Net balance (this year)', 'amount', 'Member'],
                'MemberChangeTotal' => ['Member change total (year)', 'amount', 'Member'],
                'YearChangeTotal' => ['Year change total', 'amount', 'Academic year'], 'YearGeneralTotal' => ['Year general (colony) total', 'amount', 'Academic year'],
                'YearMembersTotal' => ['Year members total', 'amount', 'Academic year'], 'EntryCount' => ['No. of entries (year)', 'number', 'Academic year'],
                'YearFixedAmount' => ['Year fixed amount', 'amount', 'Academic year'],
            ],
            'default' => ['fields' => [
                'transaction_date' => $blank, 'direction' => $blank, 'amount' => $blank,
                'description' => $blank, 'payment_mode' => $blank, 'reference_number' => $blank, 'remarks' => $blank,
            ]],
            'context' => 'acCtxChangeAmount',
        ],
        // ── Expenses ─────────────────────────────────────────────────────
        'expenses' => [
            'label' => 'Expenses', 'icon' => 'fa-receipt', 'ref' => 'expenses',
            'inputs' => [
                ['key' => 'year_id', 'label' => 'Academic year', 'type' => 'year'],
                ['key' => 'expense_date', 'label' => 'Expense date', 'type' => 'date'],
                ['key' => 'category', 'label' => 'Category', 'type' => 'expense_category'],
                ['key' => 'amount', 'label' => 'Amount (₹)', 'type' => 'number'],
            ],
            'fields' => [
                'expense_date' => ['Expense Date', 'date'], 'category' => ['Category', 'text'],
                'sub_category' => ['Sub-category', 'text'], 'description' => ['Description', 'text'],
                'amount' => ['Amount', 'amount'], 'payment_mode' => ['Payment Mode', 'text'],
                'vendor_name' => ['Vendor Name', 'text'], 'invoice_number' => ['Invoice Number', 'text'],
                'receipt_number' => ['Receipt Number', 'text'], 'status' => ['Status', 'text'], 'remarks' => ['Remarks', 'text'],
            ],
            'variables' => [
                'YearID' => ['Academic year ID', 'number', 'This record'], 'ExpenseDate' => ['Expense date', 'date', 'This record'],
                'Category' => ['Category', 'text', 'This record'], 'Amount' => ['Amount (typed)', 'amount', 'This record'],
                'CategoryTotal' => ['Category total (year)', 'amount', 'Category'], 'VendorTotal' => ['Vendor total (year)', 'amount', 'Category'],
                'CategoryYearlyLimit' => ['Category yearly budget', 'amount', 'Budget'], 'CategoryMonthlyLimit' => ['Category monthly budget', 'amount', 'Budget'],
                'CategoryBudgetRemaining' => ['Category budget remaining', 'amount', 'Budget'], 'AlertThreshold' => ['Budget alert threshold (%)', 'number', 'Budget'],
                'YearTotalExpenses' => ['Year total expenses', 'amount', 'Academic year'],
            ],
            'default' => ['fields' => [
                'expense_date' => $blank, 'category' => $blank, 'sub_category' => $blank, 'description' => $blank,
                'amount' => $blank, 'payment_mode' => $blank, 'vendor_name' => $blank, 'invoice_number' => $blank,
                'receipt_number' => $blank, 'status' => $blank, 'remarks' => $blank,
            ]],
            'context' => 'acCtxExpenses',
        ],
        // ── Event Transactions ───────────────────────────────────────────
        'events' => [
            'label' => 'Event Transactions', 'icon' => 'fa-calendar-day', 'ref' => 'event_transactions',
            'inputs' => [
                ['key' => 'event_id', 'label' => 'Event', 'type' => 'event'],
                ['key' => 'transaction_type', 'label' => 'Type', 'type' => 'txn_type'],
                ['key' => 'transaction_date', 'label' => 'Date', 'type' => 'date'],
                ['key' => 'category', 'label' => 'Category', 'type' => 'event_category'],
                ['key' => 'amount', 'label' => 'Amount (₹)', 'type' => 'number'],
            ],
            'fields' => [
                'transaction_date' => ['Transaction Date', 'date'], 'amount' => ['Amount', 'amount'],
                'description' => ['Description', 'text'], 'payment_mode' => ['Payment Mode', 'text'],
                'contributor_name' => ['Contributor Name', 'text'], 'contributor_contact' => ['Contributor Contact', 'text'],
                'receipt_number' => ['Receipt Number', 'text'], 'vendor_name' => ['Vendor Name', 'text'],
                'category' => ['Category', 'text'], 'invoice_number' => ['Invoice Number', 'text'], 'remarks' => ['Remarks', 'text'],
            ],
            'variables' => [
                'EventID' => ['Event ID', 'number', 'This record'], 'TransactionType' => ['Type (Collection/Expense)', 'text', 'This record'],
                'TransactionDate' => ['Date', 'date', 'This record'], 'Amount' => ['Amount (typed)', 'amount', 'This record'],
                'Category' => ['Category (typed)', 'text', 'This record'],
                'EventName' => ['Event name', 'text', 'Event'], 'EventDate' => ['Event date', 'date', 'Event'], 'EventStatus' => ['Event status', 'text', 'Event'],
                'EventBudget' => ['Event budget', 'amount', 'Event'], 'EventCollected' => ['Event collected', 'amount', 'Event'],
                'EventSpent' => ['Event spent', 'amount', 'Event'], 'EventBalance' => ['Event balance', 'amount', 'Event'],
                'EventBudgetVariance' => ['Budget − spent', 'amount', 'Event'], 'CategoryExpense' => ['This category spent', 'amount', 'Event'], 'YearID' => ['Academic year ID', 'number', 'Event'],
            ],
            'default' => ['fields' => [
                'transaction_date' => $blank, 'amount' => $blank, 'description' => $blank, 'payment_mode' => $blank,
                'contributor_name' => $blank, 'contributor_contact' => $blank, 'receipt_number' => $blank,
                'vendor_name' => $blank, 'category' => $blank, 'invoice_number' => $blank, 'remarks' => $blank,
            ]],
            'context' => 'acCtxEvents',
        ],
    ];
    return $m;
}

function acModule($key) {
    $m = acModules();
    return $m[$key] ?? $m['transactions'];
}

function acModuleList() {
    $out = [];
    foreach (acModules() as $key => $m) {
        $out[] = ['key' => $key, 'label' => $m['label'], 'icon' => $m['icon']];
    }
    return $out;
}

/** The editable fields of a module. [key => [label, format]] */
function acFields($module = 'transactions') {
    $out = [];
    foreach (acModule($module)['fields'] as $k => $f) {
        $out[$k] = ['label' => $f[0], 'format' => $f[1]];
    }
    return $out;
}

/** The variables a module's formulas can use. [key => [label, type, group]] */
function acVariables($module = 'transactions') {
    return acModule($module)['variables'];
}

/** Cross-module references: other modules' fields, usable as {ref.Field}. */
function acForeignVariables($module = 'transactions') {
    $out = [];
    foreach (acModules() as $key => $m) {
        if ($key === $module || empty($m['ref'])) continue;
        foreach ($m['fields'] as $fk => $f) {
            // Use the DB column name (PascalCase) where the field key differs; the engine totals it.
            $col = acForeignColumn($key, $fk);
            if ($col === null) continue;
            $out[] = ['key' => $m['ref'] . '.' . $col, 'label' => $m['label'] . ' › ' . $f[0], 'type' => $f[1], 'group' => 'Other: ' . $m['label']];
        }
    }
    return $out;
}

/** Map a module's field key to the DB column used in {ref.Column} references (for other modules' data). */
function acForeignColumn($module, $fieldKey) {
    $map = [
        'transactions' => ['collection_amount' => 'CollectionAmount', 'distributed_amount' => 'DistributedAmount',
            'interest_amount' => 'InterestAmount', 'fixed_amount' => 'FixedAmount', 'transaction_date' => 'TransactionDate'],
        'change_amount' => ['amount' => 'Amount', 'transaction_date' => 'TransactionDate'],
        'expenses' => ['amount' => 'Amount', 'category' => 'Category', 'expense_date' => 'ExpenseDate'],
        'events' => ['amount' => 'Amount', 'category' => 'Category', 'transaction_date' => 'TransactionDate'],
    ];
    return $map[$module][$fieldKey] ?? null;
}

function acDefaultConfig($module = 'transactions') {
    return acNormalizeConfig($module, acModule($module)['default']);
}

/** Clean an incoming config into the stored shape for a module. */
function acNormalizeConfig($module, $cfg) {
    if (is_string($cfg)) $cfg = json_decode($cfg, true);
    if (!is_array($cfg)) $cfg = [];
    $in = isset($cfg['fields']) && is_array($cfg['fields']) ? $cfg['fields'] : [];
    $out = ['fields' => []];
    foreach (acFields($module) as $key => $meta) {
        $f = isset($in[$key]) && is_array($in[$key]) ? $in[$key] : [];
        $out['fields'][$key] = [
            'formula' => isset($f['formula']) ? trim((string)$f['formula']) : '',
            'enabled' => !empty($f['enabled']),
            'auto'    => !empty($f['auto']),
        ];
    }
    return $out;
}

/* ────────────────────────────────────────────────────────────────────────────
 * Storage (one table, a Module column; one active rule-set per module)
 * ──────────────────────────────────────────────────────────────────────────── */
function acEnsureTable(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transaction_calc_rulesets (
            RulesetID INT AUTO_INCREMENT PRIMARY KEY,
            Module VARCHAR(50) NOT NULL DEFAULT 'transactions',
            Name VARCHAR(150) NOT NULL,
            Description VARCHAR(255) DEFAULT NULL,
            Config LONGTEXT NOT NULL,
            IsActive TINYINT(1) NOT NULL DEFAULT 0,
            CreatedBy INT DEFAULT NULL,
            UpdatedBy INT DEFAULT NULL,
            CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_module_active (Module, IsActive)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // Migrate older installs that predate the Module column
    try {
        $has = $pdo->query("SHOW COLUMNS FROM transaction_calc_rulesets LIKE 'Module'")->fetch();
        if (!$has) {
            $pdo->exec("ALTER TABLE transaction_calc_rulesets ADD COLUMN Module VARCHAR(50) NOT NULL DEFAULT 'transactions' AFTER RulesetID");
            $pdo->exec("ALTER TABLE transaction_calc_rulesets ADD INDEX idx_module_active (Module, IsActive)");
        }
    } catch (Exception $e) {
        // index may already exist; ignore
    }
    $done = true;
}

function acGetActiveConfig(PDO $pdo, $module = 'transactions') {
    acEnsureTable($pdo);
    $stmt = $pdo->prepare("SELECT Config FROM transaction_calc_rulesets WHERE IsActive = 1 AND Module = ? ORDER BY UpdatedAt DESC LIMIT 1");
    $stmt->execute([$module]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? acNormalizeConfig($module, $row['Config']) : acDefaultConfig($module);
}

function acListRulesets(PDO $pdo, $module = 'transactions') {
    acEnsureTable($pdo);
    $stmt = $pdo->prepare("SELECT RulesetID, Name, Description, IsActive, UpdatedAt FROM transaction_calc_rulesets WHERE Module = ? ORDER BY IsActive DESC, Name ASC");
    $stmt->execute([$module]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function acLoadRuleset(PDO $pdo, $id) {
    acEnsureTable($pdo);
    $stmt = $pdo->prepare("SELECT RulesetID, Module, Name, Description, IsActive, Config FROM transaction_calc_rulesets WHERE RulesetID = ?");
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $row['Config'] = acNormalizeConfig($row['Module'], $row['Config']);
    return $row ?: null;
}

function acSaveRuleset(PDO $pdo, array $data, $userId) {
    acEnsureTable($pdo);
    $id = (int)($data['id'] ?? 0);
    $module = $data['module'] ?? 'transactions';
    if (!isset(acModules()[$module])) $module = 'transactions';
    $name = trim($data['name'] ?? '') ?: 'Untitled rules';
    $desc = trim($data['description'] ?? '');
    $config = json_encode(acNormalizeConfig($module, $data['config'] ?? []), JSON_UNESCAPED_UNICODE);
    $makeActive = !empty($data['active']);

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE transaction_calc_rulesets SET Name = ?, Description = ?, Config = ?, UpdatedBy = ? WHERE RulesetID = ?");
        $stmt->execute([$name, $desc, $config, $userId, $id]);
    } else {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM transaction_calc_rulesets WHERE Module = ?");
        $cnt->execute([$module]);
        if ((int)$cnt->fetchColumn() === 0) $makeActive = true;
        $stmt = $pdo->prepare("INSERT INTO transaction_calc_rulesets (Module, Name, Description, Config, CreatedBy, UpdatedBy) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$module, $name, $desc, $config, $userId, $userId]);
        $id = (int)$pdo->lastInsertId();
    }
    if ($makeActive) acSetActive($pdo, $id);
    createAuditLog($userId, 'SuperAdmin', 'Auto-Calc', 'Saved rule-set [' . $module . ']', $name, 'transaction_calc_rulesets', $id);
    return $id;
}

function acSetActive(PDO $pdo, $id) {
    acEnsureTable($pdo);
    $mod = $pdo->prepare("SELECT Module FROM transaction_calc_rulesets WHERE RulesetID = ?");
    $mod->execute([(int)$id]);
    $module = $mod->fetchColumn();
    if ($module === false) return false;
    $off = $pdo->prepare("UPDATE transaction_calc_rulesets SET IsActive = 0 WHERE Module = ?");
    $off->execute([$module]);
    $on = $pdo->prepare("UPDATE transaction_calc_rulesets SET IsActive = 1 WHERE RulesetID = ?");
    $on->execute([(int)$id]);
    return true;
}

function acDeleteRuleset(PDO $pdo, $id, $userId) {
    acEnsureTable($pdo);
    $stmt = $pdo->prepare("DELETE FROM transaction_calc_rulesets WHERE RulesetID = ?");
    $stmt->execute([(int)$id]);
    createAuditLog($userId, 'SuperAdmin', 'Auto-Calc', 'Deleted rule-set', 'ID ' . (int)$id, 'transaction_calc_rulesets', (int)$id);
    return $stmt->rowCount() > 0;
}

/* ────────────────────────────────────────────────────────────────────────────
 * Context builders (whitelisted, parameterised SQL) — one per module
 * ──────────────────────────────────────────────────────────────────────────── */
function acNormDate($v) {
    $v = trim((string)$v);
    if ($v === '') return date('Y-m-d');
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : $v;
}

function acBuildContext(PDO $pdo, $module, array $inputs) {
    $fn = acModule($module)['context'];
    return $fn($pdo, $inputs);
}

function acCtxTransactions(PDO $pdo, array $in) {
    $memberId = (int)($in['member_id'] ?? 0);
    $yearId = (int)($in['year_id'] ?? 0);
    $month = trim((string)($in['month'] ?? ''));
    $txnDate = acNormDate($in['transaction_date'] ?? '');
    $monthNum = $month !== '' ? (int)date('n', strtotime('1 ' . $month . ' 2000') ?: time()) : (int)date('n', strtotime($txnDate));

    $row = [
        'MemberID' => $memberId, 'YearID' => $yearId, 'Month' => $month, 'MonthNum' => $monthNum, 'TransactionDate' => $txnDate,
        'CollectionAmount' => (float)($in['collection_amount'] ?? 0), 'DistributedAmount' => (float)($in['distributed_amount'] ?? 0),
        'InterestAmount' => (float)($in['interest_amount'] ?? 0), 'FixedAmount' => (float)($in['fixed_amount'] ?? 0),
        'MemberName' => '', 'NetBalance' => 0.0, 'NetBalanceAllYears' => 0.0,
        'TotalCollection' => 0.0, 'TotalDistributed' => 0.0, 'TotalInterest' => 0.0, 'TxnCount' => 0,
        'LastCollection' => 0.0, 'LastDistributed' => 0.0, 'LastInterest' => 0.0, 'LastFixed' => 0.0,
        'LastNextMonthDue' => '', 'LastTransactionDate' => '', 'LastOutstandingPrincipal' => 0.0,
        'YearFixedAmount' => 0.0, 'InterestRate' => 0.0, 'InterestRatePercent' => 0.0, 'InterestType' => '',
        'StandingMonthlyInterest' => 0.0, 'CollectionModel' => '', 'DeferredPrincipalAmount' => 0.0, 'DefaultTransactionDate' => '',
    ];
    if ($memberId > 0) {
        $s = $pdo->prepare("SELECT MemberName FROM members WHERE MemberID = ?");
        $s->execute([$memberId]);
        $row['MemberName'] = (string)($s->fetchColumn() ?: '');
        $row['NetBalance'] = (float)getCurrentMemberNetBalance($memberId, $yearId > 0 ? $yearId : null);
        $row['NetBalanceAllYears'] = (float)getCurrentMemberNetBalance($memberId, null);
        if ($yearId > 0) {
            $a = $pdo->prepare("SELECT COALESCE(SUM(CollectionAmount),0) tc, COALESCE(SUM(DistributedAmount),0) td, COALESCE(SUM(InterestAmount),0) ti, COUNT(*) cnt FROM transactions WHERE MemberID=? AND YearID=?");
            $a->execute([$memberId, $yearId]);
            if ($r = $a->fetch(PDO::FETCH_ASSOC)) {
                $row['TotalCollection'] = (float)$r['tc']; $row['TotalDistributed'] = (float)$r['td'];
                $row['TotalInterest'] = (float)$r['ti']; $row['TxnCount'] = (int)$r['cnt'];
            }
            $l = $pdo->prepare("SELECT CollectionAmount, DistributedAmount, InterestAmount, FixedAmount, NextMonthDue, TransactionDate, OutstandingPrincipal FROM transactions WHERE MemberID=? AND YearID=? ORDER BY TransactionDate DESC, TransactionID DESC LIMIT 1");
            $l->execute([$memberId, $yearId]);
            if ($r = $l->fetch(PDO::FETCH_ASSOC)) {
                $row['LastCollection'] = (float)$r['CollectionAmount']; $row['LastDistributed'] = (float)$r['DistributedAmount'];
                $row['LastInterest'] = (float)$r['InterestAmount']; $row['LastFixed'] = (float)$r['FixedAmount'];
                $row['LastNextMonthDue'] = $r['NextMonthDue'] ? date('Y-m-d', strtotime($r['NextMonthDue'])) : '';
                $row['LastTransactionDate'] = $r['TransactionDate'] ? date('Y-m-d', strtotime($r['TransactionDate'])) : '';
                $row['LastOutstandingPrincipal'] = (float)$r['OutstandingPrincipal'];
            }
        }
    }
    if ($yearId > 0) {
        $y = $pdo->prepare("SELECT FixedAmount, InterestRate, InterestType, StandingMonthlyInterest, CollectionModel, DeferredPrincipalAmount, DefaultTransactionDate FROM academic_years WHERE YearID=?");
        $y->execute([$yearId]);
        if ($r = $y->fetch(PDO::FETCH_ASSOC)) {
            $row['YearFixedAmount'] = (float)$r['FixedAmount']; $row['InterestRate'] = (float)$r['InterestRate'];
            $row['InterestRatePercent'] = (float)$r['InterestRate'] * 100; $row['InterestType'] = (string)$r['InterestType'];
            $row['StandingMonthlyInterest'] = (float)$r['StandingMonthlyInterest']; $row['CollectionModel'] = (string)$r['CollectionModel'];
            $row['DeferredPrincipalAmount'] = (float)$r['DeferredPrincipalAmount']; $row['DefaultTransactionDate'] = (string)$r['DefaultTransactionDate'];
        }
    }
    return $row;
}

function acCtxChangeAmount(PDO $pdo, array $in) {
    $memberId = (int)($in['member_id'] ?? 0);
    $yearId = (int)($in['year_id'] ?? 0);
    $row = [
        'MemberID' => $memberId, 'YearID' => $yearId, 'TransactionDate' => acNormDate($in['transaction_date'] ?? ''),
        'Amount' => (float)($in['amount'] ?? 0), 'MemberName' => '', 'NetBalance' => 0.0, 'MemberChangeTotal' => 0.0,
        'YearChangeTotal' => 0.0, 'YearGeneralTotal' => 0.0, 'YearMembersTotal' => 0.0, 'EntryCount' => 0, 'YearFixedAmount' => 0.0,
    ];
    if (function_exists('caTotals') && $yearId > 0) {
        $t = caTotals($pdo, $yearId);
        $row['YearChangeTotal'] = (float)($t['total'] ?? 0); $row['YearGeneralTotal'] = (float)($t['general'] ?? 0);
        $row['YearMembersTotal'] = (float)($t['members'] ?? 0); $row['EntryCount'] = (int)($t['count'] ?? 0);
    }
    if ($memberId > 0) {
        $s = $pdo->prepare("SELECT MemberName FROM members WHERE MemberID=?");
        $s->execute([$memberId]);
        $row['MemberName'] = (string)($s->fetchColumn() ?: '');
        $row['NetBalance'] = (float)getCurrentMemberNetBalance($memberId, $yearId > 0 ? $yearId : null);
        if (function_exists('caMemberTotal')) $row['MemberChangeTotal'] = (float)caMemberTotal($pdo, $memberId, $yearId > 0 ? $yearId : null);
    }
    if ($yearId > 0) {
        $y = $pdo->prepare("SELECT FixedAmount FROM academic_years WHERE YearID=?");
        $y->execute([$yearId]);
        $row['YearFixedAmount'] = (float)($y->fetchColumn() ?: 0);
    }
    return $row;
}

function acCtxExpenses(PDO $pdo, array $in) {
    $yearId = (int)($in['year_id'] ?? 0);
    $category = trim((string)($in['category'] ?? ''));
    $vendor = trim((string)($in['vendor_name'] ?? ''));
    $row = [
        'YearID' => $yearId, 'ExpenseDate' => acNormDate($in['expense_date'] ?? ''), 'Category' => $category,
        'Amount' => (float)($in['amount'] ?? 0), 'CategoryTotal' => 0.0, 'VendorTotal' => 0.0,
        'CategoryYearlyLimit' => 0.0, 'CategoryMonthlyLimit' => 0.0, 'CategoryBudgetRemaining' => 0.0, 'AlertThreshold' => 0.0,
        'YearTotalExpenses' => 0.0,
    ];
    if ($yearId > 0) {
        $t = $pdo->prepare("SELECT COALESCE(SUM(Amount),0) FROM expenses WHERE YearID=? AND Status='Approved'");
        $t->execute([$yearId]);
        $row['YearTotalExpenses'] = (float)$t->fetchColumn();
        if ($category !== '') {
            $c = $pdo->prepare("SELECT COALESCE(SUM(Amount),0) FROM expenses WHERE YearID=? AND Category=? AND Status='Approved'");
            $c->execute([$yearId, $category]);
            $row['CategoryTotal'] = (float)$c->fetchColumn();
            $b = $pdo->prepare("SELECT YearlyLimit, MonthlyLimit, AlertThreshold FROM budget_limits WHERE YearID=? AND Category=? AND IsActive=1 LIMIT 1");
            $b->execute([$yearId, $category]);
            if ($r = $b->fetch(PDO::FETCH_ASSOC)) {
                $row['CategoryYearlyLimit'] = (float)$r['YearlyLimit']; $row['CategoryMonthlyLimit'] = (float)$r['MonthlyLimit'];
                $row['AlertThreshold'] = (float)$r['AlertThreshold'];
                $row['CategoryBudgetRemaining'] = (float)$r['YearlyLimit'] - $row['CategoryTotal'];
            }
        }
    }
    if ($vendor !== '' && $yearId > 0) {
        $v = $pdo->prepare("SELECT COALESCE(SUM(Amount),0) FROM expenses WHERE YearID=? AND VendorName=? AND Status='Approved'");
        $v->execute([$yearId, $vendor]);
        $row['VendorTotal'] = (float)$v->fetchColumn();
    }
    return $row;
}

function acCtxEvents(PDO $pdo, array $in) {
    $eventId = (int)($in['event_id'] ?? 0);
    $type = trim((string)($in['transaction_type'] ?? 'Collection')) ?: 'Collection';
    $category = trim((string)($in['category'] ?? ''));
    $row = [
        'EventID' => $eventId, 'TransactionType' => $type, 'TransactionDate' => acNormDate($in['transaction_date'] ?? ''),
        'Amount' => (float)($in['amount'] ?? 0), 'Category' => $category,
        'EventName' => '', 'EventDate' => '', 'EventStatus' => '', 'EventBudget' => 0.0, 'EventCollected' => 0.0,
        'EventSpent' => 0.0, 'EventBalance' => 0.0, 'EventBudgetVariance' => 0.0, 'CategoryExpense' => 0.0, 'YearID' => 0,
    ];
    if ($eventId > 0) {
        $e = $pdo->prepare("SELECT EventName, EventDate, Status, BudgetAmount, TotalCollection, TotalExpense, EventBalance, YearID FROM events WHERE EventID=?");
        $e->execute([$eventId]);
        if ($r = $e->fetch(PDO::FETCH_ASSOC)) {
            $row['EventName'] = (string)$r['EventName']; $row['EventDate'] = $r['EventDate'] ? date('Y-m-d', strtotime($r['EventDate'])) : '';
            $row['EventStatus'] = (string)$r['Status']; $row['EventBudget'] = (float)$r['BudgetAmount'];
            $row['EventCollected'] = (float)$r['TotalCollection']; $row['EventSpent'] = (float)$r['TotalExpense'];
            $row['EventBalance'] = (float)$r['EventBalance']; $row['EventBudgetVariance'] = (float)$r['BudgetAmount'] - (float)$r['TotalExpense'];
            $row['YearID'] = (int)$r['YearID'];
        }
        if ($category !== '') {
            $c = $pdo->prepare("SELECT COALESCE(SUM(Amount),0) FROM event_transactions WHERE EventID=? AND Category=? AND TransactionType='Expense'");
            $c->execute([$eventId, $category]);
            $row['CategoryExpense'] = (float)$c->fetchColumn();
        }
    }
    return $row;
}

/* ────────────────────────────────────────────────────────────────────────────
 * Evaluation
 * ──────────────────────────────────────────────────────────────────────────── */
function acEvaluate(PDO $pdo, $module, $config, array $inputs) {
    $cfg = acNormalizeConfig($module, $config);
    $row = acBuildContext($pdo, $module, $inputs);

    // Load other modules' rows for any {module.Field} references in the formulas
    $calcs = [];
    foreach ($cfg['fields'] as $f) {
        if ($f['enabled'] && $f['formula'] !== '') $calcs[] = ['formula' => $f['formula']];
    }
    $prevEnv = RbFormulaContext::$env;
    try {
        RbFormulaContext::$env = ['foreign' => rbLoadForeignRows($pdo, ['columns' => [], 'calculations' => $calcs]), 'date_from' => '', 'date_to' => ''];
    } catch (Throwable $e) {
        RbFormulaContext::$env = ['foreign' => [], 'date_from' => '', 'date_to' => ''];
    }

    $layout = ['zero_dash' => false, 'date_format' => 'd-m-Y'];
    $out = [];
    foreach (acFields($module) as $key => $meta) {
        $rule = $cfg['fields'][$key];
        if (!$rule['enabled'] || $rule['formula'] === '') continue;
        $parsed = rbParseFormula($rule['formula']);
        if ($parsed['error'] !== null) {
            $out[$key] = ['error' => $parsed['error'], 'auto' => !empty($rule['auto'])];
            continue;
        }
        $value = rbEvalFormula($rule['formula'], $row);
        $out[$key] = [
            'value' => $value,
            'input' => acInputValue($value, $meta['format']),
            'formatted' => rbFormatValue($value, ['format' => $meta['format']], $layout),
            'auto' => !empty($rule['auto']),
            'error' => null,
        ];
    }
    RbFormulaContext::$env = $prevEnv;
    return ['context' => $row, 'fields' => $out];
}

/** The raw string to drop into a form input for a computed value. */
function acInputValue($value, $format) {
    if ($value === null || $value === '') return '';
    if (in_array($format, ['amount', 'number', 'integer', 'percent'], true)) {
        if (!is_numeric($value)) return (string)$value;
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }
    if ($format === 'date') {
        $ts = strtotime((string)$value);
        return $ts ? date('Y-m-d', $ts) : (string)$value;
    }
    return (string)$value;
}

/** Option lists the calculator inputs need (members, years, events, categories, …). */
function acOptionLists(PDO $pdo) {
    acEnsureTable($pdo);
    $cats = [];
    try {
        $cats = $pdo->query("SELECT CategoryName FROM expense_categories WHERE IsActive=1 ORDER BY SortOrder, CategoryName")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { $cats = []; }
    $events = [];
    try {
        $events = $pdo->query("SELECT EventID, EventName FROM events ORDER BY EventDate DESC, EventID DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $events = []; }
    return [
        'members' => $pdo->query("SELECT MemberID, MemberName FROM members ORDER BY MemberName ASC")->fetchAll(PDO::FETCH_ASSOC),
        'years' => $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearID DESC")->fetchAll(PDO::FETCH_ASSOC),
        'active_year' => (int)$pdo->query("SELECT YearID FROM academic_years WHERE Status='Active' ORDER BY YearID DESC LIMIT 1")->fetchColumn(),
        'months' => ['January','February','March','April','May','June','July','August','September','October','November','December'],
        'payment_modes' => ['Cash','Online','UPI','Bank Transfer','Cheque','Card'],
        'expense_categories' => array_values($cats),
        'event_categories' => ['Venue','Catering','Decoration','Sound & Lighting','Printing','Transportation','Gifts','Security','Photography','Miscellaneous'],
        'txn_types' => ['Collection','Expense'],
        'events' => $events,
        'today' => date('Y-m-d'),
    ];
}
