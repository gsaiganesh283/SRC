<?php
/**
 * Custom Report Builder - engine
 *
 * A report is described by a JSON config (see rbNormalizeConfig). The engine turns it into a
 * render model (columns + items + tiles + calculations) that the live preview, PDF, Excel and CSV
 * all share. Only whitelisted tables/fields ever reach SQL; every value is a bound parameter;
 * formulas run in the interpreter in report-formula.php.
 */

require_once __DIR__ . '/report-format.php';
require_once __DIR__ . '/report-formula.php';

const RB_MAX_ROWS = 20000;
const RB_FORMATS = ['text', 'amount', 'number', 'integer', 'percent', 'date', 'datetime'];
const RB_NUMERIC_FORMATS = ['amount', 'number', 'integer', 'percent'];
const RB_TOTALS = ['none', 'sum', 'avg', 'count', 'min', 'max', 'recalc'];
const RB_CONDITION_OPS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'not_contains', 'starts', 'ends', 'empty', 'not_empty', 'in', 'between'];

/** Tables never offered as a data source (credentials, secrets, internal state) */
const RB_EXCLUDED_TABLES = [
    'admin_login_tokens', 'member_login_tokens', 'super_admins', 'google_drive_settings',
    'system_settings', 'user_settings', 'portal_settings', 'custom_report_templates',
    'member_net_balance_backup', 'bill_counters',
];
/** Columns never offered from any table */
const RB_EXCLUDED_COLUMN_PATTERN = '/(password|passwd|token|secret|totp|face_descriptor|serviceaccount|api_?key|session)/i';

/**
 * All data sources: ready-made ones plus every application table.
 * Field: [label, sql, type]
 */
function rbSources() {
    global $pdo;
    static $all = null;
    if ($all !== null) {
        return $all;
    }
    $sources = rbCuratedSources() + rbTableSources();
    if ($pdo instanceof PDO) {
        $all = $sources; // only cache once the database (and so the table list) is available
    }
    return $sources;
}

function rbCuratedSources() {
    return [
        'transactions' => [
            'label' => 'Transactions',
            'group' => 'Ready-made',
            'module' => 'Chitti Fund',
            'icon' => 'fa-exchange-alt',
            'description' => 'One row per transaction: collections, distributions, interest, due dates.',
            'from' => 'transactions t LEFT JOIN members m ON m.MemberID = t.MemberID LEFT JOIN academic_years y ON y.YearID = t.YearID',
            'date' => 't.TransactionDate',
            'year' => 't.YearID',
            'member' => 't.MemberID',
            'payment_mode' => 't.PaymentMode',
            'search' => ['m.MemberName', 't.Remarks', 't.Notes', 't.ReceiptNumber', 't.ReferenceNumber'],
            'nonzero' => ['CollectionAmount', 'DistributedAmount', 'InterestAmount', 'FixedAmount'],
            'fields' => [
                'TransactionID' => ['Transaction ID', 't.TransactionID', 'integer'],
                'TransactionDate' => ['Date', 't.TransactionDate', 'date'],
                'Month' => ['Month', "DATE_FORMAT(t.TransactionDate, '%b %Y')", 'text'],
                'MemberID' => ['Member ID', 't.MemberID', 'integer'],
                'MemberName' => ['Member Name', "COALESCE(m.MemberName, CONCAT('Member #', t.MemberID))", 'text'],
                'Phone' => ['Phone', 'm.Phone', 'text'],
                'YearName' => ['Academic Year', 'y.YearName', 'text'],
                'CollectionAmount' => ['Collection Amount', 'COALESCE(t.CollectionAmount, 0)', 'amount'],
                'DistributedAmount' => ['Distributed Amount', 'COALESCE(t.DistributedAmount, 0)', 'amount'],
                'InterestAmount' => ['Interest Amount', 'COALESCE(t.InterestAmount, 0)', 'amount'],
                'FixedAmount' => ['Fixed Amount', 'COALESCE(t.FixedAmount, 0)', 'amount'],
                'OutstandingPrincipal' => ['Outstanding Principal', 'COALESCE(t.OutstandingPrincipal, 0)', 'amount'],
                'NextMonthDue' => ['Due Date', "NULLIF(t.NextMonthDue, '0000-00-00')", 'date'],
                'PaymentMode' => ['Payment Mode', 't.PaymentMode', 'text'],
                'ReceiptNumber' => ['Receipt No', 't.ReceiptNumber', 'text'],
                'ReferenceNumber' => ['Reference No', 't.ReferenceNumber', 'text'],
                'Remarks' => ['Remark', "COALESCE(NULLIF(TRIM(t.Remarks), ''), NULLIF(TRIM(t.Notes), ''), '')", 'text'],
                'Verified' => ['Verified', "IF(t.Verified = 1, 'Yes', 'No')", 'text'],
            ],
            'default_columns' => ['MemberID', 'TransactionDate', 'MemberName', 'CollectionAmount', 'DistributedAmount', 'InterestAmount', 'PaymentMode'],
            'default_sort' => 'TransactionDate',
        ],
        'member_summary' => [
            'label' => 'Member Summary',
            'group' => 'Ready-made',
            'module' => 'Chitti Fund',
            'icon' => 'fa-users',
            'description' => 'One row per member with totals for the chosen period.',
            'from' => 'transactions t LEFT JOIN members m ON m.MemberID = t.MemberID',
            'group_sql' => 't.MemberID',
            'date' => 't.TransactionDate',
            'year' => 't.YearID',
            'member' => 't.MemberID',
            'payment_mode' => 't.PaymentMode',
            'search' => ['m.MemberName', 'm.Phone'],
            'fields' => [
                'MemberID' => ['Member ID', 't.MemberID', 'integer'],
                'MemberName' => ['Member Name', "MAX(COALESCE(m.MemberName, CONCAT('Member #', t.MemberID)))", 'text'],
                'Phone' => ['Phone', 'MAX(m.Phone)', 'text'],
                'Status' => ['Status', 'MAX(m.Status)', 'text'],
                'TxnCount' => ['Transactions', 'COUNT(*)', 'integer'],
                'TotalCollection' => ['Total Collection', 'SUM(COALESCE(t.CollectionAmount, 0))', 'amount'],
                'TotalDistributed' => ['Total Distributed', 'SUM(COALESCE(t.DistributedAmount, 0))', 'amount'],
                'TotalInterest' => ['Total Interest', 'SUM(COALESCE(t.InterestAmount, 0))', 'amount'],
                'TotalFixed' => ['Total Fixed Amount', 'SUM(COALESCE(t.FixedAmount, 0))', 'amount'],
                'NetBalance' => ['Net Balance', 'SUM(COALESCE(t.DistributedAmount, 0)) + SUM(COALESCE(t.InterestAmount, 0)) - SUM(COALESCE(t.CollectionAmount, 0))', 'amount'],
                'FirstDate' => ['First Transaction', 'MIN(t.TransactionDate)', 'date'],
                'LastDate' => ['Last Transaction', 'MAX(t.TransactionDate)', 'date'],
                'LastDue' => ['Latest Due Date', "MAX(NULLIF(t.NextMonthDue, '0000-00-00'))", 'date'],
            ],
            'default_columns' => ['MemberID', 'MemberName', 'TxnCount', 'TotalCollection', 'TotalDistributed', 'TotalInterest', 'NetBalance'],
            'default_sort' => 'MemberID',
        ],
        'monthly_summary' => [
            'label' => 'Monthly Summary',
            'group' => 'Ready-made',
            'module' => 'Chitti Fund',
            'icon' => 'fa-calendar-alt',
            'description' => 'One row per month with collection, distribution and interest totals.',
            'from' => 'transactions t',
            'group_sql' => "DATE_FORMAT(t.TransactionDate, '%Y-%m')",
            'date' => 't.TransactionDate',
            'year' => 't.YearID',
            'member' => 't.MemberID',
            'payment_mode' => 't.PaymentMode',
            'fields' => [
                'MonthKey' => ['Month (sortable)', "DATE_FORMAT(t.TransactionDate, '%Y-%m')", 'text'],
                'Month' => ['Month', "DATE_FORMAT(MIN(t.TransactionDate), '%M %Y')", 'text'],
                'TxnCount' => ['Transactions', 'COUNT(*)', 'integer'],
                'Members' => ['Members', 'COUNT(DISTINCT t.MemberID)', 'integer'],
                'TotalCollection' => ['Collection', 'SUM(COALESCE(t.CollectionAmount, 0))', 'amount'],
                'TotalDistributed' => ['Distributed', 'SUM(COALESCE(t.DistributedAmount, 0))', 'amount'],
                'TotalInterest' => ['Interest', 'SUM(COALESCE(t.InterestAmount, 0))', 'amount'],
                'TotalFixed' => ['Fixed Amount', 'SUM(COALESCE(t.FixedAmount, 0))', 'amount'],
                'NetBalance' => ['Net Balance', 'SUM(COALESCE(t.DistributedAmount, 0)) + SUM(COALESCE(t.InterestAmount, 0)) - SUM(COALESCE(t.CollectionAmount, 0))', 'amount'],
            ],
            'default_columns' => ['Month', 'TxnCount', 'TotalCollection', 'TotalDistributed', 'TotalInterest'],
            'default_sort' => 'MonthKey',
        ],
        'cash_book' => [
            'label' => 'Cash Book (all money)',
            'group' => 'Ready-made',
            'module' => 'Accounting',
            'icon' => 'fa-book',
            'description' => 'Chitti collections and distributions, expenses and event money in one ledger.',
            'from' => "(
                SELECT t.TransactionDate AS d, 'Chitti collection' AS kind, 'Chitti' AS book,
                       CONCAT('Collection from ', COALESCE(m.MemberName, CONCAT('Member #', t.MemberID))) AS particulars,
                       m.MemberName AS member, COALESCE(t.CollectionAmount, 0) AS inflow, 0 AS outflow,
                       t.PaymentMode AS mode, COALESCE(NULLIF(t.ReceiptNumber, ''), t.ReferenceNumber) AS ref,
                       t.YearID AS yid, t.MemberID AS mid, y.YearName AS yname
                FROM transactions t LEFT JOIN members m ON m.MemberID = t.MemberID LEFT JOIN academic_years y ON y.YearID = t.YearID
                WHERE COALESCE(t.CollectionAmount, 0) <> 0
                UNION ALL
                SELECT t.TransactionDate, 'Chitti distribution', 'Chitti',
                       CONCAT('Distribution to ', COALESCE(m.MemberName, CONCAT('Member #', t.MemberID))),
                       m.MemberName, 0, COALESCE(t.DistributedAmount, 0),
                       t.PaymentMode, COALESCE(NULLIF(t.ReceiptNumber, ''), t.ReferenceNumber),
                       t.YearID, t.MemberID, y.YearName
                FROM transactions t LEFT JOIN members m ON m.MemberID = t.MemberID LEFT JOIN academic_years y ON y.YearID = t.YearID
                WHERE COALESCE(t.DistributedAmount, 0) <> 0
                UNION ALL
                SELECT e.ExpenseDate, 'Expense', 'Expenses',
                       CONCAT(e.Category, ': ', COALESCE(e.Description, '')),
                       NULL, 0, COALESCE(e.Amount, 0),
                       e.PaymentMode, COALESCE(NULLIF(e.ReceiptNumber, ''), e.InvoiceNumber),
                       e.YearID, NULL, y.YearName
                FROM expenses e LEFT JOIN academic_years y ON y.YearID = e.YearID
                WHERE e.Status NOT IN ('Rejected', 'Cancelled')
                UNION ALL
                SELECT et.TransactionDate, IF(et.TransactionType = 'Collection', 'Event collection', 'Event expense'),
                       CONCAT('Event: ', ev.EventName),
                       COALESCE(NULLIF(et.Description, ''), NULLIF(et.ContributorName, ''), NULLIF(et.VendorName, ''), et.TransactionType),
                       NULL, IF(et.TransactionType = 'Collection', COALESCE(et.Amount, 0), 0), IF(et.TransactionType = 'Expense', COALESCE(et.Amount, 0), 0),
                       et.PaymentMode, et.ReceiptNumber,
                       ev.YearID, NULL, y.YearName
                FROM event_transactions et LEFT JOIN events ev ON ev.EventID = et.EventID LEFT JOIN academic_years y ON y.YearID = ev.YearID
                WHERE et.Status <> 'Cancelled'
            ) cb",
            'date' => 'cb.d',
            'year' => 'cb.yid',
            'member' => 'cb.mid',
            'payment_mode' => 'cb.mode',
            'search' => ['cb.particulars', 'cb.member', 'cb.ref', 'cb.book'],
            'nonzero' => ['Inflow', 'Outflow'],
            'fields' => [
                'Date' => ['Date', 'cb.d', 'date'],
                'Month' => ['Month', "DATE_FORMAT(cb.d, '%b %Y')", 'text'],
                'Type' => ['Type', 'cb.kind', 'text'],
                'Book' => ['Book', 'cb.book', 'text'],
                'Particulars' => ['Particulars', 'cb.particulars', 'text'],
                'MemberName' => ['Member Name', 'cb.member', 'text'],
                'Inflow' => ['Money In', 'cb.inflow', 'amount'],
                'Outflow' => ['Money Out', 'cb.outflow', 'amount'],
                'Net' => ['Net (In - Out)', 'cb.inflow - cb.outflow', 'amount'],
                'PaymentMode' => ['Payment Mode', 'cb.mode', 'text'],
                'Reference' => ['Receipt / Ref No', 'cb.ref', 'text'],
                'YearName' => ['Academic Year', 'cb.yname', 'text'],
            ],
            'default_columns' => ['Date', 'Type', 'Particulars', 'Inflow', 'Outflow'],
            'default_sort' => 'Date',
        ],
        'members' => [
            'label' => 'Member Directory',
            'group' => 'Ready-made',
            'module' => 'Members',
            'icon' => 'fa-address-book',
            'description' => 'Member directory with contact details.',
            'from' => 'members m',
            'date' => 'm.JoinedDate',
            'member' => 'm.MemberID',
            'status' => 'm.Status',
            'search' => ['m.MemberName', 'm.Phone', 'm.Email', 'm.FatherName'],
            'fields' => [
                'MemberID' => ['Member ID', 'm.MemberID', 'integer'],
                'MemberName' => ['Member Name', 'm.MemberName', 'text'],
                'FatherName' => ['Father Name', 'm.FatherName', 'text'],
                'Phone' => ['Phone', 'm.Phone', 'text'],
                'Email' => ['Email', 'm.Email', 'text'],
                'Address' => ['Address', 'm.Address', 'text'],
                'Occupation' => ['Occupation', 'm.Occupation', 'text'],
                'Status' => ['Status', 'm.Status', 'text'],
                'JoinedDate' => ['Joined Date', 'm.JoinedDate', 'date'],
                'Notes' => ['Notes', 'm.Notes', 'text'],
            ],
            'default_columns' => ['MemberID', 'MemberName', 'Phone', 'Status'],
            'default_sort' => 'MemberID',
        ],
        'expenses' => [
            'label' => 'Expenses',
            'group' => 'Ready-made',
            'module' => 'Accounting',
            'icon' => 'fa-receipt',
            'description' => 'One row per expense from the accounting module.',
            'from' => 'expenses e LEFT JOIN academic_years y ON y.YearID = e.YearID',
            'date' => 'e.ExpenseDate',
            'year' => 'e.YearID',
            'payment_mode' => 'e.PaymentMode',
            'status' => 'e.Status',
            'category' => 'e.Category',
            'search' => ['e.Description', 'e.VendorName', 'e.InvoiceNumber', 'e.ReceiptNumber', 'e.Remarks', 'e.SubCategory'],
            'nonzero' => ['Amount'],
            'fields' => [
                'ExpenseID' => ['Expense ID', 'e.ExpenseID', 'integer'],
                'ExpenseDate' => ['Date', 'e.ExpenseDate', 'date'],
                'Month' => ['Month', "DATE_FORMAT(e.ExpenseDate, '%b %Y')", 'text'],
                'YearName' => ['Academic Year', 'y.YearName', 'text'],
                'Category' => ['Category', 'e.Category', 'text'],
                'SubCategory' => ['Sub-Category', 'e.SubCategory', 'text'],
                'Description' => ['Description', 'e.Description', 'text'],
                'Amount' => ['Amount', 'COALESCE(e.Amount, 0)', 'amount'],
                'PaymentMode' => ['Payment Mode', 'e.PaymentMode', 'text'],
                'VendorName' => ['Vendor', 'e.VendorName', 'text'],
                'InvoiceNumber' => ['Invoice No', 'e.InvoiceNumber', 'text'],
                'ReceiptNumber' => ['Receipt No', 'e.ReceiptNumber', 'text'],
                'Status' => ['Status', 'e.Status', 'text'],
                'Remarks' => ['Remarks', 'e.Remarks', 'text'],
            ],
            'default_columns' => ['ExpenseDate', 'Category', 'Description', 'Amount', 'PaymentMode', 'Status'],
            'default_sort' => 'ExpenseDate',
        ],
        'manual' => [
            'label' => 'Manual Table',
            'group' => 'Ready-made',
            'module' => 'Your own data',
            'icon' => 'fa-table',
            'description' => 'Type or paste your own rows and columns (e.g. from Excel).',
            'fields' => [],
            'default_columns' => [],
        ],
    ];
}

