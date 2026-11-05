<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Get admin details for audit logging
$adminId = $_SESSION['admin_id'];
$stmt = $pdo->prepare("SELECT Username FROM admins WHERE AdminID = ?");
$stmt->execute([$adminId]);
$adminUsername = $stmt->fetchColumn();

$success = $error = '';

// Handle quick actions
try {
    if (isset($_GET['toggle_status'])) {
        $id = intval($_GET['toggle_status']);
        
        $stmt = $pdo->prepare("SELECT Status, MemberName FROM members WHERE MemberID = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($member) {
            $newStatus = $member['Status'] === 'Active' ? 'Inactive' : 'Active';
            
            $stmt = $pdo->prepare("UPDATE members SET Status = ?, UpdatedAt = NOW() WHERE MemberID = ?");
            $stmt->execute([$newStatus, $id]);
            
            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, Details) 
                VALUES ('Admin', ?, ?, 'UPDATE', 'members', ?, ?, ?)
            ");
            $stmt->execute([$adminId, $adminUsername, $id, $ip, "Changed status to $newStatus for {$member['MemberName']}"]);
            
            $success = "Member status changed to $newStatus successfully!";
        }
    }
    
    if (isset($_GET['reset_password'])) {
        $id = intval($_GET['reset_password']);
        
        $stmt = $pdo->prepare("SELECT MemberName, Phone FROM members WHERE MemberID = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($member) {
            if (empty($member['Phone'])) {
                $error = "Cannot reset password: Member has no phone number set.";
            } else {
                // Reset password to NULL (member can login with phone number)
                $stmt = $pdo->prepare("UPDATE members SET Password = NULL, UpdatedAt = NOW() WHERE MemberID = ?");
                $stmt->execute([$id]);
                
                // Log activity
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, Details) 
                    VALUES ('Admin', ?, ?, 'UPDATE', 'members', ?, ?, ?)
                ");
                $stmt->execute([$adminId, $adminUsername, $id, $ip, "Reset password for {$member['MemberName']} (Phone: {$member['Phone']})"]);
                
                $success = "Password reset successfully! Member can now login with phone number: {$member['Phone']}";
            }
        }
    }
} catch (Exception $e) {
    $error = 'Error: ' . $e->getMessage();
}

// Permission check - now more permissive
$canViewMembers = true;
if (isset($_SESSION['admin_id'])) {
    try {
        $canViewMembers = hasAdminPermission($_SESSION['admin_id'], 'view_members');
    } catch (Exception $e) {
        error_log('Permission check error: ' . $e->getMessage());
        $canViewMembers = true; // Grant access on error
    }
}

