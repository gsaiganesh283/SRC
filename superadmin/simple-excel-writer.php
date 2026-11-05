<?php
/**
 * Simple Excel Writer with Dropdown Support
 * Generates real XLSX files with data validation
 */

class SimpleExcelWriter {
    private $sheets = [];
    private $currentSheet = 0;
    private $sharedStrings = [];
    private $sharedStringsCount = 0;
    private $validations = [];
    
    public function addSheet($name) {
        $this->sheets[] = [
            'name' => $name,
            'rows' => [],
            'validations' => []
        ];
        $this->currentSheet = count($this->sheets) - 1;
    }
    
    public function writeRow($data, $style = '') {
        if (empty($this->sheets)) {
            $this->addSheet('Sheet1');
        }
        
        $row = [];
        foreach ($data as $cell) {
            if (is_numeric($cell)) {
                $row[] = ['type' => 'n', 'value' => $cell, 'style' => $style];
            } else {
                $stringIndex = $this->addSharedString($cell);
                $row[] = ['type' => 's', 'value' => $stringIndex, 'style' => $style];
            }
        }
        
        $this->sheets[$this->currentSheet]['rows'][] = $row;
    }
    
    private function addSharedString($string) {
        if (isset($this->sharedStrings[$string])) {
            return $this->sharedStrings[$string];
        }
        
        $index = $this->sharedStringsCount++;
        $this->sharedStrings[$string] = $index;
        return $index;
    }
    
    public function addDropdown($cell, $options) {
        if (empty($this->sheets)) {
            return;
        }
        
        $this->sheets[$this->currentSheet]['validations'][] = [
            'cell' => $cell,
            'options' => $options
        ];
    }
    
    public function download($filename) {
        // Generate Excel content first
        ob_start();
        $content = $this->generateXLSXContent();
        ob_end_clean();
        
        // Now send headers and content
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Content-Length: ' . strlen($content));
        
        echo $content;
        exit;
    }
    
    private function generateXLSXContent() {
        $zip = new ZipArchive();
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            die('Cannot create Excel file');
        }
        
        // Create required directory structure
        $zip->addEmptyDir('_rels');
        $zip->addEmptyDir('xl');
        $zip->addEmptyDir('xl/_rels');
        $zip->addEmptyDir('xl/worksheets');
        
        // Add [Content_Types].xml
        $zip->addFromString('[Content_Types].xml', $this->getContentTypes());
        
        // Add _rels/.rels
        $zip->addFromString('_rels/.rels', $this->getRels());
        
