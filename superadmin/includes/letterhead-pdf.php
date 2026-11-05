<?php
/**
 * Shared letterhead PDF (FPDF) used by the Distribution Statement and the
 * Custom Report Builder, so every generated report carries the same letterhead.
 *
 * Requires: config.php ($pdo, UPLOADS_PATH), includes/logo-helper.php, superadmin/fpdf.php
 */

require_once __DIR__ . '/report-format.php';
require_once __DIR__ . '/letterhead-store.php';

if (!function_exists('resolveLetterheadLogo')) {
    /**
     * Resolve the uploaded logo setting (stored as a URL path) to a local image file FPDF can embed
     */
    function resolveLetterheadLogo() {
        foreach (['website', 'system'] as $type) {
            $logo = $type === 'website' ? getWebsiteLogo() : getSystemLogo();
            if (empty($logo)) {
                continue;
            }
            $path = UPLOADS_PATH . '/logos/' . basename(parse_url($logo, PHP_URL_PATH) ?? '');
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif'], true) && is_file($path) && @getimagesize($path)) {
                return $path;
            }
        }
        return null;
    }
}

if (!function_exists('letterheadOrganisation')) {
    /**
     * Organisation details printed on the letterhead (from system settings)
     */
    function letterheadOrganisation() {
        $siteEmail = getSystemSetting('site_email', '');
        $sitePhone = getSystemSetting('site_phone', '');
        $contactParts = [];
        if ($sitePhone) $contactParts[] = 'Phone: ' . $sitePhone;
        if ($siteEmail) $contactParts[] = 'Email: ' . $siteEmail;
        return [
            'name' => getSiteName('SR Colony Chitti'),
            'tagline' => 'Transparent  |  Organized  |  Efficient  |  Secure',
            'address' => getSystemSetting('site_address', 'SR Colony, Yerraguntlepalli, Pileru, Andhra Pradesh'),
            'contact' => implode('   |   ', $contactParts),
        ];
    }
}

class LetterheadPDF extends FPDF
{
    // Brand palette
    const INDIGO = [49, 46, 129];
    const INDIGO_SOFT = [238, 240, 255];
    const GOLD = [196, 154, 44];
    const INK = [30, 41, 59];
    const MUTED = [100, 116, 139];
    const ROW_ALT = [248, 249, 253];
    const BORDER = [214, 219, 232];
    const GROUP_BG = [246, 241, 225];

    public $org = [];
    public $logoPath = null;
    /** Letterhead design (see letterhead-store.php); colours below follow it */
    public $lh = null;
    public $primary = [49, 46, 129];
    public $accent = [196, 154, 44];
    public $primarySoft = [238, 240, 255];
    public $groupBg = [246, 241, 225];
    public $docTitle = 'REPORT';
    public $compactTitle = 'Report';
    public $periodText = '';
    /** @var array Rows of [leftLabel, leftValue, rightLabel, rightValue] printed under the title */
    public $details = [];
    public $refNo = '';
    public $issueDate = '';
    public $generatedAt = '';
    public $footerNote = 'Computer-generated statement';
    /** @var array Columns: ['label' => , 'width' => mm, 'align' => L|C|R] */
    public $columns = [];
    public $repeatTableHeader = false;
    public $bodyFontSize = 8.5;
    public $zebra = true;

    /** Usable width between the margins */
    function contentWidth()
    {
        return $this->w - $this->lMargin - $this->rMargin;
    }

    function Header()
    {
        if ($this->PageNo() == 1) {
            $this->letterhead();
        } else {
            $this->compactHeader();
        }
        if ($this->repeatTableHeader) {
            $this->tableHeader();
        }
    }

    /**
     * Use a letterhead design (from letterhead-store.php) for this document
     */
    function applyLetterhead(array $lh)
    {
        $this->lh = $lh;
        $this->org = [
            'name' => $lh['name'],
            'tagline' => $lh['tagline'],
            'address' => $lh['address'],
            'extra' => $lh['extra'],
            'contact' => rbLetterheadContact($lh),
        ];
        $this->logoPath = rbLetterheadLogoPath($lh);
        $this->primary = rbHexRgb($lh['primary']);
        $this->accent = rbHexRgb($lh['accent']);
        $this->primarySoft = rbTint($this->primary, 0.9);
        $this->groupBg = rbTint($this->accent, 0.85);
    }

