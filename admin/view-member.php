<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Check permission to view member details
requireAdminPermission('view_members', 'You do not have permission to view member details');

// Get member ID from URL
$memberID = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($memberID <= 0) {
    header('Location: manage-members.php');
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            MemberID,
            MemberName AS Name,
            Phone,
            Email,
            Address,
            Photo,
            Status,
            CreatedAt,
            UpdatedAt
        FROM members 
        WHERE MemberID = ?
    ");
    $stmt->execute([$memberID]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        header('Location: manage-members.php');
        exit;
    }
} catch (Exception $e) {
    die('Error fetching member: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Member - <?php echo htmlspecialchars($member['MemberName'] ?? 'Member'); ?></title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.98);
            padding: 25px 30px;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            font-size: 28px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .header-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        
        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn-secondary:hover {
            background: #667eea;
            color: white;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        
        .member-profile {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        
        .profile-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: white;
            color: #667eea;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 15px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }
        
        .profile-name {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .profile-id {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .profile-status {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 25px;
            font-size: 14px;
            font-weight: 600;
            margin-top: 15px;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .profile-body {
            padding: 30px;
        }
        
        .info-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            background: #f8f9ff;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
        }
        
        .info-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .info-value {
            font-size: 16px;
            color: #333;
            font-weight: 600;
        }
        
        .action-section {
            display: flex;
            gap: 15px;
            justify-content: center;
            padding: 30px;
            background: #f8f9ff;
            border-top: 2px solid #f0f0f0;
        }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .action-section {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-circle"></i> Member Details</h1>
            <div class="header-actions">
                <a href="manage-members.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Members
                </a>
            </div>
        </div>

        <div class="member-profile">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($member['MemberName'] ?? 'M', 0, 1)); ?>
                </div>
                <div class="profile-name"><?php echo htmlspecialchars($member['MemberName'] ?? 'Member'); ?></div>
                <div class="profile-id">Member ID: #<?php echo $member['MemberID']; ?></div>
                <span class="profile-status status-<?php echo strtolower($member['Status']); ?>">
                    <?php echo htmlspecialchars($member['Status']); ?>
                </span>
            </div>

            <div class="profile-body">
                <div class="info-section">
                    <h2 class="section-title">
                        <i class="fas fa-info-circle"></i> Contact Information
                    </h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-phone"></i> Phone Number
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($member['Phone']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-envelope"></i> Email Address
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($member['Email']); ?></div>
                        </div>
                    </div>
                </div>

                <?php if (isset($member['Address']) || isset($member['City']) || isset($member['State'])): ?>
                <div class="info-section">
                    <h2 class="section-title">
                        <i class="fas fa-map-marker-alt"></i> Address Information
                    </h2>
                    <div class="info-grid">
                        <?php if (isset($member['Address']) && !empty($member['Address'])): ?>
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-home"></i> Address
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($member['Address']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (isset($member['City']) && !empty($member['City'])): ?>
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-city"></i> City
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($member['City']); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (isset($member['State']) && !empty($member['State'])): ?>
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-map"></i> State
                            </div>
                            <div class="info-value"><?php echo htmlspecialchars($member['State']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="info-section">
                    <h2 class="section-title">
                        <i class="fas fa-clipboard-list"></i> Additional Details
                    </h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-check-circle"></i> Status
                            </div>
                            <div class="info-value">
                                <span class="profile-status status-<?php echo strtolower($member['Status']); ?>">
                                    <?php echo htmlspecialchars($member['Status']); ?>
                                </span>
                            </div>
                        </div>
                        <?php if (isset($member['JoinDate'])): ?>
                        <div class="info-item">
                            <div class="info-label">
                                <i class="fas fa-calendar"></i> Join Date
                            </div>
                            <div class="info-value"><?php echo date('F d, Y', strtotime($member['JoinDate'])); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="action-section">
                <a href="edit-member.php?id=<?php echo $member['MemberID']; ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Member
                </a>
                <a href="delete-member.php?id=<?php echo $member['MemberID']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this member?');">
                    <i class="fas fa-trash"></i> Delete Member
                </a>
            </div>
        </div>
    </div>
</body>
</html>