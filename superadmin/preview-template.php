<?php
// Preview Report Template - Super Admin
require_once 'session.php';
require_once '../config/config.php';

$templateId = $_GET['template_id'] ?? null;

if (!$templateId) {
    die('Template ID is required');
}

// Fetch template details
try {
    $stmt = $pdo->prepare("SELECT * FROM report_templates WHERE TemplateID = ?");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        die('Template not found');
    }
    
    $config = json_decode($template['TemplateConfig'], true);
} catch (Exception $e) {
    die('Error loading template: ' . $e->getMessage());
}

$superAdminName = $_SESSION['superadmin_name'] ?? 'Super Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview: <?php echo htmlspecialchars($template['TemplateName']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .preview-header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .preview-title {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .template-info {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        .badge {
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-pdf { background: #dbeafe; color: #1e40af; }
        .badge-print { background: #d1fae5; color: #065f46; }
        .badge-both { background: #e9d5ff; color: #6b21a8; }
        
        .preview-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .page-wrapper {
            padding: 40px;
            background: white;
        }
        
        /* Apply template orientation */
        .page-wrapper.landscape {
            width: 297mm;
            min-height: 210mm;
        }
        
        .page-wrapper.portrait {
            width: 210mm;
            min-height: 297mm;
        }
        
        /* Cover Page */
        .cover-page {
            text-align: center;
            padding: 100px 40px;
            background: linear-gradient(135deg, <?php echo $config['colors']['primary'] ?? '#1e293b'; ?> 0%, <?php echo $config['colors']['accent'] ?? '#eab308'; ?> 100%);
            color: white;
            min-height: 400px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .cover-logo {
            font-size: 72px;
            margin-bottom: 30px;
            opacity: 0.9;
        }
        
        .cover-title {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .cover-subtitle {
            font-size: 24px;
            opacity: 0.9;
            margin-bottom: 40px;
        }
        
        .cover-date {
            font-size: 18px;
            opacity: 0.8;
        }
        
        /* Table of Contents */
        .toc-section {
            padding: 40px;
            background: #f8fafc;
        }
        
        .toc-title {
            font-size: 32px;
            font-weight: 700;
            color: <?php echo $config['colors']['primary'] ?? '#1e293b'; ?>;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid <?php echo $config['colors']['accent'] ?? '#eab308'; ?>;
        }
        
        .toc-item {
            display: flex;
            justify-content: space-between;
            padding: 15px 20px;
            margin-bottom: 10px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid <?php echo $config['colors']['accent'] ?? '#eab308'; ?>;
        }
        
        .toc-item-title {
            font-size: 18px;
            color: #1e293b;
            font-weight: 600;
        }
        
        .toc-item-page {
            color: #64748b;
            font-weight: 600;
        }
        
        /* Report Header */
        .report-header {
            background: linear-gradient(135deg, <?php echo $config['colors']['primary'] ?? '#1e293b'; ?> 0%, <?php echo $config['colors']['accent'] ?? '#eab308'; ?> 100%);
            color: white;
            padding: 30px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-logo {
            font-size: 36px;
        }
        
        .header-info h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        
        .header-info p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        /* Content Section */
        .content-section {
            padding: 40px;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 700;
            color: <?php echo $config['colors']['primary'] ?? '#1e293b'; ?>;
            margin-bottom: 20px;
            padding-left: 15px;
            border-left: 5px solid <?php echo $config['colors']['accent'] ?? '#eab308'; ?>;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            border-top: 4px solid <?php echo $config['colors']['accent'] ?? '#eab308'; ?>;
        }
        
        .stat-label {
            font-size: 13px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: <?php echo $config['colors']['primary'] ?? '#1e293b'; ?>;
        }
        
        /* Chart Preview */
        .chart-preview {
            background: #f8fafc;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            border: 2px dashed #cbd5e1;
            text-align: center;
        }
        
        .chart-preview i {
            font-size: 64px;
            color: <?php echo $config['colors']['accent'] ?? '#eab308'; ?>;
            margin-bottom: 15px;
        }
        
        .chart-preview-title {
            font-size: 18px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 10px;
        }
        
        .chart-preview-desc {
            color: #94a3b8;
            font-size: 14px;
        }
        
        /* Table Preview */
        .table-preview {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 30px;
            border: 2px solid #e2e8f0;
        }
        
        .table-preview table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-preview th {
            background: <?php echo $config['colors']['primary'] ?? '#1e293b'; ?>;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .table-preview td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            color: #1e293b;
        }
        
        .table-preview tr:nth-child(even) {
            background: #f8fafc;
        }
        
        /* Analysis Section */
        .analysis-box {
            background: #fefce8;
            border-left: 5px solid <?php echo $config['colors']['accent'] ?? '#eab308'; ?>;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .analysis-box h3 {
            color: <?php echo $config['colors']['primary'] ?? '#1e293b'; ?>;
            margin-bottom: 15px;
            font-size: 20px;
        }
        
        .analysis-box p {
            color: #475569;
            line-height: 1.6;
        }
        
        /* Footer */
        .report-footer {
            background: #f8fafc;
            padding: 20px 40px;
            text-align: center;
            border-top: 3px solid <?php echo $config['colors']['accent'] ?? '#eab308'; ?>;
            color: #64748b;
            font-size: 14px;
        }
        
        .action-bar {
            position: fixed;
            bottom: 20px;
            right: 20px;
            display: flex;
            gap: 10px;
            z-index: 1000;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            transition: all 0.3s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.3);
        }
        
        .btn-primary { background: #3b82f6; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        
        @media print {
            body { background: white; padding: 0; }
            .preview-header, .action-bar { display: none; }
            .preview-container { box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="preview-header">
        <div class="preview-title">
            <i class="fas fa-eye"></i>
            Template Preview: <?php echo htmlspecialchars($template['TemplateName']); ?>
        </div>
        <div class="template-info">
            <span class="badge badge-<?php echo strtolower($template['TemplateType']); ?>">
                <?php echo $template['TemplateType']; ?>
            </span>
            <span style="color: #64748b;">
                <i class="fas fa-user"></i> <?php echo $template['ApplicableTo']; ?>
            </span>
            <span style="color: #64748b;">
                <i class="fas fa-file"></i> <?php echo $config['pageSize'] ?? 'A4'; ?> • 
                <?php echo ucfirst($config['orientation'] ?? 'portrait'); ?>
            </span>
        </div>
    </div>
    
    <div class="preview-container">
        <!-- Cover Page -->
        <?php if ($config['includeCover'] ?? false): ?>
        <div class="cover-page">
            <?php if ($config['logo'] ?? true): ?>
            <div class="cover-logo">
                <i class="fas fa-building"></i>
            </div>
            <?php endif; ?>
            <h1 class="cover-title">SR Colony Management System</h1>
            <p class="cover-subtitle">Financial & Analytics Report</p>
            <p class="cover-date">Generated: <?php echo date('F d, Y'); ?></p>
        </div>
        <?php endif; ?>
        
        <!-- Table of Contents -->
        <?php if ($config['includeTableOfContents'] ?? false): ?>
        <div class="toc-section">
            <h2 class="toc-title"><i class="fas fa-list"></i> Table of Contents</h2>
            <?php 
            $page = 1;
            if (in_array('summary', $config['sections'] ?? [])): ?>
                <div class="toc-item">
                    <span class="toc-item-title"><i class="fas fa-chart-bar"></i> Executive Summary</span>
                    <span class="toc-item-page">Page <?php echo $page++; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (in_array('charts', $config['sections'] ?? [])): ?>
                <div class="toc-item">
                    <span class="toc-item-title"><i class="fas fa-chart-line"></i> Visual Analytics</span>
                    <span class="toc-item-page">Page <?php echo $page++; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (in_array('tables', $config['sections'] ?? [])): ?>
                <div class="toc-item">
                    <span class="toc-item-title"><i class="fas fa-table"></i> Detailed Data Tables</span>
                    <span class="toc-item-page">Page <?php echo $page++; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (in_array('analysis', $config['sections'] ?? [])): ?>
                <div class="toc-item">
                    <span class="toc-item-title"><i class="fas fa-brain"></i> Advanced Analysis</span>
                    <span class="toc-item-page">Page <?php echo $page++; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (in_array('recommendations', $config['sections'] ?? [])): ?>
                <div class="toc-item">
                    <span class="toc-item-title"><i class="fas fa-lightbulb"></i> Recommendations</span>
                    <span class="toc-item-page">Page <?php echo $page++; ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="page-wrapper <?php echo $config['orientation'] ?? 'portrait'; ?>">
            <!-- Report Header -->
            <?php if ($config['logo'] ?? true): ?>
            <div class="report-header">
                <div class="header-logo">
                    <i class="fas fa-building"></i>
                </div>
                <div class="header-info">
                    <h1>SR Colony Chitti Management</h1>
                    <p>Report Period: January 2025 - November 2025</p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Summary Section -->
            <?php if (in_array('summary', $config['sections'] ?? [])): ?>
            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-chart-bar"></i> Executive Summary</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Members</div>
                        <div class="stat-value">150</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Total Collection</div>
                        <div class="stat-value">₹2.5M</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Transactions</div>
                        <div class="stat-value">1,234</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Active Years</div>
                        <div class="stat-value">5</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Charts Section -->
            <?php if (in_array('charts', $config['sections'] ?? [])): ?>
            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-chart-line"></i> Visual Analytics</h2>
                
                <?php foreach ($config['charts'] ?? [] as $chartType): ?>
                <div class="chart-preview">
                    <i class="fas fa-chart-<?php 
                        echo $chartType == 'monthly' ? 'line' : 
                            ($chartType == 'payment' ? 'pie' : 
                            ($chartType == 'topCollectors' ? 'bar' : 'area')); 
                    ?>"></i>
                    <div class="chart-preview-title">
                        <?php 
                        $chartTitles = [
                            'monthly' => 'Monthly Collection Trend',
                            'payment' => 'Payment Mode Distribution',
                            'topCollectors' => 'Top 5 Collectors',
                            'growth' => 'Growth Rate Analysis',
                            'ratio' => 'Collection vs Distribution',
                            'time' => 'Peak Transaction Hours'
                        ];
                        echo $chartTitles[$chartType] ?? ucfirst($chartType) . ' Chart';
                        ?>
                    </div>
                    <p class="chart-preview-desc">Interactive visualization with real-time data</p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Tables Section -->
            <?php if (in_array('tables', $config['sections'] ?? [])): ?>
            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-table"></i> Detailed Data Tables</h2>
                
                <div class="table-preview">
                    <table>
                        <thead>
                            <tr>
                                <th>Member Name</th>
                                <th>Phone</th>
                                <th>Transactions</th>
                                <th>Total Collection</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>John Doe</td>
                                <td>9876543210</td>
                                <td>25</td>
                                <td>₹125,000</td>
                                <td>Active</td>
                            </tr>
                            <tr>
                                <td>Jane Smith</td>
                                <td>9876543211</td>
                                <td>18</td>
                                <td>₹90,000</td>
                                <td>Active</td>
                            </tr>
                            <tr>
                                <td>Mike Johnson</td>
                                <td>9876543212</td>
                                <td>32</td>
                                <td>₹160,000</td>
                                <td>Active</td>
                            </tr>
                            <tr>
                                <td>Sample Data</td>
                                <td>0000000000</td>
                                <td>...</td>
                                <td>...</td>
                                <td>Preview</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Analysis Section -->
            <?php if (in_array('analysis', $config['sections'] ?? [])): ?>
            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-brain"></i> Advanced Analysis</h2>
                
                <div class="analysis-box">
                    <h3><i class="fas fa-chart-line"></i> Growth Trend Analysis</h3>
                    <p>
                        The collection trend shows a steady growth of 12% month-over-month, with peak
                        activity during festival seasons. Member engagement remains high with 85% active
                        participation rate.
                    </p>
                </div>
                
                <div class="analysis-box">
                    <h3><i class="fas fa-balance-scale"></i> Collection vs Distribution</h3>
                    <p>
                        Healthy collection-to-distribution ratio of 1.8:1 indicates strong financial
                        management. Distribution efficiency has improved by 15% compared to the previous
                        quarter.
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Recommendations Section -->
            <?php if (in_array('recommendations', $config['sections'] ?? [])): ?>
            <div class="content-section">
                <h2 class="section-title"><i class="fas fa-lightbulb"></i> Key Recommendations</h2>
                
                <div class="analysis-box">
                    <h3><i class="fas fa-check-circle"></i> Action Items</h3>
                    <p>
                        • Focus on re-engaging inactive members (15% of total)<br>
                        • Optimize collection timing based on peak hours analysis<br>
                        • Implement automated reminders for pending payments<br>
                        • Consider introducing digital payment options
                    </p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div class="report-footer">
                <p>
                    <strong>SR Colony Chitti Management System</strong><br>
                    Report generated on <?php echo date('F d, Y \a\t h:i A'); ?><br>
                    Template: <?php echo htmlspecialchars($template['TemplateName']); ?> | 
                    Page 1 of <?php echo count($config['sections'] ?? []) + 1; ?>
                </p>
            </div>
        </div>
    </div>
    
    <div class="action-bar">
        <button class="btn btn-secondary" onclick="window.close()">
            <i class="fas fa-times"></i> Close
        </button>
        <button class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print"></i> Print Preview
        </button>
    </div>
</body>
</html>
