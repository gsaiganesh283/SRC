<?php
require_once 'session.php';
require_once '../config/config.php';

$success = $error = '';
$action = $_GET['action'] ?? 'list';
$member_id = $_GET['id'] ?? null;

// Handle GET-based actions (delete, toggle status)
try {
    if (isset($_GET['delete'])) {
        $id = intval($_GET['delete']);
        
        // Get member details for logging
        $stmt = $pdo->prepare("SELECT MemberName FROM members WHERE MemberID = ?");
        $stmt->execute([$id]);
        $deleted_member = $stmt->fetchColumn();
        
        // Check if member has transactions
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE MemberID = ?");
        $stmt->execute([$id]);
        $transaction_count = $stmt->fetchColumn();
        
        if ($transaction_count > 0) {
            $error = "Cannot delete member with existing transactions. Found $transaction_count transaction(s). Please delete transactions first or set member status to Inactive.";
        } else {
            // Delete member
            $stmt = $pdo->prepare("DELETE FROM members WHERE MemberID = ?");
            $stmt->execute([$id]);
            
            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, Details) 
                VALUES ('SuperAdmin', ?, ?, 'Deleted Member', 'members', ?, ?, ?)
            ");
            $stmt->execute([$superadmin_id, $superadmin_username, $id, $ip, "Deleted: $deleted_member"]);
            
            $success = "Member deleted successfully!";
        }
        
        $action = 'list';
    }
    
    if (isset($_GET['toggle_status'])) {
        $id = intval($_GET['toggle_status']);
        
        $stmt = $pdo->prepare("SELECT Status, MemberName FROM members WHERE MemberID = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($member) {
            $new_status = $member['Status'] === 'Active' ? 'Inactive' : 'Active';
            
            $stmt = $pdo->prepare("UPDATE members SET Status = ? WHERE MemberID = ?");
            $stmt->execute([$new_status, $id]);
            
            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress) 
                VALUES ('SuperAdmin', ?, ?, ?, 'members', ?, ?)
            ");
            $stmt->execute([$superadmin_id, $superadmin_username, "Changed Status to $new_status", $id, $ip]);
            
            $success = "Member status changed to $new_status!";
        }
        
        $action = 'list';
    }
    
    if (isset($_GET['reset_password'])) {
        $id = intval($_GET['reset_password']);
        
        // Get member details
        $stmt = $pdo->prepare("SELECT MemberName, Phone FROM members WHERE MemberID = ?");
        $stmt->execute([$id]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($member) {
            if (empty($member['Phone'])) {
                $error = "Cannot reset password: Member has no phone number set.";
            } else {
                // Set password to NULL (which means first-time login with phone as password)
                // OR hash the phone number as the password
                // Setting to NULL allows them to login with their phone number
                $stmt = $pdo->prepare("UPDATE members SET Password = NULL WHERE MemberID = ?");
                $stmt->execute([$id]);
                
                // Log activity
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, Details) 
                    VALUES ('SuperAdmin', ?, ?, 'Reset Member Password', 'members', ?, ?, ?)
                ");
                $stmt->execute([
                    $superadmin_id, 
                    $superadmin_username, 
                    $id, 
                    $ip, 
                    "Reset password for: {$member['MemberName']} (Phone: {$member['Phone']})"
                ]);
                
                $success = "Password reset successfully! Member can now login with phone number: {$member['Phone']}";
            }
        } else {
            $error = "Member not found.";
        }
        
        $action = 'list';
    }
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}

