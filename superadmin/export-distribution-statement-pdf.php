<?php
/**
 * Distribution & Interest Statement (PDF on letterhead)
 * Lists every transaction with a distributed or interest amount between two dates.
 * Columns: Member ID | Date | Member Name | Distributed Amount | Interest Amount | Due Date | Remark
 */

// Prevent any output before the PDF
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

/**
 * Show a readable error page (the statement opens in a new browser tab)
 */
function statementError($message, $status = 400) {
    ob_end_clean();
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Statement Error</title></head>'
        . '<body style="font-family:Segoe UI,Arial,sans-serif;background:#f4f5fb;padding:40px;">'
        . '<div style="max-width:520px;margin:auto;background:#fff;border-radius:12px;padding:30px;'
        . 'box-shadow:0 8px 30px rgba(0,0,0,.08);border-top:5px solid #312e81;">'
        . '<h2 style="margin-top:0;color:#312e81;">Unable to generate statement</h2>'
        . '<p style="color:#475569;">' . htmlspecialchars($message) . '</p>'
        . '<a href="net-balance-manager.php" style="color:#4f46e5;">&larr; Back to Net Balance Manager</a>'
        . '</div></body></html>';
    exit;
}

class DistributionStatementPDF extends LetterheadPDF
{
}

// ── Validate the date range ───────────────────────────────────────────────
$startInput = trim($_REQUEST['start_date'] ?? '');
$endInput = trim($_REQUEST['end_date'] ?? '');
$start = DateTime::createFromFormat('!Y-m-d', $startInput);
$end = DateTime::createFromFormat('!Y-m-d', $endInput);

if (!$start || $start->format('Y-m-d') !== $startInput || !$end || $end->format('Y-m-d') !== $endInput) {
    statementError('Please choose a valid From date and To date.');
}
if ($start > $end) {
    statementError('The From date must be on or before the To date.');
}

