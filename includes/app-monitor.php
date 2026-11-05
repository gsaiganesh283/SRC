<?php
/**
 * Application monitor: records the live flow of the application and its errors in `app_monitor`.
 *
 *   - every request (portal, page, user, web or desktop app, status, time taken)
 *   - PHP warnings / notices and fatal errors (with file, line and trace)
 *   - errors reported by the desktop app (superadmin/api/monitor.php?action=client_error)
 *
 * Loaded from config.php. It never changes how a page behaves: the error handler passes every
 * error on to PHP (and to any earlier handler), and all logging failures are ignored.
 * Settings (system_settings): monitor_enabled, monitor_log_requests, monitor_retention_days.
 * A request can opt out by defining AM_SKIP before config.php (used by the monitor's own polling).
 */

if (!function_exists('amInit')) {
    $GLOBALS['__am'] = ['start' => microtime(true), 'events' => [], 'prev_handler' => null, 'active' => false, 'tables' => [], 'track' => true];

    /**
     * Statements of the main connection: notes which tables each query reads or writes (names and counts only,
     * never data) for the desktop Live Monitor's Data Flow. Behaves exactly like PDOStatement otherwise.
     */
    class AmStatement extends PDOStatement
    {
        private $amRuns = 0;

        protected function __construct()
        {
            amTrackSql($this->queryString);
        }

        #[\ReturnTypeWillChange]
        public function execute($params = null)
        {
            // the first run was counted when the statement was made; count repeated runs too
            if ($this->amRuns++ > 0) amTrackSql($this->queryString);
            return parent::execute($params);
        }
    }

    /** Remember the tables a statement reads / writes: ['transactions|read' => n, ...] */
    function amTrackSql($sql) {
        $am = &$GLOBALS['__am'];
        if (!$am['track'] || count($am['tables']) >= 60) return;
        $sql = (string)$sql;
        if (!preg_match('/^\s*\(?\s*(SELECT|INSERT|REPLACE|UPDATE|DELETE|WITH)\b/i', $sql, $m)) return;
        $verb = strtoupper($m[1]);
        $op = ['INSERT' => 'insert', 'REPLACE' => 'insert', 'UPDATE' => 'update', 'DELETE' => 'delete'][$verb] ?? 'read';
        $target = null;
        if ($op === 'insert' && preg_match('/\bINTO\s+`?(\w+)`?/i', $sql, $t)) $target = $t[1];
        elseif ($op === 'update' && preg_match('/^\s*UPDATE\s+(?:LOW_PRIORITY\s+|IGNORE\s+)*`?(\w+)`?/i', $sql, $t)) $target = $t[1];
        elseif ($op === 'delete' && preg_match('/\bFROM\s+`?(\w+)`?/i', $sql, $t)) $target = $t[1];
        $skip = ['information_schema', 'dual', 'app_monitor', 'select', 'where', 'set'];
        if ($target && !in_array(strtolower($target), $skip, true)) {
            $key = $target . '|' . $op;
            $am['tables'][$key] = ($am['tables'][$key] ?? 0) + 1;
        }
        // every other table named in FROM / JOIN is read (also inside INSERT ... SELECT and sub-queries)
        if (preg_match_all('/\b(?:FROM|JOIN)\s+`?(\w+)`?(?!\s*\()/i', $sql, $all)) {
            foreach (array_unique($all[1]) as $t) {
                if ($t === $target && $op !== 'read') continue;
                if (in_array(strtolower($t), $skip, true) || is_numeric($t)) continue;
                $key = $t . '|read';
                $am['tables'][$key] = ($am['tables'][$key] ?? 0) + 1;
            }
        }
    }

    /** The module a page belongs to (used by the desktop Data Flow) */
    function amModule($portal, $page) {
        $p = strtolower(preg_replace('/\s*\(.*$/', '', (string)$page));
        $map = [
            '#^accounting/events/#' => 'Events', '#^accounting/#' => 'Accounting', '#(^|/)dbadmin#' => 'DB Admin', '#monitor#' => 'Live Monitor',
            '#transaction#' => 'Transactions', '#member#' => 'Members', '#net-balance|balance#' => 'Net Balance', '#bill#' => 'Bill Generator',
            '#change-amount#' => 'Change Amount', '#report-builder|generate-custom-report#' => 'Report Builder', '#report|statement|export#' => 'Reports',
            '#academic-years|manage-years|years#' => 'Academic Years', '#admin#' => 'Admins', '#permission#' => 'Permissions',
            '#backup|database-update#' => 'Backup & Update', '#google-drive|gdrive|proof#' => 'Google Drive', '#template|letterhead#' => 'Templates',
            '#setting|link-behavior#' => 'Settings', '#audit#' => 'Audit Logs', '#login|logout|auth|two-factor|face#' => 'Sign in & Security',
            '#import|excel#' => 'Import / Export', '#notification|message#' => 'Notifications', '#dashboard#' => 'Dashboard', '#payment#' => 'Payments',
            '#document|profile#' => 'Profile & Documents', '#chat|gemini#' => 'Assistant',
        ];
        foreach ($map as $re => $name) {
            if (preg_match($re, $p)) return $name;
        }
        if ($portal === 'auth') return 'Sign in & Security';
        return ucwords(str_replace(['-', '_', '.php'], [' ', ' ', ''], basename($p))) ?: 'Other';
    }

    /** Field names whose values are never written to the log */
    function amSecretName($name) {
        return (bool)preg_match('/pass(word)?|secret|token|api_?key|private|credential|totp|otp|code$|descriptor|^pin$/i', (string)$name);
    }

    /** Query string without secret values */
    function amCleanQuery(array $q) {
        $out = [];
        foreach ($q as $k => $v) {
            if (is_array($v)) { $out[] = $k . '[' . count($v) . ']'; continue; }
            $out[] = $k . '=' . (amSecretName($k) ? '***' : mb_substr((string)$v, 0, 60));
        }
        return mb_substr(implode('&', $out), 0, 500);
    }

    /** Who is signed in, from the session (without starting one) */
    function amUser() {
        $s = $_SESSION ?? [];
        if (!empty($s['superadmin_id'])) return ['SuperAdmin', (int)$s['superadmin_id'], (string)($s['superadmin_username'] ?? $s['superadmin_name'] ?? '')];
        if (!empty($s['admin_id'])) return ['Admin', (int)$s['admin_id'], (string)($s['admin_username'] ?? $s['admin_name'] ?? '')];
        if (!empty($s['member_id'])) return ['Member', (int)$s['member_id'], (string)($s['member_name'] ?? '')];
        return [null, null, null];
    }

    /** Page and portal of this request, e.g. ['superadmin', 'accounting/manage-expenses.php'] */
    function amPage() {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $portal = 'public';
        foreach (['superadmin', 'admin', 'member', 'api', 'auth', 'cron'] as $p) {
            if (preg_match('#/' . $p . '/(.*)$#', $script, $m)) { $portal = $p; $page = $m[1]; break; }
        }
        $page = $page ?? ltrim(basename($script), '/');
        // Desktop bridge: show the real page it ran
        if ($portal === 'superadmin' && $page === 'desktop-api.php' && !empty($_GET['__page'])) {
            $page = preg_replace('/[^A-Za-z0-9_\-\/.]/', '', (string)$_GET['__page']) . ' (' . preg_replace('/\W/', '', (string)($_GET['__mode'] ?? '')) . ')';
        }
        return [$portal, mb_substr($page, 0, 200)];
    }

    function amIsDesktop() {
        return stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'SRColonySuperAdminDesktop') === 0;
    }

    /** Add an event to this request's buffer (written at the end of the request) */
    function amRecord($kind, $level, $message, $file = null, $line = null, $trace = null, $extra = []) {
        if (count($GLOBALS['__am']['events']) >= 50) return; // runaway loops
        $GLOBALS['__am']['events'][] = array_merge([
            'kind' => $kind, 'level' => $level, 'message' => mb_substr((string)$message, 0, 4000),
            'file' => $file ? mb_substr(str_replace('\\', '/', (string)$file), -250) : null, 'line' => $line ? (int)$line : null,
            'trace' => $trace ? mb_substr((string)$trace, 0, 12000) : null,
        ], $extra);
    }

    function amErrorHandler($errno, $errstr, $errfile = '', $errline = 0) {
        static $levels = [E_WARNING => 'warning', E_USER_WARNING => 'warning', E_NOTICE => 'notice', E_USER_NOTICE => 'notice',
            E_DEPRECATED => 'deprecated', E_USER_DEPRECATED => 'deprecated', E_USER_ERROR => 'error', E_RECOVERABLE_ERROR => 'error'];
        if (error_reporting() & $errno) {  // not silenced with @
            $level = $levels[$errno] ?? 'warning';
            if ($level !== 'deprecated') {
                $trace = [];
                foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1, 12) as $f) {
                    $trace[] = (isset($f['class']) ? $f['class'] . $f['type'] : '') . ($f['function'] ?? '') . '()' . (isset($f['file']) ? ' ' . basename($f['file']) . ':' . ($f['line'] ?? '') : '');
                }
                amRecord('error', $level, $errstr, $errfile, $errline, implode("\n", $trace));
            }
        }
        $prev = $GLOBALS['__am']['prev_handler'];
        return $prev ? (bool)call_user_func($prev, $errno, $errstr, $errfile, $errline) : false; // false: PHP handles it as usual
    }

    /** Settings with defaults (one small query, cached for the request) */
    function amSettings(PDO $pdo) {
        static $s = null;
        if ($s !== null) return $s;
        $s = ['monitor_enabled' => '1', 'monitor_log_requests' => '1', 'monitor_retention_days' => '7'];
        try {
            foreach ($pdo->query("SELECT SettingKey, SettingValue FROM system_settings WHERE SettingKey LIKE 'monitor\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                $s[$k] = (string)$v;
            }
        } catch (Throwable $e) { /* defaults */ }
        return $s;
    }

    function amEnsureTable(PDO $pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS app_monitor (
                ID BIGINT AUTO_INCREMENT PRIMARY KEY,
                LoggedAt DATETIME NOT NULL,
                Kind VARCHAR(20) NOT NULL COMMENT 'request | error | client_error',
                Level VARCHAR(12) NOT NULL DEFAULT 'info',
                Source VARCHAR(10) NOT NULL DEFAULT 'web' COMMENT 'web | desktop',
                Portal VARCHAR(20) DEFAULT NULL,
                Page VARCHAR(255) DEFAULT NULL,
                Method VARCHAR(8) DEFAULT NULL,
                Query VARCHAR(500) DEFAULT NULL,
                Status SMALLINT DEFAULT NULL,
                DurationMs INT DEFAULT NULL,
                MemoryKb INT DEFAULT NULL,
                UserType VARCHAR(20) DEFAULT NULL,
                UserID INT DEFAULT NULL,
                UserName VARCHAR(100) DEFAULT NULL,
                Message TEXT DEFAULT NULL,
                File VARCHAR(255) DEFAULT NULL,
                Line INT DEFAULT NULL,
                Trace MEDIUMTEXT DEFAULT NULL,
                IP VARCHAR(45) DEFAULT NULL,
                UserAgent VARCHAR(200) DEFAULT NULL,
                RequestID VARCHAR(16) DEFAULT NULL,
                Module VARCHAR(60) DEFAULT NULL,
                Tables TEXT DEFAULT NULL COMMENT 'JSON: tables read / written by the request',
                KEY idx_am_time (LoggedAt),
                KEY idx_am_kind (Kind, Level),
                KEY idx_am_request (RequestID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /** Add the Data Flow columns to a table made before them */
    function amUpgradeTable(PDO $pdo) {
        $have = $pdo->query('SHOW COLUMNS FROM app_monitor')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('Module', $have, true)) $pdo->exec('ALTER TABLE app_monitor ADD COLUMN Module VARCHAR(60) DEFAULT NULL');
        if (!in_array('Tables', $have, true)) $pdo->exec("ALTER TABLE app_monitor ADD COLUMN Tables TEXT DEFAULT NULL COMMENT 'JSON: tables read / written by the request'");
    }

    /** Write this request's events (called at shutdown) */
    function amFlush() {
        $am = &$GLOBALS['__am'];
        if (!$am['active']) return;
        $am['active'] = false;
        $am['track'] = false; // the monitor's own queries are not part of the flow
        try {
            $fatal = error_get_last();
            if ($fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $msg = $fatal['message'];
                $trace = null;
                if (($p = strpos($msg, 'Stack trace:')) !== false) { $trace = trim(substr($msg, $p + 12)); $msg = trim(substr($msg, 0, $p)); }
                amRecord('error', 'fatal', $msg, $fatal['file'], $fatal['line'], $trace);
            }
            $pdo = $GLOBALS['pdo'] ?? null;
            if (!$pdo instanceof PDO) return;
            $settings = amSettings($pdo);
            if ($settings['monitor_enabled'] !== '1') return;
            $status = http_response_code() ?: 200;
            $logRequest = !defined('AM_SKIP') && $settings['monitor_log_requests'] === '1';
            if (!$am['events'] && !$logRequest) return;

            // A page that left a transaction open would roll our rows back: use our own connection then
            $db = $pdo;
            if ($pdo->inTransaction()) {
                $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }
            [$portal, $page] = amPage();
            [$userType, $userId, $userName] = amUser();
            $base = [
                'Source' => amIsDesktop() ? 'desktop' : 'web', 'Portal' => $portal, 'Page' => $page,
                'Method' => mb_substr($_SERVER['REQUEST_METHOD'] ?? 'CLI', 0, 8),
                'Query' => amCleanQuery(array_filter($_GET, function ($k) { return strpos((string)$k, '__') !== 0; }, ARRAY_FILTER_USE_KEY))
                    . (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $_POST ? ' | POST: ' . amCleanQuery(array_map(function ($v) { return is_array($v) ? $v : (is_scalar($v) && mb_strlen((string)$v) <= 40 ? $v : '…'); }, $_POST)) : ''),
                'Status' => $status, 'DurationMs' => (int)round((microtime(true) - $am['start']) * 1000),
                'MemoryKb' => (int)round(memory_get_peak_usage() / 1024),
                'UserType' => $userType, 'UserID' => $userId, 'UserName' => $userName ? mb_substr($userName, 0, 100) : null,
                'IP' => mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), 'UserAgent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
                'RequestID' => substr(bin2hex(random_bytes(8)), 0, 16),
            ];
            $rows = [];
            $worst = null;
            foreach ($am['events'] as $e) {
                $rows[] = array_merge($base, ['Kind' => $e['kind'], 'Level' => $e['level'], 'Message' => $e['message'], 'File' => $e['file'], 'Line' => $e['line'], 'Trace' => $e['trace']],
                    array_intersect_key($e, array_flip(['Source', 'Page', 'Portal'])));
                if ($e['kind'] === 'error') $worst = in_array($e['level'], ['fatal', 'error'], true) ? 'error' : ($worst ?: 'warning');
            }
            if ($logRequest) {
                $level = $status >= 500 || $worst === 'error' ? 'error' : ($status >= 400 || $worst ? 'warning' : 'info');
                $tables = [];
                foreach ($am['tables'] as $key => $n) {
                    [$t, $op] = explode('|', $key);
                    $tables[] = ['t' => $t, 'op' => $op, 'n' => $n];
                }
                $rows[] = array_merge($base, ['Kind' => 'request', 'Level' => $level, 'Message' => null, 'File' => null, 'Line' => null, 'Trace' => null,
                    'Module' => amModule($portal, $page), 'Tables' => $tables ? json_encode($tables) : null]);
            }
            $cols = ['LoggedAt', 'Kind', 'Level', 'Source', 'Portal', 'Page', 'Method', 'Query', 'Status', 'DurationMs', 'MemoryKb', 'UserType', 'UserID', 'UserName', 'Message', 'File', 'Line', 'Trace', 'IP', 'UserAgent', 'RequestID', 'Module', 'Tables'];
            $sql = 'INSERT INTO app_monitor (' . implode(',', $cols) . ') VALUES (NOW(),' . implode(',', array_fill(0, count($cols) - 1, '?')) . ')';
            $insert = function () use ($db, $sql, $rows, $cols) {
                $st = $db->prepare($sql);
                foreach ($rows as $r) {
                    $st->execute(array_map(function ($c) use ($r) { return $r[$c] ?? null; }, array_slice($cols, 1)));
                }
            };
            try {
                $insert();
            } catch (PDOException $e) {
                if ($e->getCode() === '42S02') {
                    amEnsureTable($db);   // first use: create the table, then try again
                } elseif ($e->getCode() === '42S22') {
                    amUpgradeTable($db);  // table from before the Data Flow: add its columns
                } else {
                    throw $e;
                }
                $insert();
            }
            // Now and then remove old entries
            if (mt_rand(1, 200) === 1) {
                $days = max(1, min(365, (int)$settings['monitor_retention_days']));
                $db->exec('DELETE FROM app_monitor WHERE LoggedAt < NOW() - INTERVAL ' . $days . ' DAY');
            }
        } catch (Throwable $e) {
            @error_log('App monitor could not write: ' . $e->getMessage());
        }
    }

    function amInit() {
        if (PHP_SAPI === 'cli' || !empty($GLOBALS['__am']['active'])) return;
        $GLOBALS['__am']['active'] = true;
        $GLOBALS['__am']['prev_handler'] = set_error_handler('amErrorHandler');
        register_shutdown_function('amFlush');
        // Note the tables each query touches (for the Data Flow); a failure here changes nothing
        if (!defined('AM_SKIP') && ($GLOBALS['pdo'] ?? null) instanceof PDO) {
            try {
                $GLOBALS['pdo']->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['AmStatement']);
            } catch (Throwable $e) { /* keep the normal statements */ }
        }
    }
}

amInit();
