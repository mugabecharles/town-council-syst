<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action']??'') === 'add_asset') {
    verifyCsrf();
    $anum = 'AST-'.date('Y').'-'.str_pad($db->query("SELECT COUNT(*)+1 FROM assets")->fetchColumn(),5,'0',STR_PAD_LEFT);
    $db->prepare("INSERT INTO assets (asset_number,name,description,department_id,location,purchase_date,purchase_value,current_value,funding_source_id,responsible_officer,serial_number,status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$anum, trim($_POST['name']), trim($_POST['description']??''),
           !empty($_POST['department_id'])?(int)$_POST['department_id']:null,
           trim($_POST['location']??''), $_POST['purchase_date']??null,
           (float)str_replace(',','',$_POST['purchase_value']??0),
           (float)str_replace(',','',$_POST['purchase_value']??0),
           !empty($_POST['funding_source_id'])?(int)$_POST['funding_source_id']:null,
           !empty($_POST['responsible_officer'])?(int)$_POST['responsible_officer']:null,
           trim($_POST['serial_number']??''), 'active']);
    logAudit('CREATE','assets','asset',(int)$db->lastInsertId(),$anum);
    setFlash('success',"Asset registered: $anum");
    header('Location: index.php'); exit;
}

$page = max(1,(int)($_GET['page']??1));
$cnt  = $db->query("SELECT COUNT(*) FROM assets")->fetchColumn();
$pg   = paginate((int)$cnt,$page);
$rows = $db->prepare("SELECT a.*, d.name AS dept_name, fs.name AS source_name, u.full_name AS officer_name FROM assets a LEFT JOIN departments d ON a.department_id=d.id LEFT JOIN funding_sources fs ON a.funding_source_id=fs.id LEFT JOIN users u ON a.responsible_officer=u.id ORDER BY a.created_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute(); $assets = $rows->fetchAll();
$totalValue = (float)$db->query("SELECT COALESCE(SUM(current_value),0) FROM assets WHERE status='active'")->fetchColumn();

$depts   = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$fSources= $db->query("SELECT * FROM funding_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$allUsers= $db->query("SELECT id, full_name FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();

renderHead('Assets Register');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Assets Register','Council asset register');
renderPageStart('Assets Register','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Assets']
]);
renderPageActions('<button class="btn btn-primary" data-modal="addAssetModal">+ Register Asset</button>');
renderFlashMessages();
?>

<div class="grid-3" style="margin-bottom:1.2rem;">
  <div class="stat-card"><div class="stat-icon">◒</div><div class="stat-info"><div class="label">Total Assets</div><div class="value"><?=number_format($cnt)?></div></div></div>
  <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-info"><div class="label">Total Value</div><div class="value"><?=number_format($totalValue/1000000,1)?>M</div><div class="sub">UGX <?=number_format($totalValue)?></div></div></div>
  <div class="stat-card blue"><div class="stat-icon">✅</div><div class="stat-info"><div class="label">Active Assets</div><div class="value"><?=(int)$db->query("SELECT COUNT(*) FROM assets WHERE status='active'")->fetchColumn()?></div></div></div>
</div>

<div class="card">
  <div class="card-header"><h5><span class="ch-icon">◒</span> Asset Register (<?=number_format($cnt)?>)</h5></div>
  <div class="card-body p-0">
    <?php if ($assets): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Asset No.</th><th>Name</th><th>Department</th><th>Location</th><th>Serial No.</th><th class="text-right">Value (UGX)</th><th>Responsible Officer</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($assets as $a): ?>
        <tr>
          <td class="fw-700 text-primary"><?=htmlspecialchars($a['asset_number'])?></td>
          <td><div class="fw-600"><?=htmlspecialchars($a['name'])?></div><?php if ($a['description']): ?><div class="fs-xs text-muted"><?=htmlspecialchars(substr($a['description'],0,40))?></div><?php endif; ?></td>
          <td class="fs-sm"><?=htmlspecialchars($a['dept_name']??'—')?></td>
          <td class="fs-sm"><?=htmlspecialchars($a['location']??'—')?></td>
          <td class="fs-sm"><?=htmlspecialchars($a['serial_number']??'—')?></td>
          <td class="text-right fw-700"><?=number_format($a['current_value']??0)?></td>
          <td class="fs-sm"><?=htmlspecialchars($a['officer_name']??'—')?></td>
          <td><?=getStatusBadge($a['status'])?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="5" class="text-right">TOTAL VALUE:</th><th class="text-right"><?=number_format($totalValue)?></th><th colspan="2"></th></tr></tfoot>
      </table>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">◒</div><h5>No assets registered</h5></div><?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?=renderPagination($pg,'?')?></div><?php endif; ?>
</div>

<!-- Add Asset Modal -->
<div class="modal-backdrop" id="addAssetModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Register Asset</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="add_asset">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Asset Name / Description <span class="req">*</span></label><input type="text" name="name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Department</label><select name="department_id" class="form-select"><option value="">—</option><?php foreach ($depts as $d): ?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" class="form-control"></div>
          <div class="form-group"><label class="form-label">Serial Number</label><input type="text" name="serial_number" class="form-control"></div>
          <div class="form-group"><label class="form-label">Purchase Date</label><input type="date" name="purchase_date" class="form-control"></div>
          <div class="form-group"><label class="form-label">Purchase Value (UGX)</label><input type="number" name="purchase_value" class="form-control" min="0" step="1"></div>
          <div class="form-group"><label class="form-label">Funding Source</label><select name="funding_source_id" class="form-select"><option value="">—</option><?php foreach ($fSources as $fs): ?><option value="<?=$fs['id']?>"><?=htmlspecialchars($fs['name'])?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label class="form-label">Responsible Officer</label><select name="responsible_officer" class="form-select"><option value="">—</option><?php foreach ($allUsers as $u): ?><option value="<?=$u['id']?>"><?=htmlspecialchars($u['full_name'])?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-group"><label class="form-label">Additional Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Register Asset</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
