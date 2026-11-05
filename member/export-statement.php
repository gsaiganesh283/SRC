<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireMemberLogin();

$memberId = $_SESSION['member_id'];

// Get member details
$stmt = $pdo->prepare("SELECT * FROM members WHERE MemberID = ?");
$stmt->execute([$memberId]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

// Get filter parameters
$yearFilter = isset($_GET['year']) ? intval($_GET['year']) : null;
$format = isset($_GET['format']) ? $_GET['format'] : 'pdf';

// Get transactions
$query = "
    SELECT t.*, y.YearName 
    FROM transactions t
    JOIN academic_years y ON t.YearID = y.YearID
    WHERE t.MemberID = ?
";

$params = [$memberId];

if ($yearFilter) {
    $query .= " AND t.YearID = ?";
    $params[] = $yearFilter;
}

$query .= " ORDER BY t.TransactionDate DESC, t.CreatedAt DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalCollected = array_sum(array_column($transactions, 'CollectionAmount'));
$totalDistributed = array_sum(array_column($transactions, 'DistributedAmount'));
$totalInterest = array_sum(array_column($transactions, 'InterestAmount'));
$totalFixedAmount = array_sum(array_column($transactions, 'FixedAmount'));
// Net Balance = (Total Distributed + Total Interest) - Total Collected
$netBalance = ($totalDistributed + $totalInterest) - $totalCollected;

// Export based on format
if (isset($_GET['download'])) {
    if ($format == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="statement_' . $memberId . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Header
        fputcsv($output, ['SR Colony Chitti Management - Member Statement']);
        fputcsv($output, ['Member Name:', $member['MemberName']]);
        fputcsv($output, ['Member ID:', $memberId]);
        fputcsv($output, ['Generated On:', date('d-m-Y H:i:s')]);
        fputcsv($output, []);
        
        // Column headers
        fputcsv($output, ['Date', 'Year', 'Collection', 'Distribution', 'Interest', 'Next Month Due', 'Payment Mode', 'Notes']);
        
        // Data
        foreach ($transactions as $txn) {
            $displayDate = (!empty($txn['TransactionDate']) && $txn['TransactionDate'] != '0000-00-00') 
                ? date('d-m-Y', strtotime($txn['TransactionDate'])) 
                : date('d-m-Y', strtotime($txn['CreatedAt']));
            fputcsv($output, [
                $displayDate,
                $txn['YearName'],
                number_format($txn['CollectionAmount'], 2),
                number_format($txn['DistributedAmount'], 2),
                number_format($txn['InterestAmount'], 2),
                number_format($txn['NextMonthDue'], 2),
                $txn['PaymentMode'],
                $txn['Notes']
            ]);
        }
        
        // Totals
        fputcsv($output, []);
        fputcsv($output, ['Total Collection:', number_format($totalCollected, 2)]);
        fputcsv($output, ['Total Distribution:', number_format($totalDistributed, 2)]);
        fputcsv($output, ['Total Interest:', number_format($totalInterest, 2)]);
        fputcsv($output, ['Net Balance:', number_format($netBalance, 2)]);
        
        fclose($output);
        exit();
    } elseif ($format == 'pdf') {
        require_once('../superadmin/fpdf.php');
        
        $pdf = new FPDF('L', 'mm', 'A4');
        $pdf->AddPage();
        
        // Header
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'SR Colony Chitti Management', 0, 1, 'C');
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 8, 'Member Statement', 0, 1, 'C');
        $pdf->Ln(5);
        
        // Member details
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(50, 6, 'Member Name:', 0, 0);
        $pdf->Cell(0, 6, $member['MemberName'], 0, 1);
        $pdf->Cell(50, 6, 'Member ID:', 0, 0);
        $pdf->Cell(0, 6, $memberId, 0, 1);
        $pdf->Cell(50, 6, 'Generated On:', 0, 0);
        $pdf->Cell(0, 6, date('d-m-Y H:i:s'), 0, 1);
        $pdf->Ln(5);
        
        // Table header
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(25, 7, 'Date', 1, 0, 'C', true);
        $pdf->Cell(35, 7, 'Year', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Collection', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Distribution', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Interest', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Next Due', 1, 0, 'C', true);
        $pdf->Cell(25, 7, 'Mode', 1, 0, 'C', true);
        $pdf->Cell(0, 7, 'Notes', 1, 1, 'C', true);
        
        // Table data
        $pdf->SetFont('Arial', '', 8);
        foreach ($transactions as $txn) {
            $displayDate = (!empty($txn['TransactionDate']) && $txn['TransactionDate'] != '0000-00-00') 
                ? date('d-m-Y', strtotime($txn['TransactionDate'])) 
                : date('d-m-Y', strtotime($txn['CreatedAt']));
            $pdf->Cell(25, 6, $displayDate, 1, 0);
            $pdf->Cell(35, 6, substr($txn['YearName'], 0, 20), 1, 0);
            $pdf->Cell(30, 6, number_format($txn['CollectionAmount'], 2), 1, 0, 'R');
            $pdf->Cell(30, 6, number_format($txn['DistributedAmount'], 2), 1, 0, 'R');
            $pdf->Cell(30, 6, number_format($txn['InterestAmount'], 2), 1, 0, 'R');
            $pdf->Cell(30, 6, number_format($txn['NextMonthDue'], 2), 1, 0, 'R');
            $pdf->Cell(25, 6, $txn['PaymentMode'], 1, 0);
            $pdf->Cell(0, 6, substr($txn['Notes'] ?? '', 0, 30), 1, 1);
        }
        
        // Totals
        $pdf->Ln(3);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(100, 6, 'Total Collection:', 0, 0, 'R');
        $pdf->Cell(30, 6, number_format($totalCollected, 2), 1, 1, 'R');
        $pdf->Cell(100, 6, 'Total Distribution:', 0, 0, 'R');
        $pdf->Cell(30, 6, number_format($totalDistributed, 2), 1, 1, 'R');
        $pdf->Cell(100, 6, 'Total Interest:', 0, 0, 'R');
        $pdf->Cell(30, 6, number_format($totalInterest, 2), 1, 1, 'R');
        $pdf->Cell(100, 6, 'Net Balance:', 0, 0, 'R');
        $pdf->Cell(30, 6, number_format($netBalance, 2), 1, 1, 'R');
        
        $pdf->Output('D', 'statement_' . $memberId . '_' . date('Y-m-d') . '.pdf');
        exit();
    }
}

