<?php
/**
 * Proof Upload Component
 * Reusable UI component for uploading proof files to expenses and event transactions.
 * Include this in your forms with: include 'proof-upload-component.php';
 * 
 * Required variables:
 * - $entityType: 'expense' or 'event_transaction'
 * - $entityId: ID of the entity (0 for new records)
 * - $existingAttachments: Array of existing attachments (from ProofAttachmentManager::getAttachments)
 * - $isGDriveEnabled: Boolean whether Google Drive is configured
 * 
 * Optional:
 * - $componentId: Unique ID prefix for the component (default: 'proof')
 */

$componentId = $componentId ?? 'proof';
$entityType = $entityType ?? 'expense';
$entityId = $entityId ?? 0;
$existingAttachments = $existingAttachments ?? [];
$isGDriveEnabled = $isGDriveEnabled ?? false;
$maxFiles = 5;
$maxSizeMB = 10;
?>

<!-- Proof Upload Section -->
<div class="proof-upload-section" id="<?php echo $componentId; ?>Section">
    <h3 class="section-title" style="display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-paperclip" style="color: #8b5cf6;"></i> 
        Proof / Attachments
        <span class="proof-badge" id="<?php echo $componentId; ?>Badge" style="background: #8b5cf6; color: white; border-radius: 12px; padding: 2px 10px; font-size: 12px; display: <?php echo count($existingAttachments) > 0 ? 'inline' : 'none'; ?>;">
            <?php echo count($existingAttachments); ?>
        </span>
        <?php if ($isGDriveEnabled): ?>
        <span class="gdrive-status" style="margin-left: auto; font-size: 12px; color: #10b981; display: flex; align-items: center; gap: 5px;">
            <i class="fab fa-google-drive"></i> Google Drive Connected
        </span>
        <?php else: ?>
        <span class="gdrive-status" style="margin-left: auto; font-size: 12px; color: #f59e0b; display: flex; align-items: center; gap: 5px;">
            <i class="fas fa-hdd"></i> Local Storage
        </span>
        <?php endif; ?>
    </h3>
    
    <!-- Drag & Drop Zone -->
    <div class="proof-dropzone" id="<?php echo $componentId; ?>Dropzone"
         ondragover="event.preventDefault(); this.classList.add('drag-over');"
         ondragleave="this.classList.remove('drag-over');"
         ondrop="event.preventDefault(); this.classList.remove('drag-over'); handleProofDrop(event, '<?php echo $componentId; ?>');">
        
        <div class="dropzone-content">
            <i class="fas fa-cloud-upload-alt" style="font-size: 48px; color: #8b5cf6; margin-bottom: 15px;"></i>
            <h4>Drag & Drop Proof Files Here</h4>
            <p>or click to browse</p>
            <div class="dropzone-info">
                <span><i class="fas fa-info-circle"></i> Max <?php echo $maxFiles; ?> files, <?php echo $maxSizeMB; ?>MB each</span>
                <span>Accepted: Images, PDF, Word, Excel</span>
            </div>
            <input type="file" name="proof_files[]" id="<?php echo $componentId; ?>FileInput" 
                   multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"
                   onchange="handleProofFileSelect(this, '<?php echo $componentId; ?>')"
                   style="display: none;">
            <button type="button" class="btn-browse" onclick="document.getElementById('<?php echo $componentId; ?>FileInput').click()">
                <i class="fas fa-folder-open"></i> Browse Files
            </button>
        </div>
    </div>
    
    <!-- Selected Files Preview (before upload) -->
    <div class="proof-pending-list" id="<?php echo $componentId; ?>PendingList" style="display: none;">
        <h4 style="margin-bottom: 10px; color: var(--text-primary, #333);">
            <i class="fas fa-clock"></i> Files Ready to Upload
        </h4>
        <div class="pending-files" id="<?php echo $componentId; ?>PendingFiles"></div>
    </div>
    
    <!-- Existing Attachments -->
    <div class="proof-existing-list" id="<?php echo $componentId; ?>ExistingList" 
         style="display: <?php echo count($existingAttachments) > 0 ? 'block' : 'none'; ?>;">
        <h4 style="margin: 20px 0 10px; color: var(--text-primary, #333); display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-check-circle" style="color: #10b981;"></i> Uploaded Proofs
        </h4>
        <div class="existing-files" id="<?php echo $componentId; ?>ExistingFiles">
            <?php foreach ($existingAttachments as $att): ?>
            <div class="proof-file-card" id="attachment_<?php echo $att['AttachmentID']; ?>">
                <div class="file-preview">
                    <?php if (strpos($att['MimeType'], 'image/') === 0): ?>
                        <img src="<?php echo htmlspecialchars($att['GoogleDriveThumbnail'] ?? ('../../' . $att['LocalPath'])); ?>" 
                             alt="<?php echo htmlspecialchars($att['OriginalName']); ?>" class="file-thumb">
                    <?php else: ?>
                        <div class="file-icon">
                            <i class="fas <?php echo ProofAttachmentManager::getFileIcon($att['MimeType']); ?>"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="file-info">
                    <div class="file-name" title="<?php echo htmlspecialchars($att['OriginalName']); ?>">
                        <?php echo htmlspecialchars($att['OriginalName']); ?>
                    </div>
                    <div class="file-meta">
                        <span><?php echo ProofAttachmentManager::formatSize($att['FileSize']); ?></span>
                        <span><?php echo date('M d, Y', strtotime($att['UploadedAt'])); ?></span>
                        <?php if ($att['GoogleDriveFileID']): ?>
                            <span class="gdrive-badge"><i class="fab fa-google-drive"></i> Drive</span>
                        <?php endif; ?>
                        <?php if ($att['UploadStatus'] === 'failed'): ?>
                            <span class="status-failed"><i class="fas fa-exclamation-triangle"></i> Upload Failed</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="file-actions">
                    <?php 
                    $viewUrl = $att['GoogleDriveURL'] ?? ('../../' . $att['LocalPath']);
                    ?>
                    <a href="<?php echo htmlspecialchars($viewUrl); ?>" target="_blank" class="btn-file-action btn-view" title="View File">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                    <button type="button" class="btn-file-action btn-remove" 
                            onclick="deleteProofAttachment(<?php echo $att['AttachmentID']; ?>, '<?php echo $componentId; ?>')" 
                            title="Delete File">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Advanced Options Toggle -->
    <div class="proof-advanced-toggle" style="margin-top: 15px;">
        <button type="button" class="btn-advanced-toggle" onclick="toggleProofAdvanced('<?php echo $componentId; ?>')">
            <i class="fas fa-cog"></i> Advanced Options
            <i class="fas fa-chevron-down" id="<?php echo $componentId; ?>AdvancedIcon"></i>
        </button>
    </div>
    
    <!-- Advanced Options Panel -->
    <div class="proof-advanced-panel" id="<?php echo $componentId; ?>AdvancedPanel" style="display: none;">
        <div class="advanced-grid">
            <div class="advanced-option">
                <label>
                    <input type="checkbox" name="proof_auto_categorize" value="1" checked>
                    <span>Auto-categorize by file type</span>
                </label>
                <small>Automatically organize files by type in Google Drive</small>
            </div>
            <div class="advanced-option">
                <label>
                    <input type="checkbox" name="proof_notify_on_upload" value="1">
                    <span>Notify admins on upload</span>
                </label>
                <small>Send notification when proof is uploaded</small>
            </div>
            <div class="advanced-option">
                <label>
                    <input type="checkbox" name="proof_require_approval" value="0">
                    <span>Require approval for proofs</span>
                </label>
                <small>Proofs must be approved before being visible</small>
            </div>
            <div class="advanced-option">
                <label>
                    <i class="fas fa-tag"></i>
                    <span>Custom Label</span>
                </label>
                <input type="text" name="proof_label" placeholder="E.g., Invoice, Receipt, Bill Photo..."
                       style="margin-top: 5px; width: 100%; padding: 8px 12px; border: 2px solid var(--border-color, #e5e7eb); border-radius: 8px; font-size: 13px; background: var(--bg-secondary, white); color: var(--text-primary, #333);">
            </div>
            <div class="advanced-option">
                <label>
                    <i class="fas fa-comment-alt"></i>
                    <span>Proof Notes</span>
                </label>
                <textarea name="proof_notes" rows="2" placeholder="Additional notes about the proof files..."
                          style="margin-top: 5px; width: 100%; padding: 8px 12px; border: 2px solid var(--border-color, #e5e7eb); border-radius: 8px; font-size: 13px; resize: vertical; background: var(--bg-secondary, white); color: var(--text-primary, #333);"></textarea>
            </div>
            <?php if (!$isGDriveEnabled): ?>
            <div class="advanced-option" style="grid-column: 1 / -1;">
                <div class="gdrive-setup-hint">
                    <i class="fab fa-google-drive" style="font-size: 24px; color: #4285f4;"></i>
                    <div>
                        <strong>Want to upload to Google Drive?</strong>
                        <p>Connect your Google Drive account in <a href="../google-drive-settings.php">Settings</a> to automatically back up all proof files to your Google Drive.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.proof-upload-section {
    margin-top: 10px;
}

