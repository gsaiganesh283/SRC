<?php
// Report Templates Management - Super Admin
require_once 'session.php';
require_once '../config/config.php';

$superAdminName = $_SESSION['superadmin_name'] ?? 'Super Admin';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'create':
                $stmt = $pdo->prepare("
                    INSERT INTO report_templates 
                    (TemplateName, TemplateType, Description, TemplateConfig, ApplicableTo, IsDefault, CreatedBy) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['template_name'],
                    $_POST['template_type'],
                    $_POST['description'],
                    $_POST['template_config'],
                    $_POST['applicable_to'],
                    isset($_POST['is_default']) ? 1 : 0,
                    $_SESSION['superadmin_id']
                ]);
                echo json_encode(['success' => true, 'message' => 'Template created successfully']);
                break;
                
            case 'update':
                $stmt = $pdo->prepare("
                    UPDATE report_templates 
                    SET TemplateName = ?, TemplateType = ?, Description = ?, 
                        TemplateConfig = ?, ApplicableTo = ?, IsDefault = ?
                    WHERE TemplateID = ?
                ");
                $stmt->execute([
                    $_POST['template_name'],
                    $_POST['template_type'],
                    $_POST['description'],
                    $_POST['template_config'],
                    $_POST['applicable_to'],
                    isset($_POST['is_default']) ? 1 : 0,
                    $_POST['template_id']
                ]);
                echo json_encode(['success' => true, 'message' => 'Template updated successfully']);
                break;
                
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM report_templates WHERE TemplateID = ?");
                $stmt->execute([$_POST['template_id']]);
                echo json_encode(['success' => true, 'message' => 'Template deleted successfully']);
                break;
                
            case 'toggle_active':
                $stmt = $pdo->prepare("UPDATE report_templates SET IsActive = NOT IsActive WHERE TemplateID = ?");
                $stmt->execute([$_POST['template_id']]);
                echo json_encode(['success' => true, 'message' => 'Template status updated']);
                break;
                
            case 'set_default':
                // First, unset all defaults for the same ApplicableTo
                $stmt = $pdo->prepare("UPDATE report_templates SET IsDefault = 0 WHERE ApplicableTo = ?");
                $stmt->execute([$_POST['applicable_to']]);
                
                // Set new default
                $stmt = $pdo->prepare("UPDATE report_templates SET IsDefault = 1 WHERE TemplateID = ?");
                $stmt->execute([$_POST['template_id']]);
                echo json_encode(['success' => true, 'message' => 'Default template set']);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch all templates
try {
    $stmt = $pdo->query("SELECT * FROM report_templates ORDER BY ApplicableTo, IsDefault DESC, TemplateName");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $templates = [];
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Templates - Super Admin</title>
    <link rel="stylesheet" href="shared-styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .templates-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .templates-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .template-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .template-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        
        .template-card.default {
            border-color: #eab308;
            background: linear-gradient(135deg, #fefce8 0%, #ffffff 100%);
        }
        
        .template-card.inactive {
            opacity: 0.6;
        }
        
        .template-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        
        .template-name {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 5px;
        }
        
        .template-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-default { background: #fef3c7; color: #92400e; }
        .badge-pdf { background: #dbeafe; color: #1e40af; }
        .badge-print { background: #d1fae5; color: #065f46; }
        .badge-both { background: #e9d5ff; color: #6b21a8; }
        .badge-all { background: #e2e8f0; color: #475569; }
        .badge-admin { background: #fee2e2; color: #991b1b; }
        .badge-member { background: #fce7f3; color: #9f1239; }
        
        .template-description {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .template-config {
            background: #f8fafc;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .config-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: #475569;
            margin-bottom: 6px;
        }
        
        .config-item i {
            color: #94a3b8;
            width: 16px;
        }
        
        .template-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }
        
        .btn-primary { background: #3b82f6; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal.active { display: flex; }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 30px;
            max-width: 700px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #64748b;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 8px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .form-group textarea {
            min-height: 80px;
            resize: vertical;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
        }
        
        .template-builder {
            background: #f8fafc;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .builder-section {
            margin-bottom: 15px;
        }
        
        .builder-section h4 {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 10px;
        }
        
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="templates-container">
        
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-file-invoice"></i>
                Report Templates
            </h1>
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> Create Template
            </button>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <div class="templates-grid">
            <?php foreach ($templates as $template): 
                $config = json_decode($template['TemplateConfig'], true);
            ?>
                <div class="template-card <?php echo $template['IsDefault'] ? 'default' : ''; ?> <?php echo !$template['IsActive'] ? 'inactive' : ''; ?>">
                    <div class="template-header">
                        <div>
                            <div class="template-name"><?php echo htmlspecialchars($template['TemplateName']); ?></div>
                            <div class="template-badges">
                                <?php if ($template['IsDefault']): ?>
                                    <span class="badge badge-default"><i class="fas fa-star"></i> Default</span>
                                <?php endif; ?>
                                <span class="badge badge-<?php echo strtolower($template['TemplateType']); ?>">
                                    <?php echo $template['TemplateType']; ?>
                                </span>
                                <span class="badge badge-<?php echo strtolower($template['ApplicableTo']); ?>">
                                    <?php echo $template['ApplicableTo']; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="template-description">
                        <?php echo htmlspecialchars($template['Description']); ?>
                    </div>
                    
                    <div class="template-config">
                        <?php if (!empty($config['sections'])): ?>
                            <div class="config-item">
                                <i class="fas fa-layer-group"></i>
                                <span><?php echo count($config['sections']); ?> Sections</span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($config['charts'])): ?>
                            <div class="config-item">
                                <i class="fas fa-chart-bar"></i>
                                <span><?php echo count($config['charts']); ?> Charts</span>
                            </div>
                        <?php endif; ?>
                        <div class="config-item">
                            <i class="fas fa-file"></i>
                            <span><?php echo $config['pageSize'] ?? 'A4'; ?> • <?php echo ucfirst($config['orientation'] ?? 'portrait'); ?></span>
                        </div>
                        <div class="config-item">
                            <i class="fas fa-palette"></i>
                            <span style="display: flex; gap: 4px; align-items: center;">
                                <span style="width: 16px; height: 16px; background: <?php echo $config['colors']['primary'] ?? '#1e293b'; ?>; border-radius: 4px;"></span>
                                <span style="width: 16px; height: 16px; background: <?php echo $config['colors']['accent'] ?? '#eab308'; ?>; border-radius: 4px;"></span>
                            </span>
                        </div>
                    </div>
                    
                    <div class="template-actions">
                        <?php if (!$template['IsDefault']): ?>
                            <button class="btn btn-sm btn-warning" onclick="setDefault(<?php echo $template['TemplateID']; ?>, '<?php echo $template['ApplicableTo']; ?>')">
                                <i class="fas fa-star"></i> Set Default
                            </button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-primary" onclick="editTemplate(<?php echo $template['TemplateID']; ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn btn-sm btn-secondary" onclick="previewTemplate(<?php echo $template['TemplateID']; ?>)">
                            <i class="fas fa-eye"></i> Preview
                        </button>
                        <button class="btn btn-sm btn-<?php echo $template['IsActive'] ? 'warning' : 'success'; ?>" 
                                onclick="toggleActive(<?php echo $template['TemplateID']; ?>)">
                            <i class="fas fa-<?php echo $template['IsActive'] ? 'pause' : 'play'; ?>"></i>
                        </button>
                        <?php if (!$template['IsDefault']): ?>
                            <button class="btn btn-sm btn-danger" onclick="deleteTemplate(<?php echo $template['TemplateID']; ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Create/Edit Modal -->
    <div id="templateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Create Template</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            
            <form id="templateForm" onsubmit="saveTemplate(event)">
                <input type="hidden" id="templateId" name="template_id">
                <input type="hidden" id="action" name="action" value="create">
                
                <div class="form-group">
                    <label>Template Name *</label>
                    <input type="text" name="template_name" id="templateName" required>
                </div>
                
                <div class="form-group">
                    <label>Template Type *</label>
                    <select name="template_type" id="templateType" required>
                        <option value="Both">PDF & Print</option>
                        <option value="PDF">PDF Only</option>
                        <option value="Print">Print Only</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Applicable To *</label>
                    <select name="applicable_to" id="applicableTo" required>
                        <option value="All">All Portals</option>
                        <option value="SuperAdmin">Super Admin Only</option>
                        <option value="Admin">Admin Only</option>
                        <option value="Member">Member Only</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="description"></textarea>
                </div>
                
                <div class="form-group checkbox-group">
                    <input type="checkbox" name="is_default" id="isDefault">
                    <label for="isDefault" style="margin-bottom: 0;">Set as Default Template</label>
                </div>
                
                <div class="template-builder">
                    <h3 style="margin-bottom: 15px; font-size: 16px;">Template Configuration</h3>
                    
                    <div class="builder-section">
                        <h4><i class="fas fa-layer-group"></i> Sections to Include</h4>
                        <div class="checkbox-grid">
                            <label class="checkbox-group">
                                <input type="checkbox" class="section-checkbox" value="summary" checked>
                                <span>Summary</span>
                            </label>
                            <label class="checkbox-group">
                                <input type="checkbox" class="section-checkbox" value="charts" checked>
                                <span>Charts</span>
                            </label>
                            <label class="checkbox-group">
                                <input type="checkbox" class="section-checkbox" value="tables" checked>
                                <span>Tables</span>
                            </label>
                            <label class="checkbox-group">
                                <input type="checkbox" class="section-checkbox" value="analysis">
                                <span>Analysis</span>
                            </label>
                            <label class="checkbox-group">
                                <input type="checkbox" class="section-checkbox" value="recommendations">
                                <span>Recommendations</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="builder-section">
                        <h4><i class="fas fa-chart-bar"></i> Charts to Include</h4>
                        <div class="checkbox-grid">
                            <label class="checkbox-group">
                                <input type="checkbox" class="chart-checkbox" value="monthly" checked>
                                <span>Monthly Trend</span>
                            </label>
                            <label class="checkbox-group">
                                <input type="checkbox" class="chart-checkbox" value="payment" checked>
                                <span>Payment Mode</span>
                            </label>
                            <label class="checkbox-group">
                                <input type="checkbox" class="chart-checkbox" value="topCollectors">
                                <span>Top Collectors</span>
                            </label>
                            <label class="checkbox-group">
                                <input type="checkbox" class="chart-checkbox" value="growth">
                                <span>Growth Rate</span>
                            </label>
                            <label class="checkbox-group">
                                <input type="checkbox" class="chart-checkbox" value="ratio">
                                <span>Collection Ratio</span>
                            </label>
                            <label class="checkbox-group">
                                <input type="checkbox" class="chart-checkbox" value="time">
                                <span>Time Distribution</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="builder-section">
                        <h4><i class="fas fa-cog"></i> Page Settings</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label>Page Size</label>
                                <select id="pageSize">
                                    <option value="A4">A4</option>
                                    <option value="Letter">Letter</option>
                                    <option value="Legal">Legal</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Orientation</label>
                                <select id="orientation">
                                    <option value="portrait">Portrait</option>
                                    <option value="landscape">Landscape</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="builder-section">
                        <h4><i class="fas fa-palette"></i> Color Theme</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div class="form-group">
                                <label>Primary Color</label>
                                <input type="color" id="primaryColor" value="#1e293b">
                            </div>
                            <div class="form-group">
                                <label>Accent Color</label>
                                <input type="color" id="accentColor" value="#eab308">
                            </div>
                        </div>
                    </div>
                    
                    <div class="builder-section">
                        <h4><i class="fas fa-check-square"></i> Additional Options</h4>
                        <div class="checkbox-grid">
                            <label class="checkbox-group">
                                <input type="checkbox" id="includeLogo" checked>
                                <span>Include Logo</span>
                            </label>
                            <label class="checkbox-group">
                                <input type="checkbox" id="includeCover">
                                <span>Cover Page</span>
                            </label>
                            <label class="checkbox-group">
                                <input type="checkbox" id="includeTOC">
                                <span>Table of Contents</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <input type="hidden" name="template_config" id="templateConfig">
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Template
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        const templates = <?php echo json_encode($templates); ?>;
        
        function openCreateModal() {
            document.getElementById('modalTitle').textContent = 'Create Template';
            document.getElementById('action').value = 'create';
            document.getElementById('templateForm').reset();
            document.getElementById('templateModal').classList.add('active');
        }
        
        function editTemplate(id) {
            const template = templates.find(t => t.TemplateID == id);
            if (!template) return;
            
            const config = JSON.parse(template.TemplateConfig);
            
            document.getElementById('modalTitle').textContent = 'Edit Template';
            document.getElementById('action').value = 'update';
            document.getElementById('templateId').value = template.TemplateID;
            document.getElementById('templateName').value = template.TemplateName;
            document.getElementById('templateType').value = template.TemplateType;
            document.getElementById('applicableTo').value = template.ApplicableTo;
            document.getElementById('description').value = template.Description;
            document.getElementById('isDefault').checked = template.IsDefault == 1;
            
            // Set sections
            document.querySelectorAll('.section-checkbox').forEach(cb => {
                cb.checked = config.sections && config.sections.includes(cb.value);
            });
            
            // Set charts
            document.querySelectorAll('.chart-checkbox').forEach(cb => {
                cb.checked = config.charts && config.charts.includes(cb.value);
            });
            
            // Set page settings
            document.getElementById('pageSize').value = config.pageSize || 'A4';
            document.getElementById('orientation').value = config.orientation || 'portrait';
            document.getElementById('primaryColor').value = config.colors?.primary || '#1e293b';
            document.getElementById('accentColor').value = config.colors?.accent || '#eab308';
            document.getElementById('includeLogo').checked = config.logo !== false;
            document.getElementById('includeCover').checked = config.includeCover === true;
            document.getElementById('includeTOC').checked = config.includeTableOfContents === true;
            
            document.getElementById('templateModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('templateModal').classList.remove('active');
        }
        
        function saveTemplate(e) {
            e.preventDefault();
            
            // Build configuration JSON
            const sections = Array.from(document.querySelectorAll('.section-checkbox:checked')).map(cb => cb.value);
            const charts = Array.from(document.querySelectorAll('.chart-checkbox:checked')).map(cb => cb.value);
            
            const config = {
                sections: sections,
                charts: charts,
                colors: {
                    primary: document.getElementById('primaryColor').value,
                    accent: document.getElementById('accentColor').value
                },
                logo: document.getElementById('includeLogo').checked,
                header: "company",
                footer: "page_numbers",
                pageSize: document.getElementById('pageSize').value,
                orientation: document.getElementById('orientation').value,
                includeCover: document.getElementById('includeCover').checked,
                includeTableOfContents: document.getElementById('includeTOC').checked
            };
            
            document.getElementById('templateConfig').value = JSON.stringify(config);
            
            const formData = new FormData(e.target);
            
            fetch('manage-templates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error saving template: ' + error);
            });
        }
        
        function deleteTemplate(id) {
            if (!confirm('Are you sure you want to delete this template?')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('template_id', id);
            
            fetch('manage-templates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            });
        }
        
        function toggleActive(id) {
            const formData = new FormData();
            formData.append('action', 'toggle_active');
            formData.append('template_id', id);
            
            fetch('manage-templates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            });
        }
        
        function setDefault(id, applicableTo) {
            const formData = new FormData();
            formData.append('action', 'set_default');
            formData.append('template_id', id);
            formData.append('applicable_to', applicableTo);
            
            fetch('manage-templates.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            });
        }
        
        function previewTemplate(id) {
            window.open('preview-template.php?template_id=' + id, '_blank');
        }
    </script>
    <script src="theme-manager.js"></script>
</body>
</html>
