<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_fund') {
        $fid = 'GF-' . date('Y') . '-' . str_pad($db->query("SELECT COUNT(*)+1 FROM government_funds")->fetchColumn(), 4, '0', STR_PAD_LEFT);
        $db->prepare("INSERT INTO government_funds (funding_id,funding_source_id,ministry_agency,programme_name,fund_type,financial_year,department_id,amount_received,date_received,bank_account,reference_number,purpose,recorded_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $fid, (int)$_POST['funding_source_id'], trim($_POST['ministry_agency'] ?? ''),
               trim($_POST['programme_name'] ?? ''), $_POST['fund_type'], $_POST['financial_year'],
               !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null,
               (float)str_replace(',','', $_POST['amount_received']),
               $_POST['date_received'], trim($_POST['bank_account'] ?? ''),
               trim($_POST['reference_number'] ?? ''), trim($_POST['purpose'] ?? ''), $user['id']
           ]);
        logAudit('CREATE','funding','government_fund',(int)$db->lastInsertId(),$fid);
        setFlash('success',"Government fund recorded: {$fid}");
    } elseif ($action === 'update_utilization') {
        $id   = (int)$_POST['fund_id'];
        $util = (float)str_replace(',','', $_POST['amount_utilized']);
        $db->prepare("UPDATE government_funds SET amount_utilized=?, updated_at=NOW() WHERE id=?")->execute([$util,$id]);
        $db->prepare("UPDATE government_funds SET status=CASE WHEN amount_utilized>=amount_received THEN 'fully_utilized' WHEN amount_utilized>0 THEN 'partially_utilized' ELSE 'allocated' END WHERE id=?")->execute([$id]);
        logAudit('UPDATE','funding','government_fund',$id,'');
        setFlash('success','Fund utilization updated.');
    }
    header('Location: index.php?fy='.urlencode($fy)); exit;
}

$fyFil = $fy;
$page  = max(1,(int)($_GET['page'] ?? 1));
$pg    = paginate((int)$db->prepare("SELECT COUNT(*) FROM government_funds WHERE financial_year=?")->execute([$fyFil])?$db->query("SELECT COUNT(*) FROM government_funds WHERE financial_year='$fyFil'")->fetchColumn():0, $page);

$cnt = $db->prepare("SELECT COUNT(*) FROM government_funds WHERE financial_year=?"); $cnt->execute([$fyFil]); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total,$page);

