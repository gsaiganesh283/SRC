<?php
// Super Admin - Project Overview: animated guide to the whole system (portals, money flow, desktop app, backups, testing pages)
require_once 'session.php';
require_once '../config/config.php';

$page_title = 'Project Overview';

// Live numbers for the hero counters; a missing table just shows 0
function po_count($pdo, $sql) {
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}
$stats = [
    ['Members', po_count($pdo, "SELECT COUNT(*) FROM members"), 'fa-users'],
    ['Admins', po_count($pdo, "SELECT COUNT(*) FROM admins"), 'fa-user-shield'],
    ['Transactions', po_count($pdo, "SELECT COUNT(*) FROM transactions"), 'fa-money-bill-transfer'],
    ['Academic Years', po_count($pdo, "SELECT COUNT(*) FROM academic_years"), 'fa-calendar-days'],
    ['Events', po_count($pdo, "SELECT COUNT(*) FROM events"), 'fa-champagne-glasses'],
];

// Count the project's own code files for the hero (skips vendor libraries and backups)
function po_files($dir, $ext) {
    $n = 0;
    if (!is_dir($dir)) {
        return 0;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = str_replace('\\', '/', $f->getPathname());
        if (preg_match('#/(vendor|node_modules|backups?|build|dist)/#', $p)) {
            continue;
        }
        if (strtolower($f->getExtension()) === $ext) {
            $n++;
        }
    }
    return $n;
}
$stats[] = ['PHP files', po_files(dirname(__DIR__), 'php'), 'fa-code'];
$stats[] = ['Desktop files', po_files(dirname(__DIR__, 2) . '/desktop', 'py'), 'fa-desktop'];

// Whole project, by area: label, icon, colour, intro, items [name, path from site root, icon, note, open as link?]
$areas = [
    'public' => ['Public & Login', 'fa-door-open', '#f7971e', 'The first pages anyone sees. Each role signs in on its own page.', [
        ['Home page', 'index.php', 'fa-house', 'Main entry page of the website.', true],
        ['Member login', 'auth/member-login.php', 'fa-user', 'Sign in for colony members.', true],
        ['Admin login', 'auth/admin-login.php', 'fa-user-tie', 'Sign in for committee admins.', true],
        ['Super Admin login', 'superadmin/login.php', 'fa-crown', 'Sign in for the owner, with 2-step code and Face Lock.', true],
        ['Forgot password', 'auth/forgot-password.php', 'fa-unlock-keyhole', 'Reset a forgotten password.', true],
        ['Change password', 'auth/change-password.php', 'fa-key', 'Change password after signing in.', true],
        ['Logout', 'auth/logout.php', 'fa-right-from-bracket', 'Ends the session.', false],
    ]],
    'member' => ['Member portal', 'fa-user', '#36d1dc', 'What a colony member sees after signing in (member/). Opening these needs a member sign-in.', [
        ['Dashboard', 'member/dashboard.php', 'fa-gauge-high', 'Own balance, payments and charts at a glance.', true],
        ['My Payments', 'member/my-payments.php', 'fa-receipt', 'Every payment the member made, with proofs.', true],
        ['Reports', 'member/reports.php', 'fa-chart-line', 'Own reports and custom charts.', true],
        ['Statement PDF', 'member/export-statement.php', 'fa-file-pdf', 'Download an account statement.', false],
        ['Documents', 'member/documents.php', 'fa-folder-open', 'Documents shared with the member.', true],
        ['Notifications', 'member/notifications.php', 'fa-bell', 'Messages about payments and updates.', true],
        ['Profile', 'member/profile.php', 'fa-id-card', 'View and update own details and photo.', true],
        ['Settings', 'member/settings.php', 'fa-sliders', 'Theme and personal settings.', true],
    ]],
    'admin' => ['Admin portal', 'fa-user-tie', '#5b86e5', 'Day-to-day work for committee admins (admin/). What each admin can do is set in Permissions.', [
        ['Dashboard', 'admin/dashboard.php', 'fa-gauge-high', 'Collections, pending items and charts.', true],
        ['Manage Members', 'admin/manage-members.php', 'fa-users', 'Add, view, edit and delete members.', true],
        ['Manage Transactions', 'admin/manage-transactions.php', 'fa-money-bill-transfer', 'Add and edit payments.', true],
        ['Verify Transaction', 'admin/verify-transaction.php', 'fa-circle-check', 'Check the proof and approve a payment.', true],
        ['Manage Years', 'admin/manage-years.php', 'fa-calendar-days', 'Chitti years the admin works with.', true],
        ['Reports', 'admin/reports.php', 'fa-chart-pie', 'Reports and PDF export.', true],
        ['Notifications', 'admin/notifications.php', 'fa-bell', 'Admin notifications.', true],
        ['Profile & Settings', 'admin/profile.php', 'fa-id-card', 'Own details and settings.', true],
    ]],
    'super' => ['Super Admin', 'fa-crown', '#764ba2', 'Full control of the system (superadmin/). Click a card to open the page.', [
        ['Dashboard', 'superadmin/dashboard.php', 'fa-gauge-high', 'Summary cards, charts and the Bill Generator button.', true],
        ['Manage Members', 'superadmin/manage-members.php', 'fa-users', 'Add, edit and remove members.', true],
        ['Manage Admins', 'superadmin/manage-admins.php', 'fa-user-shield', 'Add, edit and remove admins.', true],
        ['Manage Transactions', 'superadmin/manage-transactions.php', 'fa-money-bill-transfer', 'Add, edit, verify, bulk actions, proofs and export.', true],
        ['Change Amount', 'superadmin/change-amount.php', 'fa-right-left', 'Adjustment entries. A year\'s Change Amount is the sum of its entries.', true],
        ['Academic Years', 'superadmin/manage-academic-years.php', 'fa-calendar-days', 'Chitti cycles and their Change Amount.', true],
        ['Net Balance', 'superadmin/net-balance-manager.php', 'fa-scale-balanced', 'Member balances, Change Amount column, Month filter, PDFs.', true],
        ['Reports', 'superadmin/reports.php', 'fa-chart-pie', 'Standard reports with Change Amount card and columns.', true],
        ['Report Builder', 'superadmin/report-builder.php', 'fa-screwdriver-wrench', 'Build any report to PDF, Excel or CSV on the shared letterhead.', true],
        ['Bill Generator', 'superadmin/bills.php', 'fa-file-invoice', '5 bill types, bill history and settings, PDF or ZIP.', true],
        ['Templates', 'superadmin/manage-templates.php', 'fa-layer-group', 'Report and permission templates.', true],
        ['Permissions', 'superadmin/permissions-management.php', 'fa-key', 'Who can see and do what.', true],
        ['Data Import / Export', 'superadmin/data-import-export.php', 'fa-file-import', 'Import or export data in Excel or CSV.', true],
        ['Online Excel Editor', 'superadmin/online-excel-editor.php', 'fa-table', 'Edit data in a spreadsheet view.', true],
        ['Live Monitor', 'superadmin/live-monitor.php', 'fa-heart-pulse', 'Live requests, errors and activity.', true],
        ['Audit Logs', 'superadmin/audit-logs.php', 'fa-clipboard-list', 'Who changed what, with PDF export.', true],
        ['Database Backup', 'superadmin/database-backup.php', 'fa-database', 'Backups and Automatic Daily Backup settings.', true],
        ['Database Update', 'superadmin/database-update.php', 'fa-arrows-rotate', 'One-click database update after uploading files.', true],
        ['Google Drive', 'superadmin/google-drive-settings.php', 'fa-brands fa-google-drive', 'Connect Drive and rename old proof files.', true],
        ['Link Behavior', 'superadmin/link-behavior-settings.php', 'fa-link', 'Choose how each page\'s links open.', true],
        ['Two-Factor Auth', 'superadmin/two-factor-auth.php', 'fa-mobile-screen', 'Google Authenticator sign-in code.', true],
        ['Face Lock', 'superadmin/face-auth.php', 'fa-face-smile', 'Face sign-in for the Super Admin.', true],
        ['Sign in as Admin / Member', 'superadmin/direct-admin-login.php', 'fa-user-secret', 'Open any admin or member account directly.', true],
        ['Settings', 'superadmin/settings.php', 'fa-gear', 'Site name, logo, theme and system settings.', true],
    ]],
    'accounts' => ['Accounting & Events', 'fa-calculator', '#10b981', 'Colony spending and event money (superadmin/accounting/).', [
        ['Accounting dashboard', 'superadmin/accounting/dashboard.php', 'fa-calculator', 'Spending at a glance.', true],
        ['Manage Expenses', 'superadmin/accounting/manage-expenses.php', 'fa-wallet', 'Add, edit, import and export expenses.', true],
        ['Add Expense', 'superadmin/accounting/add-expense.php', 'fa-plus', 'Record a new expense.', true],
        ['Expense Reports', 'superadmin/accounting/expense-reports.php', 'fa-file-lines', 'Expense reports, print and PDF.', true],
        ['Budget Tracking', 'superadmin/accounting/budget-tracking.php', 'fa-bullseye', 'Budget limits against spending.', true],
        ['Events dashboard', 'superadmin/accounting/events/dashboard.php', 'fa-champagne-glasses', 'All events at a glance.', true],
        ['Manage Events', 'superadmin/accounting/events/manage-events.php', 'fa-calendar-plus', 'Create, edit and view events.', true],
        ['Event Transactions', 'superadmin/accounting/events/event-transactions.php', 'fa-money-bill-wave', 'Money in and out for each event.', true],
        ['Import Event Transactions', 'superadmin/accounting/events/import-event-transactions.php', 'fa-file-import', 'From CSV, or Excel .xlsx (needs PHP 8.3).', true],
        ['Event Reports', 'superadmin/accounting/events/event-reports.php', 'fa-chart-column', 'Event reports and export.', true],
    ]],
    'api' => ['Website APIs', 'fa-plug', '#ef6f6c', 'Background addresses that pages, the chat widget and the desktop app call. They return data, not pages.', [
        ['Desktop API', 'superadmin/desktop-api.php', 'fa-desktop', 'Runs a real Super Admin page and returns its data as JSON for the desktop app.', false],
        ['Report Builder API', 'superadmin/api/report-builder.php', 'fa-screwdriver-wrench', 'Data and saved layouts for the Report Builder.', false],
        ['Monitor API', 'superadmin/api/monitor.php', 'fa-heart-pulse', 'Live Monitor data, desktop Data Flow and desktop error reports.', false],
        ['DB Admin API', 'superadmin/api/dbadmin.php', 'fa-database', 'Database admin for the desktop app.', false],
        ['Google Drive API', 'superadmin/api/gdrive-sync.php', 'fa-brands fa-google-drive', 'Sync, actions and proof uploads to Google Drive.', false],
        ['Net Balance API', 'superadmin/api-net-balance.php', 'fa-scale-balanced', 'Net balance data for screens and PDFs.', false],
        ['Member lookup', 'superadmin/api-member-lookup.php', 'fa-magnifying-glass', 'Finds members while typing.', false],
        ['Face login API', 'superadmin/face-login-api.php', 'fa-face-smile', 'Checks the face for Face Lock.', false],
        ['Gemini API', 'superadmin/gemini-api.php', 'fa-robot', 'Sends chat questions to Gemini AI.', false],
        ['Notifications API', 'api/get-notification-count.php', 'fa-bell', 'Unread count, mark read and mark all read.', false],
        ['Profile & logo API', 'api/update-profile-api.php', 'fa-image', 'Saves profile changes and uploads the site logo.', false],
    ]],
    'engine' => ['Shared engine', 'fa-gears', '#bf8f00', 'Code every portal shares (includes/ and superadmin/includes/). This is where the rules live.', [
        ['config.php', 'config/config.php', 'fa-sliders', 'Database connection and site address; loads the Live Monitor.', false],
        ['functions.php', 'includes/functions.php', 'fa-toolbox', 'Common helpers used by all pages.', false],
        ['Net balance', 'includes/net-balance-helper.php', 'fa-scale-balanced', 'Works out every member\'s balance; advanced-balance-management.php adds recalculation tools.', false],
        ['Change Amount', 'includes/change-amount-helper.php', 'fa-right-left', 'Change Amount totals for net balance, bills and reports.', false],
        ['Backup', 'includes/backup-helper.php', 'fa-cloud-arrow-up', 'Daily SQL backup to the server and Google Drive.', false],
        ['Google Drive', 'includes/google-drive-helper.php', 'fa-brands fa-google-drive', 'Sign-in to Drive, upload, rename and storage.', false],
        ['Live Monitor', 'includes/app-monitor.php', 'fa-heart-pulse', 'Records each request, error and the tables it used.', false],
        ['Security', 'includes/totp-helper.php', 'fa-shield-halved', '2-step codes (TOTP) and face-helper.php for Face Lock.', false],
        ['AI chat', 'includes/gemini-chatbot.php', 'fa-robot', 'Gemini chat assistant and its widget.', false],
        ['Widgets', 'includes/notification-widget.php', 'fa-puzzle-piece', 'Notification bell, proof upload, pop-up windows.', false],
        ['Site look', 'includes/logo-helper.php', 'fa-palette', 'Logo, global settings and link behaviour.', false],
        ['Report engine', 'superadmin/includes/report-builder-lib.php', 'fa-screwdriver-wrench', 'Report Builder engine, formulas and number formats.', false],
        ['Bill engine', 'superadmin/includes/bill-lib.php', 'fa-file-invoice', 'Bill Generator engine.', false],
        ['Letterhead', 'superadmin/includes/letterhead-pdf.php', 'fa-stamp', 'One shared letterhead for every PDF report and bill.', false],
        ['Excel writer', 'superadmin/includes/rb-xlsx-writer.php', 'fa-file-excel', 'Makes .xlsx files without extra libraries.', false],
    ]],
    'desktop' => ['Desktop app', 'fa-desktop', '#2c5364', 'A Windows app with the Super Admin screens (H:\\SRC\\desktop). It works through the website.', [
        ['Login screen', 'desktop/srcolony/login.py', 'fa-right-to-bracket', 'Site address, Test button, sign-in and 2-step code.', false],
        ['Website bridge', 'desktop/srcolony/api.py', 'fa-plug', 'Talks to desktop-api.php and the website pages.', false],
        ['Main window', 'desktop/srcolony/app.py', 'fa-window-maximize', 'Menu, pages, theme and widgets.', false],
        ['Dashboard', 'desktop/srcolony/pages/core.py', 'fa-gauge-high', 'Same as the website dashboard.', false],
        ['People', 'desktop/srcolony/pages/people.py', 'fa-users', 'Members, admins and permissions.', false],
        ['Transactions & Years', 'desktop/srcolony/pages/transactions.py', 'fa-money-bill-transfer', 'Transactions, academic years and Change Amount.', false],
        ['Net Balance & Reports', 'desktop/srcolony/pages/netbalance.py', 'fa-scale-balanced', 'Net balance, reports, Report Builder and bills.', false],
        ['Accounting & Events', 'desktop/srcolony/pages/accounting.py', 'fa-calculator', 'Expenses, budgets and events.', false],
        ['System', 'desktop/srcolony/pages/system.py', 'fa-gear', 'Settings, backup, import/export, Google Drive.', false],
        ['Monitor & Data Flow', 'desktop/srcolony/pages/monitor.py', 'fa-heart-pulse', 'Live Monitor and a live map of data flow.', false],
        ['DB Admin', 'desktop/srcolony/pages/dbadmin.py', 'fa-database', 'Look at database tables.', false],
        ['The .exe', 'desktop/dist/SR Colony Super Admin.exe', 'fa-file-code', 'One file, no Python needed. Rebuild with build_exe.bat.', false],
    ]],
    'auto' => ['Automation', 'fa-robot', '#f093fb', 'Things that happen by themselves, without anyone clicking.', [
        ['Net balance recalculation', 'includes/net-balance-helper.php', 'fa-arrows-rotate', 'Runs after every transaction is added, changed or deleted.', false],
        ['Audit log', 'audit_logs table', 'fa-clipboard-list', 'Every change and sign-in is written down.', false],
        ['Daily backup', 'includes/backup-helper.php', 'fa-cloud-arrow-up', 'One backup a day, old ones removed after "days to keep".', false],
        ['Cron job', 'cron/daily-backup.php', 'fa-clock', 'Optional exact backup time, e.g. daily at 02:00.', false],
        ['Live Monitor', 'includes/app-monitor.php', 'fa-heart-pulse', 'Every request is recorded as it happens.', false],
        ['Notifications', 'notifications table', 'fa-bell', 'Members and admins are told about payments and updates.', false],
        ['Azure deploy', '.github/workflows', 'fa-brands fa-github', 'GitHub workflow that can deploy to the Azure app srcolony-view.', false],
    ]],
    'services' => ['Tools & services', 'fa-cubes', '#667eea', 'What the project is built with and the outside services it uses.', [
        ['PHP', 'Website', 'fa-brands fa-php', 'All website pages and APIs.', false],
        ['MySQL', 'Database', 'fa-database', 'All data: members, transactions, years, bills…', false],
        ['JavaScript + Chart.js', 'Browser', 'fa-brands fa-js', 'Charts, live pages and animations.', false],
        ['FPDF', 'PDF', 'fa-file-pdf', 'Every PDF report, statement and bill.', false],
        ['Python + Tkinter', 'Desktop app', 'fa-brands fa-python', 'The desktop app (ttkbootstrap), packed with PyInstaller.', false],
        ['Google Drive', 'Cloud', 'fa-brands fa-google-drive', 'Proof files and the private backup copy.', false],
        ['Gemini AI', 'Cloud', 'fa-robot', 'The chat assistant.', false],
        ['Google Authenticator', 'Phone app', 'fa-mobile-screen', '2-step sign-in codes.', false],
        ['Font Awesome', 'Icons', 'fa-brands fa-font-awesome', 'Icons on every page.', false],
    ]],
];

