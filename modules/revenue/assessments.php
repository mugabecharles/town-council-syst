<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_assessment') {
        $asmNum  = generateAssessmentNumber();
        $assessed = (float)str_replace(',','', $_POST['assessed_amount']);
        $penalty  = (float)str_replace(',','', $_POST['penalty_amount'] ?? 0);
        $total    = $assessed + $penalty;
        $db->prepare("INSERT INTO revenue_assessments
            (assessment_number,payer_id,revenue_source_id,financial_year,period_from,period_to,
             assessed_amount,penalty_amount,total_due,assessed_by,assessed_date,due_date,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $asmNum, (int)$_POST['payer_id'], (int)$_POST['revenue_source_id'],
               $_POST['financial_year'] ?? $fy,
               $_POST['period_from'] ?: null, $_POST['period_to'] ?: null,
               $assessed, $penalty, $total, $user['id'], date('Y-m-d'),
               $_POST['due_date'] ?: null, trim($_POST['notes'] ?? '')
           ]);
        logAudit('CREATE','revenue','assessment',(int)$db->lastInsertId(),$asmNum);
        setFlash('success',"Assessment created: {$asmNum}");
    }
    $pid = (int)($_GET['payer_id'] ?? 0);
    header('Location: assessments.php' . ($pid ? "?payer_id=$pid" : '')); exit;
}

$payerIdFil = (int)($_GET['payer_id'] ?? 0);
$statusFil  = $_GET['status'] ?? '';
$fyFil      = $_GET['fy']     ?? $fy;
$search     = trim($_GET['search'] ?? '');
$page       = max(1,(int)($_GET['page'] ?? 1));

$where  = ['1=1']; $params = [];
if ($payerIdFil) { $where[] = "ra.payer_id=?";          $params[] = $payerIdFil; }
if ($statusFil)  { $where[] = "ra.status=?";             $params[] = $statusFil; }
if ($fyFil)      { $where[] = "ra.financial_year=?";     $params[] = $fyFil; }
if ($search)     { $where[] = "(p.full_name LIKE ? OR ra.assessment_number LIKE ?)";
                   $params  = array_merge($params,["%$search%","%$search%"]); }
$wSQL = implode(' AND ',$where);

$cnt = $db->prepare("SELECT COUNT(*) FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL");
$cnt->execute($params); $total = (int)$cnt->fetchColumn();
$pg   = paginate($total,$page);

