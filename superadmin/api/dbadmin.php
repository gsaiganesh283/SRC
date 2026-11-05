<?php
/**
 * DB Admin API (Super Admin) – used by the desktop app's DB Admin screen.
 *
 *   GET  ?action=tables                                     every table: rows, size, engine, auto increment, collation
 *   GET  ?action=structure&table=                           columns, indexes, foreign keys, CREATE TABLE
 *   GET  ?action=rows&table=&page=&per=&sort=&dir=&q=&filters=[{"col","op","val"}]
 *   GET  ?action=server                                     server version, status, database size, processes
 *   GET  ?action=export&table=&format=csv|sql               file download (current search / filters for csv)
 *   POST ?action=update_row   {table, pk:{col:val}, values:{col:val}}
 *   POST ?action=insert_row   {table, values:{col:val}}
 *   POST ?action=delete_rows  {table, pks:[{col:val}, ...]}
 *   POST ?action=sql          {sql, allow_write}            one statement; SELECT-like ones return rows
 *   POST ?action=table_op     {table, op: optimize|analyze|check|repair|truncate}
 *
 * POST calls need the session's token in X-RB-Token. Password, token and key columns are shown masked and
 * cannot be edited here. Every change is written to the audit log.
 */
session_start();
require_once '../../config/config.php';
require_once '../../includes/functions.php';

function dbaJson($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if (empty($_SESSION['superadmin_id'])) {
    dbaJson(['success' => false, 'error' => 'Your session has expired. Please sign in again.'], 401);
}
$action = $_GET['action'] ?? 'tables';
$isWrite = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isWrite) {
    $token = $_SERVER['HTTP_X_RB_TOKEN'] ?? '';
    if (empty($_SESSION['rb_token']) || !hash_equals($_SESSION['rb_token'], (string)$token)) {
        dbaJson(['success' => false, 'error' => 'The page expired. Please reload it and try again.'], 403);
    }
}
$in = $isWrite ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];

/** Columns whose values never leave the server */
function dbaSecret($col) {
    return (bool)preg_match('/pass(word)?|secret|token|api_?key|private_?key|client_?secret|refresh|descriptor|credential|totp|serviceaccount/i', (string)$col)
        && !preg_match('/^(totp_enabled|face_enabled|require_strong_password|reset_password)$/i', (string)$col);
}

function dbaTables(PDO $pdo) {
    return $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
}

/** A table name from the request, only if it exists */
function dbaTable(PDO $pdo, $name) {
    if (!in_array((string)$name, dbaTables($pdo), true)) {
        dbaJson(['success' => false, 'error' => 'That table does not exist.'], 404);
    }
    return (string)$name;
}