// Path from the site root, note, risk (Low = reads only, Medium = shows server details / creates tables, High = changes data or passwords)
$testing = [
    ['check-site-status.php', 'Quick check that the site and database respond.', 'Low'],
    ['check-php.php', 'Shows PHP version and extensions.', 'Medium'],
    ['phpinfo.php', 'Full PHP / server details.', 'High'],
    ['diagnostic.php', 'General system diagnostic.', 'Medium'],
    ['debug-session.php', 'Shows the current login session.', 'Medium'],
    ['debug-test.php', 'General debug page.', 'Medium'],
    ['check-members-table.php', 'Checks the members table structure.', 'Low'],
    ['test-db-status.php', 'Database connection and table status.', 'Low'],
    ['test-page-load.php', 'Checks pages load without errors.', 'Low'],
    ['test-dashboard-error.php', 'Finds dashboard errors.', 'Low'],
    ['test-cookie-flow.php', 'Tests login cookies.', 'Low'],
    ['test-years.php', 'Tests academic years data.', 'Low'],
    ['test-transactions.php', 'Tests transactions data.', 'Low'],
    ['test-transaction-month-fix.php', 'Tests the transaction month fix.', 'Low'],
    ['test-manual-update-fix.php', 'Tests the manual update fix.', 'Low'],
    ['test-advanced-balance-system.php', 'Tests the net balance system.', 'Low'],
    ['test-net-balance-errors.php', 'Finds net balance errors.', 'Low'],
    ['test-net-balance-transaction-updates.php', 'Tests net balance after transaction changes.', 'Low'],
    ['test-net-balance-verification.php', 'Verifies net balances.', 'Low'],
    ['test-auto-recalculate.php', 'Tests automatic recalculation.', 'Low'],
    ['test-auto-recalculate-form.php', 'Form to test automatic recalculation.', 'Low'],
    ['test-auto-recalculate-debug.php', 'Debug view of automatic recalculation.', 'Low'],
    ['verification-report.php', 'System verification report.', 'Medium'],
    ['verify-page.php', 'Page verification helper.', 'Medium'],
    ['initialize-net-balance-table.php', 'Creates / fills the net balance table. Changes data.', 'High'],
    ['fix-member-net-balance-yearid.php', 'Fixes year IDs in net balance. Changes data.', 'High'],
    ['setup-logo-settings.php', 'Sets up logo settings. Changes data.', 'Medium'],
    ['sync-database.php', 'Syncs the database. Changes data.', 'High'],
    ['import-backup-non-destructive.php', 'Imports a backup without deleting. Changes data.', 'High'],
    ['generate-password.php', 'Makes a password hash.', 'High'],
    ['generate-demo-passwords.php', 'Sets demo passwords. Changes data.', 'High'],
    ['reset-admin-password.php', 'Resets an admin password without signing in.', 'High'],
    ['unlock-super-admin.php', 'Unlocks the Super Admin without signing in.', 'High'],
    ['superadmin/check-backup-content.php', 'Checks what a backup contains.', 'Low'],
    ['superadmin/check-reports-data.php', 'Checks report data.', 'Low'],
    ['superadmin/diagnose-transactions.php', 'Finds transaction problems.', 'Low'],
    ['superadmin/quick-date-check.php', 'Checks dates in transactions.', 'Low'],
    ['superadmin/test-reports-query.php', 'Tests report queries.', 'Low'],
    ['superadmin/test_internet.php', 'Checks the server can reach the internet.', 'Low'],
    ['superadmin/fix-backup-table.php', 'Repairs the backup table. Changes data.', 'Medium'],
    ['superadmin/fix-settings-table.php', 'Repairs the settings table. Changes data.', 'Medium'],
    ['superadmin/fix-transaction-years.php', 'Fixes transaction years. Changes data.', 'High'],
    ['superadmin/accounting/setup-events.php', 'Creates the event tables.', 'Medium'],
    ['superadmin/accounting/setup-expenses.php', 'Creates the expense tables.', 'Medium'],
    ['admin/debug.php', 'Admin debug page.', 'Medium'],
    ['member/test-template-debug.php', 'Tests member report templates.', 'Low'],
];
$siteRoot = dirname(__DIR__);
foreach ($testing as &$t) {
    $t[] = file_exists($siteRoot . '/' . $t[0]);
}
unset($t);

