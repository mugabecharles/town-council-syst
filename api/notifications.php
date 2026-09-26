<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
header('Content-Type: application/json');
$db     = getDB();
$user   = getCurrentUser();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'mark_read') {
    $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$user['id']]);
    echo json_encode(['success' => true]);
} elseif ($action === 'get') {
    $notifs = getRecentNotifications(10);
    echo json_encode($notifs);
} else {
    echo json_encode([]);
}
