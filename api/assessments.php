<?php
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
header('Content-Type: application/json');
$db = getDB();
$payerId = (int)($_GET['payer_id'] ?? 0);
if (!$payerId) { echo json_encode([]); exit; }

$rows = $db->prepare("SELECT ra.id, ra.assessment_number, rs.name AS source_name, ra.total_due, ra.amount_paid, ra.balance, ra.financial_year
    FROM revenue_assessments ra JOIN revenue_sources rs ON ra.revenue_source_id=rs.id
    WHERE ra.payer_id=? AND ra.status IN ('active','partial','overdue') AND ra.balance > 0
    ORDER BY ra.due_date ASC LIMIT 50");
$rows->execute([$payerId]);
echo json_encode($rows->fetchAll());