        // Add xl/_rels/workbook.xml.rels
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->getWorkbookRels());
        
        // Add xl/workbook.xml
        $zip->addFromString('xl/workbook.xml', $this->getWorkbook());
        
        // Add xl/styles.xml
        $zip->addFromString('xl/styles.xml', $this->getStyles());
        
        // Add xl/sharedStrings.xml
        $zip->addFromString('xl/sharedStrings.xml', $this->getSharedStrings());
        
        // Add sheets
        foreach ($this->sheets as $index => $sheet) {
            $sheetNum = $index + 1;
            $zip->addFromString("xl/worksheets/sheet{$sheetNum}.xml", $this->getSheet($index));
        }
        
        $zip->close();
        
        $content = file_get_contents($tempFile);
        unlink($tempFile);
        
        return $content;
    }
    
    private function getContentTypes() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        
        foreach ($this->sheets as $index => $sheet) {
            $sheetNum = $index + 1;
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $sheetNum . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        $xml .= '</Types>';
        
        return $xml;
    }
    
    private function getRels() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
               '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
               '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
               '</Relationships>';
    }
    
    private function getWorkbookRels() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        
        foreach ($this->sheets as $index => $sheet) {
            $sheetNum = $index + 1;
            $xml .= '<Relationship Id="rId' . $sheetNum . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetNum . '.xml"/>';
        }
        
        $rId = count($this->sheets) + 1;
        $xml .= '<Relationship Id="rId' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $rId++;
        $xml .= '<Relationship Id="rId' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $xml .= '</Relationships>';
        
        return $xml;
    }
    
    private function getWorkbook() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $xml .= '<sheets>';
        
        foreach ($this->sheets as $index => $sheet) {
            $sheetNum = $index + 1;
            $xml .= '<sheet name="' . htmlspecialchars($sheet['name']) . '" sheetId="' . $sheetNum . '" r:id="rId' . $sheetNum . '"/>';
        }
        
        $xml .= '</sheets>';
        $xml .= '</workbook>';
        
        return $xml;
    }
    
    private function getStyles() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
               '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
               '<fonts count="2">' .
               '<font><sz val="11"/><name val="Calibri"/></font>' .
               '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>' .
               '</fonts>' .
               '<fills count="2">' .
               '<fill><patternFill patternType="none"/></fill>' .
               '<fill><patternFill patternType="solid"><fgColor rgb="FF4F46E5"/></patternFill></fill>' .
               '</fills>' .
               '<borders count="1">' .
               '<border><left/><right/><top/><bottom/></border>' .
               '</borders>' .
               '<cellXfs count="2">' .
               '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' .
               '<xf numFmtId="0" fontId="1" fillId="1" borderId="0" applyFont="1" applyFill="1"/>' .
               '</cellXfs>' .
               '</styleSheet>';
    }
    
    private function getSharedStrings() {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $this->sharedStringsCount . '" uniqueCount="' . $this->sharedStringsCount . '">';
        
        // Flip array to get string as key and index as value
        $strings = array_flip($this->sharedStrings);
        ksort($strings);
        
        foreach ($strings as $string => $index) {
            $xml .= '<si><t>' . htmlspecialchars($string, ENT_XML1, 'UTF-8') . '</t></si>';
        }
        
        $xml .= '</sst>';
        
        return $xml;
    }
    
    private function getSheet($sheetIndex) {
        $sheet = $this->sheets[$sheetIndex];
        
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        
        // Calculate column widths
        $colCount = 0;
        if (!empty($sheet['rows'])) {
            $colCount = count($sheet['rows'][0]);
        }
        
        if ($colCount > 0) {
            $xml .= '<cols>';
            for ($i = 1; $i <= $colCount; $i++) {
                $xml .= '<col min="' . $i . '" max="' . $i . '" width="15" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        
        $xml .= '<sheetData>';
        
        foreach ($sheet['rows'] as $rowIndex => $row) {
            $rowNum = $rowIndex + 1;
            $xml .= '<row r="' . $rowNum . '">';
            
            foreach ($row as $colIndex => $cell) {
                $colLetter = $this->columnLetter($colIndex);
                $cellRef = $colLetter . $rowNum;
                
                $styleId = ($cell['style'] === 'header') ? '1' : '0';
                
                $xml .= '<c r="' . $cellRef . '" t="' . $cell['type'] . '" s="' . $styleId . '">';
                $xml .= '<v>' . htmlspecialchars($cell['value']) . '</v>';
                $xml .= '</c>';
            }
            
            $xml .= '</row>';
        }
        
        $xml .= '</sheetData>';
        
        // Add data validations (dropdowns)
        if (!empty($sheet['validations'])) {
            $xml .= '<dataValidations count="' . count($sheet['validations']) . '">';
            
            foreach ($sheet['validations'] as $validation) {
                // Escape quotes in options
                $options = array_map(function($opt) {
                    return str_replace('"', '""', $opt);
                }, $validation['options']);
                
                $formula = '"' . implode(',', $options) . '"';
                $xml .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="' . htmlspecialchars($validation['cell']) . '">';
                $xml .= '<formula1>' . htmlspecialchars($formula) . '</formula1>';
                $xml .= '</dataValidation>';
            }
            
            $xml .= '</dataValidations>';
        }
        
        $xml .= '</worksheet>';
        
        return $xml;
    }
    
    private function columnLetter($index) {
        $letter = '';
        while ($index >= 0) {
            $letter = chr($index % 26 + 65) . $letter;
            $index = floor($index / 26) - 1;
        }
        return $letter;
    }
}
