<?php
// PDF Export for Reports & Analytics
// URL: admin/export-report-pdf.php

require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

// Require admin authentication
requireAdmin();

// Include FPDF library
require_once 'fpdf.php';

// Get date range from parameters
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-04-01');
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$memberID = isset($_GET['member_id']) ? $_GET['member_id'] : (isset($_POST['member_id']) ? $_POST['member_id'] : null);
$templateID = isset($_GET['template_id']) && $_GET['template_id'] !== '' ? intval($_GET['template_id']) : null;

// Load template configuration if specified
$templateConfig = null;
$templateColors = ['primary' => '#1e293b', 'accent' => '#eab308'];

// Debug: Log template ID
error_log("SuperAdmin PDF Export - Template ID: " . ($templateID ?? 'none'));

if ($templateID) {
    try {
        $stmt = $pdo->prepare("SELECT TemplateConfig FROM report_templates WHERE TemplateID = ? AND IsActive = 1");
        $stmt->execute([$templateID]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($template) {
            $templateConfig = json_decode($template['TemplateConfig'], true);
            if (isset($templateConfig['colors'])) {
                $templateColors = $templateConfig['colors'];
                error_log("Template colors loaded: " . json_encode($templateColors));
            }
        } else {
            error_log("Template not found for ID: " . $templateID);
        }
    } catch (Exception $e) {
        error_log("Template loading error: " . $e->getMessage());
        // Use default template if loading fails
    }
}

// Get chart images from POST data
$chartImages = [];
if (isset($_POST['charts'])) {
    $chartImages = json_decode($_POST['charts'], true) ?: [];
}

// Helper function to convert hex to RGB
function hexToRgb($hex) {
    $hex = ltrim($hex, '#');
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    ];
}

$primaryRgb = hexToRgb($templateColors['primary']);
$accentRgb = hexToRgb($templateColors['accent']);

class ReportPDF extends FPDF
{
    private $companyName = 'SR Colony';
    private $reportTitle = 'Reports & Analytics Summary';
    private $dateRange = '';
    public $primaryColor = [30, 41, 59]; // Default navy
    public $accentColor = [234, 179, 8]; // Default gold
    
    function setColors($primary, $accent) {
        $this->primaryColor = $primary;
        $this->accentColor = $accent;
    }
    
    function setPrimaryColor() {
        $this->SetTextColor($this->primaryColor[0], $this->primaryColor[1], $this->primaryColor[2]);
    }
    
    function setAccentColor() {
        $this->SetTextColor($this->accentColor[0], $this->accentColor[1], $this->accentColor[2]);
    }
    
    function setPrimaryFill() {
        $this->SetFillColor($this->primaryColor[0], $this->primaryColor[1], $this->primaryColor[2]);
    }
    
    function setAccentFill() {
        $this->SetFillColor($this->accentColor[0], $this->accentColor[1], $this->accentColor[2]);
    }
    
    function setDateRange($from, $to) {
        $this->dateRange = "Period: " . date('d M Y', strtotime($from)) . " to " . date('d M Y', strtotime($to));
    }
    
    // Page header
    function Header()
    {
        // Modern gradient header using template colors
        for ($i = 0; $i < 32; $i++) {
            $alpha = $i / 32;
            $this->SetFillColor(
                $this->primaryColor[0] + ($alpha * 10),
                $this->primaryColor[1] + ($alpha * 15),
                $this->primaryColor[2] - ($alpha * 10)
            );
            $this->Rect(0, $i, 210, 1, 'F');
        }
        
        // Gold accent line
        $this->setAccentFill();
        $this->Rect(0, 32, 210, 2, 'F');
        
        // Company Name
        $this->SetFont('Arial', 'B', 22);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(10, 7);
        $this->Cell(0, 8, $this->companyName, 0, 1, 'C');
        
        // Report Title
        $this->SetFont('Arial', 'B', 13);
        $this->setAccentColor();
        $this->Cell(0, 6, $this->reportTitle, 0, 1, 'C');
        
        // Date Generated
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(226, 232, 240);
        $this->Cell(0, 5, 'Generated: ' . date('F d, Y h:i A'), 0, 1, 'C');
        
        // Date Range
        if (!empty($this->dateRange)) {
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(203, 213, 225);
            $this->Cell(0, 4, $this->dateRange, 0, 1, 'C');
        }
        
        $this->Ln(6);
    }
    
