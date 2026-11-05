<!-- Quick Navigation Menu -->
<div class="quick-nav" style="margin-bottom: 30px;">
    <div class="quick-nav-container">
        <a href="dashboard.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
        <a href="manage-admins.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage-admins.php' ? 'active' : ''; ?>">
            <i class="fas fa-users-cog"></i>
            <span>Manage Admins</span>
        </a>
        <a href="manage-members.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage-members.php' ? 'active' : ''; ?>">
            <i class="fas fa-users"></i>
            <span>Manage Members</span>
        </a>
        <a href="manage-transactions.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage-transactions.php' ? 'active' : ''; ?>">
            <i class="fas fa-exchange-alt"></i>
            <span>Transactions</span>
        </a>
        <a href="manage-academic-years.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage-academic-years.php' ? 'active' : ''; ?>">
            <i class="fas fa-calendar-alt"></i>
            <span>Academic Years</span>
        </a>
        <a href="reports.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'reports.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-line"></i>
            <span>Reports</span>
        </a>
        <a href="report-builder.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'report-builder.php' ? 'active' : ''; ?>">
            <i class="fas fa-file-signature"></i>
            <span>Report Builder</span>
        </a>
        <a href="transaction-calc.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'transaction-calc.php' ? 'active' : ''; ?>">
            <i class="fas fa-calculator"></i>
            <span>Auto-Calc</span>
        </a>
        <a href="bills.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'bills.php' ? 'active' : ''; ?>">
            <i class="fas fa-file-invoice-dollar"></i>
            <span>Bills</span>
        </a>
        <a href="database-backup.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'database-backup.php' ? 'active' : ''; ?>">
            <i class="fas fa-database"></i>
            <span>Backup</span>
        </a>
        <a href="data-import-export.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'data-import-export.php' ? 'active' : ''; ?>">
            <i class="fas fa-exchange-alt"></i>
            <span>Import/Export</span>
        </a>
        <a href="settings.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'settings.php' ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i>
            <span>Settings</span>
        </a>
        <a href="audit-logs.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'audit-logs.php' ? 'active' : ''; ?>">
            <i class="fas fa-clipboard-list"></i>
            <span>Audit Logs</span>
        </a>
        <a href="dbadmin/dbadmin.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'dbadmin.php' ? 'active' : ''; ?>">
            <i class="fas fa-database"></i>
            <span>DB Admin</span>
        </a>
        <a href="live-monitor.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'live-monitor.php' ? 'active' : ''; ?>">
            <i class="fas fa-heart-pulse"></i>
            <span>Live Monitor</span>
        </a>
        <a href="google-drive-settings.php" class="quick-nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'google-drive-settings.php' ? 'active' : ''; ?>">
            <i class="fab fa-google-drive"></i>
            <span>Google Drive</span>
        </a>
        <a href="accounting/dashboard.php" class="quick-nav-item <?php echo strpos($_SERVER['PHP_SELF'], 'accounting/') !== false ? 'active' : ''; ?>">
            <i class="fas fa-calculator"></i>
            <span>Accounting</span>
        </a>
    </div>
</div>

<style>
.quick-nav {
    background: var(--bg-secondary);
    border-radius: 12px;
    padding: 15px;
    box-shadow: var(--shadow);
}

.quick-nav-container {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
}

.quick-nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 15px 20px;
    background: var(--bg-tertiary);
    border: 2px solid var(--border-color);
    border-radius: 10px;
    text-decoration: none;
    color: var(--text-primary);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-width: 120px;
    text-align: center;
}

.quick-nav-item i {
    font-size: 24px;
    color: var(--text-secondary);
    transition: all 0.3s ease;
}

.quick-nav-item span {
    font-size: 13px;
    font-weight: 600;
}

.quick-nav-item:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-hover);
    border-color: var(--gradient-1);
}

.quick-nav-item:hover i {
    color: var(--gradient-1);
    transform: scale(1.1);
}

.quick-nav-item.active {
    background: var(--gradient-primary);
    color: white;
    border-color: transparent;
}

.quick-nav-item.active i {
    color: white;
}

@media (max-width: 768px) {
    .quick-nav-item {
        min-width: 100px;
        padding: 12px 15px;
    }
    
    .quick-nav-item i {
        font-size: 20px;
    }
    
    .quick-nav-item span {
        font-size: 12px;
    }
}
</style>
