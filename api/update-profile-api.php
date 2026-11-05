<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $memberId = intval($_POST['member_id']);
    $name = sanitizeInput($_POST['name']);
    $phone = sanitizeInput($_POST['phone']);
    $email = sanitizeInput($_POST['email']);
    $address = sanitizeInput($_POST['address']);
    
    // Handle photo upload
    $photoPath = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = uploadFile($_FILES['photo'], PROFILES_PATH);
        
        if (!$uploadResult['success']) {
            echo json_encode($uploadResult);
            exit();
        }
        
        $photoPath = 'assets/uploads/profiles/' . $uploadResult['filename'];
        
        // Delete old photo if exists
        $stmt = $pdo->prepare("SELECT Photo FROM members WHERE MemberID = ?");
        $stmt->execute([$memberId]);
        $oldPhoto = $stmt->fetchColumn();
        
        if ($oldPhoto && file_exists(BASE_PATH . '/' . $oldPhoto)) {
            unlink(BASE_PATH . '/' . $oldPhoto);
        }
    }
    
    // Update member profile
    if ($photoPath) {
        $stmt = $pdo->prepare("
            UPDATE members 
            SET Name = ?, Phone = ?, Email = ?, Address = ?, Photo = ?
            WHERE MemberID = ?
        ");
        $stmt->execute([$name, $phone, $email, $address, $photoPath, $memberId]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE members 
            SET Name = ?, Phone = ?, Email = ?, Address = ?
            WHERE MemberID = ?
        ");
        $stmt->execute([$name, $phone, $email, $address, $memberId]);
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Profile updated successfully!',
        'photo' => $photoPath
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>