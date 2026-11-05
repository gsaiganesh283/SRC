<?php
/**
 * Helper Functions for SR Colony Chitti Management System
 */

/**
 * Sanitize input string
 */
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Format currency
 */
function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

/**
 * Format date
 */
function formatDate($date, $format = 'd-m-Y') {
    return date($format, strtotime($date));
}

/**
 * Generate random password
 */
function generateRandomPassword($length = 8) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[random_int(0, strlen($characters) - 1)];
    }
    return $password;
}

/**
 * Send email notification (placeholder)
 */
function sendEmail($to, $subject, $message) {
    // Implement email sending logic here
    return mail($to, $subject, $message);
}

/**
 * Log activity
 */
function logActivity($userId, $action, $details = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_log (user_id, action, details, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $action, $details]);
    } catch (Exception $e) {
        // Silent fail
    }
}

/**
 * Get member name by ID
 */
function getMemberName($memberId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT MemberName FROM members WHERE MemberID = ?");
        $stmt->execute([$memberId]);
        $result = $stmt->fetch();
        return $result ? $result['MemberName'] : 'Unknown';
    } catch (Exception $e) {
        return 'Unknown';
    }
}

/**
 * Upload file with validation
 */
function uploadFile($file, $targetDir, $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'], $maxSize = 5242880) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload failed'];
    }
    
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileExtension, $allowedTypes)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File size too large'];
    }
    
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }
    
    $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
    $targetPath = $targetDir . '/' . $fileName;
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => true, 'path' => $targetPath, 'filename' => $fileName];
    }
    
    return ['success' => false, 'message' => 'Failed to move uploaded file'];
}

/**
 * Check if admin has specific permission
 */