    // Page footer
    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(127, 140, 141);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        
        // Add branding
        $this->SetY(-15);
        $this->Cell(0, 10, 'SR Colony Management System', 0, 0, 'L');
    }
    
    // Executive Summary with Insights
    function AddExecutiveSummary($summary)
    {
        $this->SetFont('Arial', 'B', 16);
        $this->setPrimaryColor();
        $this->Cell(0, 10, 'Executive Summary', 0, 1, 'L');
        $this->Ln(2);
        
        // Key Insights
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(71, 85, 105);
        
        $netBalance = ($summary['totalCollection'] ?? 0) - ($summary['totalDistribution'] ?? 0);
        $avgTransaction = ($summary['totalTransactions'] ?? 0) > 0 ? 
            ($summary['totalCollection'] ?? 0) / $summary['totalTransactions'] : 0;
        
        $this->MultiCell(0, 6, "This report provides a comprehensive analysis of financial transactions and member activities for the SR Colony Chitti Management System. The data reflects collections, distributions, and interest calculations for the specified period.", 0, 'L');
        $this->Ln(3);
        
        // Key Highlights Box with modern design
        $startY = $this->GetY();
        // Light background
        $this->SetFillColor(248, 250, 252);
        $this->Rect(10, $startY, 190, 45, 'F');
        
        // Primary color left border accent
        $this->setPrimaryFill();
        $this->Rect(10, $startY, 4, 45, 'F');
        
        // Subtle border
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Rect(10, $startY, 190, 45, 'D');
        $this->SetLineWidth(0.2);
        
        $startY = $this->GetY();
        $this->SetY($startY + 3);
        $this->SetX(15);
        
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 6, 'Key Highlights:', 0, 1, 'L');
        $this->SetX(15);
        
        $this->SetFont('Arial', '', 10);
        $highlights = [
            sprintf("• Total Collection: Rs %s from %d transactions", 
                number_format($summary['totalCollection'] ?? 0, 2), 
                $summary['totalTransactions'] ?? 0),
            sprintf("• Total Distribution: Rs %s", 
                number_format($summary['totalDistribution'] ?? 0, 2)),
            sprintf("• Net Balance: Rs %s (%s)", 
                number_format(abs($netBalance), 2),
                $netBalance >= 0 ? 'Surplus' : 'Deficit'),
            sprintf("• Average Transaction: Rs %s", 
                number_format($avgTransaction, 2)),
            sprintf("• Active Members: %d across %d academic years", 
                $summary['totalMembers'] ?? 0,
                $summary['totalYears'] ?? 0)
        ];
        
        foreach ($highlights as $highlight) {
            $this->Cell(0, 6, $highlight, 0, 1, 'L');
            $this->SetX(15);
        }
        
        $this->Ln(8);
    }
    
    // Summary Statistics Section
    function AddSummaryStats($summary)
    {
        $this->SetFont('Arial', 'B', 14);
        $this->setPrimaryColor();
        $this->Cell(0, 10, 'Summary Statistics', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(71, 85, 105);
        
        // Create a grid of stats
        $stats = [
            ['Active Members', $summary['totalMembers'] ?? 0, 'members'],
            ['Total Collection', 'Rs ' . number_format($summary['totalCollection'] ?? 0, 2), ''],
            ['Total Distribution', 'Rs ' . number_format($summary['totalDistribution'] ?? 0, 2), ''],
            ['Total Interest', 'Rs ' . number_format($summary['totalInterest'] ?? 0, 2), ''],
            ['Academic Years', $summary['totalYears'] ?? 0, 'years'],
            ['Total Transactions', $summary['totalTransactions'] ?? 0, 'transactions']
        ];
        
        $colWidth = 95;
        $x = 10;
        $y = $this->GetY();
        
        foreach ($stats as $index => $stat) {
            if ($index > 0 && $index % 2 == 0) {
                $x = 10;
                $y += 20;
                $this->SetXY($x, $y);
            } else if ($index > 0) {
                $x = 105;
                $this->SetXY($x, $y);
            }
            
            // Modern stat card with navy theme
            // Background
            $this->SetFillColor(255, 255, 255);
            $this->Rect($x, $y, $colWidth, 18, 'F');
            
            // Accent top line
            $this->setAccentFill();
            $this->Rect($x, $y, $colWidth, 2, 'F');
            
            // Border
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.3);
            $this->Rect($x, $y, $colWidth, 18, 'D');
            $this->SetLineWidth(0.2);
            
            $this->SetXY($x + 2, $y + 4);
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell($colWidth - 4, 5, $stat[0], 0, 0, 'L');
            
            $this->SetXY($x + 2, $y + 10);
            $this->SetFont('Arial', 'B', 12);
            $this->setPrimaryColor();
            $this->Cell($colWidth - 4, 6, $stat[1], 0, 0, 'L');
        }
        
        $this->SetXY(10, $y + 25);
        $this->Ln(5);
    }
    
    // Monthly Trend Table
    function AddMonthlyTrend($monthlyData)
    {
        // Modern section header
        $headerY = $this->GetY();
        $this->setPrimaryFill();
        $this->Rect(10, $headerY, 190, 10, 'F');
        $this->setAccentFill();
        $this->Rect(10, $headerY, 4, 10, 'F');
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(16, $headerY);
        $this->Cell(184, 10, 'Monthly Collection Trend', 0, 1, 'L');
        
        if (empty($monthlyData)) {
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(150, 150, 150);
            $this->Cell(0, 8, 'No data available for the selected period', 0, 1, 'C');
            $this->Ln(5);
            return;
        }
        
        // Table header with navy theme
        $this->SetFont('Arial', 'B', 10);
        $this->setPrimaryFill();
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(226, 232, 240);
        
        $this->Cell(40, 8, 'Month', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Trans.', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Collection', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Distribution', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Interest', 1, 1, 'C', true);
        
        // Table data
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(71, 85, 105);
        
        $fill = false;
        foreach ($monthlyData as $row) {
            $this->SetFillColor(245, 245, 245);
            
            $this->Cell(40, 7, $row['MonthName'], 1, 0, 'L', $fill);
            $this->Cell(30, 7, $row['TransactionCount'], 1, 0, 'C', $fill);
            $this->Cell(40, 7, 'Rs ' . number_format($row['Collection'], 2), 1, 0, 'R', $fill);
            $this->Cell(40, 7, 'Rs ' . number_format($row['Distribution'], 2), 1, 0, 'R', $fill);
            $this->Cell(40, 7, 'Rs ' . number_format($row['Interest'], 2), 1, 1, 'R', $fill);
            
            $fill = !$fill;
        }
        
        $this->Ln(3);
        
        // Add Monthly Trend Insights
        $this->AddMonthlyTrendInsights($monthlyData);
        
        $this->Ln(5);
    }
    
    // Add Chart Image
    function AddChartImage($title, $base64Image, $description = '')
    {
        if (empty($base64Image)) return;
        
        // Add section title
        $this->SetFont('Arial', 'B', 14);
        $this->setPrimaryColor();
        $this->Cell(0, 10, $title, 0, 1, 'L');
        
        // Add description if provided
        if (!empty($description)) {
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(71, 85, 105);
            $this->MultiCell(0, 5, $description, 0, 'L');
            $this->Ln(2);
        }
        
        try {
            // Decode base64 image
            $imageData = $base64Image;
            if (strpos($imageData, 'data:image/png;base64,') === 0) {
                $imageData = substr($imageData, strlen('data:image/png;base64,'));
            } elseif (strpos($imageData, 'data:image/jpeg;base64,') === 0) {
                $imageData = substr($imageData, strlen('data:image/jpeg;base64,'));
            }
            
            // Validate base64
            $decoded = base64_decode($imageData, true);
            if ($decoded === false || empty($decoded)) {
                throw new Exception('Invalid base64 image data');
            }
            
            // Create temporary file
            $tempFile = tempnam(sys_get_temp_dir(), 'chart_') . '.png';
            if (file_put_contents($tempFile, $decoded) === false) {
                throw new Exception('Failed to write image file');
            }
            
            // Verify it's a valid image
            $imageInfo = @getimagesize($tempFile);
            if ($imageInfo === false) {
                unlink($tempFile);
                throw new Exception('Invalid image format');
            }
            
            // Add image to PDF (centered, max width 180mm)
            $currentY = $this->GetY();
            
            // Check if we need a new page
            if ($currentY > 200) {
                $this->AddPage();
                $currentY = $this->GetY();
            }
            
            $this->Image($tempFile, 15, $currentY, 180, 0, 'PNG');
            
            // Clean up temp file
            unlink($tempFile);
            
            // Move Y position after image
            $this->Ln(95);
            
        } catch (Exception $e) {
            // If image fails, just add a note
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(150, 150, 150);
            $this->Cell(0, 8, 'Chart image could not be embedded: ' . $e->getMessage(), 0, 1, 'C');
            $this->Ln(5);
        }
    }
    
    // Monthly Trend Insights
    function AddMonthlyTrendInsights($monthlyData)
    {
        if (empty($monthlyData)) return;
        
        $this->SetFillColor(255, 250, 240);
        $this->SetDrawColor(255, 193, 7);
        $this->Rect($this->GetX(), $this->GetY(), 190, 35, 'FD');
        
        $startY = $this->GetY();
        $this->SetY($startY + 2);
        $this->SetX(15);
        
        $this->SetFont('Arial', 'B', 10);
        $this->setPrimaryColor();
        $this->Cell(0, 5, 'Analysis & Insights:', 0, 1, 'L');
        $this->SetX(15);
        
        // Calculate trend metrics
        $totalMonths = count($monthlyData);
        $avgCollection = array_sum(array_column($monthlyData, 'Collection')) / $totalMonths;
        $maxMonth = array_reduce($monthlyData, function($carry, $item) {
            return (!$carry || $item['Collection'] > $carry['Collection']) ? $item : $carry;
        });
        $minMonth = array_reduce($monthlyData, function($carry, $item) {
            return (!$carry || $item['Collection'] < $carry['Collection']) ? $item : $carry;
        });
        
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(71, 85, 105);
        
        $insights = [
            sprintf("• Highest collection: %s (Rs %s)", 
                $maxMonth['MonthName'], number_format($maxMonth['Collection'], 2)),
            sprintf("• Lowest collection: %s (Rs %s)", 
                $minMonth['MonthName'], number_format($minMonth['Collection'], 2)),
            sprintf("• Average monthly collection: Rs %s", 
                number_format($avgCollection, 2)),
            sprintf("• Total months analyzed: %d", $totalMonths)
        ];
        
        foreach ($insights as $insight) {
            $this->Cell(0, 5, $insight, 0, 1, 'L');
            $this->SetX(15);
        }
        
        $this->SetXY(10, $startY + 35);
    }
    
    // Payment Modes Table
    function AddPaymentModes($paymentData)
    {
        // Modern section header
        $headerY = $this->GetY();
        $this->setPrimaryFill();
        $this->Rect(10, $headerY, 190, 10, 'F');
        $this->setAccentFill();
        $this->Rect(10, $headerY, 4, 10, 'F');
        $this->SetFont('Arial', 'B', 13);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(16, $headerY);
        $this->Cell(184, 10, 'Payment Mode Distribution', 0, 1, 'L');
        
        if (empty($paymentData)) {
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(150, 150, 150);
            $this->Cell(0, 8, 'No payment data available', 0, 1, 'C');
            $this->Ln(5);
            return;
        }
        
        // Calculate total for percentages
        $totalAmount = array_sum(array_column($paymentData, 'TotalAmount'));
        
        // Table header
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255, 255, 255);
        
        $this->Cell(60, 8, 'Payment Mode', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Count', 1, 0, 'C', true);
        $this->Cell(50, 8, 'Total Amount', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Percentage', 1, 1, 'C', true);
        
        // Table data
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(71, 85, 105);
        
        $fill = false;
        foreach ($paymentData as $row) {
            $percentage = $totalAmount > 0 ? ($row['TotalAmount'] / $totalAmount * 100) : 0;
            
            $this->SetFillColor(245, 245, 245);
            
            $this->Cell(60, 7, $row['PaymentMode'], 1, 0, 'L', $fill);
            $this->Cell(40, 7, $row['Count'], 1, 0, 'C', $fill);
            $this->Cell(50, 7, 'Rs ' . number_format($row['TotalAmount'], 2), 1, 0, 'R', $fill);
            $this->Cell(40, 7, number_format($percentage, 1) . '%', 1, 1, 'C', $fill);
            
            $fill = !$fill;
        }
        
        $this->Ln(3);
        
        // Add Payment Mode Insights
        $this->AddPaymentModeInsights($paymentData, $totalAmount);
        
        $this->Ln(5);
    }
    
    // Payment Mode Insights
    function AddPaymentModeInsights($paymentData, $totalAmount)
    {
        if (empty($paymentData)) return;
        
        // Modern insight box
        $startY = $this->GetY();
        $this->SetFillColor(254, 252, 232); // Light gold tint
        $this->Rect(10, $startY, 190, 28, 'F');
        
        // Accent left border
        $this->setAccentFill();
        $this->Rect(10, $startY, 4, 28, 'F');
        
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.3);
        $this->Rect(10, $startY, 190, 28, 'D');
        $this->SetLineWidth(0.2);
        
        $startY = $this->GetY();
        $this->SetY($startY + 2);
        $this->SetX(16);
        
        $this->SetFont('Arial', 'B', 10);
        $this->setPrimaryColor();
        $this->Cell(0, 5, 'Payment Mode Analysis:', 0, 1, 'L');
        $this->SetX(15);
        
        $mostUsed = $paymentData[0];
        $percentage = $totalAmount > 0 ? ($mostUsed['TotalAmount'] / $totalAmount * 100) : 0;
        
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(71, 85, 105);
        
        $insights = [
            sprintf("• Most preferred mode: %s (%d transactions, %.1f%% of total)", 
                $mostUsed['PaymentMode'], $mostUsed['Count'], $percentage),
            sprintf("• Total payment modes used: %d", count($paymentData)),
            sprintf("• Total transaction value: Rs %s", number_format($totalAmount, 2))
        ];
        
        foreach ($insights as $insight) {
            $this->Cell(0, 5, $insight, 0, 1, 'L');
            $this->SetX(15);
        }
        
        $this->SetXY(10, $startY + 28);
    }
    
    // Detailed Financial Analysis Section
    function AddFinancialAnalysis($summary, $monthlyTrend)
    {
        $this->AddPage();
        
        $this->SetFont('Arial', 'B', 16);
        $this->setPrimaryColor();
        $this->Cell(0, 10, 'Detailed Financial Analysis', 0, 1, 'L');
        $this->Ln(3);
        
        // Financial Health Indicators
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(0, 8, '1. Financial Health Indicators', 0, 1, 'L');
        
        $netBalance = ($summary['totalCollection'] ?? 0) - ($summary['totalDistribution'] ?? 0);
        $collectionEfficiency = ($summary['totalDistribution'] ?? 0) > 0 ? 
            (($summary['totalCollection'] ?? 0) / ($summary['totalDistribution'] ?? 0) * 100) : 0;
        $avgTransactionValue = ($summary['totalTransactions'] ?? 0) > 0 ? 
            ($summary['totalCollection'] ?? 0) / ($summary['totalTransactions'] ?? 0) : 0;
        
        $this->SetFont('Arial', '', 10);
        $metrics = [
            ['Net Financial Position:', 'Rs ' . number_format($netBalance, 2), $netBalance >= 0 ? 'Surplus' : 'Deficit'],
            ['Collection Efficiency:', number_format($collectionEfficiency, 2) . '%', $collectionEfficiency > 100 ? 'Excellent' : 'Good'],
            ['Average Transaction Size:', 'Rs ' . number_format($avgTransactionValue, 2), ''],
            ['Total Interest Generated:', 'Rs ' . number_format($summary['totalInterest'] ?? 0, 2), ''],
        ];
        
        foreach ($metrics as $metric) {
            $this->Cell(80, 6, $metric[0], 0, 0, 'L');
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(60, 6, $metric[1], 0, 0, 'R');
            $this->SetFont('Arial', 'I', 9);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(50, 6, $metric[2], 0, 1, 'R');
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(71, 85, 105);
        }
        
        $this->Ln(5);
        
        // Cash Flow Analysis
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, '2. Cash Flow Analysis', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 5, sprintf(
            "Total cash inflow (collections): Rs %s\nTotal cash outflow (distributions): Rs %s\nNet cash flow: Rs %s\n\nCash flow ratio: %.2f:1 (For every rupee distributed, Rs %.2f is collected)",
            number_format($summary['totalCollection'] ?? 0, 2),
            number_format($summary['totalDistribution'] ?? 0, 2),
            number_format($netBalance, 2),
            $collectionEfficiency / 100,
            $collectionEfficiency / 100
        ));
        
        $this->Ln(5);
        
        // Performance Metrics
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, '3. Performance Metrics', 0, 1, 'L');
        
        if (!empty($monthlyTrend)) {
            $totalMonths = count($monthlyTrend);
            $avgMonthlyCollection = array_sum(array_column($monthlyTrend, 'Collection')) / $totalMonths;
            $avgMonthlyDistribution = array_sum(array_column($monthlyTrend, 'Distribution')) / $totalMonths;
            
            $this->SetFont('Arial', '', 10);
            $this->Cell(80, 6, 'Average Monthly Collection:', 0, 0, 'L');
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 6, 'Rs ' . number_format($avgMonthlyCollection, 2), 0, 1, 'R');
            
            $this->SetFont('Arial', '', 10);
            $this->Cell(80, 6, 'Average Monthly Distribution:', 0, 0, 'L');
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 6, 'Rs ' . number_format($avgMonthlyDistribution, 2), 0, 1, 'R');
            
            $this->SetFont('Arial', '', 10);
            $this->Cell(80, 6, 'Number of Active Months:', 0, 0, 'L');
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 6, $totalMonths . ' months', 0, 1, 'R');
        }
    }
    
    // Transaction Pattern Analysis
    function AddTransactionPatternAnalysis($monthlyTrend)
    {
        $this->AddPage();
        
        $this->SetFont('Arial', 'B', 16);
        $this->setPrimaryColor();
        $this->Cell(0, 10, 'Transaction Pattern Analysis', 0, 1, 'L');
        $this->Ln(3);
        
        if (empty($monthlyTrend)) {
            $this->SetFont('Arial', 'I', 10);
            $this->Cell(0, 8, 'No transaction data available for pattern analysis', 0, 1, 'C');
            return;
        }
        
        // Peak Activity Analysis
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(0, 8, 'Peak Activity Periods', 0, 1, 'L');
        
        $peakMonth = array_reduce($monthlyTrend, function($carry, $item) {
            return (!$carry || $item['TransactionCount'] > $carry['TransactionCount']) ? $item : $carry;
        });
        
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 5, sprintf(
            "Highest activity month: %s with %d transactions\nTotal collection in peak month: Rs %s\nThis represents %.1f%% of total transactions in the period.",
            $peakMonth['MonthName'],
            $peakMonth['TransactionCount'],
            number_format($peakMonth['Collection'], 2),
            ($peakMonth['TransactionCount'] / array_sum(array_column($monthlyTrend, 'TransactionCount')) * 100)
        ));
        
        $this->Ln(5);
        
        // Consistency Analysis
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'Collection Consistency', 0, 1, 'L');
        
        $collections = array_column($monthlyTrend, 'Collection');
        $avgCollection = array_sum($collections) / count($collections);
        $variance = 0;
        foreach ($collections as $value) {
            $variance += pow($value - $avgCollection, 2);
        }
        $stdDev = sqrt($variance / count($collections));
        $coefficientOfVariation = $avgCollection > 0 ? ($stdDev / $avgCollection * 100) : 0;
        
        $consistency = 'High';
        if ($coefficientOfVariation > 50) $consistency = 'Low';
        elseif ($coefficientOfVariation > 25) $consistency = 'Moderate';
        
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 5, sprintf(
            "Standard Deviation: Rs %s\nCoefficient of Variation: %.2f%%\nConsistency Level: %s\n\nA lower coefficient indicates more consistent collections month-over-month.",
            number_format($stdDev, 2),
            $coefficientOfVariation,
            $consistency
        ));
    }
    
    // Member Engagement Report
    function AddMemberEngagementReport($memberSummary)
    {
        $this->AddPage();
        
        $this->SetFont('Arial', 'B', 16);
        $this->setPrimaryColor();
        $this->Cell(0, 10, 'Member Engagement Report', 0, 1, 'L');
        $this->Ln(3);
        
        if (empty($memberSummary)) {
            $this->SetFont('Arial', 'I', 10);
            $this->Cell(0, 8, 'No member engagement data available', 0, 1, 'C');
            return;
        }
        
        // Engagement Categories
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(0, 8, 'Member Engagement Categories', 0, 1, 'L');
        
        $totalMembers = count($memberSummary);
        $avgTransactionsPerMember = array_sum(array_column($memberSummary, 'TransactionCount')) / $totalMembers;
        
        $highlyActive = 0;
        $moderatelyActive = 0;
        $lowActivity = 0;
        
        foreach ($memberSummary as $member) {
            if ($member['TransactionCount'] >= $avgTransactionsPerMember * 1.5) {
                $highlyActive++;
            } elseif ($member['TransactionCount'] >= $avgTransactionsPerMember * 0.5) {
                $moderatelyActive++;
            } else {
                $lowActivity++;
            }
        }
        
        $this->SetFont('Arial', '', 10);
        $categories = [
            ['Highly Active Members:', $highlyActive, sprintf('%.1f%%', ($highlyActive / $totalMembers * 100))],
            ['Moderately Active Members:', $moderatelyActive, sprintf('%.1f%%', ($moderatelyActive / $totalMembers * 100))],
            ['Low Activity Members:', $lowActivity, sprintf('%.1f%%', ($lowActivity / $totalMembers * 100))],
        ];
        
        foreach ($categories as $cat) {
            $this->Cell(100, 6, $cat[0], 0, 0, 'L');
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(45, 6, $cat[1] . ' members', 0, 0, 'R');
            $this->SetFont('Arial', 'I', 9);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(45, 6, '(' . $cat[2] . ')', 0, 1, 'R');
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(71, 85, 105);
        }
        
        $this->Ln(5);
        
        // Engagement Insights
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'Engagement Insights', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(0, 5, sprintf(
            "Total Active Members: %d\nAverage Transactions per Member: %.1f\nHighest Individual Transactions: %d\n\nRecommendation: Focus on engaging the %d low-activity members to improve overall participation.",
            $totalMembers,
            $avgTransactionsPerMember,
            max(array_column($memberSummary, 'TransactionCount')),
            $lowActivity
        ));
    }
    
    // Recommendations and Action Items
    function AddRecommendations($summary, $monthlyTrend, $memberSummary)
    {
        $this->AddPage();
        
        $this->SetFont('Arial', 'B', 16);
        $this->setPrimaryColor();
        $this->Cell(0, 10, 'Recommendations & Action Items', 0, 1, 'L');
        $this->Ln(3);
        
        $netBalance = ($summary['totalCollection'] ?? 0) - ($summary['totalDistribution'] ?? 0);
        
        // Financial Recommendations
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(0, 8, 'Financial Recommendations', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 10);
        $recommendations = [];
        
        if ($netBalance < 0) {
            $recommendations[] = "• URGENT: Address negative balance of Rs " . number_format(abs($netBalance), 2) . " through increased collections";
        } else {
            $recommendations[] = "• Maintain positive cash flow with current surplus of Rs " . number_format($netBalance, 2);
        }
        
        $collectionEfficiency = ($summary['totalDistribution'] ?? 0) > 0 ? 
            (($summary['totalCollection'] ?? 0) / ($summary['totalDistribution'] ?? 0) * 100) : 0;
        
        if ($collectionEfficiency < 90) {
            $recommendations[] = "• Improve collection efficiency (currently " . number_format($collectionEfficiency, 1) . "%) by focusing on timely collections";
        }
        
        foreach ($recommendations as $rec) {
            $this->MultiCell(0, 5, $rec);
        }
        
        $this->Ln(5);
        
        // Operational Recommendations
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'Operational Recommendations', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 10);
        $opRecommendations = [
            "• Encourage digital payment adoption to reduce cash handling risks",
            "• Implement automated reminders for pending collections",
            "• Schedule regular member engagement sessions to improve participation",
            "• Review and optimize interest calculation methods for fairness",
            "• Maintain detailed transaction records for audit compliance"
        ];
        
        foreach ($opRecommendations as $rec) {
            $this->MultiCell(0, 5, $rec);
        }
        
        $this->Ln(5);
        
        // Action Items
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 8, 'Immediate Action Items', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 10);
        $actions = [
            ['Priority', 'Action Item', 'Timeline'],
            ['High', 'Review and reconcile all outstanding balances', '1 Week'],
            ['High', 'Contact low-activity members for engagement', '2 Weeks'],
            ['Medium', 'Analyze payment mode trends and optimize', '1 Month'],
            ['Medium', 'Generate quarterly financial projections', '1 Month'],
            ['Low', 'Plan member appreciation events', '2 Months']
        ];
        
        // Action items table
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(30, 7, $actions[0][0], 1, 0, 'C', true);
        $this->Cell(120, 7, $actions[0][1], 1, 0, 'C', true);
        $this->Cell(40, 7, $actions[0][2], 1, 1, 'C', true);
        
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(71, 85, 105);
        for ($i = 1; $i < count($actions); $i++) {
            $this->Cell(30, 6, $actions[$i][0], 1, 0, 'C');
            $this->Cell(120, 6, $actions[$i][1], 1, 0, 'L');
            $this->Cell(40, 6, $actions[$i][2], 1, 1, 'C');
        }
    }
    
    // Glossary and Methodology
    function AddGlossaryAndMethodology()
    {
        $this->AddPage();
        
        $this->SetFont('Arial', 'B', 16);
        $this->setPrimaryColor();
        $this->Cell(0, 10, 'Glossary & Methodology', 0, 1, 'L');
        $this->Ln(3);
        
        // Glossary
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(0, 8, 'Key Terms', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 9);
        $terms = [
            ['Collection:', 'Money received from members as contributions or repayments'],
            ['Distribution:', 'Money disbursed to members as loans or benefits'],
            ['Net Balance:', 'Total Collections minus Total Distributions'],
            ['Interest:', 'Calculated charge on distributed amounts based on academic year rates'],
            ['Collection Efficiency:', 'Ratio of collections to distributions, expressed as percentage'],
            ['Transaction Count:', 'Total number of financial transactions recorded'],
            ['Academic Year:', 'Financial period defined in the system (typically April-March)'],
        ];
        
        foreach ($terms as $term) {
            $this->SetFont('Arial', 'B', 9);
            $this->Cell(45, 5, $term[0], 0, 0, 'L');
            $this->SetFont('Arial', '', 9);
            $this->MultiCell(0, 5, $term[1]);
        }
        
        $this->Ln(5);
        
        // Methodology
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(0, 8, 'Calculation Methodology', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(0, 5, 
            "Growth Rate: Calculated as ((Current Month - Previous Month) / Previous Month) * 100\n\n" .
            "Collection Efficiency: (Total Collection / Total Distribution) * 100\n\n" .
            "Average Transaction Value: Total Collection / Number of Transactions\n\n" .
            "Coefficient of Variation: (Standard Deviation / Mean) * 100\n\n" .
            "Net Cash Flow: Total Inflows (Collections) - Total Outflows (Distributions)\n\n" .
            "All monetary values are in Indian Rupees (Rs). Percentages are rounded to two decimal places. " .
            "Data is filtered based on transaction dates within the selected reporting period."
        );
    }
    
    // Report Conclusion
    function AddConclusion($summary)
    {
        $this->AddPage();
        
        $this->SetFont('Arial', 'B', 16);
        $this->setPrimaryColor();
        $this->Cell(0, 10, 'Report Conclusion', 0, 1, 'L');
        $this->Ln(3);
        
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(71, 85, 105);
        
        $netBalance = ($summary['totalCollection'] ?? 0) - ($summary['totalDistribution'] ?? 0);
        $status = $netBalance >= 0 ? 'healthy' : 'requires attention';
        
        $this->MultiCell(0, 6, sprintf(
            "This comprehensive report provides a detailed analysis of the SR Colony Chitti Management System's " .
            "financial activities and member engagement for the selected period.\n\n" .
            "Key Findings:\n" .
            "• Total of %d transactions processed\n" .
            "• Net financial position of Rs %s indicating a %s status\n" .
            "• %d active members participated in the period\n" .
            "• Total collections: Rs %s\n" .
            "• Total distributions: Rs %s\n\n" .
            "The data presented in this report is accurate as of the generation date and reflects all transactions " .
            "within the specified date range. Regular monitoring and analysis of these metrics will help ensure " .
            "sustainable growth and member satisfaction.\n\n" .
            "For any questions or clarifications regarding this report, please contact the SR Colony management team.",
            $summary['totalTransactions'] ?? 0,
            number_format(abs($netBalance), 2),
            $status,
            $summary['totalMembers'] ?? 0,
            number_format($summary['totalCollection'] ?? 0, 2),
            number_format($summary['totalDistribution'] ?? 0, 2)
        ));
        
        $this->Ln(10);
        
        // Report Metadata
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(127, 140, 141);
        $this->MultiCell(0, 4, 
            "Report Generated: " . date('F d, Y h:i A') . "\n" .
            "System: SR Colony Chitti Management System\n" .
            "Report Version: 2.0\n" .
            "Confidentiality: Internal Use Only"
        );
    }
    
    // Top Collectors Table
    function AddTopCollectors($topData)
    {
        $this->SetFont('Arial', 'B', 14);
        $this->setPrimaryColor();
        $this->Cell(0, 10, 'Top 5 Collectors', 0, 1, 'L');
        
        if (empty($topData)) {
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(150, 150, 150);
            $this->Cell(0, 8, 'No collector data available', 0, 1, 'C');
            $this->Ln(5);
            return;
        }
        
        // Table header
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255, 255, 255);
        
        $this->Cell(20, 8, 'Rank', 1, 0, 'C', true);
        $this->Cell(100, 8, 'Member Name', 1, 0, 'C', true);
        $this->Cell(70, 8, 'Total Collection', 1, 1, 'C', true);
        
        // Table data
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(71, 85, 105);
        
        $rank = 1;
        $fill = false;
        foreach ($topData as $row) {
            $this->SetFillColor(245, 245, 245);
            
            $this->Cell(20, 7, $rank++, 1, 0, 'C', $fill);
            $this->Cell(100, 7, $row['MemberName'], 1, 0, 'L', $fill);
            $this->Cell(70, 7, 'Rs ' . number_format($row['TotalCollection'], 2), 1, 1, 'R', $fill);
            
            $fill = !$fill;
        }
        
        $this->Ln(5);
    }
    
    // Member Summary Table
    function AddMemberSummary($memberData)
    {
        $this->AddPage();
        
        $this->SetFont('Arial', 'B', 14);
        $this->setPrimaryColor();
        $this->Cell(0, 10, 'Member-wise Summary', 0, 1, 'L');
        
        if (empty($memberData)) {
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(150, 150, 150);
            $this->Cell(0, 8, 'No member data available', 0, 1, 'C');
            return;
        }
        
        // Modern Table header
        $this->SetFont('Arial', 'B', 9);
        $this->setPrimaryFill();
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(226, 232, 240);
        
        $this->Cell(60, 8, 'Member Name', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Trans.', 1, 0, 'C', true);
        $this->Cell(35, 8, 'Collection', 1, 0, 'C', true);
        $this->Cell(35, 8, 'Distribution', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Interest', 1, 1, 'C', true);
        
        // Table data
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(71, 85, 105);
        
        $fill = false;
        foreach ($memberData as $row) {
            // Check if we need a new page
            if ($this->GetY() > 250) {
                $this->AddPage();
                
                // Repeat header
                $this->SetFont('Arial', 'B', 9);
                $this->SetFillColor(220, 38, 38);
                $this->SetTextColor(255, 255, 255);
                
                $this->Cell(60, 8, 'Member Name', 1, 0, 'C', true);
                $this->Cell(30, 8, 'Trans.', 1, 0, 'C', true);
                $this->Cell(35, 8, 'Collection', 1, 0, 'C', true);
                $this->Cell(35, 8, 'Distribution', 1, 0, 'C', true);
                $this->Cell(30, 8, 'Interest', 1, 1, 'C', true);
                
                $this->SetFont('Arial', '', 8);
                $this->SetTextColor(71, 85, 105);
            }
            
            // Modern alternating rows
            if ($fill) {
                $this->SetFillColor(248, 250, 252); // Light gray-blue
            } else {
                $this->SetFillColor(255, 255, 255); // White
            }
            $this->SetDrawColor(226, 232, 240);
            
            $this->Cell(60, 6, $row['MemberName'], 1, 0, 'L', $fill);
            $this->Cell(30, 6, $row['TransactionCount'] ?? 0, 1, 0, 'C', $fill);
            $this->Cell(35, 6, 'Rs ' . number_format($row['TotalCollection'] ?? 0, 2), 1, 0, 'R', $fill);
            $this->Cell(35, 6, 'Rs ' . number_format($row['TotalDistribution'] ?? 0, 2), 1, 0, 'R', $fill);
            $this->Cell(30, 6, 'Rs ' . number_format($row['TotalInterest'] ?? 0, 2), 1, 1, 'R', $fill);
            
            $fill = !$fill;
        }
    }
    
    // Year Summary Table
    function AddYearSummary($yearData)
    {
        $this->AddPage();
        
        $this->SetFont('Arial', 'B', 14);
        $this->setPrimaryColor();
        $this->Cell(0, 10, 'Year-wise Summary', 0, 1, 'L');
        
        if (empty($yearData)) {
            $this->SetFont('Arial', 'I', 10);
            $this->SetTextColor(150, 150, 150);
            $this->Cell(0, 8, 'No year data available', 0, 1, 'C');
            return;
        }
        
        // Modern Table header
        $this->SetFont('Arial', 'B', 10);
        $this->setPrimaryFill();
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(226, 232, 240);
        
        $this->Cell(50, 8, 'Academic Year', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Trans.', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Collection', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Distribution', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Interest', 1, 1, 'C', true);
        
        // Table data
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(71, 85, 105);
        
        $fill = false;
        foreach ($yearData as $row) {
            // Modern alternating rows
            if ($fill) {
                $this->SetFillColor(248, 250, 252); // Light gray-blue
            } else {
                $this->SetFillColor(255, 255, 255); // White
            }
            $this->SetDrawColor(226, 232, 240);
            
            $this->Cell(50, 7, $row['YearName'], 1, 0, 'L', $fill);
            $this->Cell(30, 7, $row['TransactionCount'] ?? 0, 1, 0, 'C', $fill);
            $this->Cell(40, 7, 'Rs ' . number_format($row['TotalCollection'] ?? 0, 2), 1, 0, 'R', $fill);
            $this->Cell(40, 7, 'Rs ' . number_format($row['TotalDistribution'] ?? 0, 2), 1, 0, 'R', $fill);
            $this->Cell(30, 7, 'Rs ' . number_format($row['TotalInterest'] ?? 0, 2), 1, 1, 'R', $fill);
            
            $fill = !$fill;
        }
    }
}

// Fetch all data with the same queries as reports.php
try {
    // Build WHERE clause based on member filter
    $whereClause = "t.TransactionDate BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    
    if ($memberID) {
        $whereClause .= " AND t.MemberID = ?";
        $params[] = $memberID;
    }
    
    // Summary statistics - Fixed query
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM members" . ($memberID ? " WHERE MemberID = $memberID" : "") . ") as totalMembers,
            (SELECT COUNT(*) FROM academic_years) as totalYears,
            COUNT(t.TransactionID) as totalTransactions,
            COALESCE(SUM(t.CollectionAmount), 0) as totalCollection,
            COALESCE(SUM(t.DistributedAmount), 0) as totalDistribution,
            COALESCE(SUM(t.InterestAmount), 0) as totalInterest
        FROM transactions t
        WHERE $whereClause
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Monthly trend
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(t.TransactionDate, '%Y-%m') as Month,
            DATE_FORMAT(t.TransactionDate, '%b %Y') as MonthName,
            SUM(t.CollectionAmount) as Collection,
            SUM(t.DistributedAmount) as Distribution,
            SUM(t.InterestAmount) as Interest,
            COUNT(*) as TransactionCount
        FROM transactions t
        WHERE $whereClause
        GROUP BY DATE_FORMAT(t.TransactionDate, '%Y-%m')
        ORDER BY Month ASC
    ");
    $stmt->execute($params);
    $monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Payment modes
    $stmt = $pdo->prepare("
        SELECT 
            t.PaymentMode,
            COUNT(*) as Count,
            SUM(t.CollectionAmount) as TotalAmount
        FROM transactions t
        WHERE $whereClause
        GROUP BY t.PaymentMode
        ORDER BY TotalAmount DESC
    ");
    $stmt->execute($params);
    $paymentModes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top collectors
    $stmt = $pdo->prepare("
        SELECT 
            m.MemberName,
            SUM(t.CollectionAmount) as TotalCollection
        FROM transactions t
        LEFT JOIN members m ON t.MemberID = m.MemberID
        WHERE $whereClause
        GROUP BY t.MemberID
        ORDER BY TotalCollection DESC
        LIMIT 5
    ");
    $stmt->execute($params);
    $topCollectors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Member summary
    $memberJoinCondition = "t.TransactionDate BETWEEN ? AND ?";
    $memberJoinParams = [$dateFrom, $dateTo];
    if ($memberID) {
        $memberJoinCondition .= " AND t.MemberID = ?";
        $memberJoinParams[] = $memberID;
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            m.MemberID,
            m.MemberName,
            COUNT(t.TransactionID) as TransactionCount,
            SUM(t.CollectionAmount) as TotalCollection,
            SUM(t.DistributedAmount) as TotalDistribution,
            SUM(t.InterestAmount) as TotalInterest
        FROM members m
        LEFT JOIN transactions t ON m.MemberID = t.MemberID AND $memberJoinCondition
        " . ($memberID ? "WHERE m.MemberID = $memberID" : "") . "
        GROUP BY m.MemberID
        HAVING TransactionCount > 0
        ORDER BY TotalCollection DESC
    ");
    $stmt->execute($memberJoinParams);
    $memberSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Year summary
    $stmt = $pdo->prepare("
        SELECT 
            ay.YearID,
            ay.YearName,
            COUNT(t.TransactionID) as TransactionCount,
            SUM(t.CollectionAmount) as TotalCollection,
            SUM(t.DistributedAmount) as TotalDistribution,
            SUM(t.InterestAmount) as TotalInterest
        FROM academic_years ay
        LEFT JOIN transactions t ON ay.YearID = t.YearID AND t.TransactionDate BETWEEN ? AND ?
        GROUP BY ay.YearID
        HAVING TransactionCount > 0
        ORDER BY ay.StartDate DESC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $yearSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create PDF
    $pdf = new ReportPDF();
    $pdf->setColors($primaryRgb, $accentRgb);
    $pdf->setDateRange($dateFrom, $dateTo);
    $pdf->AliasNbPages();
    $pdf->AddPage();
    
    // Add Executive Summary First
    $pdf->AddExecutiveSummary($summary);
    
    // Add all sections with data and insights
    $pdf->AddSummaryStats($summary);
    
    // Add Monthly Trend Chart if available
    if (!empty($chartImages['monthlyTrend'])) {
        $pdf->AddPage();
        $pdf->AddChartImage(
            'Monthly Collection Trend Chart',
            $chartImages['monthlyTrend'],
            'Visual representation of collections, distributions, and interest across months.'
        );
    }
    $pdf->AddMonthlyTrend($monthlyTrend);
    
    // Add Payment Mode Chart if available
    if (!empty($chartImages['paymentMode'])) {
        $pdf->AddPage();
        $pdf->AddChartImage(
            'Payment Mode Distribution Chart',
            $chartImages['paymentMode'],
            'Breakdown of transactions by payment method (Cash, Online, UPI, etc.).'
        );
    }
    $pdf->AddPaymentModes($paymentModes);
    
    // Add Top Collectors Chart if available
    if (!empty($chartImages['topCollectors'])) {
        $pdf->AddPage();
        $pdf->AddChartImage(
            'Top Collectors Chart',
            $chartImages['topCollectors'],
            'Top 5 members with highest collection amounts during the selected period.'
        );
    }
    $pdf->AddTopCollectors($topCollectors);
    
    // Only add advanced charts if they have valid data
    $hasAdvancedCharts = false;
    
    // Add Growth Rate Chart if available and valid
    if (!empty($chartImages['growthRate']) && strpos($chartImages['growthRate'], 'data:image') === 0) {
        if (!$hasAdvancedCharts) {
            $pdf->AddPage();
            $hasAdvancedCharts = true;
        }
        $pdf->AddChartImage(
            'Growth Rate Analysis Chart',
            $chartImages['growthRate'],
            'Month-over-month growth rate showing collection trends and patterns.'
        );
    }
    
    // Add Collection vs Distribution Ratio Chart if available and valid
    if (!empty($chartImages['collectionRatio']) && strpos($chartImages['collectionRatio'], 'data:image') === 0) {
        if (!$hasAdvancedCharts) {
            $pdf->AddPage();
            $hasAdvancedCharts = true;
        }
        $pdf->AddChartImage(
            'Collection vs Distribution Ratio Chart',
            $chartImages['collectionRatio'],
            'Comparative analysis of collections against distributions over time.'
        );
    }
    
    // Add Time Distribution Chart if available and valid
    if (!empty($chartImages['timeDistribution']) && strpos($chartImages['timeDistribution'], 'data:image') === 0) {
        if (!$hasAdvancedCharts) {
            $pdf->AddPage();
        }
        $pdf->AddChartImage(
            'Transaction Time Distribution Chart',
            $chartImages['timeDistribution'],
            'Distribution of transactions by time of day (hourly breakdown).'
        );
    }
    
    // Add Custom Chart if available and valid
    if (!empty($chartImages['customChart']) && strpos($chartImages['customChart'], 'data:image') === 0) {
        $pdf->AddPage();
        $pdf->AddChartImage(
            'Custom Chart Visualization',
            $chartImages['customChart'],
            'User-generated custom chart based on selected metrics and dimensions.'
        );
    }
    
    $pdf->AddTopCollectors($topCollectors);
    
    // Page 6-7: Detailed Financial Analysis
    $pdf->AddFinancialAnalysis($summary, $monthlyTrend);
    
    // Page 8-9: Transaction Pattern Analysis
    $pdf->AddTransactionPatternAnalysis($monthlyTrend);
    
    // Page 10: Member Engagement Report
    $pdf->AddMemberEngagementReport($memberSummary);
    
    // Page 11: Member Summary Table
    $pdf->AddMemberSummary($memberSummary);
    
    // Page 12: Year Summary Table
    $pdf->AddYearSummary($yearSummary);
    
    // Page 13: Recommendations & Action Items
    $pdf->AddRecommendations($summary, $monthlyTrend, $memberSummary);
    
    // Page 14: Glossary & Methodology
    $pdf->AddGlossaryAndMethodology();
    
    // Page 15: Report Conclusion
    $pdf->AddConclusion($summary);
    
    // Output PDF
    $filename = 'SR_Colony_Comprehensive_Report_' . date('Y-m-d') . '.pdf';
    $pdf->Output('D', $filename);
    
} catch (Exception $e) {
    die('Error generating PDF: ' . $e->getMessage());
}
?>
