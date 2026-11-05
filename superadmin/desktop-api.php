<?php
/**
 * Desktop app bridge (SR Colony Super Admin Desktop).
 *
 * The desktop app signs in through login.php like a browser, then uses this script to run the
 * real Super Admin pages server-side with the same session:
 *
 *   GET  desktop-api.php?__mode=ping                       -> who is signed in
 *   GET  desktop-api.php?__mode=view&__page=dashboard.php&year=3
 *        runs the page as a normal visit and returns its data variables as JSON
 *   POST desktop-api.php?__mode=post&__page=manage-members.php   (form fields / files as the page expects)
 *        runs the page's own save logic and returns its result ($success / $error / redirect / JSON)
 *
 * Nothing is reimplemented here, so the desktop app gets exactly the website's data and behaviour.
 * Only Super Admin pages inside this folder can be run, and only for a signed-in Super Admin.
 */

// Bridge state lives in one array so it never clashes with the page's own variables
$__rbd = ['mode' => $_GET['__mode'] ?? '', 'page' => (string)($_GET['__page'] ?? ''), 'output' => null];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function rbdJson($data, $status = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header_remove();
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    // exit() below would stop the Live Monitor's own shutdown step, so let it record this request now
    if (function_exists('amFlush')) {
        amFlush();
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($__rbd['mode'] === 'ping') {
    // The Report Builder's save/letterhead calls need the same per-session token the web page uses
    if (isset($_SESSION['superadmin_id']) && empty($_SESSION['rb_token'])) {
        $_SESSION['rb_token'] = bin2hex(random_bytes(16));
    }
    rbdJson([
        'rb_token' => isset($_SESSION['superadmin_id']) ? $_SESSION['rb_token'] : null,
        'ok' => true,
        'logged_in' => isset($_SESSION['superadmin_id']),
        'superadmin' => isset($_SESSION['superadmin_id']) ? [
            'id' => $_SESSION['superadmin_id'],
            'username' => $_SESSION['superadmin_username'] ?? '',
            'name' => $_SESSION['superadmin_name'] ?? '',
        ] : null,
        'server_time' => date('Y-m-d H:i:s'),
        'php' => PHP_VERSION,
    ]);
}

if (!isset($_SESSION['superadmin_id'])) {
    rbdJson(['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has expired. Please sign in again.'], 401);
}

// ── Which page ─────────────────────────────────────────────────────────────
$__rbd['base'] = realpath(__DIR__);
$__rbd['file'] = realpath(__DIR__ . '/' . str_replace('\\', '/', $__rbd['page']));
$__rbd['blocked'] = ['desktop-api.php', 'login.php', 'session.php', 'fpdf.php', 'simple-excel-writer.php', 'logout.php'];
if (
    !in_array($__rbd['mode'], ['view', 'post'], true)
    || !$__rbd['file']
    || strpos($__rbd['file'], $__rbd['base'] . DIRECTORY_SEPARATOR) !== 0
    || strtolower(pathinfo($__rbd['file'], PATHINFO_EXTENSION)) !== 'php'
    || in_array(basename($__rbd['file']), $__rbd['blocked'], true)
    || preg_match('#[\\\\/](dbadmin|doc|tutorial|font|makefont|includes)[\\\\/]#i', substr($__rbd['file'], strlen($__rbd['base'])))
) {
    rbdJson(['ok' => false, 'error' => 'bad_page', 'message' => 'That page cannot be opened from the desktop app.'], 400);
}
if ($__rbd['mode'] === 'post' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    rbdJson(['ok' => false, 'error' => 'bad_method', 'message' => 'Saving needs a POST request.'], 405);
}

/** Keep only JSON-safe data (drops PDO objects, statements, closures) and mask secrets */
function rbdClean($v, $depth = 0) {
    if ($depth > 8) return null;
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $item) {
            if (is_object($item) || is_resource($item)) continue;
            if (is_string($k) && rbdSecret($k)) {
                $out[$k] = ($item === null || $item === '') ? '' : '********';
                continue;
            }
            $out[$k] = rbdClean($item, $depth + 1);
        }
        return $out;
    }
    if (is_object($v) || is_resource($v)) return null;
    if (is_float($v) && (is_nan($v) || is_infinite($v))) return null;
    return $v;
}

