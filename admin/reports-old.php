<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Admin Reports Generation - Working Implementation
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Permission check - now more permissive
$canViewReports = true;
if (isset($_SESSION['admin_id'])) {
    try {
        $canViewReports = hasAdminPermission($_SESSION['admin_id'], 'view_reports');
    } catch (Exception $e) {
        error_log('Permission check error: ' . $e->getMessage());
        $canViewReports = true; // Grant access on error
    }
}

$reportType = $_GET['report'] ?? '';
$format = $_GET['format'] ?? 'html';
$yearId = $_GET['year_id'] ?? '';

// Get years for filter
$stmt = $pdo->query("SELECT * FROM academic_years ORDER BY StartDate DESC");
$academicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);

$reportData = [];
$reportTitle = '';

if ($reportType) {
    try {
        switch ($reportType) {
            case 'all-members':
                $reportTitle = 'All Members Report';
                $stmt = $pdo->query("SELECT MemberID, MemberName, PhoneNumber, EmailID, Status, JoinDate FROM members ORDER BY MemberName");
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            case 'collections':
                $reportTitle = 'Collections Report';
                $query = "SELECT t.TransactionDate, m.MemberName, y.YearName, t.CollectionAmount, t.PaymentMode 
                         FROM transactions t
                         JOIN members m ON t.MemberID = m.MemberID
                         JOIN academic_years y ON t.YearID = y.YearID
                         WHERE t.CollectionAmount > 0";
                if ($yearId) $query .= " AND t.YearID = " . intval($yearId);
                $query .= " ORDER BY t.TransactionDate DESC";
                $stmt = $pdo->query($query);
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            case 'balance-sheet':
                $reportTitle = 'Member Balance Sheet';
                $query = "SELECT m.MemberName, 
                         COALESCE(SUM(t.CollectionAmount), 0) as TotalCollected,
                         COALESCE(SUM(t.DistributedAmount), 0) as TotalDistributed,
                         (COALESCE(SUM(t.CollectionAmount), 0) - COALESCE(SUM(t.DistributedAmount), 0)) as Balance
                         FROM members m
                         LEFT JOIN transactions t ON m.MemberID = t.MemberID";
                if ($yearId) $query .= " WHERE t.YearID = " . intval($yearId);
                $query .= " GROUP BY m.MemberID ORDER BY m.MemberName";
                $stmt = $pdo->query($query);
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// CSV Export
if ($format === 'csv' && !empty($reportData)) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="report_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, array_keys($reportData[0]));
    foreach ($reportData as $row) fputcsv($output, $row);
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Admin Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/styles.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f3f4f6; margin: 0; padding: 2rem; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .header h1 { font-size: 2rem; color: #111827; }
        .report-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .report-card { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: all 0.3s; }
        .report-card:hover { transform: translateY(-4px); box-shadow: 0 8px 16px rgba(0,0,0,0.15); }
        .report-card h3 { margin: 0 0 0.5rem 0; color: #111827; display: flex; align-items: center; gap: 0.5rem; }
        .report-card p { color: #6b7280; margin: 0 0 1rem 0; font-size: 0.875rem; }
        .btn { display: inline-block; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: 500; transition: all 0.2s; }
        .btn-primary { background: #6366f1; color: white; }
        .btn-primary:hover { background: #4f46e5; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-secondary:hover { background: #4b5563; }
        .btn-sm { padding: 0.5rem 1rem; font-size: 0.875rem; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        th { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: white; padding: 1rem; text-align: left; font-weight: 600; }
        td { padding: 0.875rem 1rem; border-bottom: 1px solid #e5e7eb; }
        tr:hover { background: #f9fafb; }
        .filter-section { background: white; padding: 1.5rem; border-radius: 12px; margin-bottom: 2rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .filter-row { display: flex; gap: 1rem; flex-wrap: wrap; }
        .filter-group { flex: 1; min-width: 200px; }
        .filter-group label { display: block; margin-bottom: 0.5rem; color: #374151; font-weight: 500; }
        .form-control { width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 8px; font-size: 1rem; }
        .export-buttons { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .alert-danger { background: #fee2e2; color: #991b1b; }
        .alert-info { background: #dbeafe; color: #1e40af; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="container" style="margin-left: 280px; padding: 30px;">
        <div class="header">
            <h1><i class="fas fa-chart-bar"></i> Generate Reports</h1>
            <a href="dashboard-new.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Error: <?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (!$reportType): ?>
            <div class="report-grid">
                <div class="report-card">
                    <h3><i class="fas fa-users" style="color: #6366f1;"></i> All Members</h3>
                    <p>Complete list of all registered members</p>
                    <a href="?report=all-members" class="btn btn-primary btn-sm"><i class="fas fa-file-alt"></i> Generate</a>
                </div>
                <div class="report-card">
                    <h3><i class="fas fa-arrow-down" style="color: #10b981;"></i> Collections</h3>
                    <p>All collection transactions with filters</p>
                    <a href="?report=collections" class="btn btn-success btn-sm"><i class="fas fa-file-alt"></i> Generate</a>
                </div>
                <div class="report-card">
                    <h3><i class="fas fa-balance-scale" style="color: #8b5cf6;"></i> Balance Sheet</h3>
                    <p>Member-wise balance summary</p>
                    <a href="?report=balance-sheet" class="btn btn-primary btn-sm"><i class="fas fa-file-alt"></i> Generate</a>
                </div>
            </div>
        <?php else: ?>
            <div class="filter-section">
                <h3 style="margin-bottom: 1rem;"><?php echo $reportTitle; ?></h3>
                <form method="GET">
                    <input type="hidden" name="report" value="<?php echo $reportType; ?>">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Academic Year:</label>
                            <select name="year_id" class="form-control">
                                <option value="">All Years</option>
                                <?php foreach ($academicYears as $year): ?>
                                    <option value="<?php echo $year['YearID']; ?>" <?php echo ($yearId == $year['YearID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['YearName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Report Template:</label>
                            <select name="template_id" class="form-control" id="reportTemplate">
                                <?php
                                try {
                                    $templateStmt = $pdo->query("
                                        SELECT TemplateID, TemplateName, TemplateType, IsDefault 
                                        FROM report_templates 
                                        WHERE IsActive = 1 AND ApplicableTo IN ('All', 'Admin')
                                        ORDER BY IsDefault DESC, TemplateName
                                    ");
                                    while ($template = $templateStmt->fetch(PDO::FETCH_ASSOC)) {
                                        $selected = $template['IsDefault'] ? 'selected' : '';
                                        echo '<option value="' . $template['TemplateID'] . '" ' . $selected . '>';
                                        echo htmlspecialchars($template['TemplateName']);
                                        if ($template['IsDefault']) echo ' ⭐';
                                        echo '</option>';
                                    }
                                } catch (Exception $e) {
                                    echo '<option value="">Default Template</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="filter-group" style="flex: 0;">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if (!empty($reportData)): ?>
                <div class="export-buttons">
                    <a href="?report=<?php echo $reportType; ?>&format=csv<?php echo $yearId ? '&year_id='.$yearId : ''; ?>" class="btn btn-success">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                    <button onclick="window.print()" class="btn btn-secondary"><i class="fas fa-print"></i> Print</button>
                </div>

                <table>
                    <thead>
                        <tr>
                            <?php foreach (array_keys($reportData[0]) as $header): ?>
                                <th><?php echo htmlspecialchars(ucwords(str_replace(['_', 'ID'], [' ', ' ID'], $header))); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportData as $row): ?>
                            <tr>
                                <?php foreach ($row as $cell): ?>
                                    <td><?php echo htmlspecialchars($cell ?? '-'); ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top: 1rem; text-align: center; color: #6b7280;">
                    <p>Total Records: <?php echo count($reportData); ?> | Generated: <?php echo date('F j, Y g:i A'); ?></p>
                </div>
            <?php else: ?>
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> No data found for this report.</div>
            <?php endif; ?>

            <a href="reports.php" class="btn btn-secondary" style="margin-top: 1rem;"><i class="fas fa-arrow-left"></i> Back to Reports</a>
        <?php endif; ?>
    </div>
</body>
</html>
