<?php
/**
 * Export Net Balance Report to PDF
 * Supports year-wise and all-years reports
 */

// Prevent any output before the PDF
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
require_once '../includes/net-balance-helper.php';
require_once '../includes/logo-helper.php';
require_once 'fpdf.php';

// Check authorization
if (!isset($_SESSION['superadmin_id'])) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get parameters
$yearId = !empty($_POST['year_id']) ? intval($_POST['year_id']) : 0;
$exportType = !empty($_POST['export_type']) ? $_POST['export_type'] : 'year-wise';

try {
    // Determine year for calculation
    if ($yearId == 0) {
        // Current/active year
        $yearStmt = $pdo->query("SELECT YearID, YearName FROM academic_years WHERE Status = 'Active' LIMIT 1");
        $year = $yearStmt->fetch(PDO::FETCH_ASSOC);
        $yearId = $year['YearID'] ?? 1;
        $yearName = $year['YearName'] ?? 'Academic Year';
        $yearForCalculation = $yearId;
    } else {
        // Get specific year name
        $yearStmt = $pdo->prepare("SELECT YearName FROM academic_years WHERE YearID = ?");
        $yearStmt->execute([$yearId]);
        $year = $yearStmt->fetch(PDO::FETCH_ASSOC);
        $yearName = $year['YearName'] ?? 'Academic Year';
        
        if ($exportType === 'all-years') {
            $yearForCalculation = null; // Aggregate all years
        } else {
            $yearForCalculation = $yearId;
        }
    }
    
    // Get data
    $systemSummary = function_exists('getSystemNetBalanceSummary') 
        ? getSystemNetBalanceSummary($yearForCalculation)
        : ['total_collection' => 0, 'total_distributed' => 0, 'total_interest' => 0, 'net_balance' => 0];
    
    $membersBalances = function_exists('getAllMembersNetBalance')
        ? getAllMembersNetBalance($yearForCalculation)
        : [];
    
    // Get collection data
    $collectionData = [];
    $distData = [];
    
    if (!empty($membersBalances)) {
        try {
            $transQuery = "SELECT MemberID, SUM(CollectionAmount) as total_collection 
                           FROM transactions 
                           WHERE CollectionAmount > 0";
            if ($yearForCalculation !== null) {
                $transQuery .= " AND YearID = " . intval($yearForCalculation);
            }
            $transQuery .= " GROUP BY MemberID ORDER BY total_collection DESC";
            $transStmt = $pdo->query($transQuery);
            $collectionData = $transStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("PDF Collection query error: " . $e->getMessage());
            $collectionData = [];
        }
        
        try {
            $distQuery = "SELECT MemberID, SUM(DistributedAmount) as total_distribution 
                          FROM transactions 
                          WHERE DistributedAmount > 0";
            if ($yearForCalculation !== null) {
                $distQuery .= " AND YearID = " . intval($yearForCalculation);
            }
            $distQuery .= " GROUP BY MemberID ORDER BY total_distribution DESC";
            $distStmt = $pdo->query($distQuery);
            $distData = $distStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            error_log("PDF Distribution query error: " . $e->getMessage());
            $distData = [];
        }
    }
    
    // Create PDF
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    
    // Header
    $siteName = getSiteName('SR Colony');
    $pdf->Cell(0, 10, htmlspecialchars($siteName), 0, 1, 'C');
    
    $pdf->SetFont('Arial', 'B', 14);
    $reportTitle = ($exportType === 'all-years') ? 'All Years Net Balance Report' : 'Net Balance Report';
    $pdf->Cell(0, 10, $reportTitle, 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 8, 'Year: ' . htmlspecialchars($yearName), 0, 1, 'C');
    $pdf->Cell(0, 8, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1, 'C');
    
    $pdf->Ln(5);
    
    // Summary Section
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(0, 8, 'Financial Summary', 0, 1);
    
    $pdf->SetFont('Arial', '', 10);
    $pdf->SetFillColor(240, 240, 240);
    
    $pdf->Cell(60, 7, 'Total Collection:', 0, 0);
    $pdf->Cell(0, 7, 'Rs. ' . number_format($systemSummary['total_collection'] ?? 0, 2), 0, 1, 'R', true);
    
    $pdf->Cell(60, 7, 'Total Distribution:', 0, 0);
    $pdf->Cell(0, 7, 'Rs. ' . number_format($systemSummary['total_distributed'] ?? 0, 2), 0, 1, 'R', true);
    
    $pdf->Cell(60, 7, 'Total Interest:', 0, 0);
    $pdf->Cell(0, 7, 'Rs. ' . number_format($systemSummary['total_interest'] ?? 0, 2), 0, 1, 'R', true);
    
    $pdf->SetFillColor(200, 220, 255);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(60, 7, 'Net Balance:', 0, 0);
    $pdf->Cell(0, 7, 'Rs. ' . number_format($systemSummary['net_balance'] ?? 0, 2), 0, 1, 'R', true);
    
    $pdf->Ln(8);
    
    // COLLECTION SECTION
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'Collection Details by Member', 0, 1);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(100, 150, 200);
    $pdf->SetTextColor(255, 255, 255);
    
    $pdf->Cell(30, 7, 'Member ID', 1, 0, 'C', true);
    $pdf->Cell(90, 7, 'Member Name', 1, 0, 'L', true);
    $pdf->Cell(60, 7, 'Collection Amount', 1, 1, 'R', true);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetFillColor(245, 245, 245);
    
    $fillColor = false;
    if (!empty($collectionData)) {
        foreach ($collectionData as $rec) {
            if ($fillColor) {
                $pdf->SetFillColor(245, 245, 245);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }
            
            $memberName = '';
            foreach ($membersBalances as $m) {
                if ($m['MemberID'] == $rec['MemberID']) {
                    $memberName = $m['MemberName'];
                    break;
                }
            }
            
            $pdf->Cell(30, 6, $rec['MemberID'], 1, 0, 'C', true);
            $pdf->Cell(90, 6, substr($memberName, 0, 50), 1, 0, 'L', true);
            $pdf->Cell(60, 6, 'Rs. ' . number_format($rec['total_collection'] ?? 0, 2), 1, 1, 'R', true);
            
            $fillColor = !$fillColor;
        }
    } else {
        $pdf->Cell(0, 6, 'No collection data available', 1, 1, 'C');
    }
    
    $pdf->Ln(5);
    
    // DISTRIBUTION SECTION
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'Distribution Details by Member', 0, 1);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(100, 180, 100);
    $pdf->SetTextColor(255, 255, 255);
    
    $pdf->Cell(30, 7, 'Member ID', 1, 0, 'C', true);
    $pdf->Cell(90, 7, 'Member Name', 1, 0, 'L', true);
    $pdf->Cell(60, 7, 'Distribution Amount', 1, 1, 'R', true);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetFillColor(245, 245, 245);
    
    $fillColor = false;
    if (!empty($distData)) {
        foreach ($distData as $rec) {
            if ($fillColor) {
                $pdf->SetFillColor(245, 245, 245);
            } else {
                $pdf->SetFillColor(255, 255, 255);
            }
            
            $memberName = '';
            foreach ($membersBalances as $m) {
                if ($m['MemberID'] == $rec['MemberID']) {
                    $memberName = $m['MemberName'];
                    break;
                }
            }
            
            $pdf->Cell(30, 6, $rec['MemberID'], 1, 0, 'C', true);
            $pdf->Cell(90, 6, substr($memberName, 0, 50), 1, 0, 'L', true);
            $pdf->Cell(60, 6, 'Rs. ' . number_format($rec['total_distribution'] ?? 0, 2), 1, 1, 'R', true);
            
            $fillColor = !$fillColor;
        }
    } else {
        $pdf->Cell(0, 6, 'No distribution data available', 1, 1, 'C');
    }
    
    $pdf->Ln(8);
    
    // NET BALANCE SECTION
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(0, 8, 'Member Net Balance Details', 0, 1);
    
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(150, 100, 200);
    $pdf->SetTextColor(255, 255, 255);
    
    $pdf->Cell(20, 7, 'ID', 1, 0, 'C', true);
    $pdf->Cell(80, 7, 'Member Name', 1, 0, 'L', true);
    $pdf->Cell(60, 7, 'Net Balance', 1, 1, 'R', true);
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetFillColor(245, 245, 245);
    
    $fillColor = false;
    $totalMembers = count($membersBalances);
    $totalNetBalance = 0;
    
    foreach ($membersBalances as $member) {
        if ($fillColor) {
            $pdf->SetFillColor(245, 245, 245);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }
        
        $pdf->Cell(20, 6, $member['MemberID'], 1, 0, 'C', true);
        $pdf->Cell(80, 6, substr($member['MemberName'], 0, 40), 1, 0, 'L', true);
        $pdf->Cell(60, 6, 'Rs. ' . number_format($member['NetBalance'] ?? 0, 2), 1, 1, 'R', true);
        
        $totalNetBalance += $member['NetBalance'] ?? 0;
        $fillColor = !$fillColor;
    }
    
    // Footer with totals
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(200, 200, 200);
    $pdf->Cell(20, 7, '', 1, 0, 'C', true);
    $pdf->Cell(80, 7, 'Total: ' . $totalMembers . ' Members', 1, 0, 'L', true);
    $pdf->Cell(60, 7, 'Rs. ' . number_format($totalNetBalance, 2), 1, 1, 'R', true);
    
    $pdf->Ln(5);
    
    // Footer text
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(128, 128, 128);
    $pdf->Cell(0, 5, 'Formula: Net Balance = (Distribution + Interest) - Collection', 0, 1, 'C');
    $pdf->Cell(0, 5, 'This is a system-generated report.', 0, 1, 'C');
    
    // Log the export (optional - skip if table doesn't exist)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, Details, IPAddress)
            VALUES ('SuperAdmin', ?, ?, 'Export Net Balance Report (PDF)', 'member_net_balance', ?, ?)
        ");
        $stmt->execute([
            $_SESSION['superadmin_id'] ?? 0,
            $_SESSION['superadmin_name'] ?? 'SuperAdmin',
            'Export Type: ' . $exportType . ' | Year ID: ' . $yearId . ' | Total Members: ' . count($membersBalances),
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
        ]);
    } catch (Exception $logError) {
        // Silently skip logging if there's an error
        error_log("Audit logging error (non-critical): " . $logError->getMessage());
    }
    
    // Output PDF
    ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="net-balance-report-' . date('Y-m-d') . '.pdf"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    $pdf->Output('D', 'net-balance-report-' . date('Y-m-d') . '.pdf');
    exit;
    
} catch (Exception $e) {
    ob_end_clean();
    error_log("Error exporting net balance report: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error generating PDF: ' . $e->getMessage()
    ]);
}
?>
