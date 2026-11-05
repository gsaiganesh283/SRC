<!-- Notification Bell Icon and Dropdown -->
<style>
.notification-bell {
    position: relative;
    display: inline-block;
    margin-left: 20px;
}

.bell-icon {
    font-size: 24px;
    color: #64748b;
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
}

.bell-icon:hover {
    color: #667eea;
    transform: scale(1.1);
}

.notification-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border-radius: 50%;
    min-width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: bold;
    padding: 2px 5px;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.4);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.1); }
}

.notification-dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 400px;
    max-height: 500px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    display: none;
    z-index: 1000;
    overflow: hidden;
}

.notification-dropdown.active {
    display: block;
    animation: slideDown 0.3s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.notification-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notification-header h3 {
    font-size: 16px;
    margin: 0;
}

.mark-all-read {
    background: rgba(255,255,255,0.2);
    border: none;
    color: white;
    padding: 5px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.3s;
}

.mark-all-read:hover {
    background: rgba(255,255,255,0.3);
}

.notification-list {
    max-height: 400px;
    overflow-y: auto;
}

.notification-item {
    padding: 15px 20px;
    border-bottom: 1px solid #e2e8f0;
    cursor: pointer;
    transition: all 0.3s;
    position: relative;
}

.notification-item:hover {
    background: #f8fafc;
}

.notification-item.unread {
    background: #eef2ff;
    border-left: 4px solid #667eea;
}

.notification-item.unread:hover {
    background: #e0e7ff;
}

.notification-type-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    margin-right: 12px;
    float: left;
}

.notification-type-icon.payment {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.notification-type-icon.transaction {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
}

.notification-type-icon.member {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
}

.notification-type-icon.system {
    background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
    color: white;
}

.notification-type-icon.alert {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
}

.notification-content {
    overflow: hidden;
}

.notification-title {
    font-weight: 600;
    color: #1e293b;
    font-size: 14px;
    margin-bottom: 4px;
}

.notification-message {
    color: #64748b;
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 4px;
}

.notification-payment-details {
    background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
    border-left: 3px solid #10b981;
    padding: 8px 12px;
    margin-top: 8px;
    border-radius: 6px;
    font-size: 13px;
}

.notification-payment-amount {
    font-weight: bold;
    color: #059669;
    font-size: 16px;
}

.notification-time {
    color: #94a3b8;
    font-size: 11px;
    margin-top: 4px;
}

.notification-footer {
    padding: 12px 20px;
    text-align: center;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
}

.view-all-link {
    color: #667eea;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.view-all-link:hover {
    text-decoration: underline;
}

.empty-notifications {
    padding: 40px 20px;
    text-align: center;
    color: #94a3b8;
}

.empty-notifications i {
    font-size: 48px;
    margin-bottom: 12px;
    color: #cbd5e1;
}

/* Scrollbar styling */
.notification-list::-webkit-scrollbar {
    width: 6px;
}

.notification-list::-webkit-scrollbar-track {
    background: #f1f5f9;
}

.notification-list::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.notification-list::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}
</style>

<?php
// Get notification count and recent notifications
$notificationCount = 0;
$recentNotifications = [];

if (isset($_SESSION['superadmin_id'])) {
    $notificationCount = getUnreadNotificationCount('SuperAdmin', $_SESSION['superadmin_id']);
    $recentNotifications = getRecentNotifications('SuperAdmin', $_SESSION['superadmin_id'], 10);
} elseif (isset($_SESSION['admin_id'])) {
    $notificationCount = getUnreadNotificationCount('Admin', $_SESSION['admin_id']);
    $recentNotifications = getRecentNotifications('Admin', $_SESSION['admin_id'], 10);
} elseif (isset($_SESSION['member_id'])) {
    $notificationCount = getUnreadNotificationCount('Member', $_SESSION['member_id']);
    $recentNotifications = getRecentNotifications('Member', $_SESSION['member_id'], 10);
}

function formatTimeAgo($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M d, Y', $time);
}
?>

