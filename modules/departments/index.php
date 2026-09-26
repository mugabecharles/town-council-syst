<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_dept' && hasRole(['admin','town_clerk'])) {
        $db->prepare("INSERT INTO departments (dept_code,name,description,head_user_id,is_active) VALUES (?,?,?,?,1)")
           ->execute([strtoupper(trim($_POST['dept_code'])), trim($_POST['name']), trim($_POST['description'] ?? ''), !empty($_POST['head_user_id'])?(int)$_POST['head_user_id']:null]);
        setFlash('success','Department added.');
    }
    header('Location: index.php'); exit;
}

$depts = $db->query("SELECT d.*, u.full_name AS hod_name FROM departments d LEFT JOIN users u ON d.head_user_id=u.id ORDER BY d.name")->fetchAll();

// Per-department stats
$deptStats = [];
foreach ($depts as $dept) {
    $budgetStmt = $db->prepare("SELECT COALESCE(SUM(COALESCE(revised_amount,approved_amount)),0) AS budget, COALESCE(SUM(spent_amount),0) AS spent FROM budgets WHERE department_id=? AND financial_year=?");
    $budgetStmt->execute([$dept['id'], $fy]);
    $bs = $budgetStmt->fetch();
    $vStmt = $db->prepare("SELECT COUNT(*) FROM payment_vouchers WHERE department_id=? AND financial_year=? AND status NOT IN ('rejected','cancelled')");
    $vStmt->execute([$dept['id'], $fy]);
    $deptStats[$dept['id']] = ['budget'=>(float)$bs['budget'], 'spent'=>(float)$bs['spent'], 'vouchers'=>(int)$vStmt->fetchColumn()];
}

$allUsers = $db->query("SELECT id, full_name, designation FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();

renderHead('Departments');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Department Management','View and manage council departments');
renderPageStart('Department Management','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Departments']
]);
renderPageActions(hasRole(['admin','town_clerk']) ? '<button class="btn btn-primary" data-modal="addDeptModal">+ Add Department</button>' : '');
renderFlashMessages();
?>

<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card"><div class="stat-icon">▦</div><div class="stat-info"><div class="label">Departments</div><div class="value"><?= count($depts) ?></div></div></div>
  <?php
  $totalBudget = array_sum(array_column($deptStats,'budget'));
  $totalSpent  = array_sum(array_column($deptStats,'spent'));
  ?>
  <div class="stat-card amber"><div class="stat-icon">▣</div><div class="stat-info"><div class="label">Total Budget</div><div class="value"><?=number_format($totalBudget/1000000,1)?>M</div><div class="sub">FY <?=$fy?></div></div></div>
  <div class="stat-card red"><div class="stat-icon">📤</div><div class="stat-info"><div class="label">Total Spent</div><div class="value"><?=number_format($totalSpent/1000000,1)?>M</div><div class="sub"><?=$totalBudget>0?round($totalSpent/$totalBudget*100,1):0?>%</div></div></div>
  <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-info"><div class="label">Balance</div><div class="value"><?=number_format(($totalBudget-$totalSpent)/1000000,1)?>M</div></div></div>
</div>

<div class="grid-3">
  <?php foreach ($depts as $d):
    $st  = $deptStats[$d['id']];
    $pct = $st['budget']>0 ? round($st['spent']/$st['budget']*100,1) : 0;
  ?>
  <div class="card" style="border-top:3px solid var(--primary);">
    <div class="card-body">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.8rem;">
        <div>
          <div class="fw-700" style="font-size:1rem;color:var(--primary);"><?=htmlspecialchars($d['name'])?></div>
          <div class="fs-xs text-muted"><?=htmlspecialchars($d['dept_code'])?></div>
        </div>
        <?=getStatusBadge($d['is_active']?'active':'inactive')?>
      </div>
      <?php if ($d['hod_name']): ?>
      <div class="fs-sm" style="margin-bottom:.8rem;">
        <span class="text-muted">HOD:</span> <strong><?=htmlspecialchars($d['hod_name'])?></strong>
      </div>
      <?php endif; ?>
      <?php if ($d['description']): ?>
      <p class="fs-sm text-muted" style="margin-bottom:.8rem;"><?=htmlspecialchars(substr($d['description'],0,80))?></p>
      <?php endif; ?>
      <div style="background:#f8f9fa;border-radius:6px;padding:.7rem;margin-bottom:.8rem;">
        <div class="grid-2" style="gap:.3rem;">
          <div><div class="fs-xs text-muted">Budget</div><div class="fw-700 fs-sm"><?=number_format($st['budget'])?></div></div>
          <div><div class="fs-xs text-muted">Spent</div><div class="fw-700 fs-sm"><?=number_format($st['spent'])?></div></div>
          <div><div class="fs-xs text-muted">Balance</div><div class="fw-700 fs-sm <?=$st['budget']-$st['spent']>0?'text-success':'text-danger'?>"><?=number_format($st['budget']-$st['spent'])?></div></div>
          <div><div class="fs-xs text-muted">Vouchers</div><div class="fw-700 fs-sm"><?=$st['vouchers']?></div></div>
        </div>
        <?php if ($st['budget']>0): ?>
        <div style="margin-top:.6rem;">
          <div style="display:flex;justify-content:space-between;font-size:.72rem;margin-bottom:.2rem;"><span>Utilization</span><span class="fw-700"><?=$pct?>%</span></div>
          <div class="progress" style="height:6px;"><div class="progress-bar <?=$pct>=90?'bg-danger':($pct>=70?'bg-warning':'bg-success')?>" style="width:<?=min(100,$pct)?>%"></div></div>
        </div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:.4rem;">
        <a href="<?=APP_URL?>/modules/budget/index.php?dept=<?=$d['id']?>" class="btn btn-sm btn-outline-primary">Budget</a>
        <a href="<?=APP_URL?>/modules/expenditure/vouchers.php?dept_id=<?=$d['id']?>" class="btn btn-sm btn-outline-secondary">Vouchers</a>
        <a href="<?=APP_URL?>/modules/documents/index.php?dept_id=<?=$d['id']?>" class="btn btn-sm btn-outline-secondary">Docs</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Add Department Modal -->
<div class="modal-backdrop" id="addDeptModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Add Department</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="add_dept">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Department Code <span class="req">*</span></label><input type="text" name="dept_code" class="form-control" required style="text-transform:uppercase;" maxlength="10" placeholder="e.g. WORKS"></div>
        <div class="form-group"><label class="form-label">Department Name <span class="req">*</span></label><input type="text" name="name" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Head of Department</label>
          <select name="head_user_id" class="form-select"><option value="">-- Select HOD --</option>
            <?php foreach ($allUsers as $u): ?><option value="<?=$u['id']?>"><?=htmlspecialchars($u['full_name'].($u['designation']?' — '.$u['designation']:''))?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Add Department</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
