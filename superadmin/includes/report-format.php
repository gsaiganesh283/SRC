<?php
/**
 * Text and number formatting shared by the letterhead PDF and the Custom Report Builder.
 */

if (!function_exists('pdfText')) {
    /**
     * Convert UTF-8 text to the Windows-1252 encoding used by FPDF core fonts
     */
    function pdfText($text) {
        $text = (string)$text;
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
        return $converted !== false ? $converted : $text;
    }
}

if (!function_exists('formatIndianAmount')) {
    /**
     * Format an amount with Indian digit grouping (e.g. 1,23,456.00)
     */
    function formatIndianAmount($amount, $decimals = 2) {
        $amount = (float)$amount;
        $negative = $amount < 0;
        $parts = explode('.', number_format(abs($amount), $decimals, '.', ''));
        $int = $parts[0];
        if (strlen($int) > 3) {
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($int, 0, -3));
            $int = $rest . ',' . substr($int, -3);
        }
        return ($negative ? '-' : '') . $int . (isset($parts[1]) ? '.' . $parts[1] : '');
    }
}
