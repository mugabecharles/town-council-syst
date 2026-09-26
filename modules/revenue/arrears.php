<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);

$wardFil = (int)($_GET['ward_id'] ?? 0);
$srcFil  = (int)($_GET['source_id'] ?? 0);
$page    = max(1,(int)($_GET['page'] ?? 1));

$where  = ["ra.status IN ('active','partial','overdue') AND ra.balance > 0 AND ra.financial_year=?"];
$params = [$fy];
if ($wardFil) { $where[] = "p.ward_id=?"; $params[] = $wardFil; }
if ($srcFil)  { $where[] = "ra.revenue_source_id=?"; $params[] = $srcFil; }
$wSQL = implode(' AND ',$where);

$cnt = $db->prepare("SELECT COUNT(*) FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL");
$cnt->execute($params); $total = (int)$cnt->fetchColumn();
$pg   = paginate($total,$page);

$rows = $db->prepare("SELECT ra.*, p.full_name, p.payer_number, p.business_name, p.phone,
    rs.name AS source_name, w.name AS ward_name,
    DATEDIFF(CURDATE(), ra.due_date) AS days_overdue
    FROM revenue_assessments ra
    JOIN payers p ON ra.payer_id=p.id
    JOIN revenue_sources rs ON ra.revenue_source_id=rs.id
    LEFT JOIN wards w ON p.ward_id=w.id
    WHERE $wSQL ORDER BY ra.balance DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $arrears = $rows->fetchAll();

$sumStmt = $db->prepare("SELECT COALESCE(SUM(ra.balance),0) FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL");
$sumStmt->execute($params); $totalArrears = (float)$sumStmt->fetchColumn();

$wards   = $db->query("SELECT * FROM wards WHERE is_active=1 ORDER BY name")->fetchAll();
$sources = $db->query("SELECT * FROM revenue_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

renderHead('Arrears & Defaulters');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Arrears & Defaulters','Outstanding revenue obligations');
renderPageStart('Arrears & Defaulters','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>APP_URL.'/modules/revenue/dashboard.php','label'=>'Revenue'],
    ['url'=>'#','label'=>'Arrears']
]);
renderPageActions('<a href="'.APP_URL.'/modules/reports/revenue.php?report=arrears&fy='.$fy.'" class="btn btn-outline-secondary">Export Report</a>');
renderFlashMessages();
?>

<div class="grid-3" style="margin-bottom:1.2rem;">
  <div class="stat-card red"><div class="stat-icon">⚠</div><div class="stat-info"><div class="label">Total Defaulters</div><div class="value"><?= number_format($total) ?></div><div class="sub">Outstanding assessments</div></div></div>
  <div class="stat-card amber"><div class="stat-icon">💸</div><div class="stat-info"><div class="label">Total Arrears</div><div class="value"><?= number_format($totalArrears/1000000,1)?>M</div><div class="sub">UGX <?= number_format($totalArrears) ?></div></div></div>
  <div class="stat-card"><div class="stat-icon">📅</div><div class="stat-info"><div class="label">Financial Year</div><div class="value"><?= $fy ?></div><div class="sub">Current period</div></div></div>
</div>

<form method="GET">
<div class="filter-row">
  <div class="form-group"><label>Financial Year</label>
    <select name="fy" class="form-select"><?php foreach ($fyears as $y): ?><option value="<?=$y?>" <?=$y===$fy?'selected':''?>><?=$y?></option><?php endforeach; ?></select>
  </div>
  <div class="form-group"><label>Ward</label>
    <select name="ward_id" class="form-select"><option value="">All Wards</option><?php foreach ($wards as $w): ?><option value="<?=$w['id']?>" <?=$wardFil==$w['id']?'selected':''?>><?=htmlspecialchars($w['name'])?></option><?php endforeach; ?></select>
  </div>
  <div class="form-group"><label>Revenue Source</label>
    <select name="source_id" class="form-select"><option value="">All Sources</option><?php foreach ($sources as $s): ?><option value="<?=$s['id']?>" <?=$srcFil==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?></select>
  </div>
  <div class="form-group"><label>&nbsp;</label><div style="display:flex;gap:.4rem;"><button type="submit" class="btn btn-primary">Filter</button><a href="arrears.php" class="btn btn-outline-secondary">Reset</a></div></div>
</div>
</form>

<div class="card">
  <div class="card-header"><h5><span class="ch-icon">▲</span> Defaulter List — Outstanding Balances</h5></div>
  <div class="card-body p-0">
    <?php if ($arrears): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>#</th><th>Assessment Ref</th><th>Payer</th><th>Phone</th><th>Ward</th><th>Revenue Source</th><th class="text-right">Total Due</th><th class="text-right">Paid</th><th class="text-right">Balance</th><th>Days Overdue</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($arrears as $i => $a): ?>
        <tr>
          <td class="fs-xs text-muted"><?= $pg['offset']+$i+1 ?></td>
          <td class="fw-600 text-primary fs-sm"><?= htmlspecialchars($a['assessment_number']) ?></td>
          <td>
            <div class="fw-600"><?= htmlspecialchars($a['full_name']) ?></div>
            <?php if ($a['business_name']): ?><div class="fs-xs text-muted"><?= htmlspecialchars($a['business_name']) ?></div><?php endif; ?>
            <div class="fs-xs text-muted"><?= htmlspecialchars($a['payer_number']) ?></div>
          </td>
          <td class="fs-sm"><?= htmlspecialchars($a['phone'] ?? '—') ?></td>
          <td class="fs-sm"><?= htmlspecialchars($a['ward_name'] ?? '—') ?></td>
          <td class="fs-sm"><?= htmlspecialchars($a['source_name']) ?></td>
          <td class="text-right"><?= number_format($a['total_due']) ?></td>
          <td class="text-right text-success"><?= number_format($a['amount_paid']) ?></td>
          <td class="text-right fw-700 text-danger"><?= number_format($a['balance']) ?></td>
          <td class="text-center">
            <?php if ($a['days_overdue'] > 0): ?>
              <span class="badge badge-danger"><?= $a['days_overdue'] ?> days</span>
            <?php else: ?>
              <span class="badge badge-warning">Not yet due</span>
            <?php endif; ?>
          </td>
          <td><a href="<?= APP_URL ?>/modules/revenue/payments.php?payer_id=<?= $a['payer_id'] ?>" class="btn btn-sm btn-primary">Collect</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="6" class="text-right">TOTALS:</th><th class="text-right"><?= number_format(array_sum(array_column($arrears,'total_due'))) ?></th><th class="text-right"><?= number_format(array_sum(array_column($arrears,'amount_paid'))) ?></th><th class="text-right text-danger fw-700"><?= number_format($totalArrears) ?></th><th colspan="2"></th></tr></tfoot>
      </table>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">✅</div><h5>No outstanding arrears</h5><p>All assessed revenue has been collected.</p></div><?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?= renderPagination($pg,'?fy='.urlencode($fy).'&ward_id='.$wardFil.'&source_id='.$srcFil) ?></div><?php endif; ?>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