// Get available years
$stmt = $pdo->query("SELECT * FROM academic_years ORDER BY StartDate DESC");
$years = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Statement - SR Colony</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .card h2 {
            color: #1f2937;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
        }
        
        .btn-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 0.875rem 1.5rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #4f46e5;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .summary-item {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #4f46e5;
        }
        
        .summary-label {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 0.25rem;
        }
        
        .summary-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>
                <i class="fas fa-file-download"></i>
                Export Statement
            </h2>
            
            <form method="GET" action="">
                <div class="form-group">
                    <label for="year">Filter by Year (Optional)</label>
                    <select name="year" id="year" class="form-control">
                        <option value="">All Years</option>
                        <?php foreach ($years as $year): ?>
                            <option value="<?php echo $year['YearID']; ?>" <?php echo $yearFilter == $year['YearID'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['YearName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="format">Export Format</label>
                    <select name="format" id="format" class="form-control">
                        <option value="pdf" <?php echo $format == 'pdf' ? 'selected' : ''; ?>>PDF Document</option>
                        <option value="csv" <?php echo $format == 'csv' ? 'selected' : ''; ?>>CSV (Excel)</option>
                    </select>
                </div>
                
                <div class="btn-group">
                    <button type="submit" name="download" value="1" class="btn btn-primary">
                        <i class="fas fa-download"></i>
                        Download Statement
                    </button>
                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-sync"></i>
                        Preview
                    </button>
                    <a href="dashboard-new.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Dashboard
                    </a>
                </div>
            </form>
            
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">Total Transactions</div>
                    <div class="summary-value"><?php echo count($transactions); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Collection</div>
                    <div class="summary-value">₹<?php echo number_format($totalCollected, 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total Distribution</div>
                    <div class="summary-value">₹<?php echo number_format($totalDistributed, 2); ?></div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Net Balance</div>
                    <div class="summary-value">₹<?php echo number_format($netBalance, 2); ?></div>
                </div>
            </div>
        </div>
        
        <?php if (!isset($_GET['download']) && !empty($transactions)): ?>
        <div class="card">
            <h2>
                <i class="fas fa-list"></i>
                Transaction Preview
            </h2>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                            <th style="padding: 0.75rem; text-align: left;">Date</th>
                            <th style="padding: 0.75rem; text-align: left;">Year</th>
                            <th style="padding: 0.75rem; text-align: right;">Collection</th>
                            <th style="padding: 0.75rem; text-align: right;">Distribution</th>
                            <th style="padding: 0.75rem; text-align: right;">Interest</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($transactions, 0, 10) as $txn): 
                            $displayDate = (!empty($txn['TransactionDate']) && $txn['TransactionDate'] != '0000-00-00') 
                                ? date('d-m-Y', strtotime($txn['TransactionDate'])) 
                                : date('d-m-Y', strtotime($txn['CreatedAt']));
                        ?>
                            <tr style="border-bottom: 1px solid #e5e7eb;">
                                <td style="padding: 0.75rem;"><?php echo $displayDate; ?></td>
                                <td style="padding: 0.75rem;"><?php echo htmlspecialchars($txn['YearName']); ?></td>
                                <td style="padding: 0.75rem; text-align: right; color: #10b981; font-weight: 600;">
                                    ₹<?php echo number_format($txn['CollectionAmount'], 2); ?>
                                </td>
                                <td style="padding: 0.75rem; text-align: right; color: #ef4444; font-weight: 600;">
                                    ₹<?php echo number_format($txn['DistributedAmount'], 2); ?>
                                </td>
                                <td style="padding: 0.75rem; text-align: right;">
                                    ₹<?php echo number_format($txn['InterestAmount'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($transactions) > 10): ?>
                    <p style="text-align: center; padding: 1rem; color: #6b7280;">
                        Showing first 10 of <?php echo count($transactions); ?> transactions. Download for full list.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
