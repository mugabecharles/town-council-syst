<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
header('Content-Type: application/json');
$db     = getDB();
$action = $_GET['action'] ?? '';

if ($action === 'parishes') {
    $wardId = (int)($_GET['ward_id'] ?? 0);
    $rows = $db->prepare("SELECT id, name FROM parishes WHERE ward_id=? AND is_active=1 ORDER BY name");
    $rows->execute([$wardId]);
    echo json_encode($rows->fetchAll());
} elseif ($action === 'villages') {
    $parishId = (int)($_GET['parish_id'] ?? 0);
    $rows = $db->prepare("SELECT id, name FROM villages WHERE parish_id=? AND is_active=1 ORDER BY name");
    $rows->execute([$parishId]);
    echo json_encode($rows->fetchAll());
} else {
    echo json_encode([]);
}