/** Order in which modules are listed in the builder */
const RB_MODULE_ORDER = ['Chitti Fund', 'Members', 'Accounting', 'Events', 'Administration', 'System', 'Other tables', 'Your own data'];

/**
 * Friendly names for the application's tables: [module, label, icon, description]
 * Tables not listed here still appear under "Other tables".
 */
function rbModuleCatalog() {
    return [
        'transactions' => ['Chitti Fund', 'Transactions (all columns)', 'fa-exchange-alt', 'Every column of every chitti transaction, including time, verification and approval.'],
        'academic_years' => ['Chitti Fund', 'Academic Years', 'fa-calendar', 'Each chitti year: dates, interest rate, fixed amount, totals and balance.'],
        'change_amount_transactions' => ['Chitti Fund', 'Change Amount Transactions', 'fa-coins', 'Every change amount entry: date, year, member (empty = general), amount (+ add / - deduct) and reason.'],
        'member_net_balance' => ['Chitti Fund', 'Member Net Balance', 'fa-scale-balanced', 'Stored net balance of each member for each year.'],
        'balance_checkpoints' => ['Chitti Fund', 'Balance Checkpoints', 'fa-flag-checkered', 'Saved balance snapshots per member and year.'],
        'year_members' => ['Chitti Fund', 'Year Members', 'fa-user-check', 'Which members take part in which year.'],
        'payment_reminders' => ['Chitti Fund', 'Payment Reminders', 'fa-bell', 'Due amounts and reminder status per member.'],
        'monthly_summaries' => ['Chitti Fund', 'Monthly Summaries (stored)', 'fa-calendar-alt', 'Stored month-wise totals per member.'],
        'chitticycles' => ['Chitti Fund', 'Chitti Cycles', 'fa-rotate', 'Chitti cycle definitions.'],
        'monthlystatus' => ['Chitti Fund', 'Monthly Status', 'fa-list-check', 'Month-wise received, distributed and interest per member.'],
        'summary' => ['Chitti Fund', 'Member Totals (stored)', 'fa-calculator', 'Stored total received, distributed and interest per member.'],
        'years' => ['Chitti Fund', 'Years (old)', 'fa-calendar-minus', 'Older year list.'],
        'members' => ['Members', 'Members (all columns)', 'fa-id-card', 'Every member detail: date of birth, Aadhar, address, status, last login.'],
        'member_documents' => ['Members', 'Member Documents', 'fa-file-lines', 'Documents uploaded for members.'],
        'member_permissions' => ['Members', 'Member Permissions', 'fa-user-lock', 'What each member is allowed to see and do.'],
        'custom_field_definitions' => ['Members', 'Custom Fields', 'fa-sliders', 'Extra fields defined for members and transactions.'],
        'custom_field_values' => ['Members', 'Custom Field Values', 'fa-pen-to-square', 'Values entered in the custom fields.'],
        'expenses' => ['Accounting', 'Expenses (all columns)', 'fa-file-invoice', 'Every expense column, including bill, approval and audit dates.'],
        'expense_categories' => ['Accounting', 'Expense Categories', 'fa-tags', 'The categories expenses are filed under.'],
        'budget_limits' => ['Accounting', 'Budget Limits', 'fa-bullseye', 'Monthly and yearly budget limits per category.'],
        'proof_attachments' => ['Accounting', 'Proof Attachments', 'fa-paperclip', 'Bills and proofs uploaded for expenses and events.'],
        'bills' => ['Accounting', 'Generated Bills', 'fa-file-invoice-dollar', 'Every bill, receipt, statement and voucher made with the Bill Generator: number, party, total and status.'],
        'events' => ['Events', 'Events', 'fa-calendar-day', 'Each event: dates, venue, budget, total collection, expense and balance.'],
        'event_transactions' => ['Events', 'Event Transactions', 'fa-money-bill-transfer', 'Every collection and expense of every event.'],
        'admins' => ['Administration', 'Admins', 'fa-user-shield', 'Admin accounts (no passwords).'],
        'admin_permissions' => ['Administration', 'Admin Permissions', 'fa-key', 'What each admin is allowed to do.'],
        'permission_templates' => ['Administration', 'Permission Templates', 'fa-clipboard-list', 'Saved permission sets.'],
        'audit_logs' => ['Administration', 'Audit Logs', 'fa-clipboard-check', 'Who did what and when, across the whole application.'],
        'activity_logs' => ['Administration', 'Activity Logs', 'fa-wave-square', 'Activity history.'],
        'user_activity' => ['Administration', 'User Activity', 'fa-person-walking', 'Sessions and activity per user.'],
        'notifications' => ['System', 'Notifications', 'fa-bell', 'Notifications sent to members and admins.'],
        'system_notifications' => ['System', 'System Notifications', 'fa-tower-broadcast', 'System-wide announcements.'],
        'notification_preferences' => ['System', 'Notification Preferences', 'fa-bell-slash', 'How each user wants to be notified.'],
        'messages' => ['System', 'Messages', 'fa-envelope', 'Messages between users.'],
        'database_backups' => ['System', 'Database Backups', 'fa-database', 'Backups that have been taken.'],
        'backup_history' => ['System', 'Backup History', 'fa-clock-rotate-left', 'History of backup runs.'],
        'link_target_settings' => ['System', 'Link Settings', 'fa-link', 'How each page link opens.'],
        'reports_history' => ['System', 'Reports History', 'fa-chart-column', 'Reports generated in the past.'],
        'report_templates' => ['System', 'Report Templates', 'fa-file-alt', 'Report templates from the Reports page.'],
    ];
}

