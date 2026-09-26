<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if ($_POST['action'] === 'add_project') {
        $code = 'PRJ-'.date('Y').'-'.str_pad($db->query("SELECT COUNT(*)+1 FROM projects")->fetchColumn(),4,'0',STR_PAD_LEFT);
        $db->prepare("INSERT INTO projects (project_code,name,description,location,department_id,funding_source_id,contractor_name,budget_amount,financial_year,start_date,expected_end_date,progress_percent,status,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$code, trim($_POST['name']), trim($_POST['description']??''), trim($_POST['location']??''),
               !empty($_POST['department_id'])?(int)$_POST['department_id']:null,
               !empty($_POST['funding_source_id'])?(int)$_POST['funding_source_id']:null,
               trim($_POST['contractor_name']??''), (float)str_replace(',','',$_POST['budget_amount']),
               trim($_POST['financial_year']??''), $_POST['start_date']??null, $_POST['expected_end_date']??null,
               0, 'planned', $user['id']]);
        logAudit('CREATE','projects','project',(int)$db->lastInsertId(),$code);
        setFlash('success',"Project created: $code");
    } elseif ($_POST['action'] === 'update_progress') {
        $id = (int)$_POST['project_id'];
        $db->prepare("UPDATE projects SET progress_percent=?, amount_spent=?, status=?, updated_at=NOW() WHERE id=?")
           ->execute([(int)$_POST['progress_percent'], (float)str_replace(',','',$_POST['amount_spent']), $_POST['status'], $id]);
        setFlash('success','Project updated.');
    }
    header('Location: index.php'); exit;
}

$statusFil = $_GET['status'] ?? '';
$page = max(1,(int)($_GET['page']??1));
$where = ['1=1']; $params = [];
if ($statusFil) { $where[] = "p.status=?"; $params[] = $statusFil; }
$wSQL = implode(' AND ',$where);
$cnt = $db->prepare("SELECT COUNT(*) FROM projects p WHERE $wSQL"); $cnt->execute($params); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total,$page);
$rows = $db->prepare("SELECT p.*, d.name AS dept_name, fs.name AS source_name FROM projects p LEFT JOIN departments d ON p.department_id=d.id LEFT JOIN funding_sources fs ON p.funding_source_id=fs.id WHERE $wSQL ORDER BY p.created_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $projects = $rows->fetchAll();

$depts   = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$fSources= $db->query("SELECT * FROM funding_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
$statuses= ['planned','active','on_hold','completed','cancelled'];

// Summary
$summary = $db->query("SELECT status, COUNT(*) cnt, SUM(budget_amount) budget, SUM(amount_spent) spent FROM projects GROUP BY status")->fetchAll();

renderHead('Projects');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Project Management','Track council projects and progress');
renderPageStart('Project Management','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Projects']
]);
renderPageActions('<button class="btn btn-primary" data-modal="addProjModal">+ New Project</button>');
renderFlashMessages();
?>

<div class="grid-4" style="margin-bottom:1.2rem;">
  <?php
  $totalProj = array_sum(array_column($summary,'cnt'));
  $activeProj = 0; $totalBudget = 0; $totalSpent = 0;
  foreach ($summary as $s) { if ($s['status']==='active') $activeProj=$s['cnt']; $totalBudget+=$s['budget']; $totalSpent+=$s['spent']; }
  ?>
  <div class="stat-card"><div class="stat-icon">◐</div><div class="stat-info"><div class="label">Total Projects</div><div class="value"><?=$totalProj?></div></div></div>
  <div class="stat-card green"><div class="stat-icon">▶</div><div class="stat-info"><div class="label">Active</div><div class="value"><?=$activeProj?></div></div></div>
  <div class="stat-card amber"><div class="stat-icon">💰</div><div class="stat-info"><div class="label">Total Budget</div><div class="value"><?=number_format($totalBudget/1000000,1)?>M</div></div></div>
  <div class="stat-card red"><div class="stat-icon">📤</div><div class="stat-info"><div class="label">Total Spent</div><div class="value"><?=number_format($totalSpent/1000000,1)?>M</div></div></div>
</div>

<!-- Status filter tabs -->
<div class="tab-nav" style="margin-bottom:1rem;">
  <a href="index.php" class="tab-link <?=$statusFil===''?'active':''?>">All</a>
  <?php foreach ($statuses as $s): ?>
  <a href="?status=<?=$s?>" class="tab-link <?=$statusFil===$s?'active':''?>"><?=ucfirst(str_replace('_',' ',$s))?></a>
  <?php endforeach; ?>
</div>

<div class="grid-2">
<?php foreach ($projects as $proj):
  $ppct = min(100,(int)$proj['progress_percent']);
  $spentPct = $proj['budget_amount']>0 ? min(100,round($proj['amount_spent']/$proj['budget_amount']*100,1)) : 0;
