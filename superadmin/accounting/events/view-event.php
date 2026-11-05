<?php
/**
 * View Event Details
 * Display single event with all transactions
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../session.php';
require_once '../../../config/config.php';
require_once '../../../includes/logo-helper.php';

$faviconHtml = renderFavicon();
$siteName = getSiteName('SR Colony');

// Get event ID
if (!isset($_GET['id'])) {
    header('Location: manage-events.php');
    exit;
}

$eventId = intval($_GET['id']);

// Fetch event details
$eventStmt = $pdo->prepare("SELECT e.*, ay.YearName
    FROM events e 
    LEFT JOIN academic_years ay ON e.YearID = ay.YearID
    WHERE e.EventID = ?");
$eventStmt->execute([$eventId]);
$event = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    header('Location: manage-events.php?error=not_found');
    exit;
}

// Fetch transactions with proof count
$txnStmt = $pdo->prepare("SELECT et.*,
    (SELECT COUNT(*) FROM proof_attachments pa WHERE pa.EntityType = 'event_transaction' AND pa.EntityID = et.TransactionID AND pa.DeletedAt IS NULL) as ProofCount
    FROM event_transactions et 
    WHERE et.EventID = ? 
    ORDER BY et.TransactionDate DESC, et.TransactionID DESC");
$txnStmt->execute([$eventId]);
$transactions = $txnStmt->fetchAll(PDO::FETCH_ASSOC);

// Success message
$success = isset($_GET['success']) ? $_GET['success'] : null;

// Status colors
$statusColors = [
    'Planned' => '#3b82f6',
    'Ongoing' => '#10b981',
    'Completed' => '#8b5cf6',
    'Cancelled' => '#ef4444'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($event['EventName']); ?> - <?php echo htmlspecialchars($siteName); ?></title>
    <?php echo $faviconHtml; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../shared-styles.css">
    <style>
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            text-decoration: none;
            margin-bottom: 20px;
        }
        
        .back-link:hover { color: #8b5cf6; }
        
        .event-header {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 30px;
            box-shadow: var(--shadow);
            margin-bottom: 25px;
        }
        
        .event-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .event-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        
        .event-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .event-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .status-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            color: white;
        }
        
        .action-buttons {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        
        .btn-danger-outline {
            background: transparent;
            border: 2px solid #ef4444;
            color: #ef4444;
        }
        
        .financial-summary {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
        }
        
        .summary-box {
            text-align: center;
            padding: 20px;
            background: var(--bg-tertiary);
            border-radius: 12px;
        }
        
        .summary-box h4 {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .summary-box .value {
            font-size: 26px;
            font-weight: 700;
        }
        
        .summary-box.budget .value { color: #3b82f6; }
        .summary-box.collections .value { color: #10b981; }
        .summary-box.expenses .value { color: #ef4444; }
        .summary-box.balance .value { color: var(--text-primary); }
        
        .event-details-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 25px;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-primary);
        }
        
        .card-title i { color: #8b5cf6; }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .detail-item label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            display: block;
            margin-bottom: 4px;
        }
        
        .detail-item span {
            font-size: 15px;
            color: var(--text-primary);
        }
        
        .transactions-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .transactions-header {
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .transactions-table th,
        .transactions-table td {
            padding: 14px 20px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .transactions-table th {
            background: var(--bg-tertiary);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-secondary);
        }
        
        .transactions-table tr:hover td {
            background: var(--bg-tertiary);
        }
        
        .type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .type-badge.collection {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        
        .type-badge.expense {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        
        .amount-cell {
            font-weight: 700;
            font-size: 15px;
        }
        
        .amount-cell.positive { color: #10b981; }
        .amount-cell.negative { color: #ef4444; }
        
        .empty-transactions {
            text-align: center;
            padding: 50px 20px;
            color: var(--text-secondary);
        }
        
        .empty-transactions i {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .alert {
            padding: 14px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert.success {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .budget-progress {
            margin-top: 15px;
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 15px 20px;
        }
        
        .budget-progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .budget-progress-bar {
            height: 8px;
            background: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .budget-progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        @media (max-width: 1024px) {
            .financial-summary {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .transactions-table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 768px) {
            .financial-summary {
                grid-template-columns: 1fr;
            }
            
            .event-header-top {
                flex-direction: column;
            }
            
            .action-buttons {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
        <div class="navbar-content">
            <div class="navbar-brand">
                <i class="fas fa-calendar-star"></i>
                <span>Event Management</span>
            </div>
            <div class="navbar-user">
                <button id="themeToggle" class="theme-toggle">
                    <i id="themeIcon" class="fas fa-moon"></i>
                    <span id="themeText">Dark</span>
                </button>
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($superadmin_name); ?></div>
                    <div class="user-role">Event Manager</div>
                </div>
                <a href="dashboard.php" class="btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i>
                    <span>Dashboard</span>
                </a>
                <?php include "../../includes/datetime-widget.php"; ?>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <a href="manage-events.php" class="back-link">
            <i class="fas fa-arrow-left"></i>
            Back to Events
        </a>
        
        <?php if ($success === 'transaction_added'): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                Transaction added successfully!
            </div>
        <?php endif; ?>
        
        <!-- Event Header -->
        <div class="event-header">
            <div class="event-header-top">
                <div>
                    <h1 class="event-title"><?php echo htmlspecialchars($event['EventName']); ?></h1>
                    <div class="event-meta">
                        <span><i class="fas fa-calendar"></i> <?php echo date('M d, Y', strtotime($event['EventDate'])); ?></span>
                        <?php if ($event['Venue']): ?>
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($event['Venue']); ?></span>
                        <?php endif; ?>
                        <?php if ($event['EventType']): ?>
                            <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($event['EventType']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <span class="status-badge" style="background: <?php echo $statusColors[$event['Status']] ?? '#64748b'; ?>">
                        <?php echo $event['Status']; ?>
                    </span>
                </div>
            </div>
            
            <div class="action-buttons">
                <a href="add-transaction.php?event=<?php echo $eventId; ?>&type=Collection" class="btn btn-success">
                    <i class="fas fa-plus"></i> Add Collection
                </a>
                <a href="add-transaction.php?event=<?php echo $eventId; ?>&type=Expense" class="btn btn-danger-outline">
                    <i class="fas fa-minus"></i> Add Expense
                </a>
                <a href="import-event-transactions.php?event=<?php echo $eventId; ?>" class="btn btn-info" style="background: rgba(139, 92, 246, 0.15); color: #8b5cf6; border: 1px solid rgba(139, 92, 246, 0.3);">
                    <i class="fas fa-file-import"></i> Import Excel
                </a>
                <a href="edit-event.php?id=<?php echo $eventId; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Event
                </a>
            </div>
            
            <!-- Financial Summary -->
            <div style="margin-top: 25px;">
                <div class="financial-summary">
                    <div class="summary-box budget">
                        <h4>Budget</h4>
                        <div class="value">₹<?php echo number_format($event['BudgetAmount'], 0); ?></div>
                    </div>
                    <div class="summary-box collections">
                        <h4>Collections</h4>
                        <div class="value">₹<?php echo number_format($event['TotalCollection'], 0); ?></div>
                    </div>
                    <div class="summary-box expenses">
                        <h4>Expenses</h4>
                        <div class="value">₹<?php echo number_format($event['TotalExpense'], 0); ?></div>
                    </div>
                    <div class="summary-box balance">
                        <h4>Balance</h4>
                        <div class="value">₹<?php echo number_format($event['EventBalance'], 0); ?></div>
                    </div>
                </div>
                
                <?php if ($event['BudgetAmount'] > 0): ?>
                    <?php 
                    $spentPercent = min(100, ($event['TotalExpense'] / $event['BudgetAmount']) * 100);
                    $barColor = $spentPercent > 90 ? '#ef4444' : ($spentPercent > 70 ? '#f59e0b' : '#10b981');
                    ?>
                    <div class="budget-progress">
                        <div class="budget-progress-label">
                            <span>Budget Utilized</span>
                            <span><?php echo number_format($spentPercent, 1); ?>%</span>
                        </div>
                        <div class="budget-progress-bar">
                            <div class="budget-progress-fill" style="width: <?php echo $spentPercent; ?>%; background: <?php echo $barColor; ?>;"></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Event Details -->
        <?php if ($event['EventDescription'] || $event['Organizer'] || $event['Notes']): ?>
            <div class="event-details-card">
                <h3 class="card-title"><i class="fas fa-info-circle"></i> Event Details</h3>
                <div class="details-grid">
                    <?php if ($event['EventDescription']): ?>
                        <div class="detail-item" style="grid-column: span 2;">
                            <label>Description</label>
                            <span><?php echo nl2br(htmlspecialchars($event['EventDescription'])); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($event['EventEndDate'] && $event['EventEndDate'] !== $event['EventDate']): ?>
                        <div class="detail-item">
                            <label>End Date</label>
                            <span><?php echo date('M d, Y', strtotime($event['EventEndDate'])); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($event['Organizer']): ?>
                        <div class="detail-item">
                            <label>Organizer</label>
                            <span><?php echo htmlspecialchars($event['Organizer']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($event['ContactNumber']): ?>
                        <div class="detail-item">
                            <label>Contact</label>
                            <span><?php echo htmlspecialchars($event['ContactNumber']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($event['YearName']): ?>
                        <div class="detail-item">
                            <label>Academic Year</label>
                            <span><?php echo htmlspecialchars($event['YearName']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($event['Notes']): ?>
                        <div class="detail-item" style="grid-column: span 2;">
                            <label>Notes</label>
                            <span><?php echo nl2br(htmlspecialchars($event['Notes'])); ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Transactions -->
        <div class="transactions-card">
            <div class="transactions-header">
                <h3 class="card-title" style="margin: 0;"><i class="fas fa-list"></i> Transactions</h3>
                <a href="add-transaction.php?event=<?php echo $eventId; ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Transaction
                </a>
            </div>
            
            <?php if (empty($transactions)): ?>
                <div class="empty-transactions">
                    <i class="fas fa-receipt"></i>
                    <h4>No Transactions Yet</h4>
                    <p>Add collections or expenses for this event</p>
                </div>
            <?php else: ?>
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Person/Vendor</th>
                            <th>Payment</th>
                            <th>Amount</th>
                            <th>Proofs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $txn): ?>
                            <tr>
                                <td>#<?php echo $txn['TransactionID']; ?></td>
                                <td>
                                    <span class="type-badge <?php echo strtolower($txn['TransactionType']); ?>">
                                        <?php echo $txn['TransactionType']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($txn['TransactionDate'])); ?></td>
                                <td><?php echo htmlspecialchars($txn['Description']); ?></td>
                                <td>
                                    <?php 
                                    if ($txn['TransactionType'] === 'Collection') {
                                        echo htmlspecialchars($txn['ContributorName'] ?: '-');
                                    } else {
                                        echo htmlspecialchars($txn['VendorName'] ?: '-');
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($txn['PaymentMode']); ?></td>
                                <td class="amount-cell <?php echo $txn['TransactionType'] === 'Collection' ? 'positive' : 'negative'; ?>">
                                    <?php echo $txn['TransactionType'] === 'Collection' ? '+' : '-'; ?>₹<?php echo number_format($txn['Amount'], 0); ?>
                                </td>
                                <td>
                                    <?php 
                                    $proofCount = intval($txn['ProofCount'] ?? 0);
                                    if ($proofCount > 0): ?>
                                        <span onclick="viewProofs(<?php echo $txn['TransactionID']; ?>, 'event_transaction')" style="cursor:pointer; background: rgba(139,92,246,0.15); color: #8b5cf6; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                            <i class="fas fa-paperclip"></i> <?php echo $proofCount; ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-secondary); font-size: 12px;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="../../theme-manager.js"></script>
    <script>
        const PROOF_API_URL = '../../api/proof-upload.php';
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function viewProofs(entityId, entityType) {
            const modal = document.getElementById('proofViewerModal');
            const content = document.getElementById('proofViewerContent');
            content.innerHTML = '<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:#8b5cf6;"></i><p style="margin-top:10px;color:var(--text-secondary);">Loading proofs...</p></div>';
            modal.style.display = 'flex';
            
            fetch(PROOF_API_URL + '?action=list&entity_type=' + entityType + '&entity_id=' + entityId)
                .then(r => r.json())
                .then(data => {
                    let html = '';
                    if (data.success && data.attachments.length > 0) {
                        data.attachments.forEach(att => {
                            const isImage = att.MimeType && att.MimeType.startsWith('image/');
                            const storageLabel = att.GoogleDriveFileID 
                                ? '<span style="font-size:11px;color:#4285f4;"><i class="fab fa-google-drive"></i> Google Drive</span>'
                                : '<span style="font-size:11px;color:#94a3b8;"><i class="fas fa-hdd"></i> Local Storage</span>';
                            const viewUrl = escapeHtml(att.viewUrl || '');
                            const gdriveId = att.GoogleDriveFileID || '';
                            const mimeType = att.MimeType || '';
                            const thumbUrl = att.thumbnailUrl || att.viewUrl;
                            html += `<div onclick="openDocumentViewer('${viewUrl}', '${escapeHtml(att.OriginalName)}', '${mimeType}', '${gdriveId}')" style="display:flex;align-items:center;gap:12px;background:var(--bg-tertiary,#f3f4f6);padding:15px;border-radius:10px;margin-bottom:10px;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.transform='translateX(4px)';this.style.boxShadow='0 2px 8px rgba(139,92,246,0.15)'" onmouseout="this.style.transform='';this.style.boxShadow=''">
                                ${isImage ? '<img src="' + thumbUrl + '" style="width:60px;height:60px;object-fit:cover;border-radius:8px;">' : '<div style="width:60px;height:60px;display:flex;align-items:center;justify-content:center;background:rgba(139,92,246,0.15);border-radius:8px;font-size:24px;color:#8b5cf6;"><i class="fas ' + att.iconClass + '"></i></div>'}
                                <div style="flex:1;">
                                    <div style="font-weight:600;color:var(--text-primary);">${escapeHtml(att.OriginalName)}</div>
                                    <div style="font-size:12px;color:var(--text-secondary);">${att.formattedSize} &bull; ${att.UploadedAt || ''}</div>
                                    <div style="margin-top:3px;">${storageLabel}</div>
                                </div>
                                <div style="color:#8b5cf6;font-size:18px;"><i class="fas fa-eye"></i></div>
                            </div>`;
                        });
                    } else {
                        html = '<p style="color:var(--text-secondary);text-align:center;padding:20px;">No proof files attached.</p>';
                    }
                    content.innerHTML = html;
                })
                .catch(() => { content.innerHTML = '<p style="color:#ef4444;text-align:center;">Failed to load proofs.</p>'; });
        }
        
        function openDocumentViewer(url, fileName, mimeType, gdriveId) {
            const modal = document.getElementById('documentViewerModal');
            const contentArea = document.getElementById('docViewerContent');
            const titleEl = document.getElementById('docViewerTitle');
            const downloadBtn = document.getElementById('docViewerDownload');
            const openFullBtn = document.getElementById('docViewerOpenFull');
            
            titleEl.textContent = fileName;
            downloadBtn.onclick = function() { window.open(url, '_blank'); };
            openFullBtn.onclick = function() { window.open(url, '_blank'); };
            
            const isImage = mimeType && mimeType.startsWith('image/');
            const isPdf = mimeType === 'application/pdf';
            let previewUrl = url;
            if (gdriveId) previewUrl = 'https://drive.google.com/file/d/' + gdriveId + '/preview';
            
            if (isImage) {
                contentArea.innerHTML = `<div style="text-align:center;padding:10px;max-height:calc(80vh - 140px);overflow:auto;">
                    <img src="${url}" style="max-width:100%;max-height:70vh;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,0.2);" alt="${escapeHtml(fileName)}">
                </div>`;
            } else if (isPdf || gdriveId) {
                contentArea.innerHTML = `<iframe src="${previewUrl}" style="width:100%;height:calc(80vh - 140px);border:none;" allowfullscreen></iframe>`;
            } else {
                contentArea.innerHTML = `<div style="text-align:center;padding:60px 20px;">
                    <i class="fas fa-file" style="font-size:64px;color:#8b5cf6;margin-bottom:16px;display:block;"></i>
                    <h4 style="margin:0 0 8px;color:var(--text-primary);">${escapeHtml(fileName)}</h4>
                    <p style="color:var(--text-secondary);margin:0 0 20px;">This file type cannot be previewed inline.</p>
                    <a href="${url}" target="_blank" style="display:inline-block;padding:10px 24px;background:#8b5cf6;color:white;border-radius:8px;text-decoration:none;font-weight:600;"><i class="fas fa-download"></i> Download File</a>
                </div>`;
            }
            modal.style.display = 'flex';
        }
        
        function closeDocumentViewer() {
            document.getElementById('documentViewerModal').style.display = 'none';
            document.getElementById('docViewerContent').innerHTML = '';
        }
        
        function closeProofViewer() {
            document.getElementById('proofViewerModal').style.display = 'none';
        }
        
        document.getElementById('proofViewerModal').addEventListener('click', function(e) {
            if (e.target === this) closeProofViewer();
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') { closeProofViewer(); closeDocumentViewer(); }
        });
    </script>

    <!-- Proof Viewer Modal -->
    <div id="proofViewerModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:10000;justify-content:center;align-items:center;backdrop-filter:blur(4px);">
        <div style="background:var(--bg-primary,#fff);border-radius:16px;width:90%;max-width:600px;max-height:80vh;overflow-y:auto;box-shadow:0 25px 50px rgba(0,0,0,0.25);">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid var(--border-color,#e5e7eb);">
                <h3 style="margin:0;font-size:16px;"><i class="fas fa-paperclip" style="color:#8b5cf6;"></i> Proof Files</h3>
                <button onclick="closeProofViewer()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-secondary);padding:4px 8px;border-radius:6px;">&times;</button>
            </div>
            <div id="proofViewerContent" style="padding:20px 24px;"></div>
        </div>
    </div>

    <!-- Document Viewer Modal -->
    <div id="documentViewerModal" onclick="if(event.target===this)closeDocumentViewer()" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.75);z-index:10001;justify-content:center;align-items:center;backdrop-filter:blur(6px);">
        <div style="background:var(--bg-primary,#1a1a2e);border-radius:16px;width:94%;max-width:900px;max-height:90vh;overflow:hidden;box-shadow:0 25px 60px rgba(0,0,0,0.4);display:flex;flex-direction:column;">
            <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 24px;border-bottom:1px solid var(--border-color,#2d2d44);">
                <h3 style="margin:0;font-size:15px;color:var(--text-primary,#fff);display:flex;align-items:center;gap:8px;"><i class="fas fa-eye" style="color:#8b5cf6;"></i> <span id="docViewerTitle">View Document</span></h3>
                <button onclick="closeDocumentViewer()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--text-secondary);padding:4px 8px;border-radius:6px;line-height:1;">&times;</button>
            </div>
            <div id="docViewerContent" style="flex:1;overflow:auto;background:#000;min-height:300px;"></div>
            <div style="display:flex;justify-content:center;gap:12px;padding:14px 24px;border-top:1px solid var(--border-color,#2d2d44);">
                <button id="docViewerDownload" style="display:flex;align-items:center;gap:6px;padding:8px 20px;background:#ef4444;color:white;border:none;border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;"><i class="fas fa-download"></i> Download</button>
                <button id="docViewerOpenFull" style="display:flex;align-items:center;gap:6px;padding:8px 20px;background:var(--bg-tertiary,#2d2d44);color:var(--text-primary,#fff);border:1px solid var(--border-color,#3d3d5c);border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;"><i class="fas fa-external-link-alt"></i> Open Full</button>
                <button onclick="closeDocumentViewer()" style="display:flex;align-items:center;gap:6px;padding:8px 20px;background:var(--bg-tertiary,#2d2d44);color:var(--text-primary,#fff);border:1px solid var(--border-color,#3d3d5c);border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;">Close</button>
            </div>
        </div>
    </div>
</body>
</html>