/** "EventTransactions" / "event_transactions" -> "Event Transactions" */
function rbHumanize($name) {
    $s = str_replace('_', ' ', (string)$name);
    $s = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $s);
    $s = preg_replace('/\bID\b/i', 'ID', ucwords(trim(preg_replace('/\s+/', ' ', $s))));
    return $s;
}

function rbColumnType($column, $dataType) {
    $t = strtolower($dataType);
    if (in_array($t, ['date'], true)) return 'date';
    if (in_array($t, ['datetime', 'timestamp'], true)) return 'datetime';
    if ($t === 'tinyint') return 'flag';
    if (in_array($t, ['int', 'bigint', 'smallint', 'mediumint', 'year'], true)) return 'integer';
    if (in_array($t, ['decimal', 'float', 'double'], true)) {
        return preg_match('/amount|balance|collection|expense|limit|budget|interest|principal|total|due|paid|price|cost|fee|received|distributed|collected/i', $column) ? 'amount' : 'number';
    }
    if (in_array($t, ['blob', 'longblob', 'mediumblob', 'binary', 'varbinary', 'geometry', 'json'], true)) return null;
    return 'text';
}

/**
 * Every application table as a data source, with lookups of related names
 * (e.g. MemberID -> Member Name, EventID -> Event Name).
 */
