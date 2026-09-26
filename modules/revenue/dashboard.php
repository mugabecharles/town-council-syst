<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);

$target   = (float)$db->prepare("SELECT COALESCE(SUM(target_amount),0) FROM revenue_targets WHERE financial_year=?")->execute([$fy]) ? 0 : 0;
$tStmt    = $db->prepare("SELECT COALESCE(SUM(target_amount),0) FROM revenue_targets WHERE financial_year=?"); $tStmt->execute([$fy]); $target = (float)$tStmt->fetchColumn();
if (!$target) $target = 850000000;

$collected = (float)$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'")->execute([$fy]) ? 0 : 0;
$cStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'"); $cStmt->execute([$fy]); $collected = (float)$cStmt->fetchColumn();

$outstanding = (float)$db->prepare("SELECT COALESCE(SUM(balance),0) FROM revenue_assessments WHERE financial_year=? AND status IN ('active','partial','overdue')")->execute([$fy]) ? 0 : 0;
$oStmt = $db->prepare("SELECT COALESCE(SUM(balance),0) FROM revenue_assessments WHERE financial_year=? AND status IN ('active','partial','overdue')"); $oStmt->execute([$fy]); $outstanding = (float)$oStmt->fetchColumn();

$payers_count  = $db->query("SELECT COUNT(*) FROM payers WHERE status='active'")->fetchColumn();
$voided_count  = $db->prepare("SELECT COUNT(*) FROM revenue_payments WHERE financial_year=? AND status='voided'"); $voided_count->execute([$fy]); $voided_count = (int)$voided_count->fetchColumn();
$rate = $target > 0 ? round(($collected/$target)*100,1) : 0;

