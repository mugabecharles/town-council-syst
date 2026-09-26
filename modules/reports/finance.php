<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);
$rpt  = $_GET['report'] ?? 'income_expenditure';
$deptFil = (int)($_GET['dept_id'] ?? 0);

$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
$depts   = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();

// Revenue total
$revTotal = (float)$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'")->execute([$fy])?$db->query("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year='$fy' AND status='active'")->fetchColumn():0;
$rStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'"); $rStmt->execute([$fy]); $revTotal=(float)$rStmt->fetchColumn();
$govtTotal = (float)$db->prepare("SELECT COALESCE(SUM(amount_received),0) FROM government_funds WHERE financial_year=?")->execute([$fy])?0:0;
$gStmt = $db->prepare("SELECT COALESCE(SUM(amount_received),0) FROM government_funds WHERE financial_year=?"); $gStmt->execute([$fy]); $govtTotal=(float)$gStmt->fetchColumn();
$expTotal = (float)$db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE financial_year=? AND status IN ('paid','completed')")->execute([$fy])?0:0;
$eStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE financial_year=? AND status IN ('paid','completed')"); $eStmt->execute([$fy]); $expTotal=(float)$eStmt->fetchColumn();

// Dept expenditure
$where = ["pv.financial_year=?", "pv.status IN ('paid','completed')"]; $params = [$fy];
if ($deptFil) { $where[] = "pv.department_id=?"; $params[] = $deptFil; }
$wSQL = implode(' AND ',$where);
$deptExp = $db->prepare("SELECT d.name, COALESCE(SUM(pv.amount),0) AS spent, COUNT(pv.id) AS vouchers FROM departments d LEFT JOIN payment_vouchers pv ON pv.department_id=d.id AND pv.financial_year=? AND pv.status IN ('paid','completed') GROUP BY d.id ORDER BY spent DESC");
$deptExp->execute([$fy]); $deptExp = $deptExp->fetchAll();

// Budget vs actual
$budgetVsActual = $db->prepare("SELECT d.name, COALESCE(SUM(COALESCE(b.revised_amount,b.approved_amount)),0) AS budget, COALESCE(SUM(b.spent_amount),0) AS spent FROM departments d LEFT JOIN budgets b ON b.department_id=d.id AND b.financial_year=? GROUP BY d.id ORDER BY budget DESC");
$budgetVsActual->execute([$fy]); $budgetVsActual = $budgetVsActual->fetchAll();

// Fund utilization
$fundUtil = $db->prepare("SELECT gf.funding_id, gf.programme_name, gf.amount_received, gf.amount_utilized, fs.name AS source_name, d.name AS dept_name FROM government_funds gf JOIN funding_sources fs ON gf.funding_source_id=fs.id LEFT JOIN departments d ON gf.department_id=d.id WHERE gf.financial_year=? ORDER BY gf.amount_received DESC");
$fundUtil->execute([$fy]); $fundUtil = $fundUtil->fetchAll();

renderHead('Finance Reports');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Finance Reports','Expenditure, budget and funding reports');
renderPageStart('Finance Reports','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Finance Reports']
]);
renderPageActions('<button class="btn btn-outline-secondary no-print" data-print>🖨 Print</button>');
renderFlashMessages();
?>

<div class="tab-nav">
  <?php
  $tabs = ['income_expenditure'=>'Income & Expenditure','dept_expenditure'=>'Dept Expenditure','budget_vs_actual'=>'Budget vs Actual','fund_utilization'=>'Fund Utilization'];
  foreach ($tabs as $k => $l):
  ?><a href="?report=<?=$k?>&fy=<?=urlencode($fy)?>" class="tab-link <?=$rpt===$k?'active':''?>"><?=$l?></a><?php endforeach; ?>
</div>

<form method="GET">
<input type="hidden" name="report" value="<?=htmlspecialchars($rpt)?>">
<div class="filter-row">
  <div class="form-group"><label>Financial Year</label>
    <select name="fy" class="form-select" onchange="this.form.submit()"><?php foreach ($fyears as $y): ?><option value="<?=$y?>" <?=$y===$fy?'selected':''?>><?=$y?></option><?php endforeach; ?></select>
  </div>
