<?php
/**
 * Live Monitor API (Super Admin): the application's live flow and errors, for live-monitor.php and the desktop app.
 *
 *   GET  ?action=feed&since_id=&kind=&level=&source=&portal=&q=&limit=   newest entries (or entries after since_id)
 *   GET  ?action=entry&id=                                               one entry with everything recorded for its request
 *   GET  ?action=stats                                                   tiles, top pages, slow pages, error groups, active users
 *   POST ?action=client_error   message, trace, page, level, version     an error from the desktop app
 *   POST ?action=settings       monitor_enabled, monitor_log_requests, monitor_retention_days   (X-RB-Token / rb_token)
 *   POST ?action=clear          what=all|requests|errors|older&days=                         (X-RB-Token / rb_token)
 */
define('AM_SKIP', true); // the monitor's own polling is not part of the flow
session_start();
require_once '../../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function monJson($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if (empty($_SESSION['superadmin_id'])) {
    monJson(['success' => false, 'error' => 'Your session has expired. Please sign in again.'], 401);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'feed';
$writes = ['settings', 'clear'];
if (in_array($action, $writes, true)) {
    $token = $_SERVER['HTTP_X_RB_TOKEN'] ?? ($_POST['rb_token'] ?? '');
    if (empty($_SESSION['rb_token']) || !hash_equals($_SESSION['rb_token'], (string)$token)) {
        monJson(['success' => false, 'error' => 'The page expired. Please reload it and try again.'], 403);
    }
}

