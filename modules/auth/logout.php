<?php
require_once __DIR__ . '/../../includes/functions.php';
startSecureSession();
$user = getCurrentUser();
if ($user) {
    $db = getDB();
    $db->prepare("INSERT INTO login_logs (user_id, username, action, ip_address) VALUES (?,?,?,?)")
       ->execute([$user['id'], $user['username'], 'logout', getClientIP()]);
    logAudit('LOGOUT', 'auth', 'user', $user['id'], $user['username']);
}
session_unset();
session_destroy();
header('Location: ' . APP_URL . '/modules/auth/login.php?msg=logged_out');
exit;
