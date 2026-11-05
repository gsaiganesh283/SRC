<?php
/**
 * Manage Events Page
 * List, edit, delete events with full CRUD operations
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../session.php';
require_once '../../../config/config.php';
require_once '../../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Check if events table exists
$tableCheckStmt = $pdo->query("SHOW TABLES LIKE 'events'");
if ($tableCheckStmt->rowCount() === 0) {
    header('Location: ../setup-events.php');
    exit;
}

$message = '';
$messageType = '';

// Handle delete action
if (isset($_POST['delete_event'])) {
    $eventId = $_POST['event_id'] ?? '';
    if ($eventId) {
        try {
            $deleteStmt = $pdo->prepare("DELETE FROM events WHERE EventID = ?");
            $deleteStmt->execute([$eventId]);
            $message = "Event deleted successfully!";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = "Error deleting event: " . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Handle status update
if (isset($_POST['update_status'])) {
    $eventId = $_POST['event_id'] ?? '';
    $newStatus = $_POST['new_status'] ?? '';
    if ($eventId && $newStatus) {
        try {
            $updateStmt = $pdo->prepare("UPDATE events SET Status = ? WHERE EventID = ?");
            $updateStmt->execute([$newStatus, $eventId]);
            $message = "Event status updated to '$newStatus'!";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = "Error updating status: " . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Get filter parameters
$selectedYear = isset($_GET['year']) ? $_GET['year'] : 'all';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Fetch academic years
$yearsStmt = $pdo->query("SELECT YearID, YearName FROM academic_years ORDER BY YearName DESC");
$academicYears = $yearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Build query
$whereConditions = [];
$params = [];

if ($selectedYear !== 'all') {
    $whereConditions[] = "e.YearID = ?";
    $params[] = $selectedYear;
}

if ($statusFilter !== 'all') {
    $whereConditions[] = "e.Status = ?";
    $params[] = $statusFilter;
}

if ($search) {
    $whereConditions[] = "(e.EventName LIKE ? OR e.EventDescription LIKE ? OR e.Venue LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

// Count total
$countQuery = "SELECT COUNT(*) FROM events e $whereClause";
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetchColumn();
$totalPages = ceil($totalRecords / $limit);

// Fetch events
$query = "SELECT 
    e.*,
    ay.YearName,
    (SELECT COUNT(*) FROM event_transactions WHERE EventID = e.EventID) as TransactionCount
FROM events e
LEFT JOIN academic_years ay ON e.YearID = ay.YearID
$whereClause
ORDER BY e.EventDate DESC, e.EventID DESC
LIMIT $limit OFFSET $offset";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Events - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../shared-styles.css">
    <style>
        .page-header {
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .page-header h1 {
            font-size: 28px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .page-header h1 i {
            color: #8b5cf6;
        }
        
        .btn-primary {
            padding: 12px 24px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid #10b981;
            color: #10b981;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            color: #ef4444;
        }
        
        .filters-card {
            background: var(--bg-secondary);
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: var(--shadow);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        
        .filter-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 10px 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
        }
        
        .filter-group select:focus,
        .filter-group input:focus {
            border-color: #8b5cf6;
            outline: none;
        }
        
        .btn-filter {
            padding: 10px 20px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .events-grid {
            display: grid;
            gap: 24px;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .event-card {
            background: var(--bg-secondary);
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .event-card-header {
            padding: 20px 25px;
            background: var(--bg-tertiary);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .event-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .event-title a {
            color: inherit;
            text-decoration: none;
        }
        
        .event-title a:hover {
            color: #8b5cf6;
        }
        
        .event-meta {
            font-size: 13px;
            color: var(--text-secondary);
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .event-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-badge.planned {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }
        
        .status-badge.ongoing {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }
        
        .status-badge.completed {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        
        .status-badge.cancelled {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .event-card-body {
            padding: 20px 25px;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
        }
        
        .event-stat {
            text-align: center;
            padding: 15px;
            background: var(--bg-tertiary);
            border-radius: 8px;
        }
        
        .event-stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }
        
        .event-stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .event-stat-value.collection { color: #10b981; }
        .event-stat-value.expense { color: #ef4444; }
        .event-stat-value.balance.positive { color: #10b981; }
        .event-stat-value.balance.negative { color: #ef4444; }
        
        .event-card-footer {
            padding: 15px 25px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .event-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn-action {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        
        .btn-view {
            background: rgba(139, 92, 246, 0.15);
            color: #8b5cf6;
        }
        
        .btn-edit {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }
        
        .btn-transactions {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        
        .btn-delete {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .status-dropdown {
            position: relative;
            display: inline-block;
        }
        
        .status-dropdown-content {
            display: none;
            position: absolute;
            background: var(--bg-secondary);
            min-width: 150px;
            box-shadow: var(--shadow);
            border-radius: 8px;
            z-index: 100;
            top: 100%;
            right: 0;
        }
        
        .status-dropdown:hover .status-dropdown-content {
            display: block;
        }
        
        .status-dropdown-content button {
            width: 100%;
            padding: 10px 15px;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            color: var(--text-primary);
            font-size: 13px;
        }
        
        .status-dropdown-content button:hover {
            background: var(--bg-tertiary);
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 30px 0;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
        }
        
        .pagination a {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        
        .pagination a:hover {
            background: #8b5cf6;
            color: white;
        }
        
        .pagination .current {
            background: #8b5cf6;
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-secondary);
            border-radius: 12px;
        }
        
        .empty-state i {
            font-size: 64px;
            color: var(--text-secondary);
            margin-bottom: 20px;
        }
        
        .empty-state h3 {
            font-size: 24px;
            color: var(--text-primary);
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 20px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            margin-bottom: 20px;
        }
        
        .back-link:hover {
            color: #8b5cf6;
        }
        
        @media (max-width: 1024px) {
            .event-card-body {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .event-card-body {
                grid-template-columns: 1fr;
            }
            
            .event-card-footer {
                flex-direction: column;
                gap: 15px;
            }
            
            .event-actions {
                width: 100%;
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-calendar-star"></i>
                <span>Event Management</span>
            </div>
            <div class="navbar-user">
                <button id="themeToggle" class="theme-toggle">
                    <i id="themeIcon" class="fas fa-moon"></i>
                    <span id="themeText">Dark</span>
                </button>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_name); ?></div>
                    <div class="user-role">Event Manager</div>
                </div>
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                    <span>Dashboard</span>
                </a>
                <a href="../../logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
                <?php include "../../includes/datetime-widget.php"; ?>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Event Dashboard
        </a>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="page-header">
            <div>
                <h1><i class="fas fa-calendar-alt"></i> Manage Events</h1>
                <p style="color: var(--text-secondary); margin-top: 8px;">
                    <?php echo number_format($totalRecords); ?> events found
                </p>
            </div>
            <a href="add-event.php" class="btn-primary">
                <i class="fas fa-plus"></i>
                New Event
            </a>
        </div>
        
        <!-- Filters -->
        <div class="filters-card">
            <form method="GET">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Academic Year</label>
                        <select name="year">
                            <option value="all">All Years</option>
                            <?php foreach ($academicYears as $year): ?>
                                <option value="<?php echo $year['YearID']; ?>" <?php echo $selectedYear == $year['YearID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['YearName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="Planned" <?php echo $statusFilter === 'Planned' ? 'selected' : ''; ?>>Planned</option>
                            <option value="Ongoing" <?php echo $statusFilter === 'Ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                            <option value="Completed" <?php echo $statusFilter === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="Cancelled" <?php echo $statusFilter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" name="search" placeholder="Event name, venue..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn-filter">
                            <i class="fas fa-filter"></i>
                            Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <?php if (empty($events)): ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>No Events Found</h3>
                <p>No events match your filter criteria.</p>
                <a href="add-event.php" class="btn-primary">
                    <i class="fas fa-plus"></i>
                    Create First Event
                </a>
            </div>
        <?php else: ?>
            <div class="events-grid">
                <?php foreach ($events as $event): ?>
                    <div class="event-card">
                        <div class="event-card-header">
                            <div>
                                <div class="event-title">
                                    <a href="view-event.php?id=<?php echo $event['EventID']; ?>">
                                        <?php echo htmlspecialchars($event['EventName']); ?>
                                    </a>
                                </div>
                                <div class="event-meta">
                                    <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($event['EventDate'])); ?></span>
                                    <?php if ($event['Venue']): ?>
                                        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['Venue']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($event['YearName']): ?>
                                        <span><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($event['YearName']); ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-exchange-alt"></i> <?php echo $event['TransactionCount']; ?> transactions</span>
                                </div>
                            </div>
                            <span class="status-badge <?php echo strtolower($event['Status']); ?>">
                                <?php echo $event['Status']; ?>
                            </span>
                        </div>
                        
                        <div class="event-card-body">
                            <div class="event-stat">
                                <div class="event-stat-label">Budget</div>
                                <div class="event-stat-value">₹<?php echo number_format($event['BudgetAmount'], 0); ?></div>
                            </div>
                            <div class="event-stat">
                                <div class="event-stat-label">Collections</div>
                                <div class="event-stat-value collection">₹<?php echo number_format($event['TotalCollection'], 0); ?></div>
                            </div>
                            <div class="event-stat">
                                <div class="event-stat-label">Expenses</div>
                                <div class="event-stat-value expense">₹<?php echo number_format($event['TotalExpense'], 0); ?></div>
                            </div>
                            <div class="event-stat">
                                <div class="event-stat-label">Balance</div>
                                <div class="event-stat-value balance <?php echo $event['EventBalance'] >= 0 ? 'positive' : 'negative'; ?>">
                                    ₹<?php echo number_format($event['EventBalance'], 0); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="event-card-footer">
                            <div class="event-actions">
                                <a href="view-event.php?id=<?php echo $event['EventID']; ?>" class="btn-action btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="edit-event.php?id=<?php echo $event['EventID']; ?>" class="btn-action btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                                <a href="add-transaction.php?event=<?php echo $event['EventID']; ?>" class="btn-action btn-transactions">
                                    <i class="fas fa-plus"></i> Add Transaction
                                </a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this event? This will also delete all associated transactions.')">
                                    <input type="hidden" name="event_id" value="<?php echo $event['EventID']; ?>">
                                    <button type="submit" name="delete_event" class="btn-action btn-delete">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            </div>
                            <div class="status-dropdown">
                                <button class="btn-action btn-edit">
                                    <i class="fas fa-exchange-alt"></i> Change Status
                                </button>
                                <div class="status-dropdown-content">
                                    <form method="POST">
                                        <input type="hidden" name="event_id" value="<?php echo $event['EventID']; ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <button type="submit" name="new_status" value="Planned">📋 Planned</button>
                                        <button type="submit" name="new_status" value="Ongoing">🔄 Ongoing</button>
                                        <button type="submit" name="new_status" value="Completed">✅ Completed</button>
                                        <button type="submit" name="new_status" value="Cancelled">❌ Cancelled</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="fas fa-chevron-left"></i> Prev
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script src="../../theme-manager.js"></script>
</body>
</html>
