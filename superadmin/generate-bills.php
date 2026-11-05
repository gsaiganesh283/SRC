<?php
/**
 * Bill Generator output (PDF on the letterhead, or a ZIP of one PDF per bill)
 *
 *   POST action=generate  calculate, save new bills (or reuse / replace / skip already billed ones) and print them
 *   POST action=preview   calculate and print without saving
 *   POST action=print     re-print saved bills: bill_ids[] (or a comma list)
 *
 * Used by bills.php and the desktop app; every request carries the session's rb_token.
 */

ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/logo-helper.php';
require_once 'fpdf.php';
require_once 'includes/bill-lib.php';

/** Readable message page instead of the PDF (status 200 so the desktop app shows this text, not just the HTTP code) */
function billsError($message, $status = 200) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Bill Generator</title></head>'
        . '<body style="font-family:Segoe UI,Arial,sans-serif;background:#f4f5fb;padding:40px;">'
        . '<div style="max-width:560px;margin:auto;background:#fff;border-radius:12px;padding:30px;'
        . 'box-shadow:0 8px 30px rgba(0,0,0,.08);border-top:5px solid #312e81;">'
        . '<h2 style="margin-top:0;color:#312e81;">No bills generated</h2>'
        . '<p style="color:#475569;">' . htmlspecialchars($message) . '</p>'
        . '<a href="bills.php" style="color:#4f46e5;">&larr; Back to Bill Generator</a>'
        . '</div></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    billsError('Open the Bill Generator and choose what to generate.');
}
if (empty($_SESSION['rb_token']) || !hash_equals($_SESSION['rb_token'], (string)($_POST['rb_token'] ?? ''))) {
    billsError('The page expired. Please reload the Bill Generator and try again.');
}

@set_time_limit(300);
$action = in_array($_POST['action'] ?? '', ['generate', 'preview', 'print'], true) ? $_POST['action'] : 'generate';
$preparedBy = $_SESSION['superadmin_name'] ?? $_SESSION['superadmin_username'] ?? 'Super Admin';

try {
    billEnsureTables($pdo);
    $settings = billSettings($pdo);
    $o = billOptions($_POST, $settings);
    $summary = '';

    if ($action === 'print') {
        $bills = billLoad($pdo, $o['bill_ids']);
        if (!$bills) billsError('Choose one or more saved bills to print.');
        billMarkPrinted($pdo, array_column($bills, 'BillID'));
        $summary = 'Re-printed ' . count($bills) . ' bill(s): ' . implode(', ', array_slice(array_column($bills, 'BillNo'), 0, 20));
    } else {
        $built = billBuild($pdo, $o);
        if (!$built['drafts']) {
            billsError('Nothing to bill for ' . BILL_TYPES[$o['type']][0] . ' between ' . billShowDate($o['from']) . ' and ' . billShowDate($o['to'])
                . ($built['skipped'] ? ' (' . $built['skipped'] . ' member(s) had nothing due - tick "Include zero balances" to bill them anyway).' : '.'));
        }
        if ($action === 'preview') {
            $bills = billPreviewRows($built['drafts']);
        } else {
            $saved = billSaveDrafts($pdo, $built['drafts'], $o, $settings, $_SESSION['superadmin_id']);
            if (!$saved['ids']) {
                billsError('All ' . $saved['skipped'] . ' bill(s) were already generated, and "Skip" was chosen for bills that already exist.');
            }
            $bills = billLoad($pdo, $saved['ids']);
            billMarkPrinted($pdo, $saved['ids']);
            $summary = BILL_TYPES[$o['type']][0] . ': ' . $saved['created'] . ' new, ' . $saved['reused'] . ' re-printed, ' . $saved['replaced'] . ' replaced, '
                . $saved['skipped'] . ' skipped (batch ' . $saved['batch'] . ', ' . billShowDate($o['from']) . ' to ' . billShowDate($o['to']) . ')';
            $_SESSION['bills_success'] = $saved['created'] . ' new bill(s) saved' . ($saved['reused'] ? ', ' . $saved['reused'] . ' existing re-printed' : '')
                . ($saved['replaced'] ? ', ' . $saved['replaced'] . ' replaced' : '') . ($saved['skipped'] ? ', ' . $saved['skipped'] . ' skipped' : '') . '.';
        }
    }

    if ($summary !== '') {
        createAuditLog($_SESSION['superadmin_id'], 'SuperAdmin', 'Bills', $action === 'print' ? 'print_bills' : 'generate_bills', $summary, 'bills');
    }

    $stamp = date('Ymd-His');
    $base = count($bills) === 1 ? billFileName($bills[0]['BillNo'] . '-' . $bills[0]['PartyName']) : billFileName(BILL_TYPES[$bills[0]['BillType']][1] . 's-' . $stamp);

    if ($o['output'] === 'zip' && count($bills) > 1) {
        if (!class_exists('ZipArchive')) {
            billsError('ZIP files need the PHP zip extension, which this server does not have. Choose "One PDF" instead.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'bills');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $used = [];
        foreach ($bills as $bill) {
            $name = billFileName($bill['BillNo'] . '-' . $bill['PartyName']);
            $used[$name] = ($used[$name] ?? 0) + 1;
            $file = $name . ($used[$name] > 1 ? '-' . $used[$name] : '') . '.pdf';
            $zip->addFromString($file, billMakePdf($pdo, [$bill], $o, $settings, $preparedBy)->Output('S'));
        }
        $zip->close();
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $base . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    $pdf = billMakePdf($pdo, $bills, $o, $settings, $preparedBy);
    while (ob_get_level() > 0) ob_end_clean();
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $pdf->Output('I', ($action === 'preview' ? 'preview-' : '') . $base . '.pdf');
    exit;

} catch (InvalidArgumentException $e) {
    billsError($e->getMessage());
} catch (Throwable $e) {
    error_log('Bill generator error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    billsError('Something went wrong while generating the bills: ' . $e->getMessage());
}