    /** A letterhead option, falling back to the original design */
    protected function opt($key, $default)
    {
        return $this->lh[$key] ?? $default;
    }

    function Footer()
    {
        $left = $this->lMargin;
        $right = $this->w - $this->rMargin;
        $this->SetY(-17);
        $this->SetDrawColor(...$this->accent);
        $this->SetLineWidth(0.3);
        $this->Line($left, $this->GetY(), $right, $this->GetY());
        $this->Ln(1.5);
        $this->SetFont('Helvetica', 'I', 7.5);
        $this->SetTextColor(...self::MUTED);
        $this->Cell($this->contentWidth() - 60, 5, pdfText($this->footerNote . '  |  Ref: ' . $this->refNo . '  |  Generated: ' . $this->generatedAt), 0, 0, 'L');
        $this->SetFont('Helvetica', 'B', 7.5);
        $this->Cell(60, 5, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');

        if ($this->opt('bands', true)) {
            $this->SetFillColor(...$this->accent);
            $this->Rect(0, $this->h - 5, $this->w, 0.8, 'F');
            $this->SetFillColor(...$this->primary);
            $this->Rect(0, $this->h - 4.2, $this->w, 4.2, 'F');
        }
    }

    /**
     * Full letterhead for the first page
     */
    protected function letterhead()
    {
        $left = $this->lMargin;
        $right = $this->w - $this->rMargin;
        $bands = $this->opt('bands', true);

        if ($bands) {
            $this->SetFillColor(...$this->primary);
            $this->Rect(0, 0, $this->w, 5, 'F');
            $this->SetFillColor(...$this->accent);
            $this->Rect(0, 5, $this->w, 1, 'F');
        }

        $top = $bands ? 12 : 9;
        $position = $this->opt('logo_position', 'left');
        $boxH = (float)$this->opt('logo_height', 22);
        $logoW = 0;
        $logoH = 0;
        if ($this->logoPath) {
            try {
                [$iw, $ih] = getimagesize($this->logoPath);
                $maxW = $position === 'top' ? 90 : 58;
                $logoH = $boxH;
                $logoW = $logoH * $iw / $ih;
                if ($logoW > $maxW) { // keep wide wordmarks in proportion
                    $logoW = $maxW;
                    $logoH = $logoW * $ih / $iw;
                }
                $logoX = $position === 'right' ? $right - $logoW : ($position === 'top' ? ($this->w - $logoW) / 2 : $left);
                $logoY = $position === 'top' ? $top : $top + ($boxH - $logoH) / 2;
                $this->Image($this->logoPath, $logoX, $logoY, $logoW, $logoH);
            } catch (Exception $e) {
                error_log('Letterhead logo could not be embedded: ' . $e->getMessage());
                $logoW = 0;
                $logoH = 0;
            }
        }

        // Where the organisation block goes, and how it is aligned
        $hasLogo = $logoW > 0;
        if ($hasLogo && $position === 'left') {
            $textX = $left + $logoW + 6;
            $textW = $right - $textX;
            $autoAlign = 'R';
        } elseif ($hasLogo && $position === 'right') {
            $textX = $left;
            $textW = $this->contentWidth() - $logoW - 6;
            $autoAlign = 'L';
        } else {
            $textX = $left;
            $textW = $this->contentWidth();
            $autoAlign = 'C';
        }
        $align = ['left' => 'L', 'center' => 'C', 'right' => 'R'][$this->opt('align', 'auto')] ?? $autoAlign;
        $textTop = ($hasLogo && $position === 'top') ? $top + $logoH + 3 : $top;

        // Organisation name: shrink to fit the space if it is long
        $name = (string)($this->org['name'] ?? '');
        if ($this->opt('name_upper', true)) $name = mb_strtoupper($name);
        $size = (float)$this->opt('name_size', 19);
        $this->SetFont('Helvetica', 'B', $size);
        while ($size > 10 && $this->GetStringWidth(pdfText($name)) > $textW - 2) {
            $size -= 0.5;
            $this->SetFont('Helvetica', 'B', $size);
        }
        $this->SetXY($textX, $textTop);
        $this->SetTextColor(...$this->primary);
        $this->Cell($textW, $size * 0.47, pdfText($name), 0, 2, $align);

        if (($this->org['tagline'] ?? '') !== '') {
            $this->SetFont('Helvetica', 'I', 8.5);
            $this->SetTextColor(...$this->accent);
            $this->Cell($textW, 5, pdfText($this->org['tagline']), 0, 2, $align);
        }
        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(...self::MUTED);
        foreach (['address', 'extra', 'contact'] as $line) {
            if (($this->org[$line] ?? '') !== '') {
                $this->Cell($textW, 4.5, pdfText($this->org[$line]), 0, 2, $align);
            }
        }

        // Rule under the letterhead
        $y = max($this->GetY(), ($hasLogo && $position !== 'top') ? $top + $boxH : 0) + 3;
        $this->SetDrawColor(...$this->primary);
        if ($this->opt('double_rule', true)) {
            $this->SetLineWidth(0.8);
            $this->Line($left, $y, $right, $y);
            $this->SetDrawColor(...$this->accent);
            $this->SetLineWidth(0.3);
            $this->Line($left, $y + 1.3, $right, $y + 1.3);
        } else {
            $this->SetLineWidth(0.4);
            $this->Line($left, $y, $right, $y);
        }

        // Document title
        $this->SetXY($left, $y + 5);
        $this->SetTextColor(...$this->primary);
        $this->SetFont('Helvetica', 'B', 13);
        if ($this->opt('title_style', 'band') === 'band') {
            $this->SetFillColor(...$this->primarySoft);
            $this->Cell(0, 10, pdfText(strtoupper($this->docTitle)), 0, 1, 'C', true);
            $this->SetFillColor(...$this->accent);
            $this->Rect($left, $this->GetY(), $this->contentWidth(), 0.6, 'F');
        } else {
            $this->Cell(0, 8, pdfText(strtoupper($this->docTitle)), 0, 1, 'C');
            $w = min($this->contentWidth(), $this->GetStringWidth(pdfText(strtoupper($this->docTitle))) + 20);
            $this->SetFillColor(...$this->accent);
            $this->Rect(($this->w - $w) / 2, $this->GetY(), $w, 0.6, 'F');
        }
        $this->Ln(3);

        // Statement details
        $this->SetTextColor(...self::INK);
        foreach ($this->details as $row) {
            $this->detailPair($row[0] ?? '', $row[1] ?? '', $row[2] ?? '', $row[3] ?? '');
        }
        $this->Ln(3);
    }
    protected function detailPair($leftLabel, $leftValue, $rightLabel, $rightValue)
    {
        $cw = $this->contentWidth();
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(30, 5.5, pdfText($leftLabel), 0, 0, 'L');
        $this->SetFont('Helvetica', '', 9);
        $this->Cell($cw - 110, 5.5, pdfText($leftValue), 0, 0, 'L');
        $this->SetFont('Helvetica', 'B', 9);
        $this->Cell(25, 5.5, pdfText($rightLabel), 0, 0, 'L');
        $this->SetFont('Helvetica', '', 9);
        $this->Cell(55, 5.5, pdfText($rightValue), 0, 1, 'R');
    }

    /**
     * Slim header for continuation pages
     */
    protected function compactHeader()
    {
        $left = $this->lMargin;
        $right = $this->w - $this->rMargin;
        $half = $this->contentWidth() / 2;

        if ($this->opt('bands', true)) {
            $this->SetFillColor(...$this->primary);
            $this->Rect(0, 0, $this->w, 3, 'F');
            $this->SetFillColor(...$this->accent);
            $this->Rect(0, 3, $this->w, 0.6, 'F');
        }

        $name = (string)($this->org['name'] ?? '');
        if ($this->opt('name_upper', true)) $name = mb_strtoupper($name);
        $this->SetXY($left, 8);
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(...$this->primary);
        $this->Cell($half, 6, pdfText($name), 0, 0, 'L');
        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(...self::MUTED);
        $this->Cell($half, 6, pdfText($this->compactTitle . ($this->periodText !== '' ? '  |  ' . $this->periodText : '')), 0, 1, 'R');
        $this->SetDrawColor(...$this->primary);
        $this->SetLineWidth(0.4);
        $this->Line($left, $this->GetY() + 1, $right, $this->GetY() + 1);
        $this->Ln(4);
    }
    function tableHeader()
    {
        $this->SetFont('Helvetica', 'B', $this->bodyFontSize);
        $this->SetTextColor(255, 255, 255);
        $this->SetFillColor(...$this->primary);
        $this->SetDrawColor(...$this->primary);
        $this->SetLineWidth(0.2);
        $labels = array_map('pdfText', array_column($this->columns, 'label'));
        $this->drawRow($labels, array_fill(0, count($labels), 'C'), true, $this->bodyFontSize * 0.5, false);
        $this->SetTextColor(...self::INK);
    }

    /**
     * Draw one data row whose cells wrap and are vertically centred
     */
    function dataRow(array $cells, bool $shaded)
    {
        $this->SetFont('Helvetica', '', $this->bodyFontSize);
        $this->SetTextColor(...self::INK);
        $this->SetDrawColor(...self::BORDER);
        $this->SetFillColor(...(($shaded && $this->zebra) ? self::ROW_ALT : [255, 255, 255]));
        $this->drawRow($cells, array_column($this->columns, 'align'), true, $this->bodyFontSize * 0.52, true);
    }

    /**
     * Group heading row spanning the full table width
     */
    function groupRow(string $label)
    {
        $h = $this->bodyFontSize * 0.75 + 2;
        if ($this->GetY() + $h * 2 > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
        $this->SetFont('Helvetica', 'B', $this->bodyFontSize + 0.5);
        $this->SetFillColor(...$this->groupBg);
        $this->SetDrawColor(...self::BORDER);
        $this->SetTextColor(...$this->primary);
        $this->Cell(array_sum(array_column($this->columns, 'width')), $h, pdfText($label), 1, 1, 'L', true);
        $this->SetTextColor(...self::INK);
    }

    /**
     * Totals / subtotals row: $cells uses '' for columns without a value
     */
    function totalRow(array $cells, bool $grand)
    {
        $h = $this->bodyFontSize * 0.75 + ($grand ? 3 : 2);
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
        $this->SetFont('Helvetica', 'B', $this->bodyFontSize + ($grand ? 0.5 : 0));
        $this->SetFillColor(...($grand ? $this->primarySoft : [250, 248, 240]));
        $this->SetDrawColor(...($grand ? $this->primary : self::BORDER));
        $this->SetTextColor(...$this->primary);
        foreach ($this->columns as $i => $col) {
            $this->Cell($col['width'], $h, pdfText($cells[$i] ?? ''), 1, 0, $col['align'], true);
        }
        $this->Ln($h);
        $this->SetTextColor(...self::INK);
    }

    function drawRow(array $cells, array $aligns, bool $fill, float $lineH, bool $allowBreak)
    {
        $widths = array_column($this->columns, 'width');
        $lines = [];
        foreach ($cells as $i => $text) {
            $lines[$i] = $this->nbLines($widths[$i], $text);
        }
        $h = max(max($lines) * $lineH, $lineH + 0.6) + 1.6;

        if ($allowBreak && $this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }

        $x0 = $this->GetX();
        $y0 = $this->GetY();
        foreach ($cells as $i => $text) {
            $x = $this->GetX();
            $this->Rect($x, $y0, $widths[$i], $h, $fill ? 'DF' : 'D');
            $this->SetXY($x, $y0 + ($h - $lines[$i] * $lineH) / 2);
            $this->MultiCell($widths[$i], $lineH, $text, 0, $aligns[$i]);
            $this->SetXY($x + $widths[$i], $y0);
        }
        $this->SetXY($x0, $y0 + $h);
    }

    /**
     * Summary tiles: [[label, value], ...] across the content width
     */
    function summaryTiles(array $tiles)
    {
        if (!$tiles) {
            return;
        }
        $gap = 2;
        $count = count($tiles);
        $tileW = ($this->contentWidth() - $gap * ($count - 1)) / $count;
        if ($this->GetY() + 20 > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
        $x = $this->lMargin;
        $y = $this->GetY();
        foreach ($tiles as [$label, $value]) {
            $this->SetFillColor(...$this->primarySoft);
            $this->Rect($x, $y, $tileW, 17, 'F');
            $this->SetFillColor(...$this->accent);
            $this->Rect($x, $y, 1.2, 17, 'F');
            $this->SetXY($x + 3, $y + 2.5);
            $this->SetFont('Helvetica', '', 7.5);
            $this->SetTextColor(...self::MUTED);
            $this->Cell($tileW - 4, 4.5, pdfText(strtoupper($label)), 0, 2, 'L');
            $this->SetFont('Helvetica', 'B', $count > 4 ? 9.5 : 11);
            $this->SetTextColor(...$this->primary);
            $this->Cell($tileW - 4, 7, pdfText($value), 0, 0, 'L');
            $x += $tileW + $gap;
        }
        $this->SetXY($this->lMargin, $y + 21);
        $this->SetTextColor(...self::INK);
    }

    /**
     * "Calculations" box: [[label, value], ...] as a two-column ruled list
     */
    function calculationBlock(array $lines)
    {
        if (!$lines) {
            return;
        }
        $rowH = 6.5;
        $labelW = min(110, $this->contentWidth() * 0.62);
        $valueW = 60;
        $boxW = $labelW + $valueW;
        if ($this->GetY() + 10 + $rowH * min(count($lines), 4) > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
        $x = $this->lMargin;
        $this->SetX($x);
        $this->SetFont('Helvetica', 'B', 9.5);
        $this->SetTextColor(...$this->primary);
        $this->SetFillColor(...$this->primarySoft);
        $this->SetDrawColor(...$this->accent);
        $this->Cell($boxW, 7, '  CALCULATIONS', 'B', 1, 'L', true);
        foreach ($lines as $i => [$label, $value]) {
            if ($this->GetY() + $rowH > $this->PageBreakTrigger) {
                $this->AddPage($this->CurOrientation);
            }
            $this->SetX($x);
            $this->SetFillColor(...($i % 2 ? self::ROW_ALT : [255, 255, 255]));
            $this->SetDrawColor(...self::BORDER);
            $this->SetFont('Helvetica', '', 9);
            $this->SetTextColor(...self::INK);
            $this->Cell($labelW, $rowH, '  ' . pdfText($label), 'B', 0, 'L', true);
            $this->SetFont('Helvetica', 'B', 9.5);
            $this->SetTextColor(...$this->primary);
            $this->Cell($valueW, $rowH, pdfText($value) . '  ', 'B', 1, 'R', true);
        }
        $this->SetTextColor(...self::INK);
    }

    /**
     * Signature lines at the left and right of the page
     */
    function signatureBlock(string $leftTitle, string $leftName, string $rightTitle, string $rightName)
    {
        if ($this->GetY() + 34 > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
        $sigY = $this->GetY() + 18;
        $leftX = $this->lMargin + 4;
        $rightX = $this->w - $this->rMargin - 64;
        $this->SetDrawColor(...self::INK);
        $this->SetLineWidth(0.3);
        $this->Line($leftX, $sigY, $leftX + 60, $sigY);
        $this->Line($rightX, $sigY, $rightX + 60, $sigY);
        $this->SetXY($leftX, $sigY + 1.5);
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(...self::INK);
        $this->Cell(60, 5, pdfText($leftTitle), 0, 0, 'C');
        $this->SetX($rightX);
        $this->Cell(60, 5, pdfText($rightTitle), 0, 1, 'C');
        $this->SetFont('Helvetica', '', 7.5);
        $this->SetTextColor(...self::MUTED);
        $this->SetX($leftX);
        $this->Cell(60, 4, pdfText($leftName), 0, 0, 'C');
        $this->SetX($rightX);
        $this->Cell(60, 4, pdfText($rightName), 0, 1, 'C');
    }

    /**
     * Number of lines a MultiCell of width $w will take (standard FPDF technique)
     */
    function nbLines($w, $txt)
    {
        $cw = $this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 500;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }
}
