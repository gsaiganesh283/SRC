<?php
/**
 * Transaction Auto-Calc — JSON API
 *
 * GET  ?action=meta                      fields, variables, functions, active config, saved rule-sets
 * GET  ?action=ruleset&id=               one saved rule-set (full config)
 * POST ?action=compute   {inputs, config?}   evaluate the rules for a set of form inputs (read-only)
 * POST ?action=save      {id,name,description,config,active}   save a rule-set   (needs X-RB-Token)
 * POST ?action=set_active {id}                                 make a rule-set active (needs X-RB-Token)
 * POST ?action=delete    {id}                                  delete a rule-set  (needs X-RB-Token)
 *
 * Shares the Report Builder's session + rb_token security model.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../../config/config.php';
require_once '../../includes/functions.php';
require_once '../includes/auto-calc-lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function acJson($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['superadmin_id'])) {
    acJson(['error' => 'Your session has expired. Please log in again.'], 401);
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$userId = $_SESSION['superadmin_id'];

// Write actions need the same per-session token the Report Builder uses
if (in_array($action, ['save', 'set_active', 'delete'], true)) {
    $token = $_SERVER['HTTP_X_RB_TOKEN'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['rb_token']) || !hash_equals($_SESSION['rb_token'], $token)) {
        acJson(['error' => 'Security check failed. Reload the page and try again.'], 403);
    }
}

/** Resolve the module key from GET or POST body, defaulting to transactions. */
function acReqModule($body) {
    $m = $_GET['module'] ?? ($body['module'] ?? 'transactions');
    return isset(acModules()[$m]) ? $m : 'transactions';
}

try {
    switch ($action) {
        case 'meta':
            $module = acReqModule($body);
            $m = acModule($module);
            $variables = [];
            foreach (acVariables($module) as $key => $v) {
                $variables[] = ['key' => $key, 'label' => $v[0], 'type' => $v[1], 'group' => $v[2]];
            }
            $variables = array_merge($variables, acForeignVariables($module)); // cross-module {ref.Field}
            $fields = [];
            foreach (acFields($module) as $key => $f) {
                $fields[] = ['key' => $key, 'label' => $f['label'], 'format' => $f['format']];
            }
            $functions = [];
            foreach (rbFormulaFunctions() as $name => $def) {
                $functions[] = ['name' => $name, 'category' => $def[0], 'syntax' => $def[1], 'description' => $def[2]];
            }
            acJson([
                'ok' => true,
                'module' => $module,
                'modules' => acModuleList(),
                'inputs' => $m['inputs'],
                'fields' => $fields,
                'variables' => $variables,
                'functions' => $functions,
                'default_config' => acDefaultConfig($module),
                'active_config' => acGetActiveConfig($pdo, $module),
                'rulesets' => acListRulesets($pdo, $module),
                'options' => acOptionLists($pdo),
            ]);
            break;

        case 'ruleset':
            $rs = acLoadRuleset($pdo, $_GET['id'] ?? 0);
            if (!$rs) acJson(['error' => 'Rule-set not found.'], 404);
            acJson(['ok' => true, 'ruleset' => $rs]);
            break;

        case 'compute':
            $module = acReqModule($body);
            $inputs = is_array($body['inputs'] ?? null) ? $body['inputs'] : [];
            $config = array_key_exists('config', $body) ? $body['config'] : acGetActiveConfig($pdo, $module);
            $result = acEvaluate($pdo, $module, $config, $inputs);
            acJson(['ok' => true] + $result);
            break;

        case 'save':
            $module = acReqModule($body);
            $id = acSaveRuleset($pdo, [
                'id' => $body['id'] ?? 0,
                'module' => $module,
                'name' => $body['name'] ?? '',
                'description' => $body['description'] ?? '',
                'config' => $body['config'] ?? [],
                'active' => !empty($body['active']),
            ], $userId);
            acJson(['ok' => true, 'id' => $id, 'rulesets' => acListRulesets($pdo, $module)]);
            break;

        case 'set_active':
            acSetActive($pdo, (int)($body['id'] ?? 0));
            acJson(['ok' => true, 'rulesets' => acListRulesets($pdo, acReqModule($body))]);
            break;

        case 'delete':
            acDeleteRuleset($pdo, (int)($body['id'] ?? 0), $userId);
            acJson(['ok' => true, 'rulesets' => acListRulesets($pdo, acReqModule($body))]);
            break;

        default:
            acJson(['error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    acJson(['error' => 'The request failed: ' . $e->getMessage()], 500);
}