.proof-dropzone {
    border: 3px dashed var(--border-color, #e5e7eb);
    border-radius: 16px;
    padding: 40px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: var(--bg-tertiary, #f9fafb);
    position: relative;
}

.proof-dropzone:hover,
.proof-dropzone.drag-over {
    border-color: #8b5cf6;
    background: rgba(139, 92, 246, 0.05);
    transform: scale(1.01);
}

.proof-dropzone.drag-over {
    box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.15);
}

.dropzone-content h4 {
    font-size: 18px;
    color: var(--text-primary, #333);
    margin-bottom: 5px;
}

.dropzone-content p {
    color: var(--text-secondary, #6b7280);
    margin-bottom: 15px;
}

.dropzone-info {
    display: flex;
    gap: 20px;
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: 20px;
    font-size: 12px;
    color: var(--text-secondary, #9ca3af);
}

.btn-browse {
    padding: 12px 24px;
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: white;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-browse:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

/* Pending files */
.proof-pending-list {
    margin-top: 15px;
    padding: 15px;
    background: rgba(245, 158, 11, 0.05);
    border: 2px solid rgba(245, 158, 11, 0.2);
    border-radius: 12px;
}

.pending-file-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    background: var(--bg-secondary, white);
    border-radius: 8px;
    margin-bottom: 8px;
    border: 1px solid var(--border-color, #e5e7eb);
}

.pending-file-item .file-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(139, 92, 246, 0.1);
    border-radius: 8px;
    color: #8b5cf6;
}

.pending-file-item .file-details {
    flex: 1;
    min-width: 0;
}

.pending-file-item .file-name {
    font-weight: 600;
    font-size: 13px;
    color: var(--text-primary, #333);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.pending-file-item .file-size {
    font-size: 11px;
    color: var(--text-secondary, #6b7280);
}

.pending-file-item .btn-remove-pending {
    background: rgba(239, 68, 68, 0.1);
    border: none;
    color: #ef4444;
    width: 32px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.pending-file-item .btn-remove-pending:hover {
    background: #ef4444;
    color: white;
}

/* Existing files */
.proof-file-card {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 12px;
    background: var(--bg-secondary, white);
    border: 2px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    margin-bottom: 10px;
    transition: all 0.3s ease;
}

.proof-file-card:hover {
    border-color: #8b5cf6;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.file-preview {
    flex-shrink: 0;
}

.file-thumb {
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: 8px;
    border: 2px solid var(--border-color, #e5e7eb);
}

.file-icon {
    width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(139, 92, 246, 0.1);
    border-radius: 8px;
    font-size: 24px;
    color: #8b5cf6;
}

.file-info {
    flex: 1;
    min-width: 0;
}

.file-info .file-name {
    font-weight: 600;
    font-size: 14px;
    color: var(--text-primary, #333);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 4px;
}

.file-meta {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    font-size: 12px;
    color: var(--text-secondary, #6b7280);
}

.gdrive-badge {
    color: #4285f4;
    font-weight: 600;
}

.status-failed {
    color: #ef4444;
    font-weight: 600;
}

.file-actions {
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}

.btn-file-action {
    width: 36px;
    height: 36px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    transition: all 0.3s ease;
    text-decoration: none;
}

.btn-view {
    background: rgba(59, 130, 246, 0.1);
    color: #3b82f6;
}

.btn-view:hover {
    background: #3b82f6;
    color: white;
}

.btn-remove {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
}

.btn-remove:hover {
    background: #ef4444;
    color: white;
}

/* Upload progress bar */
.upload-progress {
    margin-top: 15px;
    display: none;
}

.progress-bar-container {
    height: 8px;
    background: var(--border-color, #e5e7eb);
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 8px;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    border-radius: 4px;
    transition: width 0.3s ease;
    width: 0%;
}

.progress-text {
    font-size: 12px;
    color: var(--text-secondary, #6b7280);
    text-align: center;
}

/* Advanced options */
.btn-advanced-toggle {
    background: none;
    border: 2px solid var(--border-color, #e5e7eb);
    padding: 8px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    color: var(--text-secondary, #6b7280);
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.btn-advanced-toggle:hover {
    border-color: #8b5cf6;
    color: #8b5cf6;
}

.proof-advanced-panel {
    margin-top: 15px;
    padding: 20px;
    border: 2px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    background: var(--bg-tertiary, #f9fafb);
}

.advanced-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.advanced-option label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: var(--text-primary, #333);
    cursor: pointer;
}

.advanced-option small {
    display: block;
    margin-top: 4px;
    margin-left: 26px;
    color: var(--text-secondary, #9ca3af);
    font-size: 12px;
}

.gdrive-setup-hint {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: rgba(66, 133, 244, 0.05);
    border: 2px solid rgba(66, 133, 244, 0.2);
    border-radius: 10px;
}

.gdrive-setup-hint p {
    margin: 5px 0 0;
    font-size: 13px;
    color: var(--text-secondary, #6b7280);
}

.gdrive-setup-hint a {
    color: #4285f4;
    font-weight: 600;
}

@media (max-width: 768px) {
    .advanced-grid {
        grid-template-columns: 1fr;
    }
    
    .proof-file-card {
        flex-wrap: wrap;
    }
    
    .file-actions {
        width: 100%;
        justify-content: flex-end;
    }
}
</style>

<script>
// Proof Upload Component JavaScript
(function() {
    // Store pending files per component
    window.proofPendingFiles = window.proofPendingFiles || {};
    
    // API Base URL
    const PROOF_API_URL = '<?php echo ($entityType === "event_transaction") ? "../../api/proof-upload.php" : "../api/proof-upload.php"; ?>';
    
    window.handleProofFileSelect = function(input, componentId) {
        const files = Array.from(input.files);
        addPendingFiles(files, componentId);
        input.value = ''; // Reset so same file can be selected again
    };
    
    window.handleProofDrop = function(event, componentId) {
        const files = Array.from(event.dataTransfer.files);
        addPendingFiles(files, componentId);
    };
    
    function addPendingFiles(files, componentId) {
        if (!window.proofPendingFiles[componentId]) {
            window.proofPendingFiles[componentId] = [];
        }
        
        const maxFiles = <?php echo $maxFiles; ?>;
        const maxSize = <?php echo $maxSizeMB; ?> * 1024 * 1024;
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 
                             'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                             'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                             'text/csv', 'text/plain'];
        
        // Get existing attachment count
        const existingCount = document.querySelectorAll('#' + componentId + 'ExistingFiles .proof-file-card').length;
        const pendingCount = window.proofPendingFiles[componentId].length;
        const available = maxFiles - existingCount - pendingCount;
        
        if (available <= 0) {
            alert('Maximum ' + maxFiles + ' files allowed. Please remove some files first.');
            return;
        }
        
        let added = 0;
        for (const file of files) {
            if (added >= available) {
                alert('Only ' + available + ' more file(s) can be added.');
                break;
            }
            
            if (file.size > maxSize) {
                alert(file.name + ' exceeds the ' + <?php echo $maxSizeMB; ?> + 'MB size limit.');
                continue;
            }
            
            // Check file type by extension as fallback
            const ext = file.name.split('.').pop().toLowerCase();
            const validExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];
            
            if (!allowedTypes.includes(file.type) && !validExts.includes(ext)) {
                alert(file.name + ' is not an accepted file type.');
                continue;
            }
            
            window.proofPendingFiles[componentId].push(file);
            added++;
        }
        
        renderPendingFiles(componentId);
    }
    
    function renderPendingFiles(componentId) {
        const list = document.getElementById(componentId + 'PendingList');
        const container = document.getElementById(componentId + 'PendingFiles');
        const files = window.proofPendingFiles[componentId] || [];
        
        if (files.length === 0) {
            list.style.display = 'none';
            return;
        }
        
        list.style.display = 'block';
        container.innerHTML = '';
        
        files.forEach((file, index) => {
            const iconClass = getFileIconClass(file.type, file.name);
            const size = formatFileSize(file.size);
            
            const html = `
                <div class="pending-file-item">
                    <div class="file-icon"><i class="fas ${iconClass}"></i></div>
                    <div class="file-details">
                        <div class="file-name">${escapeHtml(file.name)}</div>
                        <div class="file-size">${size}</div>
                    </div>
                    <button type="button" class="btn-remove-pending" onclick="removePendingFile(${index}, '${componentId}')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', html);
        });
    }
    
    window.removePendingFile = function(index, componentId) {
        if (window.proofPendingFiles[componentId]) {
            window.proofPendingFiles[componentId].splice(index, 1);
            renderPendingFiles(componentId);
        }
    };
    
    window.deleteProofAttachment = function(attachmentId, componentId) {
        if (!confirm('Are you sure you want to delete this proof file? This cannot be undone.')) return;
        
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('attachment_id', attachmentId);
        
        fetch(PROOF_API_URL, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const card = document.getElementById('attachment_' + attachmentId);
                if (card) {
                    card.style.transition = 'all 0.3s ease';
                    card.style.opacity = '0';
                    card.style.transform = 'translateX(20px)';
                    setTimeout(() => card.remove(), 300);
                }
                updateBadge(componentId);
            } else {
                alert('Failed to delete: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => {
            alert('Error deleting file: ' + err.message);
        });
    };
    
    /**
     * Upload all pending proof files for an entity.
     * Call this after saving the main form (expense/event transaction).
     * Returns a promise.
     */
    window.uploadPendingProofs = function(componentId, entityType, entityId) {
        const files = window.proofPendingFiles[componentId] || [];
        if (files.length === 0) return Promise.resolve({success: true, uploaded: []});
        
        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('entity_type', entityType);
        formData.append('entity_id', entityId);
        
        files.forEach(file => {
            formData.append('proof_files[]', file);
        });
        
        // Show progress
        const progressDiv = document.getElementById(componentId + 'Progress');
        if (progressDiv) {
            progressDiv.style.display = 'block';
        }
        
        return fetch(PROOF_API_URL, {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            window.proofPendingFiles[componentId] = [];
            renderPendingFiles(componentId);
            if (progressDiv) progressDiv.style.display = 'none';
            return data;
        })
        .catch(err => {
            if (progressDiv) progressDiv.style.display = 'none';
            throw err;
        });
    };
    
    window.toggleProofAdvanced = function(componentId) {
        const panel = document.getElementById(componentId + 'AdvancedPanel');
        const icon = document.getElementById(componentId + 'AdvancedIcon');
        
        if (panel.style.display === 'none') {
            panel.style.display = 'block';
            icon.classList.remove('fa-chevron-down');
            icon.classList.add('fa-chevron-up');
        } else {
            panel.style.display = 'none';
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
        }
    };
    
    function updateBadge(componentId) {
        const badge = document.getElementById(componentId + 'Badge');
        const count = document.querySelectorAll('#' + componentId + 'ExistingFiles .proof-file-card').length;
        if (badge) {
            badge.textContent = count;
            badge.style.display = count > 0 ? 'inline' : 'none';
        }
        
        const listDiv = document.getElementById(componentId + 'ExistingList');
        if (listDiv) {
            listDiv.style.display = count > 0 ? 'block' : 'none';
        }
    }
    
    function getFileIconClass(mimeType, fileName) {
        const ext = fileName.split('.').pop().toLowerCase();
        if (mimeType.startsWith('image/') || ['jpg','jpeg','png','gif','webp'].includes(ext)) return 'fa-file-image';
        if (mimeType === 'application/pdf' || ext === 'pdf') return 'fa-file-pdf';
        if (mimeType.includes('word') || ['doc','docx'].includes(ext)) return 'fa-file-word';
        if (mimeType.includes('excel') || mimeType.includes('spreadsheet') || ['xls','xlsx'].includes(ext)) return 'fa-file-excel';
        if (ext === 'csv') return 'fa-file-csv';
        return 'fa-file';
    }
    
    function formatFileSize(bytes) {
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return bytes + ' bytes';
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
</script>

<!-- Upload Progress -->
<div class="upload-progress" id="<?php echo $componentId; ?>Progress">
    <div class="progress-bar-container">
        <div class="progress-bar" id="<?php echo $componentId; ?>ProgressBar"></div>
    </div>
    <div class="progress-text" id="<?php echo $componentId; ?>ProgressText">Uploading...</div>
</div>
