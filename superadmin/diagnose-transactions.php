<?php
// Quick Diagnostic Page - Check Transaction Data
// URL: http://localhost/SRCOLONY-VIEWS/superadmin/diagnose-transactions.php

require_once './session.php';
require_once '../config/config.php';

// Require super admin authentication
if (!isset($_SESSION['superadmin_id'])) {
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Diagnostics - Super Admin</title>
    <link rel="stylesheet" href="shared-styles.css">
    <style>
        body { padding: 20px; font-family: system-ui, -apple-system, sans-serif; }
        .container { max-width: 1200px; margin: 0 auto; }
        .diagnostic-card { background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #007bff; }
        .diagnostic-card h3 { margin-top: 0; color: #007bff; }
        .result-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        .result-table th, .result-table td { padding: 10px; text-align: left; border: 1px solid #ddd; }
        .result-table th { background: #007bff; color: white; }
        .success { color: #28a745; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .warning { color: #ffc107; font-weight: bold; }
        .code { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .back-btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-bottom: 20px; }
        .back-btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <a href="manage-transactions.php" class="back-btn">← Back to Transactions</a>
        
        <h1>📊 Transaction Data Diagnostics</h1>
        <p>This page helps diagnose why transactions aren't displaying.</p>

        <?php
        // Test 1: Total Transactions
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM transactions");
            $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            ?>
            <div class="diagnostic-card">
                <h3>✅ Test 1: Total Transactions in Database</h3>
                <?php if ($totalCount > 0): ?>
                    <p class="success">PASS: Found <?php echo $totalCount; ?> transaction(s) in database</p>
                <?php else: ?>
                    <p class="error">FAIL: No transactions found in database!</p>
                    <p><strong>Solution:</strong> You need to add transactions first.</p>
                <?php endif; ?>
            </div>
            <?php
        } catch (Exception $e) {
            echo '<div class="diagnostic-card"><h3>❌ Test 1: Failed</h3><p class="error">Error: ' . $e->getMessage() . '</p></div>';
        }

        // Test 2: Recent Transactions
        if ($totalCount > 0) {
            try {
                $stmt = $pdo->query("SELECT TransactionID, MemberID, YearID, TransactionDate, CollectionAmount, DistributedAmount FROM transactions ORDER BY TransactionDate DESC LIMIT 5");
                $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <div class="diagnostic-card">
                    <h3>📋 Test 2: Recent Transactions (Latest 5)</h3>
                    <table class="result-table">
                        <tr>
                            <th>ID</th>
                            <th>Member ID</th>
                            <th>Year ID</th>
                            <th>Date</th>
                            <th>Collection</th>
                            <th>Distribution</th>
                        </tr>
                        <?php foreach ($recentTransactions as $trans): ?>
                        <tr>
                            <td><?php echo $trans['TransactionID']; ?></td>
                            <td><?php echo $trans['MemberID'] ?? '<span class="error">NULL</span>'; ?></td>
                            <td><?php echo $trans['YearID'] ?? '<span class="error">NULL</span>'; ?></td>
                            <td><?php echo $trans['TransactionDate']; ?></td>
                            <td>₹<?php echo number_format($trans['CollectionAmount'], 2); ?></td>
                            <td>₹<?php echo number_format($trans['DistributedAmount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php
            } catch (Exception $e) {
                echo '<div class="diagnostic-card"><h3>❌ Test 2: Failed</h3><p class="error">Error: ' . $e->getMessage() . '</p></div>';
            }
        }

        // Test 3: Check Year 2025-2026
        try {
            $stmt = $pdo->query("SELECT ay.YearID, ay.YearName, ay.StartDate, ay.EndDate, COUNT(t.TransactionID) as TransactionCount FROM academic_years ay LEFT JOIN transactions t ON ay.YearID = t.YearID WHERE ay.YearName LIKE '%2025-2026%' GROUP BY ay.YearID");
            $yearData = $stmt->fetch(PDO::FETCH_ASSOC);
            ?>
            <div class="diagnostic-card">
                <h3>📅 Test 3: Year 2025-2026 Analysis</h3>
                <?php if ($yearData): ?>
                    <table class="result-table">
                        <tr><th>Property</th><th>Value</th></tr>
                        <tr><td>Year ID</td><td><?php echo $yearData['YearID']; ?></td></tr>
                        <tr><td>Year Name</td><td><?php echo $yearData['YearName']; ?></td></tr>
                        <tr><td>Start Date</td><td><?php echo $yearData['StartDate']; ?></td></tr>
                        <tr><td>End Date</td><td><?php echo $yearData['EndDate']; ?></td></tr>
                        <tr><td>Transactions</td><td class="<?php echo $yearData['TransactionCount'] > 0 ? 'success' : 'error'; ?>"><?php echo $yearData['TransactionCount']; ?></td></tr>
                    </table>
                    <?php if ($yearData['TransactionCount'] == 0): ?>
                        <p class="warning">⚠️ Year exists but has NO transactions assigned!</p>
                        <p><strong>Solution:</strong> Either add transactions for this year, or select "All Years"</p>
                    <?php else: ?>
                        <p class="success">✅ Year has <?php echo $yearData['TransactionCount']; ?> transaction(s)</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="error">❌ Year 2025-2026 NOT FOUND in database!</p>
                    <p><strong>Solution:</strong> Create this year first or select a different year</p>
                <?php endif; ?>
            </div>
            <?php
        } catch (Exception $e) {
            echo '<div class="diagnostic-card"><h3>❌ Test 3: Failed</h3><p class="error">Error: ' . $e->getMessage() . '</p></div>';
        }

        // Test 4: All Years with Transaction Counts
        try {
            $stmt = $pdo->query("SELECT ay.YearID, ay.YearName, COUNT(t.TransactionID) as TransactionCount FROM academic_years ay LEFT JOIN transactions t ON ay.YearID = t.YearID GROUP BY ay.YearID ORDER BY ay.StartDate DESC");
            $allYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="diagnostic-card">
                <h3>📊 Test 4: All Years with Transaction Counts</h3>
                <table class="result-table">
                    <tr>
                        <th>Year ID</th>
                        <th>Year Name</th>
                        <th>Transaction Count</th>
                        <th>Status</th>
                    </tr>
                    <?php foreach ($allYears as $year): ?>
                    <tr>
                        <td><?php echo $year['YearID']; ?></td>
                        <td><?php echo $year['YearName']; ?></td>
                        <td><?php echo $year['TransactionCount']; ?></td>
                        <td>
                            <?php if ($year['TransactionCount'] > 0): ?>
                                <span class="success">✓ Has Data</span>
                            <?php else: ?>
                                <span class="warning">⚠ Empty</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php
        } catch (Exception $e) {
            echo '<div class="diagnostic-card"><h3>❌ Test 4: Failed</h3><p class="error">Error: ' . $e->getMessage() . '</p></div>';
        }

        // Test 5: Check for Orphaned Transactions
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as orphaned FROM transactions WHERE MemberID IS NULL OR YearID IS NULL");
            $orphanedCount = $stmt->fetch(PDO::FETCH_ASSOC)['orphaned'];
            ?>
            <div class="diagnostic-card">
                <h3>🔗 Test 5: Orphaned Transactions Check</h3>
                <?php if ($orphanedCount > 0): ?>
                    <p class="error">❌ Found <?php echo $orphanedCount; ?> orphaned transaction(s) with NULL MemberID or YearID</p>
                    <p><strong>Solution:</strong> Fix these transactions to assign proper MemberID and YearID</p>
                <?php else: ?>
                    <p class="success">✅ No orphaned transactions found - all have proper Member and Year assignments</p>
                <?php endif; ?>
            </div>
            <?php
        } catch (Exception $e) {
            echo '<div class="diagnostic-card"><h3>❌ Test 5: Failed</h3><p class="error">Error: ' . $e->getMessage() . '</p></div>';
        }

        // Test 6: Simulate Exact Page Query
        try {
            $filterYear = 0; // Get from $_GET if needed
            $stmt = $pdo->query("
                SELECT 
                    t.TransactionID,
                    t.TransactionDate,
                    t.CollectionAmount,
                    m.MemberName,
                    ay.YearName
                FROM transactions t
                LEFT JOIN members m ON t.MemberID = m.MemberID
                LEFT JOIN academic_years ay ON t.YearID = ay.YearID
                WHERE 1=1
                ORDER BY t.TransactionDate DESC
                LIMIT 5
            ");
            $simulatedResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="diagnostic-card">
                <h3>🔍 Test 6: Simulated Page Query (No Filters)</h3>
                <?php if (count($simulatedResults) > 0): ?>
                    <p class="success">✅ Query returns <?php echo count($simulatedResults); ?> result(s) without filters</p>
                    <table class="result-table">
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Member</th>
                            <th>Year</th>
                            <th>Amount</th>
                        </tr>
                        <?php foreach ($simulatedResults as $trans): ?>
                        <tr>
                            <td><?php echo $trans['TransactionID']; ?></td>
                            <td><?php echo $trans['TransactionDate']; ?></td>
                            <td><?php echo $trans['MemberName'] ?? '<span class="error">NULL</span>'; ?></td>
                            <td><?php echo $trans['YearName'] ?? '<span class="error">NULL</span>'; ?></td>
                            <td>₹<?php echo number_format($trans['CollectionAmount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php 
                    // Check for NULL years
                    $hasNullYears = false;
                    foreach ($simulatedResults as $trans) {
                        if ($trans['YearName'] === null) {
                            $hasNullYears = true;
                            break;
                        }
                    }
                    if ($hasNullYears): ?>
                        <p class="error">⚠️ Some transactions show NULL Year - they reference a YearID that doesn't exist in academic_years!</p>
                        <p><strong>Solution:</strong> <a href="fix-transaction-years.php" style="color: #007bff; font-weight: bold;">Click here to fix the year assignment issue →</a></p>
                    <?php else: ?>
                        <p class="warning">⚠️ If these show up here but NOT on manage-transactions page, the issue is with filters!</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="error">❌ No results even without filters - database join issue!</p>
                <?php endif; ?>
            </div>
            <?php
        } catch (Exception $e) {
            echo '<div class="diagnostic-card"><h3>❌ Test 6: Failed</h3><p class="error">Error: ' . $e->getMessage() . '</p></div>';
        }
        ?>

        <!-- Summary and Recommendations -->
        <div class="diagnostic-card" style="border-left-color: #28a745;">
            <h3>💡 Recommendations</h3>
            <ol>
                <li><strong>Click "Reset" button</strong> on manage-transactions page to clear ALL filters</li>
                <li><strong>Select "All Years"</strong> from Year dropdown to see all transactions</li>
                <li><strong>Select "All Members"</strong> from Member dropdown</li>
                <li><strong>Check Year Assignment:</strong> Make sure transactions are assigned to the year you're filtering by</li>
            </ol>
        </div>

        <a href="manage-transactions.php" class="back-btn">← Back to Transactions</a>
    </div>
<?php include '../includes/gemini-widget.php'; ?>
</body>
</html>
