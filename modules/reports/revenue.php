<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);
$rpt  = $_GET['report'] ?? 'summary';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('first day of this month'));
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$wardFil  = (int)($_GET['ward_id'] ?? 0);
$srcFil   = (int)($_GET['source_id'] ?? 0);
$methodF  = $_GET['method'] ?? '';

// Build filters
$where = ["rp.status='active'"]; $params = [];
if ($fy)      { $where[] = "rp.financial_year=?"; $params[] = $fy; }
if ($dateFrom){ $where[] = "rp.payment_date >= ?"; $params[] = $dateFrom; }
if ($dateTo)  { $where[] = "rp.payment_date <= ?"; $params[] = $dateTo; }
if ($wardFil) { $where[] = "rp.ward_id=?";         $params[] = $wardFil; }
if ($srcFil)  { $where[] = "rp.revenue_source_id=?"; $params[] = $srcFil; }
if ($methodF) { $where[] = "rp.payment_method=?";  $params[] = $methodF; }
$wSQL = implode(' AND ',$where);

// Report data
$reportData = [];
$reportTitle = '';

switch ($rpt) {
    case 'by_ward':
        $reportTitle = 'Revenue by Ward';
        $reportData = $db->prepare("SELECT w.name AS label, COALESCE(SUM(rp.amount),0) AS total, COUNT(rp.id) AS count FROM wards w LEFT JOIN revenue_payments rp ON rp.ward_id=w.id AND ".str_replace("rp.ward_id=?","",$wSQL)." GROUP BY w.id ORDER BY total DESC");
        $reportData->execute(array_filter($params, fn($k)=>$k!==array_search($wardFil,$params) , ARRAY_FILTER_USE_KEY));
        // Simpler version:
        $reportData = $db->prepare("SELECT w.name AS label, COALESCE(SUM(rp.amount),0) AS total, COUNT(rp.id) AS txns FROM wards w LEFT JOIN revenue_payments rp ON rp.ward_id=w.id AND rp.status='active' AND rp.financial_year=? AND rp.payment_date BETWEEN ? AND ? GROUP BY w.id ORDER BY total DESC");
        $reportData->execute([$fy, $dateFrom, $dateTo]); $reportData = $reportData->fetchAll();
        break;
    case 'by_source':
        $reportTitle = 'Revenue by Source';
        $reportData = $db->prepare("SELECT rs.name AS label, COALESCE(SUM(rp.amount),0) AS total, COUNT(rp.id) AS txns FROM revenue_sources rs LEFT JOIN revenue_payments rp ON rp.revenue_source_id=rs.id AND rp.status='active' AND rp.financial_year=? AND rp.payment_date BETWEEN ? AND ? GROUP BY rs.id ORDER BY total DESC");
        $reportData->execute([$fy, $dateFrom, $dateTo]); $reportData = $reportData->fetchAll();
        break;
    case 'by_method':
        $reportTitle = 'Revenue by Payment Method';
        $reportData = $db->prepare("SELECT payment_method AS label, SUM(amount) AS total, COUNT(*) AS txns FROM revenue_payments WHERE status='active' AND financial_year=? AND payment_date BETWEEN ? AND ? GROUP BY payment_method ORDER BY total DESC");
        $reportData->execute([$fy, $dateFrom, $dateTo]); $reportData = $reportData->fetchAll();
        break;
    case 'arrears':
        $reportTitle = 'Revenue Arrears Report';
        break;
    case 'target_vs_actual':
        $reportTitle = 'Revenue Target vs Actual';
        $reportData = $db->prepare("SELECT rs.name AS source, COALESCE(rt.target_amount,0) AS target, COALESCE(SUM(rp.amount),0) AS collected FROM revenue_sources rs LEFT JOIN revenue_targets rt ON rt.revenue_source_id=rs.id AND rt.financial_year=? LEFT JOIN revenue_payments rp ON rp.revenue_source_id=rs.id AND rp.status='active' AND rp.financial_year=? GROUP BY rs.id ORDER BY target DESC");
        $reportData->execute([$fy,$fy]); $reportData = $reportData->fetchAll();
        break;
    default:
        $reportTitle = 'Revenue Collection Summary';
        // Overall summary
        $summary = $db->prepare("SELECT COUNT(*) AS txns, COALESCE(SUM(amount),0) AS total, payment_method FROM revenue_payments WHERE status='active' AND financial_year=? AND payment_date BETWEEN ? AND ? GROUP BY payment_method");
        $summary->execute([$fy, $dateFrom, $dateTo]); $reportData = $summary->fetchAll();
}