function rbTableSources() {
    global $pdo;
    if (!($pdo instanceof PDO)) return [];
    try {
        $cols = $pdo->query("
            SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_KEY
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            ORDER BY TABLE_NAME, ORDINAL_POSITION
        ")->fetchAll(PDO::FETCH_ASSOC);
        $tables = $pdo->query("
            SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        ")->fetchAll(PDO::FETCH_KEY_PAIR);
        $fks = $pdo->query("
            SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Report builder: information_schema unavailable, using SHOW TABLES: ' . $e->getMessage());
        $cols = [];
        $tables = [];
        $fks = [];
    }

    // Some shared hosts hide information_schema; fall back to SHOW TABLES / SHOW COLUMNS
    if (!$cols) {
        try {
            foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$table, $type]) {
                if ($type !== 'BASE TABLE' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) continue;
                $tables[$table] = 0;
                foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    $cols[] = [
                        'TABLE_NAME' => $table,
                        'COLUMN_NAME' => $col['Field'],
                        'DATA_TYPE' => strtolower(preg_replace('/[\s(].*$/', '', $col['Type'])),
                        'COLUMN_KEY' => $col['Key'],
                    ];
                }
            }
        } catch (Exception $e) {
            error_log('Report builder table discovery failed: ' . $e->getMessage());
            return [];
        }
    }

    // Exact row counts (TABLE_ROWS is only an estimate for InnoDB)
    foreach (array_keys($tables) as $table) {
        if (in_array($table, RB_EXCLUDED_TABLES, true) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) continue;
        try {
            $tables[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        } catch (Exception $e) {
            unset($tables[$table]); // no permission to read it: leave it out
        }
    }
    $catalog = rbModuleCatalog();

    $byTable = [];
    foreach ($cols as $c) {
        if (!isset($tables[$c['TABLE_NAME']]) || in_array($c['TABLE_NAME'], RB_EXCLUDED_TABLES, true)) continue;
        if (preg_match(RB_EXCLUDED_COLUMN_PATTERN, $c['COLUMN_NAME'])) continue;
        $byTable[$c['TABLE_NAME']][$c['COLUMN_NAME']] = $c;
    }

    // Which column of a table names a row (for lookups)
    $displayPrefs = ['MemberName', 'EventName', 'YearName', 'FullName', 'Username', 'CycleName', 'CategoryName', 'FieldLabel', 'TemplateName', 'Name', 'Title'];
    $displayCol = function ($table) use ($byTable, $displayPrefs) {
        foreach ($displayPrefs as $p) {
            if (isset($byTable[$table][$p])) return $p;
        }
        return null;
    };
    $pkOf = function ($table) use ($byTable) {
        foreach ($byTable[$table] ?? [] as $name => $c) {
            if ($c['COLUMN_KEY'] === 'PRI') return $name;
        }
        return null;
    };

    // Relationships: declared foreign keys plus the naming conventions used across the app
    $relations = [];
    foreach ($fks as $fk) {
        $relations[$fk['TABLE_NAME']][$fk['COLUMN_NAME']] = [$fk['REFERENCED_TABLE_NAME'], $fk['REFERENCED_COLUMN_NAME']];
    }
    $conventions = ['MemberID' => 'members', 'YearID' => 'academic_years', 'EventID' => 'events', 'CycleID' => 'chitticycles', 'FieldID' => 'custom_field_definitions', 'AdminID' => 'admins'];

    $sources = [];
    foreach ($byTable as $table => $columns) {
        $fields = [];
        $from = '`' . $table . '` b';
        $joins = 0;
        $dateFields = [];
        $search = [];
        $pk = $pkOf($table);
        $year = isset($columns['YearID']) ? 'b.`YearID`' : null;
        $member = isset($columns['MemberID']) ? 'b.`MemberID`' : null;

        foreach ($columns as $name => $c) {
            $type = rbColumnType($name, $c['DATA_TYPE']);
            if ($type === null) continue;
            $sql = 'b.`' . $name . '`';
            if ($type === 'flag') {
                $fields[$name] = [rbHumanize($name), "CASE WHEN $sql IS NULL THEN NULL WHEN $sql <> 0 THEN 'Yes' ELSE 'No' END", 'text'];
            } else {
                $fields[$name] = [rbHumanize($name), $sql, $type];
            }
            if ($type === 'date' || $type === 'datetime') $dateFields[$name] = $sql;
            if ($type === 'text' && in_array(strtolower($c['DATA_TYPE']), ['varchar', 'char', 'enum', 'text', 'mediumtext'], true) && count($search) < 8) {
                $search[] = $sql;
            }

            // Lookup of the related row's name
            $rel = $relations[$table][$name] ?? (isset($conventions[$name]) ? [$conventions[$name], $name] : null);
            if ($rel && $rel[0] !== $table && isset($byTable[$rel[0]][$rel[1]]) && ($disp = $displayCol($rel[0]))) {
                $alias = 'j' . (++$joins);
                $from .= ' LEFT JOIN `' . $rel[0] . '` ' . $alias . ' ON ' . $alias . '.`' . $rel[1] . '` = b.`' . $name . '`';
                $label = preg_match('/ID$/', $name) ? ($disp === 'YearName' ? 'Academic Year' : rbHumanize($disp)) : rbHumanize($name) . ' (Name)';
                $fields[$name . '_name'] = [$label, $alias . '.`' . $disp . '`', 'text'];
                if (count($search) < 8) $search[] = $alias . '.`' . $disp . '`';
            }
        }
        if (!$fields) continue;

        // Sensible default columns: names instead of IDs, skip housekeeping columns
        $defaults = [];
        foreach ($fields as $key => $f) {
            if (count($defaults) >= 7) break;
            if (preg_match('/^(CreatedAt|UpdatedAt|CreatedBy|UpdatedBy|ApprovedBy|ApprovedAt|VerifiedBy|VerifiedAt|LastUpdatedAt|UserAgent|IPAddress)$/', $key)) continue;
            if (isset($fields[$key . '_name'])) continue; // the name lookup is shown instead
            if ($key === $pk && count($fields) > 4) continue;
            $defaults[] = $key;
        }
        $dateKeys = array_keys($dateFields);
        $mainDate = null;
        foreach ($dateKeys as $k) {
            if (!preg_match('/^(CreatedAt|UpdatedAt|LastUpdatedAt|ApprovedAt|VerifiedAt|ReadAt|ExpiresAt|DeletedAt)$/', $k)) { $mainDate = $k; break; }
        }
        $mainDate = $mainDate ?? ($dateKeys[0] ?? null);

        $info = $catalog[$table] ?? ['Other tables', rbHumanize($table), 'fa-table', 'All rows of the ' . $table . ' table.'];
        $sources['table:' . $table] = [
            'label' => $info[1],
            'group' => 'Application tables',
            'module' => $info[0],
            'icon' => $info[2],
            'description' => $info[3],
            'rows' => (int)$tables[$table],
            'from' => $from,
            'date' => $mainDate ? $dateFields[$mainDate] : null,
            'date_fields' => $dateFields,
            'default_date_field' => $mainDate,
            'year' => $year,
            'member' => $member,
            'search' => $search,
            'fields' => $fields,
            'default_columns' => $defaults,
            'default_sort' => $mainDate ?? $pk ?? array_key_first($fields),
        ];
    }
    ksort($sources);
    return $sources;
}

/**
 * Ready-made report designs offered as starting points
 */
function rbPresets() {
    $col = function ($key, $label = null, array $extra = []) {
        return array_merge(['key' => $key, 'label' => $label], $extra);
    };
    $calc = function ($n, $label, $formula, array $extra = []) {
        return array_merge(['key' => 'calc_' . $n, 'label' => $label, 'formula' => $formula, 'format' => 'amount', 'total' => 'sum'], $extra);
    };
    $presets = [
        'distribution_statement' => [
            'name' => 'Distribution & Interest Statement',
            'config' => [
                'title' => 'Distribution & Interest Statement',
                'subtitle' => 'Member-wise distribution & interest',
                'source' => 'transactions',
                'filters' => ['nonzero' => ['DistributedAmount', 'InterestAmount']],
                'columns' => [
                    $col('MemberID', null, ['width' => 1, 'align' => 'C']),
                    $col('TransactionDate', null, ['width' => 1.3, 'align' => 'C']),
                    $col('MemberName', null, ['width' => 2.6]),
                    $col('DistributedAmount', 'Distributed Amount (Rs.)', ['width' => 1.7, 'total' => 'sum']),
                    $col('InterestAmount', 'Interest Amount (Rs.)', ['width' => 1.6, 'total' => 'sum']),
                    $col('NextMonthDue', null, ['width' => 1.3, 'align' => 'C']),
                    $col('Remarks', null, ['width' => 2]),
                ],
                'sort' => [['key' => 'TransactionDate', 'dir' => 'asc'], ['key' => 'MemberID', 'dir' => 'asc']],
            ],
        ],
        'cash_book' => [
            'name' => 'Cash Book with Running Balance',
            'config' => [
                'title' => 'Cash Book',
                'subtitle' => 'All money in and out with running balance',
                'source' => 'cash_book',
                'columns' => [
                    $col('Date', null, ['width' => 1.1]),
                    $col('Type', null, ['width' => 1.3]),
                    $col('Particulars', null, ['width' => 3]),
                    $col('Inflow', 'Money In (Rs.)', ['total' => 'sum']),
                    $col('Outflow', 'Money Out (Rs.)', ['total' => 'sum']),
                    $calc(1, 'Balance (Rs.)', 'RUNNING({Inflow}) - RUNNING({Outflow})', ['total' => 'none', 'width' => 1.2]),
                ],
                'sort' => [['key' => 'Date', 'dir' => 'asc']],
                'calculations' => [
                    ['label' => 'Total money in', 'formula' => 'SUM({Inflow})', 'format' => 'amount'],
                    ['label' => 'Total money out', 'formula' => 'SUM({Outflow})', 'format' => 'amount'],
                    ['label' => 'Closing balance', 'formula' => 'SUM({Inflow}) - SUM({Outflow})', 'format' => 'amount'],
                    ['label' => 'Money out as % of money in', 'formula' => 'PCT(SUM({Outflow}), SUM({Inflow}))', 'format' => 'percent'],
                ],
                'layout' => ['orientation' => 'P'],
            ],
        ],
        'event_statement' => [
            'name' => 'Event Statement',
            'config' => [
                'title' => 'Event Statement',
                'subtitle' => 'Collections and expenses for each event',
                'source' => 'table:event_transactions',
                'columns' => [
                    $col('TransactionDate', 'Date', ['width' => 1.1]),
                    $col('Description', 'Particulars', ['width' => 2.8]),
                    $calc(1, 'Collection (Rs.)', 'IF({TransactionType} = "Collection", {Amount}, 0)'),
                    $calc(2, 'Expense (Rs.)', 'IF({TransactionType} = "Expense", {Amount}, 0)'),
                    $calc(3, 'Balance (Rs.)', 'GRUNNING({calc_1} - {calc_2})', ['total' => 'none']),
                ],
                'sort' => [['key' => 'TransactionDate', 'dir' => 'asc']],
                'group_by' => 'EventID_name',
                'calculations' => [
                    ['label' => 'Total collections', 'formula' => 'SUMIF({Amount}, {TransactionType} = "Collection")', 'format' => 'amount'],
                    ['label' => 'Total expenses', 'formula' => 'SUMIF({Amount}, {TransactionType} = "Expense")', 'format' => 'amount'],
                    ['label' => 'Net balance', 'formula' => 'SUMIF({Amount}, {TransactionType} = "Collection") - SUMIF({Amount}, {TransactionType} = "Expense")', 'format' => 'amount'],
                    ['label' => 'Number of contributions', 'formula' => 'COUNTIF({TransactionType} = "Collection")', 'format' => 'integer'],
                ],
            ],
        ],
        'event_summary' => [
            'name' => 'Event Summary',
            'config' => [
                'title' => 'Event Summary',
                'subtitle' => 'Budget, collection and spending per event',
                'source' => 'table:events',
                'columns' => [
                    $col('EventName', null, ['width' => 2.4]),
                    $col('EventDate', 'Date'),
                    $col('Status', null, ['width' => 0.9]),
                    $col('TotalCollection', 'Collected (Rs.)', ['total' => 'sum']),
                    $col('TotalExpense', 'Spent (Rs.)', ['total' => 'sum']),
                    $col('EventBalance', 'Balance (Rs.)', ['total' => 'sum']),
                    $calc(1, 'Spent %', 'PCT({TotalExpense}, {TotalCollection})', ['format' => 'percent', 'total' => 'recalc', 'width' => 0.8]),
                ],
                'sort' => [['key' => 'EventDate', 'dir' => 'asc']],
                'calculations' => [
                    ['label' => 'Events in surplus', 'formula' => 'COUNTIF({EventBalance} > 0)', 'format' => 'integer'],
                    ['label' => 'Events in deficit', 'formula' => 'COUNTIF({EventBalance} < 0)', 'format' => 'integer'],
                    ['label' => 'Average collection per event', 'formula' => 'AVG({TotalCollection})', 'format' => 'amount'],
                ],
            ],
        ],
        'interest_analysis' => [
            'name' => 'Member Interest Analysis',
            'config' => [
                'title' => 'Member Interest Analysis',
                'subtitle' => 'Interest earned per member with ranking',
                'source' => 'member_summary',
                'columns' => [
                    $calc(2, 'Rank', 'RANK({TotalInterest})', ['format' => 'integer', 'total' => 'none', 'align' => 'C', 'width' => 0.6]),
                    $col('MemberName', null, ['width' => 2.4]),
                    $col('TotalDistributed', null, ['total' => 'sum']),
                    $col('TotalInterest', null, ['total' => 'sum']),
                    $calc(1, 'Interest % of Distributed', 'PCT({TotalInterest}, {TotalDistributed})', ['format' => 'percent', 'total' => 'recalc']),
                    $calc(3, 'Share of Interest %', 'PCTTOTAL({TotalInterest})', ['format' => 'percent', 'total' => 'sum']),
                ],
                'sort' => [['key' => 'TotalInterest', 'dir' => 'desc']],
                'calculations' => [
                    ['label' => 'Average interest per member', 'formula' => 'AVG({TotalInterest})', 'format' => 'amount'],
                    ['label' => 'Highest interest', 'formula' => 'MAX({TotalInterest})', 'format' => 'amount'],
                    ['label' => 'Members above average', 'formula' => 'COUNTIF({TotalInterest} > AVG({TotalInterest}))', 'format' => 'integer'],
                ],
            ],
        ],
        'collection_register' => [
            'name' => 'Collection Register',
            'config' => [
                'title' => 'Collection Register',
                'subtitle' => 'Collections received from members',
                'source' => 'transactions',
                'filters' => ['nonzero' => ['CollectionAmount']],
                'columns' => [
                    $col('TransactionDate', null, ['align' => 'C']),
                    $col('ReceiptNumber'),
                    $col('MemberID', null, ['align' => 'C']),
                    $col('MemberName', null, ['width' => 2.4]),
                    $col('PaymentMode'),
                    $col('CollectionAmount', 'Amount (Rs.)', ['total' => 'sum']),
                ],
                'sort' => [['key' => 'TransactionDate', 'dir' => 'asc']],
                'group_by' => 'Month',
            ],
        ],
        'member_summary' => [
            'name' => 'Member Balance Summary',
            'config' => [
                'title' => 'Member Balance Summary',
                'subtitle' => 'Totals per member',
                'source' => 'member_summary',
                'columns' => [
                    $col('MemberID', null, ['align' => 'C', 'width' => 0.8]),
                    $col('MemberName', null, ['width' => 2.4]),
                    $col('TxnCount', null, ['align' => 'C', 'width' => 0.9, 'total' => 'sum']),
                    $col('TotalCollection', null, ['total' => 'sum']),
                    $col('TotalDistributed', null, ['total' => 'sum']),
                    $col('TotalInterest', null, ['total' => 'sum']),
                    $col('NetBalance', null, ['total' => 'sum']),
                ],
                'sort' => [['key' => 'MemberID', 'dir' => 'asc']],
            ],
        ],
        'monthly_summary' => [
            'name' => 'Monthly Summary',
            'config' => [
                'title' => 'Monthly Financial Summary',
                'subtitle' => 'Month-wise collection, distribution and interest',
                'source' => 'monthly_summary',
                'columns' => [
                    $col('Month', null, ['width' => 1.6]),
                    $col('Members', null, ['align' => 'C']),
                    $col('TxnCount', null, ['align' => 'C', 'total' => 'sum']),
                    $col('TotalCollection', null, ['total' => 'sum']),
                    $col('TotalDistributed', null, ['total' => 'sum']),
                    $col('TotalInterest', null, ['total' => 'sum']),
                    $calc(1, 'Distributed + Interest', '{TotalDistributed} + {TotalInterest}'),
                ],
                'sort' => [['key' => 'MonthKey', 'dir' => 'asc']],
            ],
        ],
        'expense_register' => [
            'name' => 'Expense Register',
            'config' => [
                'title' => 'Expense Register',
                'subtitle' => 'Expenses by category',
                'source' => 'expenses',
                'columns' => [
                    $col('ExpenseDate', null, ['align' => 'C']),
                    $col('Description', null, ['width' => 2.6]),
                    $col('VendorName'),
                    $col('PaymentMode'),
                    $col('Amount', 'Amount (Rs.)', ['total' => 'sum']),
                ],
                'sort' => [['key' => 'ExpenseDate', 'dir' => 'asc']],
                'group_by' => 'Category',
            ],
        ],
        'member_directory' => [
            'name' => 'Member Directory',
            'config' => [
                'title' => 'Member Directory',
                'subtitle' => 'Contact details of members',
                'source' => 'members',
                'columns' => [
                    $col('MemberID', null, ['align' => 'C', 'width' => 0.8]),
                    $col('MemberName', null, ['width' => 2.2]),
                    $col('Phone', null, ['width' => 1.4]),
                    $col('Address', null, ['width' => 2.6]),
                    $col('Status', null, ['align' => 'C']),
                ],
                'sort' => [['key' => 'MemberID', 'dir' => 'asc']],
                'layout' => ['summary_tiles' => false],
            ],
        ],
        'blank_manual' => [
            'name' => 'Blank Manual Table',
            'config' => [
                'title' => 'Custom Statement',
                'subtitle' => '',
                'source' => 'manual',
                'columns' => [
                    ['key' => 'col1', 'label' => 'S.No', 'format' => 'integer', 'align' => 'C', 'width' => 0.6],
                    ['key' => 'col2', 'label' => 'Particulars', 'format' => 'text', 'width' => 3],
                    ['key' => 'col3', 'label' => 'Amount (Rs.)', 'format' => 'amount', 'total' => 'sum'],
                ],
                'manual_rows' => [['1', '', ''], ['2', '', '']],
                'layout' => ['row_numbers' => false],
            ],
        ],
    ];
    // Hide presets whose table isn't available on this installation
    $sources = rbSources();
    return array_filter($presets, function ($p) use ($sources) { return isset($sources[$p['config']['source']]); });
}

/**
 * The condition builder's own settings (kept so the builder can be reopened).
 * Only plain arrays / short scalars survive; the server never interprets them.
 */
function rbCleanBuilder($b, $depth = 0) {
    if (!is_array($b) || $depth > 5) return null;
    $out = [];
    foreach (array_slice($b, 0, 40, true) as $k => $v) {
        if (!is_int($k) && !preg_match('/^[a-z_]{1,20}$/i', (string)$k)) continue;
        if (is_array($v)) {
            $clean = rbCleanBuilder($v, $depth + 1);
            if ($clean !== null) $out[$k] = $clean;
        } elseif (is_scalar($v) || $v === null) {
            $out[$k] = is_string($v) ? mb_substr($v, 0, 200) : $v;
        }
    }
    return $depth === 0 && strlen(json_encode($out)) > 8000 ? null : $out;
}

/**
 * Fill defaults, clamp values and drop anything that isn't allowed
 */
/**
 * Normalize ONE table section (source + columns + filters + sort + group + maths).
 * The document-global keys (title, subtitle, layout, letterhead_id) are handled by rbNormalizeConfig.
 */
function rbNormalizeSection($raw) {
    $sources = rbSources();
    $raw = is_array($raw) ? $raw : [];
    $source = isset($raw['source'], $sources[$raw['source']]) ? $raw['source'] : 'transactions';
    $def = $sources[$source];
    $fields = $def['fields'];

    $str = function ($v, $max = 200) {
        return mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max);
    };
    $date = function ($v) {
        $v = trim(is_scalar($v) ? (string)$v : '');
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        return ($d && $d->format('Y-m-d') === $v) ? $v : '';
    };
    $fmt = function ($v, $fallback) {
        return in_array($v, RB_FORMATS, true) ? $v : $fallback;
    };

    // Columns
    $columns = [];
    $seen = [];
    $isManual = $source === 'manual';
    $rawColumns = is_array($raw['columns'] ?? null) ? $raw['columns'] : [];
    if (!$rawColumns && !$isManual) {
        $rawColumns = array_map(function ($k) { return ['key' => $k]; }, $def['default_columns']);
    }
    foreach (array_slice($rawColumns, 0, 40) as $c) {
        if (!is_array($c)) continue;
        $key = (string)($c['key'] ?? '');
        $formula = $str($c['formula'] ?? '', 500);
        $isCalc = strpos($key, 'calc_') === 0 && preg_match('/^calc_\d+$/', $key);
        if ($isManual) {
            $key = 'col' . (count($columns) + 1);
        } elseif ($isCalc) {
            if ($formula === '') continue;
        } elseif (!isset($fields[$key])) {
            continue;
        }
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $type = ($isManual || $isCalc) ? ($c['format'] ?? ($isCalc ? 'amount' : 'text')) : $fields[$key][2];
        $format = $fmt($c['format'] ?? '', $fmt($type, 'text'));
        $numeric = in_array($format, RB_NUMERIC_FORMATS, true);
        $align = in_array($c['align'] ?? '', ['L', 'C', 'R'], true) ? $c['align'] : ($numeric ? 'R' : (in_array($format, ['date', 'datetime'], true) ? 'C' : 'L'));
        $label = $str($c['label'] ?? '', 80);
        if ($label === '') {
            $label = $isCalc ? 'Calculated' : ($isManual ? 'Column ' . (count($columns) + 1) : $fields[$key][0]);
        }
        $total = in_array($c['total'] ?? '', RB_TOTALS, true) ? $c['total'] : 'none';
        $hasFormula = $isCalc || ($isManual && $formula !== '');
        if ($total === 'recalc' && !$hasFormula) $total = 'sum';
        $columns[] = [
            'key' => $key,
            'label' => $label,
            'format' => $format,
            'align' => $align,
            'width' => max(0.4, min(6, (float)($c['width'] ?? 1))),
            'total' => $total,
            'formula' => ($isCalc || $isManual) ? $formula : '',
            'hidden' => !empty($c['hidden']),
            'builder' => $hasFormula ? rbCleanBuilder($c['builder'] ?? null) : null,
        ];
    }

    // Manual rows
    $manualRows = [];
    if ($isManual) {
        foreach (array_slice(is_array($raw['manual_rows'] ?? null) ? $raw['manual_rows'] : [], 0, 2000) as $r) {
            if (!is_array($r)) continue;
            $row = [];
            for ($i = 0; $i < count($columns); $i++) {
                $row[] = mb_substr(is_scalar($r[$i] ?? '') ? (string)($r[$i] ?? '') : '', 0, 500);
            }
            $manualRows[] = $row;
        }
    }

    // Anything that can be sorted, grouped or filtered on: source fields plus column keys
    $keys = array_values(array_unique(array_merge(array_keys($fields), array_column($columns, 'key'))));

    $f = is_array($raw['filters'] ?? null) ? $raw['filters'] : [];
    $dateFields = $def['date_fields'] ?? [];
    $conditions = [];
    foreach (array_slice(is_array($f['conditions'] ?? null) ? $f['conditions'] : [], 0, 12) as $c) {
        if (!is_array($c) || !in_array($c['field'] ?? '', $keys, true) || !in_array($c['op'] ?? '', RB_CONDITION_OPS, true)) continue;
        $conditions[] = ['field' => $c['field'], 'op' => $c['op'], 'value' => $str($c['value'] ?? '', 200), 'value2' => $str($c['value2'] ?? '', 200)];
    }
    $filters = [
        'date_from' => $date($f['date_from'] ?? ''),
        'date_to' => $date($f['date_to'] ?? ''),
        'date_field' => isset($dateFields[$f['date_field'] ?? '']) ? $f['date_field'] : ($def['default_date_field'] ?? ''),
        'year_id' => max(0, (int)($f['year_id'] ?? 0)),
        'member_ids' => array_values(array_unique(array_filter(array_map('intval', is_array($f['member_ids'] ?? null) ? $f['member_ids'] : []), function ($v) { return $v > 0; }))),
        'payment_mode' => $str($f['payment_mode'] ?? '', 40),
        'status' => $str($f['status'] ?? '', 40),
        'category' => $str($f['category'] ?? '', 80),
        'search' => $str($f['search'] ?? '', 80),
        'nonzero' => array_values(array_intersect(is_array($f['nonzero'] ?? null) ? $f['nonzero'] : [], $def['nonzero'] ?? [])),
        'conditions' => $conditions,
        'match' => ($f['match'] ?? '') === 'any' ? 'any' : 'all',
    ];
    if ($filters['date_from'] && $filters['date_to'] && $filters['date_from'] > $filters['date_to']) {
        [$filters['date_from'], $filters['date_to']] = [$filters['date_to'], $filters['date_from']];
    }

    $sort = [];
    foreach (array_slice(is_array($raw['sort'] ?? null) ? $raw['sort'] : [], 0, 3) as $s) {
        if (is_array($s) && in_array($s['key'] ?? '', $keys, true)) {
            $sort[] = ['key' => $s['key'], 'dir' => ($s['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc'];
        }
    }
    if (!$sort && !empty($def['default_sort']) && in_array($def['default_sort'], $keys, true)) {
        $sort[] = ['key' => $def['default_sort'], 'dir' => 'asc'];
    }
    $groupBy = in_array($raw['group_by'] ?? '', $keys, true) ? $raw['group_by'] : '';

    $calculations = [];
    foreach (array_slice(is_array($raw['calculations'] ?? null) ? $raw['calculations'] : [], 0, 15) as $c) {
        if (!is_array($c)) continue;
        $formula = $str($c['formula'] ?? '', 500);
        $label = $str($c['label'] ?? '', 80);
        if ($formula === '' && $label === '') continue;
        $calculations[] = ['label' => $label ?: 'Result', 'formula' => $formula, 'format' => $fmt($c['format'] ?? '', 'amount'), 'builder' => rbCleanBuilder($c['builder'] ?? null)];
    }

    return [
        'heading' => $str($raw['heading'] ?? '', 120),
        'source' => $source,
        'filters' => $filters,
        'columns' => $columns,
        'manual_rows' => $manualRows,
        'sort' => $sort,
        'group_by' => $groupBy,
        'calculations' => $calculations,
    ];
}

/** Document-global layout options (shared by every section of the report). */
function rbNormalizeLayout($l) {
    $l = is_array($l) ? $l : [];
    $str = function ($v, $max = 200) { return mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max); };
    return [
        'orientation' => ($l['orientation'] ?? '') === 'L' ? 'L' : 'P',
        'font_size' => max(6.5, min(11, (float)($l['font_size'] ?? 8.5))),
        'date_format' => in_array($l['date_format'] ?? '', ['d-m-Y', 'd M Y', 'Y-m-d', 'd/m/Y'], true) ? $l['date_format'] : 'd-m-Y',
        'zero_dash' => !isset($l['zero_dash']) || !empty($l['zero_dash']),
        'zebra' => !isset($l['zebra']) || !empty($l['zebra']),
        'row_numbers' => !empty($l['row_numbers']),
        'summary_tiles' => !isset($l['summary_tiles']) || !empty($l['summary_tiles']),
        'group_subtotals' => !isset($l['group_subtotals']) || !empty($l['group_subtotals']),
        'summary_only' => !empty($l['summary_only']),
        'signatures' => !isset($l['signatures']) || !empty($l['signatures']),
        'sig_left' => $str($l['sig_left'] ?? 'Prepared By', 40) ?: 'Prepared By',
        'sig_right' => $str($l['sig_right'] ?? 'Authorised Signatory', 40) ?: 'Authorised Signatory',
        'notes' => $str($l['notes'] ?? '', 1500),
        'footer_note' => $str($l['footer_note'] ?? 'Computer-generated report', 80) ?: 'Computer-generated report',
    ];
}

/**
 * Normalize a whole report: document-global keys plus a `sections` array (one or more table sections).
 * Backward compatible: a config with no `sections` becomes a single section built from the legacy
 * top-level keys, and section[0]'s keys are also mirrored at the top level so old readers still work.
 */
function rbNormalizeConfig($raw) {
    $raw = is_array($raw) ? $raw : [];
    $str = function ($v, $max = 200) { return mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max); };
    $rawSections = (isset($raw['sections']) && is_array($raw['sections']) && $raw['sections']) ? $raw['sections'] : [$raw];
    $sections = [];
    foreach (array_slice($rawSections, 0, 12) as $rs) {
        $sections[] = rbNormalizeSection(is_array($rs) ? $rs : []);
    }
    if (!$sections) {
        $sections = [rbNormalizeSection([])];
    }
    // Back-compat: expose section[0]'s per-section keys at the top level too
    return array_merge($sections[0], [
        'title' => $str($raw['title'] ?? '', 120) ?: 'Custom Report',
        'subtitle' => $str($raw['subtitle'] ?? '', 120),
        'layout' => rbNormalizeLayout($raw['layout'] ?? []),
        'letterhead_id' => max(0, (int)($raw['letterhead_id'] ?? 0)), // 0 = the default letterhead
        'sections' => $sections,
    ]);
}

