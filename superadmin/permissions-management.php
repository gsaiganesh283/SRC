<?php
require_once './session.php';
require_once '../config/config.php';
require_once '../includes/functions.php';

// Require super admin authentication
if (!isset($_SESSION['superadmin_id'])) {
    header('Location: login.php');
    exit();
}

$success = '';
$error = '';

// Handle Admin Permission Update
if (isset($_POST['action']) && $_POST['action'] === 'update_admin_permissions') {
    $adminID = intval($_POST['admin_id']);
    $permissions = [];
    
    // Collect all permission checkboxes
    $permissionFields = [
        'can_view_members', 'can_add_members', 'can_edit_members', 'can_delete_members', 'can_verify_members',
        'can_view_transactions', 'can_add_transactions', 'can_edit_transactions', 'can_delete_transactions', 'can_verify_transactions', 'can_export_transactions',
        'can_view_reports', 'can_generate_reports', 'can_export_reports', 'can_view_analytics',
        'can_view_years', 'can_add_years', 'can_edit_years', 'can_delete_years', 'can_activate_years',
        'can_send_notifications', 'can_send_bulk_sms', 'can_send_bulk_email',
        'can_access_dashboard', 'can_view_audit_logs', 'can_backup_data', 'can_restore_data'
    ];
    
    foreach ($permissionFields as $field) {
        $permissions[$field] = isset($_POST[$field]) ? 1 : 0;
    }
    
    try {
        // Check if permissions exist
        $stmt = $pdo->prepare("SELECT PermissionID FROM admin_permissions WHERE AdminID = ?");
        $stmt->execute([$adminID]);
        
        if ($stmt->rowCount() > 0) {
            // Update existing permissions
            $updateFields = array_map(function($field) { return "$field = ?"; }, $permissionFields);
            $sql = "UPDATE admin_permissions SET " . implode(', ', $updateFields) . ", UpdatedBy = ? WHERE AdminID = ?";
            
            $values = array_values($permissions);
            $values[] = $_SESSION['superadmin_id'];
            $values[] = $adminID;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        } else {
            // Insert new permissions
            $fields = implode(', ', $permissionFields);
            $placeholders = implode(', ', array_fill(0, count($permissionFields), '?'));
            $sql = "INSERT INTO admin_permissions (AdminID, $fields, UpdatedBy) VALUES (?, $placeholders, ?)";
            
            $values = [$adminID];
            $values = array_merge($values, array_values($permissions));
            $values[] = $_SESSION['superadmin_id'];
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        }
        
        $success = 'Admin permissions updated successfully!';
    } catch (Exception $e) {
        $error = 'Error updating permissions: ' . $e->getMessage();
    }
}

// Handle Member Permission Update
if (isset($_POST['action']) && $_POST['action'] === 'update_member_permissions') {
    $memberID = intval($_POST['member_id']);
    $permissions = [];
    
    $permissionFields = [
        'can_view_profile', 'can_edit_profile', 'can_change_password', 'can_upload_documents',
        'can_view_transactions', 'can_view_payment_history', 'can_view_distribution_history', 'can_view_interest_details',
        'can_download_statements', 'can_export_data', 'can_print_statements',
        'can_view_dashboard', 'can_view_analytics', 'can_view_charts',
        'can_receive_notifications', 'can_receive_sms', 'can_receive_email',
        'can_view_outstanding_balance', 'can_view_total_contributions', 'can_view_total_distributions',
        'account_active', 'login_enabled'
    ];
    
    foreach ($permissionFields as $field) {
        $permissions[$field] = isset($_POST[$field]) ? 1 : 0;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT PermissionID FROM member_permissions WHERE MemberID = ?");
        $stmt->execute([$memberID]);
        
        if ($stmt->rowCount() > 0) {
            $updateFields = array_map(function($field) { return "$field = ?"; }, $permissionFields);
            $sql = "UPDATE member_permissions SET " . implode(', ', $updateFields) . ", UpdatedBy = ? WHERE MemberID = ?";
            
            $values = array_values($permissions);
            $values[] = $_SESSION['superadmin_id'];
            $values[] = $memberID;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        } else {
            $fields = implode(', ', $permissionFields);
            $placeholders = implode(', ', array_fill(0, count($permissionFields), '?'));
            $sql = "INSERT INTO member_permissions (MemberID, $fields, UpdatedBy) VALUES (?, $placeholders, ?)";
            
            $values = [$memberID];
            $values = array_merge($values, array_values($permissions));
            $values[] = $_SESSION['superadmin_id'];
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        }
        
        $success = 'Member permissions updated successfully!';
    } catch (Exception $e) {
        $error = 'Error updating permissions: ' . $e->getMessage();
    }
}

