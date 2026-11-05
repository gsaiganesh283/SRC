<?php
/**
 * Google Drive Management - Advanced Settings & Folder Browser
 * Desktop App OAuth (Client ID + Secret only, no manual refresh token)
 */
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/logo-helper.php';
require_once '../includes/google-drive-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Fetch all accounts
$accounts = [];
try {
    $stmt = $pdo->query("SELECT * FROM google_drive_settings ORDER BY IsDefault DESC, SettingID ASC");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Stats
$totalAccounts = count($accounts);
$connectedAccounts = 0;
foreach ($accounts as $a) {
    if ($a['IsActive'] && !empty($a['RefreshToken'])) $connectedAccounts++;
}

$onGDrive = 0;
$localOnly = 0;
try {
    $onGDrive = (int)$pdo->query("SELECT COUNT(*) FROM proof_attachments WHERE GoogleDriveFileID IS NOT NULL AND GoogleDriveFileID != '' AND UploadStatus='uploaded'")->fetchColumn();
    $localOnly = (int)$pdo->query("SELECT COUNT(*) FROM proof_attachments WHERE (GoogleDriveFileID IS NULL OR GoogleDriveFileID='') AND UploadStatus!='deleted'")->fetchColumn();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Drive Management - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
    <style>
        :root {
            --gdrive-blue: #4285f4;
            --gdrive-green: #0f9d58;
            --gdrive-yellow: #f4b400;
            --gdrive-red: #db4437;
            --bg-main: #0f172a;
            --bg-card: #1e293b;
            --bg-card-hover: #263548;
            --bg-input: #0f172a;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border-color: #334155;
            --border-accent: #475569;
            --accent: #667eea;
            --accent-hover: #5a67d8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
        }

        [data-theme="light"] {
            --bg-main: #f1f5f9;
            --bg-card: #ffffff;
            --bg-card-hover: #f8fafc;
            --bg-input: #f8fafc;
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --border-accent: #cbd5e1;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif; background: var(--bg-main); color: var(--text-primary); min-height: 100vh; }

        /* Navbar */
        .navbar {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 0 24px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .nav-left { display: flex; align-items: center; gap: 16px; }
        .nav-left .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 17px; color: #fff; }
        .nav-left .brand i { font-size: 22px; color: var(--gdrive-yellow); }
        .nav-right { display: flex; align-items: center; gap: 12px; }
        .nav-btn { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); color: #fff; padding: 7px 14px; border-radius: 8px; cursor: pointer; font-size: 13px; display: flex; align-items: center; gap: 6px; text-decoration: none; transition: 0.2s; }
        .nav-btn:hover { background: rgba(255,255,255,0.15); }
        .nav-user { color: #94a3b8; font-size: 13px; }
        .nav-user strong { color: #fff; }

        /* Container */
        .main-container { max-width: 1200px; margin: 0 auto; padding: 24px; }

        /* Back link */
        .back-link { display: inline-flex; align-items: center; gap: 8px; color: var(--text-secondary); text-decoration: none; font-size: 13px; margin-bottom: 20px; transition: 0.2s; }
        .back-link:hover { color: var(--accent); }

        /* Stat Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            border-top: 3px solid transparent;
            transition: 0.2s;
        }
        .stat-card:hover { border-color: var(--border-accent); }
        .stat-card:nth-child(1) { border-top-color: var(--accent); }
        .stat-card:nth-child(2) { border-top-color: var(--success); }
        .stat-card:nth-child(3) { border-top-color: var(--gdrive-green); }
        .stat-card:nth-child(4) { border-top-color: var(--warning); }
        .stat-card .stat-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .stat-card .stat-label { font-size: 13px; color: var(--text-secondary); }
        .stat-card .stat-value { font-size: 28px; font-weight: 800; color: var(--text-primary); }
        .stat-card .stat-icon { font-size: 22px; color: var(--text-muted); }

        /* Section Card */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
        }
        .section-header h2 { font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .section-header h2 i { color: var(--gdrive-yellow); }
        .section-body { padding: 20px 24px; }

        /* Account Card */
        .account-card {
            background: var(--bg-main);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 16px;
            transition: 0.2s;
        }
        .account-card.connected { border-color: var(--success); }
        .account-card.disconnected { border-color: var(--text-muted); opacity: 0.7; }
        .account-card:last-child { margin-bottom: 0; }
        .account-top { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 16px; }
        .account-avatar {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, var(--gdrive-green), #34a853);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .account-avatar i { font-size: 22px; color: #fff; }
        .account-info { flex: 1; }
        .account-name-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
        .account-name { font-weight: 700; font-size: 16px; }
        .badge {
            font-size: 10px; font-weight: 700; padding: 3px 10px;
            border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .badge-default { background: rgba(96,165,250,0.15); color: #60a5fa; border: 1px solid rgba(96,165,250,0.3); }
        .badge-connected { background: rgba(16,185,129,0.15); color: var(--success); border: 1px solid rgba(16,185,129,0.3); }
        .badge-disconnected { background: rgba(239,68,68,0.15); color: var(--danger); border: 1px solid rgba(239,68,68,0.3); }
        .account-email { color: var(--text-secondary); font-size: 13px; display: flex; align-items: center; gap: 6px; }
        .account-meta { color: var(--text-muted); font-size: 12px; margin-top: 4px; }
        .account-meta .folder-id { font-family: 'Courier New', monospace; font-size: 11px; color: var(--text-secondary); background: var(--bg-card); padding: 2px 6px; border-radius: 4px; word-break: break-all; }
        .account-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        /* Buttons */
        .btn-sm { padding: 8px 16px; border-radius: 8px; border: none; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; }
        .btn-choose { background: var(--gdrive-blue); color: #fff; }
        .btn-choose:hover { background: #3b78e7; }
        .btn-disconnect { background: var(--warning); color: #fff; }
        .btn-disconnect:hover { background: #d97706; }
        .btn-remove { background: var(--danger); color: #fff; }
        .btn-remove:hover { background: #dc2626; }
        .btn-test-conn { background: rgba(16,185,129,0.15); color: var(--success); border: 1px solid rgba(16,185,129,0.2); }
        .btn-test-conn:hover { background: rgba(16,185,129,0.25); }
        .btn-reconnect { background: var(--accent); color: #fff; }
        .btn-reconnect:hover { background: var(--accent-hover); }
        .btn-folders { background: rgba(139,92,246,0.15); color: #8b5cf6; border: 1px solid rgba(139,92,246,0.2); }
        .btn-folders:hover { background: rgba(139,92,246,0.25); }

        /* Add Account Button */
        .btn-add {
            background: linear-gradient(135deg, var(--accent), #764ba2);
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: 0.2s;
        }
        .btn-add:hover { transform: translateY(-1px); box-shadow: 0 4px 15px rgba(102,126,234,0.3); }

        /* Setup Guide */
        .guide-steps { display: flex; flex-direction: column; gap: 20px; padding: 4px 0; }
        .guide-step { display: flex; gap: 16px; align-items: flex-start; }
        .step-number {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, var(--gdrive-green), #34a853);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 14px; color: #fff;
            flex-shrink: 0;
        }
        .step-content h3 { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
        .step-content p { font-size: 13px; color: var(--text-secondary); line-height: 1.5; }
        .step-content a { color: var(--gdrive-blue); text-decoration: none; font-weight: 600; }
        .step-content a:hover { text-decoration: underline; }

        /* Modal Overlay */
        .modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(6px);
            display: none; align-items: center; justify-content: center;
            z-index: 1000;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            width: 100%;
            max-width: 560px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 60px rgba(0,0,0,0.4);
        }
        .modal-box.wide { max-width: 720px; }
        .modal-head {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex; justify-content: space-between; align-items: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px 16px 0 0;
        }
        .modal-head h3 { font-size: 17px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 8px; }
        .modal-close {
            background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2);
            color: #fff; width: 34px; height: 34px; border-radius: 50%;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 18px; transition: 0.3s;
        }
        .modal-close:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }
        .modal-body-inner { padding: 24px; }
        .modal-foot { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; gap: 10px; justify-content: flex-end; }

        /* Form Styles */
        .form-group { margin-bottom: 18px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; color: var(--text-secondary); margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 14px;
            transition: 0.2s;
            font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(102,126,234,0.15);
            outline: none;
        }
        .form-group .hint { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
        .form-group .mono { font-family: 'Courier New', monospace; font-size: 13px; }

        /* Auth URL Box */
        .auth-url-box {
            background: var(--bg-input);
            border: 2px dashed var(--border-color);
            border-radius: 10px;
            padding: 16px;
            margin: 16px 0;
            text-align: center;
        }
        .auth-url-box a {
            color: var(--gdrive-blue);
            font-weight: 700;
            font-size: 14px;
            word-break: break-all;
        }
        .auth-url-box p { font-size: 12px; color: var(--text-muted); margin-top: 8px; }

        /* Step Indicator in Modal */
        .oauth-steps { display: flex; gap: 0; }
        .oauth-step {
            flex: 1;
            text-align: center;
            padding: 10px 8px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            position: relative;
            border-bottom: 3px solid var(--border-color);
        }
        .oauth-step.active { color: var(--accent); border-bottom-color: var(--accent); }
        .oauth-step.done { color: var(--success); border-bottom-color: var(--success); }

        /* Folder Browser */
        .folder-browser { border: 1px solid var(--border-color); border-radius: 10px; overflow: hidden; }
        .folder-breadcrumb {
            display: flex; align-items: center; gap: 4px; padding: 12px 16px;
            background: var(--bg-input); border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap; font-size: 13px;
        }
        .folder-breadcrumb .crumb {
            color: var(--accent); cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 4px;
        }
        .folder-breadcrumb .crumb:hover { text-decoration: underline; }
        .folder-breadcrumb .sep { color: var(--text-muted); margin: 0 2px; }
        .folder-breadcrumb .current { color: var(--text-primary); font-weight: 700; display: flex; align-items: center; gap: 4px; }
        .folder-list {
            max-height: 320px;
            overflow-y: auto;
        }
        .folder-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: 0.15s;
        }
        .folder-item:last-child { border-bottom: none; }
        .folder-item:hover { background: var(--bg-card-hover); }
        .folder-item.selected { background: rgba(102,126,234,0.1); border-left: 3px solid var(--accent); }
        .folder-item i.folder-icon { color: var(--gdrive-yellow); font-size: 18px; flex-shrink: 0; }
        .folder-item .folder-name { font-weight: 600; font-size: 14px; flex: 1; }
        .folder-item .folder-date { font-size: 11px; color: var(--text-muted); }
        .folder-item .folder-enter { color: var(--text-muted); font-size: 14px; flex-shrink: 0; }
        .folder-empty { padding: 40px 20px; text-align: center; color: var(--text-muted); font-size: 13px; }
        .folder-empty i { font-size: 32px; margin-bottom: 10px; display: block; color: var(--border-color); }
        .folder-loading { padding: 40px 20px; text-align: center; color: var(--text-muted); }
        .folder-loading i { animation: spin 1s linear infinite; font-size: 24px; }
        .new-folder-row {
            display: flex; gap: 8px; padding: 12px 16px;
            border-top: 1px solid var(--border-color);
            background: var(--bg-input);
        }
        .new-folder-row input {
            flex: 1; padding: 8px 12px;
            border: 2px solid var(--border-color); border-radius: 8px;
            background: var(--bg-card); color: var(--text-primary);
            font-size: 13px;
        }
        .new-folder-row input:focus { border-color: var(--accent); outline: none; }
        .new-folder-row button {
            padding: 8px 16px; border-radius: 8px; border: none;
            background: var(--gdrive-green); color: #fff;
            font-weight: 600; font-size: 12px; cursor: pointer; white-space: nowrap;
            display: flex; align-items: center; gap: 6px;
        }
        .new-folder-row button:hover { background: #0d8b4e; }

        /* Toast */
        .toast-container { position: fixed; top: 80px; right: 24px; z-index: 2000; display: flex; flex-direction: column; gap: 10px; }
        .toast {
            padding: 14px 20px;
            border-radius: 10px;
            color: #fff;
            font-size: 14px;
            font-weight: 500;
            display: flex; align-items: center; gap: 10px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
            animation: toastIn 0.3s ease;
            min-width: 300px;
            max-width: 450px;
        }
        .toast.success { background: linear-gradient(135deg, #10b981, #059669); }
        .toast.error { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .toast.info { background: linear-gradient(135deg, #3b82f6, #2563eb); }

        @keyframes toastIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Loading state */
        .btn-loading { position: relative; pointer-events: none; opacity: 0.7; }

        /* Responsive */
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .account-top { flex-direction: column; }
            .account-actions { flex-wrap: wrap; }
            .modal-box { margin: 10px; }
            .navbar { padding: 0 12px; }
            .main-container { padding: 16px; }
            .oauth-steps { font-size: 10px; }
            .nav-right { gap: 6px; }
            .nav-user { display: none; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 14px; }
            .stat-card .stat-value { font-size: 22px; }
        }
    </style>
</head>
<body>

    <!-- Navbar -->
    <nav class="navbar">
        <div class="nav-left">
            <div class="brand">
                <i class="fab fa-google-drive"></i>
                <span>Google Drive Management</span>
            </div>
        </div>
        <div class="nav-right">
            <?php include "includes/datetime-widget.php"; ?>
            <span class="nav-user"><strong><?php echo htmlspecialchars($superadmin_name ?? 'Admin'); ?></strong> &middot; Super Admin</span>
            <button class="nav-btn" id="themeToggle" onclick="toggleTheme()">
                <i id="themeIcon" class="fas fa-moon"></i>
            </button>
            <a href="dashboard.php" class="nav-btn"><i class="fas fa-home"></i> Dashboard</a>
            <a href="settings.php" class="nav-btn"><i class="fas fa-arrow-left"></i> Settings</a>
        </div>
    </nav>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <div class="main-container">
        <a href="settings.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Settings</a>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Accounts</span>
                    <span class="stat-value" id="statAccounts"><?php echo $totalAccounts; ?></span>
                </div>
                <i class="fas fa-users stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Connected</span>
                    <span class="stat-value" id="statConnected"><?php echo $connectedAccounts; ?></span>
                </div>
                <i class="fas fa-link stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">On GDrive</span>
                    <span class="stat-value" id="statGDrive"><?php echo $onGDrive; ?></span>
                </div>
                <i class="fab fa-google-drive stat-icon"></i>
            </div>
            <div class="stat-card">
                <div class="stat-top">
                    <span class="stat-label">Local Only</span>
                    <span class="stat-value" id="statLocal"><?php echo $localOnly; ?></span>
                </div>
                <i class="fas fa-hdd stat-icon"></i>
            </div>
        </div>

        <!-- Accounts Section -->
        <div class="section-card">
            <div class="section-header">
                <h2><i class="fab fa-google-drive"></i> Google Drive Accounts</h2>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <?php if ($connectedAccounts > 0): ?>
                    <button class="btn-add" id="renameDriveBtn" onclick="renameDriveFiles()" title="Rename every proof file and backup in Google Drive to its description and date">
                        <i class="fas fa-i-cursor"></i> Rename Files in Drive
                    </button>
                    <?php endif; ?>
                    <button class="btn-add" onclick="openAddAccountModal()">
                        <i class="fas fa-plus"></i> Add Account
                    </button>
                </div>
            </div>
            <div class="section-body" id="accountsList">
                <?php if (empty($accounts)): ?>
                    <div style="text-align:center; padding:40px 20px; color:var(--text-muted);">
                        <i class="fab fa-google-drive" style="font-size:48px; margin-bottom:14px; display:block; opacity:0.3;"></i>
                        <p style="font-size:15px; font-weight:600;">No Google Drive accounts configured</p>
                        <p style="font-size:13px; margin-top:6px;">Click "Add Account" to connect your Google Drive using Desktop App OAuth</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($accounts as $acct): 
                        $isConnected = !empty($acct['IsActive']) && !empty($acct['RefreshToken']);
                        $acctName = $acct['AccountName'] ?: ($acct['AccountEmail'] ? explode('@', $acct['AccountEmail'])[0] : 'Account #'.$acct['SettingID']);
                    ?>
                    <div class="account-card <?php echo $isConnected ? 'connected' : 'disconnected'; ?>" id="account-<?php echo $acct['SettingID']; ?>">
                        <div class="account-top">
                            <div class="account-avatar">
                                <i class="fab fa-google-drive"></i>
                            </div>
                            <div class="account-info">
                                <div class="account-name-row">
                                    <span class="account-name"><?php echo htmlspecialchars($acctName); ?></span>
                                    <?php if ($acct['IsDefault']): ?>
                                        <span class="badge badge-default">Default</span>
                                    <?php endif; ?>
                                    <?php if ($isConnected): ?>
                                        <span class="badge badge-connected">Connected</span>
                                    <?php else: ?>
                                        <span class="badge badge-disconnected">Disconnected</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($acct['AccountEmail'])): ?>
                                    <div class="account-email">
                                        <i class="fas fa-envelope"></i>
                                        <?php echo htmlspecialchars($acct['AccountEmail']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="account-meta">
                                    <?php if (!empty($acct['ConnectedAt'])): ?>
                                        Connected: <?php echo date('d M Y, h:i A', strtotime($acct['ConnectedAt'])); ?>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);">Not connected yet</span>
                                    <?php endif; ?>
                                    <?php if (!empty($acct['FolderID'])): ?>
                                        &nbsp;&middot;&nbsp; <i class="fas fa-folder" style="color:var(--gdrive-yellow);"></i> Upload folder: <span class="folder-id"><?php echo htmlspecialchars($acct['FolderID']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="account-actions">
                            <?php if ($isConnected): ?>
                                <button class="btn-sm btn-choose" onclick="openFolderBrowser(<?php echo $acct['SettingID']; ?>)">
                                    <i class="fas fa-folder-open"></i> Choose Upload Folder
                                </button>
                                <button class="btn-sm btn-folders" onclick="autoCreateFolders(<?php echo $acct['SettingID']; ?>)">
                                    <i class="fas fa-folder-plus"></i> Auto-Create Folders
                                </button>
                                <button class="btn-sm btn-test-conn" onclick="testConnection(<?php echo $acct['SettingID']; ?>)">
                                    <i class="fas fa-plug"></i> Test
                                </button>
                                <button class="btn-sm btn-disconnect" onclick="disconnectAccount(<?php echo $acct['SettingID']; ?>)">
                                    <i class="fas fa-unlink"></i> Disconnect
                                </button>
                            <?php else: ?>
                                <button class="btn-sm btn-reconnect" onclick="reconnectAccount(<?php echo $acct['SettingID']; ?>, '<?php echo htmlspecialchars(addslashes($acct['ClientID'] ?? ''), ENT_QUOTES); ?>')">
                                    <i class="fas fa-plug"></i> Reconnect
                                </button>
                            <?php endif; ?>
                            <button class="btn-sm btn-remove" onclick="removeAccount(<?php echo $acct['SettingID']; ?>)">
                                <i class="fas fa-trash"></i> Remove
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Setup Guide -->
        <div class="section-card">
            <div class="section-header">
                <h2><i class="fas fa-book"></i> Setup Guide</h2>
            </div>
            <div class="section-body">
                <div class="guide-steps">
                    <div class="guide-step">
                        <div class="step-number">1</div>
                        <div class="step-content">
                            <h3>Create Google Cloud Project</h3>
                            <p>Go to <a href="https://console.cloud.google.com" target="_blank">console.cloud.google.com</a> and create a new project</p>
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-number">2</div>
                        <div class="step-content">
                            <h3>Enable Google Drive API</h3>
                            <p>In APIs &amp; Services &rarr; Library, search and enable "Google Drive API"</p>
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-number">3</div>
                        <div class="step-content">
                            <h3>Create OAuth 2.0 Credentials</h3>
                            <p>Go to APIs &amp; Services &rarr; Credentials &rarr; Create &rarr; OAuth 2.0 Client ID &rarr; <strong>Desktop App</strong></p>
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-number">4</div>
                        <div class="step-content">
                            <h3>Copy Client ID &amp; Client Secret</h3>
                            <p>Copy the Client ID and Client Secret from the created credentials. Use these when adding an account above.</p>
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-number">5</div>
                        <div class="step-content">
                            <h3>Add Test Users (if app is in Testing mode)</h3>
                            <p>Go to OAuth consent screen &rarr; Test users &rarr; Add your Gmail address. Required if the app hasn't been verified yet.</p>
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-number">6</div>
                        <div class="step-content">
                            <h3>Connect &amp; Authorize</h3>
                            <p>Click "+ Add Account" above, enter your Client ID &amp; Secret, follow the Google authorization flow, paste the code, and you're done! No refresh token needed — the system handles everything automatically.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== -->
    <!-- ADD ACCOUNT MODAL     -->
    <!-- ===================== -->
    <div class="modal-overlay" id="addAccountModal">
        <div class="modal-box">
            <div class="modal-head">
                <h3><i class="fas fa-plus-circle"></i> Connect Google Drive Account</h3>
                <button class="modal-close" onclick="closeModal('addAccountModal')">&times;</button>
            </div>

            <!-- Step indicators -->
            <div class="oauth-steps">
                <div class="oauth-step active" id="oauthStep1"><i class="fas fa-key"></i> Credentials</div>
                <div class="oauth-step" id="oauthStep2"><i class="fas fa-external-link-alt"></i> Authorize</div>
                <div class="oauth-step" id="oauthStep3"><i class="fas fa-check-circle"></i> Complete</div>
            </div>

            <div class="modal-body-inner">
                <!-- STEP 1: Enter credentials -->
                <div id="oauthPanel1">
                    <div class="form-group">
                        <label>Account Name (optional)</label>
                        <input type="text" id="addAccountName" placeholder="e.g. My Drive Account">
                        <div class="hint">A friendly name to identify this account</div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-id-card" style="color:var(--gdrive-blue);"></i> Client ID <span style="color:var(--danger);">*</span></label>
                        <input type="text" id="addClientId" class="mono" placeholder="xxxxx.apps.googleusercontent.com">
                        <div class="hint">From Google Cloud Console &rarr; Credentials &rarr; Desktop App</div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-lock" style="color:var(--warning);"></i> Client Secret <span style="color:var(--danger);">*</span></label>
                        <input type="password" id="addClientSecret" class="mono" placeholder="GOCSPX-xxxxxxxxxx">
                        <div class="hint">Keep this secure — never share it publicly</div>
                    </div>
                </div>

                <!-- STEP 2: Auth URL + Code -->
                <div id="oauthPanel2" style="display:none;">
                    <div class="auth-url-box" id="authUrlBox">
                        <p style="margin-bottom:10px; color:var(--text-secondary);"><i class="fas fa-external-link-alt"></i> Click the button below to authorize:</p>
                        <a id="authUrlLink" href="#" target="_blank" style="display:inline-flex; align-items:center; gap:8px; background:var(--gdrive-blue); color:#fff; padding:10px 20px; border-radius:8px; font-size:14px; text-decoration:none;">
                            <i class="fab fa-google"></i> Open Google Authorization
                        </a>
                        <p style="margin-top:12px;">Sign in with your Google account, click "Allow", and <strong>copy the authorization code</strong> shown on screen.</p>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-code" style="color:var(--success);"></i> Authorization Code <span style="color:var(--danger);">*</span></label>
                        <input type="text" id="addAuthCode" class="mono" placeholder="4/xxxxxxxxxxxxxxxx">
                        <div class="hint">Paste the code Google gives you after you click "Allow"</div>
                    </div>
                </div>

                <!-- STEP 3: Success -->
                <div id="oauthPanel3" style="display:none; text-align:center; padding:20px 0;">
                    <div style="width:72px; height:72px; background:rgba(16,185,129,0.15); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
                        <i class="fas fa-check-circle" style="font-size:40px; color:var(--success);"></i>
                    </div>
                    <h3 style="font-size:20px; margin-bottom:8px;">Account Connected!</h3>
                    <p id="oauthSuccessMsg" style="color:var(--text-secondary); font-size:14px;">Your Google Drive account has been connected successfully.</p>
                </div>
            </div>

            <div class="modal-foot" id="oauthFooter">
                <button class="btn-sm btn-test-conn" onclick="closeModal('addAccountModal')" style="padding:10px 20px; font-size:13px;">Cancel</button>
                <button class="btn-add" id="oauthNextBtn" onclick="oauthNextStep()">
                    <i class="fas fa-arrow-right"></i> Next: Get Auth URL
                </button>
            </div>
        </div>
    </div>

    <!-- ===================== -->
    <!-- FOLDER BROWSER MODAL  -->
    <!-- ===================== -->
    <div class="modal-overlay" id="folderBrowserModal">
        <div class="modal-box wide">
            <div class="modal-head">
                <h3><i class="fas fa-folder-open"></i> Browse Google Drive Folders</h3>
                <button class="modal-close" onclick="closeModal('folderBrowserModal')">&times;</button>
            </div>
            <div style="padding:0;">
                <div class="folder-browser">
                    <div class="folder-breadcrumb" id="folderBreadcrumb">
                        <span class="crumb" onclick="navigateFolder('root')"><i class="fab fa-google-drive"></i> My Drive</span>
                    </div>
                    <div class="folder-list" id="folderList">
                        <div class="folder-loading"><i class="fas fa-spinner"></i><br>Loading folders...</div>
                    </div>
                    <div class="new-folder-row">
                        <input type="text" id="newFolderName" placeholder="New folder name...">
                        <button onclick="createNewFolder()"><i class="fas fa-folder-plus"></i> Create Folder</button>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn-sm btn-test-conn" onclick="closeModal('folderBrowserModal')" style="padding:10px 20px; font-size:13px;">Cancel</button>
                <button class="btn-add" id="selectFolderBtn" onclick="selectFolder()" disabled>
                    <i class="fas fa-check"></i> Select This Folder
                </button>
            </div>
        </div>
    </div>

    <script>
    // ========================
    // GLOBALS
    // ========================
    const API = 'api/gdrive-actions.php';
    let currentOAuthStep = 1;
    let currentBrowseAccountId = null;
    let currentBrowseParentId = 'root';
    let selectedFolderId = null;
    let selectedFolderName = '';
    let folderNavStack = [{ id: 'root', name: 'My Drive' }];

    // ========================
    // TOAST SYSTEM
    // ========================
    function showToast(msg, type = 'info') {
        const container = document.getElementById('toastContainer');
        const toast = document.createElement('div');
        toast.className = 'toast ' + type;
        const icons = { success: 'fa-check-circle', error: 'fa-exclamation-circle', info: 'fa-info-circle' };
        toast.innerHTML = '<i class="fas ' + (icons[type] || icons.info) + '"></i> ' + escapeHtml(msg);
        container.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateX(100%)'; toast.style.transition = '0.3s'; setTimeout(() => toast.remove(), 300); }, 4500);
    }

    // ========================
    // API CALL HELPER
    // ========================
    async function apiCall(action, data = {}) {
        const formData = new FormData();
        formData.append('action', action);
        for (const [k, v] of Object.entries(data)) formData.append(k, v);
        try {
            const resp = await fetch(API, { method: 'POST', body: formData });
            if (!resp.ok) throw new Error('Server error: HTTP ' + resp.status);
            return await resp.json();
        } catch (e) {
            return { success: false, error: e.message };
        }
    }

    // ========================
    // MODAL HELPERS
    // ========================
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    // Close modal on backdrop click
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('active');
        });
    });

    // ========================
    // ADD ACCOUNT - OAUTH FLOW
    // ========================
    function openAddAccountModal() {
        currentOAuthStep = 1;
        updateOAuthUI();
        document.getElementById('addAccountName').value = '';
        document.getElementById('addClientId').value = '';
        document.getElementById('addClientSecret').value = '';
        document.getElementById('addAuthCode').value = '';
        openModal('addAccountModal');
    }

    function updateOAuthUI() {
        for (let i = 1; i <= 3; i++) {
            const step = document.getElementById('oauthStep' + i);
            const panel = document.getElementById('oauthPanel' + i);
            step.className = 'oauth-step' + (i === currentOAuthStep ? ' active' : '') + (i < currentOAuthStep ? ' done' : '');
            panel.style.display = (i === currentOAuthStep) ? 'block' : 'none';
        }
        const btn = document.getElementById('oauthNextBtn');
        if (currentOAuthStep === 1) {
            btn.innerHTML = '<i class="fas fa-arrow-right"></i> Next: Get Auth URL';
            btn.onclick = oauthNextStep;
        } else if (currentOAuthStep === 2) {
            btn.innerHTML = '<i class="fas fa-exchange-alt"></i> Connect Account';
            btn.onclick = oauthNextStep;
        } else {
            btn.innerHTML = '<i class="fas fa-check"></i> Done';
            btn.onclick = function() { closeModal('addAccountModal'); location.reload(); };
        }
    }

    async function oauthNextStep() {
        const btn = document.getElementById('oauthNextBtn');

        if (currentOAuthStep === 1) {
            const clientId = document.getElementById('addClientId').value.trim();
            const clientSecret = document.getElementById('addClientSecret').value.trim();
            if (!clientId || !clientSecret) { showToast('Client ID and Client Secret are required', 'error'); return; }
            
            btn.classList.add('btn-loading');
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            try {
                const result = await apiCall('get_auth_url', { client_id: clientId });
                if (!result.success) throw new Error(result.error);
                
                document.getElementById('authUrlLink').href = result.auth_url;
                currentOAuthStep = 2;
                updateOAuthUI();
            } catch (e) {
                showToast(e.message, 'error');
                btn.innerHTML = origHtml;
            }
            btn.classList.remove('btn-loading');

        } else if (currentOAuthStep === 2) {
            const code = document.getElementById('addAuthCode').value.trim();
            if (!code) { showToast('Please paste the authorization code from Google', 'error'); return; }
            
            btn.classList.add('btn-loading');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Connecting...';
            try {
                const result = await apiCall('exchange_code', {
                    client_id: document.getElementById('addClientId').value.trim(),
                    client_secret: document.getElementById('addClientSecret').value.trim(),
                    code: code,
                    account_name: document.getElementById('addAccountName').value.trim(),
                });
                if (!result.success) throw new Error(result.error);
                
                document.getElementById('oauthSuccessMsg').textContent = 
                    'Connected as ' + (result.account.email || result.account.name) + '. The page will reload to show your account.';
                currentOAuthStep = 3;
                updateOAuthUI();
                showToast('Account connected successfully!', 'success');
            } catch (e) {
                showToast(e.message, 'error');
                btn.innerHTML = '<i class="fas fa-exchange-alt"></i> Connect Account';
            }
            btn.classList.remove('btn-loading');
        } else {
            closeModal('addAccountModal');
            location.reload();
        }
    }

    // ========================
    // RECONNECT
    // ========================
    function reconnectAccount(accountId, existingClientId) {
        openAddAccountModal();
        if (existingClientId) {
            document.getElementById('addClientId').value = existingClientId;
        }
    }

    // ========================
    // ACCOUNT ACTIONS
    // ========================
    async function disconnectAccount(accountId) {
        if (!confirm('Disconnect this account? You can reconnect later.')) return;
        try {
            const result = await apiCall('disconnect_account', { account_id: accountId });
            if (!result.success) throw new Error(result.error);
            showToast('Account disconnected', 'success');
            setTimeout(() => location.reload(), 800);
        } catch (e) { showToast(e.message, 'error'); }
    }

    async function removeAccount(accountId) {
        if (!confirm('Permanently remove this account? This cannot be undone.')) return;
        try {
            const result = await apiCall('remove_account', { account_id: accountId });
            if (!result.success) throw new Error(result.error);
            showToast('Account removed', 'success');
            const el = document.getElementById('account-' + accountId);
            if (el) { el.style.transition = '0.3s'; el.style.opacity = '0'; el.style.transform = 'translateX(-20px)'; }
            setTimeout(() => location.reload(), 800);
        } catch (e) { showToast(e.message, 'error'); }
    }

    async function testConnection(accountId) {
        showToast('Testing connection...', 'info');
        try {
            const result = await apiCall('test_connection', { account_id: accountId });
            if (result.success) {
                showToast('Connection successful! Google Drive is accessible.', 'success');
            } else {
                showToast('Connection failed: ' + (result.message || result.error), 'error');
            }
        } catch (e) { showToast(e.message, 'error'); }
    }

    // Rename all files already in Drive to "<Description> - <dd-mm-yyyy>" (new uploads get these names automatically)
    async function renameDriveFiles() {
        if (!confirm('Rename all proof files and database backups in Google Drive to their description and date?\n\nExample: "Electricity Bill - 06-10-2026.jpg"')) return;
        const btn = document.getElementById('renameDriveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Renaming…';
        try {
            const res = await fetch('api/gdrive-sync.php?action=rename_all', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'rename_all' }) });
            const data = await res.json();
            if (data.success) {
                showToast(data.message, data.failed ? 'info' : 'success');
            } else {
                showToast('Rename failed: ' + (data.error || (data.errors || []).join('; ')), 'error');
            }
        } catch (e) {
            showToast('Rename failed: ' + e.message, 'error');
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-i-cursor"></i> Rename Files in Drive';
    }

    async function autoCreateFolders(accountId) {
        if (!confirm('Create "SR Colony - Expenses" and "SR Colony - Event Proofs" folders in Google Drive?')) return;
        showToast('Creating folders...', 'info');
        try {
            const result = await apiCall('auto_create_folders', { account_id: accountId });
            if (!result.success) throw new Error(result.error);
            showToast('Folders created successfully!', 'success');
            setTimeout(() => location.reload(), 1200);
        } catch (e) { showToast(e.message, 'error'); }
    }

    // ========================
    // FOLDER BROWSER
    // ========================
    function openFolderBrowser(accountId) {
        currentBrowseAccountId = accountId;
        folderNavStack = [{ id: 'root', name: 'My Drive' }];
        selectedFolderId = null;
        selectedFolderName = '';
        document.getElementById('selectFolderBtn').disabled = true;
        document.getElementById('newFolderName').value = '';
        openModal('folderBrowserModal');
        navigateFolder('root');
    }

    async function navigateFolder(folderId) {
        currentBrowseParentId = folderId;
        selectedFolderId = folderId;
        selectedFolderName = folderId === 'root' ? 'My Drive (root)' : (folderNavStack.length > 0 ? folderNavStack[folderNavStack.length - 1].name : folderId);
        document.getElementById('selectFolderBtn').disabled = false;
        document.getElementById('selectFolderBtn').innerHTML = '<i class="fas fa-check"></i> Select: ' + escapeHtml(selectedFolderName);

        // Update breadcrumb
        if (folderId === 'root') {
            folderNavStack = [{ id: 'root', name: 'My Drive' }];
        }
        renderBreadcrumb();

        // Show loading
        document.getElementById('folderList').innerHTML = '<div class="folder-loading"><i class="fas fa-spinner"></i><br>Loading folders...</div>';

        try {
            const result = await apiCall('list_folders', { account_id: currentBrowseAccountId, parent_id: folderId });
            if (!result.success) throw new Error(result.error);
            renderFolders(result.folders || []);
        } catch (e) {
            document.getElementById('folderList').innerHTML = '<div class="folder-empty"><i class="fas fa-exclamation-triangle"></i><br>' + escapeHtml(e.message) + '</div>';
        }
    }

    function renderBreadcrumb() {
        const bc = document.getElementById('folderBreadcrumb');
        let html = '';
        folderNavStack.forEach((item, i) => {
            if (i > 0) html += '<span class="sep"><i class="fas fa-chevron-right" style="font-size:10px;"></i></span>';
            if (i === folderNavStack.length - 1) {
                html += '<span class="current">' + (i === 0 ? '<i class="fab fa-google-drive"></i> ' : '<i class="fas fa-folder" style="color:var(--gdrive-yellow);"></i> ') + escapeHtml(item.name) + '</span>';
            } else {
                html += '<span class="crumb" onclick="breadcrumbNav(' + i + ')">' + (i === 0 ? '<i class="fab fa-google-drive"></i> ' : '') + escapeHtml(item.name) + '</span>';
            }
        });
        bc.innerHTML = html;
    }

    function breadcrumbNav(index) {
        folderNavStack = folderNavStack.slice(0, index + 1);
        navigateFolder(folderNavStack[index].id);
    }

    function renderFolders(folders) {
        const list = document.getElementById('folderList');
        if (!folders.length) {
            list.innerHTML = '<div class="folder-empty"><i class="fas fa-folder-open"></i><br>No subfolders found.<br><span style="font-size:11px;">You can create one below.</span></div>';
            return;
        }
        let html = '';
        folders.forEach(f => {
            const date = f.createdTime ? new Date(f.createdTime).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
            const safeName = escapeHtml(f.name);
            html += '<div class="folder-item" ondblclick="enterFolder(\'' + f.id + '\', \'' + safeName.replace(/'/g, "\\'") + '\')" onclick="highlightFolder(this, \'' + f.id + '\', \'' + safeName.replace(/'/g, "\\'") + '\')" data-id="' + f.id + '">'
                + '<i class="fas fa-folder folder-icon"></i>'
                + '<span class="folder-name">' + safeName + '</span>'
                + '<span class="folder-date">' + date + '</span>'
                + '<i class="fas fa-chevron-right folder-enter" onclick="event.stopPropagation(); enterFolder(\'' + f.id + '\', \'' + safeName.replace(/'/g, "\\'") + '\')"></i>'
                + '</div>';
        });
        list.innerHTML = html;
    }

    function highlightFolder(el, folderId, folderName) {
        document.querySelectorAll('.folder-item.selected').forEach(e => e.classList.remove('selected'));
        el.classList.add('selected');
        selectedFolderId = folderId;
        selectedFolderName = folderName;
        document.getElementById('selectFolderBtn').disabled = false;
        document.getElementById('selectFolderBtn').innerHTML = '<i class="fas fa-check"></i> Select: ' + escapeHtml(folderName);
    }

    function enterFolder(folderId, folderName) {
        folderNavStack.push({ id: folderId, name: folderName });
        selectedFolderId = folderId;
        selectedFolderName = folderName;
        navigateFolder(folderId);
    }

    async function createNewFolder() {
        const nameInput = document.getElementById('newFolderName');
        const name = nameInput.value.trim();
        if (!name) { showToast('Enter a folder name', 'error'); return; }
        
        showToast('Creating folder "' + name + '"...', 'info');
        try {
            const result = await apiCall('create_folder', {
                account_id: currentBrowseAccountId,
                folder_name: name,
                parent_id: currentBrowseParentId || 'root',
            });
            if (!result.success) throw new Error(result.error);
            showToast('Folder "' + name + '" created!', 'success');
            nameInput.value = '';
            navigateFolder(currentBrowseParentId); // refresh
        } catch (e) { showToast(e.message, 'error'); }
    }

    async function selectFolder() {
        if (!selectedFolderId || !currentBrowseAccountId) return;
        try {
            const result = await apiCall('set_upload_folder', {
                account_id: currentBrowseAccountId,
                folder_id: selectedFolderId === 'root' ? '' : selectedFolderId,
            });
            if (!result.success) throw new Error(result.error);
            showToast('Upload folder set to: ' + selectedFolderName, 'success');
            closeModal('folderBrowserModal');
            setTimeout(() => location.reload(), 800);
        } catch (e) { showToast(e.message, 'error'); }
    }

    // ========================
    // THEME TOGGLE
    // ========================
    function toggleTheme() {
        const html = document.documentElement;
        const current = html.getAttribute('data-theme');
        const next = current === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
        const icon = document.getElementById('themeIcon');
        icon.className = next === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
    }

    // Init theme from localStorage
    (function() {
        const saved = localStorage.getItem('theme');
        if (saved) {
            document.documentElement.setAttribute('data-theme', saved);
            const icon = document.getElementById('themeIcon');
            if (icon) icon.className = saved === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
        }
    })();

    // ========================
    // HELPERS
    // ========================
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // Enter key handlers
    document.getElementById('newFolderName')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); createNewFolder(); }
    });
    document.getElementById('addAuthCode')?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); oauthNextStep(); }
    });
    </script>
<?php include '../includes/gemini-widget.php'; ?>
</body>
</html>