$rows = $db->prepare("SELECT ra.*, p.full_name, p.payer_number, p.business_name,
    rs.name AS source_name, w.name AS ward_name
    FROM revenue_assessments ra
    JOIN payers p  ON ra.payer_id=p.id
    JOIN revenue_sources rs ON ra.revenue_source_id=rs.id
    LEFT JOIN wards w ON p.ward_id=w.id
    WHERE $wSQL ORDER BY ra.created_at DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $assessments = $rows->fetchAll();

$sumStmt = $db->prepare("SELECT COALESCE(SUM(total_due),0) td, COALESCE(SUM(amount_paid),0) tp
    FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL");
$sumStmt->execute($params); $sums = $sumStmt->fetch();
$totalDue = (float)$sums['td']; $totalPaid = (float)$sums['tp'];

$payers  = $db->query("SELECT id,payer_number,full_name,business_name FROM payers WHERE status='active' ORDER BY full_name LIMIT 2000")->fetchAll();
$sources = $db->query("SELECT * FROM revenue_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

$payerDetail = null;
if ($payerIdFil) {
    $s = $db->prepare("SELECT p.*,w.name AS ward_name FROM payers p LEFT JOIN wards w ON p.ward_id=w.id WHERE p.id=?");
    $s->execute([$payerIdFil]); $payerDetail = $s->fetch();
}

renderHead('Revenue Assessments');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Revenue Assessments','Manage revenue obligations and assessments');
renderPageStart('Revenue Assessments','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>APP_URL.'/modules/revenue/payers.php','label'=>'Payers'],
    ['url'=>'#','label'=>'Assessments']
]);
renderPageActions('<button class="btn btn-primary" data-modal="addAssessModal">+ New Assessment</button>');
renderFlashMessages();
?>

<?php if ($payerDetail): ?>
<div class="card" style="margin-bottom:1rem;border-left:4px solid var(--primary);">
  <div class="card-body" style="padding:.8rem 1.2rem;">
    <div style="display:flex;align-items:center;gap:2rem;flex-wrap:wrap;">
      <div><span class="fs-xs text-muted">PAYER</span><br><strong><?= htmlspecialchars($payerDetail['full_name']) ?></strong></div>
      <div><span class="fs-xs text-muted">PAYER ID</span><br><strong class="text-primary"><?= htmlspecialchars($payerDetail['payer_number']) ?></strong></div>
      <div><span class="fs-xs text-muted">WARD</span><br><strong><?= htmlspecialchars($payerDetail['ward_name'] ?? '—') ?></strong></div>
      <div><span class="fs-xs text-muted">PHONE</span><br><strong><?= htmlspecialchars($payerDetail['phone'] ?? '—') ?></strong></div>
      <a href="assessments.php" class="btn btn-sm btn-outline-secondary" style="margin-left:auto;">Clear Filter</a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card"><div class="stat-icon">◆</div><div class="stat-info"><div class="label">Assessments</div><div class="value"><?= number_format($total) ?></div></div></div>
  <div class="stat-card amber"><div class="stat-icon">📋</div><div class="stat-info"><div class="label">Total Due</div><div class="value"><?= number_format($totalDue/1000000,1)?>M</div><div class="sub">UGX <?= number_format($totalDue) ?></div></div></div>
  <div class="stat-card green"><div class="stat-icon">✓</div><div class="stat-info"><div class="label">Total Paid</div><div class="value"><?= number_format($totalPaid/1000000,1)?>M</div><div class="sub">UGX <?= number_format($totalPaid) ?></div></div></div>
  <div class="stat-card red"><div class="stat-icon">⏳</div><div class="stat-info"><div class="label">Outstanding</div><div class="value"><?= number_format(($totalDue-$totalPaid)/1000000,1)?>M</div><div class="sub">UGX <?= number_format($totalDue-$totalPaid) ?></div></div></div>
</div>

<form method="GET">
<?php if ($payerIdFil): ?><input type="hidden" name="payer_id" value="<?= $payerIdFil ?>"><?php endif; ?>
<div class="filter-row">
  <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Payer / Assessment No..."></div>
  <div class="form-group"><label>Financial Year</label>
    <select name="fy" class="form-select">
      <?php foreach ($fyears as $y): ?><option value="<?= $y ?>" <?= $fyFil===$y?'selected':'' ?>><?= $y ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label>Status</label>
    <select name="status" class="form-select"><option value="">All</option>
      <?php foreach (['active','paid','partial','overdue','cancelled','waived'] as $s): ?>
      <option value="<?= $s ?>" <?= $statusFil===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label>&nbsp;</label>
    <div style="display:flex;gap:.4rem;"><button type="submit" class="btn btn-primary">Filter</button><a href="assessments.php<?= $payerIdFil?'?payer_id='.$payerIdFil:'' ?>" class="btn btn-outline-secondary">Reset</a></div>
  </div>
</div>
</form>

<div class="card">
  <div class="card-header"><h5><span class="ch-icon">◆</span> Assessment Records (<?= number_format($total) ?>)</h5></div>
  <div class="card-body p-0">
    <?php if ($assessments): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>#</th><th>Ref.</th><th>Payer</th><th>Source</th><th>FY</th><th class="text-right">Total Due</th><th class="text-right">Paid</th><th class="text-right">Balance</th><th>Due Date</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($assessments as $i => $a): ?>
        <tr>
          <td class="fs-xs text-muted"><?= $pg['offset']+$i+1 ?></td>
          <td class="fw-700 text-primary fs-sm"><?= htmlspecialchars($a['assessment_number']) ?></td>
          <td>
            <div class="fw-600"><?= htmlspecialchars($a['full_name']) ?></div>
            <div class="fs-xs text-muted"><?= htmlspecialchars($a['payer_number']) ?></div>
          </td>
          <td class="fs-sm"><?= htmlspecialchars($a['source_name']) ?></td>
          <td class="fs-sm"><?= $a['financial_year'] ?></td>
          <td class="text-right"><?= number_format($a['total_due']) ?></td>
          <td class="text-right text-success fw-600"><?= number_format($a['amount_paid']) ?></td>
          <td class="text-right <?= $a['balance']>0?'text-danger fw-700':'text-success' ?>"><?= number_format($a['balance']) ?></td>
          <td class="fs-sm"><?= $a['due_date'] ? formatDate($a['due_date']) : '—' ?></td>
          <td><?= getStatusBadge($a['status']) ?></td>
          <td><a href="<?= APP_URL ?>/modules/revenue/payments.php?payer_id=<?= $a['payer_id'] ?>" class="btn btn-sm btn-outline-primary">Record Payment</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="5" class="text-right">TOTALS:</th><th class="text-right"><?= number_format($totalDue) ?></th><th class="text-right"><?= number_format($totalPaid) ?></th><th class="text-right"><?= number_format($totalDue-$totalPaid) ?></th><th colspan="3"></th></tr></tfoot>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">◆</div><h5>No assessments found</h5><p>Create a new assessment using the button above.</p></div>
    <?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?= renderPagination($pg,'?fy='.urlencode($fyFil).'&status='.urlencode($statusFil).'&search='.urlencode($search)) ?></div><?php endif; ?>
</div>

<!-- Add Assessment Modal -->
<div class="modal-backdrop" id="addAssessModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Create Revenue Assessment</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_assessment">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Payer <span class="req">*</span></label>
            <select name="payer_id" class="form-select" required>
              <option value="">-- Select Payer --</option>
              <?php foreach ($payers as $p): ?>
              <option value="<?= $p['id'] ?>" <?= $payerIdFil==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['payer_number'].' — '.$p['full_name'].($p['business_name']?' ('.$p['business_name'].')':'')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Revenue Source <span class="req">*</span></label>
            <select name="revenue_source_id" class="form-select" required>
              <option value="">-- Select Source --</option>
              <?php foreach ($sources as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Financial Year <span class="req">*</span></label>
            <select name="financial_year" class="form-select" required>
              <?php foreach ($fyears as $y): ?><option value="<?= $y ?>" <?= $y===$fy?'selected':'' ?>><?= $y ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Period From</label>
            <input type="date" name="period_from" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Period To</label>
            <input type="date" name="period_to" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Assessed Amount (UGX) <span class="req">*</span></label>
            <input type="number" name="assessed_amount" class="form-control" required min="0" step="1">
          </div>
          <div class="form-group">
            <label class="form-label">Penalty / Surcharge (UGX)</label>
            <input type="number" name="penalty_amount" class="form-control" min="0" step="1" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">Due Date</label>
            <input type="date" name="due_date" class="form-control">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Create Assessment</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