// Handle Apply Template
if (isset($_POST['action']) && $_POST['action'] === 'apply_template') {
    $templateID = intval($_POST['template_id']);
    $targetType = $_POST['target_type'];
    $targetIDs = $_POST['target_ids'] ?? [];
    
    try {
        $stmt = $pdo->prepare("SELECT PermissionJSON FROM permission_templates WHERE TemplateID = ?");
        $stmt->execute([$templateID]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($template) {
            $permissions = json_decode($template['PermissionJSON'], true);
            
            foreach ($targetIDs as $id) {
                $id = intval($id);
                
                if ($targetType === 'Admin') {
                    $permissionFields = array_keys($permissions);
                    $updateFields = array_map(function($field) { return "$field = ?"; }, $permissionFields);
                    
                    $stmt = $pdo->prepare("SELECT PermissionID FROM admin_permissions WHERE AdminID = ?");
                    $stmt->execute([$id]);
                    
                    if ($stmt->rowCount() > 0) {
                        $sql = "UPDATE admin_permissions SET " . implode(', ', $updateFields) . ", UpdatedBy = ? WHERE AdminID = ?";
                        $values = array_values($permissions);
                        $values[] = $_SESSION['superadmin_id'];
                        $values[] = $id;
                        
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($values);
                    } else {
                        $fields = implode(', ', $permissionFields);
                        $placeholders = implode(', ', array_fill(0, count($permissionFields), '?'));
                        $sql = "INSERT INTO admin_permissions (AdminID, $fields, UpdatedBy) VALUES (?, $placeholders, ?)";
                        
                        $values = [$id];
                        $values = array_merge($values, array_values($permissions));
                        $values[] = $_SESSION['superadmin_id'];
                        
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($values);
                    }
                } else {
                    // Similar for members
                    $permissionFields = array_keys($permissions);
                    $updateFields = array_map(function($field) { return "$field = ?"; }, $permissionFields);
                    
                    $stmt = $pdo->prepare("SELECT PermissionID FROM member_permissions WHERE MemberID = ?");
                    $stmt->execute([$id]);
                    
                    if ($stmt->rowCount() > 0) {
                        $sql = "UPDATE member_permissions SET " . implode(', ', $updateFields) . ", UpdatedBy = ? WHERE MemberID = ?";
                        $values = array_values($permissions);
                        $values[] = $_SESSION['superadmin_id'];
                        $values[] = $id;
                        
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($values);
                    } else {
                        $fields = implode(', ', $permissionFields);
                        $placeholders = implode(', ', array_fill(0, count($permissionFields), '?'));
                        $sql = "INSERT INTO member_permissions (MemberID, $fields, UpdatedBy) VALUES (?, $placeholders, ?)";
                        
                        $values = [$id];
                        $values = array_merge($values, array_values($permissions));
                        $values[] = $_SESSION['superadmin_id'];
                        
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($values);
                    }
                }
            }
            
            $success = 'Template applied to ' . count($targetIDs) . ' ' . strtolower($targetType) . '(s) successfully!';
        }
    } catch (Exception $e) {
        $error = 'Error applying template: ' . $e->getMessage();
    }
}

// Fetch all admins with their permissions
try {
    $stmt = $pdo->query("
        SELECT a.*, ap.* 
        FROM admins a
        LEFT JOIN admin_permissions ap ON a.AdminID = ap.AdminID
        ORDER BY a.Username ASC
    ");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $admins = [];
    $error = 'Error fetching admins: ' . $e->getMessage();
}

// Fetch all members with their permissions
try {
    $stmt = $pdo->query("
        SELECT m.*, mp.* 
        FROM members m
        LEFT JOIN member_permissions mp ON m.MemberID = mp.MemberID
        ORDER BY m.MemberName ASC
    ");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $members = [];
}

// Fetch permission templates
try {
    $stmt = $pdo->query("SELECT * FROM permission_templates ORDER BY TemplateType, TemplateName");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $templates = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permissions Management - Super Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 { font-size: 32px; display: flex; align-items: center; gap: 15px; }
        .header p { opacity: 0.9; margin-top: 10px; }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; transform: translateY(-2px); }
        
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        
        .btn-warning { background: #f59e0b; color: white; }
        .btn-warning:hover { background: #d97706; }
        
        .tabs {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .tab-buttons {
            display: flex;
            gap: 10px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .tab-btn {
            padding: 12px 24px;
            border: none;
            background: #f1f5f9;
            color: #64748b;
            border-radius: 8px 8px 0 0;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .tab-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .card h3 {
            color: #1e293b;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .permission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .permission-item {
            display: flex;
            align-items: center;
            padding: 12px;
            background: #f8fafc;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .permission-item:hover {
            background: #eef2ff;
            transform: translateX(5px);
        }
        
        .permission-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            cursor: pointer;
        }
        
        .permission-item label {
            cursor: pointer;
            font-size: 14px;
            color: #475569;
            flex: 1;
        }
        
        .user-card {
            background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            border-left: 4px solid #667eea;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .user-card:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .user-card h4 {
            color: #1e293b;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-card p {
            color: #64748b;
            font-size: 14px;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active { display: flex; }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body { padding: 30px; }
        
        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            padding: 0;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
        }
        
        .close-modal:hover { background: rgba(255,255,255,0.2); }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
        }
        
        .stat-box .number {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .stat-box .label {
            font-size: 14px;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1><i class="fas fa-shield-alt"></i> Permissions Management</h1>
                <p>Control admin and member access rights</p>
            </div>
            <a href="dashboard.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="number"><?php echo count($admins); ?></div>
                <div class="label">Total Admins</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo count($members); ?></div>
                <div class="label">Total Members</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo count($templates); ?></div>
                <div class="label">Permission Templates</div>
            </div>
        </div>

        <div class="tabs">
            <div class="tab-buttons">
                <button class="tab-btn active" onclick="switchTab('admin')">
                    <i class="fas fa-user-shield"></i> Admin Permissions
                </button>
                <button class="tab-btn" onclick="switchTab('member')">
                    <i class="fas fa-users"></i> Member Permissions
                </button>
                <button class="tab-btn" onclick="switchTab('templates')">
                    <i class="fas fa-clipboard-list"></i> Templates
                </button>
            </div>

            <!-- Admin Permissions Tab -->
            <div id="admin-tab" class="tab-content active">
                <h3><i class="fas fa-user-shield"></i> Manage Admin Permissions</h3>
                
                <?php foreach ($admins as $admin): ?>
                <div class="user-card" onclick="openAdminModal(<?php echo htmlspecialchars(json_encode($admin)); ?>)">
                    <h4>
                        <i class="fas fa-user-tie"></i>
                        <?php echo htmlspecialchars($admin['Username']); ?>
                        <span class="badge badge-success">Admin</span>
                    </h4>
                    <p>Click to manage permissions</p>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Member Permissions Tab -->
            <div id="member-tab" class="tab-content">
                <h3><i class="fas fa-users"></i> Manage Member Permissions</h3>
                
                <?php foreach ($members as $member): ?>
                <div class="user-card" onclick="openMemberModal(<?php echo htmlspecialchars(json_encode($member)); ?>)">
                    <h4>
                        <i class="fas fa-user"></i>
                        <strong><?php echo htmlspecialchars($member['MemberName']); ?></strong> <span style="color: #6b7280;">(ID: <?php echo $member['MemberID']; ?>)</span>
                        <?php if (isset($member['login_enabled']) && $member['login_enabled'] == 1): ?>
                        <span class="badge badge-success">Active</span>
                        <?php else: ?>
                        <span class="badge badge-danger">Disabled</span>
                        <?php endif; ?>
                    </h4>
                    <p>Phone: <?php echo htmlspecialchars($member['Phone'] ?? 'N/A'); ?> | Click to manage permissions</p>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Templates Tab -->
            <div id="templates-tab" class="tab-content">
                <h3><i class="fas fa-clipboard-list"></i> Permission Templates</h3>
                <p style="color: #64748b; margin-bottom: 20px;">Quick apply pre-defined permission sets to users</p>
                
                <?php foreach ($templates as $template): ?>
                <div class="card">
                    <h4 style="color: #1e293b; margin-bottom: 10px;">
                        <i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($template['TemplateName']); ?>
                        <span class="badge <?php echo $template['TemplateType'] === 'Admin' ? 'badge-warning' : 'badge-success'; ?>">
                            <?php echo $template['TemplateType']; ?>
                        </span>
                    </h4>
                    <p style="color: #64748b; margin-bottom: 15px;"><?php echo htmlspecialchars($template['Description']); ?></p>
                    <button class="btn btn-primary" onclick="applyTemplate(<?php echo $template['TemplateID']; ?>, '<?php echo $template['TemplateType']; ?>')">
                        <i class="fas fa-magic"></i> Apply Template
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Admin Permission Modal -->
    <div id="adminModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user-shield"></i> Edit Admin Permissions</h2>
                <button class="close-modal" onclick="closeAdminModal()">&times;</button>
            </div>
            <form method="POST" id="adminPermissionForm">
                <input type="hidden" name="action" value="update_admin_permissions">
                <input type="hidden" name="admin_id" id="admin_id">
                
                <div class="modal-body">
                    <h3 id="admin_name" style="color: #1e293b; margin-bottom: 20px;"></h3>
                    
                    <div class="card">
                        <h3><i class="fas fa-users"></i> Member Management</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_members" id="admin_can_view_members">
                                <label for="admin_can_view_members">View Members</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_add_members" id="admin_can_add_members">
                                <label for="admin_can_add_members">Add Members</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_edit_members" id="admin_can_edit_members">
                                <label for="admin_can_edit_members">Edit Members</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_delete_members" id="admin_can_delete_members">
                                <label for="admin_can_delete_members">Delete Members</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_verify_members" id="admin_can_verify_members">
                                <label for="admin_can_verify_members">Verify Members</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-exchange-alt"></i> Transaction Management</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_transactions" id="admin_can_view_transactions">
                                <label for="admin_can_view_transactions">View Transactions</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_add_transactions" id="admin_can_add_transactions">
                                <label for="admin_can_add_transactions">Add Transactions</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_edit_transactions" id="admin_can_edit_transactions">
                                <label for="admin_can_edit_transactions">Edit Transactions</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_delete_transactions" id="admin_can_delete_transactions">
                                <label for="admin_can_delete_transactions">Delete Transactions</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_verify_transactions" id="admin_can_verify_transactions">
                                <label for="admin_can_verify_transactions">Verify Transactions</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_export_transactions" id="admin_can_export_transactions">
                                <label for="admin_can_export_transactions">Export Transactions</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-chart-bar"></i> Reports & Analytics</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_reports" id="admin_can_view_reports">
                                <label for="admin_can_view_reports">View Reports</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_generate_reports" id="admin_can_generate_reports">
                                <label for="admin_can_generate_reports">Generate Reports</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_export_reports" id="admin_can_export_reports">
                                <label for="admin_can_export_reports">Export Reports</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_analytics" id="admin_can_view_analytics">
                                <label for="admin_can_view_analytics">View Analytics</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-calendar-alt"></i> Year Management</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_years" id="admin_can_view_years">
                                <label for="admin_can_view_years">View Years</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_add_years" id="admin_can_add_years">
                                <label for="admin_can_add_years">Add Years</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_edit_years" id="admin_can_edit_years">
                                <label for="admin_can_edit_years">Edit Years</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_delete_years" id="admin_can_delete_years">
                                <label for="admin_can_delete_years">Delete Years</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_activate_years" id="admin_can_activate_years">
                                <label for="admin_can_activate_years">Activate Years</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-bell"></i> Communication</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_send_notifications" id="admin_can_send_notifications">
                                <label for="admin_can_send_notifications">Send Notifications</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_send_bulk_sms" id="admin_can_send_bulk_sms">
                                <label for="admin_can_send_bulk_sms">Send Bulk SMS</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_send_bulk_email" id="admin_can_send_bulk_email">
                                <label for="admin_can_send_bulk_email">Send Bulk Email</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-cogs"></i> System Access</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_access_dashboard" id="admin_can_access_dashboard">
                                <label for="admin_can_access_dashboard">Access Dashboard</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_audit_logs" id="admin_can_view_audit_logs">
                                <label for="admin_can_view_audit_logs">View Audit Logs</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_backup_data" id="admin_can_backup_data">
                                <label for="admin_can_backup_data">Backup Data</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_restore_data" id="admin_can_restore_data">
                                <label for="admin_can_restore_data">Restore Data</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeAdminModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Permissions
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Member Permission Modal -->
    <div id="memberModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-user"></i> Edit Member Permissions</h2>
                <button class="close-modal" onclick="closeMemberModal()">&times;</button>
            </div>
            <form method="POST" id="memberPermissionForm">
                <input type="hidden" name="action" value="update_member_permissions">
                <input type="hidden" name="member_id" id="member_id">
                
                <div class="modal-body">
                    <h3 id="member_name" style="color: #1e293b; margin-bottom: 20px;"></h3>
                    
                    <div class="card">
                        <h3><i class="fas fa-user-circle"></i> Profile Management</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_profile" id="member_can_view_profile">
                                <label for="member_can_view_profile">View Profile</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_edit_profile" id="member_can_edit_profile">
                                <label for="member_can_edit_profile">Edit Profile</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_change_password" id="member_can_change_password">
                                <label for="member_can_change_password">Change Password</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_upload_documents" id="member_can_upload_documents">
                                <label for="member_can_upload_documents">Upload Documents</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-history"></i> Transaction Viewing</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_transactions" id="member_can_view_transactions">
                                <label for="member_can_view_transactions">View Transactions</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_payment_history" id="member_can_view_payment_history">
                                <label for="member_can_view_payment_history">View Payment History</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_distribution_history" id="member_can_view_distribution_history">
                                <label for="member_can_view_distribution_history">View Distribution History</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_interest_details" id="member_can_view_interest_details">
                                <label for="member_can_view_interest_details">View Interest Details</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-file-download"></i> Statements & Export</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_download_statements" id="member_can_download_statements">
                                <label for="member_can_download_statements">Download Statements</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_export_data" id="member_can_export_data">
                                <label for="member_can_export_data">Export Data</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_print_statements" id="member_can_print_statements">
                                <label for="member_can_print_statements">Print Statements</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-tachometer-alt"></i> Dashboard & Analytics</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_dashboard" id="member_can_view_dashboard">
                                <label for="member_can_view_dashboard">View Dashboard</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_analytics" id="member_can_view_analytics">
                                <label for="member_can_view_analytics">View Analytics</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_charts" id="member_can_view_charts">
                                <label for="member_can_view_charts">View Charts</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-bell"></i> Notifications</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_receive_notifications" id="member_can_receive_notifications">
                                <label for="member_can_receive_notifications">Receive Notifications</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_receive_sms" id="member_can_receive_sms">
                                <label for="member_can_receive_sms">Receive SMS</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_receive_email" id="member_can_receive_email">
                                <label for="member_can_receive_email">Receive Email</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-dollar-sign"></i> Financial Information</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_outstanding_balance" id="member_can_view_outstanding_balance">
                                <label for="member_can_view_outstanding_balance">View Outstanding Balance</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_total_contributions" id="member_can_view_total_contributions">
                                <label for="member_can_view_total_contributions">View Total Contributions</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="can_view_total_distributions" id="member_can_view_total_distributions">
                                <label for="member_can_view_total_distributions">View Total Distributions</label>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <h3><i class="fas fa-lock"></i> Account Access</h3>
                        <div class="permission-grid">
                            <div class="permission-item">
                                <input type="checkbox" name="account_active" id="member_account_active">
                                <label for="member_account_active">Account Active</label>
                            </div>
                            <div class="permission-item">
                                <input type="checkbox" name="login_enabled" id="member_login_enabled">
                                <label for="member_login_enabled">Login Enabled</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning" onclick="closeMemberModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Permissions
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tab) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tab + '-tab').classList.add('active');
            event.target.classList.add('active');
        }
        
        function openAdminModal(admin) {
            document.getElementById('admin_id').value = admin.AdminID;
            document.getElementById('admin_name').textContent = 'Permissions for: ' + admin.Username;
            
            // Set all checkboxes
            const permissions = [
                'can_view_members', 'can_add_members', 'can_edit_members', 'can_delete_members', 'can_verify_members',
                'can_view_transactions', 'can_add_transactions', 'can_edit_transactions', 'can_delete_transactions', 'can_verify_transactions', 'can_export_transactions',
                'can_view_reports', 'can_generate_reports', 'can_export_reports', 'can_view_analytics',
                'can_view_years', 'can_add_years', 'can_edit_years', 'can_delete_years', 'can_activate_years',
                'can_send_notifications', 'can_send_bulk_sms', 'can_send_bulk_email',
                'can_access_dashboard', 'can_view_audit_logs', 'can_backup_data', 'can_restore_data'
            ];
            
            permissions.forEach(perm => {
                const checkbox = document.getElementById('admin_' + perm);
                if (checkbox) {
                    checkbox.checked = admin[perm] == 1;
                }
            });
            
            document.getElementById('adminModal').classList.add('active');
        }
        
        function closeAdminModal() {
            document.getElementById('adminModal').classList.remove('active');
        }
        
        function openMemberModal(member) {
            document.getElementById('member_id').value = member.MemberID;
            document.getElementById('member_name').textContent = 'Permissions for: ' + member.MemberName;
            
            const permissions = [
                'can_view_profile', 'can_edit_profile', 'can_change_password', 'can_upload_documents',
                'can_view_transactions', 'can_view_payment_history', 'can_view_distribution_history', 'can_view_interest_details',
                'can_download_statements', 'can_export_data', 'can_print_statements',
                'can_view_dashboard', 'can_view_analytics', 'can_view_charts',
                'can_receive_notifications', 'can_receive_sms', 'can_receive_email',
                'can_view_outstanding_balance', 'can_view_total_contributions', 'can_view_total_distributions',
                'account_active', 'login_enabled'
            ];
            
            permissions.forEach(perm => {
                const checkbox = document.getElementById('member_' + perm);
                if (checkbox) {
                    checkbox.checked = member[perm] == 1;
                }
            });
            
            document.getElementById('memberModal').classList.add('active');
        }
        
        function closeMemberModal() {
            document.getElementById('memberModal').classList.remove('active');
        }
        
        function applyTemplate(templateID, targetType) {
            // This would open a modal to select which users to apply the template to
            alert('Template application feature: Select ' + targetType + 's to apply this template to.');
            // TODO: Implement template application with user selection
        }
    </script>
<?php include '../includes/gemini-widget.php'; ?>
</body>
</html>