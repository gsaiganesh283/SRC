<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Check permission to edit members
requireAdminPermission('edit_members', 'You do not have permission to edit members');

// Get member ID from URL
$memberID = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($memberID <= 0) {
    header('Location: manage-members.php');
    exit;
}

$errors = [];
$success = '';

// Fetch member data first
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    // Validation
    if (empty($name)) {
        $errors[] = 'Name is required';
    }
    if (empty($phone)) {
        $errors[] = 'Phone is required';
    }
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    if (empty($status)) {
        $errors[] = 'Status is required';
    }
    
    if (empty($errors)) {
        try {
            // Check if Address column exists
            $checkStmt = $pdo->query("SHOW COLUMNS FROM members LIKE 'Address'");
            $addressExists = $checkStmt->rowCount() > 0;
            
            if ($addressExists) {
                // Update with Address column (use MemberName and Phone)
                $stmt = $pdo->prepare("UPDATE members SET MemberName = ?, Phone = ?, Email = ?, Status = ?, Address = ? WHERE MemberID = ?");
                $stmt->execute([$name, $phone, $email, $status, $address, $memberID]);
            } else {
                // Update without Address column
                $stmt = $pdo->prepare("UPDATE members SET MemberName = ?, Phone = ?, Email = ?, Status = ? WHERE MemberID = ?");
                $stmt->execute([$name, $phone, $email, $status, $memberID]);
            }
            
            $success = 'Member updated successfully!';
            
            // Create audit log for member update
            createAuditLog(
                $_SESSION['admin_id'],
                'Admin',
                'Members',
                'Update Member',
                "Updated member: $name (ID: $memberID)",
                'members',
                $memberID,
                json_encode([
                    'MemberName' => $member['Name'],
                    'Phone' => $member['Phone'],
                    'Email' => $member['Email'],
                    'Status' => $member['Status']
                ]),
                json_encode([
                    'MemberName' => $name,
                    'Phone' => $phone,
                    'Email' => $email,
                    'Status' => $status
                ]),
                'Medium'
            );
            
            // Refresh member data
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
        } catch (Exception $e) {
            $errors[] = 'Error updating member: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Member - <?php echo htmlspecialchars($member['MemberName'] ?? 'Member'); ?></title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/admin-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-edit"></i> Edit Member</h1>
            <div class="header-actions">
                <a href="view-member.php?id=<?php echo $memberID; ?>" class="btn btn-secondary">
                    <i class="fas fa-eye"></i> View Details
                </a>
                <a href="manage-members.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Members
                </a>
            </div>
        </div>

        <div class="form-container">
            <div class="member-info-box">
                <div class="member-avatar">
                    <?php echo strtoupper(substr($member['MemberName'] ?? 'M', 0, 1)); ?>
                </div>
                <div class="member-details">
                    <h2><?php echo htmlspecialchars($member['MemberName'] ?? 'Member'); ?></h2>
                    <p>Member ID: #<?php echo $member['MemberID']; ?></p>
                </div>
            </div>

            <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-user"></i> Basic Information
                    </h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">
                                Full Name <span class="required">*</span>
                            </label>
                            <input type="text" name="name" class="form-input" value="<?php echo htmlspecialchars($member['MemberName'] ?? ''); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                Status <span class="required">*</span>
                            </label>
                            <select name="status" class="form-select" required>
                                <option value="Active" <?php echo $member['Status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                <option value="Inactive" <?php echo $member['Status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="section-title">
                        <i class="fas fa-address-book"></i> Contact Information
                    </h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">
                                Phone Number <span class="required">*</span>
                            </label>
                            <input type="tel" name="phone" class="form-input" value="<?php echo htmlspecialchars($member['Phone']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                Email Address <span class="required">*</span>
                            </label>
                            <input type="email" name="email" class="form-input" value="<?php echo htmlspecialchars($member['Email']); ?>" required>
                        </div>
                        <div class="form-group full-width">
                            <label class="form-label">
                                <i class="fas fa-map-marker-alt"></i> Address
                            </label>
                            <textarea name="address" class="form-textarea" placeholder="Enter full address"><?php echo htmlspecialchars($member['Address'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="manage-members.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/admin-scripts.js"></script>
</body>
</html>