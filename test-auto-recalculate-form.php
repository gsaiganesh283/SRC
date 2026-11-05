<?php
session_start();
require_once 'config/config.php';

// Simulate being logged in as super admin if not already
if (!isset($_SESSION['superadmin_id'])) {
    $_SESSION['superadmin_id'] = 1;
    $_SESSION['superadmin_name'] = 'Test Admin';
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Auto-Recalculate API</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="superadmin/shared-styles.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: var(--bg-primary);
            background-attachment: fixed;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            padding: 20px;
        }
        
        .page-wrapper {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .header-section {
            background: var(--gradient-primary);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-lg);
        }
        
        .header-section h1 {
            margin-bottom: 10px;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .header-section p {
            opacity: 0.9;
            font-size: 14px;
            margin: 5px 0;
        }
        
        .main-card {
            background: var(--bg-primary-solid);
            border-radius: 12px;
            padding: 30px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--text-primary);
            font-size: 14px;
        }
        
        .form-group small {
            display: block;
            margin-top: 5px;
            color: var(--text-secondary);
            font-size: 12px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--input-bg);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-group textarea {
            font-family: 'Courier New', monospace;
            min-height: 100px;
            resize: vertical;
        }
        
        .member-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 15px;
        }
        
        .member-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .member-checkbox:hover {
            border-color: #667eea;
            background: var(--hover-bg);
        }
        
        .member-checkbox input {
            width: auto !important;
            cursor: pointer;
        }
        
        .member-checkbox label {
            margin: 0;
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }
        
        button {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 150px;
            justify-content: center;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow);
        }
        
        .btn-primary:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: var(--border-color);
            color: var(--text-primary);
            box-shadow: var(--shadow);
        }
        
        .btn-secondary:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }
        
        .session-info {
            background: var(--input-bg);
            border-left: 4px solid #667eea;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        
        .session-info p {
            margin: 5px 0;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Message Modal System */
        .message-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(2px);
        }
        
        .message-overlay.show {
            display: flex;
            animation: fadeInOverlay 0.3s ease;
        }
        
        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .message-modal {
            background: var(--bg-primary-solid);
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            max-width: 500px;
            width: 90%;
            overflow: hidden;
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .message-header {
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .message-header.success {
            background: rgba(40, 167, 69, 0.1);
            border-bottom-color: rgba(40, 167, 69, 0.3);
        }
        
        .message-header.error {
            background: rgba(220, 53, 69, 0.1);
            border-bottom-color: rgba(220, 53, 69, 0.3);
        }
        
        .message-header.warning {
            background: rgba(255, 193, 7, 0.1);
            border-bottom-color: rgba(255, 193, 7, 0.3);
        }
        
        .message-header.info {
            background: rgba(23, 162, 184, 0.1);
            border-bottom-color: rgba(23, 162, 184, 0.3);
        }
        
        .message-header i {
            font-size: 24px;
            min-width: 24px;
        }
        
        .message-header.success i { color: #28a745; }
        .message-header.error i { color: #dc3545; }
        .message-header.warning i { color: #ffc107; }
        .message-header.info i { color: #17a2b8; }
        
        .message-title {
            font-weight: 600;
            font-size: 16px;
            color: var(--text-primary);
        }
        
        .message-body {
            padding: 20px;
            color: var(--text-primary);
            font-size: 14px;
            line-height: 1.6;
        }
        
        .message-details {
            background: var(--input-bg);
            padding: 12px;
            border-radius: 6px;
            margin-top: 12px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 250px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            word-break: break-all;
        }
        
        .message-footer {
            padding: 15px 20px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            border-top: 1px solid var(--border-color);
        }
        
        .message-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s ease;
        }
        
        .message-btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: var(--shadow);
        }
        
        .message-btn-primary:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-1px);
        }
        
        .message-btn-secondary {
            background: var(--border-color);
            color: var(--text-primary);
        }
        
        .message-btn-secondary:hover {
            background: var(--input-bg);
        }
        
        .response-box {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 25px;
            margin-top: 30px;
            box-shadow: var(--shadow);
        }
        
        .response-box h3 {
            margin: 0 0 20px 0;
            color: var(--text-primary);
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .response-section {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .response-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .response-section label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 13px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        pre {
            background: var(--bg-primary);
            padding: 12px;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.4;
            border: 1px solid var(--border-color);
            max-height: 300px;
            overflow-y: auto;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .status-ok {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        
        .status-error {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        
        .empty-state {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div class="header-section">
            <h1><i class="fas fa-sync-alt"></i> Test Auto-Recalculate API</h1>
            <p>Test the auto-recalculate net balance feature with detailed debugging information</p>
        </div>
        
        <div class="main-card">
            <div class="session-info">
                <p><i class="fas fa-check-circle"></i> <strong>Session Status:</strong></p>
                <p style="margin-left: 28px;"><i class="fas fa-user"></i> SuperAdmin ID: <strong><?php echo $_SESSION['superadmin_id'] ?? 'Not set'; ?></strong></p>
                <p style="margin-left: 28px;"><i class="fas fa-tag"></i> Name: <strong><?php echo $_SESSION['superadmin_name'] ?? 'Not set'; ?></strong></p>
            </div>
        </div>
        
        <div class="main-card">
        
            <form id="testForm">
                <div class="form-group">
                    <label for="yearId"><i class="fas fa-calendar-alt"></i> Academic Year</label>
                    <select id="yearId" name="yearId">
                    <option value="">-- Auto-detect Active Year --</option>
                    <?php
                    $stmt = $pdo->query("SELECT YearID, YearName, Status FROM academic_years ORDER BY YearID DESC");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $selected = ($row['Status'] == 'Active') ? ' selected' : '';
                        echo '<option value="' . $row['YearID'] . '"' . $selected . '>' . 
                             htmlspecialchars($row['YearName']) . 
                             ($row['Status'] == 'Active' ? ' (Active)' : '') . 
                             '</option>';
                    }
                    ?>
                </select>
                    <small><i class="fas fa-info-circle"></i> Leave empty to automatically detect the active year</small>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-users"></i> Select Members to Recalculate</label>
                <div class="member-list">
                    <?php
                    $stmt = $pdo->query("
                        SELECT MemberID, MemberName, Status 
                        FROM members 
                        WHERE Status = 'Active' 
                        ORDER BY MemberName ASC 
                        LIMIT 20
                    ");
                    $memberCount = 0;
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo '<div class="member-checkbox">';
                        echo '<input type="checkbox" id="member_' . $row['MemberID'] . '" name="memberIds" value="' . $row['MemberID'] . '">';
                        echo '<label for="member_' . $row['MemberID'] . '">' . htmlspecialchars($row['MemberName']) . '</label>';
                        echo '</div>';
                        $memberCount++;
                    }
                    if ($memberCount == 0) {
                        echo '<div class="empty-state"><i class="fas fa-inbox"></i> No active members found</div>';
                    }
                    ?>
                    </div>
                    <small><i class="fas fa-check"></i> Select one or more members to recalculate their net balance</small>
                </div>
                
                <div class="form-group">
                    <label for="memberIds"><i class="fas fa-code"></i> Alternative: Member IDs (JSON Array)</label>
                    <textarea id="memberIds" placeholder="[1, 2, 3]"></textarea>
                    <small><i class="fas fa-lightbulb"></i> If checkbox selection didn't work, paste a JSON array of member IDs here</small>
                </div>
                
                <div class="button-group">
                    <button type="button" class="btn-primary" onclick="testAPI()"><i class="fas fa-play"></i> Test API Call</button>
                    <button type="button" class="btn-secondary" onclick="clearForm()"><i class="fas fa-times"></i> Clear</button>
                </div>
            </form>
        </div>
        
        <div class="response-box" id="responseBox" style="display: none;">
            <h3><i class="fas fa-terminal"></i> API Response</h3>
            
            <div class="response-section">
                <label><i class="fas fa-signal"></i> HTTP Status</label>
                <p><span class="status-badge" id="responseStatus">Loading...</span></p>
            </div>
            
            <div class="response-section">
                <label><i class="fas fa-arrows"></i> Response Headers</label>
                <pre id="responseHeaders"></pre>
            </div>
            
            <div class="response-section">
                <label><i class="fas fa-file-alt"></i> Raw Response Text</label>
                <pre id="responseText"></pre>
            </div>
            
            <div class="response-section">
                <label><i class="fas fa-cube"></i> Parsed JSON Response</label>
                <pre id="responseParsed"></pre>
            </div>
            
            <div class="response-section">
                <label><i class="fas fa-terminal"></i> Browser Console Output</label>
                <pre id="consoleOutput"></pre>
            </div>
        </div>
    </div>
    
    <!-- Message Modal System -->
    <div class="message-overlay" id="messageOverlay">
        <div class="message-modal">
            <div class="message-header" id="messageHeader">
                <i id="messageIcon"></i>
                <span class="message-title" id="messageTitle"></span>
            </div>
            <div class="message-body" id="messageBody"></div>
            <div class="message-details" id="messageDetails" style="display: none;"></div>
            <div class="message-footer">
                <button class="message-btn message-btn-secondary" onclick="closeMessage()">Close</button>
                <button class="message-btn message-btn-primary" onclick="copyMessageDetails()" id="copyBtn" style="display: none;"><i class="fas fa-copy"></i> Copy</button>
            </div>
        </div>
    </div>
    
    <script>
    const originalLog = console.log;
    const originalError = console.error;
    const logs = [];
    
    console.log = function(...args) {
        logs.push('ℹ️ ' + args.join(' '));
        originalLog.apply(console, args);
    };
    
    console.error = function(...args) {
        logs.push('❌ ' + args.join(' '));
        originalError.apply(console, args);
    };
    
    function showMessage(type, title, message, details = null) {
        const overlay = document.getElementById('messageOverlay');
        const header = document.getElementById('messageHeader');
        const titleEl = document.getElementById('messageTitle');
        const bodyEl = document.getElementById('messageBody');
        const detailsEl = document.getElementById('messageDetails');
        const copyBtn = document.getElementById('copyBtn');
        const iconEl = document.getElementById('messageIcon');
        
        // Set header class and icon
        header.className = 'message-header ' + type;
        
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-times-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        
        iconEl.className = 'fas ' + (icons[type] || 'fa-info-circle');
        titleEl.textContent = title;
        bodyEl.innerHTML = message;
        
        // Show details if provided
        if (details) {
            detailsEl.innerHTML = '<strong>Details:</strong><br>' + (typeof details === 'object' ? JSON.stringify(details, null, 2) : details);
            detailsEl.style.display = 'block';
            copyBtn.style.display = 'flex';
        } else {
            detailsEl.style.display = 'none';
            copyBtn.style.display = 'none';
        }
        
        overlay.classList.add('show');
    }
    
    function closeMessage() {
        document.getElementById('messageOverlay').classList.remove('show');
    }
    
    function copyMessageDetails() {
        const details = document.getElementById('messageDetails').innerText;
        navigator.clipboard.writeText(details).then(() => {
            const btn = document.getElementById('copyBtn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
            setTimeout(() => {
                btn.innerHTML = originalText;
            }, 2000);
        });
    }
    
    // Close modal on overlay click
    document.getElementById('messageOverlay').addEventListener('click', function(e) {
        if (e.target === this) {
            closeMessage();
        }
    });
    
    // Close with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeMessage();
        }
    });
    
    function getSelectedMembers() {
        const checkboxes = document.querySelectorAll('input[name="memberIds"]:checked');
        if (checkboxes.length > 0) {
            return Array.from(checkboxes).map(cb => parseInt(cb.value));
        }
        
        // Try to parse from textarea
        const textarea = document.getElementById('memberIds');
        if (textarea.value.trim()) {
            try {
                return JSON.parse(textarea.value);
            } catch (e) {
                showMessage('error', 'Invalid Input', 'Failed to parse Member IDs', 'Error: ' + e.message);
                return [];
            }
        }
        
        return [];
    }
    
    function testAPI() {
        logs.length = 0;
        
        const yearId = document.getElementById('yearId').value || null;
        const memberIds = getSelectedMembers();
        
        if (memberIds.length === 0) {
            showMessage('warning', 'No Members Selected', 'Please select at least one member to recalculate');
            return;
        }
        
        const requestData = {
            action: 'auto_recalculate',
            year_id: yearId ? parseInt(yearId) : null,
            member_ids: memberIds
        };
        
        console.log('Sending request:', requestData);
        
        const responseBox = document.getElementById('responseBox');
        responseBox.style.display = 'block';
        document.getElementById('responseStatus').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        
        fetch('superadmin/api-net-balance.php', {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(requestData)
        })
        .then(response => {
            console.log('Response received - Status: ' + response.status);
            console.log('Response headers:', {
                'content-type': response.headers.get('content-type'),
                'content-length': response.headers.get('content-length')
            });
            
            const statusClass = response.ok ? 'status-ok' : 'status-error';
            const statusIcon = response.ok ? 'fa-check-circle' : 'fa-exclamation-circle';
            document.getElementById('responseStatus').innerHTML = 
                '<i class="fas ' + statusIcon + '"></i> <span>HTTP ' + response.status + 
                (response.ok ? ' OK' : ' ERROR') + '</span>';
            
            document.getElementById('responseStatus').className = 'status-badge ' + statusClass;
            
            document.getElementById('responseHeaders').innerHTML = 
                'Content-Type: ' + response.headers.get('content-type') + '\n' +
                'Status: ' + response.status;
            
            return response.text();
        })
        .then(text => {
            console.log('Response text:', text);
            document.getElementById('responseText').innerHTML = text || '(empty)';
            
            try {
                const data = JSON.parse(text);
                console.log('Parsed response:', data);
                document.getElementById('responseParsed').innerHTML = 
                    JSON.stringify(data, null, 2);
                
                if (data.success) {
                    showMessage(
                        'success',
                        'Success!',
                        '<i class="fas fa-check"></i> Successfully recalculated for <strong>' + data.changes + '</strong> member(s)',
                        JSON.stringify(data, null, 2)
                    );
                } else {
                    showMessage(
                        'error',
                        'API Error',
                        data.message || data.error || 'Unknown error occurred',
                        JSON.stringify(data, null, 2)
                    );
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                document.getElementById('responseParsed').innerHTML = 
                    '<i class="fas fa-exclamation-triangle"></i> <strong>Parse Error:</strong> ' + e.message;
                
                showMessage(
                    'error',
                    'Response Parse Error',
                    'Failed to parse server response as JSON',
                    text
                );
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            document.getElementById('responseStatus').innerHTML = 
                '<span class="status-badge status-error"><i class="fas fa-times-circle"></i> Network Error: ' + error.message + '</span>';
            
            showMessage(
                'error',
                'Network Error',
                'Failed to connect to the API endpoint',
                error.message
            );
        })
        .finally(() => {
            document.getElementById('consoleOutput').innerHTML = logs.join('\n') || '(no logs)';
        });
    }
    
    function clearForm() {
        document.querySelectorAll('input[name="memberIds"]:checked').forEach(cb => cb.checked = false);
        document.getElementById('memberIds').value = '';
        document.getElementById('responseBox').style.display = 'none';
        logs.length = 0;
        showMessage('info', 'Form Cleared', 'All selections and responses have been cleared');
    }
    </script>
</body>
</html>
