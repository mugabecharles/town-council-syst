<?php
/**
 * TCMS Payroll Module
 * Staff management, salary setup, monthly payroll run, PAYE/NSSF/LST, payslips.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();

if (!hasRole(['admin','town_clerk','finance_officer'])) {
    setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit;
}

$user = getCurrentUser();
$db   = getDB();
$fy   = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

// ══════════════════════════════════════════════════════════════════
// PAYE CALCULATION (Uganda bands 2026)
// ══════════════════════════════════════════════════════════════════
function calcPAYE(float $grossMonthly): float {
    // Annual gross
    $annual = $grossMonthly * 12;
    $paye   = 0;
    if ($annual <= 2820000)      { $paye = 0; }
    elseif ($annual <= 4020000)  { $paye = ($annual - 2820000) * 0.10; }
    elseif ($annual <= 4920000)  { $paye = 120000 + ($annual - 4020000) * 0.20; }
    elseif ($annual <= 120000000){ $paye = 300000 + ($annual - 4920000) * 0.30; }
    else                         { $paye = 34974000 + ($annual - 120000000) * 0.40; }
    return round($paye / 12, 0); // Monthly PAYE
}

function calcNSSF(float $basicMonthly, float $rate): float {
    return round($basicMonthly * $rate / 100, 0);
}

function calcLST(float $grossMonthly): float {
    // Annual gross to determine LST band
    $annual = $grossMonthly * 12;
    if ($annual < 3600000)  return 0;
    if ($annual < 9000000)  return 100000 / 12;
    if ($annual < 15000000) return 133333 / 12;
    return 150000 / 12;
}

// ══════════════════════════════════════════════════════════════════
// POST HANDLERS
// ══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Add Staff Member ──────────────────────────────────────────
    if ($action === 'add_staff') {
        $cnt  = (int)$db->query("SELECT COUNT(*)+1 FROM staff")->fetchColumn();
        $snum = 'STF-' . str_pad($cnt, 4, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO staff
            (staff_number,user_id,department_id,full_name,designation,employment_type,
             basic_salary,bank_name,bank_account,nssf_number,tin_number,phone,email,date_joined)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $snum,
               !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null,
               (int)$_POST['department_id'],
               trim($_POST['full_name']),
               trim($_POST['designation'] ?? ''),
               $_POST['employment_type'] ?? 'permanent',
               (float)str_replace(',','', $_POST['basic_salary']),
               trim($_POST['bank_name'] ?? ''),
               trim($_POST['bank_account'] ?? ''),
               trim($_POST['nssf_number'] ?? ''),
               trim($_POST['tin_number'] ?? ''),
               trim($_POST['phone'] ?? ''),
               trim($_POST['email'] ?? ''),
               $_POST['date_joined'] ?: null,
           ]);
        $sid = (int)$db->lastInsertId();
        logAudit('CREATE','payroll','staff',$sid,$snum);
        setFlash('success',"Staff member added: $snum");
        header('Location: payroll.php?tab=staff'); exit;
    }

    // ── Update Staff ──────────────────────────────────────────────
    if ($action === 'update_staff') {
        $sid = (int)$_POST['staff_id'];
        $db->prepare("UPDATE staff SET full_name=?,designation=?,employment_type=?,
            basic_salary=?,bank_name=?,bank_account=?,nssf_number=?,tin_number=?,
            phone=?,email=?,department_id=?,is_active=? WHERE id=?")
           ->execute([
               trim($_POST['full_name']), trim($_POST['designation']??''),
               $_POST['employment_type'],
               (float)str_replace(',','',$_POST['basic_salary']),
               trim($_POST['bank_name']??''), trim($_POST['bank_account']??''),
               trim($_POST['nssf_number']??''), trim($_POST['tin_number']??''),
               trim($_POST['phone']??''), trim($_POST['email']??''),
               (int)$_POST['department_id'],
               isset($_POST['is_active']) ? 1 : 0,
               $sid
           ]);
        logAudit('UPDATE','payroll','staff',$sid,'');
        setFlash('success','Staff record updated.');
        header('Location: payroll.php?tab=staff'); exit;
    }

    // ── Run Payroll ───────────────────────────────────────────────
    if ($action === 'run_payroll') {
        $payMonth = $_POST['pay_month'];     // YYYY-MM
        $deptId   = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $payDate  = $_POST['pay_date'];

        // Check for existing run this month
        $existing = $db->prepare("SELECT id FROM payroll_runs WHERE pay_month=? AND status NOT IN ('cancelled') AND (department_id=? OR (department_id IS NULL AND ? IS NULL))");
        $existing->execute([$payMonth, $deptId, $deptId]);
        if ($existing->fetch()) {
            setFlash('danger',"A payroll run already exists for $payMonth. Cancel it first to create a new one.");
            header('Location: payroll.php?tab=runs'); exit;
        }

        // Generate run number
        $cnt  = (int)$db->query("SELECT COUNT(*)+1 FROM payroll_runs")->fetchColumn();
        $rnum = 'PAY-' . date('Y', strtotime($payMonth.'-01')) . '-' . str_pad($cnt, 4, '0', STR_PAD_LEFT);

        // Fetch active staff
        $where = "s.is_active=1";
        $params = [];
        if ($deptId) { $where .= " AND s.department_id=?"; $params[] = $deptId; }
        $staffList = $db->prepare("SELECT s.*, d.name AS dept_name FROM staff s JOIN departments d ON s.department_id=d.id WHERE $where ORDER BY d.name, s.full_name");
        $staffList->execute($params);
        $staffList = $staffList->fetchAll();

        if (empty($staffList)) {
            setFlash('danger','No active staff found for the selected criteria.');
            header('Location: payroll.php?tab=runs'); exit;
        }

        // Create run record
        $db->prepare("INSERT INTO payroll_runs (run_number,financial_year,pay_month,pay_date,department_id,status,prepared_by) VALUES (?,?,?,?,?,'draft',?)")
           ->execute([$rnum, $fy, $payMonth, $payDate, $deptId, $user['id']]);
        $runId = (int)$db->lastInsertId();

        $totGross=0; $totNet=0; $totDed=0; $totPAYE=0; $totNSSFEmp=0; $totNSSFEmr=0; $totLST=0;

        // Calculate each staff member
        foreach ($staffList as $s) {
            $basic = (float)$s['basic_salary'];

            // Load allowances for this staff
            $comps = $db->prepare("SELECT sc.name, sc.component_type, sc.calculation_method, ssc.value
                FROM staff_salary_components ssc
                JOIN salary_components sc ON ssc.component_id=sc.id
                WHERE ssc.staff_id=? AND sc.is_active=1
                  AND (ssc.effective_to IS NULL OR ssc.effective_to >= CURDATE())");
            $comps->execute([$s['id']]);
            $comps = $comps->fetchAll();

            $allowances = []; $otherDeds = [];
            $totalAllowances = 0; $otherDeductions = 0;

            foreach ($comps as $c) {
                $val = (float)$c['value'];
                if ($c['calculation_method'] === 'percent_basic') $val = $basic * $val / 100;

                if ($c['component_type'] === 'allowance') {
                    $allowances[$c['name']] = round($val, 0);
                    $totalAllowances += $val;
                } elseif ($c['component_type'] === 'deduction') {
                    $otherDeds[$c['name']] = round($val, 0);
                    $otherDeductions += $val;
                }
            }

            $gross        = $basic + $totalAllowances;
            $paye         = calcPAYE($gross);
            $nssf_emp     = calcNSSF($basic, 5);
            $nssf_emr     = calcNSSF($basic, 10);
            $lst          = calcLST($gross);
            $totalDeds    = $paye + $nssf_emp + $lst + $otherDeductions;
            $net          = $gross - $totalDeds;

            $db->prepare("INSERT INTO payroll_items
                (run_id,staff_id,basic_salary,gross_salary,paye,nssf_employee,nssf_employer,
                 lst,other_deductions,total_deductions,net_salary,allowances_json,deductions_json,
                 bank_name,bank_account)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $runId, $s['id'], $basic, $gross,
                   $paye, $nssf_emp, $nssf_emr, $lst, $otherDeductions, $totalDeds, $net,
                   $allowances ? json_encode($allowances) : null,
                   $otherDeds  ? json_encode($otherDeds)  : null,
                   $s['bank_name'], $s['bank_account']
               ]);

            $totGross+=$gross; $totNet+=$net; $totDed+=$totalDeds;
            $totPAYE+=$paye; $totNSSFEmp+=$nssf_emp; $totNSSFEmr+=$nssf_emr; $totLST+=$lst;
        }

        // Update run totals
        $db->prepare("UPDATE payroll_runs SET
            total_gross=?,total_deductions=?,total_net=?,
            total_paye=?,total_nssf_employee=?,total_nssf_employer=?,total_lst=?,
            status='computed' WHERE id=?")
           ->execute([$totGross,$totDed,$totNet,$totPAYE,$totNSSFEmp,$totNSSFEmr,$totLST,$runId]);

        logAudit('CREATE','payroll','payroll_run',$runId,$rnum);
        setFlash('success',"Payroll run <strong>$rnum</strong> computed for ".count($staffList)." staff. Net payable: UGX ".number_format($totNet));
        header('Location: payroll.php?tab=runs&view='.$runId); exit;
    }

    // ── Approve Payroll ───────────────────────────────────────────
    if ($action === 'approve_payroll' && hasRole(['admin','town_clerk'])) {
        $runId = (int)$_POST['run_id'];
        $db->prepare("UPDATE payroll_runs SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND status='computed'")
           ->execute([$user['id'], $runId]);
        logAudit('APPROVE','payroll','payroll_run',$runId,'');
        setFlash('success','Payroll approved. You may now generate the payment voucher.');
        header('Location: payroll.php?tab=runs&view='.$runId); exit;
    }

    // ── Cancel Payroll ────────────────────────────────────────────
    if ($action === 'cancel_payroll' && hasRole(['admin','finance_officer'])) {
        $runId = (int)$_POST['run_id'];
        $db->prepare("UPDATE payroll_runs SET status='cancelled' WHERE id=? AND status IN ('draft','computed')")->execute([$runId]);
        setFlash('warning','Payroll run cancelled.');
        header('Location: payroll.php?tab=runs'); exit;
    }
}

// ── View single payroll run ────────────────────────────────────────
$viewRun = (int)($_GET['view'] ?? 0);
$tab     = $_GET['tab'] ?? 'staff';
$deptFil = (int)($_GET['dept_id'] ?? 0);

// ── Supporting data ────────────────────────────────────────────────
$depts    = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$allUsers = $db->query("SELECT id,full_name,username FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();
$fyears   = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

// Staff list
$where = ['1=1']; $params = [];
if ($deptFil) { $where[] = 's.department_id=?'; $params[] = $deptFil; }
$staffRows = $db->prepare("SELECT s.*, d.name AS dept_name FROM staff s JOIN departments d ON s.department_id=d.id WHERE ".implode(' AND ',$where)." ORDER BY d.name,s.full_name");
$staffRows->execute($params); $staffList = $staffRows->fetchAll();

// Payroll runs
$runs = $db->query("SELECT r.*, d.name AS dept_name, u.full_name AS preparer FROM payroll_runs r LEFT JOIN departments d ON r.department_id=d.id JOIN users u ON r.prepared_by=u.id ORDER BY r.created_at DESC LIMIT 50")->fetchAll();

// Summary stats
$totalStaff     = (int)$db->query("SELECT COUNT(*) FROM staff WHERE is_active=1")->fetchColumn();
$totalSalaryBill= (float)$db->query("SELECT COALESCE(SUM(basic_salary),0) FROM staff WHERE is_active=1")->fetchColumn();

renderHead('Payroll Management');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Payroll Management','Staff salaries, deductions and payslips');
renderPageStart('Payroll Management','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Payroll'],
]);
renderPageActions('');
renderFlashMessages();

// ── If viewing a specific run ──────────────────────────────────────
if ($viewRun) {
    $run = $db->prepare("SELECT r.*,d.name AS dept_name,u.full_name AS preparer,a.full_name AS approver_name
        FROM payroll_runs r LEFT JOIN departments d ON r.department_id=d.id
        JOIN users u ON r.prepared_by=u.id
        LEFT JOIN users a ON r.approved_by=a.id
        WHERE r.id=?");
    $run->execute([$viewRun]); $run = $run->fetch();

    if (!$run) { setFlash('danger','Payroll run not found.'); header('Location: payroll.php?tab=runs'); exit; }

    $items = $db->prepare("SELECT pi.*,s.full_name,s.staff_number,s.designation,s.nssf_number,d.name AS dept_name
        FROM payroll_items pi JOIN staff s ON pi.staff_id=s.id JOIN departments d ON s.department_id=d.id
        WHERE pi.run_id=? AND pi.status='active' ORDER BY d.name,s.full_name");
    $items->execute([$viewRun]); $items = $items->fetchAll();
    ?>

<div style="display:flex;gap:.8rem;margin-bottom:1.2rem;flex-wrap:wrap;align-items:center;">
  <a href="payroll.php?tab=runs" class="btn btn-outline-secondary">← Back to Runs</a>
  <button class="btn btn-outline-secondary no-print" data-print>🖨 Print Payroll</button>
  <?php if ($run['status']==='computed' && hasRole(['admin','town_clerk'])): ?>
  <form method="POST" style="display:inline;">
    <?= csrfField() ?><input type="hidden" name="action" value="approve_payroll"><input type="hidden" name="run_id" value="<?= $run['id'] ?>">
    <button type="submit" class="btn btn-success" data-confirm="Approve this payroll run?">✓ Approve Payroll</button>
  </form>
  <?php endif; ?>
  <?php if ($run['status']==='approved' && !$run['voucher_id'] && hasRole(['admin','finance_officer'])): ?>
  <form method="POST" action="<?= APP_URL ?>/modules/admin/payroll_voucher.php">
    <?= csrfField() ?><input type="hidden" name="run_id" value="<?= $run['id'] ?>">
    <button type="submit" class="btn btn-primary">▤ Generate Payment Voucher</button>
  </form>
  <?php endif; ?>
  <?php if ($run['voucher_id']): ?>
  <a href="<?= APP_URL ?>/modules/expenditure/voucher_view.php?id=<?= $run['voucher_id'] ?>" class="btn btn-outline-primary">View Voucher</a>
  <?php endif; ?>
</div>

<!-- Run Header -->
<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-header">
    <h5><span class="ch-icon">◎</span> Payroll Run: <?= htmlspecialchars($run['run_number']) ?></h5>
    <?= getStatusBadge($run['status']) ?>
  </div>
  <div class="card-body">
    <div class="grid-4" style="margin-bottom:1rem;">
      <div><div class="fs-xs text-muted">Pay Month</div><div class="fw-700"><?= date('F Y', strtotime($run['pay_month'].'-01')) ?></div></div>
      <div><div class="fs-xs text-muted">Department</div><div class="fw-700"><?= htmlspecialchars($run['dept_name'] ?? 'All Departments') ?></div></div>
      <div><div class="fs-xs text-muted">Pay Date</div><div class="fw-700"><?= $run['pay_date'] ? formatDate($run['pay_date']) : '—' ?></div></div>
      <div><div class="fs-xs text-muted">Prepared By</div><div class="fw-700"><?= htmlspecialchars($run['preparer']) ?></div></div>
    </div>
    <div class="grid-5">
      <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:8px;">
        <div class="fs-xs text-muted">GROSS PAYROLL</div>
        <div class="fw-800 text-primary"><?= number_format($run['total_gross']) ?></div>
      </div>
      <div style="text-align:center;padding:.8rem;background:#fff3cd;border-radius:8px;">
        <div class="fs-xs text-muted">TOTAL PAYE</div>
        <div class="fw-800 text-warning"><?= number_format($run['total_paye']) ?></div>
      </div>
      <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:8px;">
        <div class="fs-xs text-muted">NSSF (EMP)</div>
        <div class="fw-800"><?= number_format($run['total_nssf_employee']) ?></div>
      </div>
      <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:8px;">
        <div class="fs-xs text-muted">TOTAL DEDUCTIONS</div>
        <div class="fw-800 text-danger"><?= number_format($run['total_deductions']) ?></div>
      </div>
      <div style="text-align:center;padding:.8rem;background:#d4edda;border-radius:8px;">
        <div class="fs-xs text-muted">NET PAYABLE</div>
        <div class="fw-800 text-success" style="font-size:1.1rem;">UGX <?= number_format($run['total_net']) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Payroll Items Table -->
<div class="card">
  <div class="card-header"><h5><span class="ch-icon">◉</span> Payroll Schedule — <?= count($items) ?> Staff</h5></div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead>
          <tr>
            <th>#</th><th>Staff No.</th><th>Name</th><th>Designation</th><th>Dept</th>
            <th class="text-right">Basic</th><th class="text-right">Gross</th>
            <th class="text-right">PAYE</th><th class="text-right">NSSF</th>
            <th class="text-right">LST</th><th class="text-right">Total Ded.</th>
            <th class="text-right">Net Pay</th>
            <th class="no-print">Payslip</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $i => $item): ?>
        <tr>
          <td class="fs-xs text-muted"><?= $i+1 ?></td>
          <td class="fw-700 text-primary fs-sm"><?= htmlspecialchars($item['staff_number']) ?></td>
          <td>
            <div class="fw-600"><?= htmlspecialchars($item['full_name']) ?></div>
            <div class="fs-xs text-muted"><?= htmlspecialchars($item['designation']??'') ?></div>
          </td>
          <td class="fs-sm"><?= htmlspecialchars($item['designation']??'—') ?></td>
          <td class="fs-sm"><?= htmlspecialchars($item['dept_name']) ?></td>
          <td class="text-right"><?= number_format($item['basic_salary']) ?></td>
          <td class="text-right fw-600"><?= number_format($item['gross_salary']) ?></td>
          <td class="text-right text-warning"><?= number_format($item['paye']) ?></td>
          <td class="text-right"><?= number_format($item['nssf_employee']) ?></td>
          <td class="text-right"><?= number_format($item['lst']) ?></td>
          <td class="text-right text-danger"><?= number_format($item['total_deductions']) ?></td>
          <td class="text-right fw-700 text-success"><?= number_format($item['net_salary']) ?></td>
          <td class="no-print"><a href="?view=<?= $viewRun ?>&payslip=<?= $item['staff_id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">Payslip</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="5" class="text-right">TOTALS:</th>
            <th class="text-right"><?= number_format(array_sum(array_column($items,'basic_salary'))) ?></th>
            <th class="text-right"><?= number_format(array_sum(array_column($items,'gross_salary'))) ?></th>
            <th class="text-right"><?= number_format(array_sum(array_column($items,'paye'))) ?></th>
            <th class="text-right"><?= number_format(array_sum(array_column($items,'nssf_employee'))) ?></th>
            <th class="text-right"><?= number_format(array_sum(array_column($items,'lst'))) ?></th>
            <th class="text-right"><?= number_format(array_sum(array_column($items,'total_deductions'))) ?></th>
            <th class="text-right fw-700"><?= number_format(array_sum(array_column($items,'net_salary'))) ?></th>
            <th class="no-print"></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

    <?php
    renderPageEnd(); echo '</div></div>'; renderFooter(); exit;
}

// ── If printing a payslip ─────────────────────────────────────────
if (isset($_GET['view'], $_GET['payslip'])) {
    $runId   = (int)$_GET['view'];
    $staffId = (int)$_GET['payslip'];
    $run     = $db->prepare("SELECT * FROM payroll_runs WHERE id=?"); $run->execute([$runId]); $run=$run->fetch();
    $item    = $db->prepare("SELECT pi.*,s.full_name,s.staff_number,s.designation,s.nssf_number,s.tin_number,s.bank_name,s.bank_account,d.name AS dept_name FROM payroll_items pi JOIN staff s ON pi.staff_id=s.id JOIN departments d ON s.department_id=d.id WHERE pi.run_id=? AND pi.staff_id=? AND pi.status='active'");
    $item->execute([$runId,$staffId]); $item=$item->fetch();
    if (!$item || !$run) { die('Payslip not found.'); }
    $council = getSystemSetting('council_name') ?? 'Kijura Town Council';
    $allowances  = $item['allowances_json'] ? json_decode($item['allowances_json'],true) : [];
    $deductions  = $item['deductions_json']  ? json_decode($item['deductions_json'],true)  : [];
    ?>
<!DOCTYPE html><html><head><meta charset="UTF-8">
<title>Payslip — <?= htmlspecialchars($item['full_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Inter',sans-serif;background:#f4f6f9;padding:20px;}
.slip{max-width:700px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,.12);}
.slip-header{background:#1a3a5c;padding:20px 28px;border-bottom:4px solid #c8a84b;display:flex;align-items:center;gap:16px;}
.slip-logo{width:48px;height:48px;background:#c8a84b;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:900;color:#1a3a5c;}
.slip-header h2{color:#fff;font-size:16px;font-weight:800;}
.slip-header p{color:rgba(255,255,255,.65);font-size:11px;}
.slip-title{background:#f8f9fb;padding:12px 28px;text-align:center;font-size:13px;font-weight:700;color:#1a3a5c;text-transform:uppercase;letter-spacing:2px;border-bottom:1px solid #dee2e6;}
.slip-body{padding:20px 28px;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #dee2e6;border-radius:6px;overflow:hidden;margin-bottom:16px;font-size:12px;}
.info-row{display:flex;border-bottom:1px solid #dee2e6;} .info-row:last-child{border-bottom:none;}
.info-row .lbl{padding:7px 12px;background:#f8f9fa;font-weight:600;color:#6c757d;width:45%;flex-shrink:0;}
.info-row .val{padding:7px 12px;font-weight:600;}
.pay-table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:12px;}
.pay-table th{background:#1a3a5c;color:#fff;padding:7px 10px;text-align:left;}
.pay-table th.r,.pay-table td.r{text-align:right;}
.pay-table td{padding:6px 10px;border-bottom:1px solid #f0f0f0;}
.pay-table tr:nth-child(even) td{background:#f9f9f9;}
.totals{background:#1a3a5c;color:#fff;padding:14px 20px;border-radius:6px;display:flex;justify-content:space-between;margin:12px 0;}
.totals .lbl{font-size:11px;opacity:.8;}
.totals .val{font-size:18px;font-weight:900;}
.sig-row{display:flex;justify-content:space-between;margin-top:28px;padding-top:16px;border-top:1px solid #dee2e6;}
.sig-block{text-align:center;width:45%;}
.sig-line{border-top:1px solid #333;padding-top:5px;font-size:10px;color:#666;margin-top:32px;}
@media print{body{background:#fff;padding:0;}.slip{box-shadow:none;border-radius:0;} .no-print{display:none!important;}}
</style></head>
<body>
<div class="no-print" style="text-align:center;margin-bottom:12px;">
  <button onclick="window.print()" style="background:#1a3a5c;color:#fff;padding:9px 24px;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;">🖨 Print Payslip</button>
  <a href="javascript:window.close()" style="margin-left:10px;color:#666;font-size:12px;">Close</a>
</div>
<div class="slip">
  <div class="slip-header">
    <div class="slip-logo">TC</div>
    <div><h2><?= htmlspecialchars($council) ?></h2><p>Official Employee Payslip</p></div>
  </div>
  <div class="slip-title">PAYSLIP — <?= date('F Y', strtotime($run['pay_month'].'-01')) ?></div>
  <div class="slip-body">
    <div class="info-grid">
      <div class="info-row"><span class="lbl">Employee Name</span><span class="val"><?= htmlspecialchars($item['full_name']) ?></span></div>
      <div class="info-row"><span class="lbl">Staff Number</span><span class="val"><?= htmlspecialchars($item['staff_number']) ?></span></div>
      <div class="info-row"><span class="lbl">Designation</span><span class="val"><?= htmlspecialchars($item['designation']??'—') ?></span></div>
      <div class="info-row"><span class="lbl">Department</span><span class="val"><?= htmlspecialchars($item['dept_name']) ?></span></div>
      <div class="info-row"><span class="lbl">NSSF No.</span><span class="val"><?= htmlspecialchars($item['nssf_number']??'—') ?></span></div>
      <div class="info-row"><span class="lbl">TIN</span><span class="val"><?= htmlspecialchars($item['tin_number']??'—') ?></span></div>
      <div class="info-row"><span class="lbl">Bank</span><span class="val"><?= htmlspecialchars($item['bank_name']??'—') ?></span></div>
      <div class="info-row"><span class="lbl">Account No.</span><span class="val"><?= htmlspecialchars($item['bank_account']??'—') ?></span></div>
      <div class="info-row"><span class="lbl">Pay Date</span><span class="val"><?= $run['pay_date'] ? formatDate($run['pay_date']) : '—' ?></span></div>
      <div class="info-row"><span class="lbl">Pay Reference</span><span class="val"><?= htmlspecialchars($run['run_number']) ?></span></div>
    </div>

    <table class="pay-table">
      <thead><tr><th>EARNINGS</th><th class="r">Amount (UGX)</th><th>DEDUCTIONS</th><th class="r">Amount (UGX)</th></tr></thead>
      <tbody>
        <tr><td>Basic Salary</td><td class="r"><?= number_format($item['basic_salary']) ?></td><td>PAYE</td><td class="r"><?= number_format($item['paye']) ?></td></tr>
        <?php
        $allowKeys = array_keys($allowances);
        $dedKeys   = array_keys($deductions);
        $maxRows   = max(count($allowKeys), count($dedKeys));
        for ($j=0; $j<$maxRows; $j++):
            $ak = $allowKeys[$j] ?? null;
            $dk = $dedKeys[$j]   ?? null;
        ?>
        <tr>
          <td><?= $ak ? htmlspecialchars($ak) : '' ?></td>
          <td class="r"><?= $ak ? number_format($allowances[$ak]) : '' ?></td>
          <td><?= $dk ? htmlspecialchars($dk) : '' ?></td>
          <td class="r"><?= $dk ? number_format($deductions[$dk]) : '' ?></td>
        </tr>
        <?php endfor; ?>
        <tr><td></td><td></td><td>NSSF (5%)</td><td class="r"><?= number_format($item['nssf_employee']) ?></td></tr>
        <tr><td></td><td></td><td>LST</td><td class="r"><?= number_format($item['lst']) ?></td></tr>
      </tbody>
      <tfoot>
        <tr style="background:#1a3a5c;color:#fff;">
          <td colspan="2" style="padding:8px 10px;font-weight:700;font-size:13px;">TOTAL EARNINGS: UGX <?= number_format($item['gross_salary']) ?></td>
          <td colspan="2" style="padding:8px 10px;font-weight:700;font-size:13px;text-align:right;">TOTAL DED.: UGX <?= number_format($item['total_deductions']) ?></td>
        </tr>
      </tfoot>
    </table>

    <div class="totals">
      <div><div class="lbl">GROSS SALARY</div><div class="val">UGX <?= number_format($item['gross_salary']) ?></div></div>
      <div><div class="lbl">TOTAL DEDUCTIONS</div><div class="val">UGX <?= number_format($item['total_deductions']) ?></div></div>
      <div><div class="lbl" style="color:#c8a84b;">NET PAY</div><div class="val" style="color:#c8a84b;">UGX <?= number_format($item['net_salary']) ?></div></div>
    </div>

    <p style="font-size:11px;color:#aaa;text-align:center;font-style:italic;">Amount in words: <?= numberToWords($item['net_salary']) ?></p>

    <div class="sig-row">
      <div class="sig-block"><div class="sig-line">Employee Signature<br><?= htmlspecialchars($item['full_name']) ?></div></div>
      <div class="sig-block"><div class="sig-line">Authorised by<br><?= htmlspecialchars($council) ?></div></div>
    </div>
    <p style="font-size:10px;color:#bbb;text-align:center;margin-top:16px;">Printed: <?= date('d/m/Y H:i') ?> | <?= htmlspecialchars($council) ?> — Confidential</p>
  </div>
</div>
</body></html>
    <?php
    exit;
}
?>

<!-- ══ MAIN PAGE TABS ══════════════════════════════════════════════ -->
<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card"><div class="stat-icon">◉</div><div class="stat-info"><div class="label">Active Staff</div><div class="value"><?= number_format($totalStaff) ?></div></div></div>
  <div class="stat-card amber"><div class="stat-icon">💰</div><div class="stat-info"><div class="label">Monthly Salary Bill</div><div class="value"><?= number_format($totalSalaryBill/1000000,1)?>M</div><div class="sub">Basic salaries</div></div></div>
  <div class="stat-card blue"><div class="stat-icon">📋</div><div class="stat-info"><div class="label">Payroll Runs</div><div class="value"><?= count($runs) ?></div></div></div>
  <div class="stat-card green"><div class="stat-icon">✓</div><div class="stat-info"><div class="label">Last Run Status</div>
    <div class="value" style="font-size:.9rem;"><?= $runs ? ucfirst($runs[0]['status']) : 'None' ?></div></div></div>
</div>

<div class="tab-group">
  <div class="tab-nav">
    <button class="tab-link <?= $tab==='staff'?'active':'' ?>" data-tab="staffTab">Staff Register</button>
    <button class="tab-link <?= $tab==='runs'?'active':'' ?>"  data-tab="runsTab">Payroll Runs</button>
    <button class="tab-link <?= $tab==='new'?'active':'' ?>"   data-tab="newRunTab">Run Payroll</button>
  </div>
</div>

<!-- ── Staff Register Tab ───────────────────────────────────────── -->
<div id="staffTab" class="tab-panel <?= $tab==='staff'?'active':'' ?>">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <div>
      <form method="GET" style="display:flex;gap:.5rem;">
        <input type="hidden" name="tab" value="staff">
        <select name="dept_id" class="form-select" onchange="this.form.submit()">
          <option value="">All Departments</option>
          <?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>" <?= $deptFil==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
        </select>
      </form>
    </div>
    <button class="btn btn-primary" data-modal="addStaffModal">+ Add Staff Member</button>
  </div>

  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◉</span> Staff Register (<?= count($staffList) ?>)</h5></div>
    <div class="card-body p-0">
      <?php if ($staffList): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>#</th><th>Staff No.</th><th>Name</th><th>Department</th><th>Employment</th><th class="text-right">Basic Salary</th><th>Bank</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($staffList as $i => $s): ?>
          <tr>
            <td class="fs-xs text-muted"><?= $i+1 ?></td>
            <td class="fw-700 text-primary"><?= htmlspecialchars($s['staff_number']) ?></td>
            <td>
              <div class="fw-600"><?= htmlspecialchars($s['full_name']) ?></div>
              <div class="fs-xs text-muted"><?= htmlspecialchars($s['designation']??'') ?></div>
            </td>
            <td class="fs-sm"><?= htmlspecialchars($s['dept_name']) ?></td>
            <td><span class="badge badge-info"><?= ucfirst($s['employment_type']) ?></span></td>
            <td class="text-right fw-700"><?= number_format($s['basic_salary']) ?></td>
            <td class="fs-sm"><?= htmlspecialchars($s['bank_name']??'—') ?></td>
            <td><?= getStatusBadge($s['is_active']?'active':'inactive') ?></td>
            <td><button class="btn btn-sm btn-outline-primary" onclick="editStaff(<?= htmlspecialchars(json_encode($s)) ?>)">Edit</button></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th colspan="5" class="text-right">TOTAL SALARY BILL:</th><th class="text-right"><?= number_format(array_sum(array_column($staffList,'basic_salary'))) ?></th><th colspan="3"></th></tr></tfoot>
        </table>
      </div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">◉</div><h5>No staff found</h5><p>Add staff members using the button above.</p></div><?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Payroll Runs Tab ─────────────────────────────────────────── -->
<div id="runsTab" class="tab-panel <?= $tab==='runs'?'active':'' ?>">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">📋</span> Payroll Runs</h5></div>
    <div class="card-body p-0">
      <?php if ($runs): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Run No.</th><th>Pay Month</th><th>Department</th><th class="text-right">Gross</th><th class="text-right">Deductions</th><th class="text-right">Net Pay</th><th>Pay Date</th><th>Status</th><th>Prepared By</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($runs as $r): ?>
          <tr>
            <td class="fw-700 text-primary"><?= htmlspecialchars($r['run_number']) ?></td>
            <td class="fw-600"><?= date('F Y', strtotime($r['pay_month'].'-01')) ?></td>
            <td class="fs-sm"><?= htmlspecialchars($r['dept_name'] ?? 'All') ?></td>
            <td class="text-right"><?= number_format($r['total_gross']) ?></td>
            <td class="text-right text-danger"><?= number_format($r['total_deductions']) ?></td>
            <td class="text-right fw-700 text-success"><?= number_format($r['total_net']) ?></td>
            <td class="fs-sm"><?= $r['pay_date'] ? formatDate($r['pay_date']) : '—' ?></td>
            <td><?= getStatusBadge($r['status']) ?></td>
            <td class="fs-sm"><?= htmlspecialchars($r['preparer']) ?></td>
            <td>
              <div style="display:flex;gap:.3rem;">
                <a href="?view=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                <?php if (in_array($r['status'],['draft','computed']) && hasRole(['admin','finance_officer'])): ?>
                <form method="POST" style="display:inline;">
                  <?= csrfField() ?><input type="hidden" name="action" value="cancel_payroll"><input type="hidden" name="run_id" value="<?= $r['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Cancel this payroll run?">Cancel</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">📋</div><h5>No payroll runs yet</h5><p>Use the "Run Payroll" tab to process the first payroll.</p></div><?php endif; ?>
    </div>
  </div>
</div>

<!-- ── New Payroll Run Tab ──────────────────────────────────────── -->
<div id="newRunTab" class="tab-panel <?= $tab==='new'?'active':'' ?>">
  <div class="grid-2" style="align-items:start;">
    <div class="card">
      <div class="card-header"><h5><span class="ch-icon">◎</span> Process Monthly Payroll</h5></div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="run_payroll">
          <div class="form-group">
            <label class="form-label">Pay Month <span class="req">*</span></label>
            <input type="month" name="pay_month" class="form-control" required value="<?= date('Y-m') ?>">
            <div class="form-text">Select the month this payroll covers.</div>
          </div>
          <div class="form-group">
            <label class="form-label">Pay Date <span class="req">*</span></label>
            <input type="date" name="pay_date" class="form-control" required value="<?= date('Y-m-28') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Department (leave blank for all departments)</label>
            <select name="department_id" class="form-select">
              <option value="">All Departments</option>
              <?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="alert alert-info"><span>ℹ</span><span>Payroll will be computed for all <strong>active staff</strong> in the selected department. PAYE, NSSF (5% employee + 10% employer), and LST will be calculated automatically.</span></div>
          <button type="submit" class="btn btn-primary btn-block btn-lg" data-confirm="Process payroll for selected month?">Compute Payroll →</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h5><span class="ch-icon">◆</span> Uganda Tax Summary (FY <?= $fy ?>)</h5></div>
      <div class="card-body">
        <div style="font-size:.84rem;line-height:1.9;">
          <div class="fw-700 mb-1" style="color:var(--primary);">PAYE Bands (Monthly Gross)</div>
          <div style="background:#f8f9fa;border-radius:6px;padding:.8rem;font-family:monospace;font-size:.8rem;margin-bottom:1rem;">
            UGX 0 – 235,000 ............. 0%<br>
            UGX 235,001 – 335,000 ...... 10%<br>
            UGX 335,001 – 410,000 ...... 20%<br>
            UGX 410,001 – 10,000,000 ... 30%<br>
            Above UGX 10,000,000 ....... 40%
          </div>
          <div class="fw-700 mb-1" style="color:var(--primary);">NSSF (National Social Security Fund)</div>
          <div style="background:#f8f9fa;border-radius:6px;padding:.8rem;font-size:.82rem;margin-bottom:1rem;">
            Employee contribution: <strong>5% of basic salary</strong><br>
            Employer contribution: <strong>10% of basic salary</strong>
          </div>
          <div class="fw-700 mb-1" style="color:var(--primary);">LST (Local Service Tax)</div>
          <div style="background:#f8f9fa;border-radius:6px;padding:.8rem;font-family:monospace;font-size:.8rem;">
            Below 300,000/yr ........ Exempt<br>
            300,001 – 750,000/yr ... UGX 100,000<br>
            750,001 – 1,250,000/yr . UGX 133,333<br>
            Above 1,250,000/yr ..... UGX 150,000
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add Staff Modal -->
<div class="modal-backdrop" id="addStaffModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Add Staff Member</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_staff">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Full Name <span class="req">*</span></label><input type="text" name="full_name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Designation</label><input type="text" name="designation" class="form-control"></div>
          <div class="form-group"><label class="form-label">Department <span class="req">*</span></label>
            <select name="department_id" class="form-select" required><option value="">—</option><?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?></select>
          </div>
          <div class="form-group"><label class="form-label">Employment Type</label>
            <select name="employment_type" class="form-select">
              <?php foreach (['permanent','contract','casual','intern'] as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Basic Salary (UGX) <span class="req">*</span></label><input type="number" name="basic_salary" class="form-control" required min="0" step="1"></div>
          <div class="form-group"><label class="form-label">Date Joined</label><input type="date" name="date_joined" class="form-control"></div>
          <div class="form-group"><label class="form-label">NSSF Number</label><input type="text" name="nssf_number" class="form-control"></div>
          <div class="form-group"><label class="form-label">TIN Number</label><input type="text" name="tin_number" class="form-control"></div>
          <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
          <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
          <div class="form-group"><label class="form-label">Bank Name</label><input type="text" name="bank_name" class="form-control"></div>
          <div class="form-group"><label class="form-label">Bank Account No.</label><input type="text" name="bank_account" class="form-control"></div>
          <div class="form-group"><label class="form-label">Link to System User (optional)</label>
            <select name="user_id" class="form-select"><option value="">— None —</option><?php foreach ($allUsers as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?></option><?php endforeach; ?></select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Add Staff Member</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Staff Modal -->
<div class="modal-backdrop" id="editStaffModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Edit Staff Member</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="update_staff"><input type="hidden" name="staff_id" id="es_id">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Full Name <span class="req">*</span></label><input type="text" name="full_name" id="es_full_name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Designation</label><input type="text" name="designation" id="es_designation" class="form-control"></div>
          <div class="form-group"><label class="form-label">Department <span class="req">*</span></label>
            <select name="department_id" id="es_dept" class="form-select" required><option value="">—</option><?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?></select>
          </div>
          <div class="form-group"><label class="form-label">Employment Type</label>
            <select name="employment_type" id="es_emp_type" class="form-select">
              <?php foreach (['permanent','contract','casual','intern'] as $t): ?><option value="<?= $t ?>"><?= ucfirst($t) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Basic Salary (UGX) <span class="req">*</span></label><input type="number" name="basic_salary" id="es_salary" class="form-control" required min="0" step="1"></div>
          <div class="form-group"><label class="form-label">NSSF Number</label><input type="text" name="nssf_number" id="es_nssf" class="form-control"></div>
          <div class="form-group"><label class="form-label">TIN Number</label><input type="text" name="tin_number" id="es_tin" class="form-control"></div>
          <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" id="es_phone" class="form-control"></div>
          <div class="form-group"><label class="form-label">Bank Name</label><input type="text" name="bank_name" id="es_bank" class="form-control"></div>
          <div class="form-group"><label class="form-label">Bank Account</label><input type="text" name="bank_account" id="es_account" class="form-control"></div>
          <div class="form-group"><label class="form-label">Active</label>
            <select name="is_active" id="es_active" class="form-select"><option value="1">Active</option><option value="0">Inactive</option></select>
            <input type="hidden" name="is_active" id="es_active_hidden">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; ?>
<script>
function editStaff(s) {
  document.getElementById('es_id').value          = s.id;
  document.getElementById('es_full_name').value   = s.full_name;
  document.getElementById('es_designation').value = s.designation || '';
  document.getElementById('es_dept').value        = s.department_id;
  document.getElementById('es_emp_type').value    = s.employment_type;
  document.getElementById('es_salary').value      = s.basic_salary;
  document.getElementById('es_nssf').value        = s.nssf_number || '';
  document.getElementById('es_tin').value         = s.tin_number || '';
  document.getElementById('es_phone').value       = s.phone || '';
  document.getElementById('es_bank').value        = s.bank_name || '';
  document.getElementById('es_account').value     = s.bank_account || '';
  document.getElementById('es_active').value      = s.is_active;
  openModal('editStaffModal');
}
document.addEventListener('DOMContentLoaded', function() {
  var tabMap = { 'staff':'staffTab', 'runs':'runsTab', 'new':'newRunTab' };
  var p = new URLSearchParams(window.location.search).get('tab');
  if (p && tabMap[p]) {
    document.querySelectorAll('.tab-link').forEach(l => l.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    var btn = document.querySelector('[data-tab="'+tabMap[p]+'"]');
    var panel = document.getElementById(tabMap[p]);
    if (btn) btn.classList.add('active');
    if (panel) panel.classList.add('active');
  }
});
</script>
<?php renderFooter(); ?>
