<?php
require_once 'session.php';
require_once '../config/config.php';
require_once '../includes/link-target-helper.php';

$page_title = 'Link Behavior Settings';
$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_link_settings'])) {
    try {
        $pdo->beginTransaction();
        
        // Update each page's target behavior
        if (isset($_POST['links']) && is_array($_POST['links'])) {
            foreach ($_POST['links'] as $linkId => $targetBehavior) {
                $stmt = $pdo->prepare("
                    UPDATE link_target_settings 
                    SET TargetBehavior = ?,
                        UpdatedBy = ?,
                        UpdatedAt = NOW()
                    WHERE LinkID = ?
                ");
                $stmt->execute([$targetBehavior, $_SESSION['superadmin_id'], $linkId]);
            }
        }
        
        // Log the change
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (UserID, UserType, Action, TableName, IPAddress)
            VALUES (?, 'SuperAdmin', 'update', 'link_target_settings', ?)
        ");
        $stmt->execute([
            $_SESSION['superadmin_id'],
            $_SERVER['REMOTE_ADDR']
        ]);
        
        $pdo->commit();
        $message = 'Link behavior settings updated successfully!';
        $messageType = 'success';
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Error updating settings: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Register pages added since the table was filled (Academic Years, Net Balance, Accounting, Events, ...)
$pagesAdded = 0;
try {
    $pagesAdded = ltSyncPages($pdo);
    if ($pagesAdded && !$message) {
        $message = $pagesAdded . ' more page(s) found and added below. They open in the same tab until you change them.';
        $messageType = 'success';
    }
} catch (Exception $e) {
    error_log('Link behavior page sync failed: ' . $e->getMessage());
}

// Fetch all link settings grouped by portal
try {
    $stmt = $pdo->query("
        SELECT LinkID, PageName, PageURL, Portal, TargetBehavior, DisplayOrder, IsActive
        FROM link_target_settings
        WHERE IsActive = 1
        ORDER BY Portal, DisplayOrder
    ");
    $allLinks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by portal
    $linksByPortal = [];
    $stats = ['_self' => 0, '_blank' => 0, 'total' => 0];
    
    foreach ($allLinks as $link) {
        $portal = ucfirst($link['Portal']);
        if (!isset($linksByPortal[$portal])) {
            $linksByPortal[$portal] = [];
        }
        $linksByPortal[$portal][] = $link;
        
        // Calculate statistics
        $stats['total']++;
        if ($link['TargetBehavior'] === '_self') {
            $stats['_self']++;
        } else {
            $stats['_blank']++;
        }
    }
} catch (PDOException $e) {
    $linksByPortal = [];
    $stats = ['_self' => 0, '_blank' => 0, 'total' => 0];
    $message = 'Error loading link settings. Please run the database migration first.';
    $messageType = 'error';
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Behavior Settings - Super Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
    <style>
        .link-settings-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .page-header {
            margin-bottom: 30px;
            background: linear-gradient(135deg, var(--gradient-1) 0%, var(--gradient-2) 100%);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(126, 34, 206, 0.3);
            animation: slideDown 0.6s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .page-title {
            font-size: 36px;
            font-weight: 700;
            color: white;
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 0 0 10px 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .page-subtitle {
            color: rgba(255,255,255,0.95);
            font-size: 17px;
            margin: 0;
            font-weight: 500;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            animation: slideDown 0.3s ease;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            border: 2px solid #10b981;
            color: #065f46;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 2px solid #ef4444;
            color: #991b1b;
        }
        
        .portal-section {
            background: var(--bg-secondary);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-lg);
            border: 2px solid transparent;
            transition: all 0.3s ease;
            animation: fadeInUp 0.6s ease;
            position: relative;
            overflow: hidden;
        }
        
        .portal-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #ff6b6b, #4ecdc4, #45b7d1, #96f, #f093fb);
            background-size: 200% 100%;
            animation: rainbowShift 3s linear infinite;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes rainbowShift {
            0% { background-position: 0% 50%; }
            100% { background-position: 200% 50%; }
        }
        
        .portal-section:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }
        
        .portal-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .portal-icon {
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: white;
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .portal-icon.superadmin {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }
        
        .portal-icon.admin {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            box-shadow: 0 8px 20px rgba(240, 147, 251, 0.4);
        }
        
        .portal-icon.member {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            box-shadow: 0 8px 20px rgba(79, 172, 254, 0.4);
        }
        
        .portal-info h2 {
            margin: 0;
            font-size: 26px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 800;
        }
        
        .portal-info h2.admin-title {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .portal-info h2.member-title {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .portal-count {
            font-size: 14px;
            color: rgba(255,255,255,0.9);
            margin-top: 5px;
            padding: 5px 15px;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            display: inline-block;
            font-weight: 600;
        }
        
        .links-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .links-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .links-table th {
            padding: 18px;
            text-align: left;
            font-weight: 700;
            color: white;
            border-bottom: 3px solid rgba(255,255,255,0.3);
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .links-table th.checkbox-column {
            text-align: center;
            width: 120px;
        }
        
        .links-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-light);
            color: var(--text-primary);
        }
        
        .links-table tbody tr {
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        
        .links-table tbody tr:hover {
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-left: 4px solid #667eea;
            transform: translateX(5px);
        }
        
        .page-name {
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .page-name i {
            color: var(--text-secondary);
        }
        
        .page-url {
            font-size: 13px;
            color: var(--text-secondary);
            font-family: 'Courier New', monospace;
            background: var(--bg-tertiary);
            padding: 4px 8px;
            border-radius: 6px;
            display: inline-block;
        }
        
        .checkbox-cell {
            text-align: center;
        }
        
        .custom-checkbox {
            position: relative;
            display: inline-block;
            width: 28px;
            height: 28px;
        }
        
        .custom-checkbox input[type="checkbox"],
        .custom-checkbox input[type="radio"] {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
        }
        
        .checkbox-mark {
            position: absolute;
            top: 0;
            left: 0;
            height: 28px;
            width: 28px;
            background-color: var(--bg-tertiary);
            border: 3px solid var(--border-color);
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .custom-checkbox input[type="radio"]:checked ~ .checkbox-mark {
            border-color: #10b981;
            animation: checkPulse 0.4s ease;
        }
        
        /* Green for _self (same tab) */
        td:nth-child(4) .custom-checkbox input[type="radio"]:checked ~ .checkbox-mark {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-color: #10b981;
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.5);
        }
        
        /* Blue for _blank (new tab) */
        td:nth-child(5) .custom-checkbox input[type="radio"]:checked ~ .checkbox-mark {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border-color: #3b82f6;
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.5);
        }
        
        @keyframes checkPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        .checkbox-mark:after {
            content: "";
            position: absolute;
            display: none;
            left: 8px;
            top: 8px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: white;
        }
        
        .custom-checkbox input[type="radio"]:checked ~ .checkbox-mark:after,
        .custom-checkbox input[type="checkbox"]:checked ~ .checkbox-mark:after {
            display: block;
        }
        
        .custom-checkbox:hover .checkbox-mark {
            border-color: #667eea;
            transform: scale(1.15);
            box-shadow: 0 0 15px rgba(102, 126, 234, 0.3);
        }
        
        .action-buttons {
            margin-top: 30px;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            padding: 20px 0;
            border-top: 2px solid var(--border-color);
        }
        
        .btn {
            padding: 16px 40px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            background-size: 200% 100%;
            color: white;
            box-shadow: 0 6px 25px rgba(102, 126, 234, 0.4);
            animation: shimmer 3s ease-in-out infinite;
        }
        
        @keyframes shimmer {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        
        .btn-primary:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 10px 35px rgba(102, 126, 234, 0.6);
        }
        
        .btn-primary:active {
            transform: translateY(-1px) scale(1.02);
        }
        
        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 2px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background: var(--border-color);
        }
        
        .info-box {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border-left: 5px solid #f59e0b;
            padding: 20px 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            display: flex;
            align-items: start;
            gap: 15px;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.2);
            animation: fadeInUp 0.6s ease 0.2s both;
        }
        
        .info-box i {
            font-size: 20px;
            color: #1e40af;
            margin-top: 2px;
        }
        
        .info-box-content {
            flex: 1;
            color: #1e3a8a;
        }
        
        .info-box-content strong {
            display: block;
            margin-bottom: 5px;
            font-size: 15px;
        }
        
        .info-box-content p {
            margin: 0;
            font-size: 14px;
            line-height: 1.5;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            animation: fadeInUp 0.6s ease 0.3s both;
        }
        
        .stat-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 25px;
            box-shadow: var(--shadow-lg);
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }
        
        .stat-card.total {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .stat-card.same-tab {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .stat-card.new-tab {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }
        
        .stat-icon {
            font-size: 36px;
            margin-bottom: 15px;
            opacity: 0.9;
        }
        
        .stat-value {
            font-size: 42px;
            font-weight: 800;
            margin-bottom: 5px;
            line-height: 1;
        }
        
        .stat-label {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.9;
            font-weight: 600;
        }
        
        /* Quick Action Buttons */
        .quick-action-btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: 2px solid;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        
        .quick-action-btn.same-tab-btn {
            border-color: #10b981;
            color: #10b981;
        }
        
        .quick-action-btn.same-tab-btn:hover {
            background: #10b981;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .quick-action-btn.new-tab-btn {
            border-color: #3b82f6;
            color: #3b82f6;
        }
        
        .quick-action-btn.new-tab-btn:hover {
            background: #3b82f6;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        /* Custom Modal Styles */
        .custom-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            z-index: 10000;
            animation: fadeIn 0.2s ease;
        }
        
        .custom-modal-overlay.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .custom-modal {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 0;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
        }
        
        .custom-modal-header {
            padding: 24px 28px;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .custom-modal-header i {
            font-size: 24px;
            color: var(--gradient-1);
        }
        
        .custom-modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .custom-modal-body {
            padding: 28px;
            color: var(--text-primary);
            font-size: 15px;
            line-height: 1.6;
        }
        
        .custom-modal-footer {
            padding: 20px 28px;
            border-top: 2px solid var(--border-color);
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        .modal-btn {
            padding: 12px 28px;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .modal-btn-cancel {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 2px solid var(--border-color);
        }
        
        .modal-btn-cancel:hover {
            background: var(--border-color);
        }
        
        .modal-btn-confirm {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .modal-btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="link-settings-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-external-link-alt"></i>
                Link Behavior Settings
            </h1>
            <p class="page-subtitle">Control how links open across all portals (Same Tab or New Tab)</p>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <div class="info-box-content">
                <strong>How it works:</strong>
                <p>Select <strong>_self</strong> to open links in the same tab (default behavior). Select <strong>_blank</strong> to open links in a new tab. Changes apply immediately after saving and affect all users in the respective portal.</p>
            </div>
        </div>
        
        <!-- Statistics Section Heading -->
        <div style="margin: 40px 0 20px 0;">
            <h2 style="font-size: 28px; font-weight: 700; color: var(--text-primary); margin: 0 0 8px 0; display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-chart-bar" style="color: var(--gradient-1);"></i>
                Link Behavior Statistics
            </h2>
            <p style="color: var(--text-secondary); font-size: 15px; margin: 0;">
                Overview of how many pages are configured to open in same tab vs new tab across all portals
            </p>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon">
                    <i class="fas fa-link"></i>
                </div>
                <div class="stat-value"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Pages</div>
            </div>
            
            <div class="stat-card same-tab">
                <div class="stat-icon">
                    <i class="fas fa-window-restore"></i>
                </div>
                <div class="stat-value"><?php echo $stats['_self']; ?></div>
                <div class="stat-label">Same Tab (_self)</div>
            </div>
            
            <div class="stat-card new-tab">
                <div class="stat-icon">
                    <i class="fas fa-external-link-square-alt"></i>
                </div>
                <div class="stat-value"><?php echo $stats['_blank']; ?></div>
                <div class="stat-label">New Tab (_blank)</div>
            </div>
        </div>
        
        <!-- Portal Configuration Section Heading -->
        <div style="margin: 50px 0 25px 0;">
            <h2 style="font-size: 28px; font-weight: 700; color: var(--text-primary); margin: 0 0 8px 0; display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-cogs" style="color: var(--gradient-1);"></i>
                Configure Portal Link Behaviors
            </h2>
            <p style="color: var(--text-secondary); font-size: 15px; margin: 0;">
                Select whether each page should open in the same tab or a new tab. Use quick action buttons to set all pages at once, or configure individually.
            </p>
        </div>
        
        <form method="POST">
            <?php if (!empty($linksByPortal)): ?>
                <?php foreach ($linksByPortal as $portal => $links): ?>
                <div class="portal-section" data-portal="<?php echo htmlspecialchars($portal); ?>">
                    <div class="portal-header">
                        <div class="portal-icon <?php echo strtolower($portal); ?>">
                            <i class="fas fa-<?php echo $portal === 'Superadmin' ? 'crown' : ($portal === 'Admin' ? 'user-shield' : 'user'); ?>"></i>
                        </div>
                        <div class="portal-info">
                            <h2 class="<?php echo $portal === 'Admin' ? 'admin-title' : ($portal === 'Member' ? 'member-title' : ''); ?>"><?php echo htmlspecialchars($portal); ?> Portal</h2>
                            <div class="portal-count"><?php echo count($links); ?> pages configured</div>
                        </div>
                        <div style="margin-left: auto; display: flex; gap: 10px;">
                            <button type="button" class="quick-action-btn same-tab-btn" onclick="setAllForPortal('<?php echo $portal; ?>', '_self')">
                                <i class="fas fa-window-restore"></i> All Same Tab
                            </button>
                            <button type="button" class="quick-action-btn new-tab-btn" onclick="setAllForPortal('<?php echo $portal; ?>', '_blank')">
                                <i class="fas fa-external-link-alt"></i> All New Tab
                            </button>
                        </div>
                    </div>
                    
                    <table class="links-table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Page Name</th>
                                <th>Page URL</th>
                                <th class="checkbox-column">_self<br><small style="font-weight: 400;">(Same Tab)</small></th>
                                <th class="checkbox-column">_blank<br><small style="font-weight: 400;">(New Tab)</small></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($links as $index => $link): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <div class="page-name">
                                        <?php
                                        $iconColor = ['#667eea', '#f093fb', '#4facfe', '#2ecc71', '#e74c3c', '#f39c12', '#9b59b6'];
                                        $randomColor = $iconColor[$index % count($iconColor)];
                                        $pageIcon = 'file-alt';
                                        if (strpos($link['PageName'], 'Dashboard') !== false) $pageIcon = 'tachometer-alt';
                                        if (strpos($link['PageName'], 'Member') !== false) $pageIcon = 'users';
                                        if (strpos($link['PageName'], 'Admin') !== false) $pageIcon = 'user-shield';
                                        if (strpos($link['PageName'], 'Transaction') !== false) $pageIcon = 'exchange-alt';
                                        if (strpos($link['PageName'], 'Report') !== false) $pageIcon = 'chart-bar';
                                        if (strpos($link['PageName'], 'Setting') !== false) $pageIcon = 'cog';
                                        if (strpos($link['PageName'], 'Profile') !== false) $pageIcon = 'user-circle';
                                        if (strpos($link['PageName'], 'Import') !== false || strpos($link['PageName'], 'Export') !== false) $pageIcon = 'file-excel';
                                        if (strpos($link['PageName'], 'Backup') !== false) $pageIcon = 'database';
                                        if (strpos($link['PageName'], 'Payment') !== false) $pageIcon = 'credit-card';
                                        if (strpos($link['PageName'], 'Statement') !== false) $pageIcon = 'file-invoice';
                                        if (strpos($link['PageName'], 'Audit') !== false || strpos($link['PageName'], 'Log') !== false) $pageIcon = 'history';
                                        ?>
                                        <i class="fas fa-<?php echo $pageIcon; ?>" style="color: <?php echo $randomColor; ?>; font-size: 18px;"></i>
                                        <?php echo htmlspecialchars($link['PageName']); ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="page-url"><?php echo htmlspecialchars($link['PageURL']); ?></span>
                                </td>
                                <td class="checkbox-cell">
                                    <label class="custom-checkbox">
                                        <input 
                                            type="radio" 
                                            name="links[<?php echo $link['LinkID']; ?>]" 
                                            value="_self"
                                            <?php echo $link['TargetBehavior'] === '_self' ? 'checked' : ''; ?>
                                        >
                                        <span class="checkbox-mark"></span>
                                    </label>
                                </td>
                                <td class="checkbox-cell">
                                    <label class="custom-checkbox">
                                        <input 
                                            type="radio" 
                                            name="links[<?php echo $link['LinkID']; ?>]" 
                                            value="_blank"
                                            <?php echo $link['TargetBehavior'] === '_blank' ? 'checked' : ''; ?>
                                        >
                                        <span class="checkbox-mark"></span>
                                    </label>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
                
                <div class="action-buttons">
                    <button type="reset" class="btn btn-secondary">
                        <i class="fas fa-undo"></i>
                        Reset Changes
                    </button>
                    <button type="submit" name="save_link_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        Save All Settings
                    </button>
                </div>
            <?php else: ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    No link settings found. Please run the database migration: <code>CREATE_LINK_BEHAVIOR_TABLE.sql</code>
                </div>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- Custom Confirmation Modal -->
    <div class="custom-modal-overlay" id="confirmModal">
        <div class="custom-modal">
            <div class="custom-modal-header">
                <i class="fas fa-question-circle"></i>
                <h3 id="modalTitle">Confirm Action</h3>
            </div>
            <div class="custom-modal-body" id="modalMessage">
                Are you sure you want to continue?
            </div>
            <div class="custom-modal-footer">
                <button type="button" class="modal-btn modal-btn-cancel" onclick="closeModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="modal-btn modal-btn-confirm" id="modalConfirmBtn">
                    <i class="fas fa-check"></i> OK
                </button>
            </div>
        </div>
    </div>
    
    <?php outputLinkTargetScript('superadmin'); ?>
    
    <script src="theme-manager.js"></script>
    <script>
        // Set all links in a portal to same behavior
        function setAllForPortal(portal, behavior) {
            const portalSection = Array.from(document.querySelectorAll('.portal-section')).find(section => {
                const title = section.querySelector('h2');
                return title && title.textContent.includes(portal);
            });
            
            if (portalSection) {
                const radios = portalSection.querySelectorAll(`input[type="radio"][value="${behavior}"]`);
                radios.forEach(radio => {
                    radio.checked = true;
                    // Trigger animation
                    const mark = radio.nextElementSibling;
                    if (mark) {
                        mark.style.animation = 'none';
                        setTimeout(() => {
                            mark.style.animation = 'checkPulse 0.4s ease';
                        }, 10);
                    }
                });
                
                // Show feedback
                showToast(`All ${portal} portal links set to ${behavior === '_self' ? 'Same Tab' : 'New Tab'}`, 'success');
            }
        }
        
        // Toast notification
        function showToast(message, type = 'success') {
            const existingToast = document.querySelector('.toast-notification');
            if (existingToast) existingToast.remove();
            
            const toast = document.createElement('div');
            toast.className = `toast-notification ${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;
            
            const style = document.createElement('style');
            style.textContent = `
                .toast-notification {
                    position: fixed;
                    bottom: 30px;
                    right: 30px;
                    background: white;
                    padding: 16px 24px;
                    border-radius: 12px;
                    box-shadow: 0 8px 30px rgba(0,0,0,0.2);
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    z-index: 10000;
                    animation: slideInRight 0.3s ease;
                    font-weight: 600;
                    border-left: 4px solid #10b981;
                }
                
                .toast-notification.success {
                    border-left-color: #10b981;
                    color: #065f46;
                }
                
                .toast-notification.success i {
                    color: #10b981;
                    font-size: 20px;
                }
                
                @keyframes slideInRight {
                    from {
                        transform: translateX(400px);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
            `;
            
            if (!document.querySelector('style[data-toast-style]')) {
                style.setAttribute('data-toast-style', 'true');
                document.head.appendChild(style);
            }
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideInRight 0.3s ease reverse';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Custom Modal Functions
        let modalResolve = null;
        
        function showConfirmModal(title, message) {
            return new Promise((resolve) => {
                modalResolve = resolve;
                document.getElementById('modalTitle').textContent = title;
                document.getElementById('modalMessage').textContent = message;
                document.getElementById('confirmModal').classList.add('show');
                
                // Set focus on confirm button
                setTimeout(() => {
                    document.getElementById('modalConfirmBtn').focus();
                }, 100);
            });
        }
        
        function closeModal() {
            document.getElementById('confirmModal').classList.remove('show');
            if (modalResolve) {
                modalResolve(false);
                modalResolve = null;
            }
        }
        
        // Confirm button click
        document.getElementById('modalConfirmBtn').addEventListener('click', function() {
            document.getElementById('confirmModal').classList.remove('show');
            if (modalResolve) {
                modalResolve(true);
                modalResolve = null;
            }
        });
        
        // Close on overlay click
        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('confirmModal').classList.contains('show')) {
                closeModal();
            }
        });
        
        // Add confirmation before reset
        document.querySelector('button[type="reset"]')?.addEventListener('click', async function(e) {
            e.preventDefault();
            const confirmed = await showConfirmModal(
                'Reset Changes',
                'Are you sure you want to reset all changes? This will revert to the last saved state.'
            );
            if (confirmed) {
                this.form.reset();
            }
        });
        
        // Add confirmation before save with count
        document.querySelector('form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const totalLinks = document.querySelectorAll('input[type="radio"]:checked').length;
            const confirmed = await showConfirmModal(
                'Save Settings',
                `Save link behavior settings for ${totalLinks} pages?`
            );
            if (confirmed) {
                this.submit();
            }
        });
    </script>
    
    <!-- Initialize Theme on Page Load -->
    <script>
        // Load theme from localStorage on page load
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('app_theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            
            // Update theme toggle button if it exists
            const themeIcon = document.getElementById('themeIcon');
            const themeText = document.getElementById('themeText');
            if (themeIcon && themeText) {
                if (savedTheme === 'dark') {
                    themeIcon.className = 'fas fa-sun';
                    themeText.textContent = 'Light';
                } else {
                    themeIcon.className = 'fas fa-moon';
                    themeText.textContent = 'Dark';
                }
            }
        });
    </script>
</body>
</html>
