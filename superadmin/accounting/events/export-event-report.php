<?php
/**
 * Event Report PDF Export Handler
 */
session_start();
require_once '../session.php';
require_once '../../../config/config.php';

// Check SuperAdmin access
if (!isset($_SESSION['superadmin_id']) || $_SESSION['user_type'] !== 'SuperAdmin') {
    http_response_code(403);
    echo 'Access Denied. SuperAdmin only.';
    exit;
}

try {
    // Get filter parameters
    $selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';
    $statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
    $eventType = isset($_GET['type']) ? $_GET['type'] : 'all';
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01');
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

    // Validate dates
    if (!strtotime($startDate) || !strtotime($endDate)) {
        throw new Exception('Invalid date format');
    }

    // Build filter conditions
    $whereConditions = ["e.EventDate BETWEEN ? AND ?"];
    $params = [$startDate, $endDate];

    if ($selectedYear !== 'all') {
        $whereConditions[] = "e.YearID = ?";
        $params[] = $selectedYear;
    }

    if ($statusFilter !== 'all') {
        $whereConditions[] = "e.Status = ?";
        $params[] = $statusFilter;
    }

    if ($eventType !== 'all') {
        $whereConditions[] = "e.EventType = ?";
        $params[] = $eventType;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Get summary statistics
    $summaryQuery = "SELECT 
        COUNT(*) as total_events,
        IFNULL(SUM(e.TotalCollection), 0) as total_collections,
        IFNULL(SUM(e.TotalExpense), 0) as total_expenses,
        IFNULL(SUM(e.EventBalance), 0) as total_balance,
        IFNULL(SUM(e.BudgetAmount), 0) as total_budget
    FROM events e $whereClause";
    $summaryStmt = $pdo->prepare($summaryQuery);
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    // Events by type
    $typeQuery = "SELECT 
        e.EventType,
        COUNT(*) as count,
        IFNULL(SUM(e.TotalCollection), 0) as collections,
        IFNULL(SUM(e.TotalExpense), 0) as expenses,
        IFNULL(SUM(e.EventBalance), 0) as balance
    FROM events e $whereClause
    GROUP BY e.EventType
    ORDER BY count DESC";
    $typeStmt = $pdo->prepare($typeQuery);
    $typeStmt->execute($params);
    $eventsByType = $typeStmt->fetchAll(PDO::FETCH_ASSOC);

    // Detailed events list
    $eventsQuery = "SELECT 
        e.*,
        ay.YearName
    FROM events e
    LEFT JOIN academic_years ay ON e.YearID = ay.YearID
    $whereClause
    ORDER BY EventDate DESC
    LIMIT 100";
    $eventsStmt = $pdo->prepare($eventsQuery);
    $eventsStmt->execute($params);
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if FPDF is available
    $fpdfPath = __DIR__ . '/../fpdf.php';
    if (!file_exists($fpdfPath)) {
        $fpdfPath = __DIR__ . '/../../fpdf.php';
    }
    if (!file_exists($fpdfPath)) {
        $fpdfPath = __DIR__ . '/../../../includes/fpdf.php';
    }

    if (!file_exists($fpdfPath)) {
        throw new Exception('PDF library not available');
    }

    require_once $fpdfPath;

    class EventReportPDF extends FPDF {
        private $filterText = '';
        
        public function setFilterText($text) {
            $this->filterText = $text;
        }

        public function Header() {
            // Dark header background
            $this->SetFillColor(25, 30, 45);
            $this->Rect(0, 0, 210, 35, 'F');
            
            // Title
            $this->SetFont('Helvetica', 'B', 18);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 10, 'SR Colony Chitti Management', 0, 1, 'L');
            
            // Subtitle
            $this->SetFont('Helvetica', '', 11);
            $this->SetTextColor(200, 200, 210);
            $this->Cell(0, 6, 'Event Management Report', 0, 1, 'L');
            
            // Generated date on right
            $this->SetY(10);
            $this->SetFont('Helvetica', '', 9);
            $this->SetTextColor(150, 150, 160);
            $this->Cell(0, 6, 'Generated: ' . date('d M Y, h:i A'), 0, 1, 'R');
            
            // Filter bar
            $this->SetY(28);
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetTextColor(255, 255, 255);
            $this->SetFillColor(25, 30, 45);
            $this->Cell(0, 6, 'Filters: ' . $this->filterText, 0, 1, 'L', true);
            
            $this->SetY(40);
        }

        public function Footer() {
            $this->SetY(-20);
            $this->SetFont('Helvetica', 'I', 8);
            $this->SetTextColor(150, 150, 160);
            $this->Cell(0, 5, 'Report generated on ' . date('d M Y') . ' at ' . date('h:i A'), 0, 1, 'L');
            $this->SetFont('Helvetica', 'I', 8);
            $this->Cell(0, 5, 'Prepared by: Super Administrator', 0, 1, 'L');
            
            $this->SetY(-10);
            $this->SetFont('Helvetica', 'I', 8);
            $this->SetTextColor(150, 150, 160);
            $this->Cell(0, 10, 'Page ' . $this->PageNo(), 0, 0, 'C');
        }

        public function SummaryStats($label1, $value1, $label2, $value2, $label3, $value3) {
            // Draw 3 summary cards
            $colWidth = 55;
            $cardHeight = 18;
            $spacing = 5;
            
            // Card 1
            $this->SetFillColor(245, 245, 250);
            $this->Rect(10, $this->GetY(), $colWidth, $cardHeight, 'F');
            $this->SetLineWidth(0.5);
            $this->SetDrawColor(100, 100, 150);
            $this->Rect(10, $this->GetY(), $colWidth, $cardHeight);
            
            $this->SetFont('Helvetica', 'B', 8);
            $this->SetTextColor(80, 100, 130);
            $this->SetXY(11, $this->GetY() + 2);
            $this->Cell($colWidth - 2, 4, $label1, 0, 1);
            
            $this->SetFont('Helvetica', 'B', 12);
            $this->SetTextColor(30, 60, 100);
            $this->SetXY(11, $this->GetY());
            $this->Cell($colWidth - 2, 6, $value1, 0, 1);
            
            // Card 2
            $y = $this->GetY() - $cardHeight;
            $x2 = 10 + $colWidth + $spacing;
            
            $this->SetFillColor(245, 250, 245);
            $this->Rect($x2, $y, $colWidth, $cardHeight, 'F');
            $this->SetDrawColor(100, 150, 100);
            $this->Rect($x2, $y, $colWidth, $cardHeight);
            
            $this->SetFont('Helvetica', 'B', 8);
            $this->SetTextColor(80, 130, 100);
            $this->SetXY($x2 + 1, $y + 2);
            $this->Cell($colWidth - 2, 4, $label2, 0, 1);
            
            $this->SetFont('Helvetica', 'B', 12);
            $this->SetTextColor(30, 100, 60);
            $this->SetXY($x2 + 1, $y + 6);
            $this->Cell($colWidth - 2, 6, $value2, 0, 1);
            
            // Card 3
            $x3 = 10 + ($colWidth + $spacing) * 2;
            
            $this->SetFillColor(250, 245, 245);
            $this->Rect($x3, $y, $colWidth, $cardHeight, 'F');
            $this->SetDrawColor(200, 100, 100);
            $this->Rect($x3, $y, $colWidth, $cardHeight);
            
            $this->SetFont('Helvetica', 'B', 8);
            $this->SetTextColor(150, 80, 80);
            $this->SetXY($x3 + 1, $y + 2);
            $this->Cell($colWidth - 2, 4, $label3, 0, 1);
            
            $this->SetFont('Helvetica', 'B', 12);
            $this->SetTextColor(150, 50, 50);
            $this->SetXY($x3 + 1, $y + 6);
            $this->Cell($colWidth - 2, 6, $value3, 0, 1);
            
            $this->SetXY(10, $y + $cardHeight + 2);
        }

        public function SectionTitle($title) {
            $this->Ln(4);
            $this->SetFont('Helvetica', 'B', 11);
            $this->SetTextColor(25, 50, 90);
            $this->SetFillColor(220, 235, 250);
            $this->Cell(0, 7, $title, 0, 1, 'L', true);
            $this->SetTextColor(0, 0, 0);
        }

        public function TableHeader($headers, $widths) {
            $this->SetFont('Helvetica', 'B', 8);
            $this->SetFillColor(25, 40, 70);
            $this->SetTextColor(255, 255, 255);
            
            for ($i = 0; $i < count($headers); $i++) {
                $this->Cell($widths[$i], 6, $headers[$i], 1, 0, 'C', true);
            }
            $this->Ln();
            $this->SetTextColor(0, 0, 0);
        }

        public function TableRow($cells, $widths, $fill = false) {
            $this->SetFont('Helvetica', '', 8);
            $bgColor = $fill ? 240 : 255;
            $this->SetFillColor($bgColor, $bgColor, $bgColor);
            
            for ($i = 0; $i < count($cells); $i++) {
                $this->Cell($widths[$i], 5, $cells[$i], 1, 0, 'L', true);
            }
            $this->Ln();
        }

        public function GrandTotal($amount) {
            $this->SetFillColor(25, 40, 70);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Helvetica', 'B', 10);
            $this->Cell(120, 7, 'GRAND TOTAL', 1, 0, 'R', true);
            $this->Cell(70, 7, $amount, 1, 0, 'R', true);
            $this->Ln();
        }
    }

    // Create PDF
    $pdf = new EventReportPDF();
    $pdf->AddPage();

    // Build filter text
    $filterText = "From " . date('d M Y', strtotime($startDate)) . " To " . date('d M Y', strtotime($endDate));
    if ($selectedYear !== 'all') $filterText .= " | Year: $selectedYear";
    if ($statusFilter !== 'all') $filterText .= " | Status: $statusFilter";
    if ($eventType !== 'all') $filterText .= " | Type: $eventType";
    
    $pdf->setFilterText($filterText);
    $pdf->AddPage(); // Refresh to show header with filter

    // Summary cards
    $pdf->SummaryStats(
        'TOTAL EVENTS',
        $summary['total_events'],
        'TOTAL COLLECTIONS',
        '₹' . number_format($summary['total_collections'], 2),
        'TOTAL EXPENSES',
        '₹' . number_format($summary['total_expenses'], 2)
    );

    // Additional summary info
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(80, 80, 100);
    $pdf->Ln(3);
    
    $summaryData = [
        ['NET BALANCE', '₹' . number_format($summary['total_balance'], 2)],
        ['TOTAL BUDGET', '₹' . number_format($summary['total_budget'], 2)]
    ];
    
    foreach ($summaryData as $stat) {
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(80, 80, 100);
        $pdf->Cell(100, 5, $stat[0], 0, 0);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(30, 60, 100);
        $pdf->Cell(85, 5, $stat[1], 0, 1);
    }
    
    $pdf->Ln(2);

    // Events by Type section
    if (!empty($eventsByType)) {
        $pdf->SectionTitle('EVENTS BY TYPE');
        
        $headers = ['Event Type', 'Count', 'Collections', 'Expenses', 'Balance'];
        $widths = [40, 20, 35, 35, 35];
        $pdf->TableHeader($headers, $widths);

        $alt = false;
        foreach ($eventsByType as $event) {
            $pdf->TableRow([
                $event['EventType'],
                (string)$event['count'],
                '₹' . number_format($event['collections'], 2),
                '₹' . number_format($event['expenses'], 2),
                '₹' . number_format($event['balance'], 2)
            ], $widths, $alt);
            $alt = !$alt;
        }
        $pdf->Ln(2);
    }

    // Detail Events section
    if (!empty($events)) {
        $pdf->SectionTitle('EVENT DETAILS');
        
        $headers = ['#', 'Date', 'Event Name', 'Type', 'Collection', 'Expense', 'Balance'];
        $widths = [8, 22, 45, 25, 28, 28, 28];
        $pdf->TableHeader($headers, $widths);

        $alt = false;
        $count = 1;
        foreach ($events as $event) {
            $eventDate = date('d M Y', strtotime($event['EventDate']));
            $eventName = substr($event['EventName'] ?? '', 0, 18);
            
            $pdf->TableRow([
                (string)$count,
                $eventDate,
                $eventName,
                $event['EventType'] ?? '',
                '₹' . number_format($event['TotalCollection'] ?? 0, 2),
                '₹' . number_format($event['TotalExpense'] ?? 0, 2),
                '₹' . number_format($event['EventBalance'] ?? 0, 2)
            ], $widths, $alt);
            $alt = !$alt;
            $count++;
        }
        
        $pdf->Ln(2);
        $pdf->GrandTotal('₹' . number_format($summary['total_balance'], 2));
    }

    // Output PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="event-report_' . date('Y-m-d_His') . '.pdf"');
    $pdf->Output('D', 'event-report_' . date('Y-m-d_His') . '.pdf');
    exit;

} catch (Exception $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}
?>