/**
 * Which data source a {prefix.Field} reference means. A ready-made source with that key wins
 * when it has the field; otherwise the application table of that name.
 */
function rbResolveModule($prefix, $field = null) {
    $sources = rbSources();
    $candidates = [$prefix, 'table:' . $prefix];
    foreach ($candidates as $key) {
        if ($key === 'manual' || !isset($sources[$key]) || empty($sources[$key]['from'])) continue;
        if ($field === null || isset($sources[$key]['fields'][$field])) return $key;
    }
    return null;
}

/** The prefix a source is referred to by in formulas: "expenses", "event_transactions" */
function rbModuleRef($sourceKey) {
    return strpos($sourceKey, 'table:') === 0 ? substr($sourceKey, 6) : $sourceKey;
}

/** All {module.Field} references in the report's formulas, grouped by prefix */
function rbForeignRefs(array $cfg) {
    $refs = [];
    $formulas = array_merge(array_column($cfg['columns'], 'formula'), array_column($cfg['calculations'], 'formula'));
    foreach ($formulas as $formula) {
        if ($formula === '') continue;
        foreach (rbFormulaRefs($formula) as $ref) {
            if (($dot = strpos($ref, '.')) !== false) {
                $refs[substr($ref, 0, $dot)][substr($ref, $dot + 1)] = true;
            }
        }
    }
    return array_map('array_keys', $refs);
}