$rows = $db->prepare("SELECT gf.*, fs.name AS source_name, d.name AS dept_name
    FROM government_funds gf
    JOIN funding_sources fs ON gf.funding_source_id=fs.id
    LEFT JOIN departments d ON gf.department_id=d.id
    WHERE gf.financial_year=? ORDER BY gf.date_received DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute([$fyFil]); $funds = $rows->fetchAll();

$totals = $db->prepare("SELECT COALESCE(SUM(amount_received),0) tr, COALESCE(SUM(amount_utilized),0) tu FROM government_funds WHERE financial_year=?");
$totals->execute([$fyFil]); $totals = $totals->fetch();
$totalReceived = (float)$totals['tr']; $totalUtilized = (float)$totals['tu'];

$fSources = $db->query("SELECT * FROM funding_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$depts    = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears   = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

// Department allocation summary
$deptAlloc = $db->prepare("SELECT d.name, COALESCE(SUM(gf.amount_received),0) AS received, COALESCE(SUM(gf.amount_utilized),0) AS utilized
    FROM departments d LEFT JOIN government_funds gf ON gf.department_id=d.id AND gf.financial_year=?
    WHERE gf.id IS NOT NULL GROUP BY d.id ORDER BY received DESC");
$deptAlloc->execute([$fyFil]); $deptAlloc = $deptAlloc->fetchAll();

renderHead('Government Funds');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Government Funds','Track and manage government funding');
renderPageStart('Government Funds','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Government Funds']
]);
$fyOpts=''; foreach ($fyears as $y) $fyOpts.="<option value='$y'".($y===$fy?' selected':'').">$y</option>";
renderPageActions("
  <form method='GET' style='display:flex;gap:.5rem;align-items:center;'>
    <select name='fy' class='form-select' onchange='this.form.submit()' style='width:130px;font-size:.84rem;'>$fyOpts</select>
  </form>
  <button class='btn btn-primary' data-modal='addFundModal'>+ Record Fund</button>
");
renderFlashMessages();
?>

<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card green"><div class="stat-icon">🏛</div><div class="stat-info"><div class="label">Total Received</div><div class="value"><?= number_format($totalReceived/1000000,1)?>M</div><div class="sub">UGX <?= number_format($totalReceived) ?></div></div></div>
  <div class="stat-card blue"><div class="stat-icon">✅</div><div class="stat-info"><div class="label">Utilized</div><div class="value"><?= number_format($totalUtilized/1000000,1)?>M</div><div class="sub"><?= $totalReceived>0?round($totalUtilized/$totalReceived*100,1):0 ?>% utilization</div></div></div>
  <div class="stat-card amber"><div class="stat-icon">💰</div><div class="stat-info"><div class="label">Remaining</div><div class="value"><?= number_format(($totalReceived-$totalUtilized)/1000000,1)?>M</div><div class="sub">Available balance</div></div></div>
  <div class="stat-card"><div class="stat-icon">📋</div><div class="stat-info"><div class="label">Fund Records</div><div class="value"><?= number_format($total) ?></div><div class="sub">FY <?= $fy ?></div></div></div>
</div>

<!-- Dept Allocation -->
<?php if ($deptAlloc): ?>
<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-header"><h5><span class="ch-icon">▦</span> Fund Allocation by Department — FY <?= $fy ?></h5></div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Department</th><th class="text-right">Received (UGX)</th><th class="text-right">Utilized (UGX)</th><th class="text-right">Balance</th><th>Utilization</th></tr></thead>
        <tbody>
        <?php foreach ($deptAlloc as $da):
          $dpct = $da['received']>0 ? round($da['utilized']/$da['received']*100,1) : 0; ?>
        <tr>
          <td class="fw-600"><?= htmlspecialchars($da['name']) ?></td>
          <td class="text-right"><?= number_format($da['received']) ?></td>
          <td class="text-right"><?= number_format($da['utilized']) ?></td>
          <td class="text-right fw-700 <?= ($da['received']-$da['utilized'])>0?'text-success':'text-muted' ?>"><?= number_format($da['received']-$da['utilized']) ?></td>
          <td style="min-width:140px;">
            <div style="display:flex;align-items:center;gap:.5rem;">
              <div class="progress" style="flex:1;height:7px;"><div class="progress-bar <?= $dpct>=75?'bg-success':($dpct>=50?'bg-warning':'bg-danger') ?>" style="width:<?= min(100,$dpct) ?>%"></div></div>
              <span class="fs-xs fw-600"><?= $dpct ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th>TOTAL</th><th class="text-right"><?= number_format($totalReceived) ?></th><th class="text-right"><?= number_format($totalUtilized) ?></th><th class="text-right fw-700"><?= number_format($totalReceived-$totalUtilized) ?></th><th><?= $totalReceived>0?round($totalUtilized/$totalReceived*100,1):0 ?>%</th></tr></tfoot>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Fund Records -->
<div class="card">
  <div class="card-header"><h5><span class="ch-icon">◎</span> Government Fund Records</h5></div>
  <div class="card-body p-0">
    <?php if ($funds): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Fund ID</th><th>Source</th><th>Ministry / Agency</th><th>Programme</th><th>Department</th><th>Type</th><th class="text-right">Received</th><th class="text-right">Utilized</th><th class="text-right">Balance</th><th>Date</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($funds as $f): ?>
        <tr>
          <td class="fw-700 text-primary fs-sm"><?= htmlspecialchars($f['funding_id']) ?></td>
          <td class="fs-sm"><?= htmlspecialchars($f['source_name']) ?></td>
          <td class="fs-sm"><?= htmlspecialchars($f['ministry_agency'] ?? '—') ?></td>
          <td class="fs-sm"><?= htmlspecialchars(substr($f['programme_name'] ?? '—',0,25)) ?></td>
          <td class="fs-sm"><?= htmlspecialchars($f['dept_name'] ?? '—') ?></td>
          <td><span class="badge badge-info"><?= ucfirst(str_replace('_',' ',$f['fund_type'])) ?></span></td>
          <td class="text-right fw-700"><?= number_format($f['amount_received']) ?></td>
          <td class="text-right"><?= number_format($f['amount_utilized']) ?></td>
          <td class="text-right fw-700 <?= ($f['amount_received']-$f['amount_utilized'])>0?'text-success':'text-muted' ?>"><?= number_format($f['amount_received']-$f['amount_utilized']) ?></td>
          <td class="fs-sm"><?= formatDate($f['date_received']) ?></td>
          <td><?= getStatusBadge($f['status']) ?></td>
          <td>
            <button class="btn btn-sm btn-outline-primary" onclick="updateUtil(<?= htmlspecialchars(json_encode($f)) ?>)">Update</button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">◎</div><h5>No fund records</h5><p>Record a government fund using the button above.</p></div><?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?= renderPagination($pg,'?fy='.urlencode($fy)) ?></div><?php endif; ?>
</div>

<!-- Add Fund Modal -->
<div class="modal-backdrop" id="addFundModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Record Government Fund</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_fund">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Funding Source <span class="req">*</span></label>
            <select name="funding_source_id" class="form-select" required>
              <option value="">-- Select Source --</option>
              <?php foreach ($fSources as $fs): ?><option value="<?=$fs['id']?>"><?=htmlspecialchars($fs['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Fund Type <span class="req">*</span></label>
            <select name="fund_type" class="form-select" required>
              <option value="conditional">Conditional Grant</option>
              <option value="unconditional">Unconditional Grant</option>
              <option value="development">Development Fund</option>
              <option value="equalization">Equalization Grant</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Ministry / Agency</label>
            <input type="text" name="ministry_agency" class="form-control" placeholder="e.g. Ministry of Finance">
          </div>
          <div class="form-group">
            <label class="form-label">Programme Name</label>
            <input type="text" name="programme_name" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Amount Received (UGX) <span class="req">*</span></label>
            <input type="number" name="amount_received" class="form-control" required min="1" step="1">
          </div>
          <div class="form-group">
            <label class="form-label">Date Received <span class="req">*</span></label>
            <input type="date" name="date_received" class="form-control" required value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Financial Year <span class="req">*</span></label>
            <select name="financial_year" class="form-select" required>
              <?php foreach ($fyears as $y): ?><option value="<?=$y?>" <?=$y===$fy?'selected':''?>><?=$y?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Department</label>
            <select name="department_id" class="form-select">
              <option value="">-- All / General --</option>
              <?php foreach ($depts as $d): ?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Bank / Account</label>
            <input type="text" name="bank_account" class="form-control">
          </div>
          <div class="form-group">
            <label class="form-label">Reference Number</label>
            <input type="text" name="reference_number" class="form-control">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Purpose / Description</label>
          <textarea name="purpose" class="form-control" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Record Fund</button>
      </div>
    </form>
  </div>
</div>

<!-- Update Utilization Modal -->
<div class="modal-backdrop" id="updateUtilModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Update Fund Utilization</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_utilization">
      <input type="hidden" name="fund_id" id="uu_fund_id">
      <div class="modal-body">
        <div style="background:#f8f9fa;padding:.8rem 1rem;border-radius:6px;margin-bottom:1rem;">
          <div class="fw-700 fs-sm" id="uu_fund_name"></div>
          <div class="fs-sm text-muted">Received: <strong id="uu_received"></strong></div>
        </div>
        <div class="form-group">
          <label class="form-label">Total Amount Utilized (UGX) <span class="req">*</span></label>
          <input type="number" name="amount_utilized" id="uu_utilized" class="form-control" required min="0" step="1">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Update</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; ?>
<script>
function updateUtil(f) {
  document.getElementById('uu_fund_id').value   = f.id;
  document.getElementById('uu_fund_name').textContent = f.funding_id + ' — ' + f.programme_name;
  document.getElementById('uu_received').textContent  = 'UGX ' + Number(f.amount_received).toLocaleString();
  document.getElementById('uu_utilized').value  = f.amount_utilized;
  openModal('updateUtilModal');
}
</script>
<?php renderFooter(); ?>
