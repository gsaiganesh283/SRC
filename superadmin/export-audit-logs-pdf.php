<?php
// Export Audit Logs to PDF

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/config.php';
require_once '../superadmin/fpdf.php';
// Super Admin Session Protection
if (!isset($_SESSION['superadmin_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'SuperAdmin') {
    die('Access denied.');
}

// Filters (optional, for future use)
$filterUser = isset($_GET['user']) ? $_GET['user'] : '';
$filterAction = isset($_GET['action']) ? $_GET['action'] : '';
$filterDateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filterDateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

$whereConditions = [];
$params = [];

if (!empty($filterUser)) {
    $whereConditions[] = "al.UserID = ?";
    $params[] = $filterUser;
}
if (!empty($filterAction)) {
    $whereConditions[] = "al.Action = ?";
    $params[] = $filterAction;
}
if (!empty($filterDateFrom)) {
    $whereConditions[] = "DATE(al.CreatedAt) >= ?";
    $params[] = $filterDateFrom;
}
if (!empty($filterDateTo)) {
    $whereConditions[] = "DATE(al.CreatedAt) <= ?";
    $params[] = $filterDateTo;
}
if (!empty($search)) {
    $whereConditions[] = "(al.Action LIKE ? OR al.IPAddress LIKE ? OR al.UserType LIKE ? OR al.Username LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Fetch logs (limit 1000 for PDF)
$sql = "SELECT al.LogID, al.UserType, al.Username, al.Action, al.TableName, al.IPAddress, al.CreatedAt, al.RecordID, al.OldData, al.NewData, al.UserAgent FROM audit_logs al $whereClause ORDER BY al.CreatedAt DESC LIMIT 1000";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// PDF setup
class PDF extends FPDF {
    function Header() {
        $this->SetFont('Arial','B',16);
        $this->Cell(0,10,'Audit Logs Report',0,1,'C');
        $this->SetFont('Arial','',10);
        $this->Cell(0,8,'Exported: '.date('Y-m-d H:i'),0,1,'C');
        // Column headers
        $this->Ln(2);
        $this->SetFont('Arial','B',7);
        // Set fill color for header (light blue)
        $this->SetFillColor(200,220,255);
        $this->Cell(8,8,'ID',1,0,'C',true);
        $this->Cell(12,8,'Type',1,0,'C',true);
        $this->Cell(16,8,'User',1,0,'C',true);
    $this->Cell(44,8,'Action',1,0,'C',true);
    $this->Cell(28,8,'Table',1,0,'C',true);
        $this->Cell(10,8,'RecID',1,0,'C',true);
        $this->Cell(18,8,'Old Data',1,0,'C',true);
        $this->Cell(18,8,'New Data',1,0,'C',true);
        $this->Cell(16,8,'IP',1,0,'C',true);
    $this->Cell(40,8,'UserAgent',1,0,'C',true);
        $this->Cell(20,8,'Date/Time',1,1,'C',true);
        $this->SetFont('Arial','',6);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->Cell(0,10,'Page '.$this->PageNo().'/{nb}',0,0,'C');
    }
}
$pdf = new PDF('L','mm','A4');
$pdf->AliasNbPages();
$pdf->AddPage();
// Helper function to calculate number of lines for MultiCell
function getNumLines($pdf, $width, $text) {
    $pdf->SetFont('Arial','',6);
    $lines = explode("\n", wordwrap($text, max(1, intval($width/2)), "\n", true));
    return count($lines);
}


foreach ($logs as $log) {
    $pdf->SetFont('Arial','',6);
    // Calculate required height for Action and Table columns
    $actionLines = getNumLines($pdf, 44, $log['Action']);
    $tableLines = getNumLines($pdf, 28, $log['TableName']);
    $maxLines = max($actionLines, $tableLines);
    $rowHeight = $maxLines * 4;
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    $pdf->Cell(8, $rowHeight, $log['LogID'], 1, 0, 'L');
    $pdf->Cell(12, $rowHeight, mb_strimwidth($log['UserType'],0,10,'..'), 1, 0, 'L');
    $pdf->Cell(16, $rowHeight, mb_strimwidth($log['Username'],0,12,'..'), 1, 0, 'L');
    // Action (full text, wrapped)
    $pdf->MultiCell(44, 4, $log['Action'], 1, 'L', false);
    $pdf->SetXY($x+8+12+16+44, $y);
    // Table (full text, wrapped)
    $pdf->MultiCell(28, 4, $log['TableName'], 1, 'L', false);
    $pdf->SetXY($x+8+12+16+44+28, $y);
    $pdf->Cell(10, $rowHeight, isset($log['RecordID']) ? $log['RecordID'] : '', 1, 0, 'L');
    $pdf->Cell(18, $rowHeight, mb_strimwidth(strip_tags($log['OldData']??''),0,14,'..'), 1, 0, 'L');
    $pdf->Cell(18, $rowHeight, mb_strimwidth(strip_tags($log['NewData']??''),0,14,'..'), 1, 0, 'L');
    $pdf->Cell(16, $rowHeight, mb_strimwidth($log['IPAddress'],0,10,'..'), 1, 0, 'L');
    $pdf->Cell(40, $rowHeight, mb_strimwidth(strip_tags($log['UserAgent']??''),0,36,'..'), 1, 0, 'L');
    $pdf->Cell(20, $rowHeight, date('Y-m-d H:i',strtotime($log['CreatedAt'])), 1, 1, 'L');
}
// ...existing code...

$pdf->Output('D','audit-logs-'.date('Ymd_His').'.pdf');
exit;
