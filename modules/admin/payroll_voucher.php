<?php
/**
 * TCMS Payroll Auto-Voucher
 * Generates a payment voucher from an approved payroll run.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
if (!hasRole(['admin','finance_officer'])) {
    setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit;
}
verifyCsrf();
$db    = getDB();
$user  = getCurrentUser();
$runId = (int)($_POST['run_id'] ?? 0);
if (!$runId) { setFlash('danger','Invalid payroll run.'); header('Location: '.APP_URL.'/modules/admin/payroll.php?tab=runs'); exit; }

$run = $db->prepare("SELECT * FROM payroll_runs WHERE id=? AND status='approved'");
$run->execute([$runId]); $run = $run->fetch();
if (!$run) {
    setFlash('danger','Payroll run not found or not yet approved.');
    header('Location: '.APP_URL.'/modules/admin/payroll.php?tab=runs'); exit;
}
if ($run['voucher_id']) {
    setFlash('warning','A voucher already exists for this payroll run.');
    header('Location: '.APP_URL.'/modules/expenditure/voucher_view.php?id='.$run['voucher_id']); exit;
}

$fy      = $run['financial_year'];
$month   = date('F Y', strtotime($run['pay_month'].'-01'));
$vnum    = generateVoucherNumber();

// Find payroll budget line if it exists
$budget = $db->prepare("SELECT id FROM budgets WHERE financial_year=? AND budget_category LIKE '%alary%' AND status IN ('approved','active') ORDER BY id LIMIT 1");
$budget->execute([$fy]); $budgetRow = $budget->fetch();
$budgetId = $budgetRow['id'] ?? null;

// Find local revenue funding source
$fsStmt = $db->prepare("SELECT id FROM funding_sources WHERE source_type='local_revenue' LIMIT 1");
$fsStmt->execute(); $fsRow = $fsStmt->fetch();
$fundId = $fsRow['id'] ?? null;

$deptName = $run['department_id']
    ? $db->prepare("SELECT name FROM departments WHERE id=?")->execute([$run['department_id']]) ? $db->query("SELECT name FROM departments WHERE id={$run['department_id']}")->fetchColumn() : 'All Departments'
    : 'All Departments';

$desc = "Monthly Payroll — $month | $deptName | {$run['run_number']} | Staff: ".
        (int)$db->query("SELECT COUNT(*) FROM payroll_items WHERE run_id=$runId AND status='active'")->fetchColumn().
        " | PAYE: UGX ".number_format($run['total_paye'])." | NSSF (Empl): UGX ".number_format($run['total_nssf_employee']);

$db->prepare("INSERT INTO payment_vouchers
    (voucher_number,financial_year,department_id,budget_id,funding_source_id,
     payee_name,description,amount,account_code,status,prepared_by,prepared_date)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
   ->execute([
       $vnum, $fy, $run['department_id'] ?? 6,
       $budgetId, $fundId,
       'Staff Payroll — ' . $month,
       $desc,
       $run['total_net'],
       'PAYROLL',
       'draft',
       $user['id'], date('Y-m-d')
   ]);
$vid = (int)$db->lastInsertId();

// Link voucher to payroll run
$db->prepare("UPDATE payroll_runs SET voucher_id=?, status='paid' WHERE id=?")->execute([$vid, $runId]);

logAudit('CREATE','payroll','payroll_voucher',$vid,$vnum);
setFlash('success',"Payment voucher <strong>$vnum</strong> created for payroll run {$run['run_number']}. Net amount: UGX ".number_format($run['total_net']));
header('Location: '.APP_URL.'/modules/expenditure/voucher_view.php?id='.$vid); exit;
