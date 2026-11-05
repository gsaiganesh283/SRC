<?php
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/logo-helper.php';

$message = '';
$messageType = '';

// Fetch logo settings
$websiteLogo = getWebsiteLogo();
$systemLogo = getSystemLogo();
$favicon = getFavicon();
$siteName = getSiteName();

// Handle Gemini API key save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_gemini_key'])) {
    $geminiKey = trim($_POST['gemini_api_key'] ?? '');
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE SettingKey = 'gemini_api_key'");
        $stmt->execute();
        if ($stmt->fetchColumn() > 0) {
            $pdo->prepare("UPDATE system_settings SET SettingValue=?, UpdatedBy=?, UpdatedAt=NOW() WHERE SettingKey='gemini_api_key'")
                ->execute([$geminiKey, $_SESSION['superadmin_id']]);
        } else {
            $pdo->prepare("INSERT INTO system_settings (SettingKey, SettingValue, UpdatedBy, UpdatedAt) VALUES ('gemini_api_key',?,?,NOW())")
                ->execute([$geminiKey, $_SESSION['superadmin_id']]);
        }
        $message = 'Gemini API key saved successfully!';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Error saving API key: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        $pdo->beginTransaction();

        foreach ($_POST['settings'] as $key => $value) {
            $stmt = $pdo->prepare("
                UPDATE system_settings
                SET SettingValue = ?,
                    UpdatedBy = ?,
                    UpdatedAt = NOW()
                WHERE SettingKey = ?
            ");
            $stmt->execute([$value, $_SESSION['superadmin_id'], $key]);

            // Log the change
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (UserID, UserType, Action, TableName, IPAddress)
                VALUES (?, 'SuperAdmin', 'update', ?, ?)
            ");
            $stmt->execute([
                $_SESSION['superadmin_id'],
                "Updated system setting: {$key} = {$value}",
                $_SERVER['REMOTE_ADDR']
            ]);
        }

        $pdo->commit();
        $message = 'System settings updated successfully!';
        $messageType = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Error updating settings: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch all settings grouped by category
$allSettings = [];
$settingsByCategory = [];
$recentChanges = [];

try {
    // Try query without Description column first
    try {
        $stmt = $pdo->query("
            SELECT 
                ss.SettingID,
                ss.SettingKey,
                ss.SettingValue,
                '' as SettingCategory,
                'text' as DataType,
                '' as Description,
                ss.UpdatedAt,
                sa.Username as UpdatedByUser
            FROM system_settings ss
            LEFT JOIN super_admins sa ON ss.UpdatedBy = sa.SuperAdminID
            ORDER BY ss.SettingKey
        ");
    } catch (Exception $e) {
        // If that fails, try even simpler query
        $stmt = $pdo->query("
            SELECT 
                SettingID,
                SettingKey,
                SettingValue,
                UpdatedAt
            FROM system_settings
            ORDER BY SettingKey
        ");
    }
    $allSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add missing fields if they don't exist
    foreach ($allSettings as &$setting) {
        if (!isset($setting['SettingCategory'])) $setting['SettingCategory'] = 'General';
        if (!isset($setting['DataType'])) $setting['DataType'] = 'text';
        if (!isset($setting['Description'])) $setting['Description'] = '';
        if (!isset($setting['UpdatedByUser'])) $setting['UpdatedByUser'] = 'System';
    }
    unset($setting);
    
    // Group settings by category
    $settingsByCategory = [];
    foreach ($allSettings as $setting) {
        $category = $setting['SettingCategory'];
        if (!isset($settingsByCategory[$category])) {
            $settingsByCategory[$category] = [];
        }
        $settingsByCategory[$category][] = $setting;
    }
    
    // Get recent changes
    $stmt = $pdo->query("
        SELECT 
            al.Action,
            al.TableName,
            al.NewData,
            al.CreatedAt,
            sa.Username
        FROM audit_logs al
        JOIN super_admins sa ON al.UserID = sa.SuperAdminID
        WHERE al.Action = 'update' 
        AND al.TableName = 'system_settings'
        ORDER BY al.CreatedAt DESC
        LIMIT 10
    ");
    $recentChanges = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = 'Error fetching settings: ' . $e->getMessage();
}

// Fetch current Gemini API key (masked for display)
$currentGeminiKey = '';
try {
    $stmt = $pdo->prepare("SELECT SettingValue FROM system_settings WHERE SettingKey = 'gemini_api_key' LIMIT 1");
    $stmt->execute();
    $currentGeminiKey = $stmt->fetchColumn() ?: '';
} catch (Exception $e) { /* table may not have this row yet */ }

// Handle Chatbot Advanced Settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_chatbot_settings'])) {
    $chatbotSave = [
        'chatbot_history_enabled' => isset($_POST['chatbot_history_enabled']) ? '1' : '0',
        'chatbot_history_days'    => (string) max(1, min(30, intval($_POST['chatbot_history_days'] ?? 7))),
        'chatbot_auto_open'       => isset($_POST['chatbot_auto_open']) ? '1' : '0',
        'chatbot_quick_btn_1'     => substr(trim($_POST['chatbot_quick_btn_1'] ?? 'Show total collection'), 0, 60),
        'chatbot_quick_btn_2'     => substr(trim($_POST['chatbot_quick_btn_2'] ?? 'Which member paid the most?'), 0, 60),
        'chatbot_quick_btn_3'     => substr(trim($_POST['chatbot_quick_btn_3'] ?? 'Net balance?'), 0, 60),
        'chatbot_quick_btn_4'     => substr(trim($_POST['chatbot_quick_btn_4'] ?? 'Recent transactions'), 0, 60),
    ];
    try {
        foreach ($chatbotSave as $k => $v) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM system_settings WHERE SettingKey = ?");
            $chk->execute([$k]);
            if ($chk->fetchColumn() > 0) {
                $pdo->prepare("UPDATE system_settings SET SettingValue=?, UpdatedBy=?, UpdatedAt=NOW() WHERE SettingKey=?")
                    ->execute([$v, $_SESSION['superadmin_id'], $k]);
            } else {
                $pdo->prepare("INSERT INTO system_settings (SettingKey, SettingValue, UpdatedBy, UpdatedAt) VALUES (?,?,?,NOW())")
                    ->execute([$k, $v, $_SESSION['superadmin_id']]);
            }
        }
        $message = 'Chatbot settings saved successfully!';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = 'Error saving chatbot settings: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch chatbot advanced settings
$chatbotConfig = [
    'chatbot_history_enabled' => '1',
    'chatbot_history_days'    => '7',
    'chatbot_auto_open'       => '0',
    'chatbot_quick_btn_1'     => 'Show total collection',
    'chatbot_quick_btn_2'     => 'Which member paid the most?',
    'chatbot_quick_btn_3'     => 'Net balance?',
    'chatbot_quick_btn_4'     => 'Recent transactions',
];
try {
    $stmt = $pdo->prepare("SELECT SettingKey, SettingValue FROM system_settings WHERE SettingKey LIKE 'chatbot_%'");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $chatbotConfig[$row['SettingKey']] = $row['SettingValue'];
    }
} catch (Exception $e) {}

// Category icons
$categoryIcons = [
    'General' => 'fa-cog',
    'Financial' => 'fa-rupee-sign',
    'Security' => 'fa-shield-alt',
    'Email' => 'fa-envelope',
    'Backup' => 'fa-database',
    'Display' => 'fa-desktop',
    'Advanced' => 'fa-sliders-h'
];

// Category colors
$categoryColors = [
    'General' => '#667eea',
    'Financial' => '#10b981',
    'Security' => '#ef4444',
    'Email' => '#3b82f6',
    'Backup' => '#f59e0b',
    'Display' => '#8b5cf6',
    'Advanced' => '#6366f1'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — SR Colony Super Admin</title>
    <link rel="stylesheet" href="shared-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
/* ══════════════════════════════════════════════════════
   Advanced Settings — SR Colony Super Admin
   ══════════════════════════════════════════════════════ */

/* Layout */
.adv-wrap {
    display: flex;
    height: calc(100vh - 60px);
    overflow: hidden;
}

/* ── Sidebar ─────────────────────────────────────────── */
.adv-sidebar {
    width: 250px;
    min-width: 250px;
    background: #1a1d30;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    overflow-x: hidden;
    border-right: 1px solid rgba(255,255,255,.06);
    scrollbar-width: thin;
    scrollbar-color: rgba(255,255,255,.08) transparent;
}
.adv-sidebar::-webkit-scrollbar { width: 3px; }
.adv-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius: 4px; }

.adv-sb-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 18px 16px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    flex-shrink: 0;
}
.adv-sb-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: linear-gradient(135deg, #5a4fcf, #7c73e6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: #fff;
    flex-shrink: 0;
}
.adv-sb-title { font-size: 15px; font-weight: 700; color: #e8e8f4; line-height: 1.2; }
.adv-sb-sub   { font-size: 11px; color: #5a5c7a; margin-top: 1px; }

.adv-sb-search {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 14px 14px 8px;
    padding: 8px 12px;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 10px;
    flex-shrink: 0;
}
.adv-sb-search i { color: #5a5c7a; font-size: 12px; flex-shrink: 0; }
.adv-sb-search input {
    background: transparent;
    border: none;
    outline: none;
    color: #c4c4e0;
    font-size: 13px;
    width: 100%;
    font-family: inherit;
}
.adv-sb-search input::placeholder { color: #4a4c6a; }

.adv-sb-alert {
    margin: 0 14px 8px;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 12px;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    line-height: 1.4;
    flex-shrink: 0;
}
.adv-sb-alert i { flex-shrink: 0; margin-top: 1px; }
.adv-sb-alert-success { background: rgba(16,185,129,.15); color: #6ee7b7; border: 1px solid rgba(16,185,129,.3); }
.adv-sb-alert-error   { background: rgba(239,68,68,.15);  color: #fca5a5; border: 1px solid rgba(239,68,68,.3); }

.adv-nav { padding: 4px 0 8px; flex-shrink: 0; }
.adv-nav-label {
    font-size: 10px;
    font-weight: 600;
    color: #3a3c5a;
    text-transform: uppercase;
    letter-spacing: 1px;
    padding: 10px 18px 4px;
}
.adv-nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 18px;
    color: #7b7d9d;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all .2s;
    border-left: 3px solid transparent;
    margin: 1px 0;
}
.adv-nav-item i { width: 16px; text-align: center; font-size: 13px; flex-shrink: 0; }
.adv-nav-item span:first-of-type { flex: 1; }
.adv-nav-item:hover { color: #c4c4e0; background: rgba(255,255,255,.04); }
.adv-nav-item.active {
    color: #a89ff5;
    background: rgba(124,115,230,.12);
    border-left-color: #7c73e6;
}

.adv-nav-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 10px;
    background: rgba(255,255,255,.08);
    color: #7b7d9d;
    flex-shrink: 0;
}
.adv-badge-ok   { background: rgba(16,185,129,.2); color: #6ee7b7; }
.adv-badge-warn { background: rgba(245,158,11,.2);  color: #fcd34d; }
.adv-badge-err  { background: rgba(239,68,68,.2);   color: #fca5a5; }

.adv-sb-stats {
    margin-top: auto;
    padding: 14px;
    border-top: 1px solid rgba(255,255,255,.06);
    display: grid;
    grid-template-columns: repeat(3,1fr);
    gap: 8px;
    flex-shrink: 0;
}
.adv-sb-stat { text-align: center; }
.adv-sb-stat-n { font-size: 18px; font-weight: 700; color: #a89ff5; line-height: 1; }
.adv-sb-stat-l { font-size: 10px; color: #5a5c7a; margin-top: 3px; }

/* ── Main Content ─────────────────────────────────────── */
.adv-main {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    background: var(--bg-primary, #f8fafc);
}

.adv-panel { display: none; padding: 28px 32px; min-height: 100%; }
.adv-panel.active { display: block; }

.adv-panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 24px;
    padding-bottom: 18px;
    border-bottom: 2px solid var(--border-color, #e5e7eb);
    flex-wrap: wrap;
    gap: 12px;
}
.adv-panel-title {
    font-size: 24px;
    font-weight: 700;
    color: var(--text-primary, #111);
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}
.adv-panel-title i { color: #7c73e6; }
.adv-panel-sub { font-size: 14px; color: var(--text-secondary, #6b7280); margin: 5px 0 0; }

/* Cards */
.adv-card {
    background: var(--bg-secondary, #fff);
    border-radius: 14px;
    border: 1px solid var(--border-color, #e5e7eb);
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    margin-bottom: 20px;
    overflow: hidden;
}
.adv-card-head {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 22px;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
}
.adv-card-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: #fff;
    flex-shrink: 0;
}
.adv-card-title { font-size: 17px; font-weight: 600; color: var(--text-primary, #111); margin: 0; line-height: 1.2; }
.adv-card-desc  { font-size: 14px; color: var(--text-secondary, #6b7280); margin-top: 3px; }
.adv-card-body  { padding: 22px; }

/* Row layout in card */
.adv-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 0;
    border-bottom: 1px solid var(--border-light, #f3f4f6);
}
.adv-row:last-child { border-bottom: none; padding-bottom: 0; }
.adv-row-label { flex: 1; min-width: 0; }
.adv-row-label strong { display: block; font-size: 15px; color: var(--text-primary, #111); font-weight: 600; margin-bottom: 4px; }
.adv-row-label p { font-size: 13px; color: var(--text-secondary, #6b7280); margin: 0; line-height: 1.6; }
.adv-row-ctrl { flex-shrink: 0; display: flex; align-items: center; gap: 10px; }

/* Inputs */
.adv-input {
    padding: 10px 15px;
    border: 1.5px solid var(--border-color, #e5e7eb);
    border-radius: 9px;
    background: var(--bg-tertiary, #f9fafb);
    color: var(--text-primary, #111);
    font-size: 14px;
    font-family: inherit;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.adv-input:focus {
    border-color: #7c73e6;
    box-shadow: 0 0 0 3px rgba(124,115,230,.1);
    background: var(--bg-secondary, #fff);
}
.adv-input-mono { font-family: 'Courier New', monospace; }
.adv-input-wide { width: 100%; box-sizing: border-box; }
.adv-input-sm { width: 80px; text-align: center; }

.adv-select {
    padding: 9px 32px 9px 12px;
    border: 1.5px solid var(--border-color, #e5e7eb);
    border-radius: 9px;
    background: var(--bg-tertiary, #f9fafb);
    color: var(--text-primary, #111);
    font-size: 13px;
    font-family: inherit;
    outline: none;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
}
.adv-select:focus { border-color: #7c73e6; box-shadow: 0 0 0 3px rgba(124,115,230,.1); }

/* Toggle Switch */
.adv-toggle {
    position: relative;
    width: 46px;
    height: 26px;
    flex-shrink: 0;
    display: inline-block;
}
.adv-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.adv-toggle-track {
    position: absolute;
    inset: 0;
    background: var(--border-color, #d1d5db);
    border-radius: 26px;
    cursor: pointer;
    transition: background .3s;
}
.adv-toggle-track::before {
    content: '';
    position: absolute;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #fff;
    top: 3px;
    left: 3px;
    transition: transform .3s;
    box-shadow: 0 1px 4px rgba(0,0,0,.2);
}
.adv-toggle input:checked + .adv-toggle-track { background: #7c73e6; }
.adv-toggle input:checked + .adv-toggle-track::before { transform: translateX(20px); }

/* Buttons */
.adv-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 20px;
    border-radius: 9px;
    border: none;
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: all .2s;
    text-decoration: none;
    white-space: nowrap;
}
.adv-btn-primary {
    background: linear-gradient(135deg, #5a4fcf, #7c73e6);
    color: #fff;
    box-shadow: 0 2px 8px rgba(124,115,230,.35);
}
.adv-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(124,115,230,.45); }
.adv-btn-secondary {
    background: var(--bg-tertiary, #f3f4f6);
    color: var(--text-primary, #374151);
    border: 1.5px solid var(--border-color, #e5e7eb);
}
.adv-btn-secondary:hover { background: var(--border-color, #e5e7eb); }
.adv-btn-danger {
    background: rgba(239,68,68,.1);
    color: #ef4444;
    border: 1.5px solid rgba(239,68,68,.3);
}
.adv-btn-danger:hover { background: rgba(239,68,68,.18); }
.adv-btn-sm { padding: 6px 12px; font-size: 12px; }

/* Action bar */
.adv-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 16px 22px;
    background: var(--bg-tertiary, #f9fafb);
    border-top: 1px solid var(--border-color, #e5e7eb);
    flex-wrap: wrap;
}
.adv-actions-right { margin-left: auto; }

/* Status pill */
.adv-status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.adv-status-ok   { background: rgba(16,185,129,.12); color: #059669; }
.adv-status-warn { background: rgba(245,158,11,.12);  color: #d97706; }
.adv-status-err  { background: rgba(239,68,68,.12);   color: #dc2626; }
.adv-status-dot  { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

/* Logo grid */
.adv-logo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px,1fr));
    gap: 18px;
    padding: 22px;
}
.adv-logo-card {
    border: 2px dashed var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 18px;
    transition: border-color .2s;
    background: var(--bg-secondary, #fff);
}
.adv-logo-card:hover { border-color: #7c73e6; }
.adv-logo-card.drag-over { border-color: #7c73e6; background: rgba(124,115,230,.05); }
.adv-logo-card-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
}
.adv-logo-card-title { font-weight: 600; font-size: 14px; color: var(--text-primary, #111); }
.adv-logo-preview {
    width: 100%;
    height: 110px;
    background: var(--bg-tertiary, #f9fafb);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 14px;
    overflow: hidden;
    position: relative;
}
.adv-logo-preview img { max-width: 90%; max-height: 100px; object-fit: contain; }
.adv-logo-preview .adv-no-logo {
    color: var(--text-secondary, #9ca3af);
    font-size: 13px;
    text-align: center;
}
.adv-logo-preview .adv-no-logo i { font-size: 30px; display: block; margin-bottom: 6px; opacity: .4; }
.adv-logo-actions { display: flex; gap: 8px; }
.adv-logo-desc { font-size: 11px; color: var(--text-secondary, #9ca3af); margin-top: 10px; line-height: 1.5; }
.adv-upload-progress { display: none; margin-top: 10px; }
.adv-upload-progress.active { display: block; }
.adv-progress-bar { height: 3px; background: var(--border-color, #e5e7eb); border-radius: 3px; overflow: hidden; }
.adv-progress-fill { height: 100%; background: linear-gradient(90deg,#5a4fcf,#7c73e6); width: 0%; transition: width .3s; }

/* System settings table */
.adv-settings-table { width: 100%; border-collapse: collapse; }
.adv-settings-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--text-secondary, #6b7280);
    background: var(--bg-tertiary, #f9fafb);
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    white-space: nowrap;
}
.adv-settings-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-light, #f3f4f6);
    vertical-align: middle;
    font-size: 14px;
}
.adv-settings-table tr:last-child td { border-bottom: none; }
.adv-settings-table tr:hover td { background: var(--hover-bg, #fafafa); }
.adv-settings-table .adv-key {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    color: var(--text-secondary, #6b7280);
    max-width: 200px;
    word-break: break-all;
}
.adv-settings-table .adv-key strong { display: block; font-family: inherit; color: var(--text-primary, #111); font-size: 14px; }
.adv-settings-table .adv-last { font-size: 12px; color: var(--text-secondary, #9ca3af); }
.adv-settings-table input { width: 100%; max-width: 320px; }

/* Audit table */
.adv-audit-table { width: 100%; border-collapse: collapse; }
.adv-audit-table th {
    text-align: left;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--text-secondary, #6b7280);
    background: var(--bg-tertiary, #f9fafb);
    border-bottom: 2px solid var(--border-color, #e5e7eb);
}
.adv-audit-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-light, #f3f4f6);
    font-size: 14px;
    color: var(--text-primary, #374151);
    vertical-align: middle;
}
.adv-audit-table tr:last-child td { border-bottom: none; }
.adv-audit-empty { text-align: center; padding: 40px !important; color: var(--text-secondary, #9ca3af); }

/* Search highlight */
.adv-hidden-by-search { display: none !important; }

/* API key group */
.adv-key-group { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; width: 100%; }
.adv-key-group .adv-input { flex: 1; min-width: 200px; }

/* Quick btn grid */
.adv-qbtn-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; }

/* Responsive */
@media (max-width: 900px) {
    .adv-sidebar { width: 200px; min-width: 200px; }
    .adv-panel { padding: 20px 18px; }
}
@media (max-width: 680px) {
    .adv-wrap { flex-direction: column; height: auto; }
    .adv-sidebar { width: 100%; max-height: 280px; }
    .adv-main { overflow: visible; }
    .adv-panel { display: block !important; }
}
@media print { .adv-sidebar { display: none; } .adv-panel { display: block !important; } }

/* Toast */
.adv-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 14px 20px;
    border-radius: 10px;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    z-index: 99999;
    box-shadow: 0 4px 20px rgba(0,0,0,.2);
    animation: advToastIn .3s ease;
    max-width: 360px;
}
.adv-toast-success { background: #059669; }
.adv-toast-error   { background: #dc2626; }
@keyframes advToastIn { from { opacity:0; transform:translateX(40px); } to { opacity:1; transform:translateX(0); } }
@keyframes advToastOut { from { opacity:1; transform:translateX(0); } to { opacity:0; transform:translateX(40px); } }
</style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="adv-wrap">

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<aside class="adv-sidebar">

    <div class="adv-sb-brand">
        <div class="adv-sb-icon"><i class="fas fa-sliders-h"></i></div>
        <div>
            <div class="adv-sb-title">Settings</div>
            <div class="adv-sb-sub">SR Colony · Super Admin</div>
        </div>
    </div>

    <div class="adv-sb-search">
        <i class="fas fa-search"></i>
        <input type="text" id="adv-search" placeholder="Search settings..." autocomplete="off">
    </div>

    <?php if ($message): ?>
    <div class="adv-sb-alert adv-sb-alert-<?php echo $messageType; ?>">
        <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <nav class="adv-nav">
        <div class="adv-nav-label">Configure</div>

        <a class="adv-nav-item active" data-panel="branding" href="#branding">
            <i class="fas fa-palette"></i>
            <span>Branding</span>
            <span class="adv-nav-badge">Logos</span>
        </a>

        <a class="adv-nav-item" data-panel="system" href="#system">
            <i class="fas fa-cog"></i>
            <span>System Settings</span>
            <span class="adv-nav-badge"><?php echo count($allSettings ?? []); ?></span>
        </a>

        <a class="adv-nav-item" data-panel="chatbot" href="#chatbot">
            <i class="fas fa-robot"></i>
            <span>AI Chatbot</span>
            <?php if ($currentGeminiKey): ?>
            <span class="adv-nav-badge adv-badge-ok"><i class="fas fa-circle" style="font-size:7px;"></i> ON</span>
            <?php else: ?>
            <span class="adv-nav-badge adv-badge-warn">!</span>
            <?php endif; ?>
        </a>

        <div class="adv-nav-label">Monitor</div>

        <a class="adv-nav-item" data-panel="audit" href="#audit">
            <i class="fas fa-history"></i>
            <span>Activity Log</span>
            <?php if (!empty($recentChanges)): ?>
            <span class="adv-nav-badge"><?php echo count($recentChanges); ?></span>
            <?php endif; ?>
        </a>
    </nav>

    <div class="adv-sb-stats">
        <div class="adv-sb-stat">
            <div class="adv-sb-stat-n"><?php echo count($allSettings ?? []); ?></div>
            <div class="adv-sb-stat-l">Settings</div>
        </div>
        <div class="adv-sb-stat">
            <div class="adv-sb-stat-n"><?php echo count($settingsByCategory ?? []); ?></div>
            <div class="adv-sb-stat-l">Categories</div>
        </div>
        <div class="adv-sb-stat">
            <div class="adv-sb-stat-n"><?php echo count($recentChanges ?? []); ?></div>
            <div class="adv-sb-stat-l">Changes</div>
        </div>
    </div>

</aside>

<!-- ═══════════════ MAIN CONTENT ═══════════════ -->
<main class="adv-main">

<!-- ──────────── PANEL: BRANDING ──────────── -->
<section class="adv-panel active" id="panel-branding">
    <div class="adv-panel-header">
        <div>
            <h1 class="adv-panel-title"><i class="fas fa-palette"></i> Branding</h1>
            <p class="adv-panel-sub">Upload logos and manage your site's visual identity</p>
        </div>
    </div>

    <!-- Site name quick-edit -->
    <?php
    $siteNameSetting = null;
    foreach (($allSettings ?? []) as $s) {
        if (in_array($s['SettingKey'], ['site_name','sitename','SiteName'])) { $siteNameSetting = $s; break; }
    }
    if ($siteName || $siteNameSetting): ?>
    <div class="adv-card" style="margin-bottom:20px;">
        <div class="adv-card-head">
            <div class="adv-card-icon" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);">
                <i class="fas fa-signature"></i>
            </div>
            <div>
                <div class="adv-card-title">Site Name</div>
                <div class="adv-card-desc">Displayed in the browser tab, header, and notifications</div>
            </div>
        </div>
        <div class="adv-card-body">
            <form method="POST" action="">
                <div class="adv-row" style="border:none;padding:0;">
                    <div class="adv-row-label">
                        <strong>Current Site Name</strong>
                        <p>Shown throughout the portal as your organisation's display name</p>
                    </div>
                    <div class="adv-row-ctrl" style="flex:1;max-width:360px;">
                        <?php if ($siteNameSetting): ?>
                        <input class="adv-input adv-input-wide" type="text"
                            name="settings[<?php echo htmlspecialchars($siteNameSetting['SettingKey']); ?>]"
                            value="<?php echo htmlspecialchars($siteNameSetting['SettingValue']); ?>"
                            placeholder="SR Colony">
                        <?php else: ?>
                        <input class="adv-input adv-input-wide" type="text" value="<?php echo htmlspecialchars($siteName); ?>" disabled>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($siteNameSetting): ?>
                <div style="margin-top:16px;">
                    <button type="submit" name="save_settings" class="adv-btn adv-btn-primary">
                        <i class="fas fa-save"></i> Save Name
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Logo cards -->
    <div class="adv-card">
        <div class="adv-card-head">
            <div class="adv-card-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                <i class="fas fa-images"></i>
            </div>
            <div>
                <div class="adv-card-title">Logo Management</div>
                <div class="adv-card-desc">Drag &amp; drop or click to upload. Accepted formats: PNG, JPG, SVG, ICO, GIF</div>
            </div>
        </div>
        <div class="adv-logo-grid">

            <!-- Website Logo -->
            <div class="adv-logo-card" id="website_logo-card"
                 ondragover="advDragOver(event,this)" ondragleave="advDragLeave(this)"
                 ondrop="advDrop(event,'website_logo',this)">
                <div class="adv-logo-card-head">
                    <span class="adv-logo-card-title">Website Logo</span>
                    <span class="adv-status adv-status-ok" style="font-size:10px;">Public</span>
                </div>
                <div class="adv-logo-preview" id="website_logo-preview">
                    <?php if (!empty($websiteLogo)): ?>
                        <img src="<?php echo htmlspecialchars($websiteLogo); ?>" alt="Website Logo">
                    <?php else: ?>
                        <div class="adv-no-logo"><i class="fas fa-globe"></i>No logo uploaded</div>
                    <?php endif; ?>
                </div>
                <div class="adv-logo-actions">
                    <input type="file" id="website_logo-input" accept="image/*" style="display:none;">
                    <button type="button" class="adv-btn adv-btn-primary" style="flex:1;justify-content:center;"
                        onclick="document.getElementById('website_logo-input').click()">
                        <i class="fas fa-upload"></i> Upload
                    </button>
                    <button type="button" class="adv-btn adv-btn-danger adv-btn-sm"
                        onclick="advRemoveLogo('website_logo')" <?php echo empty($websiteLogo) ? 'disabled' : ''; ?>>
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <div class="adv-upload-progress" id="website_logo-progress">
                    <div class="adv-progress-bar"><div class="adv-progress-fill"></div></div>
                </div>
                <p class="adv-logo-desc">Login pages, public pages, email templates. Recommended: 200×80 px</p>
            </div>

            <!-- System Logo -->
            <div class="adv-logo-card" id="system_logo-card"
                 ondragover="advDragOver(event,this)" ondragleave="advDragLeave(this)"
                 ondrop="advDrop(event,'system_logo',this)">
                <div class="adv-logo-card-head">
                    <span class="adv-logo-card-title">System Logo</span>
                    <span class="adv-status" style="background:rgba(99,102,241,.12);color:#4f46e5;font-size:10px;">Internal</span>
                </div>
                <div class="adv-logo-preview" id="system_logo-preview">
                    <?php if (!empty($systemLogo)): ?>
                        <img src="<?php echo htmlspecialchars($systemLogo); ?>" alt="System Logo">
                    <?php else: ?>
                        <div class="adv-no-logo"><i class="fas fa-desktop"></i>No logo uploaded</div>
                    <?php endif; ?>
                </div>
                <div class="adv-logo-actions">
                    <input type="file" id="system_logo-input" accept="image/*" style="display:none;">
                    <button type="button" class="adv-btn adv-btn-primary" style="flex:1;justify-content:center;"
                        onclick="document.getElementById('system_logo-input').click()">
                        <i class="fas fa-upload"></i> Upload
                    </button>
                    <button type="button" class="adv-btn adv-btn-danger adv-btn-sm"
                        onclick="advRemoveLogo('system_logo')" <?php echo empty($systemLogo) ? 'disabled' : ''; ?>>
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <div class="adv-upload-progress" id="system_logo-progress">
                    <div class="adv-progress-bar"><div class="adv-progress-fill"></div></div>
                </div>
                <p class="adv-logo-desc">Navbar, sidebar, dashboard headers. Recommended: 150×50 px</p>
            </div>

            <!-- Favicon -->
            <div class="adv-logo-card" id="favicon-card"
                 ondragover="advDragOver(event,this)" ondragleave="advDragLeave(this)"
                 ondrop="advDrop(event,'favicon',this)">
                <div class="adv-logo-card-head">
                    <span class="adv-logo-card-title">Favicon</span>
                    <span class="adv-status adv-status-warn" style="font-size:10px;">Browser</span>
                </div>
                <div class="adv-logo-preview" id="favicon-preview">
                    <?php if (!empty($favicon)): ?>
                        <img src="<?php echo htmlspecialchars($favicon); ?>" alt="Favicon" style="max-height:64px;">
                    <?php else: ?>
                        <div class="adv-no-logo"><i class="fas fa-star"></i>No favicon uploaded</div>
                    <?php endif; ?>
                </div>
                <div class="adv-logo-actions">
                    <input type="file" id="favicon-input" accept=".ico,.png,.svg,.gif" style="display:none;">
                    <button type="button" class="adv-btn adv-btn-primary" style="flex:1;justify-content:center;"
                        onclick="document.getElementById('favicon-input').click()">
                        <i class="fas fa-upload"></i> Upload
                    </button>
                    <button type="button" class="adv-btn adv-btn-danger adv-btn-sm"
                        onclick="advRemoveLogo('favicon')" <?php echo empty($favicon) ? 'disabled' : ''; ?>>
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <div class="adv-upload-progress" id="favicon-progress">
                    <div class="adv-progress-bar"><div class="adv-progress-fill"></div></div>
                </div>
                <p class="adv-logo-desc">Browser tab icon. Recommended: 32×32 or 64×64 px ICO/PNG</p>
            </div>

        </div>
    </div>
</section>

<!-- ──────────── PANEL: SYSTEM SETTINGS ──────────── -->
<section class="adv-panel" id="panel-system">
    <div class="adv-panel-header">
        <div>
            <h1 class="adv-panel-title"><i class="fas fa-cog"></i> System Settings</h1>
            <p class="adv-panel-sub">All database-backed configuration values, grouped by category</p>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <div style="position:relative;">
                <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:12px;"></i>
                <input type="text" id="sys-search" class="adv-input" placeholder="Filter settings…"
                    style="padding-left:32px;min-width:200px;" oninput="filterSettings(this.value)">
            </div>
        </div>
    </div>

    <?php if (empty($settingsByCategory)): ?>
    <div class="adv-card">
        <div class="adv-card-body" style="text-align:center;padding:40px;color:var(--text-secondary);">
            <i class="fas fa-inbox" style="font-size:32px;opacity:.3;display:block;margin-bottom:12px;"></i>
            No settings found in the database.
        </div>
    </div>
    <?php else: ?>
    <form method="POST" action="" id="sys-form">
        <?php foreach (($settingsByCategory ?? []) as $category => $catSettings): ?>
        <?php
        // Skip chatbot-specific settings here
        $filteredSettings = array_filter($catSettings, function($s) {
            return strpos($s['SettingKey'], 'chatbot_') !== 0;
        });
        if (empty($filteredSettings)) continue;
        ?>
        <div class="adv-card adv-sys-group" style="margin-bottom:18px;">
            <div class="adv-card-head" style="cursor:pointer;" onclick="toggleGroup(this)">
                <div class="adv-card-icon" style="background:<?php echo $categoryColors[$category] ?? '#667eea'; ?>;">
                    <i class="fas <?php echo $categoryIcons[$category] ?? 'fa-cog'; ?>"></i>
                </div>
                <div style="flex:1;">
                    <div class="adv-card-title"><?php echo htmlspecialchars($category); ?></div>
                    <div class="adv-card-desc"><?php echo count($filteredSettings); ?> setting<?php echo count($filteredSettings) !== 1 ? 's' : ''; ?></div>
                </div>
                <i class="fas fa-chevron-down adv-group-chevron" style="color:var(--text-secondary);transition:transform .2s;"></i>
            </div>
            <div class="adv-group-body">
                <div style="overflow-x:auto;">
                <table class="adv-settings-table">
                    <thead>
                        <tr>
                            <th style="width:36%">Setting</th>
                            <th>Value</th>
                            <th style="width:160px;">Last Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($filteredSettings as $setting): ?>
                    <tr class="adv-sys-row" data-key="<?php echo htmlspecialchars(strtolower($setting['SettingKey'])); ?>">
                        <td>
                            <div class="adv-key">
                                <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $setting['SettingKey']))); ?></strong>
                                <?php echo htmlspecialchars($setting['SettingKey']); ?>
                            </div>
                        </td>
                        <td>
                            <?php
                            $k = "settings[{$setting['SettingKey']}]";
                            $v = htmlspecialchars($setting['SettingValue'], ENT_QUOTES);
                            $dt = $setting['DataType'] ?? 'text';
                            if ($dt === 'boolean'):
                            ?>
                            <label class="adv-toggle" style="margin:2px 0;">
                                <input type="checkbox" name="<?php echo $k; ?>" value="true"
                                    <?php echo $setting['SettingValue'] === 'true' ? 'checked' : ''; ?>>
                                <span class="adv-toggle-track"></span>
                            </label>
                            <?php elseif ($dt === 'select' && $setting['SettingKey'] === 'link_target_behavior'): ?>
                            <select name="<?php echo $k; ?>" class="adv-select">
                                <option value="_self"  <?php echo $setting['SettingValue']==='_self'  ? 'selected':''; ?>>Same Tab (_self)</option>
                                <option value="_blank" <?php echo $setting['SettingValue']==='_blank' ? 'selected':''; ?>>New Tab (_blank)</option>
                            </select>
                            <?php elseif ($dt === 'integer' || $dt === 'decimal'): ?>
                            <input class="adv-input adv-input-sm" type="number"
                                name="<?php echo $k; ?>" value="<?php echo $v; ?>"
                                step="<?php echo $dt === 'decimal' ? '0.01' : '1'; ?>">
                            <?php elseif ($dt === 'email'): ?>
                            <input class="adv-input" type="email" name="<?php echo $k; ?>" value="<?php echo $v; ?>" style="min-width:220px;">
                            <?php else: ?>
                            <input class="adv-input" type="text" name="<?php echo $k; ?>" value="<?php echo $v; ?>" style="min-width:220px;">
                            <?php endif; ?>
                        </td>
                        <td class="adv-last">
                            <?php if (!empty($setting['UpdatedAt'])): ?>
                                <?php echo date('d M Y', strtotime($setting['UpdatedAt'])); ?><br>
                                <span style="opacity:.7;"><?php echo date('h:i A', strtotime($setting['UpdatedAt'])); ?></span>
                                <?php if (!empty($setting['UpdatedByUser'])): ?>
                                <br><span style="opacity:.6;">by <?php echo htmlspecialchars($setting['UpdatedByUser']); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="opacity:.4;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div style="display:flex;gap:12px;padding:4px 0 24px;flex-wrap:wrap;">
            <button type="submit" name="save_settings" class="adv-btn adv-btn-primary">
                <i class="fas fa-save"></i> Save All Settings
            </button>
            <button type="reset" class="adv-btn adv-btn-secondary">
                <i class="fas fa-undo"></i> Reset
            </button>
            <span id="sys-saved-msg" style="display:flex;align-items:center;font-size:13px;"></span>
        </div>
    </form>
    <?php endif; ?>
</section>

<!-- ──────────── PANEL: AI CHATBOT ──────────── -->
<section class="adv-panel" id="panel-chatbot">
    <div class="adv-panel-header">
        <div>
            <h1 class="adv-panel-title"><i class="fas fa-robot"></i> AI Chatbot</h1>
            <p class="adv-panel-sub">Configure the Groq-powered assistant that answers questions from your live data</p>
        </div>
        <?php if ($currentGeminiKey): ?>
        <span class="adv-status adv-status-ok"><span class="adv-status-dot"></span> Active</span>
        <?php else: ?>
        <span class="adv-status adv-status-err"><span class="adv-status-dot"></span> Not configured</span>
        <?php endif; ?>
    </div>

    <!-- API Key -->
    <div class="adv-card" style="margin-bottom:20px;">
        <div class="adv-card-head">
            <div class="adv-card-icon" style="background:linear-gradient(135deg,#5a4fcf,#7c73e6);">
                <i class="fas fa-key"></i>
            </div>
            <div>
                <div class="adv-card-title">Chatbot API Key</div>
                <div class="adv-card-desc">Enter your Groq API key — stored encrypted in the database</div>
            </div>
        </div>
        <form method="POST" action="#chatbot" onsubmit="return confirmApiKeySave()">
        <div class="adv-card-body">
            <div class="adv-row" style="padding-top:0;">
                <div class="adv-row-label">
                    <strong>Groq API Key</strong>
                    <p>Get a free key at <a href="https://console.groq.com" target="_blank" rel="noopener" style="color:#7c73e6;">console.groq.com</a>. Prefix usually starts with <code style="background:var(--bg-tertiary);padding:1px 5px;border-radius:3px;">gsk_</code></p>
                </div>
                <div class="adv-row-ctrl" style="flex:1;max-width:480px;">
                    <div class="adv-key-group">
                        <input type="password" id="gemini_api_key" name="gemini_api_key"
                            class="adv-input adv-input-mono"
                            value="<?php echo htmlspecialchars($currentGeminiKey, ENT_QUOTES); ?>"
                            placeholder="gsk_…" autocomplete="off" style="flex:1;min-width:0;">
                        <button type="button" class="adv-btn adv-btn-secondary adv-btn-sm" onclick="toggleKeyVisibility()" id="key-vis-btn">
                            <i class="fas fa-eye" id="gemini-eye-icon"></i>
                        </button>
                        <button type="button" class="adv-btn adv-btn-secondary adv-btn-sm" onclick="copyApiKey()" title="Copy key">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    <?php if ($currentGeminiKey): ?>
                    <div style="margin-top:8px;">
                        <span class="adv-status adv-status-ok">
                            <span class="adv-status-dot"></span>
                            Key configured &nbsp;
                            <code style="font-size:10px;"><?php echo strlen($currentGeminiKey) > 8 ? substr($currentGeminiKey,0,4).'…'.substr($currentGeminiKey,-4) : '****'; ?></code>
                        </span>
                    </div>
                    <?php else: ?>
                    <div style="margin-top:8px;">
                        <span class="adv-status adv-status-err"><span class="adv-status-dot"></span> No key — chatbot is inactive</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="adv-actions">
            <button type="submit" name="save_gemini_key" class="adv-btn adv-btn-primary">
                <i class="fas fa-save"></i> Save API Key
            </button>
            <button type="button" class="adv-btn adv-btn-secondary" onclick="testApiKey()">
                <i class="fas fa-vial"></i> Test Connection
            </button>
            <span id="gemini-test-result" style="font-size:13px;"></span>
        </div>
        </form>
    </div>

    <!-- Behavior Settings -->
    <div class="adv-card" style="margin-bottom:20px;">
        <div class="adv-card-head">
            <div class="adv-card-icon" style="background:linear-gradient(135deg,#10b981,#059669);">
                <i class="fas fa-sliders-h"></i>
            </div>
            <div>
                <div class="adv-card-title">Behavior &amp; History</div>
                <div class="adv-card-desc">Configure how the chatbot stores and presents conversations</div>
            </div>
        </div>
        <form method="POST" action="#chatbot">
        <div class="adv-card-body">

            <!-- History toggle -->
            <div class="adv-row" style="padding-top:0;">
                <div class="adv-row-label">
                    <strong>Chat History</strong>
                    <p>Save conversations in the browser. Data stays local — nothing is sent to the server.</p>
                </div>
                <div class="adv-row-ctrl">
                    <label class="adv-toggle">
                        <input type="checkbox" name="chatbot_history_enabled" value="1"
                            <?php echo ($chatbotConfig['chatbot_history_enabled'] === '1') ? 'checked' : ''; ?>>
                        <span class="adv-toggle-track"></span>
                    </label>
                </div>
            </div>

            <!-- History duration -->
            <div class="adv-row">
                <div class="adv-row-label">
                    <strong>Retention Period</strong>
                    <p>How many days to keep chat history before it auto-expires (1–30 days).</p>
                </div>
                <div class="adv-row-ctrl">
                    <input type="number" name="chatbot_history_days" class="adv-input adv-input-sm"
                        value="<?php echo intval($chatbotConfig['chatbot_history_days']); ?>" min="1" max="30">
                    <span style="font-size:13px;color:var(--text-secondary);">days</span>
                </div>
            </div>

            <!-- Auto-open -->
            <div class="adv-row" style="padding-bottom:0;border-bottom:none;">
                <div class="adv-row-label">
                    <strong>Auto-Open on Page Load</strong>
                    <p>Automatically open the chatbot widget when any portal page loads.</p>
                </div>
                <div class="adv-row-ctrl">
                    <label class="adv-toggle">
                        <input type="checkbox" name="chatbot_auto_open" value="1"
                            <?php echo ($chatbotConfig['chatbot_auto_open'] === '1') ? 'checked' : ''; ?>>
                        <span class="adv-toggle-track"></span>
                    </label>
                </div>
            </div>

        </div>
        <div class="adv-actions">
            <button type="submit" name="save_chatbot_settings" class="adv-btn adv-btn-primary">
                <i class="fas fa-save"></i> Save Settings
            </button>
            <button type="button" class="adv-btn adv-btn-secondary" onclick="clearChatbotHistory()">
                <i class="fas fa-trash-alt"></i> Clear Browser History
            </button>
            <span id="clear-hist-msg" style="font-size:13px;"></span>
        </div>
        </form>
    </div>

    <!-- Quick Action Buttons -->
    <div class="adv-card">
        <div class="adv-card-head">
            <div class="adv-card-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                <i class="fas fa-bolt"></i>
            </div>
            <div>
                <div class="adv-card-title">Quick Action Buttons</div>
                <div class="adv-card-desc">The 4 shortcut buttons shown at the top of the chatbot widget — leave blank to hide</div>
            </div>
        </div>
        <form method="POST" action="#chatbot">
        <div class="adv-card-body">
            <div class="adv-qbtn-grid">
                <?php for ($i = 1; $i <= 4; $i++): ?>
                <div>
                    <label style="font-size:11px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.6px;display:block;margin-bottom:6px;">
                        Button <?php echo $i; ?>
                    </label>
                    <input type="text" name="chatbot_quick_btn_<?php echo $i; ?>"
                        class="adv-input adv-input-wide"
                        value="<?php echo htmlspecialchars($chatbotConfig['chatbot_quick_btn_'.$i], ENT_QUOTES); ?>"
                        placeholder="Button <?php echo $i; ?> label" maxlength="60">
                </div>
                <?php endfor; ?>
            </div>
            <p style="margin:12px 0 0;font-size:11px;color:var(--text-secondary);">
                <i class="fas fa-info-circle" style="color:#7c73e6;"></i>
                Changes take effect on the next page load.
            </p>
        </div>
        <div class="adv-actions">
            <button type="submit" name="save_chatbot_settings" class="adv-btn adv-btn-primary">
                <i class="fas fa-save"></i> Save Buttons
            </button>
        </div>
        </form>
    </div>
</section>

<!-- ──────────── PANEL: ACTIVITY LOG ──────────── -->
<section class="adv-panel" id="panel-audit">
    <div class="adv-panel-header">
        <div>
            <h1 class="adv-panel-title"><i class="fas fa-history"></i> Activity Log</h1>
            <p class="adv-panel-sub">Recent changes made to system settings — all actions are audited</p>
        </div>
    </div>

    <div class="adv-card">
        <div style="overflow-x:auto;">
        <?php if (!empty($recentChanges)): ?>
        <table class="adv-audit-table">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Setting Changed</th>
                    <th>Admin</th>
                    <th>Date &amp; Time</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentChanges as $change): ?>
            <tr>
                <td>
                    <span class="adv-status adv-status-ok" style="font-size:11px;">
                        <i class="fas fa-pencil-alt" style="font-size:9px;"></i>
                        <?php echo htmlspecialchars(ucfirst($change['Action'] ?? 'update')); ?>
                    </span>
                </td>
                <td style="font-family:monospace;font-size:12px;color:var(--text-secondary);">
                    <?php
                    preg_match('/setting: (.+?) =/', $change['TableName'] ?? $change['Description'] ?? '', $m);
                    echo htmlspecialchars($m[1] ?? ($change['TableName'] ?? 'System Setting'));
                    ?>
                </td>
                <td style="font-weight:600;">
                    <i class="fas fa-user-circle" style="color:#7c73e6;margin-right:5px;"></i>
                    <?php echo htmlspecialchars($change['Username'] ?? '—'); ?>
                </td>
                <td class="adv-last">
                    <?php
                    $ts = $change['ActionDate'] ?? $change['CreatedAt'] ?? null;
                    echo $ts ? date('d M Y, h:i A', strtotime($ts)) : '—';
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <table class="adv-audit-table">
            <thead><tr><th>Action</th><th>Setting</th><th>Admin</th><th>Date</th></tr></thead>
            <tbody>
            <tr><td class="adv-audit-empty" colspan="4">
                <i class="fas fa-inbox" style="font-size:28px;display:block;margin-bottom:10px;opacity:.3;"></i>
                No activity recorded yet
            </td></tr>
            </tbody>
        </table>
        <?php endif; ?>
        </div>
    </div>
</section>

</main>
</div><!-- .adv-wrap -->

<script src="theme-manager.js"></script>
<script>
/* ── Tab Navigation ─────────────────────────────── */
document.querySelectorAll('.adv-nav-item').forEach(function(item) {
    item.addEventListener('click', function(e) {
        e.preventDefault();
        const panel = this.dataset.panel;
        document.querySelectorAll('.adv-nav-item').forEach(function(n) { n.classList.remove('active'); });
        document.querySelectorAll('.adv-panel').forEach(function(p) { p.classList.remove('active'); });
        this.classList.add('active');
        const target = document.getElementById('panel-' + panel);
        if (target) target.classList.add('active');
        location.hash = panel;
    });
});

// Restore tab from URL hash
(function() {
    const hash = location.hash.replace('#','');
    if (hash) {
        const link = document.querySelector('.adv-nav-item[data-panel="'+hash+'"]');
        if (link) link.click();
    }
})();

/* ── Global search (sidebar) ─────────────────────── */
document.getElementById('adv-search').addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.adv-nav-item').forEach(function(item) { item.style.display = ''; });
    if (!q) return;
    // Auto-switch to system panel if user is searching
    if (q.length > 1) {
        document.querySelectorAll('.adv-nav-item').forEach(function(n) { n.classList.remove('active'); });
        document.querySelectorAll('.adv-panel').forEach(function(p) { p.classList.remove('active'); });
        const sysLink = document.querySelector('.adv-nav-item[data-panel="system"]');
        const sysPanel = document.getElementById('panel-system');
        if (sysLink) sysLink.classList.add('active');
        if (sysPanel) sysPanel.classList.add('active');
        filterSettings(q);
    }
    // Also trigger chatbot search
    const chatQ = q;
    document.querySelectorAll('#panel-chatbot .adv-row').forEach(function(row) {
        const txt = row.textContent.toLowerCase();
        row.style.display = txt.includes(chatQ) || !chatQ ? '' : 'none';
    });
});

/* ── System settings filter ──────────────────────── */
function filterSettings(q) {
    q = (q || '').toLowerCase().trim();
    document.querySelectorAll('.adv-sys-row').forEach(function(row) {
        const key = row.dataset.key || '';
        const txt = row.textContent.toLowerCase();
        row.classList.toggle('adv-hidden-by-search', !!q && !txt.includes(q) && !key.includes(q));
    });
    // Hide empty groups
    document.querySelectorAll('.adv-sys-group').forEach(function(group) {
        const visible = group.querySelectorAll('.adv-sys-row:not(.adv-hidden-by-search)');
        group.style.display = visible.length === 0 && q ? 'none' : '';
    });
}

const sysSearch = document.getElementById('sys-search');
if (sysSearch) sysSearch.addEventListener('input', function() { filterSettings(this.value); });

/* ── Collapsible groups ──────────────────────────── */
function toggleGroup(head) {
    const body = head.nextElementSibling;
    const chevron = head.querySelector('.adv-group-chevron');
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : '';
    if (chevron) chevron.style.transform = isOpen ? 'rotate(-90deg)' : '';
}

/* ── API Key visibility ──────────────────────────── */
function toggleKeyVisibility() {
    const input = document.getElementById('gemini_api_key');
    const icon  = document.getElementById('gemini-eye-icon');
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}
function copyApiKey() {
    const input = document.getElementById('gemini_api_key');
    if (!input || !input.value) return;
    navigator.clipboard.writeText(input.value).then(function() {
        showToast('API key copied to clipboard', 'success');
    }).catch(function() {
        input.select(); document.execCommand('copy');
        showToast('Copied!', 'success');
    });
}
function confirmApiKeySave() {
    const key = (document.getElementById('gemini_api_key') || {}).value || '';
    if (!key.trim()) return confirm('Save an empty API key? This will disable the AI chatbot.');
    return true;
}

/* ── Test API connection ─────────────────────────── */
const GEMINI_API_URL = new URL('gemini-api.php', window.location.href).href;
function testApiKey() {
    const key = (document.getElementById('gemini_api_key') || {}).value || '';
    const result = document.getElementById('gemini-test-result');
    if (!key.trim()) { result.innerHTML = '<span style="color:#ef4444;">Enter an API key first.</span>'; return; }
    result.innerHTML = '<span style="color:#9ca3af;"><i class="fas fa-spinner fa-spin"></i> Testing…</span>';
    fetch(GEMINI_API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ query: 'Hello, are you working?' })
    })
    .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function(d) {
        if (d.error && d.error !== 'Unauthorized') {
            result.innerHTML = '<span style="color:#ef4444;"><i class="fas fa-times-circle"></i> ' + d.error + '</span>';
        } else if (d.answer) {
            result.innerHTML = '<span style="color:#10b981;"><i class="fas fa-check-circle"></i> Connected!</span>';
        } else {
            result.innerHTML = '<span style="color:#f59e0b;"><i class="fas fa-exclamation-circle"></i> Save key first, then test.</span>';
        }
    })
    .catch(function(err) {
        result.innerHTML = '<span style="color:#ef4444;"><i class="fas fa-times-circle"></i> ' + err.message + '</span>';
    });
}

/* ── Clear chat history ──────────────────────────── */
function clearChatbotHistory() {
    let cleared = 0;
    for (let i = localStorage.length - 1; i >= 0; i--) {
        const k = localStorage.key(i);
        if (k && k.startsWith('gc_hist_')) { localStorage.removeItem(k); cleared++; }
    }
    const msg = document.getElementById('clear-hist-msg');
    if (msg) {
        msg.innerHTML = cleared > 0
            ? '<span style="color:#10b981;"><i class="fas fa-check-circle"></i> Cleared ' + cleared + ' session(s).</span>'
            : '<span style="color:#9ca3af;"><i class="fas fa-info-circle"></i> No history in this browser.</span>';
        setTimeout(function() { msg.innerHTML = ''; }, 4000);
    }
    showToast(cleared > 0 ? 'Chat history cleared' : 'No history found', cleared > 0 ? 'success' : 'error');
}

/* ── Logo upload (drag & drop) ───────────────────── */
function advDragOver(e, el) { e.preventDefault(); el.classList.add('drag-over'); }
function advDragLeave(el)    { el.classList.remove('drag-over'); }
function advDrop(e, type, el) {
    e.preventDefault();
    el.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) advUploadLogo(type, file);
}
function advUploadLogo(type, file) {
    const fd = new FormData();
    fd.append('logo_type', type);
    fd.append('logo_file', file);
    const prog = document.getElementById(type + '-progress');
    const fill = prog ? prog.querySelector('.adv-progress-fill') : null;
    if (prog) { prog.classList.add('active'); }
    if (fill) fill.style.width = '40%';
    fetch('../api/upload-logo.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (fill) fill.style.width = '100%';
        setTimeout(function() {
            if (prog) prog.classList.remove('active');
            if (fill) fill.style.width = '0%';
            if (data.success) {
                const prev = document.getElementById(type + '-preview');
                if (prev) prev.innerHTML = '<img src="' + data.logo_url + '?' + Date.now() + '" alt="Logo">';
                const card = document.getElementById(type + '-card');
                if (card) { const btn = card.querySelector('.adv-btn-danger'); if (btn) btn.disabled = false; }
                showToast('Logo uploaded successfully!', 'success');
            } else {
                showToast(data.message || 'Upload failed', 'error');
            }
        }, 400);
    })
    .catch(function(err) {
        if (prog) prog.classList.remove('active');
        showToast('Upload failed: ' + err.message, 'error');
    });
}
function advRemoveLogo(type) {
    if (!confirm('Remove this logo?')) return;
    const fd = new FormData();
    fd.append('logo_type', type);
    fd.append('action', 'remove');
    fetch('../api/upload-logo.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            const prev = document.getElementById(type + '-preview');
            const icons = { website_logo:'fa-globe', system_logo:'fa-desktop', favicon:'fa-star' };
            if (prev) prev.innerHTML = '<div class="adv-no-logo"><i class="fas ' + (icons[type]||'fa-image') + '"></i>No logo uploaded</div>';
            const card = document.getElementById(type + '-card');
            if (card) { const btn = card.querySelector('.adv-btn-danger'); if (btn) btn.disabled = true; }
            showToast('Logo removed', 'success');
        } else {
            showToast(data.message || 'Removal failed', 'error');
        }
    })
    .catch(function(err) { showToast('Error: ' + err.message, 'error'); });
}

// Attach file input listeners
['website_logo','system_logo','favicon'].forEach(function(type) {
    const el = document.getElementById(type + '-input') || document.getElementById(type.replace('_','-') + '-input');
    if (el) el.addEventListener('change', function(e) {
        if (e.target.files.length > 0) advUploadLogo(type, e.target.files[0]);
    });
});
// Also attach favicon-input with its actual ID
const faviconInput = document.getElementById('favicon-input');
if (faviconInput && !faviconInput._advBound) {
    faviconInput._advBound = true;
    faviconInput.addEventListener('change', function(e) {
        if (e.target.files.length > 0) advUploadLogo('favicon', e.target.files[0]);
    });
}

/* ── Toast notification ──────────────────────────── */
function showToast(msg, type) {
    const existing = document.querySelector('.adv-toast');
    if (existing) existing.remove();
    const t = document.createElement('div');
    t.className = 'adv-toast adv-toast-' + type;
    t.innerHTML = '<i class="fas fa-' + (type==='success'?'check-circle':'exclamation-circle') + '"></i> ' + msg;
    document.body.appendChild(t);
    setTimeout(function() {
        t.style.animation = 'advToastOut .3s ease forwards';
        setTimeout(function() { t.remove(); }, 320);
    }, 3000);
}

// Also wire old showNotification to new showToast for compat
window.showNotification = showToast;

// Auto-dismiss server-side alert after 6s
setTimeout(function() {
    const a = document.querySelector('.adv-sb-alert');
    if (a) { a.style.transition = 'opacity .4s'; a.style.opacity = '0'; setTimeout(function(){ a.remove(); },400); }
}, 6000);

// Scroll to top on any form submit
document.querySelectorAll('form').forEach(function(f) {
    f.addEventListener('submit', function() { window.scrollTo({ top: 0, behavior: 'smooth' }); });
});
</script>

<style>
@keyframes slideIn  { from { transform:translateX(100px); opacity:0; } to { transform:translateX(0); opacity:1; } }
@keyframes slideOut { from { transform:translateX(0); opacity:1; } to { transform:translateX(100px); opacity:0; } }
.adv-group-body { transition: none; }
</style>
</body>
</html>
