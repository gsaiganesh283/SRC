<?php
/**
 * Database Update – brings a database up to date with the October 2026 changes.
 * Checks each item first, shows what is needed, takes a full backup, then applies only what is missing.
 * Safe to open again: items that are already done are left alone.
 */
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/backup-helper.php';
require_once '../includes/functions.php';
require_once '../includes/logo-helper.php';
require_once 'fpdf.php';
require_once 'includes/bill-lib.php';

if (empty($_SESSION['db_update_token'])) {
    $_SESSION['db_update_token'] = bin2hex(random_bytes(16));
}

/** Integer single-column primary keys without AUTO_INCREMENT */
function dbuMissingAutoIncrement(PDO $pdo) {
    return $pdo->query("SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
        WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_KEY = 'PRI' AND c.COLUMN_TYPE LIKE '%int%' AND c.EXTRA NOT LIKE '%auto_increment%'
          AND (SELECT COUNT(*) FROM information_schema.COLUMNS k WHERE k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME AND k.COLUMN_KEY = 'PRI') = 1
        ORDER BY c.TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
}

function dbuColumns(PDO $pdo, $table) {
    try {
        return $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return null; // table missing
    }
}

function dbuHasPrimary(PDO $pdo, $table) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY'");
    $s->execute([$table]);
    return (bool)$s->fetchColumn();
}

// Rows with ID 0 can be moved to a normal ID when we know what points at them
$DBU_CHILDREN = [
    'expenses' => [["UPDATE proof_attachments SET EntityID = ? WHERE EntityType = 'expense' AND EntityID = 0"],
        ["UPDATE bills SET RefID = ? WHERE RefType = 'expense' AND RefID = 0"]],
    'event_transactions' => [["UPDATE proof_attachments SET EntityID = ? WHERE EntityType = 'event_transaction' AND EntityID = 0"],
        ["UPDATE bills SET RefID = ? WHERE RefType = 'event_transaction' AND RefID = 0"]],
    'transactions' => [["UPDATE proof_attachments SET EntityID = ? WHERE EntityType = 'transaction' AND EntityID = 0"],
        ["UPDATE bills SET RefID = ? WHERE RefType = 'transaction' AND RefID = 0"]],
    'events' => [['UPDATE event_transactions SET EventID = ? WHERE EventID = 0'], ['UPDATE bills SET EventID = ? WHERE EventID = 0']],
];