// Handle form submissions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_member'])) {
            // Get all fields (none are mandatory)
            $member_name = trim($_POST['member_name'] ?? '');
            $father_name = trim($_POST['father_name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $aadhar = trim($_POST['aadhar'] ?? '');
            $status = $_POST['status'] ?? 'Active';
            $notes = trim($_POST['notes'] ?? '');
            
            // Convert empty strings to NULL for unique fields to avoid duplicate entry errors
            $aadhar = $aadhar === '' ? null : $aadhar;
            $phone = $phone === '' ? null : $phone;
            $email = $email === '' ? null : $email;
            
            $stmt = $pdo->prepare("
                INSERT INTO members (MemberName, FatherName, Address, Phone, Email, AadharNumber, Status, Notes, CreatedAt) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$member_name, $father_name, $address, $phone, $email, $aadhar, $status, $notes]);
            
            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress) 
                VALUES ('SuperAdmin', ?, ?, 'Created Member', 'members', ?, ?)
            ");
            $stmt->execute([$superadmin_id, $superadmin_username, $pdo->lastInsertId(), $ip]);
            
            $success = "Member added successfully!";
            $action = 'list';
        }
        
        if (isset($_POST['edit_member'])) {
            $id = $_POST['member_id'];
            $member_name = trim($_POST['member_name'] ?? '');
            $father_name = trim($_POST['father_name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $aadhar = trim($_POST['aadhar'] ?? '');
            $status = $_POST['status'] ?? 'Active';
            $notes = trim($_POST['notes'] ?? '');
            
            // Convert empty strings to NULL for unique fields to avoid duplicate entry errors
            $aadhar = $aadhar === '' ? null : $aadhar;
            $phone = $phone === '' ? null : $phone;
            $email = $email === '' ? null : $email;
            
            $stmt = $pdo->prepare("
                UPDATE members 
                SET MemberName = ?, FatherName = ?, Address = ?, Phone = ?, Email = ?, 
                    AadharNumber = ?, Status = ?, Notes = ?
                WHERE MemberID = ?
            ");
            $stmt->execute([$member_name, $father_name, $address, $phone, $email, $aadhar, $status, $notes, $id]);
            
            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress) 
                VALUES ('SuperAdmin', ?, ?, 'Updated Member', 'members', ?, ?)
            ");
            $stmt->execute([$superadmin_id, $superadmin_username, $id, $ip]);
            
            $success = "Member updated successfully!";
            $action = 'list';
        }
        
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch data for edit mode
$member_data = null;
if ($action === 'edit' && $member_id) {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE MemberID = ?");
    $stmt->execute([$member_id]);
    $member_data = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch all members for list mode
$members = [];
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';

if ($action === 'list') {
    $sql = "SELECT m.*, 
            (SELECT COUNT(*) FROM transactions WHERE MemberID = m.MemberID) as transaction_count,
            (SELECT IFNULL(SUM(CollectionAmount), 0) FROM transactions WHERE MemberID = m.MemberID) as total_collection,
            (SELECT IFNULL(SUM(DistributedAmount), 0) FROM transactions WHERE MemberID = m.MemberID) as total_distribution,
            (SELECT COALESCE(DistributedAmount, 0) + COALESCE(InterestAmount, 0) 
             FROM transactions 
             WHERE MemberID = m.MemberID 
             ORDER BY TransactionDate DESC, CreatedAt DESC 
             LIMIT 1) as net_balance
            FROM members m 
            WHERE 1=1";
    
    $params = [];
    
    if ($search) {
        $sql .= " AND (MemberName LIKE ? OR Phone LIKE ? OR Email LIKE ? OR AadharNumber LIKE ?)";
        $search_param = "%$search%";
        $params = array_fill(0, 4, $search_param);
    }
    
    if ($status_filter) {
        $sql .= " AND Status = ?";
        $params[] = $status_filter;
    }
    
    $sql .= " ORDER BY CreatedAt DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Members - Super Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
    <style>
        /* Custom Confirmation Modal */
        .custom-confirm-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        
        .custom-confirm-modal.show {
            display: flex;
        }
        
        .custom-confirm-box {
            background: white;
            border-radius: 8px;
            padding: 0;
            min-width: 400px;
            max-width: 500px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.2s ease-out;
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .custom-confirm-header {
            background: #f8f9fa;
            padding: 16px 20px;
            border-bottom: 1px solid #e0e0e0;
            border-radius: 8px 8px 0 0;
            font-size: 14px;
            font-weight: 500;
            color: #333;
        }
        
        .custom-confirm-body {
            padding: 24px 20px;
            font-size: 14px;
            color: #333;
            line-height: 1.5;
        }
        
        .custom-confirm-footer {
            padding: 12px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
        }
        
        .custom-confirm-btn {
            padding: 8px 24px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .custom-confirm-btn-ok {
            background: #0066cc;
            color: white;
        }
        
        .custom-confirm-btn-ok:hover {
            background: #0052a3;
        }
        
        .custom-confirm-btn-cancel {
            background: #e0e0e0;
            color: #333;
        }
        
        .custom-confirm-btn-cancel:hover {
            background: #d0d0d0;
        }
        
        .member-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
            padding: 15px;
            background: var(--bg-tertiary);
            border-radius: 8px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 5px;
        }
        
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .search-bar input,
        .search-bar select {
            padding: 10px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            cursor: pointer;
        }
        
        .search-bar select option {
            background: var(--bg-secondary);
            color: var(--text-primary);
            padding: 10px;
        }
        
        .search-bar input {
            cursor: text;
        }
        
        .search-bar input::placeholder {
            color: var(--text-muted);
        }
        
        .search-bar input {
            flex: 1;
            min-width: 250px;
        }
        
        .search-bar select {
            min-width: 150px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .actions-cell {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .btn-icon {
            padding: 8px 12px;
            font-size: 14px;
        }
        
        .member-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .member-card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }
        
        .member-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        
        .member-name {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .member-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .detail-item i {
            color: var(--gradient-1);
            width: 20px;
        }
        
        .detail-item strong {
            color: var(--text-primary);
        }
        
        /* Dark Theme Enhancements */
        [data-theme="dark"] .member-card {
            background: linear-gradient(135deg, #1a202c 0%, #2d3748 100%);
            border-color: #4a5568;
        }
        
        [data-theme="dark"] .member-stats {
            background: #1a202c;
            border: 1px solid #4a5568;
        }
        
        [data-theme="dark"] .search-bar input,
        [data-theme="dark"] .search-bar select {
            background: linear-gradient(135deg, #1a202c 0%, #2d3748 100%);
            border-color: #4a5568;
            color: #f7fafc;
        }
        
        [data-theme="dark"] .search-bar select option {
            background: #1a202c;
            color: #f7fafc;
            padding: 10px;
        }
        
        [data-theme="dark"] .search-bar select option:hover,
        [data-theme="dark"] .search-bar select option:checked {
            background: #2d3748;
            color: #ffffff;
        }
        
        [data-theme="dark"] .search-bar input::placeholder {
            color: #a0aec0;
        }
        
        [data-theme="dark"] .detail-item {
            color: #cbd5e0;
        }
        
        [data-theme="dark"] .detail-item i {
            color: #667eea;
        }
        
        [data-theme="dark"] .stat-value {
            color: #f7fafc;
        }
        
        [data-theme="dark"] .stat-label {
            color: #cbd5e0;
        }
        
        [data-theme="dark"] .member-name {
            color: #f7fafc;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .search-bar {
                flex-direction: column;
            }
            
            .search-bar input,
            .search-bar select {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <?php include 'includes/navbar.php'; ?>

    <div class="container">
        <!-- Quick Navigation -->
        
        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        
        <!-- List View -->
        <?php if ($action === 'list'): ?>
            <div class="page-header">
                <div>
                    <h1><i class="fas fa-users"></i> Manage Members</h1>
                    <p>Full access to add, edit, and delete members</p>
                </div>
                <a href="?action=add" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Add New Member
                </a>
            </div>
            
            <!-- Search and Filter -->
            <div class="card">
                <div class="card-body">
                    <form method="GET" class="search-bar">
                        <input type="hidden" name="action" value="list">
                        <input type="text" name="search" placeholder="Search by name, phone, email, or Aadhar..." value="<?php echo htmlspecialchars($search); ?>">
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <?php if ($search || $status_filter): ?>
                            <a href="?action=list" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <!-- Members List -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list"></i> All Members (<?php echo count($members); ?>)
                    </h3>
                </div>
                
                <?php if (empty($members)): ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-secondary);">
                        <i class="fas fa-users" style="font-size: 48px; margin-bottom: 15px; opacity: 0.3;"></i>
                        <p>No members found</p>
                        <?php if ($search || $status_filter): ?>
                            <a href="?action=list" class="btn btn-secondary" style="margin-top: 10px;">
                                <i class="fas fa-times"></i> Clear Filters
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($members as $member): ?>
                        <div class="member-card">
                            <div class="member-header">
                                <div>
                                    <div class="member-name">
                                        <?php echo htmlspecialchars($member['MemberName'] ?: 'Unnamed Member'); ?>
                                    </div>
                                    <span class="badge badge-<?php echo $member['Status'] === 'Active' ? 'success' : 'danger'; ?>">
                                        <?php echo htmlspecialchars($member['Status']); ?>
                                    </span>
                                </div>
                                <div class="actions-cell">
                                    <a href="#" 
                                       class="btn btn-success btn-icon member-login-btn" 
                                       data-member-id="<?php echo $member['MemberID']; ?>"
                                       data-member-name="<?php echo htmlspecialchars($member['MemberName'] ?: 'Member'); ?>"
                                       title="Login as Member">
                                        <i class="fas fa-sign-in-alt"></i>
                                    </a>
                                    <a href="?action=edit&id=<?php echo $member['MemberID']; ?>" class="btn btn-info btn-icon" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="?toggle_status=<?php echo $member['MemberID']; ?>" 
                                       class="btn btn-warning btn-icon confirm-action" 
                                       title="Toggle Status"
                                       data-confirm-title="Change Status"
                                       data-confirm-message="Change member status?">
                                        <i class="fas fa-toggle-on"></i>
                                    </a>
                                    <a href="?reset_password=<?php echo $member['MemberID']; ?>" 
                                       class="btn btn-primary btn-icon confirm-action" 
                                       title="Reset Password to Phone Number"
                                       data-confirm-title="Reset Password"
                                       data-confirm-message="Reset password to default (phone number)?\n\nMember will be able to login with:\nUsername: <?php echo htmlspecialchars($member['Phone'] ?? 'N/A'); ?>\nPassword: <?php echo htmlspecialchars($member['Phone'] ?? 'N/A'); ?>">
                                        <i class="fas fa-key"></i>
                                    </a>
                                    <a href="?delete=<?php echo $member['MemberID']; ?>" 
                                       class="btn btn-danger btn-icon confirm-action" 
                                       title="Delete"
                                       data-confirm-title="Delete Member"
                                       data-confirm-message="Are you sure you want to delete this member? This action cannot be undone!">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>
                            
                            <div class="member-details">
                                <?php if ($member['FatherName']): ?>
                                    <div class="detail-item">
                                        <i class="fas fa-user"></i>
                                        <span><strong>Father:</strong> <?php echo htmlspecialchars($member['FatherName']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($member['Phone']): ?>
                                    <div class="detail-item">
                                        <i class="fas fa-phone"></i>
                                        <span><?php echo htmlspecialchars($member['Phone']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($member['Email']): ?>
                                    <div class="detail-item">
                                        <i class="fas fa-envelope"></i>
                                        <span><?php echo htmlspecialchars($member['Email']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($member['AadharNumber']): ?>
                                    <div class="detail-item">
                                        <i class="fas fa-id-card"></i>
                                        <span>Aadhar: <?php echo htmlspecialchars($member['AadharNumber']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($member['Address']): ?>
                                    <div class="detail-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?php echo htmlspecialchars($member['Address']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="member-stats">
                                <div class="stat-item">
                                    <div class="stat-value"><?php echo $member['transaction_count']; ?></div>
                                    <div class="stat-label">Transactions</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value">₹<?php echo number_format($member['total_collection'], 2); ?></div>
                                    <div class="stat-label">Total Collection</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value">₹<?php echo number_format($member['total_distribution'], 2); ?></div>
                                    <div class="stat-label">Total Distribution</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-value">₹<?php echo number_format($member['net_balance'] ?? 0, 2); ?></div>
                                    <div class="stat-label">Net Balance</div>
                                </div>
                            </div>
                            
                            <?php if ($member['Notes']): ?>
                                <div class="detail-item" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border-color);">
                                    <i class="fas fa-sticky-note"></i>
                                    <span><strong>Notes:</strong> <?php echo nl2br(htmlspecialchars($member['Notes'])); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
        <!-- Add/Edit Form -->
        <?php elseif ($action === 'add' || $action === 'edit'): ?>
            <div class="page-header">
                <h1>
                    <i class="fas fa-<?php echo $action === 'add' ? 'user-plus' : 'user-edit'; ?>"></i> 
                    <?php echo $action === 'add' ? 'Add New Member' : 'Edit Member'; ?>
                </h1>
                <a href="?action=list" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-info-circle"></i> Member Information
                    </h3>
                    <span style="color: var(--text-secondary); font-size: 14px;">
                        <i class="fas fa-info-circle"></i> All fields are optional
                    </span>
                </div>
                
                <div class="card-body">
                    <form method="POST">
                        <?php if ($action === 'edit'): ?>
                            <input type="hidden" name="member_id" value="<?php echo $member_id; ?>">
                        <?php endif; ?>
                        
                        <div class="form-grid">
                            <!-- Member Name -->
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-user"></i> Member Name
                                </label>
                                <input type="text" 
                                       name="member_name" 
                                       class="form-control" 
                                       placeholder="Enter member name"
                                       value="<?php echo htmlspecialchars($member_data['MemberName'] ?? ''); ?>">
                            </div>
                            
                            <!-- Father's Name -->
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-user-tie"></i> Father's Name
                                </label>
                                <input type="text" 
                                       name="father_name" 
                                       class="form-control" 
                                       placeholder="Enter father's name"
                                       value="<?php echo htmlspecialchars($member_data['FatherName'] ?? ''); ?>">
                            </div>
                            
                            <!-- Phone -->
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-phone"></i> Phone Number
                                </label>
                                <input type="text" 
                                       name="phone" 
                                       class="form-control" 
                                       placeholder="Enter phone number"
                                       value="<?php echo htmlspecialchars($member_data['Phone'] ?? ''); ?>">
                            </div>
                            
                            <!-- Email -->
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-envelope"></i> Email
                                </label>
                                <input type="email" 
                                       name="email" 
                                       class="form-control" 
                                       placeholder="Enter email address"
                                       value="<?php echo htmlspecialchars($member_data['Email'] ?? ''); ?>">
                            </div>
                            
                            <!-- Aadhar Number -->
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-id-card"></i> Aadhar Number
                                </label>
                                <input type="text" 
                                       name="aadhar" 
                                       class="form-control" 
                                       placeholder="Enter Aadhar number"
                                       value="<?php echo htmlspecialchars($member_data['AadharNumber'] ?? ''); ?>">
                            </div>
                            
                            <!-- Status -->
                            <div class="form-group">
                                <label class="form-label">
                                    <i class="fas fa-toggle-on"></i> Status
                                </label>
                                <select name="status" class="form-control">
                                    <option value="Active" <?php echo ($member_data['Status'] ?? 'Active') === 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo ($member_data['Status'] ?? '') === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Address (Full Width) -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-map-marker-alt"></i> Address
                            </label>
                            <textarea name="address" 
                                      class="form-control" 
                                      rows="3" 
                                      placeholder="Enter complete address"><?php echo htmlspecialchars($member_data['Address'] ?? ''); ?></textarea>
                        </div>
                        
                        <!-- Notes (Full Width) -->
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-sticky-note"></i> Notes
                            </label>
                            <textarea name="notes" 
                                      class="form-control" 
                                      rows="4" 
                                      placeholder="Enter any additional notes or comments"><?php echo htmlspecialchars($member_data['Notes'] ?? ''); ?></textarea>
                        </div>
                        
                        <!-- Submit Buttons -->
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" 
                                    name="<?php echo $action === 'add' ? 'add_member' : 'edit_member'; ?>" 
                                    class="btn btn-primary">
                                <i class="fas fa-save"></i> 
                                <?php echo $action === 'add' ? 'Add Member' : 'Update Member'; ?>
                            </button>
                            <a href="?action=list" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Custom Confirmation Modal -->
    <div class="custom-confirm-modal" id="loginConfirmModal">
        <div class="custom-confirm-box">
            <div class="custom-confirm-header">localhost:3000 says</div>
            <div class="custom-confirm-body" id="loginConfirmMessage"></div>
            <div class="custom-confirm-footer">
                <button class="custom-confirm-btn custom-confirm-btn-ok" id="loginConfirmOk">OK</button>
                <button class="custom-confirm-btn custom-confirm-btn-cancel" id="loginConfirmCancel">Cancel</button>
            </div>
        </div>
    </div>

    <script src="theme-manager.js"></script>
    <script>
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            });
        }, 5000);
        
        // Custom confirm dialog function
        function showConfirm(message) {
            return new Promise((resolve) => {
                const modal = document.getElementById('loginConfirmModal');
                const messageEl = document.getElementById('loginConfirmMessage');
                const okBtn = document.getElementById('loginConfirmOk');
                const cancelBtn = document.getElementById('loginConfirmCancel');
                
                messageEl.textContent = message;
                modal.classList.add('show');
                
                function cleanup() {
                    modal.classList.remove('show');
                    okBtn.removeEventListener('click', handleOk);
                    cancelBtn.removeEventListener('click', handleCancel);
                }
                
                function handleOk() {
                    cleanup();
                    resolve(true);
                }
                
                function handleCancel() {
                    cleanup();
                    resolve(false);
                }
                
                okBtn.addEventListener('click', handleOk);
                cancelBtn.addEventListener('click', handleCancel);
            });
        }
        
        // Handle member login button clicks
        document.addEventListener('DOMContentLoaded', function() {
            const loginButtons = document.querySelectorAll('.member-login-btn');
            
            loginButtons.forEach(button => {
                button.addEventListener('click', async function(e) {
                    e.preventDefault();
                    
                    const memberId = this.getAttribute('data-member-id');
                    const memberName = this.getAttribute('data-member-name');
                    
                    // Show custom styled confirmation dialog
                    const confirmed = await showConfirm('Open member portal for ' + memberName + ' in new tab?');
                    
                    if (confirmed) {
                        // Show loading state
                        const originalHTML = this.innerHTML;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                        this.style.pointerEvents = 'none';
                        
                        // Fetch the login token
                        fetch('direct-member-login.php?id=' + memberId)
                            .then(response => {
                                // Check if response is ok
                                if (!response.ok) {
                                    throw new Error('HTTP error! status: ' + response.status);
                                }
                                // Get the response text first to check if it's valid JSON
                                return response.text();
                            })
                            .then(text => {
                                // Try to parse as JSON
                                try {
                                    const data = JSON.parse(text);
                                    if (data.success) {
                                        // Open in new tab
                                        window.open(data.url, '_blank');
                                    } else {
                                        // Show detailed error message
                                        let errorMsg = 'Error: ' + (data.error || 'Failed to generate login token');
                                        if (data.file && data.line) {
                                            errorMsg += '\n\nFile: ' + data.file + '\nLine: ' + data.line;
                                        }
                                        alert(errorMsg);
                                    }
                                } catch (e) {
                                    // Show the actual response for debugging
                                    console.error('Invalid JSON response:', text);
                                    alert('Error: Invalid response from server.\n\nResponse: ' + text.substring(0, 200));
                                }
                            })
                            .catch(error => {
                                console.error('Fetch error:', error);
                                alert('Error: ' + error.message);
                            })
                            .finally(() => {
                                // Restore button
                                this.innerHTML = originalHTML;
                                this.style.pointerEvents = 'auto';
                            });
                    }
                });
            });
        });
    </script>
    
    <?php include '../includes/modal-component.php'; ?>
</body>
</html>
