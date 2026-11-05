<?php
// Link Target Helper Functions
// Include this file to get dynamic link target attributes based on database settings

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/config.php';
}

/** Friendly names for pages (portal => [PageURL => name]); other pages get a name from their file name */
function ltPageNames() {
    return [
        'superadmin' => [
            'dashboard.php' => 'Dashboard', 'manage-members.php' => 'Manage Members', 'manage-transactions.php' => 'Manage Transactions',
            'change-amount.php' => 'Change Amount', 'manage-admins.php' => 'Manage Admins', 'manage-academic-years.php' => 'Academic Years',
            'manage-years.php' => 'Manage Years', 'net-balance-manager.php' => 'Net Balance Manager', 'reports.php' => 'Reports',
            'report-builder.php' => 'Report Builder', 'bills.php' => 'Bill Generator', 'manage-templates.php' => 'Report Templates',
            'permissions-management.php' => 'Permissions Management', 'data-import-export.php' => 'Data Import/Export',
            'online-excel-editor.php' => 'Excel Editor', 'database-backup.php' => 'Database Backup', 'database-update.php' => 'Database Update',
            'google-drive-settings.php' => 'Google Drive', 'settings.php' => 'Settings', 'link-behavior-settings.php' => 'Link Behavior Settings',
            'audit-logs.php' => 'Audit Logs', 'two-factor-auth.php' => 'Two-Factor Authentication', 'face-auth.php' => 'Face Lock',
            'accounting/dashboard.php' => 'Accounting · Dashboard', 'accounting/manage-expenses.php' => 'Accounting · Manage Expenses',
            'accounting/add-expense.php' => 'Accounting · Add Expense', 'accounting/manage-transactions.php' => 'Accounting · View Transactions',
            'accounting/expense-reports.php' => 'Accounting · Expense Reports', 'accounting/budget-tracking.php' => 'Accounting · Budget Tracking',
            'accounting/import-expenses.php' => 'Accounting · Import Expenses', 'accounting/export-expenses.php' => 'Accounting · Export Expenses',
            'accounting/events/dashboard.php' => 'Events · Dashboard', 'accounting/events/manage-events.php' => 'Events · Manage Events',
            'accounting/events/add-event.php' => 'Events · Add Event', 'accounting/events/view-event.php' => 'Events · View Event',
            'accounting/events/edit-event.php' => 'Events · Edit Event', 'accounting/events/event-transactions.php' => 'Events · Event Transactions',
            'accounting/events/add-transaction.php' => 'Events · Add Event Transaction', 'accounting/events/edit-transaction.php' => 'Events · Edit Event Transaction',
            'accounting/events/event-reports.php' => 'Events · Event Reports', 'accounting/events/import-event-transactions.php' => 'Events · Import Event Transactions',
        ],
        'admin' => [
            'dashboard-new.php' => 'Dashboard', 'manage-members.php' => 'Manage Members', 'manage-transactions.php' => 'Manage Transactions',
            'add-transaction.php' => 'Add Transaction', 'edit-transaction.php' => 'Edit Transaction', 'verify-transaction.php' => 'Verify Transaction',
            'reports.php' => 'Reports', 'profile.php' => 'Profile', 'view-profile.php' => 'View Profile', 'settings.php' => 'Account Settings',
        ],
        'member' => [
            'dashboard-new.php' => 'Dashboard', 'my-payments.php' => 'My Payments', 'member.php' => 'My Account', 'profile.php' => 'Profile',
            'view-profile.php' => 'View Profile', 'settings.php' => 'Account Settings', 'reports.php' => 'Reports',
        ],
    ];
}

/** Every user-facing page of each portal: portal => [PageURL => name] (helpers, exports, APIs and tests are left out) */
function ltDiscoverPages() {
    $root = dirname(__DIR__);
    $folders = ['superadmin' => ['superadmin', 'superadmin/accounting', 'superadmin/accounting/events'], 'admin' => ['admin'], 'member' => ['member']];
    $skip = '/^(api|ajax|export|download|print|generate|fix|test|check|diagnose|debug|session|login|logout|direct|admin-auto|member-auto|face-login|gemini|fetch|save|preview|'
        . 'quick-date|desktop-api|simple-excel|fpdf|dashboard-redirect|reports-old|update-profile|delete-transaction|new-event-transaction|setup-|dashboard\.php$)/i';
    $names = ltPageNames();
    $out = [];
    foreach ($folders as $portal => $dirs) {
        $out[$portal] = [];
        // Named pages first, in their listed order
        foreach ($names[$portal] as $url => $name) {
            if (is_file($root . '/' . $portal . '/' . $url)) {
                $out[$portal][$url] = $name;
            }
        }
        foreach ($dirs as $dir) {
            $prefix = substr($dir, strlen($portal) + 1);
            foreach (glob($root . '/' . $dir . '/*.php') ?: [] as $file) {
                $base = basename($file);
                $url = ($prefix !== '' ? $prefix . '/' : '') . $base;
                if (isset($out[$portal][$url])) continue;
                if (preg_match($skip, $base) && !($base === 'dashboard.php' && $portal === 'superadmin')) continue;
                $label = ucwords(str_replace(['-', '_'], ' ', pathinfo($base, PATHINFO_FILENAME)));
                $group = ['accounting' => 'Accounting · ', 'accounting/events' => 'Events · '][$prefix] ?? '';
                $out[$portal][$url] = $group . $label;
            }
        }
    }
    return $out;
}