function dbaColumns(PDO $pdo, $table) {
    return $pdo->query('SHOW FULL COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
}

function dbaPrimary(array $cols) {
    return array_values(array_map(function ($c) { return $c['Field']; }, array_filter($cols, function ($c) { return $c['Key'] === 'PRI'; })));
}

function dbaQ($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

/** WHERE for search text and filters, validated against the table's columns */
function dbaWhere(array $cols, $q, $filters) {
    $names = array_column($cols, 'Field');
    $where = [];
    $args = [];
    if ($q !== '') {
        $parts = [];
        foreach ($names as $n) {
            if (dbaSecret($n)) continue;
            $parts[] = 'CAST(' . dbaQ($n) . ' AS CHAR) LIKE ?';
            $args[] = '%' . $q . '%';
        }
        if ($parts) $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    $ops = ['=' => '=', '!=' => '<>', '>' => '>', '>=' => '>=', '<' => '<', '<=' => '<=', 'contains' => 'LIKE', 'starts' => 'LIKE', 'not contains' => 'NOT LIKE',
        'is empty' => null, 'is not empty' => null, 'is null' => null, 'is not null' => null];
    foreach (is_array($filters) ? $filters : [] as $f) {
        $col = $f['col'] ?? '';
        $op = $f['op'] ?? '=';
        if (!in_array($col, $names, true) || !array_key_exists($op, $ops) || dbaSecret($col)) continue;
        $c = dbaQ($col);
        $v = (string)($f['val'] ?? '');
        if ($op === 'is null') $where[] = "$c IS NULL";
        elseif ($op === 'is not null') $where[] = "$c IS NOT NULL";
        elseif ($op === 'is empty') $where[] = "($c IS NULL OR $c = '')";
        elseif ($op === 'is not empty') $where[] = "($c IS NOT NULL AND $c <> '')";
        elseif ($op === 'contains' || $op === 'not contains') { $where[] = "$c {$ops[$op]} ?"; $args[] = '%' . $v . '%'; }
        elseif ($op === 'starts') { $where[] = "$c LIKE ?"; $args[] = $v . '%'; }
        else { $where[] = "$c {$ops[$op]} ?"; $args[] = $v; }
    }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $args];
}

function dbaMask(array $rows) {
    foreach ($rows as &$r) {
        foreach ($r as $k => $v) {
            if (dbaSecret($k)) $r[$k] = ($v === null || $v === '') ? $v : '********';
        }
    }
    return $rows;
}

function dbaAudit($action, $table, $details, $recordId = null) {
    try {
        createAuditLog($_SESSION['superadmin_id'], 'SuperAdmin', 'DB Admin', $action, mb_substr($details, 0, 1000), $table, $recordId, null, null, 'Warning');
    } catch (Throwable $e) { /* logging is optional */ }
}

/** Values for INSERT / UPDATE: known columns only, never secret ones; "" for a nullable column becomes NULL when asked */
function dbaValues(array $cols, $values) {
    $byName = [];
    foreach ($cols as $c) $byName[$c['Field']] = $c;
    $out = [];
    foreach (is_array($values) ? $values : [] as $k => $v) {
        if (!isset($byName[$k])) dbaJson(['success' => false, 'error' => "Unknown column $k."], 400);
        if (dbaSecret($k)) dbaJson(['success' => false, 'error' => "$k is a protected column and cannot be changed here."], 400);
        $out[$k] = $v;
    }
    return $out;
}

try {
    switch ($action) {
        case 'tables':
            $s = $pdo->query("SELECT TABLE_NAME AS name, TABLE_ROWS AS approx_rows, ENGINE AS engine, TABLE_COLLATION AS collation,
                    DATA_LENGTH + INDEX_LENGTH AS size_bytes, DATA_FREE AS free_bytes, AUTO_INCREMENT AS auto_increment, CREATE_TIME AS created, UPDATE_TIME AS updated, TABLE_COMMENT AS comment
                FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
            $tables = $s->fetchAll(PDO::FETCH_ASSOC);
            foreach ($tables as &$t) {
                $t['rows'] = (int)$pdo->query('SELECT COUNT(*) FROM ' . dbaQ($t['name']))->fetchColumn();  // exact (InnoDB estimates are rough)
            }
            unset($t);
            dbaJson(['success' => true, 'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(), 'tables' => $tables]);

        case 'structure':
            $table = dbaTable($pdo, $_GET['table'] ?? '');
            $indexes = $pdo->query('SHOW INDEX FROM ' . dbaQ($table))->fetchAll(PDO::FETCH_ASSOC);
            $fk = $pdo->prepare('SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL');
            $fk->execute([$table]);
            $create = $pdo->query('SHOW CREATE TABLE ' . dbaQ($table))->fetch(PDO::FETCH_NUM)[1];
            dbaJson(['success' => true, 'table' => $table, 'columns' => dbaColumns($pdo, $table), 'indexes' => $indexes, 'foreign_keys' => $fk->fetchAll(PDO::FETCH_ASSOC), 'create' => $create]);

        case 'rows':
        case 'export':
            $table = dbaTable($pdo, $_GET['table'] ?? '');
            $cols = dbaColumns($pdo, $table);
            $names = array_column($cols, 'Field');
            [$where, $args] = dbaWhere($cols, trim((string)($_GET['q'] ?? '')), json_decode((string)($_GET['filters'] ?? '[]'), true));
            $sort = in_array($_GET['sort'] ?? '', $names, true) ? $_GET['sort'] : (dbaPrimary($cols)[0] ?? $names[0]);
            $dir = strtolower($_GET['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
            if ($action === 'export') {
                $format = ($_GET['format'] ?? 'csv') === 'sql' ? 'sql' : 'csv';
                $s = $pdo->prepare('SELECT * FROM ' . dbaQ($table) . " $where ORDER BY " . dbaQ($sort) . " $dir");
                $s->execute($args);
                $name = $table . '-' . date('Ymd-His') . '.' . $format;
                header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'application/sql') . '; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $name . '"');
                $out = fopen('php://output', 'w');
                if ($format === 'csv') {
                    fwrite($out, "\xEF\xBB\xBF");
                    fputcsv($out, $names);
                    while ($r = $s->fetch(PDO::FETCH_ASSOC)) fputcsv($out, dbaMask([$r])[0]);
                } else {
                    fwrite($out, "-- Table $table, exported " . date('Y-m-d H:i:s') . " (protected columns masked)\n");
                    fwrite($out, $pdo->query('SHOW CREATE TABLE ' . dbaQ($table))->fetch(PDO::FETCH_NUM)[1] . ";\n\n");
                    while ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                        $r = dbaMask([$r])[0];
                        fwrite($out, 'INSERT INTO ' . dbaQ($table) . ' (' . implode(',', array_map('dbaQ', array_keys($r))) . ') VALUES ('
                            . implode(',', array_map(function ($v) use ($pdo) { return $v === null ? 'NULL' : $pdo->quote((string)$v); }, $r)) . ");\n");
                    }
                }
                fclose($out);
                dbaAudit('export_table', $table, "Exported $table as $format");
                exit;
            }
            $per = max(10, min(1000, (int)($_GET['per'] ?? 100)));
            $c = $pdo->prepare('SELECT COUNT(*) FROM ' . dbaQ($table) . " $where");
            $c->execute($args);
            $total = (int)$c->fetchColumn();
            $pages = max(1, (int)ceil($total / $per));
            $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
            $s = $pdo->prepare('SELECT * FROM ' . dbaQ($table) . " $where ORDER BY " . dbaQ($sort) . " $dir LIMIT $per OFFSET " . (($page - 1) * $per));
            $s->execute($args);
            dbaJson(['success' => true, 'table' => $table, 'columns' => $cols, 'primary' => dbaPrimary($cols), 'protected' => array_values(array_filter($names, 'dbaSecret')),
                'rows' => dbaMask($s->fetchAll(PDO::FETCH_ASSOC)), 'total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per, 'sort' => $sort, 'dir' => $dir]);

        case 'server':
            $status = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime', 'Threads_connected', 'Threads_running', 'Questions', 'Slow_queries',
                'Connections', 'Aborted_connects', 'Bytes_received', 'Bytes_sent', 'Max_used_connections', 'Innodb_buffer_pool_pages_total', 'Innodb_buffer_pool_pages_free')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $vars = $pdo->query("SHOW VARIABLES WHERE Variable_name IN ('version', 'version_comment', 'max_connections', 'max_allowed_packet', 'innodb_buffer_pool_size',
                'character_set_server', 'collation_server', 'time_zone', 'system_time_zone', 'sql_mode', 'wait_timeout')")->fetchAll(PDO::FETCH_KEY_PAIR);
            $size = $pdo->query('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0), COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch(PDO::FETCH_NUM);
            try {
                $processes = $pdo->query('SHOW FULL PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $processes = [];
            }
            dbaJson(['success' => true, 'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(), 'size_bytes' => (int)$size[0], 'table_count' => (int)$size[1],
                'status' => $status, 'variables' => $vars, 'processes' => $processes, 'server_time' => $pdo->query('SELECT NOW()')->fetchColumn(),
                'php' => ['version' => PHP_VERSION, 'memory_limit' => ini_get('memory_limit'), 'upload_max_filesize' => ini_get('upload_max_filesize'),
                    'post_max_size' => ini_get('post_max_size'), 'max_execution_time' => ini_get('max_execution_time'), 'zip' => class_exists('ZipArchive')]]);

        case 'update_row':
        case 'insert_row':
            $table = dbaTable($pdo, $in['table'] ?? '');
            $cols = dbaColumns($pdo, $table);
            $values = dbaValues($cols, $in['values'] ?? []);
            if (!$values) dbaJson(['success' => false, 'error' => 'Nothing to save.'], 400);
            if ($action === 'insert_row') {
                $pdo->prepare('INSERT INTO ' . dbaQ($table) . ' (' . implode(',', array_map('dbaQ', array_keys($values))) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')')
                    ->execute(array_values($values));
                $id = $pdo->lastInsertId();
                dbaAudit('insert_row', $table, "Inserted a row into $table (ID $id): " . json_encode($values, JSON_UNESCAPED_UNICODE), (int)$id ?: null);
                dbaJson(['success' => true, 'message' => "Row added to $table" . ($id ? " (ID $id)." : '.'), 'id' => $id]);
            }
            $pk = dbaPrimary($cols);
            $where = $in['pk'] ?? [];
            if (!$pk || !is_array($where) || array_diff($pk, array_keys($where))) dbaJson(['success' => false, 'error' => "$table has no primary key, so a single row cannot be edited safely here. Use the SQL tab."], 400);
            $sets = implode(', ', array_map(function ($k) { return dbaQ($k) . ' = ?'; }, array_keys($values)));
            $cond = implode(' AND ', array_map(function ($k) { return dbaQ($k) . ' = ?'; }, $pk));
            $old = $pdo->prepare('SELECT * FROM ' . dbaQ($table) . " WHERE $cond");
            $old->execute(array_map(function ($k) use ($where) { return $where[$k]; }, $pk));
            $before = $old->fetch(PDO::FETCH_ASSOC);
            if (!$before) dbaJson(['success' => false, 'error' => 'That row no longer exists.'], 404);
            $s = $pdo->prepare('UPDATE ' . dbaQ($table) . " SET $sets WHERE $cond LIMIT 1");
            $s->execute(array_merge(array_values($values), array_map(function ($k) use ($where) { return $where[$k]; }, $pk)));
            $changed = array_filter($values, function ($v, $k) use ($before) { return (string)$before[$k] !== (string)$v; }, ARRAY_FILTER_USE_BOTH);
            dbaAudit('update_row', $table, "Updated $table " . json_encode($where) . ': ' . json_encode(array_map(function ($k) use ($before, $values) {
                return [$before[$k], $values[$k]]; }, array_combine(array_keys($changed), array_keys($changed))), JSON_UNESCAPED_UNICODE));
            dbaJson(['success' => true, 'message' => $s->rowCount() ? 'Row updated (' . count($changed) . ' field(s) changed).' : 'No changes.']);

        case 'delete_rows':
            $table = dbaTable($pdo, $in['table'] ?? '');
            $pk = dbaPrimary(dbaColumns($pdo, $table));
            $pks = is_array($in['pks'] ?? null) ? $in['pks'] : [];
            if (!$pk) dbaJson(['success' => false, 'error' => "$table has no primary key, so rows cannot be deleted one by one here. Use the SQL tab."], 400);
            if (!$pks) dbaJson(['success' => false, 'error' => 'Select one or more rows first.'], 400);
            $cond = implode(' AND ', array_map(function ($k) { return dbaQ($k) . ' = ?'; }, $pk));
            $s = $pdo->prepare('DELETE FROM ' . dbaQ($table) . " WHERE $cond LIMIT 1");
            $n = 0;
            $pdo->beginTransaction();
            foreach ($pks as $row) {
                if (!is_array($row) || array_diff($pk, array_keys($row))) continue;
                $s->execute(array_map(function ($k) use ($row) { return $row[$k]; }, $pk));
                $n += $s->rowCount();
            }
            $pdo->commit();
            dbaAudit('delete_rows', $table, "Deleted $n row(s) from $table: " . json_encode(array_slice($pks, 0, 50)));
            dbaJson(['success' => true, 'message' => "$n row(s) deleted from $table."]);

        case 'sql':
            $sql = trim((string)($in['sql'] ?? ''));
            $sql = rtrim($sql, "; \t\r\n");
            if ($sql === '') dbaJson(['success' => false, 'error' => 'Type an SQL statement first.'], 400);
            if (preg_match('/;\s*\S/', preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/s", "''", $sql))) {
                dbaJson(['success' => false, 'error' => 'Run one statement at a time.'], 400);
            }
            $readOnly = (bool)preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $sql);
            if (!$readOnly && empty($in['allow_write'])) {
                dbaJson(['success' => false, 'needs_confirm' => true, 'error' => 'This statement changes the database. Confirm to run it.'], 409);
            }
            $t0 = microtime(true);
            $s = $pdo->query($sql);
            $ms = (int)round((microtime(true) - $t0) * 1000);
            if ($s->columnCount() > 0) {
                $rows = [];
                while (count($rows) < 2000 && ($r = $s->fetch(PDO::FETCH_ASSOC))) $rows[] = $r;
                $more = (bool)$s->fetch();
                $cols = [];
                for ($i = 0; $i < $s->columnCount(); $i++) $cols[] = $s->getColumnMeta($i)['name'] ?? ('col' . $i);
                if (!$readOnly) dbaAudit('sql', null, 'SQL: ' . $sql);
                dbaJson(['success' => true, 'columns' => $cols, 'rows' => dbaMask($rows), 'count' => count($rows), 'truncated' => $more, 'ms' => $ms,
                    'message' => count($rows) . ' row(s)' . ($more ? ' (first 2000 shown)' : '') . " in {$ms} ms."]);
            }
            $n = $s->rowCount();
            dbaAudit('sql', null, 'SQL: ' . $sql . " ($n row(s))");
            dbaJson(['success' => true, 'columns' => [], 'rows' => [], 'affected' => $n, 'ms' => $ms, 'message' => "$n row(s) affected in {$ms} ms."]);

        case 'table_op':
            $table = dbaTable($pdo, $in['table'] ?? '');
            $op = $in['op'] ?? '';
            if (!in_array($op, ['optimize', 'analyze', 'check', 'repair', 'truncate'], true)) dbaJson(['success' => false, 'error' => 'Unknown table operation.'], 400);
            if ($op === 'truncate') {
                if (($in['confirm'] ?? '') !== $table) dbaJson(['success' => false, 'error' => 'Type the table name to confirm emptying it.'], 400);
                $pdo->exec('TRUNCATE TABLE ' . dbaQ($table));
                dbaAudit('truncate_table', $table, "Emptied table $table");
                dbaJson(['success' => true, 'message' => "$table emptied.", 'result' => []]);
            }
            $result = $pdo->query(strtoupper($op) . ' TABLE ' . dbaQ($table))->fetchAll(PDO::FETCH_ASSOC);
            dbaAudit($op . '_table', $table, ucfirst($op) . " table $table");
            dbaJson(['success' => true, 'message' => ucfirst($op) . " finished for $table.", 'result' => $result]);

        default:
            dbaJson(['success' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    dbaJson(['success' => false, 'error' => $e->getMessage()], 400);
}
