<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireMemberLogin();

// Check permission to edit profile
requireMemberPermission('edit_profile', 'You do not have permission to edit your profile');

$memberId = $_SESSION['member_id'];
$error = '';
$success = '';

// Fetch current member data
try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE MemberID = ?");
    $stmt->execute([$memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member) {
        die("Member not found");
    }
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitizeInput($_POST['name']);
    $phone = sanitizeInput($_POST['phone']);
    $email = sanitizeInput($_POST['email']);
    $address = sanitizeInput($_POST['address']);
    
    if (empty($name) || empty($phone)) {
        $error = 'Name and phone are required';
    } else {
        try {
            // Handle photo upload
            $photoPath = $member['Photo'];
            
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = uploadFile($_FILES['photo'], PROFILES_PATH);
                
                if ($uploadResult['success']) {
                    $photoPath = 'assets/uploads/profiles/' . $uploadResult['filename'];
                    
                    // Delete old photo
                    if (!empty($member['Photo']) && file_exists(BASE_PATH . '/' . $member['Photo'])) {
                        unlink(BASE_PATH . '/' . $member['Photo']);
                    }
                } else {
                    $error = $uploadResult['message'];
                }
            }
            
            if (empty($error)) {
                // Get old data for audit log
                $oldData = [
                    'MemberName' => $member['MemberName'],
                    'Phone' => $member['Phone'],
                    'Email' => $member['Email']
                ];
                
                $stmt = $pdo->prepare("
                    UPDATE members 
                    SET MemberName = ?, Phone = ?, Email = ?, Address = ?, Photo = ?
                    WHERE MemberID = ?
                ");
                $stmt->execute([$name, $phone, $email, $address, $photoPath, $memberId]);
                
                $_SESSION['member_name'] = $name;
                
                // Create audit log for profile update
                createAuditLog(
                    $memberId,
                    'Member',
                    'Members',
                    'Update Own Profile',
                    "Member updated their profile",
                    'members',
                    $memberId,
                    json_encode($oldData),
                    json_encode([
                        'MemberName' => $name,
                        'Phone' => $phone,
                        'Email' => $email
                    ]),
                    'Low'
                );
                
                $success = 'Profile updated successfully!';
                
                // Refresh member data
                $stmt = $pdo->prepare("SELECT * FROM members WHERE MemberID = ?");
                $stmt->execute([$memberId]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            $error = 'Error updating profile: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Profile - SR Colony Chitti</title>
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>/css/styles.css">
</head>
<body>
    <div class="particles"></div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1>✏️ Update Profile</h1>
                <p><?php echo htmlspecialchars($member['MemberName'] ?? 'Member'); ?></p>
            </div>
            
            <?php if ($error): ?>
                <div class="error-message">⚠️ <?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message">✓ <?php echo $success; ?></div>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="name">👤 Full Name</label>
                    <input 
                        type="text" 
                        id="name" 
                        name="name" 
                        class="form-control" 
                        value="<?php echo htmlspecialchars($member['MemberName'] ?? ''); ?>"
                        required
                    >
                </div>
                
                <div class="form-group">
                    <label for="phone">📱 Phone Number</label>
                    <input 
                        type="tel" 
                        id="phone" 
                        name="phone" 
                        class="form-control" 
                        value="<?php echo htmlspecialchars($member['Phone']); ?>"
                        pattern="[0-9]{10}"
                        maxlength="10"
                        required
                    >
                </div>
                
                <div class="form-group">
                    <label for="email">📧 Email</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-control" 
                        value="<?php echo htmlspecialchars($member['Email'] ?? ''); ?>"
                    >
                </div>
                
                <div class="form-group">
                    <label for="address">🏠 Address</label>
                    <textarea 
                        id="address" 
                        name="address" 
                        class="form-control" 
                        rows="3"
                    ><?php echo htmlspecialchars($member['Address'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="photo">📷 Profile Photo</label>
                    <input 
                        type="file" 
                        id="photo" 
                        name="photo" 
                        class="form-control" 
                        accept="image/*"
                    >
                    <?php if (!empty($member['Photo'])): ?>
                        <p style="font-size: 12px; color: #666; margin-top: 5px;">Current photo will be replaced</p>
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="btn-login">💾 Update Profile</button>
            </form>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="dashboard-new.php" style="color: #667eea; text-decoration: none;">← Back to Dashboard</a>
            </div>
        </div>
    </div>
    
    <script src="<?php echo ASSETS_URL; ?>/js/script.js"></script>
</body>
</html>