// Grand total for current filters
$grand = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE $wSQL"); $grand->execute($params); $grandTotal=(float)$grand->fetchColumn();
$txnCount = $db->prepare("SELECT COUNT(*) FROM revenue_payments WHERE $wSQL"); $txnCount->execute($params); $txnCount=(int)$txnCount->fetchColumn();

$wards   = $db->query("SELECT * FROM wards WHERE is_active=1 ORDER BY name")->fetchAll();
$sources = $db->query("SELECT * FROM revenue_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

renderHead('Revenue Reports');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Revenue Reports','Generate and export revenue reports');
renderPageStart('Revenue Reports','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Revenue Reports']
]);
renderPageActions('<button class="btn btn-outline-secondary no-print" data-print>🖨 Print Report</button>');
renderFlashMessages();
?>

<!-- Report Type Tabs -->
<div class="tab-group">
<div class="tab-nav">
  <?php
  $tabs = ['summary'=>'Summary','by_ward'=>'By Ward','by_source'=>'By Source','by_method'=>'By Method','target_vs_actual'=>'Target vs Actual','arrears'=>'Arrears'];
  foreach ($tabs as $key => $label):
  ?>
  <a href="?report=<?=$key?>&fy=<?=urlencode($fy)?>&date_from=<?=$dateFrom?>&date_to=<?=$dateTo?>"
     class="tab-link <?=$rpt===$key?'active':''?>"><?=$label?></a>
  <?php endforeach; ?>
</div>
</div>

<!-- Filters -->
<form method="GET">
<input type="hidden" name="report" value="<?=htmlspecialchars($rpt)?>">
<div class="filter-row">
  <div class="form-group"><label>Financial Year</label>
    <select name="fy" class="form-select"><?php foreach ($fyears as $y): ?><option value="<?=$y?>" <?=$y===$fy?'selected':''?>><?=$y?></option><?php endforeach; ?></select>
  </div>
  <div class="form-group"><label>From</label><input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>"></div>
  <div class="form-group"><label>To</label><input type="date" name="date_to" class="form-control" value="<?=$dateTo?>"></div>
  <?php if ($rpt==='summary'||$rpt==='arrears'): ?>
  <div class="form-group"><label>Ward</label>
    <select name="ward_id" class="form-select"><option value="">All Wards</option><?php foreach ($wards as $w): ?><option value="<?=$w['id']?>" <?=$wardFil==$w['id']?'selected':''?>><?=htmlspecialchars($w['name'])?></option><?php endforeach; ?></select>
  </div>
  <div class="form-group"><label>Source</label>
    <select name="source_id" class="form-select"><option value="">All Sources</option><?php foreach ($sources as $s): ?><option value="<?=$s['id']?>" <?=$srcFil==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?></select>
  </div>
  <?php endif; ?>
  <div class="form-group"><label>&nbsp;</label><button type="submit" class="btn btn-primary">Generate</button></div>
</div>
</form>

<!-- Report Header (printable) -->
<div class="card" style="margin-bottom:1rem;">
  <div class="card-body" style="padding:1.2rem;">
    <div style="text-align:center;border-bottom:2px solid var(--primary);padding-bottom:1rem;margin-bottom:1rem;">
      <strong style="font-size:1.1rem;color:var(--primary);"><?=htmlspecialchars(getSystemSetting('council_name')??'Town Council')?></strong>
      <div style="font-size:.9rem;font-weight:700;color:var(--accent);margin:.3rem 0;"><?=$reportTitle?> — OFFICIAL REPORT</div>
      <div class="fs-sm text-muted">Financial Year: <?=$fy?> | Period: <?=formatDate($dateFrom)?> to <?=formatDate($dateTo)?></div>
      <div class="fs-xs text-muted">Generated: <?=date('d/m/Y H:i')?> by <?=htmlspecialchars($user['full_name'])?></div>
    </div>

    <div class="grid-3" style="margin-bottom:1rem;">
      <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:6px;">
        <div class="fs-xs text-muted">TOTAL REVENUE</div>
        <div style="font-size:1.4rem;font-weight:800;color:var(--primary);"><?=CURRENCY?> <?=number_format($grandTotal)?></div>
      </div>
      <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:6px;">
        <div class="fs-xs text-muted">TRANSACTIONS</div>
        <div style="font-size:1.4rem;font-weight:800;color:var(--success);"><?=number_format($txnCount)?></div>
      </div>
      <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:6px;">
        <div class="fs-xs text-muted">AVERAGE PER TRANSACTION</div>
        <div style="font-size:1.4rem;font-weight:800;color:var(--info);"><?=CURRENCY?> <?=$txnCount>0?number_format($grandTotal/$txnCount):'0'?></div>
      </div>
    </div>
  </div>
