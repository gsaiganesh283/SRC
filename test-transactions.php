<?php
require_once 'config/config.php';

echo "=== TESTING TRANSACTIONS ===\n\n";

// Count transactions
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM transactions");
    $result = $stmt->fetch();
    echo "✅ Total transactions in database: " . $result['count'] . "\n\n";
} catch (Exception $e) {
    echo "❌ Error counting transactions: " . $e->getMessage() . "\n\n";
}

// Try to fetch transactions with the fixed query
try {
    $query = "
        SELECT 
            t.*,
            m.MemberName as MemberName,
            ay.YearName,
            a.Username as AdminUsername
        FROM transactions t
        JOIN members m ON t.MemberID = m.MemberID
        JOIN academic_years ay ON t.YearID = ay.YearID
        LEFT JOIN admins a ON t.CreatedBy = a.AdminID
        LIMIT 5
    ";
    
    $stmt = $pdo->query($query);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ Query executed successfully!\n";
    echo "Found " . count($transactions) . " transactions (showing first 5)\n\n";
    
    if (count($transactions) > 0) {
        echo "Sample transaction:\n";
        print_r($transactions[0]);
    } else {
        echo "⚠️ No transactions found in database.\n";
        echo "You need to add transactions first!\n";
    }
} catch (Exception $e) {
    echo "❌ Error fetching transactions: " . $e->getMessage() . "\n";
}

echo "\n=== TEST COMPLETE ===\n";
?>
