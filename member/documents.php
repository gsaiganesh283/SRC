<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireMemberLogin();

$memberId = $_SESSION['member_id'];

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['document'])) {
    $documentType = $_POST['document_type'] ?? 'Other';
    $notes = $_POST['notes'] ?? '';
    
    $uploadDir = '../assets/uploads/documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $file = $_FILES['document'];
    $fileName = time() . '_' . $memberId . '_' . basename($file['name']);
    $targetPath = $uploadDir . $fileName;
    
    $allowedTypes = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    if (in_array($fileExt, $allowedTypes) && $file['size'] <= 5242880) { // 5MB limit
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO member_documents (MemberID, DocumentType, DocumentName, FilePath, FileSize, MimeType, UploadedBy, Notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $memberId,
                    $documentType,
                    $file['name'],
                    $fileName,
                    $file['size'],
                    $file['type'],
                    'Member',
                    $notes
                ]);
                
                $success = "Document uploaded successfully!";
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Failed to upload file.";
        }
    } else {
        $error = "Invalid file type or size exceeds 5MB.";
    }
}

// Handle document deletion
if (isset($_GET['delete'])) {
    $docId = intval($_GET['delete']);
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM member_documents WHERE DocumentID = ? AND MemberID = ?");
        $stmt->execute([$docId, $memberId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($doc) {
            // Delete file
            $filePath = '../assets/uploads/documents/' . $doc['FilePath'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            // Delete record
            $stmt = $pdo->prepare("DELETE FROM member_documents WHERE DocumentID = ?");
            $stmt->execute([$docId]);
            
            $success = "Document deleted successfully!";
        }
    } catch (Exception $e) {
        $error = "Error deleting document: " . $e->getMessage();
    }
}

// Get all documents
try {
    $stmt = $pdo->prepare("SELECT * FROM member_documents WHERE MemberID = ? ORDER BY UploadedAt DESC");
    $stmt->execute([$memberId]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $documents = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Documents - SR Colony</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #4f46e5;
            color: white;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #047857;
            border-left: 4px solid #10b981;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
        }
        
        .documents-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        
        .document-card {
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            transition: all 0.2s;
        }
        
        .document-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            border-color: #4f46e5;
        }
        
        .document-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            margin-bottom: 1rem;
        }
        
        .document-name {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.5rem;
            word-break: break-word;
        }
        
        .document-meta {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 0.25rem;
        }
        
        .document-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .modal-header h2 {
            color: #1f2937;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-folder"></i>
                My Documents
            </h1>
            <div>
                <button onclick="openModal()" class="btn btn-primary">
                    <i class="fas fa-upload"></i>
                    Upload Document
                </button>
                <a href="dashboard-new.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Back
                </a>
            </div>
        </div>
        
        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <?php if (empty($documents)): ?>
                <div style="text-align: center; padding: 3rem; color: #6b7280;">
                    <i class="fas fa-folder-open" style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <p style="font-size: 1.125rem;">No documents uploaded yet</p>
                    <button onclick="openModal()" class="btn btn-primary" style="margin-top: 1rem;">
                        <i class="fas fa-upload"></i>
                        Upload Your First Document
                    </button>
                </div>
            <?php else: ?>
                <div class="documents-grid">
                    <?php foreach ($documents as $doc): ?>
                        <div class="document-card">
                            <div class="document-icon">
                                <i class="fas fa-file-<?php 
                                    $ext = strtolower(pathinfo($doc['DocumentName'], PATHINFO_EXTENSION));
                                    echo $ext == 'pdf' ? 'pdf' : ($ext == 'doc' || $ext == 'docx' ? 'word' : 'alt');
                                ?>"></i>
                            </div>
                            <div class="document-name"><?php echo htmlspecialchars($doc['DocumentName']); ?></div>
                            <div class="document-meta">
                                <i class="fas fa-tag"></i>
                                <?php echo htmlspecialchars($doc['DocumentType']); ?>
                            </div>
                            <div class="document-meta">
                                <i class="fas fa-calendar"></i>
                                <?php echo date('d M Y', strtotime($doc['UploadedAt'])); ?>
                            </div>
                            <div class="document-meta">
                                <i class="fas fa-file"></i>
                                <?php echo number_format($doc['FileSize'] / 1024, 2); ?> KB
                            </div>
                            <?php if ($doc['Notes']): ?>
                                <div class="document-meta" style="margin-top: 0.5rem; font-style: italic;">
                                    "<?php echo htmlspecialchars(substr($doc['Notes'], 0, 50)); ?><?php echo strlen($doc['Notes']) > 50 ? '...' : ''; ?>"
                                </div>
                            <?php endif; ?>
                            <div class="document-actions">
                                <a href="../assets/uploads/documents/<?php echo htmlspecialchars($doc['FilePath']); ?>" 
                                   target="_blank" class="btn btn-primary btn-sm">
                                    <i class="fas fa-eye"></i>
                                    View
                                </a>
                                <a href="../assets/uploads/documents/<?php echo htmlspecialchars($doc['FilePath']); ?>" 
                                   download class="btn btn-secondary btn-sm">
                                    <i class="fas fa-download"></i>
                                    Download
                                </a>
                                <a href="?delete=<?php echo $doc['DocumentID']; ?>" 
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Are you sure you want to delete this document?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Upload Modal -->
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-upload"></i> Upload Document</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="document_type">Document Type</label>
                    <select name="document_type" id="document_type" class="form-control" required>
                        <option value="Aadhar Card">Aadhar Card</option>
                        <option value="PAN Card">PAN Card</option>
                        <option value="Bank Statement">Bank Statement</option>
                        <option value="Receipt">Receipt</option>
                        <option value="Agreement">Agreement</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="document">Select File (Max 5MB)</label>
                    <input type="file" name="document" id="document" class="form-control" required
                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    <small style="color: #6b7280; margin-top: 0.25rem; display: block;">
                        Allowed: PDF, JPG, PNG, DOC, DOCX
                    </small>
                </div>
                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea name="notes" id="notes" class="form-control" rows="3" 
                              placeholder="Add any additional notes..."></textarea>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i>
                        Upload
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openModal() {
            document.getElementById('uploadModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('uploadModal').classList.remove('active');
        }
        
        // Close modal on outside click
        document.getElementById('uploadModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    </script>
    
    <?php include '../includes/modal-component.php'; ?>
</body>
</html>