$sections = [
    'po-arch' => ['How it connects', 'fa-diagram-project'],
    'po-portals' => ['Portals', 'fa-door-open'],
    'po-login' => ['Signing in', 'fa-key'],
    'po-money' => ['Money flow', 'fa-indian-rupee-sign'],
    'po-features' => ['Whole project', 'fa-grip'],
    'po-desktop' => ['Desktop app', 'fa-desktop'],
    'po-backup' => ['Backups', 'fa-cloud-arrow-up'],
    'po-db' => ['Database', 'fa-database'],
    'po-folders' => ['Folders', 'fa-folder-tree'],
    'po-deploy' => ['Updating', 'fa-rocket'],
    'po-tests' => ['Testing pages', 'fa-vial'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Overview - Super Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared-styles.css">
    <script src="theme-manager.js"></script>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .po-progress { position: fixed; top: 0; left: 0; height: 4px; width: 0; z-index: 2000; background: linear-gradient(90deg, #667eea, #764ba2, #f093fb); transition: width .1s linear; }
        .po-page { max-width: 1300px; margin: 0 auto; padding: 20px; display: grid; grid-template-columns: 220px 1fr; gap: 24px; }
        .po-main { min-width: 0; }

        /* table of contents with scroll spy */
        .po-toc { position: sticky; top: 90px; align-self: start; background: var(--bg-secondary-solid); border: 1px solid var(--border-color); border-radius: 14px; padding: 12px; }
        .po-toc a { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 9px; color: var(--text-secondary); text-decoration: none; font-size: 14px; transition: all .25s; position: relative; }
        .po-toc a i { width: 18px; text-align: center; }
        .po-toc a:hover { background: var(--bg-tertiary); color: var(--text-primary); transform: translateX(3px); }
        .po-toc a.active { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; box-shadow: 0 4px 14px rgba(102,126,234,.4); }

        /* hero */
        .po-hero { position: relative; overflow: hidden; border-radius: 20px; padding: 44px 36px 36px; color: #fff; background: linear-gradient(-45deg, #667eea, #764ba2, #2c5364, #f093fb); background-size: 400% 400%; animation: poGradient 14s ease infinite; margin-bottom: 26px; }
        @keyframes poGradient { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        .po-hero h1 { font-size: 40px; margin: 0 0 8px; animation: poDrop .9s cubic-bezier(.2,.9,.3,1.3) both; }
        .po-hero .po-sub { font-size: 18px; opacity: .92; min-height: 26px; }
        .po-hero .po-caret { display: inline-block; width: 2px; height: 20px; background: #fff; vertical-align: middle; margin-left: 2px; animation: poBlink 1s step-end infinite; }
        @keyframes poBlink { 50% { opacity: 0; } }
        @keyframes poDrop { from { opacity: 0; transform: translateY(-30px) scale(.9); } to { opacity: 1; transform: none; } }
        .po-coin { position: absolute; bottom: -60px; width: 34px; height: 34px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #fff6c9, #ffd966 45%, #bf8f00); color: #7f6000; font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 16px; box-shadow: 0 4px 10px rgba(0,0,0,.25); animation: poRise linear infinite; opacity: .9; }
        @keyframes poRise { 0% { transform: translateY(0) rotateY(0); opacity: 0; } 10% { opacity: .9; } 100% { transform: translateY(-420px) rotateY(720deg); opacity: 0; } }
        .po-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-top: 28px; position: relative; z-index: 1; }
        .po-stat { background: rgba(255,255,255,.16); backdrop-filter: blur(6px); border: 1px solid rgba(255,255,255,.3); border-radius: 14px; padding: 14px 16px; animation: poUp .7s both; transition: transform .3s; }
        .po-stat:hover { transform: translateY(-4px) scale(1.03); }
        .po-stat i { font-size: 20px; opacity: .9; }
        .po-stat b { display: block; font-size: 30px; margin: 4px 0 0; font-variant-numeric: tabular-nums; }
        .po-stat span { font-size: 13px; opacity: .9; }
        @keyframes poUp { from { opacity: 0; transform: translateY(24px); } to { opacity: 1; transform: none; } }
        .po-hero-actions { margin-top: 22px; display: flex; gap: 10px; flex-wrap: wrap; position: relative; z-index: 1; }
        .po-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; border: 0; cursor: pointer; font-weight: 600; font-size: 14px; text-decoration: none; transition: transform .2s, box-shadow .2s; }
        .po-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.2); }
        .po-btn.white { background: #fff; color: #5b4bc4; }
        .po-btn.ghost { background: rgba(255,255,255,.18); color: #fff; border: 1px solid rgba(255,255,255,.4); }
        .po-btn.main { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }

        /* sections */
        .po-sec { background: var(--bg-secondary-solid); border: 1px solid var(--border-color); border-radius: 18px; padding: 26px; margin-bottom: 24px; scroll-margin-top: 90px; }
        .po-sec h2 { display: flex; align-items: center; gap: 12px; margin: 0 0 6px; color: var(--text-primary); font-size: 23px; }
        .po-sec h2 .po-ico { width: 40px; height: 40px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; color: #fff; background: linear-gradient(135deg, #667eea, #764ba2); font-size: 18px; }
        .po-sec.in h2 .po-ico { animation: poSpinIn .8s both; }
        @keyframes poSpinIn { from { transform: rotate(-180deg) scale(0); } to { transform: none; } }
        .po-sec > p.po-lead { color: var(--text-secondary); margin: 0 0 18px; }
        .po-sec p, .po-sec li { color: var(--text-primary); line-height: 1.6; }
        .po-sec code { background: var(--bg-tertiary); color: var(--text-primary); padding: 2px 6px; border-radius: 6px; font-size: 13px; }

        /* scroll reveal */
        .po-reveal { opacity: 0; transform: translateY(40px); transition: opacity .8s ease, transform .8s cubic-bezier(.2,.8,.2,1); }
        .po-reveal.in { opacity: 1; transform: none; }
        .po-stagger > * { opacity: 0; transform: translateY(20px) scale(.97); transition: opacity .5s ease, transform .5s ease; }
        .in .po-stagger > *, .po-stagger.in > * { opacity: 1; transform: none; }

        /* diagrams */
        .po-svg { width: 100%; height: auto; display: block; }
        .po-svg .t { fill: var(--text-primary); font-size: 14px; }
        .po-svg .ts { fill: var(--text-secondary); font-size: 12px; }
        .po-svg .tb { font-weight: 700; }
        .po-svg .node { transition: transform .3s; transform-box: fill-box; transform-origin: center; cursor: default; }
        .po-svg .node:hover { transform: scale(1.06); }
        .po-svg .flow { stroke-dasharray: 7 6; animation: poDash 1s linear infinite; }
        @keyframes poDash { to { stroke-dashoffset: -26; } }

        /* portals: flip cards */
        .po-portals { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 18px; }
        .po-flip { perspective: 1000px; height: 220px; }
        .po-flip-in { position: relative; width: 100%; height: 100%; transition: transform .8s cubic-bezier(.3,.7,.3,1.2); transform-style: preserve-3d; }
        .po-flip:hover .po-flip-in, .po-flip.flipped .po-flip-in { transform: rotateY(180deg); }
        .po-face { position: absolute; inset: 0; backface-visibility: hidden; border-radius: 16px; padding: 22px; display: flex; flex-direction: column; justify-content: center; color: #fff; }
        .po-face.back { transform: rotateY(180deg); }
        .po-face i.big { font-size: 46px; margin-bottom: 12px; animation: poFloat 3s ease-in-out infinite; }
        @keyframes poFloat { 50% { transform: translateY(-8px); } }
        .po-face h3 { margin: 0 0 4px; font-size: 20px; }
        .po-face p, .po-face li { color: #fff; margin: 0; font-size: 14px; }
        .po-face ul { margin: 6px 0 0; padding-left: 18px; }
        .po-face small { opacity: .85; margin-top: 10px; }
        .po-g1 { background: linear-gradient(135deg, #36d1dc, #5b86e5); }
        .po-g2 { background: linear-gradient(135deg, #f7971e, #ef6f6c); }
        .po-g3 { background: linear-gradient(135deg, #667eea, #764ba2); }
        .po-g4 { background: linear-gradient(135deg, #10b981, #2c5364); }

        /* stepper (login + deploy) */
        .po-steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; position: relative; }
        .po-step { position: relative; background: var(--bg-tertiary); border: 2px solid var(--border-color); border-radius: 14px; padding: 18px 14px; text-align: center; transition: all .5s; }
        .po-step i { font-size: 28px; color: var(--text-muted); transition: all .5s; }
        .po-step h4 { margin: 10px 0 4px; color: var(--text-primary); font-size: 15px; }
        .po-step p { margin: 0; font-size: 13px; color: var(--text-secondary); }
        .po-step.on { border-color: #667eea; background: linear-gradient(135deg, rgba(102,126,234,.14), rgba(118,75,162,.14)); transform: translateY(-6px); box-shadow: 0 10px 24px rgba(102,126,234,.25); }
        .po-step.on i { color: #667eea; transform: scale(1.25) rotate(-8deg); }
        .po-step.done i { color: #10b981; }
        .po-track { height: 6px; background: var(--border-color); border-radius: 3px; margin: 18px 0 6px; overflow: hidden; }
        .po-track span { display: block; height: 100%; width: 0; background: linear-gradient(90deg, #667eea, #10b981); transition: width .6s ease; }
        .po-replay { margin-top: 14px; }

        /* money flow */
        .po-money { position: relative; display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; padding-top: 50px; }
        .po-money .po-step { padding-top: 22px; }
        .po-rupee { position: absolute; top: 0; left: 0; width: 40px; height: 40px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #fff6c9, #ffd966 45%, #bf8f00); color: #7f6000; font-weight: 800; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(191,143,0,.5); transition: left 1s cubic-bezier(.5,0,.3,1.4); }
        .po-rupee.bounce { animation: poBounce .5s; }
        @keyframes poBounce { 30% { transform: translateY(-14px) rotate(20deg); } 60% { transform: translateY(0) rotate(-10deg); } }
        .po-change { margin: 18px auto 0; max-width: 420px; text-align: center; border: 2px dashed #bf8f00; border-radius: 14px; padding: 14px; color: var(--text-primary); animation: poPulse 2.4s ease-in-out infinite; }
        @keyframes poPulse { 0%,100% { box-shadow: 0 0 0 0 rgba(191,143,0,.35); } 50% { box-shadow: 0 0 0 10px rgba(191,143,0,0); } }

        /* feature cards */
        .po-search { width: 100%; max-width: 340px; padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-tertiary); color: var(--text-primary); font-size: 14px; margin-bottom: 16px; }
        .po-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 14px; }
        .po-card { position: relative; overflow: hidden; display: block; text-decoration: none; background: var(--bg-tertiary); border: 1px solid var(--border-color); border-radius: 14px; padding: 16px; transition: transform .3s, box-shadow .3s, border-color .3s; }
        .po-card::before { content: ''; position: absolute; top: 0; left: -100%; width: 60%; height: 100%; background: linear-gradient(120deg, transparent, rgba(255,255,255,.35), transparent); transition: left .7s; }
        .po-card:hover::before { left: 140%; }
        .po-card:hover { transform: translateY(-6px) rotate(-.5deg); box-shadow: 0 12px 26px rgba(102,126,234,.25); border-color: #667eea; }
        .po-card i { font-size: 22px; color: #667eea; transition: transform .4s; }
        .po-card:hover i { transform: scale(1.3) rotate(-12deg); }
        .po-card h4 { margin: 10px 0 4px; color: var(--text-primary); font-size: 15px; }
        .po-card p { margin: 0; color: var(--text-secondary); font-size: 13px; line-height: 1.45; }
        .po-card.hide { display: none; }
        .po-card { border-top: 3px solid var(--c, #667eea); }
        .po-card > i:first-child { color: var(--c, #667eea); }
        .po-card:hover { border-color: var(--c, #667eea); }
        .po-card code { display: inline-block; margin-top: 8px; font-size: 11px; word-break: break-all; }
        .po-card .po-ext { position: absolute; top: 14px; right: 14px; font-size: 12px; color: var(--text-muted); }
        .po-card .po-tag { display: none; position: absolute; top: 12px; right: 12px; font-size: 11px; padding: 2px 8px; border-radius: 10px; color: #fff; background: var(--c, #667eea); }
        .po-cards.searching .po-tag { display: inline-block; }
        .po-cards.searching .po-ext { display: none; }
        .po-card.pop { animation: poPop .45s cubic-bezier(.2,.9,.3,1.3) both; }
        @keyframes poPop { from { opacity: 0; transform: translateY(18px) scale(.92); } to { opacity: 1; transform: none; } }
        .po-areas { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .po-area { display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--border-color); background: var(--bg-tertiary); color: var(--text-primary); padding: 8px 12px; border-radius: 12px; cursor: pointer; font-size: 14px; transition: all .3s; }
        .po-area i { color: var(--c); transition: transform .4s; }
        .po-area b { font-size: 11px; background: var(--border-color); color: var(--text-primary); border-radius: 10px; padding: 1px 7px; }
        .po-area:hover { transform: translateY(-2px); border-color: var(--c); }
        .po-area:hover i { transform: rotate(-15deg) scale(1.2); }
        .po-area.active { background: var(--c); border-color: var(--c); color: #fff; box-shadow: 0 6px 16px rgba(0,0,0,.18); }
        .po-area.active i { color: #fff; }
        .po-area.active b { background: rgba(255,255,255,.3); color: #fff; }
        .po-area-lead { color: var(--text-secondary); margin: 0 0 14px; min-height: 22px; animation: poUp .4s both; }
        .po-empty { display: none; color: var(--text-secondary); padding: 20px; text-align: center; }

        /* tools marquee */
        .po-marquee { overflow: hidden; margin: -10px 0 24px; mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent); -webkit-mask-image: linear-gradient(90deg, transparent, #000 8%, #000 92%, transparent); }
        .po-marquee-in { display: flex; gap: 12px; width: max-content; animation: poMarquee 30s linear infinite; }
        .po-marquee:hover .po-marquee-in { animation-play-state: paused; }
        .po-marquee span { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 20px; background: var(--bg-secondary-solid); border: 1px solid var(--border-color); color: var(--text-primary); font-size: 14px; white-space: nowrap; }
        .po-marquee span i { color: #667eea; }
        @keyframes poMarquee { to { transform: translateX(-50%); } }

        /* desktop sequence */
        .po-seq { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; text-align: center; margin-bottom: 8px; }
        .po-seq .who { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; padding: 10px; border-radius: 10px; font-weight: 600; font-size: 14px; }
        .po-msgs { position: relative; background: repeating-linear-gradient(90deg, transparent 0, transparent calc(12.5% - 1px), var(--border-color) calc(12.5% - 1px), var(--border-color) calc(12.5% + 1px), transparent calc(12.5% + 1px), transparent 25%); border-radius: 10px; padding: 8px 0; }
        .po-msg { position: relative; height: 34px; margin: 6px 0; opacity: 0; transition: opacity .4s; }
        .po-msg.show { opacity: 1; }
        .po-msg .line { position: absolute; top: 16px; height: 2px; background: #667eea; transform: scaleX(0); transition: transform .6s ease; }
        .po-msg.back .line { background: #10b981; }
        .po-msg.show .line { transform: scaleX(1); }
        .po-msg .line::after { content: ''; position: absolute; top: -5px; border: 6px solid transparent; }
        .po-msg:not(.back) .line { transform-origin: left; } .po-msg:not(.back) .line::after { right: -6px; border-left-color: #667eea; }
        .po-msg.back .line { transform-origin: right; } .po-msg.back .line::after { left: -6px; border-right-color: #10b981; }
        .po-msg .lbl { position: absolute; top: -2px; font-size: 12px; color: var(--text-primary); white-space: nowrap; transform: translateX(-50%); background: var(--bg-secondary-solid); padding: 0 6px; border-radius: 4px; }

        /* backup */
        .po-clock { width: 70px; height: 70px; border-radius: 50%; border: 4px solid #667eea; position: relative; margin: 0 auto 8px; }
        .po-clock::before, .po-clock::after { content: ''; position: absolute; left: 50%; bottom: 50%; background: var(--text-primary); transform-origin: bottom center; border-radius: 2px; }
        .po-clock::before { width: 3px; height: 22px; margin-left: -1.5px; animation: poSpin 6s linear infinite; }
        .po-clock::after { width: 4px; height: 15px; margin-left: -2px; animation: poSpin 72s linear infinite; }
        @keyframes poSpin { to { transform: rotate(360deg); } }

        /* database */
        .po-db { display: grid; grid-template-columns: repeat(auto-fill, minmax(165px, 1fr)); gap: 10px; }
        .po-tbl { padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border-color); background: var(--bg-tertiary); color: var(--text-primary); font-size: 13px; font-family: Consolas, monospace; cursor: pointer; transition: all .3s; }
        .po-tbl i { color: #667eea; margin-right: 6px; }
        .po-tbl.hl { border-color: #667eea; background: linear-gradient(135deg, rgba(102,126,234,.18), rgba(118,75,162,.18)); transform: scale(1.06); box-shadow: 0 6px 16px rgba(102,126,234,.3); }
        .po-tbl.dim { opacity: .35; }
        .po-dbnote { margin-top: 12px; min-height: 22px; color: var(--text-secondary); font-size: 14px; }

        /* folders */
        .po-tree, .po-tree ul { list-style: none; margin: 0; padding-left: 20px; }
        .po-tree { padding-left: 0; font-family: Consolas, monospace; font-size: 14px; }
        .po-tree li { margin: 4px 0; color: var(--text-primary); }
        .po-tree .dir { cursor: pointer; user-select: none; }
        .po-tree .dir > i { color: #f6ad55; transition: transform .3s; }
        .po-tree .dir em { color: var(--text-secondary); font-style: normal; font-family: 'Segoe UI', sans-serif; font-size: 13px; margin-left: 6px; }
        .po-tree ul { overflow: hidden; max-height: 600px; transition: max-height .5s ease, opacity .4s; }
        .po-tree li.closed > ul { max-height: 0; opacity: 0; }
        .po-tree li.closed > .dir > i::before { content: "\f07b"; }

        /* deploy timeline */
        .po-timeline { position: relative; padding-left: 34px; }
        .po-timeline::before { content: ''; position: absolute; left: 13px; top: 6px; bottom: 6px; width: 3px; background: var(--border-color); }
        .po-timeline .fill { position: absolute; left: 13px; top: 6px; width: 3px; height: 0; background: linear-gradient(#667eea, #10b981); transition: height 2.4s ease; }
        .in .po-timeline .fill { height: calc(100% - 12px); }
        .po-tl { position: relative; margin-bottom: 18px; opacity: 0; transform: translateX(-20px); transition: all .6s; }
        .in .po-tl { opacity: 1; transform: none; }
        .po-tl .dot { position: absolute; left: -30px; top: 2px; width: 22px; height: 22px; border-radius: 50%; background: var(--bg-secondary-solid); border: 3px solid #667eea; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #10b981; }
        .po-tl h4 { margin: 0 0 2px; color: var(--text-primary); font-size: 15px; }
        .po-tl p { margin: 0; color: var(--text-secondary); font-size: 14px; }

        /* testing table */
        .po-filters { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .po-filters button { border: 1px solid var(--border-color); background: var(--bg-tertiary); color: var(--text-primary); padding: 7px 14px; border-radius: 20px; cursor: pointer; font-size: 13px; transition: all .25s; }
        .po-filters button.active { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; border-color: transparent; }
        .po-table-wrap { overflow-x: auto; }
        .po-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .po-table th { text-align: left; padding: 10px; color: var(--text-secondary); border-bottom: 2px solid var(--border-color); font-size: 13px; }
        .po-table td { padding: 9px 10px; border-bottom: 1px solid var(--border-color); color: var(--text-primary); }
        .po-table tr { transition: background .2s, opacity .3s; }
        .po-table tbody tr:hover { background: var(--bg-tertiary); }
        .po-table a { color: #667eea; font-family: Consolas, monospace; text-decoration: none; }
        .po-table a:hover { text-decoration: underline; }
        .po-risk { padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
        .po-risk.Low { background: #c6efce; color: #276221; }
        .po-risk.Medium { background: #ffe699; color: #7f6000; }
        .po-risk.High { background: #f8cbad; color: #9c2a00; animation: poWarn 2s ease-in-out infinite; }
        @keyframes poWarn { 50% { box-shadow: 0 0 0 4px rgba(239,68,68,.25); } }
        .po-missing { color: var(--text-muted); font-size: 12px; }
        .po-warn { margin-top: 14px; padding: 12px 14px; border-radius: 12px; background: rgba(239,68,68,.1); border-left: 4px solid #ef4444; color: var(--text-primary); font-size: 14px; }

        .po-top { position: fixed; right: 22px; bottom: 22px; width: 46px; height: 46px; border-radius: 50%; border: 0; color: #fff; cursor: pointer; background: linear-gradient(135deg, #667eea, #764ba2); box-shadow: 0 6px 16px rgba(102,126,234,.45); opacity: 0; transform: translateY(20px) scale(.6); transition: all .35s; z-index: 1500; }
        .po-top.show { opacity: 1; transform: none; }
        .po-top:hover { transform: translateY(-3px) scale(1.08); }

        @media (max-width: 900px) {
            .po-page { grid-template-columns: 1fr; padding: 12px; }
            .po-toc { position: static; display: flex; flex-wrap: wrap; gap: 4px; }
            .po-steps, .po-money { grid-template-columns: repeat(2, 1fr); }
            .po-hero { padding: 30px 20px; } .po-hero h1 { font-size: 30px; }
            .po-rupee { display: none; } .po-money { padding-top: 0; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
            .po-reveal, .po-stagger > * { opacity: 1 !important; transform: none !important; }
        }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<div class="po-progress" id="poProgress"></div>

<div class="po-page">
    <nav class="po-toc" id="poToc">
        <?php foreach ($sections as $id => [$label, $icon]): ?>
            <a href="#<?php echo $id; ?>"><i class="fas <?php echo $icon; ?>"></i><?php echo htmlspecialchars($label); ?></a>
        <?php endforeach; ?>
    </nav>

    <main class="po-main">
        <!-- Hero -->
        <header class="po-hero" id="poHero">
            <h1><i class="fas fa-city"></i> SR Colony</h1>
            <div class="po-sub"><span id="poTyped"></span><span class="po-caret"></span></div>
            <div class="po-stats">
                <?php foreach ($stats as $i => [$label, $num, $icon]): ?>
                    <div class="po-stat" style="animation-delay: <?php echo 0.3 + $i * 0.12; ?>s">
                        <i class="fas <?php echo $icon; ?>"></i>
                        <b data-count="<?php echo $num; ?>">0</b>
                        <span><?php echo htmlspecialchars($label); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="po-hero-actions">
                <a class="po-btn white" href="#po-arch"><i class="fas fa-play"></i> Start the tour</a>
                <a class="po-btn ghost" href="dashboard.php"><i class="fas fa-gauge-high"></i> Dashboard</a>
                <a class="po-btn ghost" href="live-monitor.php"><i class="fas fa-heart-pulse"></i> Live Monitor</a>
            </div>
        </header>

        <!-- Tools marquee (list repeated once so the loop is seamless) -->
        <div class="po-marquee" aria-hidden="true"><div class="po-marquee-in">
            <?php for ($k = 0; $k < 2; $k++): foreach ($areas['services'][4] as [$name, , $ic]):
                $iconClass = strpos($ic, 'fa-brands') === 0 ? $ic : 'fas ' . $ic; ?>
                <span><i class="<?php echo $iconClass; ?>"></i><?php echo htmlspecialchars($name); ?></span>
            <?php endforeach; endfor; ?>
        </div></div>

        <!-- Architecture -->
        <section class="po-sec po-reveal" id="po-arch">
            <h2><span class="po-ico"><i class="fas fa-diagram-project"></i></span> How the pieces connect</h2>
            <p class="po-lead">People use the browser, the Super Admin can also use the desktop app. Everything goes through the website.</p>
            <svg class="po-svg" viewBox="0 0 900 400" role="img" aria-label="Architecture diagram">
                <defs>
                    <linearGradient id="poG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#667eea"/><stop offset="1" stop-color="#764ba2"/></linearGradient>
                </defs>
                <g class="node"><rect x="20" y="70" width="180" height="70" rx="12" fill="rgba(54,209,220,.18)" stroke="#36d1dc" stroke-width="2"/><text x="110" y="100" text-anchor="middle" class="t tb">Browser</text><text x="110" y="122" text-anchor="middle" class="ts">Members · Admins · Super Admin</text></g>
                <g class="node"><rect x="20" y="260" width="180" height="70" rx="12" fill="rgba(16,185,129,.16)" stroke="#10b981" stroke-width="2"/><text x="110" y="290" text-anchor="middle" class="t tb">Desktop app</text><text x="110" y="312" text-anchor="middle" class="ts">SR Colony Super Admin.exe</text></g>

                <rect x="290" y="40" width="320" height="320" rx="16" fill="none" stroke="url(#poG)" stroke-width="3"/>
                <text x="450" y="70" text-anchor="middle" class="t tb" style="font-size:16px">PHP Website</text>
                <?php
                $layers = [['auth/ — login pages', '#f7971e'], ['member/ — Member portal', '#36d1dc'], ['admin/ — Admin portal', '#5b86e5'], ['superadmin/ — full control', '#764ba2'], ['desktop-api.php — desktop bridge', '#10b981'], ['includes/ — shared logic + Live Monitor', '#bf8f00']];
                foreach ($layers as $i => [$txt, $col]):
                    $y = 86 + $i * 44; ?>
                    <g class="node"><rect x="310" y="<?php echo $y; ?>" width="280" height="36" rx="9" fill="<?php echo $col; ?>" opacity=".22"><animate attributeName="opacity" values=".22;.45;.22" dur="3s" begin="<?php echo $i * 0.5; ?>s" repeatCount="indefinite"/></rect><text x="450" y="<?php echo $y + 23; ?>" text-anchor="middle" class="t" style="font-size:13px"><?php echo htmlspecialchars($txt); ?></text></g>
                <?php endforeach; ?>

                <g class="node"><rect x="700" y="50" width="180" height="70" rx="12" fill="rgba(191,143,0,.16)" stroke="#bf8f00" stroke-width="2"/><text x="790" y="80" text-anchor="middle" class="t tb">MySQL</text><text x="790" y="102" text-anchor="middle" class="ts">members, transactions…</text></g>
                <g class="node"><rect x="700" y="165" width="180" height="70" rx="12" fill="rgba(16,185,129,.16)" stroke="#10b981" stroke-width="2"/><text x="790" y="195" text-anchor="middle" class="t tb">Google Drive</text><text x="790" y="217" text-anchor="middle" class="ts">proofs + backups</text></g>
                <g class="node"><rect x="700" y="280" width="180" height="70" rx="12" fill="rgba(118,75,162,.16)" stroke="#764ba2" stroke-width="2"/><text x="790" y="310" text-anchor="middle" class="t tb">Gemini AI</text><text x="790" y="332" text-anchor="middle" class="ts">chat assistant</text></g>

                <g fill="none" stroke-width="2.5">
                    <path id="poP1" class="flow" stroke="#36d1dc" d="M200 105 H290"/>
                    <path id="poP2" class="flow" stroke="#10b981" d="M200 295 C245 295 245 304 310 304"/>
                    <path id="poP3" class="flow" stroke="#bf8f00" d="M610 180 C655 180 655 85 700 85"/>
                    <path id="poP4" class="flow" stroke="#10b981" d="M610 200 H700"/>
                    <path id="poP5" class="flow" stroke="#764ba2" d="M610 230 C655 230 655 315 700 315"/>
                </g>
                <?php foreach ([['poP1', '#36d1dc', 2], ['poP2', '#10b981', 2.6], ['poP3', '#bf8f00', 1.8], ['poP4', '#10b981', 3], ['poP5', '#764ba2', 3.4]] as [$p, $c, $d]): ?>
                    <circle r="7" fill="<?php echo $c; ?>"><animateMotion dur="<?php echo $d; ?>s" repeatCount="indefinite" keyPoints="0;1;0" keyTimes="0;.5;1" calcMode="linear"><mpath href="#<?php echo $p; ?>"/></animateMotion><animate attributeName="r" values="5;8;5" dur="1s" repeatCount="indefinite"/></circle>
                <?php endforeach; ?>
                <text x="245" y="95" text-anchor="middle" class="ts">HTTPS</text>
                <text x="245" y="285" text-anchor="middle" class="ts">JSON</text>
                <text x="450" y="390" text-anchor="middle" class="ts">The desktop app never touches the database, so both always show the same data.</text>
            </svg>
        </section>

        <!-- Portals -->
        <section class="po-sec po-reveal" id="po-portals">
            <h2><span class="po-ico"><i class="fas fa-door-open"></i></span> The three portals</h2>
            <p class="po-lead">Hover (or tap) a card to flip it.</p>
            <div class="po-portals po-stagger">
                <div class="po-flip"><div class="po-flip-in">
                    <div class="po-face po-g1"><i class="fas fa-user big"></i><h3>Member</h3><p>member/ — colony members</p><small><i class="fas fa-rotate"></i> flip</small></div>
                    <div class="po-face po-g1 back"><h3>Members can</h3><ul><li>See own payments and balance</li><li>Download statements</li><li>Documents and notifications</li><li>Edit profile</li></ul></div>
                </div></div>
                <div class="po-flip"><div class="po-flip-in">
                    <div class="po-face po-g2"><i class="fas fa-user-tie big"></i><h3>Admin</h3><p>admin/ — committee admins</p><small><i class="fas fa-rotate"></i> flip</small></div>
                    <div class="po-face po-g2 back"><h3>Admins can</h3><ul><li>Manage members</li><li>Add and verify transactions</li><li>Run reports</li><li>Send notifications</li></ul></div>
                </div></div>
                <div class="po-flip"><div class="po-flip-in">
                    <div class="po-face po-g3"><i class="fas fa-crown big"></i><h3>Super Admin</h3><p>superadmin/ — full control</p><small><i class="fas fa-rotate"></i> flip</small></div>
                    <div class="po-face po-g3 back"><h3>Super Admin can</h3><ul><li>Everything admins can</li><li>Years, net balance, bills, reports</li><li>Accounting and events</li><li>Permissions, settings, backups, Live Monitor</li></ul></div>
                </div></div>
                <div class="po-flip"><div class="po-flip-in">
                    <div class="po-face po-g4"><i class="fas fa-desktop big"></i><h3>Desktop app</h3><p>desktop/ — Windows app</p><small><i class="fas fa-rotate"></i> flip</small></div>
                    <div class="po-face po-g4 back"><h3>Desktop app has</h3><ul><li>All Super Admin screens</li><li>Works through the website</li><li>Live Monitor and Data Flow map</li><li>DB Admin</li></ul></div>
                </div></div>
            </div>
        </section>

        <!-- Login -->
        <section class="po-sec po-reveal" id="po-login">
            <h2><span class="po-ico"><i class="fas fa-key"></i></span> Signing in</h2>
            <p class="po-lead">Each sign-in is written to the audit log and shown in the Live Monitor.</p>
            <div class="po-steps" data-player="login">
                <div class="po-step"><i class="fas fa-user-lock"></i><h4>Username + password</h4><p>Login page for your role</p></div>
                <div class="po-step"><i class="fas fa-mobile-screen"></i><h4>2-step code</h4><p>Google Authenticator, if on</p></div>
                <div class="po-step"><i class="fas fa-face-smile"></i><h4>Face Lock</h4><p>Optional, Super Admin</p></div>
                <div class="po-step"><i class="fas fa-gauge-high"></i><h4>Dashboard</h4><p>Member, Admin or Super Admin</p></div>
            </div>
            <div class="po-track" data-track="login"><span></span></div>
            <button class="po-btn main po-replay" data-replay="login"><i class="fas fa-rotate-right"></i> Replay</button>
        </section>

        <!-- Money -->
        <section class="po-sec po-reveal" id="po-money">
            <h2><span class="po-ico"><i class="fas fa-indian-rupee-sign"></i></span> How money moves</h2>
            <p class="po-lead">Watch a payment travel from entry to reports. Every step writes to the audit log.</p>
            <div class="po-money" id="poMoney" data-player="money">
                <div class="po-rupee" id="poRupee">₹</div>
                <div class="po-step"><i class="fas fa-hand-holding-dollar"></i><h4>1. Payment</h4><p>Transaction + proof file (Google Drive)</p></div>
                <div class="po-step"><i class="fas fa-circle-check"></i><h4>2. Verify</h4><p>Admin checks and approves</p></div>
                <div class="po-step"><i class="fas fa-scale-balanced"></i><h4>3. Net Balance</h4><p>Recalculated automatically</p></div>
                <div class="po-step"><i class="fas fa-file-pdf"></i><h4>4. Outputs</h4><p>Reports, Report Builder, bills, PDFs</p></div>
            </div>
            <div class="po-track" data-track="money"><span></span></div>
            <div class="po-change"><b><i class="fas fa-right-left"></i> Change Amount</b><br><span style="color:var(--text-secondary);font-size:14px">Adjustment entries. A year's Change Amount is the sum of its entries; member entries also count in that member's net balance.</span></div>
        </section>

        <!-- Whole project explorer -->
        <section class="po-sec po-reveal" id="po-features">
            <h2><span class="po-ico"><i class="fas fa-grip"></i></span> Everything in the project</h2>
            <p class="po-lead">Pick an area, or search across all of them. Cards with <i class="fas fa-arrow-up-right-from-square"></i> open the page.</p>
            <div class="po-areas" id="poAreas">
                <?php $first = true; foreach ($areas as $key => [$label, $icon, $color]): ?>
                    <button class="po-area<?php echo $first ? ' active' : ''; ?>" data-area="<?php echo $key; ?>" style="--c: <?php echo $color; ?>"><i class="fas <?php echo $icon; ?>"></i><span><?php echo htmlspecialchars($label); ?></span><b><?php echo count($areas[$key][4]); ?></b></button>
                <?php $first = false; endforeach; ?>
            </div>
            <input type="search" class="po-search" id="poSearch" placeholder="Search the whole project…">
            <div class="po-area-lead" id="poAreaLead"></div>
            <div class="po-cards" id="poCards">
                <?php foreach ($areas as $key => [$label, $icon, $color, $lead, $items]):
                    foreach ($items as [$name, $path, $ic, $note, $isLink]):
                        $iconClass = strpos($ic, 'fa-brands') === 0 ? $ic : 'fas ' . $ic;
                        $tag = $isLink ? 'a' : 'div';
                        $href = $isLink ? ' href="../' . htmlspecialchars($path) . '"' : ''; ?>
                        <<?php echo $tag . $href; ?> class="po-card" data-area="<?php echo $key; ?>" style="--c: <?php echo $color; ?>" data-text="<?php echo htmlspecialchars(strtolower($name . ' ' . $path . ' ' . $note . ' ' . $label)); ?>">
                            <i class="<?php echo $iconClass; ?>"></i><?php if ($isLink): ?><i class="fas fa-arrow-up-right-from-square po-ext"></i><?php endif; ?>
                            <h4><?php echo htmlspecialchars($name); ?></h4>
                            <p><?php echo htmlspecialchars($note); ?></p>
                            <code><?php echo htmlspecialchars($path); ?></code>
                            <span class="po-tag"><?php echo htmlspecialchars($label); ?></span>
                        </<?php echo $tag; ?>>
                <?php endforeach; endforeach; ?>
            </div>
            <div class="po-empty" id="poEmpty"><i class="fas fa-magnifying-glass"></i> Nothing matches that search.</div>
            <script>window.poAreaLeads = <?php echo json_encode(array_map(function ($a) { return $a[3]; }, $areas)); ?>;</script>
        </section>

        <!-- Desktop -->
        <section class="po-sec po-reveal" id="po-desktop">
            <h2><span class="po-ico"><i class="fas fa-desktop"></i></span> Desktop app</h2>
            <p class="po-lead">The desktop app runs the real website page through <code>desktop-api.php</code>. Saves use the same form fields, so the same rules run.</p>
            <div class="po-seq"><div class="who">Desktop app</div><div class="who">desktop-api.php</div><div class="who">Super Admin page</div><div class="who">MySQL</div></div>
            <div class="po-msgs" id="poMsgs">
                <?php
                // from column, to column (0-3), label
                $msgs = [[0, 1, 'Request screen'], [1, 2, 'Run the real page'], [2, 3, 'Read data'], [3, 2, 'Rows'], [2, 1, 'Page data'], [1, 0, 'JSON'], [0, 2, 'Save (same form fields)'], [2, 3, 'Write + audit log + net balance']];
                foreach ($msgs as [$a, $b, $lbl]):
                    $l = min($a, $b) * 25 + 12.5; $w = abs($b - $a) * 25; ?>
                    <div class="po-msg<?php echo $b < $a ? ' back' : ''; ?>"><div class="line" style="left: <?php echo $l; ?>%; width: <?php echo $w; ?>%"></div><span class="lbl" style="left: <?php echo $l + $w / 2; ?>%"><?php echo htmlspecialchars($lbl); ?></span></div>
                <?php endforeach; ?>
            </div>
            <ul>
                <li>Open <code>desktop\dist\SR Colony Super Admin.exe</code> (no Python needed), or <code>desktop\run.bat</code>.</li>
                <li>On the login screen enter the site address, click <b>Test</b>, then sign in.</li>
                <li>Downloads go to <code>Downloads\SR Colony</code>. Rebuild the .exe with <code>desktop\build_exe.bat</code>.</li>
            </ul>
        </section>

        <!-- Backup -->
        <section class="po-sec po-reveal" id="po-backup">
            <h2><span class="po-ico"><i class="fas fa-cloud-arrow-up"></i></span> Automatic daily backup</h2>
            <p class="po-lead"><code>includes/backup-helper.php</code> makes one SQL backup a day and keeps a private copy in Google Drive.</p>
            <svg class="po-svg" viewBox="0 0 900 230" role="img" aria-label="Backup flow">
                <g class="node"><rect x="30" y="75" width="190" height="80" rx="12" fill="rgba(191,143,0,.16)" stroke="#bf8f00" stroke-width="2"/><text x="125" y="108" text-anchor="middle" class="t tb">MySQL database</text><text x="125" y="130" text-anchor="middle" class="ts">daily, or cron at 02:00</text></g>
                <g class="node"><rect x="355" y="75" width="190" height="80" rx="12" fill="rgba(102,126,234,.16)" stroke="#667eea" stroke-width="2"/><text x="450" y="108" text-anchor="middle" class="t tb">backup-helper.php</text><text x="450" y="130" text-anchor="middle" class="ts">builds a .sql file</text>
                    <g transform="translate(450 40)"><circle r="18" fill="none" stroke="#667eea" stroke-width="3"/><line y2="-12" stroke="#667eea" stroke-width="3" stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" from="0" to="360" dur="4s" repeatCount="indefinite"/></line></g>
                </g>
                <g class="node"><rect x="680" y="40" width="190" height="64" rx="12" fill="rgba(113,128,150,.14)" stroke="#718096" stroke-width="2"/><text x="775" y="68" text-anchor="middle" class="t tb">Server folder</text><text x="775" y="88" text-anchor="middle" class="ts">backups/ (protected)</text></g>
                <g class="node"><rect x="680" y="130" width="190" height="64" rx="12" fill="rgba(16,185,129,.16)" stroke="#10b981" stroke-width="2"/><text x="775" y="158" text-anchor="middle" class="t tb">Google Drive</text><text x="775" y="178" text-anchor="middle" class="ts">private copy</text></g>
                <g fill="none" stroke-width="2.5">
                    <path id="poB1" class="flow" stroke="#bf8f00" d="M220 115 H355"/>
                    <path id="poB2" class="flow" stroke="#718096" d="M545 105 C610 105 610 72 680 72"/>
                    <path id="poB3" class="flow" stroke="#10b981" d="M545 125 C610 125 610 162 680 162"/>
                </g>
                <?php foreach ([['poB1', '#bf8f00', 0], ['poB2', '#718096', 1], ['poB3', '#10b981', 1]] as [$p, $c, $b]): ?>
                    <g><rect x="-9" y="-11" width="18" height="22" rx="3" fill="<?php echo $c; ?>"/><rect x="-5" y="-5" width="10" height="2" fill="#fff"/><rect x="-5" y="0" width="10" height="2" fill="#fff"/><animateMotion dur="2s" begin="<?php echo $b; ?>s" repeatCount="indefinite"><mpath href="#<?php echo $p; ?>"/></animateMotion></g>
                <?php endforeach; ?>
                <text x="450" y="218" text-anchor="middle" class="ts">Old backups are removed after the "days to keep" setting.</text>
            </svg>
            <p><a class="po-btn main" href="database-backup.php"><i class="fas fa-database"></i> Open Database Backup</a></p>
        </section>

        <!-- Database -->
        <section class="po-sec po-reveal" id="po-db">
            <h2><span class="po-ico"><i class="fas fa-database"></i></span> Database</h2>
            <p class="po-lead">Hover or tap a table to see what it is linked to.</p>
            <?php
            $tables = [
                'members' => ['transactions', 'member_net_balance', 'bills', 'member_documents', 'member_permissions'],
                'admins' => ['audit_logs', 'admin_permissions'],
                'super_admins' => ['audit_logs'],
                'academic_years' => ['transactions', 'member_net_balance', 'change_amount_transactions', 'year_members'],
                'transactions' => ['members', 'academic_years', 'proof_attachments'],
                'member_net_balance' => ['members', 'academic_years'],
                'change_amount_transactions' => ['academic_years', 'members'],
                'bills' => ['members', 'bill_counters'],
                'bill_counters' => ['bills'],
                'proof_attachments' => ['transactions', 'google_drive_settings'],
                'events' => ['event_transactions'],
                'event_transactions' => ['events'],
                'expenses' => ['expense_categories', 'budget_limits'],
                'expense_categories' => ['expenses', 'budget_limits'],
                'budget_limits' => ['expense_categories'],
                'audit_logs' => ['admins', 'super_admins'],
                'report_templates' => ['report_letterheads'],
                'report_letterheads' => ['report_templates', 'bills'],
                'permission_templates' => ['admin_permissions', 'member_permissions'],
                'admin_permissions' => ['admins', 'permission_templates'],
                'member_permissions' => ['members', 'permission_templates'],
                'member_documents' => ['members'],
                'year_members' => ['academic_years', 'members'],
                'notifications' => [],
                'google_drive_settings' => ['proof_attachments', 'database_backups'],
                'database_backups' => ['google_drive_settings'],
                'system_settings' => [],
                'app_monitor' => [],
            ];
            ?>
            <div class="po-db po-stagger" id="poDb">
                <?php foreach ($tables as $name => $links): ?>
                    <div class="po-tbl" data-name="<?php echo $name; ?>" data-links="<?php echo implode(',', $links); ?>"><i class="fas fa-table"></i><?php echo $name; ?></div>
                <?php endforeach; ?>
            </div>
            <div class="po-dbnote" id="poDbNote">&nbsp;</div>
        </section>

        <!-- Folders -->
        <section class="po-sec po-reveal" id="po-folders">
            <h2><span class="po-ico"><i class="fas fa-folder-tree"></i></span> Folder structure</h2>
            <p class="po-lead">Click a folder to open or close it.</p>
            <?php
            $tree = [
                'H:\\SRC' => ['', [
                    'Website' => ['PHP website', [
                        'auth' => ['login, logout, passwords', []],
                        'member' => ['Member portal', []],
                        'admin' => ['Admin portal', []],
                        'superadmin' => ['Super Admin portal', [
                            'accounting' => ['expenses, budgets', ['events' => ['event money', []]]],
                            'api' => ['report-builder, monitor, dbadmin, gdrive-sync', []],
                            'includes' => ['letterhead, report & bill engines', []],
                        ]],
                        'includes' => ['shared helpers: net balance, backup, monitor, Drive', []],
                        'config' => ['config.php (server) · config.local.php (local)', []],
                        'cron' => ['daily-backup.php', []],
                        'database' => ['SQL updates', []],
                        'backups' => ['local .sql backups, never upload', []],
                    ]],
                    'desktop' => ['Tkinter desktop app + .exe', []],
                    'docs' => ['pictures for PROJECT-OVERVIEW.md', []],
                ]],
            ];
            function po_tree($nodes, $depth = 0) {
                echo '<ul' . ($depth === 0 ? ' class="po-tree"' : '') . '>';
                foreach ($nodes as $name => [$note, $kids]) {
                    echo '<li' . ($depth >= 2 && $kids ? ' class="closed"' : '') . '><span class="dir"><i class="fas fa-folder-open"></i> ' . htmlspecialchars($name) . ($note ? '<em>' . htmlspecialchars($note) . '</em>' : '') . '</span>';
                    if ($kids) {
                        po_tree($kids, $depth + 1);
                    }
                    echo '</li>';
                }
                echo '</ul>';
            }
            po_tree($tree);
            ?>
        </section>

        <!-- Deploy -->
        <section class="po-sec po-reveal" id="po-deploy">
            <h2><span class="po-ico"><i class="fas fa-rocket"></i></span> Updating the hosted site</h2>
            <p class="po-lead">Full file list: <code>H:\SRC\DEPLOY-2026-10.md</code>. Never upload <code>config/config.local.php</code>, anything in <code>backups/</code>, or <code>*.md</code> notes.</p>
            <div class="po-timeline">
                <div class="fill"></div>
                <?php
                $deploy = [
                    ['Export the hosted database', 'phpMyAdmin → Export, as an extra safety copy.'],
                    ['Upload the changed files', 'Into the same folders inside Website on the server.'],
                    ['Open Database Update', 'Sign in as Super Admin and open database-update.php.'],
                    ['Back up and apply', 'A full backup is made first, then only missing items are fixed.'],
                    ['Check "Everything is up to date"', 'Open the page again to confirm.'],
                    ['Check settings', 'Database Backup (time, days to keep, Drive copy) and Google Drive (Rename Files in Drive).'],
                ];
                foreach ($deploy as $i => [$h, $p]): ?>
                    <div class="po-tl" style="transition-delay: <?php echo 0.2 + $i * 0.35; ?>s"><span class="dot"><i class="fas fa-check"></i></span><h4><?php echo ($i + 1) . '. ' . htmlspecialchars($h); ?></h4><p><?php echo htmlspecialchars($p); ?></p></div>
                <?php endforeach; ?>
            </div>
            <a class="po-btn main" href="database-update.php"><i class="fas fa-arrows-rotate"></i> Open Database Update</a>
        </section>

        <!-- Testing -->
        <section class="po-sec po-reveal" id="po-tests">
            <h2><span class="po-ico"><i class="fas fa-vial"></i></span> Testing pages</h2>
            <p class="po-lead">All test, debug, check and fix pages. Use them on the local site only.</p>
            <div class="po-filters" id="poFilters">
                <button class="active" data-risk="all">All (<?php echo count($testing); ?>)</button>
                <?php foreach (['Low', 'Medium', 'High'] as $r): ?>
                    <button data-risk="<?php echo $r; ?>"><?php echo $r; ?> (<?php echo count(array_filter($testing, function ($t) use ($r) { return $t[2] === $r; })); ?>)</button>
                <?php endforeach; ?>
            </div>
            <div class="po-table-wrap">
                <table class="po-table">
                    <thead><tr><th>Page</th><th>What it does</th><th>Risk</th></tr></thead>
                    <tbody id="poTests">
                        <?php foreach ($testing as [$path, $note, $risk, $exists]): ?>
                            <tr data-risk="<?php echo $risk; ?>">
                                <td><?php if ($exists): ?><a href="../<?php echo htmlspecialchars($path); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($path); ?></a><?php else: ?><span class="po-missing"><?php echo htmlspecialchars($path); ?> · not on this server</span><?php endif; ?></td>
                                <td><?php echo htmlspecialchars($note); ?></td>
                                <td><span class="po-risk <?php echo $risk; ?>"><?php echo $risk; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="po-warn"><i class="fas fa-triangle-exclamation"></i> <b>Low</b> only reads data · <b>Medium</b> shows server details or creates tables · <b>High</b> changes data or passwords, often without signing in. Delete High pages from the hosted server.</div>
        </section>
    </main>
</div>

<button class="po-top" id="poTop" title="Back to top"><i class="fas fa-arrow-up"></i></button>

<script>
(function () {
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Typed subtitle
    var phrases = ['The whole SR Colony project on one page', 'Chitti & colony money management', 'Member, Admin and Super Admin portals', 'Accounting, events, bills and reports', 'Website + Desktop app, same data', 'Backups, AI chat and live monitoring'];
    var typed = document.getElementById('poTyped'), pi = 0, ci = 0, del = false;
    function type() {
        var p = phrases[pi];
        typed.textContent = p.slice(0, ci);
        if (!del && ci < p.length) { ci++; setTimeout(type, 55); }
        else if (!del) { del = true; setTimeout(type, 1800); }
        else if (ci > 0) { ci--; setTimeout(type, 25); }
        else { del = false; pi = (pi + 1) % phrases.length; setTimeout(type, 300); }
    }
    if (reduce) { typed.textContent = phrases[0]; } else { type(); }

    // Floating coins in the hero
    var hero = document.getElementById('poHero');
    if (!reduce) {
        for (var i = 0; i < 12; i++) {
            var c = document.createElement('div');
            c.className = 'po-coin';
            c.textContent = '₹';
            c.style.left = (5 + Math.random() * 90) + '%';
            c.style.animationDuration = (6 + Math.random() * 6) + 's';
            c.style.animationDelay = (Math.random() * 8) + 's';
            var s = 22 + Math.random() * 18;
            c.style.width = c.style.height = s + 'px';
            hero.appendChild(c);
        }
    }

    // Count-up numbers
    document.querySelectorAll('[data-count]').forEach(function (el) {
        var end = parseInt(el.getAttribute('data-count'), 10) || 0, start = null, dur = 1600;
        if (reduce || !end) { el.textContent = end.toLocaleString(); return; }
        function step(t) {
            if (!start) start = t;
            var k = Math.min((t - start) / dur, 1), e = 1 - Math.pow(1 - k, 3);
            el.textContent = Math.round(end * e).toLocaleString();
            if (k < 1) requestAnimationFrame(step);
        }
        setTimeout(function () { requestAnimationFrame(step); }, 500);
    });

    // Step players (login + money)
    var players = {};
    function play(name) {
        var wrap = document.querySelector('[data-player="' + name + '"]');
        var steps = wrap.querySelectorAll('.po-step');
        var bar = document.querySelector('[data-track="' + name + '"] span');
        var rupee = name === 'money' ? document.getElementById('poRupee') : null;
        clearTimeout(players[name]);
        steps.forEach(function (s) { s.classList.remove('on', 'done'); });
        bar.style.width = '0';
        var i = 0;
        function next() {
            steps.forEach(function (s, j) { s.classList.toggle('on', j === i); s.classList.toggle('done', j < i); });
            bar.style.width = ((i + 1) / steps.length * 100) + '%';
            if (rupee) {
                var st = steps[i];
                rupee.style.left = (st.offsetLeft + st.offsetWidth / 2 - 20) + 'px';
                rupee.classList.remove('bounce'); void rupee.offsetWidth; rupee.classList.add('bounce');
            }
            i++;
            players[name] = setTimeout(i < steps.length ? next : function () { steps.forEach(function (s) { s.classList.remove('on'); s.classList.add('done'); }); players[name] = setTimeout(function () { play(name); }, 2500); }, 1400);
        }
        next();
    }
    document.querySelectorAll('[data-replay]').forEach(function (b) { b.addEventListener('click', function () { play(b.getAttribute('data-replay')); }); });

    // Desktop sequence messages
    var msgTimer;
    function playMsgs() {
        var msgs = document.querySelectorAll('#poMsgs .po-msg');
        clearTimeout(msgTimer);
        msgs.forEach(function (m) { m.classList.remove('show'); });
        var i = 0;
        (function next() {
            if (i < msgs.length) { msgs[i++].classList.add('show'); msgTimer = setTimeout(next, 700); }
            else { msgTimer = setTimeout(playMsgs, 3000); }
        })();
    }

    // Scroll reveal + start animations when a section comes into view
    var started = {};
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
            if (!e.isIntersecting) return;
            var el = e.target;
            el.classList.add('in');
            el.querySelectorAll('.po-stagger > *').forEach(function (k, j) { k.style.transitionDelay = (j * 0.05) + 's'; });
            if (el.id === 'po-login' && !started.login) { started.login = 1; play('login'); }
            if (el.id === 'po-money' && !started.money) { started.money = 1; play('money'); }
            if (el.id === 'po-desktop' && !started.desk) { started.desk = 1; playMsgs(); }
            io.unobserve(el);
        });
    }, { threshold: 0.15 });
    document.querySelectorAll('.po-reveal').forEach(function (s) { io.observe(s); });

    // Scroll progress, scroll spy, back-to-top
    var bar = document.getElementById('poProgress'), top = document.getElementById('poTop');
    var links = document.querySelectorAll('#poToc a');
    var secs = Array.prototype.map.call(links, function (a) { return document.querySelector(a.getAttribute('href')); });
    function onScroll() {
        var h = document.documentElement, max = h.scrollHeight - h.clientHeight;
        bar.style.width = (max > 0 ? h.scrollTop / max * 100 : 0) + '%';
        top.classList.toggle('show', h.scrollTop > 500);
        var cur = 0;
        secs.forEach(function (s, i) { if (s && s.getBoundingClientRect().top < 140) cur = i; });
        links.forEach(function (a, i) { a.classList.toggle('active', i === cur && h.scrollTop > 200); });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
    top.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    links.forEach(function (a) { a.addEventListener('click', function (ev) { ev.preventDefault(); document.querySelector(a.getAttribute('href')).scrollIntoView({ behavior: 'smooth' }); }); });

    // Flip cards on tap (touch screens)
    document.querySelectorAll('.po-flip').forEach(function (f) { f.addEventListener('click', function () { f.classList.toggle('flipped'); }); });

    // Whole-project explorer: area tabs + search across every area
    var cards = document.querySelectorAll('#poCards .po-card'), cardWrap = document.getElementById('poCards');
    var areaBtns = document.querySelectorAll('#poAreas .po-area'), areaLead = document.getElementById('poAreaLead');
    var search = document.getElementById('poSearch'), empty = document.getElementById('poEmpty');
    var curArea = areaBtns.length ? areaBtns[0].getAttribute('data-area') : '';
    function showCards() {
        var q = search.value.trim().toLowerCase(), n = 0;
        cardWrap.classList.toggle('searching', q !== '');
        cards.forEach(function (c) {
            var show = q !== '' ? c.getAttribute('data-text').indexOf(q) !== -1 : c.getAttribute('data-area') === curArea;
            c.classList.toggle('hide', !show);
            c.classList.remove('pop');
            if (show) { void c.offsetWidth; c.style.animationDelay = (n * 0.04) + 's'; c.classList.add('pop'); n++; }
        });
        empty.style.display = n ? 'none' : 'block';
        areaLead.style.animation = 'none'; void areaLead.offsetWidth; areaLead.style.animation = '';
        areaLead.textContent = q !== '' ? n + ' match' + (n === 1 ? '' : 'es') + ' across the whole project' : (window.poAreaLeads[curArea] || '');
    }
    areaBtns.forEach(function (b) {
        b.addEventListener('click', function () {
            curArea = b.getAttribute('data-area');
            search.value = '';
            areaBtns.forEach(function (x) { x.classList.toggle('active', x === b); });
            showCards();
        });
    });
    search.addEventListener('input', showCards);
    showCards();

    // Database table links
    var tbls = document.querySelectorAll('#poDb .po-tbl'), note = document.getElementById('poDbNote');
    function hl(t) {
        var links = t ? t.getAttribute('data-links').split(',').filter(Boolean) : [];
        tbls.forEach(function (x) {
            var n = x.getAttribute('data-name');
            x.classList.toggle('hl', !!t && (x === t || links.indexOf(n) !== -1));
            x.classList.toggle('dim', !!t && x !== t && links.indexOf(n) === -1);
        });
        note.innerHTML = t ? '<b>' + t.getAttribute('data-name') + '</b> ' + (links.length ? '→ ' + links.join(', ') : 'stands on its own') : '&nbsp;';
    }
    tbls.forEach(function (t) {
        t.addEventListener('mouseenter', function () { hl(t); });
        t.addEventListener('click', function () { hl(t); });
    });
    document.getElementById('poDb').addEventListener('mouseleave', function () { hl(null); });

    // Folder tree
    document.querySelectorAll('.po-tree .dir').forEach(function (d) {
        d.addEventListener('click', function () { if (d.nextElementSibling) d.parentNode.classList.toggle('closed'); });
    });

    // Testing filters
    var rows = document.querySelectorAll('#poTests tr');
    document.querySelectorAll('#poFilters button').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('#poFilters button').forEach(function (x) { x.classList.toggle('active', x === b); });
            var r = b.getAttribute('data-risk');
            rows.forEach(function (row) { row.style.display = (r === 'all' || row.getAttribute('data-risk') === r) ? '' : 'none'; });
        });
    });
})();
</script>
</body>
</html>
