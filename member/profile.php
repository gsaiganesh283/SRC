<?php
require_once '../config/config.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

requireMemberLogin();

// Redirect to view profile page
header('Location: view-profile.php');
exit();
?>