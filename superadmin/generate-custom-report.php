<?php
/**
 * Custom Report Builder - output (PDF on letterhead, Excel, CSV)
 * POST: config (JSON) | template_id, format = pdf | xlsx | csv
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
require_once 'includes/letterhead-pdf.php';
require_once 'includes/report-builder-lib.php';

function reportError($message, $status = 400) {
    ob_end_clean();
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Report Error</title></head>'
        . '<body style="font-family:Segoe UI,Arial,sans-serif;background:#f4f5fb;padding:40px;">'
        . '<div style="max-width:520px;margin:auto;background:#fff;border-radius:12px;padding:30px;'
        . 'box-shadow:0 8px 30px rgba(0,0,0,.08);border-top:5px solid #312e81;">'
        . '<h2 style="margin-top:0;color:#312e81;">Unable to generate report</h2>'
        . '<p style="color:#475569;">' . htmlspecialchars($message) . '</p>'
        . '<a href="report-builder.php" style="color:#4f46e5;">&larr; Back to Report Builder</a>'
        . '</div></body></html>';
    exit;
}

// ── Load the report design ────────────────────────────────────────────────
$format = in_array($_REQUEST['format'] ?? 'pdf', ['pdf', 'xlsx', 'csv'], true) ? $_REQUEST['format'] : 'pdf';
$raw = null;
if (!empty($_POST['config'])) {
    $raw = json_decode($_POST['config'], true);
} elseif (!empty($_REQUEST['template_id'])) {
    rbEnsureTemplateTable($pdo);
    $stmt = $pdo->prepare('SELECT Config FROM custom_report_templates WHERE TemplateID = ?');
    $stmt->execute([(int)$_REQUEST['template_id']]);
    $raw = json_decode((string)$stmt->fetchColumn(), true);
}
if (!is_array($raw)) {
    reportError('No report design was received. Please open the report in the Report Builder and try again.');
}

try {
    $cfg = rbNormalizeConfig($raw);
    $built = rbBuildReportSections($pdo, $cfg);
    $sections = $built['sections'];
    $hasColumns = false;
    foreach ($sections as $s) { if (!empty($s['columns'])) { $hasColumns = true; break; } }
    if (!$hasColumns) {
        reportError('Add at least one column to the report.');
    }
    $totalRows = (int)array_sum(array_map(function ($s) { return $s['row_count']; }, $sections));
    $sources = rbSources();
    $letterhead = rbGetLetterhead($pdo, $cfg['letterhead_id']);
    $org = [
        'name' => $letterhead['name'],
        'tagline' => $letterhead['tagline'],
        'address' => trim($letterhead['address'] . ($letterhead['extra'] !== '' ? '   |   ' . $letterhead['extra'] : '')),
        'contact' => rbLetterheadContact($letterhead),
        'letterhead' => $letterhead,
    ];
    $refNo = 'SRC/RPT/' . date('Ymd-His');
    $issueDate = date('d M Y');
    $reportType = $cfg['subtitle'] !== '' ? $cfg['subtitle'] : ($sources[$cfg['source']]['label'] ?? 'Report');
    $baseName = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($cfg['title'])), '-') ?: 'report';
    $fileName = $baseName . '-' . date('Ymd');

    createAuditLog(
        $_SESSION['superadmin_id'],
        'SuperAdmin',
        'Reports',
        'export_custom_report',
        'Custom report "' . $cfg['title'] . '" (' . strtoupper($format) . ', ' . count($sections) . ' table(s), ' . $totalRows . ' rows)',
        null
    );

    if ($format === 'csv') {
        outputCsv($cfg, $sections, $fileName);
    } elseif ($format === 'xlsx') {
        outputExcel($cfg, $sections, $org, $refNo, $issueDate, $reportType, $totalRows, $fileName);
    } else {
        outputPdf($cfg, $sections, $org, $refNo, $issueDate, $reportType, $totalRows, $fileName);
    }
} catch (Throwable $e) {
    error_log('Custom report error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    reportError('Something went wrong while generating the report. Please check the design and try again.', 500);
}

// ── PDF ───────────────────────────────────────────────────────────────────
function outputPdf(array $cfg, array $sections, array $org, $refNo, $issueDate, $reportType, $totalRows, $fileName) {
    $layout = $cfg['layout'];
    $first = $sections[0] ?? ['period' => '', 'filters_text' => ''];
    $multi = count($sections) > 1;
    $pdf = new LetterheadPDF($layout['orientation'], 'mm', 'A4');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 22);
    $pdf->AliasNbPages();
    $pdf->applyLetterhead($org['letterhead']);
    $pdf->docTitle = $cfg['title'];
    $pdf->compactTitle = $cfg['title'];
    $pdf->periodText = $first['period'];
    $pdf->refNo = $refNo;
    $pdf->issueDate = $issueDate;
    $pdf->generatedAt = date('d-m-Y h:i A');
    $pdf->footerNote = $layout['footer_note'];
    $pdf->bodyFontSize = $layout['font_size'];
    $pdf->zebra = $layout['zebra'];
    $pdf->details = [
        ['Report Period:', $first['period'] !== '' ? $first['period'] : 'All dates', 'Ref. No:', $refNo],
        ['Report Type:', $reportType, 'Date of Issue:', $issueDate],
        [$multi ? 'Tables:' : 'Filters:', $multi ? (count($sections) . ' sections') : $first['filters_text'], 'Records:', formatIndianAmount($totalRows, 0)],
    ];

    $contentW = $pdf->contentWidth();
    $pdf->SetTitle(pdfText($cfg['title']));
    $pdf->SetAuthor(pdfText($org['name']));
    $pdf->SetCreator('SR Colony Chitti Management System');
    $pdf->AddPage();

    foreach ($sections as $si => $report) {
        $cols = $report['columns'];
        if (!$cols) continue;

        // Section heading (shown when named, or when there is more than one table)
        $heading = ($report['heading'] ?? '') !== '' ? $report['heading'] : ($multi ? 'Table ' . ($si + 1) : '');
        if ($si > 0) $pdf->Ln(6);
        if ($heading !== '') {
            if ($pdf->GetY() + 16 > $pdf->GetPageHeight() - 22) $pdf->AddPage($layout['orientation']);
            $pdf->SetFont('Helvetica', 'B', 10.5);
            $pdf->SetTextColor(...$pdf->primary);
            $pdf->Cell(0, 6, pdfText($heading), 0, 1, 'L');
            if ($multi && ($report['filters_text'] ?? '') !== '') {
                $pdf->SetFont('Helvetica', 'I', 7.5);
                $pdf->SetTextColor(...LetterheadPDF::MUTED);
                $pdf->Cell(0, 4, pdfText($report['filters_text']), 0, 1, 'L');
            }
            $pdf->Ln(1);
        }

        // Column widths: relative weights scaled to the printable width (per section)
        $weights = array_sum(array_column($cols, 'width')) ?: 1;
        $pdf->columns = array_map(function ($c) use ($contentW, $weights) {
            return ['label' => $c['label'], 'width' => $contentW * $c['width'] / $weights, 'align' => $c['align']];
        }, $cols);

        $pdf->tableHeader();
        $pdf->repeatTableHeader = true;
        if (!$report['row_count']) {
            $pdf->SetFont('Helvetica', 'I', 9);
            $pdf->SetTextColor(...LetterheadPDF::MUTED);
            $pdf->SetDrawColor(...LetterheadPDF::BORDER);
            $pdf->Cell($contentW, 12, 'No records match the selected filters.', 1, 1, 'C');
        }
        $shade = false;
        foreach ($report['items'] as $item) {
            switch ($item['type']) {
                case 'group':
                    $pdf->groupRow($item['label']);
                    $shade = false;
                    break;
                case 'row':
                    $pdf->dataRow(array_map('pdfText', $item['cells']), $shade);
                    $shade = !$shade;
                    break;
                case 'subtotal':
                    $pdf->totalRow($item['cells'], false);
                    break;
                case 'total':
                    $pdf->totalRow($item['cells'], true);
                    break;
            }
        }
        $pdf->repeatTableHeader = false;

        if ($report['truncated']) {
            $pdf->Ln(2);
            $pdf->SetFont('Helvetica', 'I', 8);
            $pdf->SetTextColor(...LetterheadPDF::MUTED);
            $pdf->Cell(0, 5, 'Only the first ' . formatIndianAmount(RB_MAX_ROWS, 0) . ' records are included. Narrow the filters to see the rest.', 0, 1, 'L');
        }
        if ($report['tiles']) {
            $pdf->Ln(7);
            $pdf->summaryTiles($report['tiles']);
        }
        if ($report['calculations']) {
            $pdf->Ln($report['tiles'] ? 1 : 7);
            $pdf->calculationBlock(array_map(function ($c) { return [$c['label'], $c['value']]; }, $report['calculations']));
        }
    }

    if ($layout['notes'] !== '') {
        $pdf->Ln(3);
        if ($pdf->GetY() + 15 > $pdf->GetPageHeight() - 22) {
            $pdf->AddPage($layout['orientation']);
        }
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(...$pdf->primary);
        $pdf->Cell(0, 6, 'Notes', 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->SetTextColor(...LetterheadPDF::INK);
        $pdf->MultiCell(0, 4.6, pdfText($layout['notes']), 0, 'L');
    }

    if ($layout['signatures']) {
        $pdf->signatureBlock(
            $layout['sig_left'],
            $_SESSION['superadmin_name'] ?? $_SESSION['superadmin_username'] ?? 'Super Admin',
            $layout['sig_right'],
            'For ' . $org['name']
        );
    }

    ob_end_clean();
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $pdf->Output('I', $fileName . '.pdf');
    exit;
}

// ── CSV ───────────────────────────────────────────────────────────────────
function outputCsv(array $cfg, array $sections, $fileName) {
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel-friendly UTF-8
    $multi = count($sections) > 1;
    foreach ($sections as $si => $report) {
        if (!$report['columns']) continue;
        if ($si > 0) fputcsv($out, []); // blank line between sections
        $heading = ($report['heading'] ?? '') !== '' ? $report['heading'] : ($multi ? 'Table ' . ($si + 1) : '');
        if ($heading !== '') fputcsv($out, [$heading]);
        fputcsv($out, array_column($report['columns'], 'label'));
        foreach ($report['items'] as $item) {
            if ($item['type'] === 'group') {
                fputcsv($out, [$item['label']]);
                continue;
            }
            $line = [];
            foreach ($report['columns'] as $i => $c) {
                $raw = $item['raw'][$i] ?? '';
                $numeric = in_array($c['format'], ['amount', 'number', 'integer', 'percent'], true);
                // Numbers stay numeric so spreadsheets can total them; everything else as displayed
                $line[] = ($numeric && is_numeric($raw)) ? round((float)$raw, 2) : ($item['cells'][$i] ?? '');
            }
            fputcsv($out, $line);
        }
        if ($report['calculations']) {
            fputcsv($out, []);
            fputcsv($out, ['Calculations']);
            foreach ($report['calculations'] as $c) {
                $numeric = in_array($c['format'], RB_NUMERIC_FORMATS, true) && is_numeric($c['raw']);
                fputcsv($out, [$c['label'], $numeric ? round((float)$c['raw'], 2) : $c['value']]);
            }
        }
    }
    fclose($out);
    exit;
}

// ── Excel ─────────────────────────────────────────────────────────────────
function outputExcel(array $cfg, array $sections, array $org, $refNo, $issueDate, $reportType, $totalRows, $fileName) {
    require_once __DIR__ . '/includes/rb-xlsx-writer.php';

    $x = new RbXlsx();
    $x->sheetName($cfg['title']);
    $x->properties($cfg['title'], $org['name']);
    $x->landscape($cfg['layout']['orientation'] === 'L');
    $first = $sections[0] ?? ['period' => '', 'filters_text' => ''];
    $multi = count($sections) > 1;

    // Colours of the report's letterhead
    $hex = function (array $rgb) { return sprintf('%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]); };
    $primaryRgb = rbHexRgb($org['letterhead']['primary']);
    $accentRgb = rbHexRgb($org['letterhead']['accent']);
    $indigo = $hex($primaryRgb);
    $gold = $hex($accentRgb);
    $soft = $hex(rbTint($primaryRgb, 0.9));
    $groupFill = $hex(rbTint($accentRgb, 0.85));
    $subFill = $hex(rbTint($accentRgb, 0.93));
    $border = 'D6DBE8';
    $nMax = 1;
    foreach ($sections as $s) $nMax = max($nMax, count($s['columns']));
    $n = $nMax;

    $sOrg = $x->style(['bold' => true, 'size' => 16, 'color' => $indigo, 'h' => 'center']);
    $sTag = $x->style(['italic' => true, 'color' => $gold, 'h' => 'center']);
    $sAddr = $x->style(['color' => '64748B', 'h' => 'center']);
    $sRule = $x->style(['fill' => $indigo]);
    $sTitle = $x->style(['bold' => true, 'size' => 12, 'color' => $indigo, 'fill' => $soft, 'h' => 'center', 'v' => 'center']);
    $sSection = $x->style(['bold' => true, 'size' => 11, 'color' => $indigo, 'fill' => $soft, 'h' => 'left', 'v' => 'center']);
    $sLabel = $x->style(['bold' => true]);
    $sValue = $x->style([]);
    $sValueR = $x->style(['h' => 'right']);
    $sHead = $x->style(['bold' => true, 'color' => 'FFFFFF', 'fill' => $indigo, 'h' => 'center', 'v' => 'center', 'wrap' => true, 'border' => $indigo]);
    $sGroup = $x->style(['bold' => true, 'color' => $indigo, 'fill' => $groupFill, 'border' => $border]);
    $numFmt = ['amount' => '#,##0.00', 'number' => '#,##0.##', 'integer' => '#,##0', 'percent' => '0.00"%"', 'date' => 'dd-mm-yyyy'];
    $hMap = ['L' => 'left', 'C' => 'center', 'R' => 'right'];

    // Letterhead block (once)
    $x->set(1, 1, $org['letterhead']['name_upper'] ? mb_strtoupper($org['name']) : $org['name'], $sOrg);
    $x->set(2, 1, $org['tagline'], $sTag);
    $x->set(3, 1, trim($org['address'] . ($org['contact'] ? '   |   ' . $org['contact'] : '')), $sAddr);
    $x->styleRange(4, 1, 4, $n, $sRule);
    $x->rowHeight(4, 4);
    $x->set(5, 1, strtoupper($cfg['title']), $sTitle);
    $x->rowHeight(5, 24);
    foreach ([1, 2, 3, 5] as $r) {
        $x->merge($r, 1, $r, $n);
    }
    $x->rowHeight(1, 24);

    $details = [
        ['Report Period', $first['period'] !== '' ? $first['period'] : 'All dates', 'Ref. No', $refNo],
        ['Report Type', $reportType, 'Date of Issue', $issueDate],
        [$multi ? 'Tables' : 'Filters', $multi ? (count($sections) . ' sections') : $first['filters_text'], 'Records', (string)$totalRows],
    ];
    $row = 7;
    foreach ($details as $d) {
        $x->set($row, 1, $d[0], $sLabel);
        $x->set($row, 2, $d[1], $sValue);
        if ($n >= 4) {
            $x->set($row, $n - 1, $d[2], $sLabel);
            $x->set($row, $n, $d[3], $sValueR);
        } else {
            $x->set($row, 3, $d[2] . ': ' . $d[3], $sValue);
        }
        $row++;
    }
    $row++;

    $firstHeaderRow = null;
    $widthCols = $sections[0]['columns'] ?? [];
    foreach ($sections as $si => $report) {
        $cols = $report['columns'];
        if (!$cols) continue;
        $cn = count($cols);
        if (count($cols) === $nMax && !$widthCols) $widthCols = $cols;

        // Section heading
        if ($si > 0) $row++;
        $heading = ($report['heading'] ?? '') !== '' ? $report['heading'] : ($multi ? 'Table ' . ($si + 1) : '');
        if ($heading !== '') {
            $x->set($row, 1, $heading, $sSection);
            $x->styleRange($row, 2, $row, $cn, $sSection);
            $x->merge($row, 1, $row, max($cn, 1));
            $x->rowHeight($row, 20);
            $row++;
        }

        // Per-column styles for this section
        $cellStyle = [];
        foreach ($cols as $i => $c) {
            $base = ['border' => $border, 'h' => $hMap[$c['align']] ?? 'left', 'v' => 'top', 'wrap' => true];
            if (isset($numFmt[$c['format']])) $base['numFmt'] = $numFmt[$c['format']];
            $cellStyle[$i] = [
                'row' => $x->style($base),
                'zebra' => $x->style($base + ['fill' => 'F8F9FD']),
                'subtotal' => $x->style(array_merge($base, ['bold' => true, 'color' => $indigo, 'fill' => $subFill])),
                'total' => $x->style(array_merge($base, ['bold' => true, 'color' => $indigo, 'fill' => $soft])),
            ];
        }

        // Header
        $headerRow = $row;
        if ($firstHeaderRow === null) $firstHeaderRow = $headerRow;
        foreach ($cols as $i => $c) {
            $x->set($row, $i + 1, $c['label'], $sHead);
        }
        $x->rowHeight($row, 30);
        $row++;
        $zebra = false;
        foreach ($report['items'] as $item) {
            if ($item['type'] === 'group') {
                $x->set($row, 1, $item['label'], $sGroup);
                $x->styleRange($row, 2, $row, $cn, $sGroup);
                $x->merge($row, 1, $row, $cn);
                $row++;
                $zebra = false;
                continue;
            }
            $variant = $item['type'] === 'row' ? (($zebra && $cfg['layout']['zebra']) ? 'zebra' : 'row') : $item['type'];
            foreach ($cols as $i => $c) {
                $raw = $item['raw'][$i] ?? null;
                $style = $cellStyle[$i][$variant];
                if (in_array($c['format'], ['amount', 'number', 'integer', 'percent'], true) && is_numeric($raw)) {
                    $x->set($row, $i + 1, (float)$raw, $style, 'n');
                } elseif ($c['format'] === 'date' && $item['type'] === 'row' && $raw && RbXlsx::excelDate($raw) !== null) {
                    $x->set($row, $i + 1, RbXlsx::excelDate($raw), $style, 'n');
                } else {
                    $x->set($row, $i + 1, (string)($item['cells'][$i] ?? ''), $style, 's');
                }
            }
            if ($item['type'] === 'row') $zebra = !$zebra;
            $row++;
        }

        // Summary tiles for this section
        $row++;
        foreach ($report['tiles'] as [$label, $value]) {
            $x->set($row, 1, $label, $sLabel);
            $x->set($row, 2, $value, $sValue);
            $row++;
        }
        if ($report['calculations']) {
            $row++;
            $sCalcHead = $x->style(['bold' => true, 'color' => $indigo, 'fill' => $soft, 'border' => $gold]);
            $x->set($row, 1, 'CALCULATIONS', $sCalcHead);
            $x->styleRange($row, 2, $row, 2, $sCalcHead);
            $row++;
            $calcFmt = ['amount' => '#,##0.00', 'number' => '#,##0.##', 'integer' => '#,##0', 'percent' => '0.00"%"'];
            foreach ($report['calculations'] as $c) {
                $x->set($row, 1, $c['label'], $x->style(['border' => $border]));
                if (isset($calcFmt[$c['format']]) && is_numeric($c['raw'])) {
                    $x->set($row, 2, (float)$c['raw'], $x->style(['bold' => true, 'color' => $indigo, 'border' => $border, 'numFmt' => $calcFmt[$c['format']]]), 'n');
                } else {
                    $x->set($row, 2, $c['value'], $x->style(['bold' => true, 'color' => $indigo, 'border' => $border, 'h' => 'right']), 's');
                }
                $row++;
            }
        }
    }

    if ($cfg['layout']['notes'] !== '') {
        $row++;
        $x->set($row, 1, 'Notes', $x->style(['bold' => true, 'color' => $indigo]));
        $row++;
        $x->set($row, 1, $cfg['layout']['notes'], $x->style(['wrap' => true, 'v' => 'top']));
        $x->merge($row, 1, $row, $n);
        $x->rowHeight($row, 15 * max(1, substr_count($cfg['layout']['notes'], "\n") + 1));
    }

    // Column widths from the widest section's relative weights
    if (!$widthCols) $widthCols = $sections[0]['columns'] ?? [];
    $weights = array_sum(array_column($widthCols, 'width')) ?: 1;
    $totalChars = $cfg['layout']['orientation'] === 'L' ? 150 : 105;
    foreach ($widthCols as $i => $c) {
        $x->colWidth($i + 1, max(8, $totalChars * $c['width'] / $weights));
    }

    if ($firstHeaderRow !== null) {
        $x->freezeBelow($firstHeaderRow);
        if (!$multi && $totalRows) {
            $x->autoFilter($firstHeaderRow, 1, $firstHeaderRow, $n);
        }
        $x->printTitleRow($firstHeaderRow);
    }
    $x->footer($cfg['layout']['footer_note'] . ' | Ref: ' . $refNo);

    ob_end_clean();
    $x->output($fileName . '.xlsx');
    exit;
}