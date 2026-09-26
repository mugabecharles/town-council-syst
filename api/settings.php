<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (!hasRole(['admin','town_clerk'])) { setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/modules/admin/settings.php'); exit; }
$db       = getDB();
$action   = $_POST['action'] ?? '';
$redirect = $_POST['redirect'] ?? APP_URL.'/modules/admin/settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: '.$redirect); exit; }
verifyCsrf();

if ($action === 'add_ward') {
    $code = strtoupper(trim($_POST['ward_code'] ?? ''));
    $name = trim($_POST['ward_name'] ?? '');
    if ($code && $name) {
        try {
            $db->prepare("INSERT INTO wards (ward_code, name) VALUES (?,?)")->execute([$code, $name]);
            setFlash('success','Ward added: '.$name);
        } catch (PDOException $e) {
            setFlash('danger','Failed: '.($e->getCode()==23000?'Ward code already exists.':$e->getMessage()));
        }
    }
} elseif ($action === 'add_revenue_source') {
    $code = strtoupper(trim($_POST['source_code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    if ($code && $name) {
        try {
            $db->prepare("INSERT INTO revenue_sources (source_code, name, category, payment_frequency) VALUES (?,?,?,?)")
               ->execute([$code, $name, trim($_POST['category'] ?? ''), $_POST['payment_frequency'] ?? 'annually']);
            setFlash('success','Revenue source added: '.$name);
        } catch (PDOException $e) {
            setFlash('danger','Failed: '.($e->getCode()==23000?'Code already exists.':$e->getMessage()));
        }
    }
}

header('Location: '.$redirect); exit;