/**
 * Load the rows of every other module the formulas use (only the fields they need).
 * Rows are keyed "prefix.Field" so they never clash with the report's own fields.
 */
function rbLoadForeignRows(PDO $pdo, array $cfg) {
    $out = [];
    foreach (rbForeignRefs($cfg) as $prefix => $fieldList) {
        // Fields of one prefix may live in a ready-made source or the raw table; load each set
        $bySource = [];
        foreach ($fieldList as $field) {
            $key = rbResolveModule($prefix, $field);
            if ($key !== null) $bySource[$key][] = $field;
        }
        if (!$bySource) continue;
        $sourceKey = array_key_first($bySource);
        if (count($bySource) > 1) {
            // Prefer the source that has every requested field
            foreach ($bySource as $k => $fs) {
                if (count($fs) === count($fieldList)) { $sourceKey = $k; break; }
            }
        }
        $fields = $bySource[$sourceKey];
        $sub = rbNormalizeConfig(['source' => $sourceKey, 'columns' => array_map(function ($f) { return ['key' => $f]; }, $fields), 'sort' => []]);
        $sub['sort'] = [];
        $rows = rbFetchRows($pdo, $sub);
        $out[$prefix] = array_map(function ($r) use ($prefix, $fields) {
            $q = [];
            foreach ($fields as $f) $q[$prefix . '.' . $f] = $r[$f] ?? null;
            return $q;
        }, $rows);
    }
    return $out;
}

/**
 * Problems with formulas: syntax errors and references to fields that don't exist.
 * Returns ['columns' => [key => message], 'calculations' => [index => message]]
 */
function rbFormulaIssues(array $cfg) {
    $fields = rbSources()[$cfg['source']]['fields'];
    $issues = ['columns' => [], 'calculations' => []];
    $available = array_keys($fields);
    $check = function ($formula, array $known) {
        if ($formula === '') return 'Enter a formula';
        $p = rbParseFormula($formula);
        if ($p['error'] !== null) return $p['error'];
        foreach (array_diff($p['refs'], $known) as $ref) {
            if (($dot = strpos($ref, '.')) === false) {
                return 'Unknown field {' . $ref . '} — pick one from the list below';
            }
            // {module.Field}: the module and the field must exist
            $prefix = substr($ref, 0, $dot);
            if (rbResolveModule($prefix) === null) return 'Unknown module "' . $prefix . '" in {' . $ref . '}';
            if (rbResolveModule($prefix, substr($ref, $dot + 1)) === null) {
                return 'Module "' . $prefix . '" has no field "' . substr($ref, $dot + 1) . '"';
            }
        }
        return null;
    };
    foreach ($cfg['columns'] as $col) {
        $known = array_merge($available, array_column($cfg['columns'], 'key'));
        if ($col['formula'] !== '' || strpos($col['key'], 'calc_') === 0) {
            $msg = $check($col['formula'], $known);
            if (in_array($col['key'], rbFormulaRefs($col['formula']), true)) $msg = 'A column cannot use itself in its formula';
            if ($msg) $issues['columns'][$col['key']] = $msg;
        }
    }
    foreach ($cfg['calculations'] as $i => $c) {
        if ($c['formula'] === '') continue; // not filled in yet
        $msg = $check($c['formula'], array_merge($available, array_column($cfg['columns'], 'key')));
        if ($msg) $issues['calculations'][$i] = $msg;
    }
    return $issues;
}

/**
 * Format one value for display according to the column format
 */
function rbFormatValue($value, array $col, array $layout) {
    $format = $col['format'] ?? 'text';
    if ($value === null || $value === '') {
        return in_array($format, array_merge(RB_NUMERIC_FORMATS, ['date', 'datetime']), true) ? '-' : '';
    }
    if (in_array($format, RB_NUMERIC_FORMATS, true) && !is_numeric($value)) {
        return preg_replace('/\s+/', ' ', (string)$value); // a formula returned text
    }
    switch ($format) {
        case 'amount':
            $n = (float)$value;
            return ($n == 0 && $layout['zero_dash']) ? '-' : formatIndianAmount($n, 2);
        case 'number':
            $n = (float)$value;
            if ($n == 0 && $layout['zero_dash']) return '-';
            return formatIndianAmount($n, abs($n - round($n)) < 0.00001 ? 0 : 2);
        case 'integer':
            return formatIndianAmount(round((float)$value), 0);
        case 'percent':
            return number_format((float)$value, 2) . '%';
        case 'date':
        case 'datetime':
            $ts = strtotime((string)$value);
            if (!$ts) return (string)$value;
            $out = date($layout['date_format'], $ts);
            return ($format === 'datetime' && strlen((string)$value) > 10 && substr((string)$value, 11, 8) !== '00:00:00') ? $out . ' ' . date('h:i A', $ts) : $out;
        default:
            return preg_replace('/\s+/', ' ', (string)$value);
    }
}

/**
 * SQL for one condition on a field expression (adds bound parameters)
 */
function rbConditionSql($expr, array $c, array &$params) {
    $v = $c['value'];
    switch ($c['op']) {
        case 'eq': $params[] = $v; return "$expr = ?";
        case 'ne': $params[] = $v; return "($expr <> ? OR $expr IS NULL)";
        case 'gt': $params[] = $v; return "$expr > ?";
        case 'gte': $params[] = $v; return "$expr >= ?";
        case 'lt': $params[] = $v; return "$expr < ?";
        case 'lte': $params[] = $v; return "$expr <= ?";
        case 'contains': $params[] = '%' . rbLikeEscape($v) . '%'; return "$expr LIKE ?";
        case 'not_contains': $params[] = '%' . rbLikeEscape($v) . '%'; return "($expr NOT LIKE ? OR $expr IS NULL)";
        case 'starts': $params[] = rbLikeEscape($v) . '%'; return "$expr LIKE ?";
        case 'ends': $params[] = '%' . rbLikeEscape($v); return "$expr LIKE ?";
        case 'empty': return "($expr IS NULL OR $expr = '')";
        case 'not_empty': return "($expr IS NOT NULL AND $expr <> '')";
        case 'in':
            $list = array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
            if (!$list) return '1 = 0';
            $params = array_merge($params, $list);
            return "$expr IN (" . implode(',', array_fill(0, count($list), '?')) . ')';
        case 'between':
            $params[] = $v;
            $params[] = $c['value2'];
            return "$expr BETWEEN ? AND ?";
    }
    return '1 = 1';
}

