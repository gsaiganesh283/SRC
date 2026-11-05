<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireAdmin();

// Check permission to add members
requireAdminPermission('add_members', 'You do not have permission to add members');

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $fatherName = trim($_POST['father_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $aadharNumber = trim($_POST['aadhar_number'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');
    $address = trim($_POST['address'] ?? '');
    $joinedDate = trim($_POST['joined_date'] ?? date('Y-m-d'));
    
    // Validation
    if (empty($name)) {
        $errors[] = 'Name is required';
    }
    if (empty($phone)) {
        $errors[] = 'Phone is required';
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = 'Phone must be 10 digits';
    }
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    if (!empty($aadharNumber) && !preg_match('/^[0-9]{12}$/', $aadharNumber)) {
        $errors[] = 'Aadhar must be 12 digits';
    }
    if (empty($status)) {
        $errors[] = 'Status is required';
    }
    
    // Check if email already exists
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM members WHERE Email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Email address already exists';
            }
        } catch (Exception $e) {
            // Email column might not exist, continue
        }
    }
    
    if (empty($errors)) {
        try {
            // Insert new member with all available fields
            // Use MemberName (physical column) instead of Name
            $stmt = $pdo->prepare("
                INSERT INTO members (
                    MemberName, 
                    FatherName,
                    Phone, 
                    Email, 
                    AadharNumber,
                    Status, 
                    Address,
                    JoinedDate,
                    CreatedBy,
                    CreatedAt,
                    UpdatedAt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                $name, 
                $fatherName,
                $phone, 
                $email, 
                $aadharNumber,
                $status, 
                $address,
                $joinedDate,
                $_SESSION['admin_id'] // CreatedBy = current admin
            ]);
            
            $success = 'Member added successfully!';
            $newMemberID = $pdo->lastInsertId();
            
            // Create audit log for member creation
            createAuditLog(
                $_SESSION['admin_id'],
                'Admin',
                'Members',
                'Create Member',
                "Created new member: $name (ID: $newMemberID)",
                'members',
                $newMemberID,
                null,
                json_encode([
                    'MemberName' => $name,
                    'Phone' => $phone,
                    'Email' => $email,
                    'Status' => $status
                ]),
                'Medium'
            );
            
            // Redirect to view the new member after 2 seconds
            header("refresh:2;url=view-member.php?id=$newMemberID");
        } catch (Exception $e) {
            $errors[] = 'Error adding member: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Member - Admin</title>
    <link rel="stylesheet" href="../assets/css/styles.css">
    <link rel="stylesheet" href="../assets/css/admin-styles.css">
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
        
        /* Header Section */
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
            gap: 12px;
        }
        
        .header-actions {
            display: flex;
            gap: 12px;
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
            border: none;
            cursor: pointer;
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
            background: #f0f4ff;
        }
        
        /* Form Container */
        .form-container {
            background: white;
            padding: 35px;
            border-radius: 15px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        }
        
        .form-intro {
            background: linear-gradient(135deg, #e0f7fa 0%, #e1bee7 100%);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 5px solid #667eea;
        }
        
        .form-intro h3 {
            color: #333;
            margin-bottom: 8px;
            font-size: 18px;
        }
        
        .form-intro p {
            color: #666;
            font-size: 14px;
        }
        
        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 5px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 5px solid #dc3545;
        }
        
        /* Form Sections */
        .form-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .required {
            color: #dc3545;
        }
        
        .form-input, .form-select, .form-textarea {
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e0e0e0;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .form-container {
                padding: 20px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column-reverse;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-user-plus"></i> Add New Member</h1>
            <div class="header-actions">
                <a href="manage-members.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Members
                </a>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
        </div>

        <div class="form-container">
            <div class="form-intro">
                <h3><i class="fas fa-info-circle"></i> Member Information</h3>
                <p>Fill in the details below to add a new member to the SR Colony Chitti system.</p>
            </div>

            <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div>
                    <?php echo htmlspecialchars($success); ?>
                    <br><small>Redirecting to member details...</small>
                </div>
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
                            <input 
                                type="text" 
                                name="name" 
                                class="form-input" 
                                placeholder="Enter member's full name"
                                value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" 
                                required
                            >
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                Status <span class="required">*</span>
                            </label>
                            <select name="status" class="form-select" required>
                                <option value="Member" <?php echo (isset($_POST['status']) && $_POST['status'] === 'Member') ? 'selected' : 'selected'; ?>>Member</option>
                                <option value="Active" <?php echo (isset($_POST['status']) && $_POST['status'] === 'Active') ? 'selected' : ''; ?>>Active</option>
                                <option value="Hold" <?php echo (isset($_POST['status']) && $_POST['status'] === 'Hold') ? 'selected' : ''; ?>>Hold</option>
                                <option value="Inactive" <?php echo (isset($_POST['status']) && $_POST['status'] === 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
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
                            <input 
                                type="tel" 
                                name="phone" 
                                class="form-input" 
                                placeholder="Enter phone number"
                                value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" 
                                required
                            >
                        </div>
                        <div class="form-group">
                            <label class="form-label">
                                Email Address <span class="required">*</span>
                            </label>
                            <input 
                                type="email" 
                                name="email" 
                                class="form-input" 
                                placeholder="Enter email address"
                                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                                required
                            >
                        </div>
                        <div class="form-group full-width">
                            <label class="form-label">
                                <i class="fas fa-map-marker-alt"></i> Address
                            </label>
                            <textarea 
                                name="address" 
                                class="form-textarea" 
                                placeholder="Enter full address (optional)"
                            ><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="manage-members.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Add Member
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="../assets/js/admin-scripts.js"></script>
</body>
</html>