?>
<div class="card">
  <div class="card-header">
    <h5 style="font-size:.9rem;"><?=htmlspecialchars($proj['project_code'])?></h5>
    <?=getStatusBadge($proj['status'])?>
  </div>
  <div class="card-body">
    <div class="fw-700" style="font-size:1rem;margin-bottom:.5rem;"><?=htmlspecialchars($proj['name'])?></div>
    <?php if ($proj['description']): ?><p class="fs-sm text-muted" style="margin-bottom:.7rem;"><?=htmlspecialchars(substr($proj['description'],0,100))?></p><?php endif; ?>
    <div class="grid-2" style="gap:.4rem;font-size:.8rem;margin-bottom:.8rem;">
      <div><span class="text-muted">Department:</span> <strong><?=htmlspecialchars($proj['dept_name']??'—')?></strong></div>
      <div><span class="text-muted">Funding:</span> <strong><?=htmlspecialchars($proj['source_name']??'—')?></strong></div>
      <div><span class="text-muted">Contractor:</span> <strong><?=htmlspecialchars($proj['contractor_name']??'—')?></strong></div>
      <div><span class="text-muted">FY:</span> <strong><?=htmlspecialchars($proj['financial_year']??'—')?></strong></div>
      <?php if ($proj['start_date']): ?><div><span class="text-muted">Start:</span> <strong><?=formatDate($proj['start_date'])?></strong></div><?php endif; ?>
      <?php if ($proj['expected_end_date']): ?><div><span class="text-muted">Expected End:</span> <strong><?=formatDate($proj['expected_end_date'])?></strong></div><?php endif; ?>
    </div>
    <div style="background:#f8f9fa;border-radius:6px;padding:.7rem;margin-bottom:.8rem;">
      <div class="grid-2" style="gap:.3rem;font-size:.8rem;">
        <div><div class="fs-xs text-muted">Budget</div><div class="fw-700"><?=number_format($proj['budget_amount'])?></div></div>
        <div><div class="fs-xs text-muted">Spent</div><div class="fw-700"><?=number_format($proj['amount_spent'])?></div></div>
      </div>
    </div>
    <div style="margin-bottom:.3rem;">
      <div style="display:flex;justify-content:space-between;font-size:.78rem;margin-bottom:.2rem;"><span>Project Progress</span><span class="fw-700"><?=$ppct?>%</span></div>
      <div class="progress" style="height:8px;"><div class="progress-bar <?=$ppct>=75?'bg-success':($ppct>=40?'bg-primary':'bg-warning')?>" style="width:<?=$ppct?>%"></div></div>
    </div>
    <div style="display:flex;justify-content:space-between;margin-top:.8rem;">
      <a href="<?=APP_URL?>/modules/documents/index.php?related_module=project&related_id=<?=$proj['id']?>" class="btn btn-sm btn-outline-secondary">Documents</a>
      <button class="btn btn-sm btn-outline-primary" onclick="updateProj(<?=htmlspecialchars(json_encode($proj))?>)">Update Progress</button>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if (!$projects): ?><div class="empty-state" style="grid-column:span 2;"><div class="empty-icon">◐</div><h5>No projects found</h5><p>Add a project using the button above.</p></div><?php endif; ?>
</div>

<!-- Add Project Modal -->
<div class="modal-backdrop" id="addProjModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Add New Project</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="add_project">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Project Name <span class="req">*</span></label><input type="text" name="name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Department</label><select name="department_id" class="form-select"><option value="">—</option><?php foreach ($depts as $d): ?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label class="form-label">Funding Source</label><select name="funding_source_id" class="form-select"><option value="">—</option><?php foreach ($fSources as $fs): ?><option value="<?=$fs['id']?>"><?=htmlspecialchars($fs['name'])?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label class="form-label">Budget (UGX) <span class="req">*</span></label><input type="number" name="budget_amount" class="form-control" required min="0" step="1"></div>
          <div class="form-group"><label class="form-label">Financial Year</label><select name="financial_year" class="form-select"><?php foreach ($fyears as $y): ?><option value="<?=$y?>"><?=$y?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label class="form-label">Contractor Name</label><input type="text" name="contractor_name" class="form-control"></div>
          <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" class="form-control"></div>
          <div class="form-group"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control"></div>
          <div class="form-group"><label class="form-label">Expected End Date</label><input type="date" name="expected_end_date" class="form-control"></div>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Create Project</button>
      </div>
    </form>
  </div>
</div>

<!-- Update Progress Modal -->
<div class="modal-backdrop" id="updateProjModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Update Project Progress</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="update_progress">
      <input type="hidden" name="project_id" id="up_id">
      <div class="modal-body">
        <div class="fw-700 fs-sm" id="up_name" style="margin-bottom:1rem;"></div>
        <div class="form-group"><label class="form-label">Progress % (0–100)</label><input type="number" name="progress_percent" id="up_pct" class="form-control" min="0" max="100"></div>
        <div class="form-group"><label class="form-label">Amount Spent (UGX)</label><input type="number" name="amount_spent" id="up_spent" class="form-control" min="0"></div>
        <div class="form-group"><label class="form-label">Status</label>
          <select name="status" id="up_status" class="form-select">
            <?php foreach ($statuses as $s): ?><option value="<?=$s?>"><?=ucfirst(str_replace('_',' ',$s))?></option><?php endforeach; ?>
          </select>
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
function updateProj(p) {
  document.getElementById('up_id').value      = p.id;
  document.getElementById('up_name').textContent = p.name;
  document.getElementById('up_pct').value     = p.progress_percent;
  document.getElementById('up_spent').value   = p.amount_spent;
  document.getElementById('up_status').value  = p.status;
  openModal('updateProjModal');
}
</script>
<?php renderFooter(); ?>
