<?php
/**
 * Custom Report Builder - JSON API
 * GET  ?action=meta | templates | template&id=
 * POST ?action=preview | save | delete | pin   (JSON body; save/delete/pin need the X-RB-Token header)
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../../config/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/logo-helper.php';
require_once '../includes/report-builder-lib.php';
require_once '../includes/letterhead-store.php';

/** Letterheads with their logo address, for the builder */
function rbLetterheadsForUi(PDO $pdo) {
    return array_map(function ($lh) {
        $lh['logo_url'] = rbLetterheadLogoUrl($lh['config']);
        return $lh;
    }, rbListLetterheads($pdo));
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rbJson($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['superadmin_id'])) {
    rbJson(['error' => 'Your session has expired. Please log in again.'], 401);
}

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input'), true) ?: [];

if (in_array($action, ['save', 'delete', 'pin', 'letterhead_save', 'letterhead_delete', 'letterhead_default', 'letterhead_logo'], true)) {
    $token = $_SERVER['HTTP_X_RB_TOKEN'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['rb_token']) || !hash_equals($_SESSION['rb_token'], $token)) {
        rbJson(['error' => 'Security check failed. Reload the page and try again.'], 403);
    }
}

try {
    switch ($action) {
        case 'meta':
            $sources = [];
            foreach (rbSources() as $key => $s) {
                $sources[$key] = [
                    'label' => $s['label'],
                    'group' => $s['group'],
                    'module' => $s['module'] ?? 'Other tables',
                    'curated' => $s['group'] !== 'Application tables',
                    // How other reports refer to this module in formulas, e.g. {expenses.Amount}
                    'ref' => ($key === 'manual' || empty($s['from'])) ? null : rbModuleRef($key),
                    'date_key' => $s['default_date_field'] ?? (function () use ($s) {
                        foreach ($s['fields'] as $fk => $f) if (!empty($s['date']) && $f[1] === $s['date']) return $fk;
                        return null;
                    })(),
                    'rows' => $s['rows'] ?? null,
                    'icon' => $s['icon'],
                    'description' => $s['description'],
                    'fields' => array_map(function ($f) { return ['label' => $f[0], 'type' => $f[2]]; }, $s['fields']),
                    'default_columns' => $s['default_columns'],
                    'date_fields' => array_map(function ($k) use ($s) { return ['key' => $k, 'label' => $s['fields'][$k][0]]; }, array_keys($s['date_fields'] ?? [])),
                    'default_date_field' => $s['default_date_field'] ?? '',
                    'filters' => [
                        'date' => !empty($s['date']) || !empty($s['date_fields']),
                        'year' => !empty($s['year']),
                        'member' => !empty($s['member']),
                        'payment_mode' => !empty($s['payment_mode']),
                        'status' => !empty($s['status']),
                        'category' => !empty($s['category']),
                        'search' => !empty($s['search']),
                    ],
                    'nonzero' => $s['nonzero'] ?? [],
                ];
            }
            $presets = [];
            foreach (rbPresets() as $key => $p) {
                $presets[] = ['key' => $key, 'name' => $p['name'], 'source' => $p['config']['source'], 'config' => $p['config']];
            }
            $col = function ($sql) use ($pdo) {
                try { return $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { return []; }
            };
            rbJson([
                'sources' => $sources,
                'presets' => $presets,
                'years' => $pdo->query('SELECT YearID, YearName, StartDate, EndDate, Status FROM academic_years ORDER BY StartDate DESC')->fetchAll(PDO::FETCH_ASSOC),
                'members' => $pdo->query('SELECT MemberID, MemberName, Status FROM members ORDER BY MemberName')->fetchAll(PDO::FETCH_ASSOC),
                'payment_modes' => array_values(array_unique(array_merge(
                    ['Cash', 'UPI', 'Bank Transfer', 'Cheque', 'Online'],
                    $col("SELECT DISTINCT PaymentMode FROM transactions WHERE PaymentMode <> ''"),
                    $col("SELECT DISTINCT PaymentMode FROM expenses WHERE PaymentMode <> ''")
                ))),
                'statuses' => [
                    'members' => $col("SELECT DISTINCT Status FROM members WHERE Status <> '' ORDER BY Status"),
                    'expenses' => $col("SELECT DISTINCT Status FROM expenses WHERE Status <> '' ORDER BY Status"),
                ],
                'categories' => $col("SELECT DISTINCT Category FROM expenses WHERE Category <> '' ORDER BY Category"),
                'modules' => RB_MODULE_ORDER,
                'letterheads' => rbLetterheadsForUi($pdo),
                'system_logo_url' => rbLetterheadLogoUrl(array_merge(rbLetterheadDefaults(), ['logo' => 'system'])),
                'formats' => RB_FORMATS,
                'totals' => RB_TOTALS,
                'functions' => array_map(function ($name, $f) {
                    return ['name' => $name, 'category' => $f[0], 'syntax' => $f[1], 'description' => $f[2]];
                }, array_keys(rbFormulaFunctions()), rbFormulaFunctions()),
            ]);

        case 'preview':
            $cfg = rbNormalizeConfig($body['config'] ?? []);
            $full = rbBuildReportSections($pdo, $cfg);
            // Keep each section light: first 250 data rows, plus the grand total
            $lighten = function ($report) {
                $items = [];
                $rows = 0;
                $limited = false;
                foreach ($report['items'] as $item) {
                    if ($item['type'] === 'total') continue;
                    if ($item['type'] === 'row' && ++$rows > 250) { $limited = true; break; }
                    unset($item['raw']);
                    $items[] = $item;
                }
                foreach ($report['items'] as $item) {
                    if ($item['type'] === 'total') { unset($item['raw']); $items[] = $item; }
                }
                return [
                    'heading' => $report['heading'] ?? '',
                    'columns' => $report['columns'],
                    'items' => $items,
                    'row_count' => $report['row_count'],
                    'group_count' => $report['group_count'],
                    'tiles' => $report['tiles'],
                    'calculations' => array_map(function ($c) { return ['label' => $c['label'], 'value' => $c['value'], 'index' => $c['index']]; }, $report['calculations']),
                    'issues' => $report['issues'],
                    'period' => $report['period'],
                    'filters_text' => $report['filters_text'] ?? '',
                    'truncated' => $report['truncated'],
                    'preview_limited' => $limited,
                ];
            };
            $sections = array_map($lighten, $full['sections']);
            // section[0] is also mirrored at the top level so older clients keep working
            rbJson(array_merge($sections[0] ?? [], [
                'config' => $cfg,
                'sections' => $sections,
            ]));

        case 'values':
            // Distinct values of one field, to suggest in the condition builder
            $sources = rbSources();
            $def = $sources[$body['source'] ?? ''] ?? null;
            $field = (string)($body['field'] ?? '');
            if (!$def || empty($def['from']) || !isset($def['fields'][$field])) rbJson(['values' => []]);
            $expr = $def['fields'][$field][1];
            $sql = 'SELECT DISTINCT v FROM (SELECT ' . $expr . ' AS v FROM ' . $def['from']
                . (!empty($def['group_sql']) ? ' GROUP BY ' . $def['group_sql'] : '')
                . ") rbv WHERE v IS NOT NULL AND v <> '' ORDER BY v LIMIT 200";
            rbJson(['values' => array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN))]);

        case 'letterheads':
            rbJson(['letterheads' => rbLetterheadsForUi($pdo)]);

        case 'letterhead_save':
            rbEnsureLetterheadTable($pdo);
            $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 100);
            if ($name === '') rbJson(['error' => 'Give the letterhead a name.'], 422);
            $config = json_encode(rbNormalizeLetterhead($body['config'] ?? []), JSON_UNESCAPED_UNICODE);
            $id = (int)($body['id'] ?? 0);
            $exists = false;
            if ($id > 0) {
                $stmt = $pdo->prepare('SELECT 1 FROM report_letterheads WHERE LetterheadID = ?');
                $stmt->execute([$id]);
                $exists = (bool)$stmt->fetchColumn();
            }
            if ($exists) {
                $pdo->prepare('UPDATE report_letterheads SET Name = ?, Config = ?, UpdatedBy = ? WHERE LetterheadID = ?')
                    ->execute([$name, $config, $_SESSION['superadmin_id'], $id]);
            } else {
                // The first letterhead saved becomes the default
                $first = !(int)$pdo->query('SELECT COUNT(*) FROM report_letterheads')->fetchColumn();
                $pdo->prepare('INSERT INTO report_letterheads (Name, Config, IsDefault, UpdatedBy) VALUES (?, ?, ?, ?)')
                    ->execute([$name, $config, $first ? 1 : 0, $_SESSION['superadmin_id']]);
                $id = (int)$pdo->lastInsertId();
            }
            createAuditLog($_SESSION['superadmin_id'], 'SuperAdmin', 'Reports', 'save_letterhead', 'Saved letterhead "' . $name . '"', 'report_letterheads', $id);
            rbJson(['id' => $id, 'letterheads' => rbLetterheadsForUi($pdo)]);

        case 'letterhead_default':
            rbEnsureLetterheadTable($pdo);
            $id = (int)($body['id'] ?? 0);
            $pdo->prepare('UPDATE report_letterheads SET IsDefault = (LetterheadID = ?)')->execute([$id]);
            rbJson(['letterheads' => rbLetterheadsForUi($pdo)]);

        case 'letterhead_delete':
            rbEnsureLetterheadTable($pdo);
            $id = (int)($body['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT Name, IsDefault FROM report_letterheads WHERE LetterheadID = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $pdo->prepare('DELETE FROM report_letterheads WHERE LetterheadID = ?')->execute([$id]);
                if ($row['IsDefault']) {
                    // Keep one default: the oldest remaining letterhead
                    $pdo->exec('UPDATE report_letterheads SET IsDefault = 1 ORDER BY LetterheadID LIMIT 1');
                }
                createAuditLog($_SESSION['superadmin_id'], 'SuperAdmin', 'Reports', 'delete_letterhead', 'Deleted letterhead "' . $row['Name'] . '"', 'report_letterheads', $id);
            }
            rbJson(['letterheads' => rbLetterheadsForUi($pdo)]);

        case 'letterhead_logo':
            // Logo upload: PNG / JPG / GIF up to 2 MB that the PDF library can embed
            $file = $_FILES['logo'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                rbJson(['error' => 'The file did not upload. Please try again.'], 422);
            }
            if ($file['size'] > 2 * 1024 * 1024) rbJson(['error' => 'The logo must be 2 MB or smaller.'], 422);
            $info = @getimagesize($file['tmp_name']);
            $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif'];
            if (!$info || !isset($types[$info[2]])) rbJson(['error' => 'Use a PNG, JPG or GIF image.'], 422);
            $ext = $types[$info[2]];

            $dir = UPLOADS_PATH . '/' . RB_LETTERHEAD_DIR;
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) rbJson(['error' => 'Could not create the logo folder.'], 500);
            $name = 'lh_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $target = $dir . '/' . $name;
            if (!move_uploaded_file($file['tmp_name'], $target)) rbJson(['error' => 'Could not save the logo.'], 500);

            // Make sure the PDF library can actually use it (e.g. interlaced PNGs can't be embedded)
            require_once '../fpdf.php';
            try {
                $test = new FPDF();
                $test->AddPage();
                $test->Image($target, 10, 10, 20);
            } catch (Throwable $e) {
                @unlink($target);
                rbJson(['error' => 'This image cannot be used in PDFs (' . $e->getMessage() . '). Save it again as a JPG, or as a non-interlaced PNG, and upload that.'], 422);
            }
            rbJson(['logo' => 'custom:' . $name, 'url' => UPLOADS_URL . '/' . RB_LETTERHEAD_DIR . '/' . $name]);

        case 'templates':
            rbEnsureTemplateTable($pdo);
            $list = $pdo->query('SELECT TemplateID, Name, Description, Source, IsPinned, UpdatedAt FROM custom_report_templates ORDER BY IsPinned DESC, UpdatedAt DESC')->fetchAll(PDO::FETCH_ASSOC);
            rbJson(['templates' => $list]);

        case 'template':
            rbEnsureTemplateTable($pdo);
            $stmt = $pdo->prepare('SELECT TemplateID, Name, Description, Config FROM custom_report_templates WHERE TemplateID = ?');
            $stmt->execute([(int)($_GET['id'] ?? 0)]);
            $t = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$t) rbJson(['error' => 'That saved report no longer exists.'], 404);
            $t['Config'] = rbNormalizeConfig(json_decode($t['Config'], true));
            rbJson(['template' => $t]);

        case 'save':
            rbEnsureTemplateTable($pdo);
            $name = mb_substr(trim((string)($body['name'] ?? '')), 0, 150);
            if ($name === '') rbJson(['error' => 'Give the report a name.'], 422);
            $description = mb_substr(trim((string)($body['description'] ?? '')), 0, 255);
            $cfg = rbNormalizeConfig($body['config'] ?? []);
            $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);
            $id = (int)($body['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE custom_report_templates SET Name = ?, Description = ?, Source = ?, Config = ?, UpdatedBy = ? WHERE TemplateID = ?');
                $stmt->execute([$name, $description, $cfg['source'], $json, $_SESSION['superadmin_id'], $id]);
                if (!$stmt->rowCount()) {
                    $exists = $pdo->prepare('SELECT 1 FROM custom_report_templates WHERE TemplateID = ?');
                    $exists->execute([$id]);
                    if (!$exists->fetchColumn()) $id = 0;
                }
            }
            if ($id <= 0) {
                $stmt = $pdo->prepare('INSERT INTO custom_report_templates (Name, Description, Source, Config, CreatedBy, UpdatedBy) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $description, $cfg['source'], $json, $_SESSION['superadmin_id'], $_SESSION['superadmin_id']]);
                $id = (int)$pdo->lastInsertId();
            }
            createAuditLog($_SESSION['superadmin_id'], 'SuperAdmin', 'Reports', 'save_report_template', 'Saved custom report "' . $name . '"', 'custom_report_templates', $id);
            rbJson(['id' => $id, 'name' => $name]);

        case 'delete':
            rbEnsureTemplateTable($pdo);
            $id = (int)($body['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT Name FROM custom_report_templates WHERE TemplateID = ?');
            $stmt->execute([$id]);
            $name = $stmt->fetchColumn();
            $pdo->prepare('DELETE FROM custom_report_templates WHERE TemplateID = ?')->execute([$id]);
            if ($name !== false) {
                createAuditLog($_SESSION['superadmin_id'], 'SuperAdmin', 'Reports', 'delete_report_template', 'Deleted custom report "' . $name . '"', 'custom_report_templates', $id);
            }
            rbJson(['deleted' => $name !== false]);

        case 'pin':
            rbEnsureTemplateTable($pdo);
            $stmt = $pdo->prepare('UPDATE custom_report_templates SET IsPinned = 1 - IsPinned WHERE TemplateID = ?');
            $stmt->execute([(int)($body['id'] ?? 0)]);
            rbJson(['ok' => true]);

        default:
            rbJson(['error' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('Report builder API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    rbJson(['error' => 'Something went wrong. Please try again.'], 500);
}
