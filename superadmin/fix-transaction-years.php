<?php
// One-Click Fix for Transaction Year Assignment
// URL: http://localhost/SRCOLONY-VIEWS/superadmin/fix-transaction-years.php

require_once './session.php';
require_once '../config/config.php';

// Require super admin authentication
if (!isset($_SESSION['superadmin_id'])) {
    header('Location: login.php');
    exit();
}

$message = '';
$messageType = '';

// Handle fix action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_action'])) {
    try {
        $action = $_POST['fix_action'];
        
        if ($action === 'create_year_4') {
            // Create Year 4 in academic_years table
            $stmt = $pdo->prepare("
                INSERT INTO academic_years 
                (YearID, YearName, StartDate, EndDate, Status, CreatedAt, UpdatedAt)
                VALUES 
                (4, '2024-2025', '2024-04-01', '2025-03-31', 'Active', NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    YearName = '2024-2025',
                    StartDate = '2024-04-01',
                    EndDate = '2025-03-31',
                    Status = 'Active'
            ");
            $stmt->execute();
            $message = "✅ Successfully created Year 4 (2024-2025) in academic_years table! Transactions are now properly linked.";
            $messageType = 'success';
            
        } elseif ($action === 'assign_to_existing') {
            // Assign to existing year based on date
            $targetYearID = $_POST['target_year_id'] ?? null;
            if (!$targetYearID) {
                throw new Exception("Please select a target year");
            }
            
            $stmt = $pdo->prepare("UPDATE transactions SET YearID = ? WHERE YearID = 4");
            $stmt->execute([$targetYearID]);
            $affected = $stmt->rowCount();
            
            // Get year name
            $nameStmt = $pdo->prepare("SELECT YearName FROM academic_years WHERE YearID = ?");
            $nameStmt->execute([$targetYearID]);
            $yearName = $nameStmt->fetchColumn();
            
            $message = "✅ Successfully reassigned $affected transaction(s) to $yearName!";
            $messageType = 'success';
            
        } elseif ($action === 'smart_assign') {
            // Smart assignment based on transaction date to existing years in academic_years
            $stmt = $pdo->prepare("
                UPDATE transactions t
                SET YearID = (
                    SELECT ay.YearID 
                    FROM academic_years ay
                    WHERE t.TransactionDate BETWEEN ay.StartDate AND ay.EndDate
                    LIMIT 1
                )
                WHERE YearID = 4
            ");
            $stmt->execute();
            $affected = $stmt->rowCount();
            $message = "✅ Successfully smart-assigned $affected transaction(s) based on their dates!";
            $messageType = 'success';
        }
        
    } catch (Exception $e) {
        $message = "❌ Error: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Get current status - CHECK BOTH TABLES
try {
    // First check which table exists and is used
    $stmt = $pdo->query("
        SELECT 
            t.YearID,
            ay.YearName,
            COUNT(t.TransactionID) as Count,
            MIN(t.TransactionDate) as FirstDate,
            MAX(t.TransactionDate) as LastDate
        FROM transactions t
        LEFT JOIN academic_years ay ON t.YearID = ay.YearID
        GROUP BY t.YearID
        ORDER BY t.YearID
    ");
    $yearStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get available years from academic_years
    $stmt = $pdo->query("SELECT YearID, YearName, StartDate, EndDate FROM academic_years ORDER BY YearID");
    $availableYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $yearStats = [];
    $availableYears = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Transaction Years - Super Admin</title>
    <link rel="stylesheet" href="shared-styles.css">
    <style>
        body { padding: 20px; font-family: system-ui, -apple-system, sans-serif; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .back-btn { display: inline-block; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 5px; margin-bottom: 20px; }
        .back-btn:hover { background: #5a6268; }
        h1 { color: #333; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 30px; }
        .status-card { background: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #dc3545; }
        .status-card.good { border-left-color: #28a745; }
        .status-card h3 { margin-top: 0; color: #dc3545; }
        .status-card.good h3 { color: #28a745; }
        .stats-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        .stats-table th, .stats-table td { padding: 12px; text-align: left; border: 1px solid #ddd; }
        .stats-table th { background: #007bff; color: white; }
        .problem { background: #fff3cd; padding: 10px; border-radius: 5px; border-left: 4px solid #ffc107; margin: 15px 0; }
        .problem strong { color: #856404; }
        .fix-options { margin: 30px 0; }
        .fix-option { background: #fff; border: 2px solid #dee2e6; padding: 20px; margin: 15px 0; border-radius: 8px; transition: all 0.3s; }
        .fix-option:hover { border-color: #007bff; box-shadow: 0 2px 8px rgba(0,123,255,0.1); }
        .fix-option h4 { margin-top: 0; color: #007bff; }
        .fix-option p { color: #666; margin: 10px 0; }
        .fix-btn { padding: 12px 24px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; transition: all 0.3s; }
        .btn-primary { background: #007bff; color: white; }
        .btn-primary:hover { background: #0056b3; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-warning { background: #ffc107; color: #212529; }
        .btn-warning:hover { background: #e0a800; }
        .message { padding: 15px; margin: 20px 0; border-radius: 5px; font-weight: bold; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .recommended { position: relative; }
        .recommended::before { content: "⭐ RECOMMENDED"; position: absolute; top: -10px; right: 10px; background: #28a745; color: white; padding: 5px 10px; border-radius: 5px; font-size: 12px; font-weight: bold; }
        .warning-box { background: #dc3545; color: white; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .warning-box strong { display: block; margin-bottom: 10px; font-size: 18px; }
    </style>
</head>
<body>
    <div class="container">
        <a href="manage-transactions.php" class="back-btn">← Back to Transactions</a>
        
        <h1>🔧 Fix Transaction Year Assignment</h1>
        <p class="subtitle">Resolve the issue where transactions are assigned to non-existent years</p>

        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Current Status -->
        <div class="status-card <?php echo (count($yearStats) > 0 && $yearStats[0]['YearName'] === null) ? '' : 'good'; ?>">
            <h3>📊 Current Status</h3>
            <table class="stats-table">
                <tr>
                    <th>Year ID</th>
                    <th>Year Name</th>
                    <th>Transactions</th>
                    <th>Date Range</th>
                    <th>Status</th>
                </tr>
                <?php foreach ($yearStats as $stat): ?>
                <tr>
                    <td><?php echo $stat['YearID']; ?></td>
                    <td>
                        <?php if ($stat['YearName']): ?>
                            <?php echo $stat['YearName']; ?>
                        <?php else: ?>
                            <strong style="color: #dc3545;">❌ INVALID (Year doesn't exist!)</strong>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $stat['Count']; ?></td>
                    <td>
                        <?php echo $stat['FirstDate']; ?><br>
                        to<br>
                        <?php echo $stat['LastDate']; ?>
                    </td>
                    <td>
                        <?php if ($stat['YearName']): ?>
                            <span style="color: #28a745;">✅ OK</span>
                        <?php else: ?>
                            <span style="color: #dc3545;">⚠️ BROKEN</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>

            <?php if (count($yearStats) > 0 && $yearStats[0]['YearName'] === null): ?>
                <div class="problem">
                    <strong>⚠️ Problem Detected:</strong> 
                    You have <?php echo $yearStats[0]['Count']; ?> transaction(s) assigned to Year ID <?php echo $yearStats[0]['YearID']; ?>, 
                    but this year doesn't exist in your database! This is why they don't appear when filtering.
                </div>
            <?php else: ?>
                <div style="background: #d4edda; padding: 10px; border-radius: 5px; border-left: 4px solid #28a745; margin: 15px 0;">
                    <strong style="color: #155724;">✅ All Good!</strong> All transactions are properly assigned to valid years.
                </div>
            <?php endif; ?>
        </div>

        <?php if (count($yearStats) > 0 && $yearStats[0]['YearName'] === null): ?>
        <!-- Fix Options -->
        <div class="fix-options">
            <h2>Choose a Fix Option:</h2>

            <div class="warning-box">
                <strong>⚠️ IMPORTANT:</strong>
                Your transactions reference YearID = 4, but it doesn't exist in the <code>academic_years</code> table!
            </div>

            <!-- Show available years -->
            <div class="fix-option" style="background: #e7f3ff; border-color: #0066cc;">
                <h4>📋 Available Years in Database:</h4>
                <?php if (count($availableYears) > 0): ?>
                    <table class="stats-table">
                        <tr>
                            <th>Year ID</th>
                            <th>Year Name</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                        </tr>
                        <?php foreach ($availableYears as $year): ?>
                        <tr>
                            <td><?php echo $year['YearID']; ?></td>
                            <td><?php echo $year['YearName']; ?></td>
                            <td><?php echo $year['StartDate']; ?></td>
                            <td><?php echo $year['EndDate']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <p style="color: #dc3545;">❌ No years found in academic_years table!</p>
                <?php endif; ?>
            </div>

            <!-- Option 1: Create Year 4 (RECOMMENDED) -->
            <div class="fix-option recommended">
                <h4>Option 1: Create Year 4 in Database ⭐</h4>
                <p>This will <strong>create Year 4 (2024-2025)</strong> in the <code>academic_years</code> table.</p>
                <p>Your <?php echo $yearStats[0]['Count']; ?> transactions will automatically link to it!</p>
                <ul>
                    <li>✅ No data changes needed</li>
                    <li>✅ Keeps existing YearID = 4 references</li>
                    <li>✅ Safest option - no risk of data corruption</li>
                </ul>
                <p><strong>Your transaction dates:</strong> <?php echo $yearStats[0]['FirstDate']; ?> to <?php echo $yearStats[0]['LastDate']; ?></p>
                <p style="color: #28a745; font-weight: bold;">✓ RECOMMENDED: This is the safest option!</p>
                <form method="POST" onsubmit="return confirm('Create Year 4 (2024-2025) in academic_years table?');">
                    <input type="hidden" name="fix_action" value="create_year_4">
                    <button type="submit" class="fix-btn btn-success">Create Year 4 (Recommended)</button>
                </form>
            </div>

            <?php if (count($availableYears) > 0): ?>
            <!-- Option 2: Reassign to existing year -->
            <div class="fix-option">
                <h4>Option 2: Reassign to Existing Year</h4>
                <p>Choose an existing year to reassign your <?php echo $yearStats[0]['Count']; ?> transaction(s) to:</p>
                <form method="POST" onsubmit="return confirm('Reassign all transactions to the selected year?');">
                    <input type="hidden" name="fix_action" value="assign_to_existing">
                    <select name="target_year_id" class="fix-btn" style="width: 100%; margin-bottom: 10px;" required>
                        <option value="">-- Select Year --</option>
                        <?php foreach ($availableYears as $year): ?>
                        <option value="<?php echo $year['YearID']; ?>">
                            <?php echo $year['YearName']; ?> 
                            (<?php echo $year['StartDate']; ?> to <?php echo $year['EndDate']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="fix-btn btn-primary">Reassign to Selected Year</button>
                </form>
            </div>

            <!-- Option 3: Smart Assignment -->
            <div class="fix-option">
                <h4>Option 3: Smart Assignment (Auto-match by Date)</h4>
                <p>Transactions will be automatically assigned to years based on their date:</p>
                <ul>
                    <?php foreach ($availableYears as $year): ?>
                    <li>Dates between <strong><?php echo $year['StartDate']; ?></strong> and <strong><?php echo $year['EndDate']; ?></strong> → <?php echo $year['YearName']; ?></li>
                    <?php endforeach; ?>
                </ul>
                <p><strong>Your transaction dates:</strong> <?php echo $yearStats[0]['FirstDate']; ?> to <?php echo $yearStats[0]['LastDate']; ?></p>
                <form method="POST" onsubmit="return confirm('Smart-assign all transactions based on their dates?');">
                    <input type="hidden" name="fix_action" value="smart_assign">
                    <button type="submit" class="fix-btn btn-warning">Smart Assign by Date</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
            <div style="text-align: center; padding: 40px;">
                <h2 style="color: #28a745;">🎉 Everything is Perfect!</h2>
                <p>All your transactions are properly assigned to valid years.</p>
                <a href="manage-transactions.php" class="fix-btn btn-success">Go to Manage Transactions</a>
            </div>
        <?php endif; ?>

        <a href="diagnose-transactions.php" class="back-btn" style="margin-top: 30px;">🔍 Run Diagnostics Again</a>
    </div>
<?php include '../includes/gemini-widget.php'; ?>
</body>
</html>