</div>

<!-- Report Data -->
<div class="card">
  <div class="card-header"><h5><span class="ch-icon">▨</span> <?=$reportTitle?></h5></div>
  <div class="card-body p-0">
    <?php if ($reportData): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <?php if ($rpt === 'target_vs_actual'): ?>
        <thead><tr><th>Revenue Source</th><th class="text-right">Target (UGX)</th><th class="text-right">Collected (UGX)</th><th class="text-right">Variance</th><th>Achievement</th></tr></thead>
        <tbody>
        <?php foreach ($reportData as $r):
          $achv = $r['target']>0 ? round($r['collected']/$r['target']*100,1) : 0; ?>
        <tr>
          <td class="fw-600"><?=htmlspecialchars($r['source'])?></td>
          <td class="text-right"><?=number_format($r['target'])?></td>
          <td class="text-right fw-700"><?=number_format($r['collected'])?></td>
          <td class="text-right <?=$r['collected']>=$r['target']?'text-success':'text-danger'?>"><?=number_format($r['collected']-$r['target'])?></td>
          <td>
            <div style="display:flex;align-items:center;gap:.5rem;">
              <div class="progress" style="flex:1;height:7px;"><div class="progress-bar <?=$achv>=100?'bg-success':($achv>=75?'bg-primary':'bg-warning')?>" style="width:<?=min(100,$achv)?>%"></div></div>
              <span class="fs-xs fw-700"><?=$achv?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php elseif ($rpt === 'summary'): ?>
        <thead><tr><th>Payment Method</th><th class="text-right">Amount (UGX)</th><th class="text-right">Transactions</th><th>% Share</th></tr></thead>
        <tbody>
        <?php foreach ($reportData as $r):
          $share = $grandTotal>0 ? round($r['total']/$grandTotal*100,1) : 0; ?>
        <tr>
          <td class="fw-600"><?=ucwords(str_replace('_',' ',$r['payment_method']))?></td>
          <td class="text-right fw-700"><?=number_format($r['total'])?></td>
          <td class="text-right"><?=number_format($r['txns'])?></td>
          <td><div style="display:flex;align-items:center;gap:.5rem;"><div class="progress" style="flex:1;height:6px;"><div class="progress-bar bg-primary" style="width:<?=$share?>%"></div></div><span class="fs-xs"><?=$share?>%</span></div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php else: ?>
        <thead><tr><th><?=$rpt==='by_ward'?'Ward':($rpt==='by_source'?'Revenue Source':'Category')?></th><th class="text-right">Amount (UGX)</th><th class="text-right">Transactions</th><th>% Share</th></tr></thead>
        <tbody>
        <?php foreach ($reportData as $r):
          $share = $grandTotal>0 ? round($r['total']/$grandTotal*100,1) : 0; ?>
        <tr>
          <td class="fw-600"><?=htmlspecialchars($r['label']??'')?></td>
          <td class="text-right fw-700"><?=number_format($r['total'])?></td>
          <td class="text-right"><?=number_format($r['txns']??0)?></td>
          <td><div style="display:flex;align-items:center;gap:.5rem;"><div class="progress" style="flex:1;height:6px;"><div class="progress-bar bg-primary" style="width:<?=$share?>%"></div></div><span class="fs-xs"><?=$share?>%</span></div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php endif; ?>
        <tfoot><tr><th>TOTAL</th><th class="text-right"><?=number_format($grandTotal)?></th><th class="text-right"><?=number_format($txnCount)?></th><th>100%</th></tr></tfoot>
      </table>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">▨</div><h5>No data for selected filters</h5></div><?php endif; ?>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
