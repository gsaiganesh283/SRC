<?php
// Test Template Loading
require_once '../config/config.php';
require_once '../includes/session.php';

if (!isset($_SESSION['member_id'])) {
    die('Not logged in');
}

$templateID = isset($_GET['template_id']) ? intval($_GET['template_id']) : null;

echo "<h2>Template Debug Test</h2>";
echo "<p>Template ID from GET: " . ($templateID ?? 'NONE') . "</p>";

if ($templateID) {
    $stmt = $pdo->prepare("SELECT TemplateID, TemplateName, TemplateConfig FROM report_templates WHERE TemplateID = ? AND IsActive = 1");
    $stmt->execute([$templateID]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($template) {
        echo "<h3>Template Found: " . htmlspecialchars($template['TemplateName']) . "</h3>";
        $config = json_decode($template['TemplateConfig'], true);
        
        if (isset($config['colors'])) {
            echo "<h4>Colors:</h4>";
            echo "<p>Primary: " . $config['colors']['primary'] . "</p>";
            echo "<p>Accent: " . $config['colors']['accent'] . "</p>";
            
            // Convert to RGB
            function hexToRgb($hex) {
                $hex = ltrim($hex, '#');
                return [
                    hexdec(substr($hex, 0, 2)),
                    hexdec(substr($hex, 2, 2)),
                    hexdec(substr($hex, 4, 2))
                ];
            }
            
            $primaryRgb = hexToRgb($config['colors']['primary']);
            $accentRgb = hexToRgb($config['colors']['accent']);
            
            echo "<p>Primary RGB: " . implode(', ', $primaryRgb) . "</p>";
            echo "<p>Accent RGB: " . implode(', ', $accentRgb) . "</p>";
            
            echo "<div style='width: 100px; height: 50px; background-color: " . $config['colors']['primary'] . ";'></div>";
            echo "<div style='width: 100px; height: 50px; background-color: " . $config['colors']['accent'] . "; margin-top: 10px;'></div>";
        }
        
        echo "<h4>Full Config:</h4>";
        echo "<pre>" . print_r($config, true) . "</pre>";
    } else {
        echo "<p style='color: red;'>Template not found!</p>";
    }
} else {
    echo "<p>No template ID provided. Add ?template_id=3 to test.</p>";
    
    // Show all templates
    $stmt = $pdo->query("SELECT TemplateID, TemplateName, IsActive FROM report_templates");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Available Templates:</h3>";
    echo "<ul>";
    foreach ($templates as $t) {
        echo "<li><a href='?template_id=" . $t['TemplateID'] . "'>" . htmlspecialchars($t['TemplateName']) . "</a> (ID: " . $t['TemplateID'] . ", Active: " . ($t['IsActive'] ? 'Yes' : 'No') . ")</li>";
    }
    echo "</ul>";
}
?>
