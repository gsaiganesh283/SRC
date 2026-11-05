<?php
// Export Report Handler for Excel and CSV
require_once '../config/config.php';
require_once 'session.php';

if (!isset($_SESSION['superadmin_id'])) {
    die('Unauthorized');
}

$format = $_POST['format'] ?? 'csv';
$dateFrom = $_POST['date_from'] ?? date('Y-04-01');
$dateTo = $_POST['date_to'] ?? date('Y-m-d');
$memberID = $_POST['member_id'] ?? null;

try {
    // Build WHERE clause based on member filter
    $whereConditions = "t.TransactionDate BETWEEN ? AND ?";
    $params = [$dateFrom, $dateTo];
    
    if ($memberID) {
        $whereConditions .= " AND m.MemberID = ?";
        $params[] = $memberID;
    }
    
    // Fetch report data
    $stmt = $pdo->prepare("
        SELECT 
            m.MemberName,
            m.Phone,
            COUNT(t.TransactionID) as TransactionCount,
            SUM(t.CollectionAmount) as TotalCollection,
            SUM(t.DistributedAmount) as TotalDistribution,
            SUM(t.InterestAmount) as TotalInterest,
            MAX(t.TransactionDate) as LastTransaction
        FROM members m
        LEFT JOIN transactions t ON m.MemberID = t.MemberID AND $whereConditions
        GROUP BY m.MemberID
        ORDER BY TotalCollection DESC
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'csv') {
        // CSV Export
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, ['Member Name', 'Phone', 'Transactions', 'Collection', 'Distribution', 'Interest', 'Last Transaction']);
        
        // Data rows
        foreach ($data as $row) {
            fputcsv($output, [
                $row['MemberName'],
                $row['Phone'],
                $row['TransactionCount'],
                $row['TotalCollection'],
                $row['TotalDistribution'],
                $row['TotalInterest'],
                $row['LastTransaction'] ?? 'N/A'
            ]);
        }
        
        fclose($output);
        
    } elseif ($format === 'excel') {
        // Simple Excel export using HTML table format
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="report_' . date('Y-m-d') . '.xls"');
        
        echo '<table border="1">';
        echo '<thead><tr>';
        echo '<th>Member Name</th><th>Phone</th><th>Transactions</th><th>Collection</th><th>Distribution</th><th>Interest</th><th>Last Transaction</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($data as $row) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['MemberName']) . '</td>';
            echo '<td>' . htmlspecialchars($row['Phone']) . '</td>';
            echo '<td>' . $row['TransactionCount'] . '</td>';
            echo '<td>' . number_format($row['TotalCollection'], 2) . '</td>';
            echo '<td>' . number_format($row['TotalDistribution'], 2) . '</td>';
            echo '<td>' . number_format($row['TotalInterest'], 2) . '</td>';
            echo '<td>' . ($row['LastTransaction'] ?? 'N/A') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
} catch (Exception $e) {
    die('Export Error: ' . $e->getMessage());
}