</div>
</form>

<!-- Report header -->
<div class="card" style="margin-bottom:1rem;">
  <div class="card-body" style="padding:1rem;">
    <div style="text-align:center;margin-bottom:.8rem;">
      <strong style="font-size:1rem;color:var(--primary);"><?=htmlspecialchars(getSystemSetting('council_name')??'Town Council')?></strong>
      <div class="fs-sm text-muted">Financial Year: <?=$fy?> | Generated: <?=date('d/m/Y H:i')?></div>
    </div>
    <div class="grid-4">
      <div style="text-align:center;padding:.7rem;background:#f8f9fa;border-radius:6px;">
        <div class="fs-xs text-muted">LOCAL REVENUE</div>
        <div class="fw-800" style="font-size:1.2rem;color:var(--success);"><?=number_format($revTotal)?></div>
      </div>
      <div style="text-align:center;padding:.7rem;background:#f8f9fa;border-radius:6px;">
        <div class="fs-xs text-muted">GOVT FUNDS</div>
        <div class="fw-800" style="font-size:1.2rem;color:var(--info);"><?=number_format($govtTotal)?></div>
      </div>
      <div style="text-align:center;padding:.7rem;background:#f8f9fa;border-radius:6px;">
        <div class="fs-xs text-muted">TOTAL INCOME</div>
        <div class="fw-800" style="font-size:1.2rem;color:var(--primary);"><?=number_format($revTotal+$govtTotal)?></div>
      </div>
      <div style="text-align:center;padding:.7rem;background:#f8f9fa;border-radius:6px;">
        <div class="fs-xs text-muted">TOTAL EXPENDITURE</div>
        <div class="fw-800" style="font-size:1.2rem;color:var(--danger);"><?=number_format($expTotal)?></div>
      </div>
    </div>
    <?php $balance = $revTotal + $govtTotal - $expTotal; ?>
    <div style="text-align:center;margin-top:.8rem;padding:.6rem;background:<?=$balance>=0?'#d4edda':'#f8d7da'?>;border-radius:6px;">
      <strong>Net Balance: UGX <?=number_format($balance)?></strong>
    </div>
  </div>
</div>

<?php if ($rpt === 'income_expenditure'): ?>
<div class="grid-2">
  <div class="card">
    <div class="card-header"><h5>Income Summary</h5></div>
    <div class="card-body p-0">
      <table class="tcms-table">
        <tbody>
          <tr><td class="fw-600">Local Revenue</td><td class="text-right fw-700 text-success"><?=number_format($revTotal)?></td></tr>
          <tr><td class="fw-600">Government Grants</td><td class="text-right fw-700 text-info"><?=number_format($govtTotal)?></td></tr>
          <tr style="background:#f8f9fa;"><td class="fw-700">TOTAL INCOME</td><td class="text-right fw-800 text-primary"><?=number_format($revTotal+$govtTotal)?></td></tr>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h5>Expenditure Summary</h5></div>
    <div class="card-body p-0">
      <table class="tcms-table">
        <tbody>
          <?php foreach ($deptExp as $de): if ($de['spent']<=0) continue; ?>
          <tr><td class="fw-600"><?=htmlspecialchars($de['name'])?></td><td class="text-right fw-700"><?=number_format($de['spent'])?></td></tr>
          <?php endforeach; ?>
          <tr style="background:#f8f9fa;"><td class="fw-700">TOTAL EXPENDITURE</td><td class="text-right fw-800 text-danger"><?=number_format($expTotal)?></td></tr>
          <tr style="background:<?=$balance>=0?'#d4edda':'#f8d7da'?>;"><td class="fw-700">NET BALANCE</td><td class="text-right fw-800"><?=number_format($balance)?></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($rpt === 'dept_expenditure'): ?>