try {
    // Select all columns explicitly, using MemberName as Name for compatibility
    $stmt = $pdo->query("
        SELECT 
            MemberID,
            MemberName AS Name,
            Phone,
            Email,
            Address,
            Photo,
            Status,
            CreatedAt,
            UpdatedAt
        FROM members 
        ORDER BY MemberID ASC
    ");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $totalMembers = count($members);
    $activeMembers = count(array_filter($members, fn($m) => strtolower($m['Status']) === 'active'));
    $inactiveMembers = $totalMembers - $activeMembers;
} catch (Exception $e) {
    die('Error fetching members: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Members - Admin</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Header Section */
        .header {
            background: rgba(255, 255, 255, 0.98);
            padding: 25px 30px;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header-left h1 {
            font-size: 28px;
            color: #333;
            margin-bottom: 5px;
        }
        
        .header-left p {
            color: #666;
            font-size: 14px;
        }
        
        .header-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        
        .btn-secondary {
            background: white;
            color: #667eea;
            padding: 12px 24px;
            border: 2px solid #667eea;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: #667eea;
            color: white;
        }

        /* Notification Styles */
        .notification {
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
            font-weight: 500;
        }

        .notification.success {
            background: #d4edda;
            color: #155724;
            border-left: 5px solid #28a745;
        }

        .notification.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 5px solid #dc3545;
        }

        .notification i {
            font-size: 20px;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.98);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }
        
        .stat-icon.total { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        .stat-icon.active { background: linear-gradient(135deg, #06d6a0, #118ab2); color: white; }
        .stat-icon.inactive { background: linear-gradient(135deg, #ef476f, #ff6b9d); color: white; }
        
        .stat-info h3 {
            font-size: 32px;
            color: #333;
            margin-bottom: 5px;
        }
        
        .stat-info p {
            color: #666;
            font-size: 14px;
        }
        
        /* Search and Filter */
        .controls {
            background: rgba(255, 255, 255, 0.98);
            padding: 20px 25px;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            margin-bottom: 25px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }
        
        .search-box input {
            width: 100%;
            padding: 12px 45px 12px 45px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
        }
        
        .filter-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .filter-btn {
            padding: 10px 20px;
            border: 2px solid #e0e0e0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .filter-btn:hover, .filter-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        /* View Toggle */
        .view-toggle {
            display: flex;
            gap: 8px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .view-toggle button {
            padding: 10px 18px;
            border: none;
            background: white;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .view-toggle button:hover, .view-toggle button.active {
            background: #667eea;
            color: white;
        }
        
        /* Box/Card View */
        .members-grid {
            display: none;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        
        .members-grid.active {
            display: grid;
        }
        
        .member-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .member-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .card-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 24px;
        }
        
        .card-title {
            flex: 1;
        }
        
        .card-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }
        
        .card-id {
            font-size: 12px;
            color: #999;
        }
        
        .card-details {
            margin: 16px 0;
        }
        
        .card-detail-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
            color: #666;
            font-size: 14px;
        }
        
        .card-detail-row i {
            width: 20px;
            color: #667eea;
        }
        
        .card-status {
            margin: 16px 0;
        }
        
        .card-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 16px;
        }
        
        .card-actions .btn-action {
            justify-content: center;
        }
        
        /* Members Table */
        .members-section {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        
        .table-container {
            overflow-x: auto;
            display: none;
        }
        
        .table-container.active {
            display: block;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        thead th {
            padding: 18px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s ease;
        }
        
        tbody tr:hover {
            background: #f8f9ff;
        }
        
        tbody td {
            padding: 18px 20px;
            color: #333;
            font-size: 14px;
        }
        
        .member-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .member-avatar-table {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 16px;
        }
        
        .member-name-cell {
            font-weight: 600;
            color: #333;
        }
        
        .member-id-cell {
            color: #999;
            font-size: 12px;
        }
        
        .status-badge-table {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-badge-table.active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge-table.inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
        }
        
        .btn-view {
            background: #17a2b8;
            color: white;
        }
        
        .btn-view:hover {
            background: #138496;
            transform: scale(1.05);
        }
        
        .btn-edit {
            background: #28a745;
            color: white;
        }
        
        .btn-edit:hover {
            background: #218838;
            transform: scale(1.05);
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        
        .btn-delete:hover {
            background: #c82333;
            transform: scale(1.05);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
            transform: scale(1.05);
        }
        
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        
        .btn-warning:hover {
            background: #e0a800;
            transform: scale(1.05);
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #138496;
            transform: scale(1.05);
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .no-data i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .table-container {
                overflow-x: scroll;
            }
            
            table {
                min-width: 800px;
            }
            
            .members-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="dashboard-container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-users"></i> Member Management</h1>
                <p>Manage and monitor all colony members</p>
            </div>
            <div class="header-actions">
                <?php if (hasAdminPermission($_SESSION['admin_id'], 'add_members')): ?>
                <a href="add-member.php" class="btn-primary">
                    <i class="fas fa-user-plus"></i> Add New Member
                </a>
                <?php endif; ?>
                <a href="dashboard.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- Notifications -->
        <?php if ($success): ?>
            <div class="notification success" id="notification">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="notification error" id="notification">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['success'])): ?>
            <div class="notification success" id="notification">
                <i class="fas fa-check-circle"></i>
                <?php 
                    if ($_GET['success'] === 'member_deleted') {
                        echo 'Member deleted successfully!';
                    } elseif ($_GET['success'] === 'member_deactivated') {
                        $count = isset($_GET['transactions']) ? $_GET['transactions'] : 0;
                        echo "Member has $count transaction(s) and was marked as Inactive instead of deleted.";
                    }
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="notification error" id="notification">
                <i class="fas fa-exclamation-circle"></i>
                <?php 
                    if ($_GET['error'] === 'invalid_id') {
                        echo 'Invalid member ID provided!';
                    } elseif ($_GET['error'] === 'member_not_found') {
                        echo 'Member not found!';
                    } elseif ($_GET['error'] === 'delete_failed') {
                        echo 'Failed to delete member. Please try again.';
                    }
                ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $totalMembers; ?></h3>
                    <p>Total Members</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon active">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $activeMembers; ?></h3>
                    <p>Active Members</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon inactive">
                    <i class="fas fa-user-times"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $inactiveMembers; ?></h3>
                    <p>Inactive Members</p>
                </div>
            </div>
        </div>

        <!-- Search and Filter -->
        <div class="controls">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search by name, email, or phone...">
            </div>
            <div class="filter-group">
                <button class="filter-btn active" data-filter="all">
                    <i class="fas fa-filter"></i> All
                </button>
                <button class="filter-btn" data-filter="active">
                    <i class="fas fa-check-circle"></i> Active
                </button>
                <button class="filter-btn" data-filter="inactive">
                    <i class="fas fa-times-circle"></i> Inactive
                </button>
            </div>
            <div class="view-toggle">
                <button id="tableViewBtn" class="active" title="Table View">
                    <i class="fas fa-table"></i>
                </button>
                <button id="cardViewBtn" title="Card View">
                    <i class="fas fa-th-large"></i>
                </button>
            </div>
        </div>

        <!-- Members Section -->
        <div class="members-section">
            <!-- Table View -->
            <div class="table-container active" id="tableView">
                <?php if (!empty($members)): ?>
                <table id="membersTable">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $m): ?>
                        <tr data-status="<?php echo strtolower($m['Status']); ?>">
                            <td>
                                <div class="member-cell">
                                    <div class="member-avatar-table">
                                        <?php echo strtoupper(substr($m['Name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div class="member-name-cell"><?php echo htmlspecialchars($m['Name']); ?></div>
                                        <div class="member-id-cell">ID: #<?php echo $m['MemberID']; ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><i class="fas fa-phone"></i> <?php echo htmlspecialchars($m['Phone'] ?? ''); ?></td>
                            <td><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($m['Email'] ?? ''); ?></td>
                            <td>
                                <span class="status-badge-table <?php echo strtolower($m['Status'] ?? 'active'); ?>">
                                    <?php echo htmlspecialchars($m['Status'] ?? 'Active'); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view-member.php?id=<?php echo $m['MemberID']; ?>" class="btn-action btn-view" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (hasAdminPermission($_SESSION['admin_id'], 'edit_members')): ?>
                                    <a href="edit-member.php?id=<?php echo $m['MemberID']; ?>" class="btn-action btn-edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?toggle_status=<?php echo $m['MemberID']; ?>" 
                                       class="btn-action <?php echo strtolower($m['Status']) === 'active' ? 'btn-warning' : 'btn-success'; ?>" 
                                       title="<?php echo strtolower($m['Status']) === 'active' ? 'Deactivate' : 'Activate'; ?>"
                                       onclick="return confirm('Change member status?');">
                                        <i class="fas fa-<?php echo strtolower($m['Status']) === 'active' ? 'toggle-off' : 'toggle-on'; ?>"></i>
                                    </a>
                                    <a href="?reset_password=<?php echo $m['MemberID']; ?>" 
                                       class="btn-action btn-info" 
                                       title="Reset Password"
                                       onclick="return confirm('Reset password for this member? They will be able to login with their phone number.');">
                                        <i class="fas fa-key"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (hasAdminPermission($_SESSION['admin_id'], 'delete_members')): ?>
                                    <a href="delete-member.php?id=<?php echo $m['MemberID']; ?>" class="btn-action btn-delete confirm-action" 
                                       title="Delete" data-confirm-title="Delete Member" data-confirm-message="Are you sure you want to delete this member?">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-users-slash"></i>
                    <p style="font-size: 18px; margin-top: 10px;">No members found</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Card/Box View -->
            <div class="members-grid" id="cardView">
                <?php if (!empty($members)): ?>
                    <?php foreach ($members as $m): ?>
                    <div class="member-card" data-status="<?php echo strtolower($m['Status']); ?>">
                        <div class="card-header">
                            <div class="card-avatar">
                                <?php echo strtoupper(substr($m['Name'], 0, 1)); ?>
                            </div>
                            <div class="card-title">
                                <div class="card-name"><?php echo htmlspecialchars($m['Name']); ?></div>
                                <div class="card-id">ID: #<?php echo $m['MemberID']; ?></div>
                            </div>
                        </div>
                        
                        <div class="card-details">
                            <div class="card-detail-row">
                                <i class="fas fa-phone"></i>
                                <span><?php echo htmlspecialchars($m['Phone'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="card-detail-row">
                                <i class="fas fa-envelope"></i>
                                <span><?php echo htmlspecialchars($m['Email'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                        
                        <div class="card-status">
                            <span class="status-badge-table <?php echo strtolower($m['Status']); ?>">
                                <?php echo htmlspecialchars($m['Status']); ?>
                            </span>
                        </div>
                        
                        <div class="card-actions">
                            <a href="view-member.php?id=<?php echo $m['MemberID']; ?>" class="btn-action btn-view" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if (hasAdminPermission($_SESSION['admin_id'], 'edit_members')): ?>
                            <a href="edit-member.php?id=<?php echo $m['MemberID']; ?>" class="btn-action btn-edit" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="?toggle_status=<?php echo $m['MemberID']; ?>" 
                               class="btn-action <?php echo strtolower($m['Status']) === 'active' ? 'btn-warning' : 'btn-success'; ?>" 
                               title="<?php echo strtolower($m['Status']) === 'active' ? 'Deactivate' : 'Activate'; ?>"
                               onclick="return confirm('Change member status?');">
                                <i class="fas fa-<?php echo strtolower($m['Status']) === 'active' ? 'toggle-off' : 'toggle-on'; ?>"></i>
                            </a>
                            <a href="?reset_password=<?php echo $m['MemberID']; ?>" 
                               class="btn-action btn-info" 
                               title="Reset Password"
                               onclick="return confirm('Reset password for this member?');">
                                <i class="fas fa-key"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (hasAdminPermission($_SESSION['admin_id'], 'delete_members')): ?>
                            <a href="delete-member.php?id=<?php echo $m['MemberID']; ?>" class="btn-action btn-delete" title="Delete" onclick="return confirm('Are you sure?');">
                                <i class="fas fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="no-data" style="grid-column: 1/-1;">
                    <i class="fas fa-users-slash"></i>
                    <p style="font-size: 18px; margin-top: 10px;">No members found</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // View toggle functionality
        const tableViewBtn = document.getElementById('tableViewBtn');
        const cardViewBtn = document.getElementById('cardViewBtn');
        const tableView = document.getElementById('tableView');
        const cardView = document.getElementById('cardView');

        tableViewBtn.addEventListener('click', function() {
            tableViewBtn.classList.add('active');
            cardViewBtn.classList.remove('active');
            tableView.classList.add('active');
            cardView.classList.remove('active');
        });

        cardViewBtn.addEventListener('click', function() {
            cardViewBtn.classList.add('active');
            tableViewBtn.classList.remove('active');
            tableView.classList.remove('active');
            cardView.classList.add('active');
        });

        // Search functionality (works for both views)
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            
            // Search in table view
            const tableRows = document.querySelectorAll('#membersTable tbody tr');
            tableRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchValue) ? '' : 'none';
            });
            
            // Search in card view
            const cards = document.querySelectorAll('.member-card');
            cards.forEach(card => {
                const text = card.textContent.toLowerCase();
                card.style.display = text.includes(searchValue) ? '' : 'none';
            });
        });

        // Filter functionality (works for both views)
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const filter = this.dataset.filter;
                
                // Filter table rows
                const tableRows = document.querySelectorAll('#membersTable tbody tr');
                tableRows.forEach(row => {
                    if (filter === 'all') {
                        row.style.display = '';
                    } else {
                        row.style.display = row.dataset.status === filter ? '' : 'none';
                    }
                });
                
                // Filter cards
                const cards = document.querySelectorAll('.member-card');
                cards.forEach(card => {
                    if (filter === 'all') {
                        card.style.display = '';
                    } else {
                        card.style.display = card.dataset.status === filter ? '' : 'none';
                    }
                });
            });
        });

        // Auto-hide notification after 5 seconds
        const notification = document.getElementById('notification');
        if (notification) {
            setTimeout(() => {
                notification.style.transition = 'opacity 0.5s ease';
                notification.style.opacity = '0';
                setTimeout(() => {
                    notification.remove();
                    // Clean URL
                    const url = new URL(window.location);
                    url.searchParams.delete('success');
                    url.searchParams.delete('error');
                    url.searchParams.delete('transactions');
                    window.history.replaceState({}, '', url);
                }, 500);
            }, 5000);
        }
    </script>
    
    <?php include '../includes/modal-component.php'; ?>
</body>
</html>