/**
 * Register every page in link_target_settings (new pages open in the same tab until changed);
 * pages whose file no longer exists are hidden. Returns how many pages were added.
 */
function ltSyncPages(PDO $pdo) {
    $pages = ltDiscoverPages();
    $existing = [];
    foreach ($pdo->query('SELECT LinkID, Portal, PageURL, IsActive, DisplayOrder FROM link_target_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[$r['Portal']][$r['PageURL']] = $r;
    }
    $ins = $pdo->prepare("INSERT IGNORE INTO link_target_settings (PageName, PageURL, Portal, TargetBehavior, DisplayOrder, IsActive) VALUES (?, ?, ?, '_self', ?, 1)");
    $added = 0;
    foreach ($pages as $portal => $list) {
        $order = 0;
        foreach ($existing[$portal] ?? [] as $r) $order = max($order, (int)$r['DisplayOrder']);
        foreach ($list as $url => $name) {
            if (isset($existing[$portal][$url])) {
                if (!(int)$existing[$portal][$url]['IsActive']) {
                    $pdo->prepare('UPDATE link_target_settings SET IsActive = 1 WHERE LinkID = ?')->execute([$existing[$portal][$url]['LinkID']]);
                }
                continue;
            }
            $ins->execute([$name, $url, $portal, ++$order]);
            $added += $ins->rowCount();
        }
        // Hide rows for pages that no longer exist
        foreach ($existing[$portal] ?? [] as $url => $r) {
            if ((int)$r['IsActive'] && !isset($list[$url]) && !is_file(dirname(__DIR__) . '/' . $portal . '/' . $url)) {
                $pdo->prepare('UPDATE link_target_settings SET IsActive = 0 WHERE LinkID = ?')->execute([$r['LinkID']]);
            }
        }
    }
    return $added;
}

// Get link target for a specific page ("reports" and "reports.php" both work)
function getLinkTarget($pageURL, $portal = 'all') {
    global $pdo;
    if ($pageURL !== '' && substr($pageURL, -4) !== '.php') {
        $pageURL .= '.php';
    }

    try {
        // Try to get setting from database
        $stmt = $pdo->prepare("
            SELECT TargetBehavior 
            FROM link_target_settings 
            WHERE PageURL = ? AND (Portal = ? OR Portal = 'all') AND IsActive = 1
            ORDER BY Portal DESC
            LIMIT 1
        ");
        $stmt->execute([$pageURL, $portal]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['TargetBehavior'] === '_blank') {
            return 'target="_blank" rel="noopener noreferrer"';
        }
    } catch (PDOException $e) {
        // If table doesn't exist or error, return default
        return '';
    }
    
    return ''; // Default to same tab
}

// Get all link targets for a portal as an associative array
function getAllLinkTargets($portal = 'all') {
    global $pdo;
    
    $targets = [];
    
    try {
        $stmt = $pdo->prepare("
            SELECT PageURL, TargetBehavior 
            FROM link_target_settings 
            WHERE (Portal = ? OR Portal = 'all') AND IsActive = 1
        ");
        $stmt->execute([$portal]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($results as $row) {
            $targets[$row['PageURL']] = $row['TargetBehavior'] === '_blank' 
                ? 'target="_blank" rel="noopener noreferrer"' 
                : '';
        }
    } catch (PDOException $e) {
        // Return empty array if table doesn't exist
        return [];
    }
    
    return $targets;
}

// JavaScript function to apply link targets dynamically
function outputLinkTargetScript($portal = 'all') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT PageURL, TargetBehavior 
            FROM link_target_settings 
            WHERE (Portal = ? OR Portal = 'all') AND IsActive = 1 AND TargetBehavior = '_blank'
        ");
        $stmt->execute([$portal]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($results)) {
            return '';
        }
        
        $blankLinks = array_column($results, 'PageURL');
        $jsonLinks = json_encode($blankLinks);
        
        echo "<script>
        document.addEventListener('DOMContentLoaded', function() {
            const blankLinks = $jsonLinks;
            const links = document.querySelectorAll('a');
            
            links.forEach(link => {
                const href = link.getAttribute('href');
                if (href) {
                    blankLinks.forEach(pageUrl => {
                        if (href.includes(pageUrl)) {
                            link.setAttribute('target', '_blank');
                            link.setAttribute('rel', 'noopener noreferrer');
                        }
                    });
                }
            });
        });
        </script>";
    } catch (PDOException $e) {
        return '';
    }
}