function rbLikeEscape($s) {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * The same conditions evaluated in PHP (for calculated and manual columns)
 */
function rbConditionMatch($actual, array $c) {
    $blank = rbIsBlank($actual);
    $a = trim((string)($actual ?? ''));
    $v = $c['value'];
    $numeric = !$blank && is_numeric(is_string($actual) ? str_replace(',', '', $a) : $actual) && is_numeric(str_replace(',', '', $v));
    $cmp = $numeric ? (rbNum($actual) <=> rbNum($v)) : strcasecmp($a, $v);
    switch ($c['op']) {
        case 'eq': return !$blank && $cmp === 0;
        case 'ne': return $blank || $cmp !== 0;
        case 'gt': return !$blank && $cmp > 0;
        case 'gte': return !$blank && $cmp >= 0;
        case 'lt': return !$blank && $cmp < 0;
        case 'lte': return !$blank && $cmp <= 0;
        case 'contains': return $v === '' || mb_stripos($a, $v) !== false;
        case 'not_contains': return $v === '' || mb_stripos($a, $v) === false;
        case 'starts': return mb_stripos($a, $v) === 0;
        case 'ends': return $v === '' || (mb_strlen($a) >= mb_strlen($v) && mb_strtolower(mb_substr($a, -mb_strlen($v))) === mb_strtolower($v));
        case 'empty': return $blank;
        case 'not_empty': return !$blank;
        case 'in':
            foreach (array_map('trim', explode(',', $v)) as $item) {
                if ($item !== '' && ((is_numeric($item) && !$blank && rbNum($actual) == rbNum($item)) || strcasecmp($a, $item) === 0)) return true;
            }
            return false;
        case 'between':
            if ($blank) return false;
            $lo = $c['value'];
            $hi = $c['value2'];
            if (is_numeric($lo) && is_numeric($hi) && is_numeric(str_replace(',', '', $a))) {
                return rbNum($actual) >= rbNum($lo) && rbNum($actual) <= rbNum($hi);
            }
            return strcasecmp($a, $lo) >= 0 && strcasecmp($a, $hi) <= 0;
    }
    return true;
}

/**
 * Fetch raw rows (associative, keyed by field / column key) for a normalised config.
 * Conditions on database fields run in SQL; conditions on calculated columns run later in PHP.
 */
function rbFetchRows(PDO $pdo, array $cfg, &$truncated = false) {
    $truncated = false;
    if ($cfg['source'] === 'manual') {
        $rows = [];
        // An auto-numbered first column (S.No) alone does not make a row worth printing
        $first = $cfg['columns'][0] ?? null;
        $skipFirst = $first && $first['format'] === 'integer' && preg_match('/^s\.?\s*no/i', $first['label']);
        foreach ($cfg['manual_rows'] as $r) {
            if (trim(implode('', $skipFirst ? array_slice($r, 1) : $r)) === '') continue;
            $assoc = [];
            foreach ($cfg['columns'] as $i => $col) {
                $v = $r[$i] ?? '';
                if (in_array($col['format'], RB_NUMERIC_FORMATS, true) && $v !== '') {
                    $clean = preg_replace('/[^0-9.\-]/', '', $v);
                    $v = is_numeric($clean) ? (float)$clean : $v;
                }
                $assoc[$col['key']] = $v;
            }
            $rows[] = $assoc;
        }
        return $rows;
    }

    $def = rbSources()[$cfg['source']];
    $fields = $def['fields'];
    $f = $cfg['filters'];

    // Fields needed: shown columns, formula references, sort keys, group key, conditions
    $need = [];
    $addRefs = function ($formula) use (&$need, $fields) {
        foreach (rbFormulaRefs($formula) as $ref) {
            if (isset($fields[$ref])) $need[$ref] = true;
        }
    };
    foreach ($cfg['columns'] as $col) {
        if (isset($fields[$col['key']])) $need[$col['key']] = true;
        $addRefs($col['formula']);
    }
    foreach ($cfg['calculations'] as $c) $addRefs($c['formula']);
    foreach ($cfg['sort'] as $s) if (isset($fields[$s['key']])) $need[$s['key']] = true;
    if ($cfg['group_by'] !== '' && isset($fields[$cfg['group_by']])) $need[$cfg['group_by']] = true;
    foreach ($f['conditions'] as $c) if (isset($fields[$c['field']])) $need[$c['field']] = true;
    if (!$need) $need[array_key_first($fields)] = true;

    $select = [];
    foreach (array_keys($need) as $key) {
        $select[] = $fields[$key][1] . ' AS `' . $key . '`';
    }

    $where = [];
    $params = [];
    $dateExpr = $def['date'] ?? null;
    if (!empty($def['date_fields']) && $f['date_field'] !== '' && isset($def['date_fields'][$f['date_field']])) {
        $dateExpr = $def['date_fields'][$f['date_field']];
    }
    if ($dateExpr) {
        if ($f['date_from'] !== '') { $where[] = $dateExpr . ' >= ?'; $params[] = $f['date_from']; }
        if ($f['date_to'] !== '') { $where[] = $dateExpr . ' < ?'; $params[] = date('Y-m-d', strtotime($f['date_to'] . ' +1 day')); }
    }
    if (!empty($def['year']) && $f['year_id'] > 0) { $where[] = $def['year'] . ' = ?'; $params[] = $f['year_id']; }
    if (!empty($def['member']) && $f['member_ids']) {
        $where[] = $def['member'] . ' IN (' . implode(',', array_fill(0, count($f['member_ids']), '?')) . ')';
        $params = array_merge($params, $f['member_ids']);
    }
    if (!empty($def['payment_mode']) && $f['payment_mode'] !== '') { $where[] = $def['payment_mode'] . ' = ?'; $params[] = $f['payment_mode']; }
    if (!empty($def['status']) && $f['status'] !== '') { $where[] = $def['status'] . ' = ?'; $params[] = $f['status']; }
    if (!empty($def['category']) && $f['category'] !== '') { $where[] = $def['category'] . ' = ?'; $params[] = $f['category']; }
    if (!empty($def['search']) && $f['search'] !== '') {
        $like = '%' . rbLikeEscape($f['search']) . '%';
        $where[] = '(' . implode(' OR ', array_map(function ($c) { return $c . ' LIKE ?'; }, $def['search'])) . ')';
        $params = array_merge($params, array_fill(0, count($def['search']), $like));
    }

    $nonzeroSql = $f['nonzero'] ? '(' . implode(' OR ', array_map(function ($k) use ($fields) { return $fields[$k][1] . ' <> 0'; }, $f['nonzero'])) . ')' : null;
    $aggregated = !empty($def['group_sql']);
    $having = [];
    $havingParams = [];
    if ($nonzeroSql) {
        if ($aggregated) $having[] = $nonzeroSql; else $where[] = $nonzeroSql;
    }

    // Conditions on database fields go to SQL, unless "match any" mixes them with calculated columns
    $sqlConds = array_values(array_filter($f['conditions'], function ($c) use ($fields) { return isset($fields[$c['field']]); }));
    $phpConds = count($sqlConds) !== count($f['conditions']);
    if ($sqlConds && !($phpConds && $f['match'] === 'any')) {
        $parts = [];
        $p = [];
        foreach ($sqlConds as $c) {
            $parts[] = rbConditionSql($fields[$c['field']][1], $c, $p);
        }
        $sql = '(' . implode($f['match'] === 'any' ? ' OR ' : ' AND ', $parts) . ')';
        if ($aggregated) { $having[] = $sql; $havingParams = array_merge($havingParams, $p); }
        else { $where[] = $sql; $params = array_merge($params, $p); }
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $def['from']
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ($aggregated ? ' GROUP BY ' . $def['group_sql'] : '')
        . ($having ? ' HAVING ' . implode(' AND ', $having) : '')
        . ' LIMIT ' . (RB_MAX_ROWS + 1);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, $havingParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > RB_MAX_ROWS) {
        $truncated = true;
        $rows = array_slice($rows, 0, RB_MAX_ROWS);
    }
    return $rows;
}

/**
 * Compute every formula column, in column order, for all rows.
 * $groups: list of [key, rows-index-list] when the report is grouped (for GSUM / GRUNNING).
 */
function rbComputeFormulaColumns(array &$rows, array $cfg, array $groups = []) {
    $formulaCols = array_values(array_filter($cfg['columns'], function ($c) { return $c['formula'] !== ''; }));
    if (!$formulaCols || !$rows) return;

    $groupOf = [];
    foreach ($groups as $gi => [$gkey, $indexes]) {
        foreach ($indexes as $pos => $rowIndex) $groupOf[$rowIndex] = [$gi, $pos];
    }
    foreach ($formulaCols as $col) {
        $parsed = rbParseFormula($col['formula']);
        if ($parsed['error'] !== null) {
            foreach ($rows as &$r) $r[$col['key']] = null;
            unset($r);
            continue;
        }
        $ctx = new RbFormulaContext();
        $ctx->rows = $rows;
        $groupRows = [];
        foreach ($groups as $gi => [$gkey, $indexes]) {
            $groupRows[$gi] = array_map(function ($i) use ($rows) { return $rows[$i]; }, $indexes);
        }
        $values = [];
        foreach ($rows as $i => $r) {
            $ctx->row = $r;
            $ctx->index = $i;
            if (isset($groupOf[$i])) {
                [$gi, $pos] = $groupOf[$i];
                $ctx->group = $groupRows[$gi];
                $ctx->groupKey = (string)$gi;
                $ctx->gindex = $pos;
            } else {
                $ctx->group = null;
                $ctx->groupKey = '';
                $ctx->gindex = $i;
            }
            try {
                $values[$i] = rbEvalNode($parsed['ast'], $ctx);
            } catch (Throwable $e) {
                $values[$i] = null;
            }
        }
        foreach ($values as $i => $v) {
            if (is_float($v) && (is_nan($v) || is_infinite($v))) $v = null;
            $rows[$i][$col['key']] = $v;
        }
    }
}

/**
 * Aggregate one column over a set of rows (totals and subtotals)
 */
function rbColumnTotal(array $set, array $col) {
    $fn = $col['total'];
    if ($fn === 'recalc') {
        // Recalculate the column's own formula from the set's totals (e.g. a weighted percentage)
        $parsed = rbParseFormula($col['formula']);
        if ($parsed['error'] !== null) return null;
        $ctx = new RbFormulaContext();
        $ctx->rows = array_values($set);
        $ctx->summary = true;
        try {
            return rbEvalNode($parsed['ast'], $ctx);
        } catch (Throwable $e) {
            return null;
        }
    }
    $vals = [];
    foreach ($set as $r) {
        $v = $r[$col['key']] ?? null;
        if ($fn === 'count') {
            if (!rbIsBlank($v)) $vals[] = 1;
        } elseif (!rbIsBlank($v) && (is_numeric($v) || is_float($v))) {
            $vals[] = (float)$v;
        }
    }
    if ($fn === 'count') return count($vals);
    if (!$vals) return in_array($fn, ['sum'], true) ? 0 : null;
    switch ($fn) {
        case 'avg': return array_sum($vals) / count($vals);
        case 'min': return min($vals);
        case 'max': return max($vals);
        default: return array_sum($vals);
    }
}

/** Backwards-compatible helper */
function rbAggregate(array $rows, string $key, string $fn) {
    return rbColumnTotal($rows, ['key' => $key, 'total' => $fn, 'formula' => '']);
}

/**
 * Build the render model shared by preview, PDF, Excel and CSV.
 * Items: ['type' => 'group'|'row'|'subtotal'|'total', 'label' => ..., 'cells' => [...], 'raw' => [...]]
 */
function rbBuildReport(PDO $pdo, array $cfg) {
    // Data from other modules used by the formulas, and the report's date range
    RbFormulaContext::$env = [
        'foreign' => rbLoadForeignRows($pdo, $cfg),
        'date_from' => $cfg['filters']['date_from'],
        'date_to' => $cfg['filters']['date_to'],
    ];
    $truncated = false;
    $rows = rbFetchRows($pdo, $cfg, $truncated);
    $fields = rbSources()[$cfg['source']]['fields'];
    $layout = $cfg['layout'];

    // Pass 1: formulas in fetch order (so calculated columns can be filtered and sorted on)
    rbComputeFormulaColumns($rows, $cfg);

    // Conditions that SQL could not apply (calculated / manual columns, or "match any" with those)
    $conds = $cfg['filters']['conditions'];
    $sqlFields = $cfg['source'] === 'manual' ? [] : $fields;
    $phpConds = array_values(array_filter($conds, function ($c) use ($sqlFields) { return !isset($sqlFields[$c['field']]); }));
    $mixedAny = $phpConds && $cfg['filters']['match'] === 'any';
    $applyConds = $mixedAny ? $conds : $phpConds;
    if ($applyConds) {
        $any = $cfg['filters']['match'] === 'any';
        $rows = array_values(array_filter($rows, function ($r) use ($applyConds, $any) {
            foreach ($applyConds as $c) {
                $ok = rbConditionMatch($r[$c['field']] ?? null, $c);
                if ($any && $ok) return true;
                if (!$any && !$ok) return false;
            }
            return !$any;
        }));
    }

    // Sort (in PHP so formula and manual columns sort too)
    if ($cfg['sort']) {
        $indexed = [];
        foreach ($rows as $i => $r) $indexed[] = [$i, $r];
        usort($indexed, function ($a, $b) use ($cfg) {
            foreach ($cfg['sort'] as $s) {
                $x = $a[1][$s['key']] ?? null;
                $y = $b[1][$s['key']] ?? null;
                if ($x === $y) continue;
                if (rbIsBlank($x)) return 1;
                if (rbIsBlank($y)) return -1;
                $c = (is_numeric($x) && is_numeric($y)) ? ((float)$x <=> (float)$y) : strnatcasecmp((string)$x, (string)$y);
                if ($c !== 0) return $s['dir'] === 'desc' ? -$c : $c;
            }
            return $a[0] <=> $b[0];
        });
        $rows = array_column($indexed, 1);
    }

    // Grouping keeps the sort order: groups appear in the order their first row does
    $groups = [];
    if ($cfg['group_by'] !== '') {
        $buckets = [];
        foreach ($rows as $r) {
            $buckets[(string)($r[$cfg['group_by']] ?? '')][] = $r;
        }
        $rows = [];
        foreach ($buckets as $gkey => $set) {
            $indexes = [];
            foreach ($set as $r) {
                $indexes[] = count($rows);
                $rows[] = $r;
            }
            $groups[] = [$gkey, $indexes];
        }
    }

    // Pass 2: formulas again in display order, so running totals, ranks and group maths are final
    rbComputeFormulaColumns($rows, $cfg, $groups);

    $visible = array_values(array_filter($cfg['columns'], function ($c) { return !$c['hidden']; }));
    $columns = [];
    if ($layout['row_numbers']) {
        $columns[] = ['key' => '__sno', 'label' => 'S.No', 'format' => 'integer', 'align' => 'C', 'width' => 0.6, 'total' => 'none', 'formula' => ''];
    }
    foreach ($visible as $c) $columns[] = $c;

    $hasTotals = (bool)array_filter($columns, function ($c) { return $c['total'] !== 'none'; });
    $labelIndex = 0;
    foreach ($columns as $i => $c) {
        if ($c['total'] === 'none' && $c['key'] !== '__sno') { $labelIndex = $i; break; }
    }

    $totalCells = function (array $set, string $label) use ($columns, $layout, $labelIndex) {
        $cells = [];
        $raw = [];
        foreach ($columns as $i => $c) {
            if ($c['total'] !== 'none') {
                $v = rbColumnTotal($set, $c);
                $fmtCol = $c['total'] === 'count' ? array_merge($c, ['format' => 'integer']) : $c;
                $cells[] = rbFormatValue($v, $fmtCol, array_merge($layout, ['zero_dash' => false]));
                $raw[] = $v;
            } else {
                $cells[] = $i === $labelIndex ? $label : '';
                $raw[] = $i === $labelIndex ? $label : '';
            }
        }
        return [$cells, $raw];
    };

    // Label and format of the grouping field
    $groupLabel = '';
    $groupFmt = ['format' => 'text'];
    if ($cfg['group_by'] !== '') {
        foreach ($cfg['columns'] as $c) {
            if ($c['key'] === $cfg['group_by']) { $groupLabel = $c['label']; $groupFmt = $c; }
        }
        if ($groupLabel === '' && isset($fields[$cfg['group_by']])) {
            $groupLabel = $fields[$cfg['group_by']][0];
            $groupFmt = ['format' => $fields[$cfg['group_by']][2] === 'flag' ? 'text' : $fields[$cfg['group_by']][2]];
        }
    }
    $groupDisplay = function ($gv) use ($groupFmt, $layout) {
        return rbIsBlank($gv) ? '(blank)' : rbFormatValue($gv, $groupFmt, $layout);
    };

    $items = [];
    $sno = 0;
    $rowItem = function (array $r) use (&$sno, $columns, $layout) {
        $sno++;
        $cells = [];
        $raw = [];
        foreach ($columns as $c) {
            $v = $c['key'] === '__sno' ? $sno : ($r[$c['key']] ?? null);
            $cells[] = rbFormatValue($v, $c, $layout);
            $raw[] = $v;
        }
        return ['type' => 'row', 'cells' => $cells, 'raw' => $raw];
    };

    if ($groups && $layout['summary_only']) {
        // One line per group: the group name plus each totalled column
        foreach ($groups as [$gkey, $indexes]) {
            $set = array_map(function ($i) use ($rows) { return $rows[$i]; }, $indexes);
            [$cells, $raw] = $totalCells($set, $groupDisplay($set[0][$cfg['group_by']] ?? $gkey) . ' (' . count($set) . ')');
            $sno++;
            foreach ($columns as $i => $c) {
                if ($c['key'] === '__sno') { $cells[$i] = (string)$sno; $raw[$i] = $sno; }
            }
            $items[] = ['type' => 'row', 'cells' => $cells, 'raw' => $raw];
        }
    } elseif ($groups) {
        foreach ($groups as [$gkey, $indexes]) {
            $set = array_map(function ($i) use ($rows) { return $rows[$i]; }, $indexes);
            $items[] = ['type' => 'group', 'label' => $groupLabel . ': ' . $groupDisplay($set[0][$cfg['group_by']] ?? $gkey)];
            foreach ($set as $r) $items[] = $rowItem($r);
            if ($hasTotals && $layout['group_subtotals']) {
                [$cells, $raw] = $totalCells($set, 'Subtotal');
                $items[] = ['type' => 'subtotal', 'cells' => $cells, 'raw' => $raw];
            }
        }
    } else {
        foreach ($rows as $r) $items[] = $rowItem($r);
    }

    if ($hasTotals && $rows) {
        [$cells, $raw] = $totalCells($rows, 'TOTAL');
        $items[] = ['type' => 'total', 'cells' => $cells, 'raw' => $raw];
    }

    // Summary tiles: row count plus each totalled column (max 5 tiles)
    $tiles = [['Total Entries', formatIndianAmount(count($rows), 0)]];
    if ($groups) $tiles[] = ['Groups', formatIndianAmount(count($groups), 0)];
    foreach ($columns as $c) {
        if ($c['total'] === 'none' || count($tiles) >= 5) continue;
        $v = rbColumnTotal($rows, $c);
        $prefix = ['sum' => 'Total ', 'avg' => 'Average ', 'min' => 'Minimum ', 'max' => 'Maximum ', 'count' => 'Count of ', 'recalc' => 'Overall '][$c['total']];
        $fmtCol = $c['total'] === 'count' ? array_merge($c, ['format' => 'integer']) : $c;
        $value = rbFormatValue($v, $fmtCol, array_merge($layout, ['zero_dash' => false]));
        if ($fmtCol['format'] === 'amount' && is_numeric($v)) $value = 'Rs. ' . $value;
        $name = preg_replace('/\s*\(Rs\.?\)\s*$/i', '', $c['label']);
        // Avoid "Total Total Distributed" when the column name already starts with the word
        if (stripos($name, trim($prefix)) === 0) $prefix = '';
        $tiles[] = [$prefix . $name, $value];
    }

    // Calculation lines: evaluated over all rows, where {Field} means the field's total
    $calcs = [];
    foreach ($cfg['calculations'] as $calcIndex => $c) {
        if ($c['formula'] === '') continue; // blank lines are not printed
        $value = null;
        $parsed = rbParseFormula($c['formula']);
        if ($parsed['error'] === null) {
            $ctx = new RbFormulaContext();
            $ctx->rows = $rows;
            $ctx->summary = true;
            try { $value = rbEvalNode($parsed['ast'], $ctx); } catch (Throwable $e) { $value = null; }
        }
        $display = $parsed['error'] !== null ? 'Formula error' : rbFormatValue($value, ['format' => $c['format']], array_merge($layout, ['zero_dash' => false]));
        if ($c['format'] === 'amount' && is_numeric($value)) $display = 'Rs. ' . $display;
        $calcs[] = ['label' => $c['label'], 'value' => $display, 'raw' => $value, 'format' => $c['format'], 'index' => $calcIndex];
    }

    $f = $cfg['filters'];
    $period = '';
    if ($f['date_from'] !== '' || $f['date_to'] !== '') {
        $period = ($f['date_from'] !== '' ? date('d M Y', strtotime($f['date_from'])) : 'Beginning')
            . '  to  ' . ($f['date_to'] !== '' ? date('d M Y', strtotime($f['date_to'])) : date('d M Y'));
    }

    return [
        'columns' => array_map(function ($c) {
            return ['label' => $c['label'], 'align' => $c['align'], 'width' => $c['width'], 'format' => $c['format']];
        }, $columns),
        'items' => $items,
        'row_count' => count($rows),
        'group_count' => count($groups),
        'tiles' => $layout['summary_tiles'] ? $tiles : [],
        'calculations' => $calcs,
        'period' => $period,
        'truncated' => $truncated,
        'issues' => rbFormulaIssues($cfg),
    ];
}

/**
 * Build every table section of a report. Returns one render model per section (same shape as
 * rbBuildReport), all sharing the document-global layout. A legacy config with no `sections`
 * yields exactly one section, so existing single-table reports are unchanged.
 */
function rbBuildReportSections(PDO $pdo, array $cfg) {
    $layout = $cfg['layout'];
    $sections = (isset($cfg['sections']) && is_array($cfg['sections']) && $cfg['sections'])
        ? $cfg['sections']
        : [rbNormalizeSection($cfg)];
    $models = [];
    foreach (array_values($sections) as $sec) {
        // Each section is a full, buildable config that shares the global layout + letterhead
        $sc = array_merge($sec, ['layout' => $layout, 'letterhead_id' => $cfg['letterhead_id'] ?? 0]);
        $model = rbBuildReport($pdo, $sc); // resets RbFormulaContext::$env at its top
        $model['heading'] = $sec['heading'] ?? '';
        $model['filters_text'] = rbFilterSummary($pdo, $sc);
        $models[] = $model;
    }
    return [
        'title' => $cfg['title'] ?? 'Custom Report',
        'subtitle' => $cfg['subtitle'] ?? '',
        'sections' => $models,
        'section_count' => count($models),
    ];
}

/**
 * Human-readable list of the active filters (printed on the report)
 */
function rbFilterSummary(PDO $pdo, array $cfg) {
    $f = $cfg['filters'];
    $fields = rbSources()[$cfg['source']]['fields'];
    $parts = [];
    if ($f['year_id'] > 0) {
        $stmt = $pdo->prepare('SELECT YearName FROM academic_years WHERE YearID = ?');
        $stmt->execute([$f['year_id']]);
        $parts[] = 'Year ' . ($stmt->fetchColumn() ?: $f['year_id']);
    }
    if ($f['member_ids']) {
        if (count($f['member_ids']) <= 3) {
            $stmt = $pdo->prepare('SELECT MemberName FROM members WHERE MemberID IN (' . implode(',', array_fill(0, count($f['member_ids']), '?')) . ')');
            $stmt->execute($f['member_ids']);
            $parts[] = implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } else {
            $parts[] = count($f['member_ids']) . ' members';
        }
    }
    foreach (['payment_mode' => '', 'status' => 'Status ', 'category' => ''] as $k => $p) {
        if ($f[$k] !== '') $parts[] = $p . $f[$k];
    }
    if ($f['search'] !== '') $parts[] = '"' . $f['search'] . '"';
    $opText = ['eq' => '=', 'ne' => '≠', 'gt' => '>', 'gte' => '≥', 'lt' => '<', 'lte' => '≤', 'contains' => 'contains', 'not_contains' => 'excludes', 'starts' => 'starts with', 'ends' => 'ends with', 'empty' => 'is blank', 'not_empty' => 'is not blank', 'in' => 'is one of', 'between' => 'between'];
    $condParts = [];
    foreach ($f['conditions'] as $c) {
        $label = $fields[$c['field']][0] ?? null;
        if ($label === null) {
            foreach ($cfg['columns'] as $col) if ($col['key'] === $c['field']) $label = $col['label'];
        }
        $value = in_array($c['op'], ['empty', 'not_empty'], true) ? '' : ' ' . $c['value'] . ($c['op'] === 'between' ? ' and ' . $c['value2'] : '');
        $condParts[] = ($label ?? $c['field']) . ' ' . $opText[$c['op']] . $value;
    }
    if ($condParts) $parts[] = implode($f['match'] === 'any' ? ' or ' : ' and ', $condParts);
    return $parts ? implode(' · ', $parts) : 'All records';
}

/**
 * Saved report templates (created on first use)
 */
function rbEnsureTemplateTable(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS custom_report_templates (
            TemplateID INT AUTO_INCREMENT PRIMARY KEY,
            Name VARCHAR(150) NOT NULL,
            Description VARCHAR(255) DEFAULT NULL,
            Source VARCHAR(80) NOT NULL,
            Config LONGTEXT NOT NULL,
            IsPinned TINYINT(1) NOT NULL DEFAULT 0,
            CreatedBy INT DEFAULT NULL,
            UpdatedBy INT DEFAULT NULL,
            CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (Name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
