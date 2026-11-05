<?php
/**
 * Proof File Upload API
 * Handles AJAX file uploads for expense and event transaction proofs
 * Supports both Google Drive and local storage
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

require_once '../../config/config.php';
require_once '../../includes/google-drive-helper.php';

// Session check
session_start();
if (empty($_SESSION['superadmin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $proofManager = new ProofAttachmentManager($pdo);
    
    switch ($action) {
        case 'upload':
            handleUpload($proofManager);
            break;
        case 'delete':
            handleDelete($proofManager);
            break;
        case 'list':
            handleList($proofManager);
            break;
        case 'retry':
            handleRetry($proofManager);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function handleUpload($proofManager) {
    $entityType = $_POST['entity_type'] ?? '';
    $entityId = intval($_POST['entity_id'] ?? 0);
    
    if (!in_array($entityType, ['expense', 'event_transaction', 'transaction'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid entity type']);
        return;
    }
    
    if ($entityId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid entity ID']);
        return;
    }
    
    if (!isset($_FILES['proof_files'])) {
        echo json_encode(['success' => false, 'message' => 'No files provided']);
        return;
    }
    
    $results = $proofManager->uploadProofs(
        $_FILES['proof_files'],
        $entityType,
        $entityId,
        $_SESSION['superadmin_id']
    );
    
    $successCount = count($results['success']);
    $errorCount = count($results['errors']);
    
    echo json_encode([
        'success' => $successCount > 0,
        'message' => "{$successCount} file(s) uploaded" . ($errorCount ? ", {$errorCount} failed" : ''),
        'uploaded' => $results['success'],
        'errors' => $results['errors'],
        'gdrive_enabled' => $proofManager->isGDriveEnabled(),
    ]);
}

function handleDelete($proofManager) {
    $attachmentId = intval($_POST['attachment_id'] ?? 0);
    
    if ($attachmentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid attachment ID']);
        return;
    }
    
    $proofManager->deleteAttachment($attachmentId, $_SESSION['superadmin_id']);
    
    echo json_encode(['success' => true, 'message' => 'Attachment deleted successfully']);
}

function handleList($proofManager) {
    $entityType = $_GET['entity_type'] ?? '';
    $entityId = intval($_GET['entity_id'] ?? 0);
    
    if (!in_array($entityType, ['expense', 'event_transaction', 'transaction'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid entity type']);
        return;
    }
    
    $attachments = $proofManager->getAttachments($entityType, $entityId);
    
    // Enhance with URLs
    foreach ($attachments as &$att) {
        $att['viewUrl'] = $proofManager->getFileUrl($att);
        $att['thumbnailUrl'] = $proofManager->getThumbnailUrl($att);
        $att['iconClass'] = ProofAttachmentManager::getFileIcon($att['MimeType']);
        $att['formattedSize'] = ProofAttachmentManager::formatSize($att['FileSize']);
    }
    
    echo json_encode([
        'success' => true,
        'attachments' => $attachments,
        'count' => count($attachments),
        'gdrive_enabled' => $proofManager->isGDriveEnabled(),
    ]);
}

function handleRetry($proofManager) {
    $retried = $proofManager->retryFailedUploads();
    echo json_encode(['success' => true, 'message' => "{$retried} file(s) retried", 'retried' => $retried]);
}
