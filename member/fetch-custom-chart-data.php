<?php
session_start();
require_once '../config/config.php';
require_once '../includes/functions.php';

// Check if user is logged in as Member
if (!isset($_SESSION['member_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Auto-set member ID filter
$loggedInMemberID = $_SESSION['member_id'];

header('Content-Type: application/json');

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['action']) || $input['action'] !== 'getCustomChartData') {
        throw new Exception('Invalid request');
    }
    
    $chartType = $input['chartType'] ?? 'line';
    $xAxis = $input['xAxis'] ?? '';
    $yMetrics = $input['yMetrics'] ?? [];
    $aggregation = $input['aggregation'] ?? 'SUM';
    $startDate = $input['startDate'] ?? '';
    $endDate = $input['endDate'] ?? '';
    
    if (empty($xAxis) || empty($yMetrics)) {
        throw new Exception('X-Axis and Y-Axis metrics are required');
    }
    
    // Validate aggregation method
    $validAggregations = ['SUM', 'AVG', 'COUNT', 'MAX', 'MIN'];
    if (!in_array($aggregation, $validAggregations)) {
        throw new Exception('Invalid aggregation method');
    }
    
    // Build SQL query based on X-Axis dimension
    $labels = [];
    $datasets = [];
    
    // Prepare WHERE clause for date filtering
    $whereClause = "WHERE 1=1";
    $params = [];
    
    if (!empty($startDate)) {
        $whereClause .= " AND DATE(t.TransactionDate) >= ?";
        $params[] = $startDate;
    }
    
    if (!empty($endDate)) {
        $whereClause .= " AND DATE(t.TransactionDate) <= ?";
        $params[] = $endDate;
    }
    
    // Build query based on X-Axis
    $joinClause = "";
    
    switch ($xAxis) {
        case 'TransactionDate':
            $groupBy = "DATE(t.TransactionDate)";
            $orderBy = "DATE(t.TransactionDate)";
            $labelField = "DATE(t.TransactionDate) as Label";
            break;
            
        case 'Month':
            $groupBy = "DATE_FORMAT(t.TransactionDate, '%Y-%m')";
            $orderBy = "DATE_FORMAT(t.TransactionDate, '%Y-%m')";
            $labelField = "DATE_FORMAT(t.TransactionDate, '%M %Y') as Label";
            break;
            
        case 'Year':
            $groupBy = "YEAR(t.TransactionDate)";
            $orderBy = "YEAR(t.TransactionDate)";
            $labelField = "YEAR(t.TransactionDate) as Label";
            break;
            
        case 'Quarter':
            $groupBy = "CONCAT(YEAR(t.TransactionDate), '-Q', QUARTER(t.TransactionDate))";
            $orderBy = "YEAR(t.TransactionDate), QUARTER(t.TransactionDate)";
            $labelField = "CONCAT('Q', QUARTER(t.TransactionDate), ' ', YEAR(t.TransactionDate)) as Label";
            break;
            
        case 'DayOfWeek':
            $groupBy = "DAYNAME(t.TransactionDate)";
            $orderBy = "DAYOFWEEK(t.TransactionDate)";
            $labelField = "DAYNAME(t.TransactionDate) as Label";
            break;
            
        case 'MemberName':
            $groupBy = "m.MemberName";
            $orderBy = "m.MemberName";
            $labelField = "m.MemberName as Label";
            $joinClause = "LEFT JOIN members m ON t.MemberID = m.MemberID";
            break;
            
        case 'MemberID':
            $groupBy = "t.MemberID";
            $orderBy = "t.MemberID";
            $labelField = "CONCAT('Member ', t.MemberID) as Label";
            break;
            
        case 'YearName':
        case 'AcademicYear':
            $groupBy = "ay.YearName";
            $orderBy = "ay.YearName";
            $labelField = "ay.YearName as Label";
            $joinClause = "LEFT JOIN academic_years ay ON t.YearID = ay.YearID";
            break;
            
        case 'PaymentMode':
            $groupBy = "COALESCE(t.PaymentMode, 'Not Specified')";
            $orderBy = "t.PaymentMode";
            $labelField = "COALESCE(t.PaymentMode, 'Not Specified') as Label";
            break;
            
        case 'TransactionType':
            $groupBy = "CASE WHEN t.CollectionAmount > 0 THEN 'Collection' WHEN t.DistributedAmount > 0 THEN 'Distribution' ELSE 'Other' END";
            $orderBy = "CASE WHEN t.CollectionAmount > 0 THEN 'Collection' WHEN t.DistributedAmount > 0 THEN 'Distribution' ELSE 'Other' END";
            $labelField = "CASE WHEN t.CollectionAmount > 0 THEN 'Collection' WHEN t.DistributedAmount > 0 THEN 'Distribution' ELSE 'Other' END as Label";
            break;
            
        default:
            throw new Exception('Invalid X-Axis dimension');
    }
    
    // Build SELECT clause for Y-Axis metrics
    $selectClauses = [$labelField];
    
    foreach ($yMetrics as $metric) {
        switch ($metric) {
            case 'CollectionAmount':
                $selectClauses[] = "$aggregation(COALESCE(t.CollectionAmount, 0)) as CollectionAmount";
                break;
                
            case 'DistributionAmount':
                $selectClauses[] = "$aggregation(COALESCE(t.DistributedAmount, 0)) as DistributedAmount";
                break;
                
            case 'InterestAmount':
                $selectClauses[] = "$aggregation(COALESCE(t.InterestAmount, 0)) as InterestAmount";
                break;
                
            case 'TransactionCount':
                $selectClauses[] = "COUNT(*) as TransactionCount";
                break;
                
            case 'NetBalance':
                $selectClauses[] = "$aggregation(COALESCE(t.CollectionAmount, 0) - COALESCE(t.DistributedAmount, 0)) as NetBalance";
                break;
                
            case 'AvgCollection':
                $selectClauses[] = "AVG(COALESCE(t.CollectionAmount, 0)) as AvgCollection";
                break;
                
            default:
                continue 2; // Skip invalid metrics
        }
    }
    
    // Construct final query
    $sql = "SELECT " . implode(", ", $selectClauses) . " 
            FROM transactions t 
            $joinClause
            $whereClause 
            GROUP BY $groupBy 
            ORDER BY $orderBy";
    
    // Prepare and execute query using PDO
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Process results
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $labels[] = $row['Label'];
        
        foreach ($yMetrics as $metric) {
            if (!isset($datasets[$metric])) {
                $datasets[$metric] = [
                    'label' => getMetricLabel($metric),
                    'data' => []
                ];
            }
            
            $value = isset($row[$metric]) ? floatval($row[$metric]) : 0;
            $datasets[$metric]['data'][] = $value;
        }
    }
    
    // Convert datasets to indexed array
    $datasetsArray = array_values($datasets);
    
    // Return data
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'datasets' => $datasetsArray,
        'chartType' => $chartType,
        'xAxis' => $xAxis,
        'yMetrics' => $yMetrics
    ]);
    
} catch (Exception $e) {
    error_log("Custom chart data error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Unable to load chart data. Please try again.'
    ]);
}

function getMetricLabel($metric) {
    $labels = [
        'CollectionAmount' => 'Collection Amount (₹)',
        'DistributionAmount' => 'Distribution Amount (₹)',
        'InterestAmount' => 'Interest Amount (₹)',
        'TransactionCount' => 'Transaction Count',
        'NetBalance' => 'Net Balance (₹)',
        'AvgCollection' => 'Average Collection (₹)'
    ];
    
    return $labels[$metric] ?? $metric;
}
?>
