<?php
/**
 * Debug Test for Auto-Recalculate Feature
 * Tests the complete flow: API call simulation, helper function, database operations
 */

session_start();
require_once 'config/config.php';
require_once 'includes/net-balance-helper.php';

// Simulate being a logged-in super admin
if (!isset($_SESSION['superadmin_id'])) {
    $_SESSION['superadmin_id'] = 1;
    $_SESSION['superadmin_name'] = 'Debug Test';
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Auto-Recalculate Debug Test</title>
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
            max-width: 1000px;
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
        
        .test-box {
            background: var(--bg-primary-solid);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            border-left: 4px solid #667eea;
        }
        
        .test-box h2 {
            margin-bottom: 20px;
            color: var(--text-primary);
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .success-message {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #28a745;
            margin: 10px 0;
            font-weight: 500;
        }
        
        .error-message {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #dc3545;
            margin: 10px 0;
            font-weight: 500;
        }
        
        .info-message {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #17a2b8;
            margin: 10px 0;
            font-weight: 500;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        th {
            background: var(--input-bg);
            font-weight: 600;
            color: var(--text-primary);
        }
        
        pre {
            background: var(--input-bg);
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 12px;
            line-height: 1.5;
            border: 1px solid var(--border-color);
            margin: 10px 0;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        button {
            padding: 12px 24px;
            background: var(--gradient-primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }
        
        button:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
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
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
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
            flex-shrink: 0;
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
            overflow-y: auto;
            flex: 1;
        }
        
        .message-details {
            background: var(--input-bg);
            padding: 12px;
            border-radius: 6px;
            margin-top: 12px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 300px;
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
            flex-shrink: 0;
        }
        
        .message-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 6px;
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
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div class="header-section">
            <h1><i class="fas fa-bug"></i> Auto-Recalculate Debug Test</h1>
            <p>Complete system diagnostic for the auto-recalculate feature</p>
        </div>
        
        <div class="test-box">
            <h2><i class="fas fa-database"></i> Step 1: Check Database Connection</h2>
            <?php
            try {
                $stmt = $pdo->query("SELECT 1");
                echo '<p class="success-message"><i class="fas fa-check-circle"></i> Database connection OK</p>';
            } catch (Exception $e) {
                echo '<p class="error-message"><i class="fas fa-times-circle"></i> Database error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
            ?>
        </div>
        
        <div class="test-box">
            <h2><i class="fas fa-table"></i> Step 2: Verify member_net_balance Table Structure</h2>
            <?php
            try {
                $stmt = $pdo->query("DESCRIBE member_net_balance");
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo '<p class="success-message"><i class="fas fa-check-circle"></i> Table structure verified</p>';
                echo '<table><tr><th>Field</th><th>Type</th><th>Key</th></tr>';
                foreach ($columns as $col) {
                    echo '<tr><td>' . htmlspecialchars($col['Field']) . '</td><td>' . htmlspecialchars($col['Type']) . '</td><td>' . htmlspecialchars($col['Key']) . '</td></tr>';
                }
                echo '</table>';
            } catch (Exception $e) {
                echo '<p class="error-message"><i class="fas fa-times-circle"></i> Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
            ?>
        </div>
        
        <div class="test-box">
            <h2><i class="fas fa-calendar"></i> Step 3: Get Active Academic Year</h2>
            <?php
            try {
                $stmt = $pdo->query("SELECT YearID, YearName, Status FROM academic_years WHERE Status = 'Active' LIMIT 1");
                $activeYear = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($activeYear) {
                    echo '<p class="success-message"><i class="fas fa-check-circle"></i> Active Year Found</p>';
                    echo '<pre>' . json_encode($activeYear, JSON_PRETTY_PRINT) . '</pre>';
                    $testYearId = $activeYear['YearID'];
                } else {
                    echo '<p class="error-message"><i class="fas fa-times-circle"></i> No active year found</p>';
                    $stmt = $pdo->query("SELECT YearID FROM academic_years LIMIT 1");
                    $year = $stmt->fetch(PDO::FETCH_ASSOC);
                    $testYearId = $year['YearID'] ?? 1;
                }
            } catch (Exception $e) {
                echo '<p class="error-message"><i class="fas fa-times-circle"></i> Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
            ?>
        </div>
        
        <div class="test-box">
            <h2><i class="fas fa-users"></i> Step 4: Get Sample Members</h2>
            <?php
            try {
                $stmt = $pdo->query("SELECT MemberID, MemberName, Status FROM members WHERE Status = 'Active' LIMIT 5");
                $sampleMembers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($sampleMembers) > 0) {
                    echo '<p class="success-message"><i class="fas fa-check-circle"></i> Found ' . count($sampleMembers) . ' active members</p>';
                    $memberIds = [];
                    foreach ($sampleMembers as $member) {
                        echo '• ' . $member['MemberID'] . ': ' . htmlspecialchars($member['MemberName']) . '<br>';
                        $memberIds[] = $member['MemberID'];
                    }
                } else {
                    echo '<p class="error-message"><i class="fas fa-times-circle"></i> No active members found</p>';
                    $memberIds = [];
                }
            } catch (Exception $e) {
                echo '<p class="error-message"><i class="fas fa-times-circle"></i> Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
            ?>
        </div>
        
        <div class="test-box">
            <h2><i class="fas fa-cogs"></i> Step 5: Test autoRecalculateSelectedNetBalances() Function</h2>
            <?php
            try {
                if (!empty($memberIds)) {
                    echo '<p class="info-message"><i class="fas fa-info-circle"></i> Testing with members: ' . implode(', ', $memberIds) . '</p>';
                    echo '<p class="info-message"><i class="fas fa-info-circle"></i> Testing with year ID: ' . $testYearId . '</p>';
                    
                    $result = autoRecalculateSelectedNetBalances($testYearId, $memberIds);
                    
                    if ($result['success']) {
                        echo '<p class="success-message"><i class="fas fa-check-circle"></i> Function executed successfully</p>';
                    } else {
                        echo '<p class="error-message"><i class="fas fa-times-circle"></i> Function error: ' . htmlspecialchars($result['error'] ?? $result['message'] ?? 'Unknown error') . '</p>';
                    }
                    echo '<pre>' . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . '</pre>';
                } else {
                    echo '<p class="error-message"><i class="fas fa-times-circle"></i> No members available to test</p>';
                }
            } catch (Exception $e) {
                echo '<p class="error-message"><i class="fas fa-times-circle"></i> Exception: ' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
            }
            ?>
        </div>
        
        <div class="test-box">
            <h2><i class="fas fa-save"></i> Step 6: Verify Data Was Saved to Database</h2>
            <?php
            try {
                if (!empty($memberIds)) {
                    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
                    $stmt = $pdo->prepare("
                        SELECT 
                            MemberID, 
                            YearID, 
                            NetBalance, 
                            LastUpdatedAt,
                            UpdatedBy
                        FROM member_net_balance 
                        WHERE YearID = ? AND MemberID IN ($placeholders)
                        ORDER BY MemberID ASC
                    ");
                    $params = array_merge([$testYearId], $memberIds);
                    $stmt->execute($params);
                    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (count($records) > 0) {
                        echo '<p class="success-message"><i class="fas fa-check-circle"></i> Found ' . count($records) . ' records in database</p>';
                        echo '<table>';
                        echo '<tr><th>MemberID</th><th>YearID</th><th>NetBalance</th><th>LastUpdatedAt</th><th>UpdatedBy</th></tr>';
                        foreach ($records as $record) {
                            echo '<tr>';
                            echo '<td>' . htmlspecialchars($record['MemberID']) . '</td>';
                            echo '<td>' . htmlspecialchars($record['YearID']) . '</td>';
                            echo '<td>' . htmlspecialchars($record['NetBalance']) . '</td>';
                            echo '<td>' . htmlspecialchars($record['LastUpdatedAt']) . '</td>';
                            echo '<td>' . htmlspecialchars($record['UpdatedBy']) . '</td>';
                            echo '</tr>';
                        }
                        echo '</table>';
                    } else {
                        echo '<p class="error-message"><i class="fas fa-exclamation-circle"></i> No records found in member_net_balance table</p>';
                    }
                }
            } catch (Exception $e) {
                echo '<p class="error-message"><i class="fas fa-times-circle"></i> Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
            ?>
        </div>
        
        <div class="test-box">
            <h2><i class="fas fa-file-alt"></i> Step 7: Check PHP Error Logs</h2>
            <?php
            $errorLogPath = ini_get('error_log');
            echo '<p class="info-message"><i class="fas fa-info-circle"></i> Error log location: ' . htmlspecialchars($errorLogPath ?: 'Default system log') . '</p>';
            echo '<p><a href="test-php-info.php" style="color: #667eea; text-decoration: none;"><i class="fas fa-link"></i> View PHP Info</a></p>';
            ?>
        </div>
        
        <div class="test-box">
            <h2><i class="fas fa-plug"></i> Step 8: Test API Endpoint Directly</h2>
            <p class="info-message"><i class="fas fa-lightbulb"></i> Click the button below to make a fetch request to the API</p>
            <pre>
fetch('superadmin/api-net-balance.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        action: 'auto_recalculate',
        year_id: <?php echo $testYearId ?? 1; ?>,
        member_ids: [<?php echo implode(', ', array_slice($memberIds, 0, 2)); ?>]
    })
})
.then(r => r.text())
.then(text => {
    console.log('Response:', text);
    try {
        console.log('Parsed:', JSON.parse(text));
    } catch(e) {
        console.error('Parse error:', e);
    }
});
            </pre>
            <div class="button-group">
                <button onclick="testAPIEndpoint()"><i class="fas fa-play"></i> Test API Endpoint</button>
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
    
    function testAPIEndpoint() {
        const testData = {
            action: 'auto_recalculate',
            year_id: <?php echo $testYearId ?? 1; ?>,
            member_ids: [<?php echo implode(', ', array_slice($memberIds, 0, 2)); ?>]
        };
        
        console.log('Sending request:', testData);
        
        fetch('superadmin/api-net-balance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(testData)
        })
        .then(response => {
            console.log('Response status:', response.status);
            return response.text();
        })
        .then(text => {
            console.log('Raw response:', text);
            try {
                const data = JSON.parse(text);
                console.log('Parsed response:', data);
                showMessage(
                    'success',
                    'API Response Received',
                    '<i class="fas fa-check"></i> Response parsed successfully',
                    JSON.stringify(data, null, 2)
                );
            } catch (e) {
                console.error('Parse error:', e);
                showMessage(
                    'error',
                    'Response Parse Error',
                    'Failed to parse response as JSON: ' + e.message,
                    text
                );
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            showMessage(
                'error',
                'API Request Failed',
                'Failed to connect to API: ' + error.message
            );
        });
    }
    </script>
</body>
</html>
