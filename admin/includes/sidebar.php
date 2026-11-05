<?php
/**
 * Admin Sidebar Navigation Component
 * Include this file in all admin pages
 */

// Include logo helper if available
$systemLogoHtml = '';
$siteName = 'SR Colony Chitti';
if (function_exists('renderSystemLogo')) {
    $systemLogoHtml = renderSystemLogo('', '', 40);
    $siteName = getSiteName('SR Colony Chitti');
} elseif (isset($pdo)) {
    $logoHelperPath = dirname(__DIR__, 2) . '/includes/logo-helper.php';
    if (file_exists($logoHelperPath)) {
        require_once $logoHelperPath;
        $systemLogoHtml = renderSystemLogo('', '', 40);
        $siteName = getSiteName('SR Colony Chitti');
    }
}

// Get current page name
$currentPage = basename($_SERVER['PHP_SELF']);

// Get unread notifications count
$unreadNotifications = 0;
try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'system_notifications'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM system_notifications WHERE IsRead = 0");
        $unreadNotifications = $stmt->fetchColumn();
    }
} catch (PDOException $e) {
    // Ignore error if table doesn't exist
}

// Get admin data if not already loaded
if (!isset($admin)) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE AdminID = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $admin = ['FullName' => 'Admin'];
    }
}
?>

<style>
    /* Sidebar Styles */
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: 280px;
        height: 100vh;
        background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%);
        color: white;
        overflow-y: auto;
        z-index: 1000;
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    }
    
    .sidebar-header {
        padding: 30px 20px;
        background: rgba(0,0,0,0.2);
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    
    .sidebar-header h2 {
        margin: 0 0 5px 0;
        font-size: 20px;
        font-weight: 600;
        color: white;
    }
    
    .sidebar-header .admin-role {
        margin: 0;
        font-size: 13px;
        color: rgba(255,255,255,0.7);
    }
    
    .sidebar-menu {
        padding: 20px 0;
    }
    
    .menu-item {
        display: flex;
        align-items: center;
        padding: 15px 25px;
        color: rgba(255,255,255,0.8);
        text-decoration: none;
        transition: all 0.3s ease;
        border-left: 3px solid transparent;
        position: relative;
        cursor: pointer;
    }
    
    .menu-item:hover {
        background: rgba(255,255,255,0.1);
        color: white !important;
        border-left-color: #3498db;
        text-decoration: none;
    }
    
    .menu-item.active {
        background: rgba(52, 152, 219, 0.2);
        color: white !important;
        border-left-color: #3498db;
    }
    
    .menu-item:visited {
        color: rgba(255,255,255,0.8);
    }
    
    .menu-item:active {
        background: rgba(52, 152, 219, 0.3);
    }
    
    .menu-item i {
        width: 24px;
        margin-right: 15px;
        font-size: 18px;
    }
    
    .menu-item span {
        flex: 1;
        font-size: 15px;
        font-weight: 500;
    }
    
    .menu-badge {
        background: #e74c3c;
        color: white;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        margin-left: 10px;
    }
    
    /* Main content area offset */
    .main-content {
        margin-left: 280px;
        min-height: 100vh;
        background: #f8f9fa;
    }
    
    .dashboard-container {
        margin-left: 280px;
        padding: 30px;
        min-height: 100vh;
        background: #f8f9fa;
    }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .main-content,
        .dashboard-container {
            margin-left: 0;
        }
    }
</style>

<!-- Sidebar -->
<aside class="sidebar" id="adminSidebar">
    <div class="sidebar-header">
        <?php if (!empty($systemLogoHtml)): ?>
            <div style="margin-bottom: 10px;"><?php echo $systemLogoHtml; ?></div>
        <?php endif; ?>
        <h2><?php echo htmlspecialchars($admin['FullName'] ?? 'Admin'); ?></h2>
        <p class="admin-role">Administrator</p>
    </div>
    <nav class="sidebar-menu">
        <a href="dashboard.php" class="menu-item <?php echo ($currentPage == 'dashboard-new.php' || $currentPage == 'dashboard.php') ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
        <a href="manage-members.php" class="menu-item <?php echo $currentPage == 'manage-members.php' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            <span>Manage Members</span>
        </a>
        <a href="manage-transactions.php" class="menu-item <?php echo $currentPage == 'manage-transactions.php' ? 'active' : ''; ?>">
            <i class="fas fa-exchange-alt"></i>
            <span>Transactions</span>
        </a>
        <a href="manage-years.php" class="menu-item <?php echo ($currentPage == 'manage-years.php' || $currentPage == 'manage-academic-years.php') ? 'active' : ''; ?>">
            <i class="fas fa-calendar-alt"></i>
            <span>Academic Years</span>
        </a>
        <a href="reports.php" class="menu-item <?php echo $currentPage == 'reports.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-line"></i>
            <span>Reports</span>
        </a>
        <a href="notifications.php" class="menu-item <?php echo $currentPage == 'notifications.php' ? 'active' : ''; ?>">
            <i class="fas fa-bell"></i>
            <span>Notifications</span>
            <?php if ($unreadNotifications > 0): ?>
                <span class="menu-badge"><?php echo $unreadNotifications; ?></span>
            <?php endif; ?>
        </a>
        <a href="profile.php" class="menu-item <?php echo $currentPage == 'profile.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-circle"></i>
            <span>My Profile</span>
        </a>
        <a href="logout.php" class="menu-item">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </nav>
</aside>

<script>
// Ensure sidebar links are clickable
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('adminSidebar');
    if (sidebar) {
        sidebar.style.pointerEvents = 'auto';
        const links = sidebar.querySelectorAll('a.menu-item');
        links.forEach(link => {
            link.style.pointerEvents = 'auto';
            link.addEventListener('click', function(e) {
                console.log('Link clicked:', this.getAttribute('href'));
            });
        });
    }
});
</script>