/** Field names whose values never leave the server (passwords, keys, 2FA / face data) */
function rbdSecret($name) {
    return (bool)preg_match('/pass(word)?|secret|token|api_?key|private_?key|client_?secret|refresh|descriptor|^db_(host|user|name|pass)|credential|geminikey|serviceaccount/i', $name)
        && !preg_match('/^(totp_enabled|face_enabled|require_strong_password|reset_password)$/i', $name);
}

/** Messages shown in the page's alert boxes */
function rbdMessages($html) {
    $found = [];
    if (!is_string($html) || $html === '') return $found;
    if (preg_match_all('#<div[^>]*class="[^"]*\b(alert|message|notification)\b[^"]*\b(alert-)?(success|danger|error|warning|info)\b[^"]*"[^>]*>(.*?)</div>#is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($hit[4]), ENT_QUOTES, 'UTF-8')));
            if ($text !== '') $found[] = ['type' => $hit[3] === 'danger' ? 'error' : $hit[3], 'text' => $text];
        }
    }
    return $found;
}

// Variables that exist before the page runs are not part of its data
$__rbd['before'] = array_keys(get_defined_vars());

register_shutdown_function(function () {
    global $__rbd;
    $html = '';
    while (ob_get_level() > 0) {
        $html = ob_get_clean() . $html;
    }
    $redirect = null;
    foreach (headers_list() as $h) {
        if (stripos($h, 'Location:') === 0) $redirect = trim(substr($h, 9));
    }
    $vars = [];
    foreach ($GLOBALS as $name => $value) {
        if ($name === 'GLOBALS' || $name === '__rbd' || $name[0] === '_' || in_array($name, $__rbd['before'], true)) continue;
        if (in_array($name, ['pdo', 'stmt', 'e', 'superadmin_id', 'docRoot', 'projectPath', 'conn', 'mysqli'], true) || rbdSecret($name)) continue;
        if (is_object($value) || is_resource($value)) continue;
        $vars[$name] = rbdClean($value);
    }
    $trimmed = ltrim($html);
    $json = ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) ? json_decode($trimmed, true) : null;
    // One-time messages the page left for its next visit (the desktop shows them now instead)
    $flash = [];
    foreach (($_SESSION ?? []) as $key => $value) {
        if (preg_match('/(^|_)(success|error|message|msg|flash|notice)$/i', (string)$key) && !rbdSecret((string)$key)) {
            $flash[$key] = rbdClean($value);
            unset($_SESSION[$key]);
        }
    }
    $error = error_get_last();
    $fatal = $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    rbdJson([
        'ok' => !$fatal,
        'page' => $__rbd['page'],
        'vars' => $vars,
        'messages' => rbdMessages($html),
        'redirect' => $redirect,
        'json' => $json,
        'session_flash' => $flash,
        'error' => $fatal ? 'The page stopped with an error: ' . $error['message'] : null,
    ], $fatal ? 500 : 200);
});

// ── Run the page as if it had been visited directly ────────────────────────
foreach (array_keys($_GET) as $k) {
    if (strpos($k, '__') === 0) unset($_GET[$k], $_REQUEST[$k]);
}
$__rbd['script'] = substr(str_replace('\\', '/', $_SERVER['SCRIPT_NAME']), 0, -strlen('desktop-api.php')) . str_replace('\\', '/', substr($__rbd['file'], strlen($__rbd['base']) + 1));
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $__rbd['script'];
$_SERVER['QUERY_STRING'] = http_build_query($_GET);
chdir(dirname($__rbd['file']));
ob_start();
require $__rbd['file'];