// Ward performance
$wardPerf = $db->prepare("SELECT w.name, 
    COALESCE(SUM(CASE WHEN rp.status='active' THEN rp.amount ELSE 0 END),0) AS collected,
    COALESCE(SUM(rt.target_amount),0) AS target
    FROM wards w
    LEFT JOIN revenue_payments rp ON rp.ward_id=w.id AND rp.financial_year=?
    LEFT JOIN revenue_targets rt  ON rt.ward_id=w.id AND rt.financial_year=?
    GROUP BY w.id ORDER BY collected DESC");
$wardPerf->execute([$fy,$fy]); $wardPerf = $wardPerf->fetchAll();

// Source performance
$srcPerf = $db->prepare("SELECT rs.name,
    COALESCE(SUM(CASE WHEN rp.status='active' THEN rp.amount ELSE 0 END),0) AS collected,
    COUNT(DISTINCT rp.id) AS transactions
    FROM revenue_sources rs
    LEFT JOIN revenue_payments rp ON rp.revenue_source_id=rs.id AND rp.financial_year=?
    GROUP BY rs.id ORDER BY collected DESC LIMIT 10");
$srcPerf->execute([$fy]); $srcPerf = $srcPerf->fetchAll();

// Monthly trend
$monthly = $db->prepare("SELECT DATE_FORMAT(payment_date,'%b') AS mon,
    MONTH(payment_date) AS mnum,
    SUM(CASE WHEN status='active' THEN amount ELSE 0 END) AS revenue
    FROM revenue_payments WHERE financial_year=?
    GROUP BY YEAR(payment_date),MONTH(payment_date) ORDER BY YEAR(payment_date),MONTH(payment_date)");
$monthly->execute([$fy]); $monthly = $monthly->fetchAll();

// Top payers
$topPayers = $db->prepare("SELECT p.full_name, p.business_name, p.payer_number, w.name AS ward,
    SUM(rp.amount) AS total
    FROM revenue_payments rp JOIN payers p ON rp.payer_id=p.id LEFT JOIN wards w ON p.ward_id=w.id
    WHERE rp.financial_year=? AND rp.status='active'
    GROUP BY rp.payer_id ORDER BY total DESC LIMIT 10");
$topPayers->execute([$fy]); $topPayers = $topPayers->fetchAll();

$fyears = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

renderHead('Revenue Dashboard');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Revenue Dashboard','Financial Year: '.$fy);
renderPageStart('Revenue Dashboard','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Revenue Dashboard']
]);
// FY switcher in actions
$fyOpts = '';
foreach ($fyears as $y) $fyOpts .= "<option value='$y'" . ($y===$fy?' selected':'') . ">$y</option>";
renderPageActions("
  <form method='GET' style='display:flex;gap:.5rem;align-items:center;'>
    <label style='font-size:.82rem;font-weight:600;'>Financial Year:</label>
    <select name='fy' class='form-select' onchange='this.form.submit()' style='width:130px;font-size:.84rem;'>$fyOpts</select>
  </form>
  <a href='".APP_URL."/modules/revenue/payers.php' class='btn btn-outline-secondary'>Manage Payers</a>
  <a href='".APP_URL."/modules/revenue/payments.php' class='btn btn-primary'>Record Payment</a>
");
renderFlashMessages();
?>

<!-- KPI Row -->
<div class="grid-5" style="margin-bottom:1.2rem;">
  <div class="stat-card green">
    <div class="stat-icon">🎯</div>
    <div class="stat-info"><div class="label">Annual Target</div><div class="value"><?= number_format($target/1000000,0) ?>M</div><div class="sub">UGX <?= number_format($target) ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">💰</div>
    <div class="stat-info"><div class="label">Collected</div><div class="value"><?= number_format($collected/1000000,1) ?>M</div><div class="sub up"><?= $rate ?>% of target</div></div>
  </div>
  <div class="stat-card red">
    <div class="stat-icon">⏳</div>
    <div class="stat-info"><div class="label">Outstanding</div><div class="value"><?= number_format($outstanding/1000000,1) ?>M</div><div class="sub">Revenue arrears</div></div>
  </div>
  <div class="stat-card blue">
    <div class="stat-icon">◉</div>
    <div class="stat-info"><div class="label">Active Payers</div><div class="value"><?= number_format($payers_count) ?></div><div class="sub">Registered payers</div></div>
  </div>
  <div class="stat-card amber">
    <div class="stat-icon">✕</div>
    <div class="stat-info"><div class="label">Voided Receipts</div><div class="value"><?= $voided_count ?></div><div class="sub">This financial year</div></div>
  </div>
</div>

<!-- Progress Bar -->
<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-body">
    <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
      <span class="fw-600 fs-sm">Revenue Collection Progress — FY <?= $fy ?></span>
      <span class="fw-700 <?= $rate>=75?'text-success':($rate>=50?'text-warning':'text-danger') ?>"><?= $rate ?>%</span>
    </div>
    <div class="progress" style="height:16px;">
      <div class="progress-bar <?= $rate>=75?'bg-success':($rate>=50?'bg-warning':'bg-danger') ?>" style="width:<?= min(100,$rate) ?>%"></div>
    </div>
    <div style="display:flex;justify-content:space-between;margin-top:.5rem;font-size:.78rem;color:#6c757d;">
      <span>Collected: UGX <?= number_format($collected) ?></span>
      <span>Remaining: UGX <?= number_format(max(0,$target-$collected)) ?></span>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="grid-3" style="margin-bottom:1.2rem;">
  <div class="card" style="grid-column:span 2;">
    <div class="card-header"><h5><span class="ch-icon">📈</span> Monthly Revenue Collection — FY <?= $fy ?></h5></div>
    <div class="card-body"><div class="chart-container"><canvas id="monthlyRev"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◎</span> Revenue by Source</h5></div>
    <div class="card-body"><div class="chart-container"><canvas id="srcChart"></canvas></div></div>
  </div>
</div>

<!-- Ward Performance Table -->
<div class="grid-2" style="margin-bottom:1.2rem;">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">▣</span> Ward Revenue Performance</h5></div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Ward</th><th class="text-right">Collected</th><th class="text-right">Target</th><th>Rate</th><th>Progress</th></tr></thead>
          <tbody>
          <?php foreach ($wardPerf as $w):
            $wpct = $w['target']>0 ? round(($w['collected']/$w['target'])*100,1) : 0; ?>
          <tr>
            <td class="fw-600"><?= htmlspecialchars($w['name']) ?></td>
            <td class="text-right"><?= number_format($w['collected']) ?></td>
            <td class="text-right text-muted"><?= $w['target']>0?number_format($w['target']):'—' ?></td>
            <td class="fw-700 <?= $wpct>=75?'text-success':($wpct>=50?'text-warning':'text-danger') ?>"><?= $w['target']>0?"$wpct%":'—' ?></td>
            <td style="min-width:80px;"><div class="progress" style="height:6px;"><div class="progress-bar bg-primary" style="width:<?= min(100,$wpct) ?>%"></div></div></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th>TOTAL</th><th class="text-right"><?= number_format($collected) ?></th><th class="text-right"><?= number_format($target) ?></th><th class="fw-700"><?= $rate ?>%</th><th></th></tr></tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- Top Payers -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◉</span> Top Revenue Payers — FY <?= $fy ?></h5></div>
    <div class="card-body p-0">
      <?php if ($topPayers): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>#</th><th>Payer</th><th>Ward</th><th class="text-right">Total Paid</th></tr></thead>
          <tbody>
          <?php foreach ($topPayers as $i => $tp): ?>
          <tr>
            <td class="fs-xs text-muted"><?= $i+1 ?></td>
            <td><div class="fw-600"><?= htmlspecialchars($tp['full_name']) ?></div><div class="fs-xs text-muted"><?= htmlspecialchars($tp['payer_number']) ?></div></td>
            <td class="fs-sm"><?= htmlspecialchars($tp['ward'] ?? '—') ?></td>
            <td class="text-right fw-700"><?= number_format($tp['total']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state"><p>No payment data yet.</p></div><?php endif; ?>
    </div>
  </div>
</div>

<!-- Revenue Source Detail -->
<div class="card">
  <div class="card-header"><h5><span class="ch-icon">◈</span> Revenue by Source — FY <?= $fy ?></h5></div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Revenue Source</th><th class="text-right">Collected (UGX)</th><th>Transactions</th><th>% of Total</th><th>Contribution</th></tr></thead>
        <tbody>
        <?php foreach ($srcPerf as $src):
          $pct = $collected>0 ? round(($src['collected']/$collected)*100,1) : 0; ?>
        <tr>
          <td class="fw-600"><?= htmlspecialchars($src['name']) ?></td>
          <td class="text-right fw-700"><?= number_format($src['collected']) ?></td>
          <td class="text-center"><?= number_format($src['transactions']) ?></td>
          <td class="text-center"><?= $pct ?>%</td>
          <td style="min-width:120px;"><div class="progress" style="height:7px;"><div class="progress-bar bg-gold" style="width:<?= min(100,$pct) ?>%"></div></div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>';
$mLabels = json_encode(array_column($monthly,'mon'));
$mValues = json_encode(array_column($monthly,'revenue'));
$sLabels = json_encode(array_column($srcPerf,'name'));
$sValues = json_encode(array_column($srcPerf,'collected'));
?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  makeBarChart('monthlyRev',<?= $mLabels ?>,[{label:'Revenue (UGX)',data:<?= $mValues ?>,backgroundColor:'#1a3a5c',borderRadius:4}]);
  makeDoughnutChart('srcChart',<?= $sLabels ?>,<?= $sValues ?>);
});
</script>
<?php renderFooter(); ?>
