<?php
/**
 * Minimal dependency-free .xlsx writer for the Custom Report Builder.
 * Pure PHP (no ZipArchive, no PhpSpreadsheet) so Excel export works on any host.
 *
 * Usage: $x = new RbXlsx(); $s = $x->style([...]); $x->set(1, 1, 'Text', $s); ... $x->output('file.xlsx');
 */
class RbXlsx
{
    private $cells = [];        // [row][col] => [value, type, styleId]
    private $styles = [];       // styleId => spec
    private $styleIndex = [];   // json(spec) => styleId
    private $merges = [];
    private $colWidths = [];
    private $rowHeights = [];
    private $freezeRow = 0;
    private $autoFilter = '';
    private $printTitleRow = 0;
    private $landscape = false;
    private $footer = '';
    private $sheetName = 'Report';
    private $title = '';
    private $creator = '';

    public function __construct()
    {
        $this->style([]); // style 0 = default
    }

    /**
     * Register a cell style and return its id.
     * Spec keys: bold, italic, size, color (RRGGBB), fill (RRGGBB), numFmt, h (left|center|right), v (top|center), wrap, border (RRGGBB)
     */
    public function style(array $spec)
    {
        ksort($spec);
        $key = json_encode($spec);
        if (!isset($this->styleIndex[$key])) {
            $this->styleIndex[$key] = count($this->styles);
            $this->styles[] = $spec;
        }
        return $this->styleIndex[$key];
    }

    public function set($row, $col, $value, $style = 0, $type = null)
    {
        if ($type === null) {
            $type = (is_int($value) || is_float($value)) ? 'n' : 's';
        }
        $this->cells[$row][$col] = [$value, $type, $style];
    }

    /** Apply a style to a rectangular range, creating empty cells where needed */
    public function styleRange($r1, $c1, $r2, $c2, $style)
    {
        for ($r = $r1; $r <= $r2; $r++) {
            for ($c = $c1; $c <= $c2; $c++) {
                if (isset($this->cells[$r][$c])) {
                    $this->cells[$r][$c][2] = $style;
                } else {
                    $this->cells[$r][$c] = ['', 's', $style];
                }
            }
        }
    }

