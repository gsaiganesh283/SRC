<?php
// Include logo helper if not already loaded
$systemLogoHtml = '';
if (function_exists('renderSystemLogo')) {
    $systemLogoHtml = renderSystemLogo('', '', 35);
} elseif (isset($pdo)) {
    // Try to load logo helper if pdo is available
    $logoHelperPath = dirname(__DIR__, 2) . '/includes/logo-helper.php';
    if (file_exists($logoHelperPath)) {
        require_once $logoHelperPath;
        $systemLogoHtml = renderSystemLogo('', '', 35);
    }
}
?>
<!-- Super Admin Navbar Component -->
<nav class="navbar">
    <div class="navbar-content">
        <a href="dashboard.php" class="navbar-brand">
            <?php if (!empty($systemLogoHtml)): ?>
                <?php echo $systemLogoHtml; ?>
            <?php else: ?>
                <i class="fas fa-crown"></i>
            <?php endif; ?>
            <span>Super Admin</span>
            <?php if (isset($page_title)): ?>
                <span style="margin-left: 15px; opacity: 0.7; font-size: 18px;">| <?php echo htmlspecialchars($page_title); ?></span>
            <?php endif; ?>
        </a>
        <div class="navbar-user">
            <?php if (basename($_SERVER['PHP_SELF']) !== 'dashboard.php'): ?>
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
            <?php endif; ?>
            <button class="theme-toggle" onclick="window.ThemeManager.toggleTheme()">
                <i class="fas fa-moon"></i>
                <span id="themeText">Dark</span>
            </button>
            <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($superadmin_username ?? 'Super Admin'); ?></div>
                <div class="user-role">Super Administrator</div>
            </div>
            <div class="navbar-datetime" id="navbar-datetime">
                <span class="datetime-date" id="navbar-date"></span>
                <span class="datetime-time" id="navbar-time"></span>
            </div>
        </div>
    </div>
</nav>
<script>
function updateNavbarDateTime() {
    const dateEl = document.getElementById('navbar-date');
    const timeEl = document.getElementById('navbar-time');
    if (!dateEl || !timeEl) return;
    const now = new Date();
    const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const day = days[now.getDay()];
    const date = now.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
    const time = now.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    dateEl.textContent = `${day}, ${date}`;
    timeEl.textContent = time;
}
setInterval(updateNavbarDateTime, 1000);
updateNavbarDateTime();
</script>
<?php include dirname(__DIR__, 2) . '/includes/gemini-widget.php'; ?>