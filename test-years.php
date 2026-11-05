<?php
require_once 'config/config.php';

echo "=== ACADEMIC YEARS ===\n\n";

$stmt = $pdo->query("SELECT YearID, YearName, Status FROM academic_years ORDER BY StartDate DESC");
$years = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach($years as $y) {
    echo $y['YearID'] . ' - ' . $y['YearName'] . ' (' . $y['Status'] . ')' . "\n";
}

echo "\n=== TRANSACTIONS BY YEAR ===\n\n";

$stmt = $pdo->query("
    SELECT 
        t.YearID, 
        ay.YearName,
        COUNT(*) as TransactionCount
    FROM transactions t
    JOIN academic_years ay ON t.YearID = ay.YearID
    GROUP BY t.YearID, ay.YearName
    ORDER BY t.YearID DESC
");
$transactionsByYear = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach($transactionsByYear as $ty) {
    echo "Year " . $ty['YearID'] . " (" . $ty['YearName'] . "): " . $ty['TransactionCount'] . " transactions\n";
}
?>
