<?php
// PDF Export for Transactions
// URL: superadmin/export-transactions-pdf.php

require_once './session.php';
require_once '../config/config.php';

// Require super admin authentication
if (!isset($_SESSION['superadmin_id'])) {
    header('Location: login.php');
    exit();
}

// Include FPDF library
require_once 'fpdf.php';

class PDF extends FPDF
{
    private $companyName = 'SR Colony';
    private $reportTitle = 'Transaction Report';
    private $filterInfo = '';
    
    function setFilterInfo($info) {
        $this->filterInfo = $info;
    }
    
    // Page header
    function Header()
    {
        // Logo (if you have one)
        // $this->Image('logo.png', 10, 6, 30);
        
        // Company Name
        $this->SetFont('Arial', 'B', 20);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(0, 10, $this->companyName, 0, 1, 'C');
        
        // Report Title
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(0, 8, $this->reportTitle, 0, 1, 'C');
        
        // Date Generated
        $this->SetFont('Arial', 'I', 10);
        $this->SetTextColor(127, 140, 141);
        $this->Cell(0, 6, 'Generated on: ' . date('F d, Y h:i A'), 0, 1, 'C');
        
        // Filter Information
        if (!empty($this->filterInfo)) {
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(52, 73, 94);
            $this->MultiCell(0, 5, $this->filterInfo, 0, 'C');
        }
        
        $this->Ln(3);
    }
    