<div class="card">
  <div class="card-header"><h5>Departmental Expenditure — FY <?=$fy?></h5></div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Department</th><th class="text-right">Amount Spent (UGX)</th><th>Vouchers</th><th>% of Total</th><th>Proportion</th></tr></thead>
        <tbody>
        <?php foreach ($deptExp as $de):
          $pct = $expTotal>0 ? round($de['spent']/$expTotal*100,1) : 0; ?>
        <tr>
          <td class="fw-600"><?=htmlspecialchars($de['name'])?></td>
          <td class="text-right fw-700"><?=number_format($de['spent'])?></td>
          <td class="text-center"><?=$de['vouchers']?></td>
          <td class="text-center"><?=$pct?>%</td>
          <td style="min-width:120px;"><div class="progress" style="height:7px;"><div class="progress-bar bg-primary" style="width:<?=$pct?>%"></div></div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th>TOTAL</th><th class="text-right"><?=number_format($expTotal)?></th><th class="text-center"><?=array_sum(array_column($deptExp,'vouchers'))?></th><th>100%</th><th></th></tr></tfoot>
      </table>
    </div>
  </div>
</div>

<?php elseif ($rpt === 'budget_vs_actual'): ?>
<div class="card">
  <div class="card-header"><h5>Budget vs Actual Expenditure — FY <?=$fy?></h5></div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Department</th><th class="text-right">Budget (UGX)</th><th class="text-right">Spent (UGX)</th><th class="text-right">Balance</th><th>Utilization</th></tr></thead>
        <tbody>
        <?php foreach ($budgetVsActual as $bva):
          $upct = $bva['budget']>0 ? round($bva['spent']/$bva['budget']*100,1) : 0; ?>
        <tr>
          <td class="fw-600"><?=htmlspecialchars($bva['name'])?></td>
          <td class="text-right"><?=number_format($bva['budget'])?></td>
          <td class="text-right fw-700"><?=number_format($bva['spent'])?></td>
          <td class="text-right <?=($bva['budget']-$bva['spent'])<0?'text-danger fw-700':'text-success'?>"><?=number_format($bva['budget']-$bva['spent'])?></td>
          <td><div style="display:flex;align-items:center;gap:.4rem;">
            <div class="progress" style="flex:1;height:7px;"><div class="progress-bar <?=$upct>=90?'bg-danger':($upct>=70?'bg-warning':'bg-success')?>" style="width:<?=min(100,$upct)?>%"></div></div>
            <span class="fs-xs fw-700"><?=$upct?>%</span></div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($rpt === 'fund_utilization'): ?>
<div class="card">
  <div class="card-header"><h5>Government Fund Utilization — FY <?=$fy?></h5></div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Fund ID</th><th>Source</th><th>Programme</th><th>Department</th><th class="text-right">Received</th><th class="text-right">Utilized</th><th class="text-right">Balance</th><th>Utilization</th></tr></thead>
        <tbody>
        <?php foreach ($fundUtil as $fu):
          $fpct = $fu['amount_received']>0 ? round($fu['amount_utilized']/$fu['amount_received']*100,1) : 0; ?>
        <tr>
          <td class="fw-600 text-primary"><?=htmlspecialchars($fu['funding_id'])?></td>
          <td class="fs-sm"><?=htmlspecialchars($fu['source_name'])?></td>
          <td class="fs-sm"><?=htmlspecialchars($fu['programme_name']??'—')?></td>
          <td class="fs-sm"><?=htmlspecialchars($fu['dept_name']??'—')?></td>
          <td class="text-right fw-700"><?=number_format($fu['amount_received'])?></td>
          <td class="text-right"><?=number_format($fu['amount_utilized'])?></td>
          <td class="text-right <?=($fu['amount_received']-$fu['amount_utilized'])>0?'text-success':'text-muted'?>"><?=number_format($fu['amount_received']-$fu['amount_utilized'])?></td>
          <td><div style="display:flex;align-items:center;gap:.4rem;"><div class="progress" style="flex:1;height:7px;"><div class="progress-bar bg-primary" style="width:<?=$fpct?>%"></div></div><span class="fs-xs"><?=$fpct?>%</span></div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="4">TOTAL</th><th class="text-right"><?=number_format($govtTotal)?></th><th class="text-right"><?=number_format(array_sum(array_column($fundUtil,'amount_utilized')))?></th><th class="text-right"><?=number_format($govtTotal-array_sum(array_column($fundUtil,'amount_utilized')))?></th><th></th></tr></tfoot>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