function hasAdminPermission($adminID, $permission) {
    global $pdo;
    // Permission names are interpolated as column identifiers below
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $permission)) {
        return false;
    }
    try {
        // First check if admin_permissions table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'admin_permissions'");
        if ($stmt->rowCount() == 0) {
            // Table doesn't exist, grant all permissions
            return true;
        }
        
        // Check if the permission column exists
        $stmt = $pdo->prepare("SHOW COLUMNS FROM admin_permissions LIKE ?");
        $stmt->execute([$permission]);
        if ($stmt->rowCount() == 0) {
            // Column doesn't exist, grant permission
            return true;
        }
        
        // Check the actual permission value
        $stmt = $pdo->prepare("SELECT `$permission` FROM admin_permissions WHERE AdminID = ?");
        $stmt->execute([$adminID]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            // No permissions record exists, grant all permissions
            return true;
        }
        
        return isset($result[$permission]) && $result[$permission] == 1;
    } catch (Exception $e) {
        // On error, deny permission (fail closed)
        error_log("Permission check error for admin $adminID, permission $permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if member has specific permission
 */
function hasMemberPermission($memberID, $permission) {
    global $pdo;
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $permission)) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT `$permission` FROM member_permissions WHERE MemberID = ?");
        $stmt->execute([$memberID]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result[$permission] == 1;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Require admin login - redirect to login if not authenticated
 */
function requireAdminLogin() {
    if (!isset($_SESSION['admin_id'])) {
        header('Location: ../auth/admin-login.php');
        exit();
    }
}

/**
 * Require admin - alias for requireAdminLogin
 */
function requireAdmin() {
    requireAdminLogin();
}

/**
 * Require member login - redirect to login if not authenticated
 */
function requireMemberLogin() {
    if (!isset($_SESSION['member_id'])) {
        header('Location: ../auth/member-login.php');
        exit();
    }
}

/**
 * Check admin permission or redirect with error
 */
function requireAdminPermission($adminID, $permission, $redirectUrl = '../admin/dashboard.php') {
    if (!hasAdminPermission($adminID, $permission)) {
        $_SESSION['error'] = 'You do not have permission to perform this action.';
        header('Location: ' . $redirectUrl);
        exit();
    }
}

/**
 * Check member permission or redirect with error
 */
function requireMemberPermission($memberID, $permission, $redirectUrl = '../member/dashboard.php') {
    if (!hasMemberPermission($memberID, $permission)) {
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: ' . $redirectUrl);
        exit();
    }
}

/**
 * Get all admin permissions as array
 */
function getAdminPermissions($adminID) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM admin_permissions WHERE AdminID = ?");
        $stmt->execute([$adminID]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get all member permissions as array
 */
function getMemberPermissions($memberID) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM member_permissions WHERE MemberID = ?");
        $stmt->execute([$memberID]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Create audit log entry
 * Maps to actual audit_logs table structure: LogID, UserType, UserID, Username, Action, 
 * TableName, RecordID, OldData, NewData, IPAddress, UserAgent, CreatedAt
 */
function createAuditLog($userID, $userType, $module, $action, $description = '', $tableName = null, $recordID = null, $oldData = null, $newData = null, $severity = 'Info') {
    global $pdo;
    try {
        // Get username based on user type
        $username = '';
        if ($userType === 'SuperAdmin' && isset($_SESSION['superadmin_username'])) {
            $username = $_SESSION['superadmin_username'];
        } elseif ($userType === 'Admin' && isset($_SESSION['admin_username'])) {
            $username = $_SESSION['admin_username'];
        } elseif ($userType === 'Member' && isset($_SESSION['member_name'])) {
            $username = $_SESSION['member_name'];
        }
        
        // Combine description with module if provided
        $fullAction = $module ? "[$module] $action" : $action;
        
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs 
            (UserType, UserID, Username, Action, TableName, RecordID, OldData, NewData, IPAddress, UserAgent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userType,
            $userID,
            $username,
            $fullAction . ($description ? ': ' . $description : ''),
            $tableName,
            $recordID,
            $oldData,
            $newData,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Create notification
 */
function createNotification($recipientType, $recipientID, $notificationType, $title, $message, $options = []) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications 
            (RecipientType, RecipientID, NotificationType, Title, Message, RelatedEntity, RelatedEntityID, 
             PaymentAmount, PaymentType, Priority, ActionURL, ExpiresAt)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $recipientType,
            $recipientID,
            $notificationType,
            $title,
            $message,
            $options['relatedEntity'] ?? null,
            $options['relatedEntityID'] ?? null,
            $options['paymentAmount'] ?? null,
            $options['paymentType'] ?? null,
            $options['priority'] ?? 'Medium',
            $options['actionURL'] ?? null,
            $options['expiresAt'] ?? null
        ]);
        return true;
    } catch (Exception $e) {
        error_log("Notification creation failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get unread notification count
 */
function getUnreadNotificationCount($recipientType, $recipientID) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE RecipientType = ? 
            AND RecipientID = ? 
            AND IsRead = 0
            AND (ExpiresAt IS NULL OR ExpiresAt > NOW())
        ");
        $stmt->execute([$recipientType, $recipientID]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int)$result['count'] : 0;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Get recent notifications
 */
function getRecentNotifications($recipientType, $recipientID, $limit = 10, $unreadOnly = false) {
    global $pdo;
    try {
        $query = "
            SELECT * FROM notifications 
            WHERE RecipientType = ? 
            AND RecipientID = ? 
            AND (ExpiresAt IS NULL OR ExpiresAt > NOW())
        ";
        
        if ($unreadOnly) {
            $query .= " AND IsRead = 0";
        }
        
        $query .= " ORDER BY CreatedAt DESC LIMIT ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$recipientType, $recipientID, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Mark notification as read
 */
function markNotificationRead($notificationID) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET IsRead = 1, ReadAt = NOW() WHERE NotificationID = ?");
        $stmt->execute([$notificationID]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Mark all notifications as read
 */
function markAllNotificationsRead($recipientType, $recipientID) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            UPDATE notifications 
            SET IsRead = 1, ReadAt = NOW() 
            WHERE RecipientType = ? 
            AND RecipientID = ? 
            AND IsRead = 0
        ");
        $stmt->execute([$recipientType, $recipientID]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get user setting
 */
function getUserSetting($userType, $userID, $settingKey, $default = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT SettingValue, SettingType 
            FROM user_settings 
            WHERE UserType = ? AND UserID = ? AND SettingKey = ?
        ");
        $stmt->execute([$userType, $userID, $settingKey]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return $default;
        }
        
        // Convert based on type
        $value = $result['SettingValue'];
        switch ($result['SettingType']) {
            case 'number':
                return (int)$value;
            case 'boolean':
                return $value === '1' || $value === 'true';
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Set user setting
 */
function setUserSetting($userType, $userID, $settingKey, $settingValue, $settingType = 'string', $category = 'General') {
    global $pdo;
    try {
        // Convert value based on type
        if ($settingType === 'boolean') {
            $settingValue = $settingValue ? '1' : '0';
        } elseif ($settingType === 'json') {
            $settingValue = json_encode($settingValue);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO user_settings (UserType, UserID, SettingKey, SettingValue, SettingType, Category)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE SettingValue = ?, UpdatedAt = NOW()
        ");
        $stmt->execute([$userType, $userID, $settingKey, $settingValue, $settingType, $category, $settingValue]);
        return true;
    } catch (Exception $e) {
        error_log("Setting update failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Get notification preferences
 */
function getNotificationPreferences($userType, $userID) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM notification_preferences WHERE UserType = ? AND UserID = ?");
        $stmt->execute([$userType, $userID]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Create default if not exists
        if (!$result) {
            $pdo->prepare("
                INSERT INTO notification_preferences (UserType, UserID) VALUES (?, ?)
            ")->execute([$userType, $userID]);
            
            return getNotificationPreferences($userType, $userID);
        }
        
        return $result;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Update notification preferences
 */
function updateNotificationPreferences($userType, $userID, $preferences) {
    global $pdo;
    try {
        $fields = [];
        $values = [];
        
        foreach ($preferences as $key => $value) {
            $fields[] = "$key = ?";
            $values[] = $value;
        }
        
        $values[] = $userType;
        $values[] = $userID;
        
        $sql = "UPDATE notification_preferences SET " . implode(', ', $fields) . " WHERE UserType = ? AND UserID = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return true;
    } catch (Exception $e) {
        error_log("Preference update failed: " . $e->getMessage());
        return false;
    }
}
?>