/** Give the row with ID 0 the next free ID and move what points at it; returns the new ID */
function dbuMoveZeroRow(PDO $pdo, $t, $c) {
    global $DBU_CHILDREN;
    $new = (int)$pdo->query("SELECT MAX(`$c`) FROM `$t`")->fetchColumn() + 1;
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$c` = 0")->execute([$new]);
    foreach ($DBU_CHILDREN[$t] ?? [] as [$sql]) {
        try {
            $pdo->prepare($sql)->execute([$new]);
        } catch (PDOException $e) {
            // the linked table does not exist on this site - nothing to move there
            if ($e->getCode() !== '42S02') throw $e;
        }
    }
    $pdo->commit();
    return $new;
}

/**
 * Tables whose ID column (first column, integer, name ending in "ID") has no primary key at all:
 * the database never numbers their new rows, so every new record gets ID 0, and the other ID checks cannot see them.
 * Returns [['table', 'column', 'type', 'zero' => rows with ID 0/empty, 'dupes' => repeated non-zero IDs], ...]
 */
function dbuNoPrimaryKey(PDO $pdo) {
    // Each table's own ID column (from the application's schema); other tables are left alone
    $ids = ['academic_years' => 'YearID', 'activity_logs' => 'LogID', 'admins' => 'AdminID', 'admin_login_tokens' => 'TokenID', 'admin_permissions' => 'PermissionID',
        'audit_logs' => 'LogID', 'backup_history' => 'BackupID', 'balance_checkpoints' => 'CheckpointID', 'bills' => 'BillID', 'budget_limits' => 'BudgetID',
        'change_amount_transactions' => 'ChangeID', 'chitticycles' => 'CycleID', 'custom_field_definitions' => 'FieldID', 'custom_field_values' => 'ValueID',
        'custom_report_templates' => 'TemplateID', 'database_backups' => 'BackupID', 'events' => 'EventID', 'event_transactions' => 'TransactionID',
        'expenses' => 'ExpenseID', 'expense_categories' => 'CategoryID', 'google_drive_settings' => 'SettingID', 'link_target_settings' => 'LinkID',
        'members' => 'MemberID', 'member_documents' => 'DocumentID', 'member_login_tokens' => 'TokenID', 'member_permissions' => 'PermissionID',
        'messages' => 'MessageID', 'monthlystatus' => 'StatusID', 'monthly_summaries' => 'SummaryID', 'notifications' => 'NotificationID',
        'notification_preferences' => 'PreferenceID', 'payment_reminders' => 'ReminderID', 'permission_templates' => 'TemplateID', 'portal_settings' => 'SettingID',
        'proof_attachments' => 'AttachmentID', 'reports_history' => 'ReportID', 'report_letterheads' => 'LetterheadID', 'report_templates' => 'TemplateID',
        'summary' => 'SummaryID', 'super_admins' => 'SuperAdminID', 'system_notifications' => 'NotificationID', 'system_settings' => 'SettingID',
        'transactions' => 'TransactionID', 'user_activity' => 'ActivityID', 'user_settings' => 'SettingID', 'years' => 'YearID', 'year_members' => 'YearMemberID'];
    $out = [];
    $cols = $pdo->query("SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
        WHERE c.TABLE_SCHEMA = DATABASE() AND c.DATA_TYPE IN ('int', 'bigint', 'mediumint', 'smallint')
          AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA = c.TABLE_SCHEMA AND s.TABLE_NAME = c.TABLE_NAME AND s.INDEX_NAME = 'PRIMARY')
        ORDER BY c.TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        if (($ids[$col['TABLE_NAME']] ?? null) !== $col['COLUMN_NAME']) continue;
        $t = $col['TABLE_NAME']; $c = $col['COLUMN_NAME'];
        $out[] = [
            'table' => $t, 'column' => $c, 'type' => preg_replace('/\s*unsigned.*/i', ' unsigned', $col['COLUMN_TYPE']),
            'zero' => (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE `$c` IS NULL OR `$c` = 0")->fetchColumn(),
            'dupes' => (int)$pdo->query("SELECT COUNT(*) FROM (SELECT `$c` FROM `$t` WHERE `$c` > 0 GROUP BY `$c` HAVING COUNT(*) > 1) d")->fetchColumn(),
        ];
    }
    return $out;
}

/** Tables (single integer primary key) that have a row with ID 0, even though new rows are numbered automatically */
function dbuZeroIdRows(PDO $pdo) {
    $out = [];
    $cols = $pdo->query("SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c
        JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
        WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_KEY = 'PRI' AND c.COLUMN_TYPE LIKE '%int%' AND c.EXTRA LIKE '%auto_increment%'
          AND (SELECT COUNT(*) FROM information_schema.COLUMNS k WHERE k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME AND k.COLUMN_KEY = 'PRI') = 1
        ORDER BY c.TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        if ($pdo->query("SELECT COUNT(*) FROM `{$col['TABLE_NAME']}` WHERE `{$col['COLUMN_NAME']}` = 0")->fetchColumn()) {
            $out[] = $col;
        }
    }
    return $out;
}
$DBU_SAFE_TO_MOVE = ['expenses', 'event_transactions', 'transactions', 'events', 'proof_attachments', 'notifications', 'system_notifications',
    'messages', 'reports_history', 'user_activity', 'link_target_settings', 'system_settings', 'user_settings', 'portal_settings', 'monthlystatus',
    'monthly_summaries', 'summary', 'payment_reminders', 'notification_preferences', 'member_login_tokens', 'chitticycles', 'member_documents'];

/** Everything this update does: [key, title, needed?, detail, apply()] */
function dbuChecks(PDO $pdo) {
    global $DBU_CHILDREN, $DBU_SAFE_TO_MOVE;
    $checks = [];

    // 1. AUTO_INCREMENT
    $missing = dbuMissingAutoIncrement($pdo);
    $blocked = [];
    foreach ($missing as $m) {
        $zero = $pdo->query("SELECT COUNT(*) FROM `{$m['TABLE_NAME']}` WHERE `{$m['COLUMN_NAME']}` = 0")->fetchColumn();
        if ($zero && !in_array($m['TABLE_NAME'], $DBU_SAFE_TO_MOVE, true)) $blocked[] = $m['TABLE_NAME'];
    }
    $checks[] = [
        'key' => 'auto_increment',
        'title' => 'New records get proper ID numbers (AUTO_INCREMENT)',
        'needed' => (bool)$missing,
        'detail' => $missing
            ? count($missing) . ' table(s) without automatic numbering – new members, transactions, expenses and events would all get ID 0: ' . implode(', ', array_column($missing, 'TABLE_NAME'))
              . ($blocked ? '. Not changed automatically (a row with ID 0 that other records point to): ' . implode(', ', $blocked) : '')
            : 'All tables number new records automatically.',
        'apply' => function () use ($pdo, $missing, $blocked, $DBU_CHILDREN) {
            $done = [];
            foreach ($missing as $m) {
                $t = $m['TABLE_NAME']; $c = $m['COLUMN_NAME'];
                if (in_array($t, $blocked, true)) continue;
                if ($pdo->query("SELECT COUNT(*) FROM `$t` WHERE `$c` = 0")->fetchColumn()) {
                    // Give the ID-0 row a normal number first (and move what points at it)
                    $done[] = "$t: row 0 moved to " . dbuMoveZeroRow($pdo, $t, $c);
                }
                $pdo->exec("ALTER TABLE `$t` MODIFY `$c` {$m['COLUMN_TYPE']} NOT NULL AUTO_INCREMENT");
                $done[] = "$t.$c";
            }
            return 'Automatic numbering added: ' . implode(', ', $done);
        },
    ];

    // 1a. Tables with no primary key on their ID column (every new row gets ID 0; invisible to the checks above and below)
    $noPk = dbuNoPrimaryKey($pdo);
    $noPkFix = array_values(array_filter($noPk, function ($n) use ($DBU_SAFE_TO_MOVE) { return $n['dupes'] === 0 && ($n['zero'] <= 1 || in_array($n['table'], $DBU_SAFE_TO_MOVE, true)); }));
    $noPkSkip = array_diff(array_column($noPk, 'table'), array_column($noPkFix, 'table'));
    $checks[] = [
        'key' => 'primary_keys',
        'title' => 'ID columns have a primary key',
        'needed' => (bool)$noPkFix,
        'detail' => $noPk
            ? implode('; ', array_map(function ($n) { return $n['table'] . '.' . $n['column'] . ' has no primary key' . ($n['zero'] ? ' – ' . $n['zero'] . ' record(s) with ID 0' : '') . ($n['dupes'] ? ' – ' . $n['dupes'] . ' repeated ID(s)' : ''); }, $noPk))
              . '. New records in these tables get ID 0 and cannot be opened or linked to (e.g. a new event gives "Please fill in all required fields" when adding a transaction). '
              . ($noPkFix ? 'Records with ID 0 get the next free ID, then the primary key and automatic numbering are added: ' . implode(', ', array_column($noPkFix, 'table')) . '.' : '')
              . ($noPkSkip ? ' Not changed automatically (repeated IDs – check them in DB Admin first): ' . implode(', ', $noPkSkip) . '.' : '')
            : 'All ID columns have a primary key.',
        'apply' => function () use ($pdo, $noPkFix) {
            $done = [];
            foreach ($noPkFix as $n) {
                $t = $n['table']; $c = $n['column'];
                if ($n['zero'] === 1) {
                    // one record with ID 0: it and everything pointing at it move to the new ID
                    $pdo->exec("UPDATE `$t` SET `$c` = 0 WHERE `$c` IS NULL");
                    $done[] = "$t: ID 0 is now " . dbuMoveZeroRow($pdo, $t, $c);
                } elseif ($n['zero'] > 1) {
                    // several records with ID 0: give each its own number (what points at ID 0 cannot be told apart, so it is left)
                    $pdo->exec("SET @dbu_n = (SELECT COALESCE(MAX(`$c`), 0) FROM `$t`)");
                    $pdo->exec("UPDATE `$t` SET `$c` = (@dbu_n := @dbu_n + 1) WHERE `$c` IS NULL OR `$c` = 0");
                    $done[] = "$t: {$n['zero']} records with ID 0 renumbered";
                }
                $pdo->exec("ALTER TABLE `$t` MODIFY `$c` {$n['type']} NOT NULL, ADD PRIMARY KEY (`$c`)");
                $pdo->exec("ALTER TABLE `$t` MODIFY `$c` {$n['type']} NOT NULL AUTO_INCREMENT");
                $done[] = "$t.$c: primary key and automatic numbering added";
            }
            return implode('; ', $done);
        },
    ];

    // 1b. Rows still holding ID 0 in tables that already number automatically
    //     (e.g. restored from a backup, which keeps ID 0 because of NO_AUTO_VALUE_ON_ZERO)
    $zero = dbuZeroIdRows($pdo);
    $zeroMovable = array_values(array_filter($zero, function ($z) use ($DBU_SAFE_TO_MOVE) { return in_array($z['TABLE_NAME'], $DBU_SAFE_TO_MOVE, true); }));
    $zeroOther = array_diff(array_column($zero, 'TABLE_NAME'), array_column($zeroMovable, 'TABLE_NAME'));
    $checks[] = [
        'key' => 'zero_ids',
        'title' => 'Records saved with ID 0',
        'needed' => (bool)$zeroMovable,
        'detail' => $zero
            ? ($zeroMovable ? 'A record with ID 0 cannot be opened or linked to (e.g. "Please fill in all required fields" when adding an event transaction). It will get the next free ID, and the records that point to it move with it: '
                . implode(', ', array_column($zeroMovable, 'TABLE_NAME')) : '')
              . ($zeroOther ? ($zeroMovable ? '. ' : '') . 'Not changed automatically (other records may point at them): ' . implode(', ', $zeroOther) : '')
            : 'No record has ID 0.',
        'apply' => function () use ($pdo, $zeroMovable) {
            $done = [];
            foreach ($zeroMovable as $z) {
                $t = $z['TABLE_NAME'];
                $new = dbuMoveZeroRow($pdo, $t, $z['COLUMN_NAME']);
                // make sure the next new record is numbered after the moved one
                $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = " . ($new + 1));
                $done[] = "$t: ID 0 is now $new";
            }
            return implode('; ', $done);
        },
    ];

    // 2. audit_logs.Details
    $al = dbuColumns($pdo, 'audit_logs') ?? [];
    $checks[] = [
        'key' => 'audit_details',
        'title' => 'Audit log "Details" column',
        'needed' => !in_array('Details', $al, true),
        'detail' => in_array('Details', $al, true) ? 'Present.' : 'Missing – deleting a member and recalculating net balances fail without it.',
        'apply' => function () use ($pdo) {
            $pdo->exec('ALTER TABLE audit_logs ADD COLUMN Details TEXT NULL AFTER NewData');
            return 'Added audit_logs.Details';
        },
    ];

    // 3. audit_logs.UserType allows 'System' (automatic backups write as System)
    $ut = $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs' AND COLUMN_NAME = 'UserType'")->fetchColumn();
    $needSystem = $ut && stripos($ut, 'enum(') === 0 && stripos($ut, "'System'") === false;
    $checks[] = [
        'key' => 'audit_system',
        'title' => 'Audit log accepts "System" entries (automatic backups)',
        'needed' => $needSystem,
        'detail' => $needSystem ? 'UserType is ' . $ut . ' – "System" will be added.' : 'OK.',
        'apply' => function () use ($pdo, $ut) {
            $newType = rtrim($ut, ')') . ",'System')";
            $pdo->exec("ALTER TABLE audit_logs MODIFY UserType $newType NOT NULL");
            return 'UserType now ' . $newType;
        },
    ];

    // 4. report_templates columns + keys
    $rt = dbuColumns($pdo, 'report_templates');
    $rtAdd = [
        'ApplicableTo' => "ADD COLUMN ApplicableTo enum('All','SuperAdmin','Admin','Member') DEFAULT 'All' AFTER TemplateConfig",
        'IsDefault' => 'ADD COLUMN IsDefault tinyint(1) DEFAULT 0 AFTER ApplicableTo',
        'IsActive' => 'ADD COLUMN IsActive tinyint(1) DEFAULT 1 AFTER IsDefault',
        'CreatedBy' => 'ADD COLUMN CreatedBy int(11) DEFAULT NULL AFTER IsActive',
        'CreatedAt' => 'ADD COLUMN CreatedAt timestamp NOT NULL DEFAULT current_timestamp() AFTER CreatedBy',
        'UpdatedAt' => 'ADD COLUMN UpdatedAt timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER CreatedAt',
    ];
    $rtMissing = $rt === null ? [] : array_diff(array_keys($rtAdd), $rt);
    $rtNoPk = $rt !== null && !dbuHasPrimary($pdo, 'report_templates');
    $checks[] = [
        'key' => 'report_templates',
        'title' => 'Report Templates table (Templates page)',
        'needed' => $rt !== null && ($rtMissing || $rtNoPk),
        'detail' => $rt === null ? 'Table not found – skipped.' : (($rtMissing || $rtNoPk) ? 'Missing: ' . implode(', ', array_merge($rtNoPk ? ['primary key'] : [], $rtMissing)) . ' – the Templates page shows "Unknown column ApplicableTo".' : 'OK.'),
        'apply' => function () use ($pdo, $rtAdd, $rtMissing, $rtNoPk) {
            $parts = [];
            if ($rtNoPk) { $parts[] = 'ADD PRIMARY KEY (TemplateID)'; $parts[] = 'MODIFY TemplateID int(11) NOT NULL AUTO_INCREMENT'; }
            foreach ($rtMissing as $c) $parts[] = $rtAdd[$c];
            if (in_array('ApplicableTo', $rtMissing, true)) $parts[] = 'ADD KEY idx_applicable_to (ApplicableTo)';
            if (in_array('IsActive', $rtMissing, true)) $parts[] = 'ADD KEY idx_is_active (IsActive)';
            $pdo->exec('ALTER TABLE report_templates ' . implode(', ', $parts));
            return 'report_templates updated';
        },
    ];

    // 5. permission_templates columns + key
    $pt = dbuColumns($pdo, 'permission_templates');
    $ptAdd = [
        'IsDefault' => "ADD COLUMN IsDefault tinyint(1) DEFAULT 0 COMMENT 'Use as default for new users' AFTER PermissionJSON",
        'CreatedAt' => 'ADD COLUMN CreatedAt timestamp NOT NULL DEFAULT current_timestamp() AFTER IsDefault',
        'UpdatedAt' => 'ADD COLUMN UpdatedAt timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() AFTER CreatedAt',
    ];
    $ptMissing = $pt === null ? [] : array_diff(array_keys($ptAdd), $pt);
    $ptNoPk = $pt !== null && !dbuHasPrimary($pdo, 'permission_templates');
    $checks[] = [
        'key' => 'permission_templates',
        'title' => 'Permission Templates table (Permissions page)',
        'needed' => $pt !== null && ($ptMissing || $ptNoPk),
        'detail' => $pt === null ? 'Table not found – skipped.' : (($ptMissing || $ptNoPk) ? 'Missing: ' . implode(', ', array_merge($ptNoPk ? ['primary key'] : [], $ptMissing)) : 'OK.'),
        'apply' => function () use ($pdo, $ptAdd, $ptMissing, $ptNoPk) {
            $parts = [];
            if ($ptNoPk) { $parts[] = 'ADD PRIMARY KEY (TemplateID)'; $parts[] = 'MODIFY TemplateID int(11) NOT NULL AUTO_INCREMENT'; }
            foreach ($ptMissing as $c) $parts[] = $ptAdd[$c];
            $pdo->exec('ALTER TABLE permission_templates ' . implode(', ', $parts));
            return 'permission_templates updated';
        },
    ];

    // 6. Default templates when the tables are empty
    $emptyTpl = [];
    foreach (['report_templates', 'permission_templates'] as $t) {
        try {
            if ((int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() === 0) $emptyTpl[] = $t;
        } catch (Exception $e) { /* table missing */ }
    }
    $checks[] = [
        'key' => 'default_templates',
        'title' => 'Default templates (5 report templates, 6 permission templates)',
        'needed' => (bool)$emptyTpl,
        'detail' => $emptyTpl ? 'Empty: ' . implode(', ', $emptyTpl) . ' – the default templates will be added.' : 'Templates exist – left as they are.',
        'apply' => function () use ($pdo, $emptyTpl) {
            $sql = file_get_contents(dirname(__DIR__) . '/database/updates/default-templates.sql');
            $added = [];
            foreach ($emptyTpl as $t) {
                if (preg_match('/INSERT INTO `' . $t . '`.*?;\r?\n/s', $sql, $m)) {
                    $pdo->exec($m[0]);
                    $added[] = $t;
                }
            }
            return 'Default rows added to ' . implode(', ', $added);
        },
    ];

    // 7. Backup table columns and settings for the automatic daily backup
    $bc = dbuColumns($pdo, 'database_backups') ?? [];
    $needBk = !in_array('BackupType', $bc, true) || !in_array('DriveLink', $bc, true);
    $checks[] = [
        'key' => 'backups',
        'title' => 'Automatic daily backup (backup table columns)',
        'needed' => $needBk,
        'detail' => $needBk ? 'database_backups needs BackupType, DriveFileID, DriveLink and Notes columns.' : 'OK.',
        'apply' => function () use ($pdo) {
            srcBackupEnsureTable($pdo);
            return 'database_backups updated';
        },
    ];
    // 8. Bill Generator tables
    $billTables = billTablesExist($pdo);
    $checks[] = [
        'key' => 'bills',
        'title' => 'Bill Generator tables (bills, bill_counters)',
        'needed' => !$billTables,
        'detail' => $billTables ? 'OK.' : 'Missing – they hold the generated bills and their numbering.',
        'apply' => function () use ($pdo) {
            billEnsureTables($pdo);
            return 'bills and bill_counters created';
        },
    ];
    // 9. Change Amount transactions (existing year Change Amounts become opening entries)
    $caTable = caTableExists($pdo);
    $checks[] = [
        'key' => 'change_amount',
        'title' => 'Change Amount transactions table',
        'needed' => !$caTable,
        'detail' => $caTable ? 'OK.' : 'Missing – holds the change amount entries from Manage Transactions. Each year\'s current Change Amount is kept as an "Opening change amount" entry.',
        'apply' => function () use ($pdo) {
            caEnsureTable($pdo);
            return 'change_amount_transactions created';
        },
    ];
    $settingsNeeded = [];
    foreach (['auto_backup_enabled' => '1', 'backup_time' => '02:00', 'backup_retention_days' => '30', 'backup_to_gdrive' => '1'] as $k => $v) {
        $s = $pdo->prepare('SELECT COUNT(*) FROM system_settings WHERE SettingKey = ?');
        $s->execute([$k]);
        if (!$s->fetchColumn()) $settingsNeeded[$k] = $v;
    }
    $checks[] = [
        'key' => 'backup_settings',
        'title' => 'Automatic daily backup settings',
        'needed' => (bool)$settingsNeeded,
        'detail' => $settingsNeeded ? 'Will add: ' . implode(', ', array_map(function ($k, $v) { return "$k = $v"; }, array_keys($settingsNeeded), $settingsNeeded)) : 'OK (change them under Database Backup).',
        'apply' => function () use ($pdo, $settingsNeeded) {
            foreach ($settingsNeeded as $k => $v) srcBackupSaveSetting($pdo, $k, $v, $_SESSION['superadmin_id']);
            return 'Backup settings added';
        },
    ];
    return $checks;
}

$results = [];
$backupInfo = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply_updates'])) {
    if (!hash_equals($_SESSION['db_update_token'], (string)($_POST['token'] ?? ''))) {
        $error = 'The page expired. Please reload it and try again.';
    } else {
        @set_time_limit(600);
        try {
            // A full backup first, so everything can be undone
            $backupInfo = srcCreateBackup($pdo, $_SESSION['superadmin_id'], 'manual');
            foreach (dbuChecks($pdo) as $c) {
                if (!$c['needed']) continue;
                try {
                    $results[] = ['ok' => true, 'title' => $c['title'], 'text' => $c['apply']()];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $results[] = ['ok' => false, 'title' => $c['title'], 'text' => $e->getMessage()];
                }
            }
            try {
                $pdo->prepare("INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, IPAddress) VALUES ('SuperAdmin', ?, ?, ?, 'database', ?)")
                    ->execute([$_SESSION['superadmin_id'], $_SESSION['superadmin_username'] ?? 'superadmin', 'Database update applied (' . count($results) . ' item(s)), backup ' . $backupInfo['name'], $_SERVER['REMOTE_ADDR'] ?? '']);
            } catch (Exception $e) { /* logging is optional */ }
        } catch (Throwable $e) {
            $error = 'Could not make the safety backup, so nothing was changed: ' . $e->getMessage();
        }
    }
}
$checks = dbuChecks($pdo);
$pending = array_filter($checks, function ($c) { return $c['needed']; });
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Update - Super Admin</title>
    <link rel="stylesheet" href="shared-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dbu { max-width: 1000px; margin: 0 auto; padding: 20px; }
        .dbu h1 { font-size: 26px; color: var(--text-primary); display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
        .dbu .sub { color: var(--text-secondary); margin-bottom: 20px; }
        .dbu-card { background: var(--card-bg); border-radius: 12px; box-shadow: var(--shadow-sm); padding: 6px 20px; margin-bottom: 20px; }
        .dbu-item { display: flex; gap: 14px; padding: 14px 0; border-bottom: 1px solid var(--border-color); }
        .dbu-item:last-child { border-bottom: none; }
        .dbu-item > i { font-size: 20px; margin-top: 2px; width: 22px; text-align: center; }
        .dbu-item .t { font-weight: 600; color: var(--text-primary); }
        .dbu-item .d { font-size: 13px; color: var(--text-secondary); margin-top: 3px; word-break: break-word; }
        .ok { color: #34a853; } .todo { color: #f59e0b; } .bad { color: #dc3545; }
        .dbu-msg { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; }
        .dbu-msg.success { background: #d4edda; color: #155724; }
        .dbu-msg.error { background: #f8d7da; color: #721c24; }
        .dbu-msg.info { background: #e8f0fe; color: #1a4fa0; }
        .dbu-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .dbu-btn { background: var(--primary-color); color: #fff; border: none; padding: 12px 22px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 15px; }
        .dbu-btn:disabled { opacity: .5; cursor: default; }
        .dbu a.link { color: var(--primary-color); }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    <div class="dbu">
        <h1><i class="fas fa-database"></i> Database Update</h1>
        <p class="sub">Brings this website's database up to date with the October 2026 changes. Items already done are left alone, and a full backup is made before anything changes.</p>

        <?php if ($error): ?><div class="dbu-msg error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($backupInfo): ?>
            <div class="dbu-msg info"><i class="fas fa-shield-alt"></i> Safety backup made first: <b><?php echo htmlspecialchars($backupInfo['name']); ?></b> – restore it from <a class="link" href="database-backup.php">Database Backup</a> if anything looks wrong.</div>
            <div class="dbu-card">
                <?php foreach ($results as $r): ?>
                    <div class="dbu-item"><i class="fas <?php echo $r['ok'] ? 'fa-check-circle ok' : 'fa-times-circle bad'; ?>"></i>
                        <div><div class="t"><?php echo htmlspecialchars($r['title']); ?></div><div class="d"><?php echo htmlspecialchars($r['text']); ?></div></div></div>
                <?php endforeach; ?>
                <?php if (!$results): ?><div class="dbu-item"><i class="fas fa-check-circle ok"></i><div class="t">Nothing needed changing.</div></div><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="dbu-card">
            <?php foreach ($checks as $c): ?>
                <div class="dbu-item">
                    <i class="fas <?php echo $c['needed'] ? 'fa-exclamation-circle todo' : 'fa-check-circle ok'; ?>"></i>
                    <div>
                        <div class="t"><?php echo htmlspecialchars($c['title']); ?> – <?php echo $c['needed'] ? '<span class="todo">needs update</span>' : '<span class="ok">up to date</span>'; ?></div>
                        <div class="d"><?php echo htmlspecialchars($c['detail']); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="POST" class="dbu-actions" onsubmit="return confirm('Make a full backup and apply <?php echo count($pending); ?> update(s) to the database?');">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($_SESSION['db_update_token']); ?>">
            <button type="submit" name="apply_updates" class="dbu-btn" <?php echo $pending ? '' : 'disabled'; ?>>
                <i class="fas fa-play"></i> <?php echo $pending ? 'Back up and apply ' . count($pending) . ' update(s)' : 'Everything is up to date'; ?>
            </button>
            <a class="link" href="database-backup.php"><i class="fas fa-history"></i> Database Backup</a>
        </form>
    </div>
</body>
</html>