    // Page footer
    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(127, 140, 141);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        
        // Add timestamp
        $this->SetY(-15);
        $this->Cell(0, 10, 'SR Colony Transaction System', 0, 0, 'L');
    }
    
    // Summary section
    function AddSummary($stats)
    {
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(0, 10, 'Summary Statistics', 0, 1, 'L');
        
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(52, 73, 94);
        
        // Summary boxes
        $boxWidth = 63;
        $boxHeight = 20;
        
        // Total Collection
        $this->SetFillColor(46, 204, 113);
        $this->SetTextColor(255, 255, 255);
        $this->Cell($boxWidth, $boxHeight/2, 'Total Collection', 1, 0, 'C', true);
        
        // Total Distribution
        $this->SetFillColor(52, 152, 219);
        $this->Cell($boxWidth, $boxHeight/2, 'Total Distribution', 1, 0, 'C', true);
        
        // Total Interest
        $this->SetFillColor(155, 89, 182);
        $this->Cell($boxWidth, $boxHeight/2, 'Total Interest', 1, 1, 'C', true);
        
        // Values
        $this->SetFont('Arial', 'B', 14);
        $this->SetFillColor(236, 240, 241);
        $this->SetTextColor(39, 174, 96);
        $this->Cell($boxWidth, $boxHeight/2, chr(8377) . ' ' . number_format($stats['totalCollection'], 2), 1, 0, 'C', true);
        
        $this->SetTextColor(41, 128, 185);
        $this->Cell($boxWidth, $boxHeight/2, chr(8377) . ' ' . number_format($stats['totalDistribution'], 2), 1, 0, 'C', true);
        
        $this->SetTextColor(142, 68, 173);
        $this->Cell($boxWidth, $boxHeight/2, chr(8377) . ' ' . number_format($stats['totalInterest'], 2), 1, 1, 'C', true);
        
        $this->Ln(5);
        
        // Transaction counts
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(95, 6, 'Total Transactions: ' . $stats['total'], 0, 0, 'L');
        $this->Cell(95, 6, 'Verified: ' . $stats['verified'] . ' | Unverified: ' . $stats['unverified'], 0, 1, 'R');
        
        $this->Ln(8);
        
        // Add Table Header after summary
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(41, 128, 185);
        
        // Column widths
        $this->Cell(10, 8, 'ID', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Date', 1, 0, 'C', true);
        $this->Cell(40, 8, 'Member', 1, 0, 'C', true);
        $this->Cell(30, 8, 'Year', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Collection', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Distribution', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Interest', 1, 0, 'C', true);
        $this->Cell(25, 8, 'Mode', 1, 0, 'C', true);
        $this->Cell(15, 8, 'Status', 1, 1, 'C', true);
    }
}

// Get filter parameters
$filterYear = isset($_GET['year']) ? intval($_GET['year']) : 0;
$filterMember = isset($_GET['member']) ? intval($_GET['member']) : 0;
$filterMode = isset($_GET['mode']) ? $_GET['mode'] : '';
$filterVerified = isset($_GET['verified']) ? $_GET['verified'] : '';
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build filter info string
$filterParts = [];
if ($filterYear > 0) {
    $stmt = $pdo->prepare("SELECT YearName FROM academic_years WHERE YearID = ?");
    $stmt->execute([$filterYear]);
    $yearName = $stmt->fetchColumn();
    if ($yearName) $filterParts[] = "Year: $yearName";
}
if ($filterMember > 0) {
    $stmt = $pdo->prepare("SELECT MemberName FROM members WHERE MemberID = ?");
    $stmt->execute([$filterMember]);
    $memberName = $stmt->fetchColumn();
    if ($memberName) $filterParts[] = "Member: $memberName";
}
if (!empty($filterMode)) $filterParts[] = "Payment Mode: $filterMode";
if ($filterVerified === '1') $filterParts[] = "Status: Verified Only";
if ($filterVerified === '0') $filterParts[] = "Status: Unverified Only";
if (!empty($searchTerm)) $filterParts[] = "Search: $searchTerm";
if (!empty($dateFrom)) $filterParts[] = "From: $dateFrom";
if (!empty($dateTo)) $filterParts[] = "To: $dateTo";

$filterInfo = !empty($filterParts) ? "Filters Applied: " . implode(' | ', $filterParts) : "All Transactions (No Filters)";

// Check if Verified column exists
$columnCheck = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'Verified'");
$verifiedColumnExists = $columnCheck->rowCount() > 0;

// Fetch transactions
$query = "
    SELECT 
        t.TransactionID,
        t.YearID,
        t.MemberID,
        t.TransactionDate,
        t.CollectionAmount,
        t.DistributedAmount,
        t.InterestAmount,
        t.NextMonthDue,
        t.PaymentMode,
        t.Remarks,
        DATE_FORMAT(t.TransactionDate, '%M %Y') AS MonthName,
        m.MemberName,
        m.Phone as MemberPhone,
        y.YearName";

if ($verifiedColumnExists) {
    $query .= ",\n        t.Verified";
}

$query .= "
FROM transactions t
LEFT JOIN members m ON t.MemberID = m.MemberID
LEFT JOIN academic_years y ON t.YearID = y.YearID
WHERE 1=1
";

$params = [];

if ($filterYear > 0) {
    $query .= " AND t.YearID = ?";
    $params[] = $filterYear;
}

if ($filterMember > 0) {
    $query .= " AND t.MemberID = ?";
    $params[] = $filterMember;
}

if (!empty($filterMode)) {
    $query .= " AND t.PaymentMode = ?";
    $params[] = $filterMode;
}

if (!empty($searchTerm)) {
    $query .= " AND (m.MemberName LIKE ? OR m.Phone LIKE ? OR t.Remarks LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}

if (!empty($dateFrom)) {
    $query .= " AND t.TransactionDate >= ?";
    $params[] = $dateFrom;
}

if (!empty($dateTo)) {
    $query .= " AND t.TransactionDate <= ?";
    $params[] = $dateTo;
}

if ($verifiedColumnExists && $filterVerified !== '') {
    $query .= " AND t.Verified = ?";
    $params[] = intval($filterVerified);
}

$query .= " ORDER BY t.TransactionDate DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    $totalCollection = 0;
    $totalDistribution = 0;
    $totalInterest = 0;
    $verified = 0;
    $unverified = 0;
    
    foreach ($transactions as $trans) {
        $totalCollection += $trans['CollectionAmount'];
        $totalDistribution += $trans['DistributedAmount'];
        $totalInterest += $trans['InterestAmount'];
        if ($verifiedColumnExists && isset($trans['Verified'])) {
            if ($trans['Verified']) $verified++;
            else $unverified++;
        }
    }
    
    $stats = [
        'total' => count($transactions),
        'totalCollection' => $totalCollection,
        'totalDistribution' => $totalDistribution,
        'totalInterest' => $totalInterest,
        'verified' => $verified,
        'unverified' => $unverified
    ];
    
    // Create PDF
    $pdf = new PDF('L', 'mm', 'A4'); // Landscape orientation
    $pdf->AliasNbPages();
    $pdf->setFilterInfo($filterInfo);
    $pdf->AddPage();
    
    // Add summary
    $pdf->AddSummary($stats);
    
    // Add transactions
    $pdf->SetFont('Arial', '', 8);
    $alternate = false;
    
    foreach ($transactions as $trans) {
        // Alternate row colors
        if ($alternate) {
            $pdf->SetFillColor(236, 240, 241);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }
        $alternate = !$alternate;
        
        $pdf->SetTextColor(52, 73, 94);
        $pdf->SetDrawColor(189, 195, 199);
        
        // Transaction data
        $displayDate = (!empty($trans['TransactionDate']) && $trans['TransactionDate'] != '0000-00-00') 
            ? date('d-M-Y', strtotime($trans['TransactionDate'])) 
            : date('d-M-Y', strtotime($trans['CreatedAt']));
        $pdf->Cell(10, 6, $trans['TransactionID'], 1, 0, 'C', true);
        $pdf->Cell(25, 6, $displayDate, 1, 0, 'C', true);
        $pdf->Cell(40, 6, substr($trans['MemberName'], 0, 25), 1, 0, 'L', true);
        $pdf->Cell(30, 6, substr($trans['YearName'] ?? 'N/A', 0, 20), 1, 0, 'C', true);
        $pdf->Cell(25, 6, chr(8377) . number_format($trans['CollectionAmount'], 2), 1, 0, 'R', true);
        $pdf->Cell(25, 6, chr(8377) . number_format($trans['DistributedAmount'], 2), 1, 0, 'R', true);
        $pdf->Cell(25, 6, chr(8377) . number_format($trans['InterestAmount'], 2), 1, 0, 'R', true);
        $pdf->Cell(25, 6, substr($trans['PaymentMode'], 0, 15), 1, 0, 'C', true);
        
        // Verified status
        if ($verifiedColumnExists && isset($trans['Verified'])) {
            $status = $trans['Verified'] ? 'Yes' : 'No';
            $pdf->Cell(15, 6, $status, 1, 1, 'C', true);
        } else {
            $pdf->Cell(15, 6, '-', 1, 1, 'C', true);
        }
    }
    
    // Add totals row
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(41, 128, 185);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(105, 7, 'TOTAL', 1, 0, 'R', true);
    $pdf->Cell(25, 7, chr(8377) . number_format($totalCollection, 2), 1, 0, 'R', true);
    $pdf->Cell(25, 7, chr(8377) . number_format($totalDistribution, 2), 1, 0, 'R', true);
    $pdf->Cell(25, 7, chr(8377) . number_format($totalInterest, 2), 1, 0, 'R', true);
    $pdf->Cell(40, 7, '', 1, 1, 'C', true);
    
    // Output PDF
    $filename = 'Transactions_Report_' . date('Y-m-d_His') . '.pdf';
    $pdf->Output('D', $filename); // 'D' = force download
    
} catch (Exception $e) {
    die('Error generating PDF: ' . $e->getMessage());
}
?>
