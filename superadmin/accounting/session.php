<?php
/**
 * Accounting Portal Session Handler
 * Extends superadmin session for accounting portal access
 */

// Include the parent superadmin session
require_once __DIR__ . '/../session.php';

// Verify superadmin is logged in
if (!isset($_SESSION['superadmin_id'])) {
    header('Location: ../login.php');
    exit;
}

// Set accounting portal flag
$_SESSION['accounting_portal'] = true;
