<?php
/**
 * TCMS KPI Performance Indicators
 * Council-wide KPIs: revenue, expenditure, staff, projects, approvals.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);
$fyears = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

// ── FY date range ──────────────────────────────────────────────────
$fyRow = $db->prepare("SELECT start_date,end_date FROM financial_years WHERE year_code=?");
$fyRow->execute([$fy]); $fyRow=$fyRow->fetch();
$fyStart = $fyRow['start_date'] ?? date('Y-07-01');
$fyEnd   = $fyRow['end_date']   ?? date('Y-06-30',strtotime('+1 year'));
$daysTotal   = max(1,(int)((strtotime($fyEnd)-strtotime($fyStart))/86400));
$daysElapsed = max(1,min($daysTotal,(int)((time()-strtotime($fyStart))/86400)));
$fyPct = round($daysElapsed/$daysTotal*100,1);

// ── Revenue KPIs ────────────────────────────────────────────────────
$revTarget    = (float)$db->prepare("SELECT COALESCE(SUM(target_amount),0) FROM revenue_targets WHERE financial_year=?")->execute([$fy])?$db->query("SELECT COALESCE(SUM(target_amount),0) FROM revenue_targets WHERE financial_year='$fy'")->fetchColumn():0;
$rtStmt=$db->prepare("SELECT COALESCE(SUM(target_amount),0) FROM revenue_targets WHERE financial_year=?");$rtStmt->execute([$fy]);$revTarget=(float)$rtStmt->fetchColumn();
if(!$revTarget)$revTarget=850000000;
$revCollected=(float)$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'")->execute([$fy])?0:0;
$rcStmt=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'");$rcStmt->execute([$fy]);$revCollected=(float)$rcStmt->fetchColumn();
$revArrears=(float)$db->prepare("SELECT COALESCE(SUM(balance),0) FROM revenue_assessments WHERE financial_year=? AND status IN ('active','partial','overdue')")->execute([$fy])?0:0;
$raStmt=$db->prepare("SELECT COALESCE(SUM(balance),0) FROM revenue_assessments WHERE financial_year=? AND status IN ('active','partial','overdue')");$raStmt->execute([$fy]);$revArrears=(float)$raStmt->fetchColumn();
$collectionRate = $revTarget>0?round($revCollected/$revTarget*100,1):0;
$arrearsRate    = ($revCollected+$revArrears)>0?round($revArrears/($revCollected+$revArrears)*100,1):0;
$dailyRate      = $revCollected/$daysElapsed;
$projectedAnnual= $revCollected+($dailyRate*($daysTotal-$daysElapsed));
$perfIndex      = $fyPct>0?round($collectionRate/$fyPct*100,1):0;

// ── Revenue by month (last 12) ──────────────────────────────────────
$revMonthly=$db->prepare("SELECT DATE_FORMAT(payment_date,'%b %Y') lbl,DATE_FORMAT(payment_date,'%Y-%m') ym,SUM(amount) rev FROM revenue_payments WHERE financial_year=? AND status='active' GROUP BY DATE_FORMAT(payment_date,'%Y-%m') ORDER BY ym");
$revMonthly->execute([$fy]);$revMonthly=$revMonthly->fetchAll();
// MoM growth
$momGrowth=null;
if(count($revMonthly)>=2){
    $last=(float)end($revMonthly)['rev'];
    prev($revMonthly);$prev=(float)current($revMonthly)['rev'];
    $momGrowth=$prev>0?round(($last-$prev)/$prev*100,1):null;
}

// ── Ward collection rates ───────────────────────────────────────────
$wardKpi=$db->prepare("SELECT w.name,COALESCE(SUM(rp.amount),0) collected,COALESCE(rt.target_amount,0) target FROM wards w LEFT JOIN revenue_payments rp ON rp.ward_id=w.id AND rp.status='active' AND rp.financial_year=? LEFT JOIN revenue_targets rt ON rt.ward_id=w.id AND rt.financial_year=? GROUP BY w.id ORDER BY collected DESC");
$wardKpi->execute([$fy,$fy]);$wardKpi=$wardKpi->fetchAll();

// ── Expenditure KPIs ────────────────────────────────────────────────
$totalBudget=(float)$db->prepare("SELECT COALESCE(SUM(COALESCE(revised_amount,approved_amount)),0) FROM budgets WHERE financial_year=?")->execute([$fy])?0:0;
$tbStmt=$db->prepare("SELECT COALESCE(SUM(COALESCE(revised_amount,approved_amount)),0) FROM budgets WHERE financial_year=?");$tbStmt->execute([$fy]);$totalBudget=(float)$tbStmt->fetchColumn();
$totalSpent=(float)$db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE financial_year=? AND status IN ('paid','completed')")->execute([$fy])?0:0;
$tsStmt=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE financial_year=? AND status IN ('paid','completed')");$tsStmt->execute([$fy]);$totalSpent=(float)$tsStmt->fetchColumn();
$budgetUtil=$totalBudget>0?round($totalSpent/$totalBudget*100,1):0;

// ── Voucher processing time ─────────────────────────────────────────
$procTime=$db->prepare("SELECT AVG(DATEDIFF(payment_date,prepared_date)) avg_days,MIN(DATEDIFF(payment_date,prepared_date)) min_days,MAX(DATEDIFF(payment_date,prepared_date)) max_days FROM payment_vouchers WHERE financial_year=? AND status IN ('paid','completed') AND payment_date IS NOT NULL AND prepared_date IS NOT NULL");
$procTime->execute([$fy]);$procTime=$procTime->fetch();
$pendingCount=(int)$db->prepare("SELECT COUNT(*) FROM payment_vouchers WHERE status IN ('submitted','hod_approved','tc_approved','finance_verified')")->execute([])?$db->query("SELECT COUNT(*) FROM payment_vouchers WHERE status IN ('submitted','hod_approved','tc_approved','finance_verified')")->fetchColumn():0;
$pcStmt=$db->prepare("SELECT COUNT(*) FROM payment_vouchers WHERE status IN ('submitted','hod_approved','tc_approved','finance_verified')");$pcStmt->execute();$pendingCount=(int)$pcStmt->fetchColumn();

// ── Government funds ────────────────────────────────────────────────
$govtRec=(float)$db->prepare("SELECT COALESCE(SUM(amount_received),0) FROM government_funds WHERE financial_year=?")->execute([$fy])?0:0;
$grStmt=$db->prepare("SELECT COALESCE(SUM(amount_received),0) FROM government_funds WHERE financial_year=?");$grStmt->execute([$fy]);$govtRec=(float)$grStmt->fetchColumn();
$govtUtil=(float)$db->prepare("SELECT COALESCE(SUM(amount_utilized),0) FROM government_funds WHERE financial_year=?")->execute([$fy])?0:0;
$guStmt=$db->prepare("SELECT COALESCE(SUM(amount_utilized),0) FROM government_funds WHERE financial_year=?");$guStmt->execute([$fy]);$govtUtil=(float)$guStmt->fetchColumn();
$govtUtilRate=$govtRec>0?round($govtUtil/$govtRec*100,1):0;

// ── Project KPIs ────────────────────────────────────────────────────
$projStats=$db->query("SELECT status,COUNT(*) cnt,SUM(budget_amount) budget,SUM(amount_spent) spent FROM projects GROUP BY status")->fetchAll();
$projMap=[];foreach($projStats as $p)$projMap[$p['status']]=$p;
$activeProj=(int)($projMap['active']['cnt']??0);
$avgProgress=(float)$db->query("SELECT AVG(progress_percent) FROM projects WHERE status='active'")->fetchColumn();

// ── Staff KPIs ──────────────────────────────────────────────────────
$activeStaff=(int)$db->query("SELECT COUNT(*) FROM staff WHERE is_active=1")->fetchColumn();
$salaryBill=(float)$db->query("SELECT COALESCE(SUM(basic_salary),0) FROM staff WHERE is_active=1")->fetchColumn();
$lastPayroll=$db->query("SELECT run_number,pay_month,total_net,status FROM payroll_runs ORDER BY created_at DESC LIMIT 1")->fetch();

// ── Payer KPIs ──────────────────────────────────────────────────────
$activePayers=(int)$db->query("SELECT COUNT(*) FROM payers WHERE status='active'")->fetchColumn();
$newPayersThisMonth=(int)$db->query("SELECT COUNT(*) FROM payers WHERE MONTH(registration_date)=MONTH(CURDATE()) AND YEAR(registration_date)=YEAR(CURDATE())")->fetchColumn();
$defaulters=(int)$db->prepare("SELECT COUNT(DISTINCT payer_id) FROM revenue_assessments WHERE financial_year=? AND balance>0 AND status IN ('active','partial','overdue')")->execute([$fy])?0:0;
$dfStmt=$db->prepare("SELECT COUNT(DISTINCT payer_id) FROM revenue_assessments WHERE financial_year=? AND balance>0 AND status IN ('active','partial','overdue')");$dfStmt->execute([$fy]);$defaulters=(int)$dfStmt->fetchColumn();
$defaulterRate=$activePayers>0?round($defaulters/$activePayers*100,1):0;

// ── Revenue officer performance ─────────────────────────────────────
$officerPerf=$db->prepare("SELECT u.full_name,u.id,COUNT(rp.id) txns,COALESCE(SUM(rp.amount),0) collected FROM users u JOIN revenue_payments rp ON rp.collected_by=u.id WHERE rp.financial_year=? AND rp.status='active' GROUP BY u.id ORDER BY collected DESC LIMIT 8");
$officerPerf->execute([$fy]);$officerPerf=$officerPerf->fetchAll();
$maxOfficerCollected=$officerPerf?max(array_column($officerPerf,'collected')):0;

// ── Meetings KPIs ──────────────────────────────────────────────────
$meetingsHeld=(int)$db->query("SELECT COUNT(*) FROM council_meetings WHERE status='completed'")->fetchColumn();
$resolutionsPending=(int)$db->query("SELECT COUNT(*) FROM council_resolutions WHERE status='pending'")->fetchColumn();
$resolutionsImplemented=(int)$db->query("SELECT COUNT(*) FROM council_resolutions WHERE status='implemented'")->fetchColumn();
$totalRes=(int)$db->query("SELECT COUNT(*) FROM council_resolutions")->fetchColumn();
$resImplementRate=$totalRes>0?round($resolutionsImplemented/$totalRes*100,1):0;

// Chart data
$mLabels=json_encode(array_column($revMonthly,'lbl'));
$mValues=json_encode(array_column($revMonthly,'rev'));
$wLabels=json_encode(array_column($wardKpi,'name'));
$wColl  =json_encode(array_column($wardKpi,'collected'));
$wTgt   =json_encode(array_column($wardKpi,'target'));
$dLabels=json_encode(array_map(fn($d)=>$d['dept_name']??'',
    $db->prepare("SELECT d.name dept_name,COALESCE(SUM(pv.amount),0) spent FROM departments d LEFT JOIN payment_vouchers pv ON pv.department_id=d.id AND pv.financial_year='$fy' AND pv.status IN ('paid','completed') GROUP BY d.id ORDER BY spent DESC LIMIT 8")->execute()||true?$db->query("SELECT d.name dept_name,COALESCE(SUM(pv.amount),0) spent FROM departments d LEFT JOIN payment_vouchers pv ON pv.department_id=d.id AND pv.financial_year='$fy' AND pv.status IN ('paid','completed') GROUP BY d.id ORDER BY spent DESC LIMIT 8")->fetchAll():[]));
$dSpent=json_encode(array_column(
    $db->query("SELECT d.name,COALESCE(SUM(pv.amount),0) spent FROM departments d LEFT JOIN payment_vouchers pv ON pv.department_id=d.id AND pv.financial_year='$fy' AND pv.status IN ('paid','completed') GROUP BY d.id ORDER BY spent DESC LIMIT 8")->fetchAll(),'spent'));

renderHead('KPI Dashboard');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('KPI Performance Dashboard','Key performance indicators — FY '.$fy);
renderPageStart('KPI Performance Dashboard','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'KPI Dashboard'],
]);
$fyOpts='';foreach($fyears as $y)$fyOpts.="<option value='$y'".($y===$fy?' selected':'').">$y</option>";
renderPageActions("
  <form method='GET' style='display:flex;gap:.5rem;align-items:center;'>
    <label class='fs-sm fw-600'>FY:</label>
    <select name='fy' class='form-select' onchange='this.form.submit()' style='width:130px;'>$fyOpts</select>
  </form>
  <button class='btn btn-outline-secondary no-print' data-print>🖨 Print KPI Report</button>
");
renderFlashMessages();
?>

<!-- ═══ SECTION 1: REVENUE KPIs ════════════════════════════════ -->
<div style="margin-bottom:.5rem;padding:.4rem 0;border-bottom:2px solid var(--primary);">
  <span class="fw-800" style="color:var(--primary);font-size:.9rem;text-transform:uppercase;letter-spacing:1px;">Revenue Performance</span>
</div>
<div class="grid-5" style="margin-bottom:1.2rem;">
  <div class="stat-card <?= $collectionRate>=75?'green':($collectionRate>=50?'':'red') ?>">
    <div class="stat-icon">🎯</div>
    <div class="stat-info">
      <div class="label">Collection Rate</div>
      <div class="value"><?= $collectionRate ?>%</div>
      <div class="sub <?= $collectionRate>=$fyPct?'up':'down' ?>"><?= $collectionRate>=$fyPct?'▲ On track':'▼ Behind pace' ?></div>
    </div>
  </div>
  <div class="stat-card blue">
    <div class="stat-icon">📈</div>
    <div class="stat-info">
      <div class="label">Performance Index</div>
      <div class="value"><?= $perfIndex ?>%</div>
      <div class="sub">vs year pace (<?= $fyPct ?>%)</div>
    </div>
  </div>
  <div class="stat-card <?= $momGrowth!==null&&$momGrowth>=0?'green':'red' ?>">
    <div class="stat-icon">📊</div>
    <div class="stat-info">
      <div class="label">MoM Growth</div>
      <div class="value"><?= $momGrowth!==null?(($momGrowth>=0?'+':'').$momGrowth.'%'):'—' ?></div>
      <div class="sub">Month-on-month</div>
    </div>
  </div>
  <div class="stat-card amber">
    <div class="stat-icon">⏳</div>
    <div class="stat-info">
      <div class="label">Arrears Rate</div>
      <div class="value"><?= $arrearsRate ?>%</div>
      <div class="sub">UGX <?= number_format($revArrears/1000000,1) ?>M outstanding</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">👥</div>
    <div class="stat-info">
      <div class="label">Defaulter Rate</div>
      <div class="value"><?= $defaulterRate ?>%</div>
      <div class="sub"><?= number_format($defaulters) ?> of <?= number_format($activePayers) ?> payers</div>
    </div>
  </div>
</div>

<!-- Revenue chart + ward table -->
<div class="grid-3" style="margin-bottom:1.2rem;">
  <div class="card" style="grid-column:span 2;">
    <div class="card-header"><h5><span class="ch-icon">📈</span> Monthly Revenue Collection — FY <?= $fy ?></h5></div>
    <div class="card-body"><div class="chart-container" style="height:220px;"><canvas id="revMonthChart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◈</span> Ward Collection Rates</h5></div>
    <div class="card-body p-0">
      <?php foreach($wardKpi as $w):
        $wpct=$w['target']>0?round($w['collected']/$w['target']*100,1):0; ?>
      <div style="padding:.55rem 1rem;border-bottom:1px solid #f5f5f5;">
        <div style="display:flex;justify-content:space-between;font-size:.79rem;margin-bottom:.2rem;">
          <span class="fw-600"><?= htmlspecialchars($w['name']) ?></span>
          <span class="fw-700 <?= $wpct>=75?'text-success':($wpct>=50?'text-warning':'text-danger') ?>"><?= $w['target']>0?"$wpct%":'—' ?></span>
        </div>
        <div class="progress" style="height:5px;"><div class="progress-bar <?= $wpct>=75?'bg-success':($wpct>=50?'bg-warning':'bg-danger') ?>" style="width:<?= min(100,$wpct) ?>%"></div></div>
        <div style="font-size:.7rem;color:#aaa;margin-top:.1rem;">UGX <?= number_format($w['collected']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══ SECTION 2: EXPENDITURE KPIs ═══════════════════════════ -->
<div style="margin-bottom:.5rem;padding:.4rem 0;border-bottom:2px solid var(--primary);">
  <span class="fw-800" style="color:var(--primary);font-size:.9rem;text-transform:uppercase;letter-spacing:1px;">Expenditure & Budget</span>
</div>
<div class="grid-5" style="margin-bottom:1.2rem;">
  <div class="stat-card <?= $budgetUtil>=90?'red':($budgetUtil>=70?'amber':'') ?>">
    <div class="stat-icon">▣</div>
    <div class="stat-info">
      <div class="label">Budget Utilization</div>
      <div class="value"><?= $budgetUtil ?>%</div>
      <div class="sub">UGX <?= number_format($totalSpent/1000000,1) ?>M of <?= number_format($totalBudget/1000000,1) ?>M</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">⏱</div>
    <div class="stat-info">
      <div class="label">Avg Processing Time</div>
      <div class="value"><?= $procTime['avg_days']?round($procTime['avg_days'],1).'d':'—' ?></div>
      <div class="sub">Voucher submit → paid</div>
    </div>
  </div>
  <div class="stat-card <?= $pendingCount>10?'red':($pendingCount>5?'amber':'green') ?>">
    <div class="stat-icon">⏳</div>
    <div class="stat-info">
      <div class="label">Pending Approvals</div>
      <div class="value"><?= $pendingCount ?></div>
      <div class="sub">Vouchers in pipeline</div>
    </div>
  </div>
  <div class="stat-card blue">
    <div class="stat-icon">🏛</div>
    <div class="stat-info">
      <div class="label">Govt Fund Utilization</div>
      <div class="value"><?= $govtUtilRate ?>%</div>
      <div class="sub">UGX <?= number_format($govtUtil/1000000,1) ?>M of <?= number_format($govtRec/1000000,1) ?>M</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">📋</div>
    <div class="stat-info">
      <div class="label">Min/Max Proc. Time</div>
      <div class="value" style="font-size:1rem;"><?= $procTime['min_days']??'—' ?>d / <?= $procTime['max_days']??'—' ?>d</div>
      <div class="sub">Fastest / Slowest</div>
    </div>
  </div>
</div>

<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-header"><h5><span class="ch-icon">▦</span> Expenditure by Department — FY <?= $fy ?></h5></div>
  <div class="card-body"><div class="chart-container" style="height:200px;"><canvas id="deptExpChart"></canvas></div></div>
</div>

<!-- ═══ SECTION 3: OPERATIONAL KPIs ════════════════════════════ -->
<div style="margin-bottom:.5rem;padding:.4rem 0;border-bottom:2px solid var(--primary);">
  <span class="fw-800" style="color:var(--primary);font-size:.9rem;text-transform:uppercase;letter-spacing:1px;">Operations & Governance</span>
</div>
<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card"><div class="stat-icon">◉</div><div class="stat-info"><div class="label">Active Staff</div><div class="value"><?= $activeStaff ?></div><div class="sub">Monthly bill: UGX <?= number_format($salaryBill/1000000,1) ?>M</div></div></div>
  <div class="stat-card blue"><div class="stat-icon">◐</div><div class="stat-info"><div class="label">Active Projects</div><div class="value"><?= $activeProj ?></div><div class="sub">Avg progress: <?= round($avgProgress) ?>%</div></div></div>
  <div class="stat-card green"><div class="stat-icon">◎</div><div class="stat-info"><div class="label">Meetings Held</div><div class="value"><?= $meetingsHeld ?></div><div class="sub">Council meetings</div></div></div>
  <div class="stat-card <?= $resImplementRate>=75?'green':($resImplementRate>=50?'amber':'red') ?>">
    <div class="stat-icon">◆</div>
    <div class="stat-info"><div class="label">Resolution Implementation</div><div class="value"><?= $resImplementRate ?>%</div>
      <div class="sub"><?= $resolutionsImplemented ?>/<?= $totalRes ?> implemented</div>
    </div>
  </div>
</div>

<!-- Revenue Officer Performance -->
<div class="grid-2" style="margin-bottom:1.2rem;">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◉</span> Revenue Officer Performance — FY <?= $fy ?></h5></div>
    <div class="card-body p-0">
      <?php if($officerPerf): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>#</th><th>Officer</th><th class="text-right">Transactions</th><th class="text-right">Collected (UGX)</th><th>Performance</th></tr></thead>
          <tbody>
          <?php foreach($officerPerf as $i=>$o):
            $opct=$maxOfficerCollected>0?round($o['collected']/$maxOfficerCollected*100):0; ?>
          <tr>
            <td class="fs-xs text-muted"><?=$i+1?></td>
            <td class="fw-600"><?=htmlspecialchars($o['full_name'])?></td>
            <td class="text-right"><?=number_format($o['txns'])?></td>
            <td class="text-right fw-700"><?=number_format($o['collected'])?></td>
            <td style="min-width:120px;"><div class="progress" style="height:7px;"><div class="progress-bar bg-primary" style="width:<?=$opct?>%"></div></div></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state" style="padding:1.5rem;"><p>No payment data for this FY.</p></div><?php endif; ?>
    </div>
  </div>

  <!-- Payroll KPI -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◎</span> HR & Payroll</h5></div>
    <div class="card-body">
      <div class="grid-2" style="gap:.5rem;margin-bottom:1rem;">
        <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:8px;">
          <div class="fs-xs text-muted">Active Staff</div>
          <div class="fw-700 text-primary" style="font-size:1.4rem;"><?= $activeStaff ?></div>
        </div>
        <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:8px;">
          <div class="fs-xs text-muted">Monthly Salary Bill</div>
          <div class="fw-700 text-warning" style="font-size:1.1rem;">UGX <?= number_format($salaryBill/1000000,1) ?>M</div>
        </div>
        <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:8px;">
          <div class="fs-xs text-muted">Annual Salary Cost</div>
          <div class="fw-700" style="font-size:1rem;">UGX <?= number_format($salaryBill*12/1000000,1) ?>M</div>
        </div>
        <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:8px;">
          <div class="fs-xs text-muted">Salary as % of Revenue</div>
          <div class="fw-700 <?= $revCollected>0&&($salaryBill*12/$revCollected)>0.4?'text-danger':'text-success' ?>"><?= $revCollected>0?round($salaryBill*12/$revCollected*100,1).'%':'—' ?></div>
        </div>
      </div>
      <?php if($lastPayroll): ?>
      <div style="background:#f0f7ff;padding:.8rem;border-radius:6px;font-size:.83rem;">
        <div class="fw-700" style="margin-bottom:.2rem;">Last Payroll Run</div>
        <div><?= htmlspecialchars($lastPayroll['run_number']) ?> — <?= date('F Y',strtotime($lastPayroll['pay_month'].'-01')) ?></div>
        <div class="fw-700 text-success">Net: UGX <?= number_format($lastPayroll['total_net']) ?> <?= getStatusBadge($lastPayroll['status']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══ SECTION 4: FORECAST SUMMARY ════════════════════════════ -->
<div class="card" style="border:2px solid var(--primary);">
  <div class="card-header"><h5><span class="ch-icon">📊</span> Year-End Projection Summary — FY <?= $fy ?></h5></div>
  <div class="card-body">
    <div class="grid-4">
      <div style="text-align:center;padding:1rem;background:<?= $projectedAnnual>=$revTarget?'#d4edda':'#fff3cd' ?>;border-radius:8px;">
        <div class="fs-xs text-muted">Projected Revenue</div>
        <div style="font-size:1.4rem;font-weight:900;color:<?= $projectedAnnual>=$revTarget?'var(--success)':'var(--warning)' ?>;">UGX <?= number_format($projectedAnnual/1000000,1) ?>M</div>
        <div class="fs-xs"><?= $projectedAnnual>=$revTarget?'▲ Will meet target':'▼ Will miss target' ?></div>
      </div>
      <div style="text-align:center;padding:1rem;background:#f8f9fa;border-radius:8px;">
        <div class="fs-xs text-muted">Revenue Target</div>
        <div style="font-size:1.4rem;font-weight:900;color:var(--primary);">UGX <?= number_format($revTarget/1000000,1) ?>M</div>
        <div class="fs-xs"><?= $collectionRate ?>% collected so far</div>
      </div>
      <div style="text-align:center;padding:1rem;background:#f8f9fa;border-radius:8px;">
        <div class="fs-xs text-muted">Days Remaining</div>
        <div style="font-size:1.4rem;font-weight:900;color:var(--info);"><?= $daysTotal-$daysElapsed ?></div>
        <div class="fs-xs">of <?= $daysTotal ?> days in FY</div>
      </div>
      <div style="text-align:center;padding:1rem;background:#f8f9fa;border-radius:8px;">
        <div class="fs-xs text-muted">Required Daily Rate</div>
        <div style="font-size:1.1rem;font-weight:900;color:var(--primary);">UGX <?= number_format(max(0,($revTarget-$revCollected)/max(1,$daysTotal-$daysElapsed))) ?></div>
        <div class="fs-xs">to meet target</div>
      </div>
    </div>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  makeLineChart('revMonthChart',<?=$mLabels?>,[{label:'Revenue (UGX)',data:<?=$mValues?>,borderColor:'#1a3a5c',backgroundColor:'rgba(26,58,92,.07)',fill:true,tension:.4,borderWidth:2.5,pointRadius:3}]);
  makeBarChart('deptExpChart',<?=$dLabels?>,[{label:'Expenditure (UGX)',data:<?=$dSpent?>,backgroundColor:'#c8a84b',borderRadius:4}]);
});
</script>
<?php renderFooter(); ?>