<div class="notification-bell">
    <div class="bell-icon" onclick="toggleNotifications()">
        <i class="fas fa-bell"></i>
        <?php if ($notificationCount > 0): ?>
        <span class="notification-badge"><?php echo $notificationCount > 99 ? '99+' : $notificationCount; ?></span>
        <?php endif; ?>
    </div>
    
    <div class="notification-dropdown" id="notificationDropdown">
        <div class="notification-header">
            <h3><i class="fas fa-bell"></i> Notifications</h3>
            <?php if ($notificationCount > 0): ?>
            <button class="mark-all-read" onclick="markAllAsRead()">
                <i class="fas fa-check-double"></i> Mark all read
            </button>
            <?php endif; ?>
        </div>
        
        <div class="notification-list">
            <?php if (empty($recentNotifications)): ?>
            <div class="empty-notifications">
                <i class="fas fa-bell-slash"></i>
                <p>No notifications yet</p>
            </div>
            <?php else: ?>
                <?php foreach ($recentNotifications as $notification): ?>
                <div class="notification-item <?php echo $notification['IsRead'] ? '' : 'unread'; ?>" 
                     onclick="markNotificationAsRead(<?php echo $notification['NotificationID']; ?>, '<?php echo $notification['ActionURL'] ?? ''; ?>')">
                    
                    <div class="notification-type-icon <?php echo strtolower($notification['NotificationType']); ?>">
                        <?php
                        $icons = [
                            'Payment' => 'fa-dollar-sign',
                            'Transaction' => 'fa-exchange-alt',
                            'Member' => 'fa-user',
                            'System' => 'fa-cog',
                            'Settings' => 'fa-sliders-h',
                            'Permission' => 'fa-shield-alt',
                            'Alert' => 'fa-exclamation-triangle'
                        ];
                        $icon = $icons[$notification['NotificationType']] ?? 'fa-info-circle';
                        ?>
                        <i class="fas <?php echo $icon; ?>"></i>
                    </div>
                    
                    <div class="notification-content">
                        <div class="notification-title"><?php echo htmlspecialchars($notification['Title']); ?></div>
                        <div class="notification-message"><?php echo htmlspecialchars($notification['Message']); ?></div>
                        
                        <?php if ($notification['PaymentAmount']): ?>
                        <div class="notification-payment-details">
                            <i class="fas fa-rupee-sign"></i> 
                            <span class="notification-payment-amount">₹<?php echo number_format($notification['PaymentAmount'], 2); ?></span>
                            <?php if ($notification['PaymentType']): ?>
                            <span style="color: #64748b;"> - <?php echo htmlspecialchars($notification['PaymentType']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="notification-time">
                            <i class="far fa-clock"></i> <?php echo formatTimeAgo($notification['CreatedAt']); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($recentNotifications)): ?>
        <div class="notification-footer">
            <a href="notifications.php" class="view-all-link">
                <i class="fas fa-list"></i> View All Notifications
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    dropdown.classList.toggle('active');
}

function markNotificationAsRead(notificationId, actionUrl) {
    fetch('mark-notification-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notificationId: notificationId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update UI
            const item = event.currentTarget;
            item.classList.remove('unread');
            
            // Update badge count
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                let count = parseInt(badge.textContent);
                count = Math.max(0, count - 1);
                if (count === 0) {
                    badge.remove();
                } else {
                    badge.textContent = count;
                }
            }
            
            // Navigate if there's an action URL
            if (actionUrl) {
                setTimeout(() => {
                    window.location.href = actionUrl;
                }, 300);
            }
        }
    })
    .catch(error => console.error('Error:', error));
}

function markAllAsRead() {
    if (!confirm('Mark all notifications as read?')) return;
    
    fetch('mark-all-notifications-read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Remove all unread classes
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
            
            // Remove badge
            const badge = document.querySelector('.notification-badge');
            if (badge) badge.remove();
            
            // Remove mark all button
            const markAllBtn = document.querySelector('.mark-all-read');
            if (markAllBtn) markAllBtn.style.display = 'none';
        }
    })
    .catch(error => console.error('Error:', error));
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const notificationBell = document.querySelector('.notification-bell');
    const dropdown = document.getElementById('notificationDropdown');
    
    if (notificationBell && !notificationBell.contains(event.target)) {
        dropdown.classList.remove('active');
    }
});

// Auto-refresh notification count every 30 seconds
setInterval(function() {
    fetch('get-notification-count.php')
        .then(response => response.json())
        .then(data => {
            const badge = document.querySelector('.notification-badge');
            const bellIcon = document.querySelector('.bell-icon');
            
            if (data.count > 0) {
                if (badge) {
                    badge.textContent = data.count > 99 ? '99+' : data.count;
                } else {
                    const newBadge = document.createElement('span');
                    newBadge.className = 'notification-badge';
                    newBadge.textContent = data.count > 99 ? '99+' : data.count;
                    bellIcon.appendChild(newBadge);
                }
            } else {
                if (badge) badge.remove();
            }
        })
        .catch(error => console.error('Error:', error));
}, 30000);
</script>
