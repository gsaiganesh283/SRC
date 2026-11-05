<!-- Custom Modal Component -->
<style>
    .custom-modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        animation: fadeIn 0.2s ease-out;
    }
    
    .custom-modal-overlay.show {
        display: flex;
    }
    
    .custom-modal {
        background: var(--bg-secondary, #ffffff);
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        max-width: 500px;
        width: 90%;
        animation: slideUp 0.3s ease-out;
        overflow: hidden;
    }
    
    .custom-modal-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border-color, #e0e0e0);
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .custom-modal-icon {
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }
    
    .custom-modal-title {
        font-size: 18px;
        font-weight: 600;
        color: var(--text-primary, #333333);
        margin: 0;
    }
    
    .custom-modal-body {
        padding: 24px;
        color: var(--text-secondary, #666666);
        font-size: 15px;
        line-height: 1.6;
    }
    
    .custom-modal-footer {
        padding: 16px 24px;
        border-top: 1px solid var(--border-color, #e0e0e0);
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }
    
    .modal-btn {
        padding: 10px 24px;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
        outline: none;
    }
    
    .modal-btn-cancel {
        background: transparent;
        border: 1px solid var(--border-color, #ddd);
        color: var(--text-primary, #333);
    }
    
    .modal-btn-cancel:hover {
        background: var(--bg-tertiary, #f5f5f5);
    }
    
    .modal-btn-confirm {
        background: linear-gradient(135deg, var(--gradient-1, #667eea) 0%, var(--gradient-2, #764ba2) 100%);
        color: #ffffff;
        border: none;
    }
    
    .modal-btn-confirm:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }
    
    .modal-btn-alert {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #ffffff;
        border: none;
        min-width: 100px;
    }
    
    .modal-btn-alert:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }
    
    @keyframes fadeIn {
        from {
            opacity: 0;
        }
        to {
            opacity: 1;
        }
    }
    
    @keyframes slideUp {
        from {
            transform: translateY(30px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    /* Dark mode support */
    [data-theme="dark"] .custom-modal {
        background: var(--bg-secondary, #2d3748);
    }
    
    [data-theme="dark"] .custom-modal-title {
        color: var(--text-primary, #f7fafc);
    }
    
    [data-theme="dark"] .custom-modal-body {
        color: var(--text-secondary, #e2e8f0);
    }
    
    [data-theme="dark"] .modal-btn-cancel {
        border-color: var(--border-color, #4a5568);
        color: var(--text-primary, #f7fafc);
    }
    
    [data-theme="dark"] .modal-btn-cancel:hover {
        background: var(--bg-tertiary, #1a202c);
    }
</style>

<!-- Confirm Modal -->
<div id="confirmModal" class="custom-modal-overlay">
    <div class="custom-modal">
        <div class="custom-modal-header">
            <span class="custom-modal-icon">⚠️</span>
            <h3 class="custom-modal-title" id="modalTitle">Confirm Action</h3>
        </div>
        <div class="custom-modal-body" id="modalMessage">
            Are you sure you want to proceed?
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="modal-btn modal-btn-cancel" onclick="closeModal()">Cancel</button>
            <button type="button" class="modal-btn modal-btn-confirm" id="modalConfirmBtn">OK</button>
        </div>
    </div>
</div>

<!-- Alert Modal -->
<div id="alertModal" class="custom-modal-overlay">
    <div class="custom-modal">
        <div class="custom-modal-header">
            <span class="custom-modal-icon" id="alertIcon">ℹ️</span>
            <h3 class="custom-modal-title" id="alertTitle">Notice</h3>
        </div>
        <div class="custom-modal-body" id="alertMessage">
            This is an alert message.
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="modal-btn modal-btn-alert" onclick="closeAlertModal()">OK</button>
        </div>
    </div>
</div>

<script>
    // Custom Modal Functions
    let modalResolve = null;
    
    function showConfirmModal(title, message) {
        return new Promise((resolve) => {
            modalResolve = resolve;
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalMessage').textContent = message;
            document.getElementById('confirmModal').classList.add('show');
            
            // Set focus on confirm button
            setTimeout(() => {
                document.getElementById('modalConfirmBtn').focus();
            }, 100);
        });
    }
    
    function closeModal() {
        document.getElementById('confirmModal').classList.remove('show');
        if (modalResolve) {
            modalResolve(false);
            modalResolve = null;
        }
    }
    
    // Confirm button click
    document.getElementById('modalConfirmBtn').addEventListener('click', function() {
        document.getElementById('confirmModal').classList.remove('show');
        if (modalResolve) {
            modalResolve(true);
            modalResolve = null;
        }
    });
    
    // Close on overlay click
    document.getElementById('confirmModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeModal();
        }
    });
    
    // Alert Modal Functions
    function showAlertModal(title, message, icon = 'ℹ️') {
        return new Promise((resolve) => {
            document.getElementById('alertTitle').textContent = title;
            document.getElementById('alertMessage').textContent = message;
            document.getElementById('alertIcon').textContent = icon;
            document.getElementById('alertModal').classList.add('show');
            
            // Store resolve for alert
            window.alertResolve = resolve;
            
            // Set focus on OK button
            setTimeout(() => {
                document.querySelector('#alertModal .modal-btn-alert').focus();
            }, 100);
        });
    }
    
    function closeAlertModal() {
        document.getElementById('alertModal').classList.remove('show');
        if (window.alertResolve) {
            window.alertResolve(true);
            window.alertResolve = null;
        }
    }
    
    // Close alert on overlay click
    document.getElementById('alertModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeAlertModal();
        }
    });
    
    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (document.getElementById('confirmModal').classList.contains('show')) {
                closeModal();
            }
            if (document.getElementById('alertModal').classList.contains('show')) {
                closeAlertModal();
            }
        }
    });
    
    // Override native confirm (optional - for backwards compatibility)
    window.customConfirm = function(message) {
        return showConfirmModal('Confirm', message);
    };
    
    // Override native alert (optional - for backwards compatibility)
    window.customAlert = function(message) {
        return showAlertModal('Notice', message);
    };
    
    // Auto-attach to confirm-action links
    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('click', function(e) {
            const target = e.target.closest('.confirm-action');
            if (target) {
                e.preventDefault();
                const title = target.dataset.confirmTitle || 'Confirm Action';
                const message = target.dataset.confirmMessage || 'Are you sure you want to proceed?';
                const href = target.href;
                
                showConfirmModal(title, message).then(confirmed => {
                    if (confirmed) {
                        window.location.href = href;
                    }
                });
            }
        });
        
        // Handle form submits with confirm-submit class
        document.querySelectorAll('form.confirm-submit').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const title = this.dataset.confirmTitle || 'Confirm Action';
                const message = this.dataset.confirmMessage || 'Are you sure you want to proceed?';
                
                showConfirmModal(title, message).then(confirmed => {
                    if (confirmed) {
                        // Remove the event listener to avoid loop
                        this.classList.remove('confirm-submit');
                        this.submit();
                    }
                });
            });
        });
    });
</script>
