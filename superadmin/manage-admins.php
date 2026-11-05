<?php
require_once 'session.php';
require_once '../config/config.php';

$success = $error = '';
$action = $_GET['action'] ?? 'list';
$admin_id = $_GET['id'] ?? null;

// Handle form submissions (and the ?delete= / ?toggle= links in the admin list)
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['delete']) || isset($_GET['toggle'])) {
    try {
        if (isset($_POST['add_admin'])) {
            $username = trim($_POST['username']);
            $password = $_POST['password'];
            $fullname = trim($_POST['fullname']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            
            // Check if username exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE Username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Username already exists!";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("
                    INSERT INTO admins (Username, Password, FullName, Email, Phone, Status, CreatedAt) 
                    VALUES (?, ?, ?, ?, ?, 'Active', NOW())
                ");
                $stmt->execute([$username, $hashed_password, $fullname, $email, $phone]);
                
                // Log activity
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $stmt = $pdo->prepare("
                    INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress) 
                    VALUES ('SuperAdmin', ?, ?, 'Created Admin', 'admins', ?, ?)
                ");
                $stmt->execute([$superadmin_id, $superadmin_username, $pdo->lastInsertId(), $ip]);
                
                $success = "Admin added successfully!";
                $action = 'list';
            }
        }
        
        if (isset($_POST['edit_admin'])) {
            $id = $_POST['admin_id'];
            $fullname = trim($_POST['fullname']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $status = $_POST['status'];
            
            $stmt = $pdo->prepare("
                UPDATE admins 
                SET FullName = ?, Email = ?, Phone = ?, Status = ? 
                WHERE AdminID = ?
            ");
            $stmt->execute([$fullname, $email, $phone, $status, $id]);
            
            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress) 
                VALUES ('SuperAdmin', ?, ?, 'Updated Admin', 'admins', ?, ?)
            ");
            $stmt->execute([$superadmin_id, $superadmin_username, $id, $ip]);
            
            $success = "Admin updated successfully!";
            $action = 'list';
        }
        
        if (isset($_POST['reset_password'])) {
            $id = $_POST['admin_id'];
            $new_password = $_POST['new_password'];
            
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("UPDATE admins SET Password = ? WHERE AdminID = ?");
            $stmt->execute([$hashed_password, $id]);
            
            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress) 
                VALUES ('SuperAdmin', ?, ?, 'Reset Admin Password', 'admins', ?, ?)
            ");
            $stmt->execute([$superadmin_id, $superadmin_username, $id, $ip]);
            
            $success = "Password reset successfully!";
        }
        
        if (isset($_GET['delete'])) {
            $id = $_GET['delete'];
            
            // Get admin details for logging
            $stmt = $pdo->prepare("SELECT Username FROM admins WHERE AdminID = ?");
            $stmt->execute([$id]);
            $deleted_username = $stmt->fetchColumn();
            
            // Delete admin
            $stmt = $pdo->prepare("DELETE FROM admins WHERE AdminID = ?");
            $stmt->execute([$id]);
            
            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress, OldData) 
                VALUES ('SuperAdmin', ?, ?, 'Deleted Admin', 'admins', ?, ?, ?)
            ");
            $stmt->execute([$superadmin_id, $superadmin_username, $id, $ip, $deleted_username]);
            
            $success = "Admin deleted successfully!";
            $action = 'list';
        }
        
        if (isset($_GET['toggle'])) {
            $id = $_GET['toggle'];
            
            $stmt = $pdo->prepare("SELECT Status FROM admins WHERE AdminID = ?");
            $stmt->execute([$id]);
            $current_status = $stmt->fetchColumn();
            
            $new_status = ($current_status === 'Active') ? 'Inactive' : 'Active';
            
            $stmt = $pdo->prepare("UPDATE admins SET Status = ? WHERE AdminID = ?");
            $stmt->execute([$new_status, $id]);
            
            // Log activity
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserType, UserID, Username, Action, TableName, RecordID, IPAddress) 
                VALUES ('SuperAdmin', ?, ?, ?, 'admins', ?, ?)
            ");
            $stmt->execute([$superadmin_id, $superadmin_username, "Toggled Admin Status to $new_status", $id, $ip]);
            
            $success = "Admin status updated to $new_status!";
            $action = 'list';
        }
        
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Fetch admins for listing
if ($action === 'list') {
    $stmt = $pdo->query("SELECT * FROM admins ORDER BY CreatedAt DESC");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch specific admin for editing
if ($action === 'edit' && $admin_id) {
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE AdminID = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Admins - Super Admin</title>
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
        
        /* Page-specific styles */
        .container {
            max-width: 1400px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal {
            background: var(--bg-secondary);
            border-radius: 12px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            margin-bottom: 20px;
        }
        
        .modal-header h2 {
            color: var(--text-primary);
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 25px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-content">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-shield-alt"></i>
                <span>Super Admin Control Panel</span>
            </a>
            <div class="navbar-user">
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
                <button id="themeToggle" class="theme-toggle">
                    <i id="themeIcon" class="fas fa-moon"></i>
                    <span id="themeText">Dark</span>
                </button>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_username); ?></div>
                    <div class="user-role">Super Administrator</div>
                </div>
                <div class="navbar-datetime" id="adm-navbar-datetime">
                    <span class="datetime-date" id="adm-navbar-date"></span>
                    <span class="datetime-time" id="adm-navbar-time"></span>
                </div>
                <a href="logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
    </nav>
    
    <div class="container">
        <!-- Quick Navigation -->
        
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
        
        <?php if ($action === 'list'): ?>
            <div class="page-header">
                <h1><i class="fas fa-users-cog"></i> Manage Admins</h1>
                <a href="?action=add" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add New Admin
                </a>
            </div>
            
            <div class="card">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Created At</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($admins)): ?>
                                <tr>
                                    <td colspan="9" style="text-align: center; color: #999; padding: 40px;">
                                        No admins found
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($admins as $admin): ?>
                                    <tr>
                                        <td><?php echo $admin['AdminID']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($admin['Username']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($admin['FullName'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($admin['Email'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($admin['Phone'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $admin['Status'] === 'Active' ? 'success' : 'danger'; ?>">
                                                <?php echo $admin['Status']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($admin['CreatedAt'])); ?></td>
                                        <td><?php echo $admin['LastLogin'] ? date('M d, Y H:i', strtotime($admin['LastLogin'])) : 'Never'; ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="#" 
                                                   class="btn btn-success btn-sm admin-login-btn" 
                                                   data-admin-id="<?php echo $admin['AdminID']; ?>"
                                                   data-admin-name="<?php echo htmlspecialchars($admin['FullName'] ?? 'Admin'); ?>"
                                                   title="Login as Admin">
                                                    <i class="fas fa-sign-in-alt"></i>
                                                </a>
                                                <a href="?action=edit&id=<?php echo $admin['AdminID']; ?>" class="btn btn-warning btn-sm" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="?toggle=<?php echo $admin['AdminID']; ?>" class="btn btn-secondary btn-sm confirm-action" 
                                                   data-confirm-title="Toggle Status" data-confirm-message="Toggle admin status?" title="Toggle Status">
                                                    <i class="fas fa-toggle-on"></i>
                                                </a>
                                                <button onclick="showResetPassword(<?php echo $admin['AdminID']; ?>, '<?php echo htmlspecialchars($admin['Username']); ?>')" 
                                                        class="btn btn-primary btn-sm" title="Reset Password">
                                                    <i class="fas fa-key"></i>
                                                </button>
                                                <a href="?delete=<?php echo $admin['AdminID']; ?>" class="btn btn-danger btn-sm confirm-action" 
                                                   data-confirm-title="Delete Admin" data-confirm-message="Are you sure you want to delete this admin?" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        
        <?php elseif ($action === 'add'): ?>
            <div class="page-header">
                <h1><i class="fas fa-user-plus"></i> Add New Admin</h1>
                <a href="?action=list" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
            
            <div class="card">
                <form method="POST">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Username *</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="fullname" class="form-control" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" class="form-control">
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" name="add_admin" class="btn btn-success">
                            <i class="fas fa-save"></i> Add Admin
                        </button>
                        <a href="?action=list" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        
        <?php elseif ($action === 'edit' && isset($admin)): ?>
            <div class="page-header">
                <h1><i class="fas fa-user-edit"></i> Edit Admin</h1>
                <a href="?action=list" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
            
            <div class="card">
                <form method="POST">
                    <input type="hidden" name="admin_id" value="<?php echo $admin['AdminID']; ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Username (Cannot be changed)</label>
                        <input type="text" value="<?php echo htmlspecialchars($admin['Username']); ?>" class="form-control" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="fullname" value="<?php echo htmlspecialchars($admin['FullName'] ?? ''); ?>" class="form-control" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($admin['Email'] ?? ''); ?>" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone" value="<?php echo htmlspecialchars($admin['Phone'] ?? ''); ?>" class="form-control">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status *</label>
                        <select name="status" class="form-control" required>
                            <option value="Active" <?php echo $admin['Status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo $admin['Status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" name="edit_admin" class="btn btn-success">
                            <i class="fas fa-save"></i> Update Admin
                        </button>
                        <a href="?action=list" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Reset Password Modal -->
    <div id="resetPasswordModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2><i class="fas fa-key"></i> Reset Password</h2>
                <p id="resetUsername" style="color: #666; margin-top: 5px;"></p>
            </div>
            
            <form method="POST">
                <input type="hidden" name="admin_id" id="resetAdminId">
                
                <div class="form-group">
                    <label class="form-label">New Password *</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6">
                </div>
                
                <div class="modal-footer">
                    <button type="submit" name="reset_password" class="btn btn-primary">
                        <i class="fas fa-check"></i> Reset Password
                    </button>
                    <button type="button" onclick="hideResetPassword()" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function showResetPassword(adminId, username) {
            document.getElementById('resetAdminId').value = adminId;
            document.getElementById('resetUsername').textContent = 'Username: ' + username;
            document.getElementById('resetPasswordModal').classList.add('active');
        }
        
        function hideResetPassword() {
            document.getElementById('resetPasswordModal').classList.remove('active');
        }
        
        // Close modal when clicking overlay
        document.getElementById('resetPasswordModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideResetPassword();
            }
        });
        
        // Custom confirm dialog function
        function showConfirmAdmin(message) {
            return new Promise((resolve) => {
                const modal = document.getElementById('adminLoginConfirmModal');
                const messageEl = document.getElementById('adminLoginConfirmMessage');
                const okBtn = document.getElementById('adminLoginConfirmOk');
                const cancelBtn = document.getElementById('adminLoginConfirmCancel');
                
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
        
        // Handle admin login button clicks
        document.addEventListener('DOMContentLoaded', function() {
            const loginButtons = document.querySelectorAll('.admin-login-btn');
            
            loginButtons.forEach(button => {
                button.addEventListener('click', async function(e) {
                    e.preventDefault();
                    
                    const adminId = this.getAttribute('data-admin-id');
                    const adminName = this.getAttribute('data-admin-name');
                    
                    // Show custom styled confirmation dialog
                    const confirmed = await showConfirmAdmin('Open admin portal for ' + adminName + ' in new tab?');
                    
                    if (confirmed) {
                        // Show loading state
                        const originalHTML = this.innerHTML;
                        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                        this.style.pointerEvents = 'none';
                        
                        // Fetch the login token
                        fetch('direct-admin-login.php?id=' + adminId)
                            .then(response => {
                                if (!response.ok) {
                                    throw new Error('HTTP error! status: ' + response.status);
                                }
                                return response.text();
                            })
                            .then(text => {
                                try {
                                    const data = JSON.parse(text);
                                    if (data.success) {
                                        window.open(data.url, '_blank');
                                    } else {
                                        let errorMsg = 'Error: ' + (data.error || 'Failed to generate login token');
                                        if (data.file && data.line) {
                                            errorMsg += '\n\nFile: ' + data.file + '\nLine: ' + data.line;
                                        }
                                        alert(errorMsg);
                                    }
                                } catch (e) {
                                    console.error('Invalid JSON response:', text);
                                    alert('Error: Invalid response from server.\n\nResponse: ' + text.substring(0, 200));
                                }
                            })
                            .catch(error => {
                                console.error('Fetch error:', error);
                                alert('Error: ' + error.message);
                            })
                            .finally(() => {
                                this.innerHTML = originalHTML;
                                this.style.pointerEvents = 'auto';
                            });
                    }
                });
            });
        });
    </script>

    <!-- Custom Confirmation Modal for Admin Login -->
    <div class="custom-confirm-modal" id="adminLoginConfirmModal">
        <div class="custom-confirm-box">
            <div class="custom-confirm-header">localhost:3000 says</div>
            <div class="custom-confirm-body" id="adminLoginConfirmMessage"></div>
            <div class="custom-confirm-footer">
                <button class="custom-confirm-btn custom-confirm-btn-ok" id="adminLoginConfirmOk">OK</button>
                <button class="custom-confirm-btn custom-confirm-btn-cancel" id="adminLoginConfirmCancel">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Theme Manager -->
    <script src="theme-manager.js"></script>
    <script>
    (function(){
        function updateDT(){
            var d=document.getElementById('adm-navbar-date'),t=document.getElementById('adm-navbar-time');
            if(!d||!t)return;
            var n=new Date(),days=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
            d.textContent=days[n.getDay()]+', '+n.toLocaleDateString(undefined,{year:'numeric',month:'short',day:'2-digit'});
            t.textContent=n.toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit',second:'2-digit'});
        }
        updateDT();setInterval(updateDT,1000);
    })();
    </script>
    <?php include '../includes/modal-component.php'; ?>
<?php include '../includes/gemini-widget.php'; ?>
</body>
</html>