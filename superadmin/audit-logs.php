<?php
// Audit Logs Viewer - Super Admin
// URL: superadmin/audit-logs.php

require_once '../config/config.php';
require_once 'session.php';

// Require super admin authentication
if (!isset($_SESSION['superadmin_id'])) {
    header('Location: login.php');
    exit();
}

$superAdminName = $_SESSION['superadmin_name'] ?? 'Super Admin';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 50;
$offset = ($page - 1) * $perPage;

// Filters
$filterUser = isset($_GET['user']) ? $_GET['user'] : '';
$filterUserType = isset($_GET['user_type']) ? $_GET['user_type'] : '';
$filterModule = isset($_GET['module']) ? $_GET['module'] : '';
$filterAction = isset($_GET['action']) ? $_GET['action'] : '';
$filterDateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filterDateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Build WHERE clause
$whereConditions = [];
$params = [];

if (!empty($filterUser)) {
    $whereConditions[] = "al.UserID = ?";
    $params[] = $filterUser;
}

if (!empty($filterUserType)) {
    $whereConditions[] = "al.UserType = ?";
    $params[] = $filterUserType;
}

if (!empty($filterModule)) {
    $whereConditions[] = "al.Module = ?";
    $params[] = $filterModule;
}

if (!empty($filterAction)) {
    $whereConditions[] = "al.Action LIKE ?";
    $params[] = '%' . $filterAction . '%';
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
    $whereConditions[] = "(al.Action LIKE ? OR al.Username LIKE ? OR al.IPAddress LIKE ? OR al.TableName LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Fetch audit logs
$totalLogs = 0;
try {
    // Get total count
    $countQuery = "
        SELECT COUNT(*) as total
        FROM audit_logs al
        $whereClause
    ";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $totalLogs = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalLogs / $perPage);
    
    // Get logs for current page
    $query = "
        SELECT 
            al.*,
            sa.Username as UserName,
            sa.Email as UserEmail
        FROM audit_logs al
        LEFT JOIN super_admins sa ON al.UserID = sa.SuperAdminID
        $whereClause
    ORDER BY al.CreatedAt DESC
    LIMIT $perPage OFFSET $offset
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get unique users for filter
    $stmt = $pdo->query("
        SELECT DISTINCT sa.SuperAdminID, sa.Username
        FROM audit_logs al
        LEFT JOIN super_admins sa ON al.UserID = sa.SuperAdminID
        WHERE sa.SuperAdminID IS NOT NULL
        ORDER BY sa.Username
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get unique actions for filter
    $stmt = $pdo->query("
        SELECT DISTINCT Action
        FROM audit_logs
        WHERE Action IS NOT NULL
        ORDER BY Action
    ");
    $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as totalLogs,
            COUNT(DISTINCT UserID) as uniqueUsers,
            COUNT(DISTINCT DATE(CreatedAt)) as activeDays,
            COUNT(DISTINCT Action) as uniqueActions
        FROM audit_logs
        WHERE CreatedAt >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent activity by action type
    $stmt = $pdo->query("
        SELECT 
            Action,
            COUNT(*) as count
        FROM audit_logs
    WHERE CreatedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY Action
        ORDER BY count DESC
        LIMIT 5
    ");
    $actionStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $logs = [];
    $users = [];
    $actions = [];
    $stats = ['totalLogs' => 0, 'uniqueUsers' => 0, 'activeDays' => 0, 'uniqueActions' => 0];
    $actionStats = [];
    $error = $e->getMessage();
}

// Export to CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV Headers
    fputcsv($output, ['Log ID', 'User', 'Action', 'Details', 'IP Address', 'Date/Time']);
    
    // Fetch all logs for export (without pagination)
    $query = "
        SELECT 
            al.*,
            sa.Username as UserName
        FROM audit_logs al
        LEFT JOIN super_admins sa ON al.UserID = sa.SuperAdminID
        $whereClause
        ORDER BY al.CreatedAt DESC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $exportLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($exportLogs as $log) {
        fputcsv($output, [
            $log['LogID'],
            $log['UserName'] ?? 'Unknown',
            $log['Action'],
            !empty($log['NewData']) ? strip_tags($log['NewData']) : (!empty($log['OldData']) ? strip_tags($log['OldData']) : '-'),
            $log['IPAddress'],
            date('Y-m-d H:i:s', strtotime($log['CreatedAt']))
        ]);
    }
    
    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - Super Admin</title>
    <link rel="stylesheet" href="shared-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .logs-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .page-title {
            font-size: 28px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .page-title i {
            color: var(--primary-color);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            border-left: 4px solid var(--primary-color);
        }
        
        .stat-card.blue { border-left-color: #1a73e8; }
        .stat-card.green { border-left-color: #34a853; }
        .stat-card.orange { border-left-color: #fbbc04; }
        .stat-card.purple { border-left-color: #9334e6; }
        
        .stat-icon {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .stat-card.blue .stat-icon { color: #1a73e8; }
        .stat-card.green .stat-icon { color: #34a853; }
        .stat-card.orange .stat-icon { color: #fbbc04; }
        .stat-card.purple .stat-icon { color: #9334e6; }
        
        .stat-label {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        /* Filters */
        .filter-section {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
        }
        
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .filter-group select,
        .filter-group input {
            padding: 10px;
            border: 2px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            color: #1e293b;
            transition: all 0.3s;
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .filter-group select {
            cursor: pointer;
            background-image: linear-gradient(45deg, transparent 50%, #667eea 50%), linear-gradient(135deg, #667eea 50%, transparent 50%);
            background-position: calc(100% - 20px) calc(1em + 2px), calc(100% - 15px) calc(1em + 2px);
            background-size: 5px 5px, 5px 5px;
            background-repeat: no-repeat;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        /* Logs Table */
        .table-container {
            background: var(--card-bg);
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        
        .table-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: var(--primary-color);
            color: white;
        }
        
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 14px;
        }
        
        tbody tr:hover {
            background: var(--hover-bg);
        }
        
        .action-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .action-login { background: #e3f2fd; color: #1976d2; }
        .action-logout { background: #fce4ec; color: #c2185b; }
        .action-create { background: #e8f5e9; color: #388e3c; }
        .action-update { background: #fff3e0; color: #f57c00; }
        .action-delete { background: #ffebee; color: #d32f2f; }
        .action-view { background: #f3e5f5; color: #7b1fa2; }
        .action-export { background: #e0f2f1; color: #00796b; }
        .action-default { background: #f5f5f5; color: #616161; }
        
        .ip-address {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            color: var(--text-secondary);
        }
        
        .details-text {
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .details-text:hover {
            white-space: normal;
            overflow: visible;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 20px;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all 0.3s;
        }
        
        .pagination a:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .pagination .active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .pagination .disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        
        .empty-state i {
            font-size: 60px;
            color: var(--border-color);
            margin-bottom: 20px;
        }
        
        /* Action Stats */
        .action-stats {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
        }
        
        .action-stats-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-primary);
        }
        
        .action-stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .action-stat-item:last-child {
            border-bottom: none;
        }
        
        .action-stat-label {
            font-size: 14px;
            color: var(--text-secondary);
        }
        
        .action-stat-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 10px;
            }
            
            .details-text {
                max-width: 150px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="logs-container">
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <h1 class="page-title"><i class="fas fa-clipboard-list"></i> Audit Logs</h1>
            <button class="btn btn-primary" onclick="exportAuditLogsPDF()">
                <i class="fas fa-file-pdf"></i> Export to PDF
            </button>
        </div>
        <script>
        function exportAuditLogsPDF() {
            const params = new URLSearchParams(window.location.search);
            window.open('export-audit-logs-pdf.php?' + params.toString(), '_blank');
        }
        </script>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-icon"><i class="fas fa-list"></i></div>
                <div class="stat-label">Total Logs (30 Days)</div>
                <div class="stat-value"><?php echo number_format($stats['totalLogs']); ?></div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-label">Unique Users</div>
                <div class="stat-value"><?php echo number_format($stats['uniqueUsers']); ?></div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                <div class="stat-label">Active Days</div>
                <div class="stat-value"><?php echo number_format($stats['activeDays']); ?></div>
            </div>
            <div class="stat-card purple">
                <div class="stat-icon"><i class="fas fa-bolt"></i></div>
                <div class="stat-label">Action Types</div>
                <div class="stat-value"><?php echo number_format($stats['uniqueActions']); ?></div>
            </div>
        </div>
        
        <!-- Recent Activity Stats -->
        <?php if (!empty($actionStats)): ?>
        <div class="action-stats">
            <div class="action-stats-title">
                <i class="fas fa-chart-bar"></i> Top Actions (Last 7 Days)
            </div>
            <?php foreach ($actionStats as $actionStat): ?>
            <div class="action-stat-item">
                <span class="action-stat-label"><?php echo htmlspecialchars($actionStat['Action']); ?></span>
                <span class="action-stat-value"><?php echo number_format($actionStat['count']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <!-- Filters -->
        <div class="filter-section">
            <div class="filter-header">
                <h2 class="filter-title">
                    <i class="fas fa-filter"></i>
                    Filter Logs
                </h2>
            </div>
            
            <form method="GET" action="">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="user_type"><i class="fas fa-user-tag"></i> User Type</label>
                        <select name="user_type" id="user_type">
                            <option value="">All Types</option>
                            <option value="SuperAdmin" <?php echo (isset($_GET['user_type']) && $_GET['user_type'] == 'SuperAdmin') ? 'selected' : ''; ?>>Super Admin</option>
                            <option value="Admin" <?php echo (isset($_GET['user_type']) && $_GET['user_type'] == 'Admin') ? 'selected' : ''; ?>>Admin</option>
                            <option value="Member" <?php echo (isset($_GET['user_type']) && $_GET['user_type'] == 'Member') ? 'selected' : ''; ?>>Member</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="module"><i class="fas fa-cube"></i> Module</label>
                        <select name="module" id="module">
                            <option value="">All Modules</option>
                            <option value="Authentication" <?php echo $filterModule == 'Authentication' ? 'selected' : ''; ?>>Authentication</option>
                            <option value="Members" <?php echo $filterModule == 'Members' ? 'selected' : ''; ?>>Members</option>
                            <option value="Transactions" <?php echo $filterModule == 'Transactions' ? 'selected' : ''; ?>>Transactions</option>
                            <option value="Settings" <?php echo $filterModule == 'Settings' ? 'selected' : ''; ?>>Settings</option>
                            <option value="Reports" <?php echo $filterModule == 'Reports' ? 'selected' : ''; ?>>Reports</option>
                            <option value="System" <?php echo $filterModule == 'System' ? 'selected' : ''; ?>>System</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="user"><i class="fas fa-user"></i> User</label>
                        <select name="user" id="user">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['SuperAdminID']; ?>" 
                                        <?php echo $filterUser == $user['SuperAdminID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['Username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="action"><i class="fas fa-bolt"></i> Action</label>
                        <select name="action" id="action">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $action): ?>
                                <option value="<?php echo htmlspecialchars($action['Action']); ?>" 
                                        <?php echo $filterAction == $action['Action'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($action['Action']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_from"><i class="fas fa-calendar"></i> From Date</label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_to"><i class="fas fa-calendar"></i> To Date</label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="search"><i class="fas fa-search"></i> Search</label>
                        <input type="text" name="search" id="search" placeholder="Action, username, IP, or table..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="audit-logs.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear Filters
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Logs Table -->
        <div class="table-container">
            <div class="table-header">
                <h2 class="table-title">
                    <i class="fas fa-history"></i>
                    Activity History (<?php echo number_format($totalLogs); ?> logs)
                </h2>
            </div>
            
            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>No Logs Found</h3>
                    <p>No audit logs match your current filters</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>IP Address</th>
                            <th>Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): 
                            $actionClass = 'action-default';
                            $actionLower = strtolower($log['Action']);
                            if (strpos($actionLower, 'login') !== false) $actionClass = 'action-login';
                            elseif (strpos($actionLower, 'logout') !== false) $actionClass = 'action-logout';
                            elseif (strpos($actionLower, 'create') !== false || strpos($actionLower, 'add') !== false) $actionClass = 'action-create';
                            elseif (strpos($actionLower, 'update') !== false || strpos($actionLower, 'edit') !== false) $actionClass = 'action-update';
                            elseif (strpos($actionLower, 'delete') !== false) $actionClass = 'action-delete';
                            elseif (strpos($actionLower, 'view') !== false) $actionClass = 'action-view';
                            elseif (strpos($actionLower, 'export') !== false) $actionClass = 'action-export';
                        ?>
                            <tr>
                                <td><?php echo $log['LogID']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($log['UserName'] ?? 'Unknown'); ?></strong>
                                    <?php if ($log['UserEmail']): ?>
                                        <br><small style="color: var(--text-secondary);"><?php echo htmlspecialchars($log['UserEmail']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="action-badge <?php echo $actionClass; ?>">
                                        <?php echo htmlspecialchars($log['Action']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="details-text" title="<?php 
                                        $details = '';
                                        if (!empty($log['NewData'])) {
                                            $details = mb_strimwidth(strip_tags($log['NewData']), 0, 120, '...');
                                        } elseif (!empty($log['OldData'])) {
                                            $details = mb_strimwidth(strip_tags($log['OldData']), 0, 120, '...');
                                        } else {
                                            $details = '-';
                                        }
                                        echo htmlspecialchars($details); 
                                    ?>">
                                        <?php echo htmlspecialchars($details); ?>
                                    </div>
                                </td>
                                <td class="ip-address"><?php echo htmlspecialchars($log['IPAddress']); ?></td>
                                <td><?php echo date('M d, Y h:i A', strtotime($log['CreatedAt'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php
                    $queryParams = $_GET;
                    unset($queryParams['page']);
                    
                    if ($page > 1):
                        $queryParams['page'] = $page - 1;
                    ?>
                        <a href="?<?php echo http_build_query($queryParams); ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i> Previous</span>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                        $queryParams['page'] = $i;
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query($queryParams); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php
                    if ($page < $totalPages):
                        $queryParams['page'] = $page + 1;
                    ?>
                        <a href="?<?php echo http_build_query($queryParams); ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <script src="theme-manager.js"></script>
</body>
</html>
