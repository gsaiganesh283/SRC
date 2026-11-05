<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['superadmin_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

// Save template selection to session
if (isset($_POST['template_id'])) {
    $_SESSION['selected_template_id'] = intval($_POST['template_id']);
    echo json_encode(['success' => true, 'template_id' => $_SESSION['selected_template_id']]);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Template ID not provided']);
}