try {
    // ── Fetch entries ──────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT t.MemberID,
               t.TransactionDate,
               COALESCE(m.MemberName, CONCAT('Member #', t.MemberID)) AS MemberName,
               COALESCE(t.DistributedAmount, 0) AS DistributedAmount,
               COALESCE(t.InterestAmount, 0) AS InterestAmount,
               t.NextMonthDue,
               COALESCE(NULLIF(TRIM(t.Remarks), ''), NULLIF(TRIM(t.Notes), ''), '') AS Remark
        FROM transactions t
        LEFT JOIN members m ON m.MemberID = t.MemberID
        WHERE t.TransactionDate BETWEEN ? AND ?
          AND (COALESCE(t.DistributedAmount, 0) > 0 OR COALESCE(t.InterestAmount, 0) > 0)
        ORDER BY t.TransactionDate ASC, t.MemberID ASC, t.TransactionID ASC
    ");
    $stmt->execute([$start->format('Y-m-d'), $end->format('Y-m-d')]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalDistributed = array_sum(array_column($rows, 'DistributedAmount'));
    $totalInterest = array_sum(array_column($rows, 'InterestAmount'));
    $memberCount = count(array_unique(array_column($rows, 'MemberID')));

    // ── Letterhead details ─────────────────────────────────────────────────
    $pdf = new DistributionStatementPDF('P', 'mm', 'A4');
    $pdf->applyLetterhead(rbGetLetterhead($pdo, 0)); // the default letterhead
    $pdf->docTitle = 'Distribution & Interest Statement';
    $pdf->compactTitle = 'Distribution & Interest Statement';
    $pdf->periodText = $start->format('d M Y') . '  to  ' . $end->format('d M Y');
    $pdf->refNo = 'SRC/DIS/' . date('Ymd-His');
    $pdf->issueDate = date('d M Y');
    $pdf->generatedAt = date('d-m-Y h:i A');
    $pdf->details = [
        ['Statement Period:', $pdf->periodText, 'Ref. No:', $pdf->refNo],
        ['Report Type:', 'Member-wise distribution & interest', 'Date of Issue:', $pdf->issueDate],
    ];
    $pdf->columns = [
        ['label' => 'Member ID', 'width' => 16, 'align' => 'C'],
        ['label' => 'Date', 'width' => 21, 'align' => 'C'],
        ['label' => 'Member Name', 'width' => 44, 'align' => 'L'],
        ['label' => 'Distributed Amount (Rs.)', 'width' => 29, 'align' => 'R'],
        ['label' => 'Interest Amount (Rs.)', 'width' => 26, 'align' => 'R'],
        ['label' => 'Due Date', 'width' => 21, 'align' => 'C'],
        ['label' => 'Remark', 'width' => 33, 'align' => 'L'],
    ];
    $pdf->SetTitle('Distribution & Interest Statement');
    $pdf->SetAuthor(pdfText($pdf->org['name']));
    $pdf->SetCreator('SR Colony Chitti Management System');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 22);
    $pdf->AliasNbPages();
    $pdf->AddPage();

    // ── Table ──────────────────────────────────────────────────────────────
    $pdf->tableHeader();
    $pdf->repeatTableHeader = true;

    if (empty($rows)) {
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->SetTextColor(...DistributionStatementPDF::MUTED);
        $pdf->SetDrawColor(...DistributionStatementPDF::BORDER);
        $pdf->Cell(190, 12, 'No distribution or interest entries were found for this period.', 1, 1, 'C');
    } else {
        foreach ($rows as $i => $row) {
            $pdf->dataRow([
                (string)$row['MemberID'],
                date('d-m-Y', strtotime($row['TransactionDate'])),
                pdfText($row['MemberName']),
                $row['DistributedAmount'] > 0 ? formatIndianAmount($row['DistributedAmount']) : '-',
                $row['InterestAmount'] > 0 ? formatIndianAmount($row['InterestAmount']) : '-',
                !empty($row['NextMonthDue']) && $row['NextMonthDue'] !== '0000-00-00' ? date('d-m-Y', strtotime($row['NextMonthDue'])) : '-',
                pdfText(preg_replace('/\s+/', ' ', $row['Remark'])) ?: '-',
            ], $i % 2 === 1);
        }

        // Totals row
        if ($pdf->GetY() + 9 > 275) {
            $pdf->AddPage();
        }
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(...$pdf->primarySoft);
        $pdf->SetDrawColor(...$pdf->primary);
        $pdf->SetTextColor(...$pdf->primary);
        $pdf->Cell(81, 9, 'TOTAL', 1, 0, 'R', true);
        $pdf->Cell(29, 9, formatIndianAmount($totalDistributed), 1, 0, 'R', true);
        $pdf->Cell(26, 9, formatIndianAmount($totalInterest), 1, 0, 'R', true);
        $pdf->Cell(54, 9, '', 1, 1, 'C', true);
    }
    $pdf->repeatTableHeader = false;

    // ── Summary tiles + signatures (kept together on one page) ──────────────
    if ($pdf->GetY() + 62 > 275) {
        $pdf->AddPage();
    }
    $pdf->Ln(7);
    $tiles = [
        ['Total Entries', (string)count($rows)],
        ['Members', (string)$memberCount],
        ['Total Distributed', 'Rs. ' . formatIndianAmount($totalDistributed)],
        ['Total Interest', 'Rs. ' . formatIndianAmount($totalInterest)],
    ];
    $tileW = [32, 32, 61, 59];
    $x = 10;
    $y = $pdf->GetY();
    foreach ($tiles as $i => [$label, $value]) {
        $pdf->SetFillColor(...$pdf->primarySoft);
        $pdf->Rect($x, $y, $tileW[$i], 17, 'F');
        $pdf->SetFillColor(...$pdf->accent);
        $pdf->Rect($x, $y, 1.2, 17, 'F');
        $pdf->SetXY($x + 3, $y + 2.5);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->SetTextColor(...DistributionStatementPDF::MUTED);
        $pdf->Cell($tileW[$i] - 4, 4.5, strtoupper($label), 0, 2, 'L');
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(...$pdf->primary);
        $pdf->Cell($tileW[$i] - 4, 7, $value, 0, 0, 'L');
        $x += $tileW[$i] + 2;
    }

    $pdf->SetXY(10, $y + 21);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(...DistributionStatementPDF::INK);
    $pdf->Cell(0, 6, 'Total Payable (Distributed + Interest):  Rs. ' . formatIndianAmount($totalDistributed + $totalInterest), 0, 1, 'L');

    // Signature block
    $sigY = $pdf->GetY() + 18;
    $pdf->SetDrawColor(...DistributionStatementPDF::INK);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(14, $sigY, 74, $sigY);
    $pdf->Line(136, $sigY, 196, $sigY);
    $pdf->SetXY(14, $sigY + 1.5);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(60, 5, 'Prepared By', 0, 0, 'C');
    $pdf->SetX(136);
    $pdf->Cell(60, 5, 'Authorised Signatory', 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(...DistributionStatementPDF::MUTED);
    $pdf->SetX(14);
    $pdf->Cell(60, 4, pdfText($_SESSION['superadmin_name'] ?? $_SESSION['superadmin_username'] ?? 'Super Admin'), 0, 0, 'C');
    $pdf->SetX(136);
    $pdf->Cell(60, 4, pdfText('For ' . $pdf->org['name']), 0, 1, 'C');

    createAuditLog(
        $_SESSION['superadmin_id'],
        'SuperAdmin',
        'Net Balance',
        'export_distribution_statement',
        'Distribution statement PDF ' . $start->format('Y-m-d') . ' to ' . $end->format('Y-m-d') . ' (' . count($rows) . ' entries)',
        'transactions'
    );

    $filename = 'distribution-statement-' . $start->format('Ymd') . '-to-' . $end->format('Ymd') . '.pdf';
    ob_end_clean();
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $pdf->Output('I', $filename);
    exit;

} catch (Exception $e) {
    error_log('Distribution statement PDF error: ' . $e->getMessage());
    statementError('Something went wrong while generating the statement. Please try again.', 500);
}