try {
    amEnsureTable($pdo);
    $settings = amSettings($pdo);

    switch ($action) {
        case 'feed':
            $where = ['1=1'];
            $args = [];
            $since = (int)($_GET['since_id'] ?? 0);
            if ($since > 0) { $where[] = 'ID > ?'; $args[] = $since; }
            if (in_array($_GET['kind'] ?? '', ['request', 'error', 'client_error'], true)) { $where[] = 'Kind = ?'; $args[] = $_GET['kind']; }
            if (($_GET['kind'] ?? '') === 'errors') { $where[] = "Kind IN ('error', 'client_error')"; }
            if (in_array($_GET['level'] ?? '', ['info', 'notice', 'warning', 'error', 'fatal'], true)) { $where[] = 'Level = ?'; $args[] = $_GET['level']; }
            if (($_GET['level'] ?? '') === 'problems') { $where[] = "Level IN ('warning', 'error', 'fatal')"; }
            if (in_array($_GET['source'] ?? '', ['web', 'desktop'], true)) { $where[] = 'Source = ?'; $args[] = $_GET['source']; }
            if (in_array($_GET['portal'] ?? '', ['superadmin', 'admin', 'member', 'api', 'auth', 'public', 'cron'], true)) { $where[] = 'Portal = ?'; $args[] = $_GET['portal']; }
            if (($q = trim((string)($_GET['q'] ?? ''))) !== '') {
                $where[] = '(Page LIKE ? OR Message LIKE ? OR UserName LIKE ? OR Query LIKE ? OR IP LIKE ?)';
                array_push($args, ...array_fill(0, 5, '%' . $q . '%'));
            }
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 150)));
            $order = $since > 0 ? 'ASC' : 'DESC';
            $s = $pdo->prepare('SELECT ID, LoggedAt, Kind, Level, Source, Portal, Page, Method, Query, Status, DurationMs, MemoryKb, UserType, UserID, UserName,
                    LEFT(Message, 300) AS Message, File, Line, IP, RequestID FROM app_monitor WHERE ' . implode(' AND ', $where) . " ORDER BY ID $order LIMIT $limit");
            $s->execute($args);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            if ($order === 'ASC') $rows = array_reverse($rows);
            // Database clock: the same one the entries are stamped with (PHP may run in another time zone)
            monJson(['success' => true, 'rows' => $rows, 'last_id' => (int)$pdo->query('SELECT COALESCE(MAX(ID), 0) FROM app_monitor')->fetchColumn(),
                'server_time' => $pdo->query('SELECT NOW()')->fetchColumn(), 'settings' => $settings]);

        case 'flow':
            // Requests after since_id with the tables they touched (desktop Data Flow animation)
            $since = (int)($_GET['since_id'] ?? 0);
            if ($since <= 0) {
                $since = (int)$pdo->query("SELECT COALESCE(MAX(ID), 0) FROM app_monitor")->fetchColumn() - (int)min(300, max(0, (int)($_GET['backfill'] ?? 0)));
            }
            $s = $pdo->prepare("SELECT ID, LoggedAt, Source, Portal, Page, Module, Method, Level, Status, DurationMs, UserType, UserName, Tables
                FROM app_monitor WHERE Kind = 'request' AND ID > ? ORDER BY ID ASC LIMIT 300");
            $s->execute([$since]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['Tables'] = $r['Tables'] ? (json_decode($r['Tables'], true) ?: []) : [];
                $r['Module'] = $r['Module'] ?: amModule($r['Portal'], $r['Page']);
            }
            unset($r);
            monJson(['success' => true, 'rows' => $rows, 'last_id' => (int)$pdo->query('SELECT COALESCE(MAX(ID), 0) FROM app_monitor')->fetchColumn(),
                'server_time' => $pdo->query('SELECT NOW()')->fetchColumn()]);

        case 'flow_map':
            // Who → module → table totals for a time window, plus table → table links
            $minutes = max(1, min(10080, (int)($_GET['minutes'] ?? 60)));
            $where = "Kind = 'request' AND LoggedAt >= NOW() - INTERVAL $minutes MINUTE";
            $args = [];
            if (in_array($_GET['source'] ?? '', ['web', 'desktop'], true)) { $where .= ' AND Source = ?'; $args[] = $_GET['source']; }
            $s = $pdo->prepare("SELECT Source, Portal, Page, Module, Level, DurationMs, Tables FROM app_monitor WHERE $where ORDER BY ID DESC LIMIT 20000");
            $s->execute($args);
            $portalName = ['superadmin' => 'Super Admin', 'admin' => 'Admin', 'member' => 'Member', 'auth' => 'Sign in', 'api' => 'API', 'public' => 'Public', 'cron' => 'Scheduled'];
            $who = $modules = $tables = $whoMod = $modTab = $tabTab = [];
            $total = 0;
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $total++;
                $w = $r['Source'] === 'desktop' ? 'Desktop app' : 'Web · ' . ($portalName[$r['Portal']] ?? ucfirst((string)$r['Portal']));
                $m = $r['Module'] ?: amModule($r['Portal'], $r['Page']);
                $bad = $r['Level'] !== 'info';
                $who[$w] = ($who[$w] ?? 0) + 1;
                $modules[$m]['requests'] = ($modules[$m]['requests'] ?? 0) + 1;
                $modules[$m]['errors'] = ($modules[$m]['errors'] ?? 0) + ($bad ? 1 : 0);
                $modules[$m]['ms'] = ($modules[$m]['ms'] ?? 0) + (int)$r['DurationMs'];
                $modules[$m]['pages'][$r['Page']] = ($modules[$m]['pages'][$r['Page']] ?? 0) + 1;
                $k = $w . "\t" . $m;
                $whoMod[$k]['n'] = ($whoMod[$k]['n'] ?? 0) + 1;
                $whoMod[$k]['errors'] = ($whoMod[$k]['errors'] ?? 0) + ($bad ? 1 : 0);
                $reads = $writes = [];
                foreach (($r['Tables'] ? json_decode($r['Tables'], true) : []) ?: [] as $t) {
                    $name = $t['t'];
                    $op = in_array($t['op'], ['read', 'insert', 'update', 'delete'], true) ? $t['op'] : 'read';
                    $n = (int)$t['n'];
                    $tables[$name][$op] = ($tables[$name][$op] ?? 0) + $n;
                    $k = $m . "\t" . $name;
                    $modTab[$k][$op] = ($modTab[$k][$op] ?? 0) + $n;
                    if ($op === 'read') $reads[$name] = true; else $writes[$name] = $op;
                }
                foreach ($writes as $wt => $op) {
                    foreach (array_keys($reads) as $rt) {
                        if ($rt === $wt) continue;
                        $k = $rt . "\t" . $wt;
                        $tabTab[$k] = ($tabTab[$k] ?? 0) + 1;
                    }
                }
            }
            $out = function ($map, $fn) { $o = []; foreach ($map as $k => $v) $o[] = $fn(explode("\t", $k), $v); return $o; };
            $modList = [];
            foreach ($modules as $name => $v) {
                arsort($v['pages']);
                $modList[] = ['name' => $name, 'requests' => $v['requests'], 'errors' => $v['errors'], 'avg_ms' => (int)round($v['ms'] / max(1, $v['requests'])),
                    'pages' => array_slice($v['pages'], 0, 8, true)];
            }
            $tabList = [];
            foreach ($tables as $name => $ops) {
                $tabList[] = ['name' => $name, 'read' => $ops['read'] ?? 0, 'insert' => $ops['insert'] ?? 0, 'update' => $ops['update'] ?? 0, 'delete' => $ops['delete'] ?? 0];
            }
            monJson(['success' => true, 'minutes' => $minutes, 'requests' => $total,
                'who' => array_map(function ($k, $v) { return ['name' => $k, 'requests' => $v]; }, array_keys($who), $who),
                'modules' => $modList, 'tables' => $tabList,
                'who_module' => $out($whoMod, function ($k, $v) { return ['from' => $k[0], 'to' => $k[1], 'n' => $v['n'], 'errors' => $v['errors']]; }),
                'module_table' => $out($modTab, function ($k, $v) { return ['from' => $k[0], 'to' => $k[1], 'read' => $v['read'] ?? 0, 'insert' => $v['insert'] ?? 0, 'update' => $v['update'] ?? 0, 'delete' => $v['delete'] ?? 0]; }),
                'table_table' => $out($tabTab, function ($k, $v) { return ['from' => $k[0], 'to' => $k[1], 'n' => $v]; }),
                'server_time' => $pdo->query('SELECT NOW()')->fetchColumn()]);

        case 'entry':
            $s = $pdo->prepare('SELECT * FROM app_monitor WHERE ID = ?');
            $s->execute([(int)($_GET['id'] ?? 0)]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) monJson(['success' => false, 'error' => 'That entry is no longer in the log.'], 404);
            $related = [];
            if ($row['RequestID']) {
                $r = $pdo->prepare('SELECT ID, Kind, Level, LEFT(Message, 300) AS Message, File, Line FROM app_monitor WHERE RequestID = ? AND ID <> ? ORDER BY ID');
                $r->execute([$row['RequestID'], $row['ID']]);
                $related = $r->fetchAll(PDO::FETCH_ASSOC);
            }
            monJson(['success' => true, 'entry' => $row, 'related' => $related]);

        case 'stats':
            $one = function ($sql) use ($pdo) { return $pdo->query($sql)->fetch(PDO::FETCH_ASSOC); };
            $tiles = $one("SELECT
                    SUM(Kind = 'request' AND LoggedAt >= NOW() - INTERVAL 1 MINUTE) AS req_1m,
                    SUM(Kind = 'request' AND LoggedAt >= NOW() - INTERVAL 1 HOUR) AS req_1h,
                    SUM(Kind = 'request' AND LoggedAt >= NOW() - INTERVAL 1 DAY) AS req_24h,
                    SUM(Kind <> 'request' AND LoggedAt >= NOW() - INTERVAL 1 HOUR) AS err_1h,
                    SUM(Kind <> 'request' AND LoggedAt >= NOW() - INTERVAL 1 DAY) AS err_24h,
                    SUM(Kind <> 'request' AND Level IN ('error', 'fatal') AND LoggedAt >= NOW() - INTERVAL 1 DAY) AS fatal_24h,
                    SUM(Kind = 'client_error' AND LoggedAt >= NOW() - INTERVAL 1 DAY) AS desktop_err_24h,
                    ROUND(AVG(CASE WHEN Kind = 'request' AND LoggedAt >= NOW() - INTERVAL 1 HOUR THEN DurationMs END)) AS avg_ms_1h,
                    SUM(Kind = 'request' AND Source = 'desktop' AND LoggedAt >= NOW() - INTERVAL 1 DAY) AS desktop_req_24h,
                    COUNT(*) AS total_rows, MIN(LoggedAt) AS oldest
                FROM app_monitor");
            $active = $pdo->query("SELECT UserType, UserName, Source, MAX(LoggedAt) AS LastSeen, COUNT(*) AS Requests, SUBSTRING_INDEX(GROUP_CONCAT(Page ORDER BY ID DESC), ',', 1) AS LastPage
                FROM app_monitor WHERE Kind = 'request' AND LoggedAt >= NOW() - INTERVAL 15 MINUTE AND UserName IS NOT NULL
                GROUP BY UserType, UserName, Source ORDER BY LastSeen DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
            $topPages = $pdo->query("SELECT Portal, Page, COUNT(*) AS Hits, ROUND(AVG(DurationMs)) AS AvgMs, MAX(DurationMs) AS MaxMs, SUM(Level <> 'info') AS Problems
                FROM app_monitor WHERE Kind = 'request' AND LoggedAt >= NOW() - INTERVAL 1 DAY GROUP BY Portal, Page ORDER BY Hits DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
            $slow = $pdo->query("SELECT Portal, Page, ROUND(AVG(DurationMs)) AS AvgMs, MAX(DurationMs) AS MaxMs, COUNT(*) AS Hits
                FROM app_monitor WHERE Kind = 'request' AND LoggedAt >= NOW() - INTERVAL 1 DAY GROUP BY Portal, Page HAVING AvgMs > 0 ORDER BY AvgMs DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            $groups = $pdo->query("SELECT MAX(ID) AS LastID, Kind, Level, Source, LEFT(Message, 200) AS Message, File, Line, COUNT(*) AS Times, MIN(LoggedAt) AS FirstSeen, MAX(LoggedAt) AS LastSeen,
                    SUBSTRING_INDEX(GROUP_CONCAT(Page ORDER BY ID DESC SEPARATOR '\n'), '\n', 1) AS LastPage
                FROM app_monitor WHERE Kind <> 'request' AND LoggedAt >= NOW() - INTERVAL 7 DAY
                GROUP BY Kind, Level, Source, LEFT(Message, 200), File, Line ORDER BY LastSeen DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
            $perMinute = $pdo->query("SELECT DATE_FORMAT(LoggedAt, '%H:%i') AS Minute, SUM(Kind = 'request') AS Requests, SUM(Kind <> 'request') AS Errors
                FROM app_monitor WHERE LoggedAt >= NOW() - INTERVAL 30 MINUTE GROUP BY DATE_FORMAT(LoggedAt, '%H:%i') ORDER BY Minute")->fetchAll(PDO::FETCH_ASSOC);
            monJson(['success' => true, 'tiles' => array_map(function ($v) { return $v === null ? 0 : $v; }, $tiles ?: []), 'active_users' => $active,
                'top_pages' => $topPages, 'slow_pages' => $slow, 'error_groups' => $groups, 'per_minute' => $perMinute, 'settings' => $settings]);

        case 'client_error':
            $in = json_decode(file_get_contents('php://input'), true);
            if (!is_array($in)) $in = $_POST;
            $level = in_array($in['level'] ?? '', ['notice', 'warning', 'error', 'fatal'], true) ? $in['level'] : 'error';
            amRecord('client_error', $level, (string)($in['message'] ?? 'Unknown desktop error'), $in['file'] ?? null, $in['line'] ?? null, $in['trace'] ?? null,
                ['Source' => 'desktop', 'Portal' => 'desktop', 'Page' => mb_substr('desktop: ' . preg_replace('/[^\w\s\-.:\/()]/u', '', (string)($in['page'] ?? '')) . (!empty($in['version']) ? ' (v' . preg_replace('/[^\w.\-]/', '', (string)$in['version']) . ')' : ''), 0, 200)]);
            monJson(['success' => true]);

        case 'settings':
            $values = [
                'monitor_enabled' => !empty($_POST['monitor_enabled']) && $_POST['monitor_enabled'] !== '0' ? '1' : '0',
                'monitor_log_requests' => !empty($_POST['monitor_log_requests']) && $_POST['monitor_log_requests'] !== '0' ? '1' : '0',
                'monitor_retention_days' => (string)max(1, min(365, (int)($_POST['monitor_retention_days'] ?? 7))),
            ];
            foreach ($values as $k => $v) {
                $s = $pdo->prepare('SELECT COUNT(*) FROM system_settings WHERE SettingKey = ?');
                $s->execute([$k]);
                if ($s->fetchColumn()) {
                    $pdo->prepare('UPDATE system_settings SET SettingValue = ?, UpdatedBy = ?, UpdatedAt = NOW() WHERE SettingKey = ?')->execute([$v, $_SESSION['superadmin_id'], $k]);
                } else {
                    $pdo->prepare('INSERT INTO system_settings (SettingKey, SettingValue, UpdatedBy, UpdatedAt) VALUES (?, ?, ?, NOW())')->execute([$k, $v, $_SESSION['superadmin_id']]);
                }
            }
            $pdo->exec('DELETE FROM app_monitor WHERE LoggedAt < NOW() - INTERVAL ' . (int)$values['monitor_retention_days'] . ' DAY');
            monJson(['success' => true, 'message' => 'Live Monitor settings saved.', 'settings' => $values]);

        case 'clear':
            $what = $_POST['what'] ?? 'older';
            if ($what === 'all') $n = $pdo->exec('DELETE FROM app_monitor');
            elseif ($what === 'requests') $n = $pdo->exec("DELETE FROM app_monitor WHERE Kind = 'request'");
            elseif ($what === 'errors') $n = $pdo->exec("DELETE FROM app_monitor WHERE Kind <> 'request'");
            else $n = $pdo->exec('DELETE FROM app_monitor WHERE LoggedAt < NOW() - INTERVAL ' . max(1, min(365, (int)($_POST['days'] ?? 1))) . ' DAY');
            monJson(['success' => true, 'message' => $n . ' log entr' . ($n === 1 ? 'y' : 'ies') . ' removed.']);

        default:
            monJson(['success' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    error_log('Monitor API: ' . $e->getMessage());
    monJson(['success' => false, 'error' => 'The monitor could not answer: ' . $e->getMessage()], 500);
}