    public function merge($r1, $c1, $r2, $c2) { $this->merges[] = self::ref($r1, $c1) . ':' . self::ref($r2, $c2); }
    public function colWidth($col, $width) { $this->colWidths[$col] = $width; }
    public function rowHeight($row, $height) { $this->rowHeights[$row] = $height; }
    public function freezeBelow($row) { $this->freezeRow = $row; }
    public function autoFilter($r1, $c1, $r2, $c2) { $this->autoFilter = self::ref($r1, $c1) . ':' . self::ref($r2, $c2); }
    public function printTitleRow($row) { $this->printTitleRow = $row; }
    public function landscape($on) { $this->landscape = (bool)$on; }
    public function footer($text) { $this->footer = $text; }
    public function sheetName($name) { $this->sheetName = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $name), 0, 31) ?: 'Report'; }
    public function properties($title, $creator) { $this->title = $title; $this->creator = $creator; }

    /** Excel serial date from a Y-m-d (or any strtotime) string */
    public static function excelDate($value)
    {
        $ts = strtotime((string)$value . ' 00:00:00 UTC');
        return $ts === false ? null : 25569 + floor($ts / 86400);
    }

    public static function colLetter($col)
    {
        $s = '';
        while ($col > 0) {
            $m = ($col - 1) % 26;
            $s = chr(65 + $m) . $s;
            $col = intdiv($col - 1, 26);
        }
        return $s;
    }

    public static function ref($row, $col) { return self::colLetter($col) . $row; }

    private static function esc($s)
    {
        // Strip characters that are illegal in XML 1.0
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string)$s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ── Package parts ─────────────────────────────────────────────────────
    private function sheetXml()
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        $x .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0">';
        if ($this->freezeRow > 0) {
            $x .= '<pane ySplit="' . $this->freezeRow . '" topLeftCell="A' . ($this->freezeRow + 1) . '" activePane="bottomLeft" state="frozen"/>';
        }
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if ($this->colWidths) {
            ksort($this->colWidths);
            $x .= '<cols>';
            foreach ($this->colWidths as $c => $w) {
                $x .= '<col min="' . $c . '" max="' . $c . '" width="' . round($w, 2) . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        ksort($this->cells);
        foreach ($this->cells as $r => $cols) {
            ksort($cols);
            $x .= '<row r="' . $r . '"' . (isset($this->rowHeights[$r]) ? ' ht="' . $this->rowHeights[$r] . '" customHeight="1"' : '') . '>';
            foreach ($cols as $c => [$value, $type, $style]) {
                $attr = ' r="' . self::ref($r, $c) . '"' . ($style ? ' s="' . $style . '"' : '');
                if ($type === 'n' && is_numeric($value)) {
                    $x .= '<c' . $attr . '><v>' . (0 + $value) . '</v></c>';
                } elseif ((string)$value === '') {
                    $x .= '<c' . $attr . '/>';
                } else {
                    $x .= '<c' . $attr . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc($value) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($this->autoFilter) {
            $x .= '<autoFilter ref="' . $this->autoFilter . '"/>';
        }
        if ($this->merges) {
            $x .= '<mergeCells count="' . count($this->merges) . '">';
            foreach ($this->merges as $m) $x .= '<mergeCell ref="' . $m . '"/>';
            $x .= '</mergeCells>';
        }
        $x .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="' . ($this->landscape ? 'landscape' : 'portrait') . '" fitToWidth="1" fitToHeight="0"/>';
        if ($this->footer !== '') {
            $x .= '<headerFooter><oddFooter>' . self::esc('&L' . $this->footer . '&RPage &P of &N') . '</oddFooter></headerFooter>';
        }
        return $x . '</worksheet>';
    }

    private function stylesXml()
    {
        $fonts = ['<font><sz val="10"/><name val="Calibri"/></font>'];
        $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>'];
        $numFmts = [];
        $fontIdx = [];
        $fillIdx = [];
        $borderIdx = [];
        $xfs = [];
        foreach ($this->styles as $s) {
            $font = '<font>' . (!empty($s['bold']) ? '<b/>' : '') . (!empty($s['italic']) ? '<i/>' : '')
                . '<sz val="' . ($s['size'] ?? 10) . '"/>' . (!empty($s['color']) ? '<color rgb="FF' . $s['color'] . '"/>' : '')
                . '<name val="Calibri"/></font>';
            if (!isset($fontIdx[$font])) { $fontIdx[$font] = count($fonts); $fonts[] = $font; }
            $fillId = 0;
            if (!empty($s['fill'])) {
                $fill = '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $s['fill'] . '"/><bgColor indexed="64"/></patternFill></fill>';
                if (!isset($fillIdx[$fill])) { $fillIdx[$fill] = count($fills); $fills[] = $fill; }
                $fillId = $fillIdx[$fill];
            }
            $borderId = 0;
            if (!empty($s['border'])) {
                $side = '<%1$s style="thin"><color rgb="FF' . $s['border'] . '"/></%1$s>';
                $border = '<border>' . sprintf($side, 'left') . sprintf($side, 'right') . sprintf($side, 'top') . sprintf($side, 'bottom') . '<diagonal/></border>';
                if (!isset($borderIdx[$border])) { $borderIdx[$border] = count($borders); $borders[] = $border; }
                $borderId = $borderIdx[$border];
            }
            $numFmtId = 0;
            if (!empty($s['numFmt'])) {
                if (!isset($numFmts[$s['numFmt']])) $numFmts[$s['numFmt']] = 164 + count($numFmts);
                $numFmtId = $numFmts[$s['numFmt']];
            }
            $align = '';
            if (!empty($s['h']) || !empty($s['v']) || !empty($s['wrap'])) {
                $align = '<alignment' . (!empty($s['h']) ? ' horizontal="' . $s['h'] . '"' : '') . (!empty($s['v']) ? ' vertical="' . $s['v'] . '"' : '') . (!empty($s['wrap']) ? ' wrapText="1"' : '') . '/>';
            }
            $xfs[] = '<xf numFmtId="' . $numFmtId . '" fontId="' . $fontIdx[$font] . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
                . ($numFmtId ? ' applyNumberFormat="1"' : '') . ($fillId ? ' applyFill="1"' : '') . ($borderId ? ' applyBorder="1"' : '') . ' applyFont="1"'
                . ($align ? ' applyAlignment="1">' . $align . '</xf>' : '/>');
        }
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($numFmts) {
            $x .= '<numFmts count="' . count($numFmts) . '">';
            foreach ($numFmts as $code => $id) $x .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::esc($code) . '"/>';
            $x .= '</numFmts>';
        }
        return $x . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
            . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
            . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function parts()
    {
        $sheetName = self::esc($this->sheetName);
        $definedNames = $this->printTitleRow
            ? '<definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">\'' . str_replace("'", "''", $sheetName) . '\'!$' . $this->printTitleRow . ':$' . $this->printTitleRow . '</definedName>'
              . ($this->autoFilter ? '<definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . str_replace("'", "''", $sheetName) . '\'!' . preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $this->autoFilter) . '</definedName>' : '')
              . '</definedNames>'
            : '';
        return [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                . '<dc:title>' . self::esc($this->title) . '</dc:title><dc:creator>' . self::esc($this->creator) . '</dc:creator>'
                . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets>' . $definedNames . '</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => $this->stylesXml(),
            'xl/worksheets/sheet1.xml' => $this->sheetXml(),
        ];
    }

    /**
     * Build the .xlsx as a ZIP archive (deflate when zlib is available, otherwise stored)
     */
    public function toString()
    {
        $data = '';
        $central = '';
        $count = 0;
        [$dosTime, $dosDate] = self::dosDateTime();
        foreach ($this->parts() as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $method = 0;
            $packed = $content;
            if (function_exists('gzdeflate')) {
                $deflated = gzdeflate($content, 6);
                if ($deflated !== false && strlen($deflated) < $size) {
                    $method = 8;
                    $packed = $deflated;
                }
            }
            $offset = strlen($data);
            $header = pack('vvvvvVVVvv', 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($packed), $size, strlen($name), 0);
            $data .= pack('V', 0x04034b50) . $header . $name . $packed;
            $central .= pack('V', 0x02014b50) . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, $method, $dosTime, $dosDate, $crc, strlen($packed), $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            $count++;
        }
        return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($data), 0);
    }

    private static function dosDateTime()
    {
        $t = getdate();
        return [
            ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2),
            (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'],
        ];
    }

    public function output($filename)
    {
        $bytes = $this->toString();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $bytes;
    }
}
