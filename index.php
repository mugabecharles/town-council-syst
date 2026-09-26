<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}
header('Location: ' . APP_URL . '/modules/auth/login.php');
exit;
