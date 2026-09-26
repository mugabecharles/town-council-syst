<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'add_budget') {
        $bcode = 'BDG-'.$_POST['financial_year'].'-'.str_pad($db->query("SELECT COUNT(*)+1 FROM budgets")->fetchColumn(),4,'0',STR_PAD_LEFT);
        $db->prepare("INSERT INTO budgets (budget_code,financial_year,department_id,funding_source_id,budget_category,description,approved_amount,status,created_by)
            VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([
               $bcode, $_POST['financial_year'], (int)$_POST['department_id'],
               !empty($_POST['funding_source_id'])?(int)$_POST['funding_source_id']:null,
               trim($_POST['budget_category'] ?? ''), trim($_POST['description'] ?? ''),
               (float)str_replace(',','', $_POST['approved_amount']),
               'draft', $user['id']
           ]);
        logAudit('CREATE','budget','budget',(int)$db->lastInsertId(),$bcode);
        setFlash('success',"Budget created: {$bcode}");
    } elseif ($action === 'approve_budget') {
        $id = (int)$_POST['budget_id'];
        if (hasRole(['admin','town_clerk','finance_officer'])) {
            $db->prepare("UPDATE budgets SET status='approved', approved_by=?, approved_date=CURDATE() WHERE id=?")->execute([$user['id'],$id]);
            logAudit('APPROVE','budget','budget',$id,'');
            setFlash('success','Budget approved.');
        }
    }
    header('Location: index.php?fy='.urlencode($fy)); exit;
}

$cnt = $db->prepare("SELECT COUNT(*) FROM budgets WHERE financial_year=?"); $cnt->execute([$fy]); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total, max(1,(int)($_GET['page'] ?? 1)));

$rows = $db->prepare("SELECT b.*, d.name AS dept_name, fs.name AS source_name,
    COALESCE(b.revised_amount, b.approved_amount) AS effective_budget,
    b.spent_amount, (COALESCE(b.revised_amount,b.approved_amount) - b.spent_amount) AS balance_amount
    FROM budgets b JOIN departments d ON b.department_id=d.id
    LEFT JOIN funding_sources fs ON b.funding_source_id=fs.id
    WHERE b.financial_year=? ORDER BY d.name, b.budget_category
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute([$fy]); $budgets = $rows->fetchAll();

$sums = $db->prepare("SELECT COALESCE(SUM(COALESCE(revised_amount,approved_amount)),0) total_budget, COALESCE(SUM(spent_amount),0) total_spent FROM budgets WHERE financial_year=?");
$sums->execute([$fy]); $sums = $sums->fetch();
$totalBudget = (float)$sums['total_budget']; $totalSpent = (float)$sums['total_spent'];

$depts   = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$fSources= $db->query("SELECT * FROM funding_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

// Dept budget summary chart
$deptChart = $db->prepare("SELECT d.name, COALESCE(SUM(COALESCE(b.revised_amount,b.approved_amount)),0) AS budget, COALESCE(SUM(b.spent_amount),0) AS spent
    FROM departments d LEFT JOIN budgets b ON b.department_id=d.id AND b.financial_year=? GROUP BY d.id ORDER BY budget DESC");
$deptChart->execute([$fy]); $deptChart = $deptChart->fetchAll();

renderHead('Budget Management');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Budget Management','Department budgets and utilization');
renderPageStart('Budget Management','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Budgets']
]);
$fyOpts=''; foreach ($fyears as $y) $fyOpts.="<option value='$y'".($y===$fy?' selected':'').">$y</option>";
renderPageActions("
  <form method='GET' style='display:flex;gap:.5rem;align-items:center;'>
    <select name='fy' class='form-select' onchange='this.form.submit()' style='width:130px;font-size:.84rem;'>$fyOpts</select>
  </form>
  <button class='btn btn-primary' data-modal='addBudgetModal'>+ Create Budget</button>
");
renderFlashMessages();
?>

<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card"><div class="stat-icon">▣</div><div class="stat-info"><div class="label">Total Budget</div><div class="value"><?= number_format($totalBudget/1000000,1)?>M</div><div class="sub">UGX <?= number_format($totalBudget) ?></div></div></div>
  <div class="stat-card red"><div class="stat-icon">📤</div><div class="stat-info"><div class="label">Total Spent</div><div class="value"><?= number_format($totalSpent/1000000,1)?>M</div><div class="sub"><?= $totalBudget>0?round($totalSpent/$totalBudget*100,1):0 ?>% utilized</div></div></div>
  <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-info"><div class="label">Balance</div><div class="value"><?= number_format(($totalBudget-$totalSpent)/1000000,1)?>M</div><div class="sub">Available</div></div></div>
  <div class="stat-card blue"><div class="stat-icon">◉</div><div class="stat-info"><div class="label">Budget Lines</div><div class="value"><?= number_format($total) ?></div><div class="sub">FY <?= $fy ?></div></div></div>
</div>

<!-- Overall Progress -->
<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-header"><h5><span class="ch-icon">▣</span> Overall Budget Utilization — FY <?= $fy ?></h5></div>
  <div class="card-body">
    <?php $utilPct = $totalBudget>0 ? round($totalSpent/$totalBudget*100,1) : 0; ?>
    <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
      <span class="fw-600 fs-sm">UGX <?= number_format($totalSpent) ?> spent of UGX <?= number_format($totalBudget) ?> budgeted</span>
      <span class="fw-700 <?= $utilPct>=90?'text-danger':($utilPct>=70?'text-warning':'text-success') ?>"><?= $utilPct ?>%</span>
    </div>
    <div class="progress" style="height:14px;">
      <div class="progress-bar <?= $utilPct>=90?'bg-danger':($utilPct>=70?'bg-warning':'bg-success') ?>" style="width:<?= min(100,$utilPct) ?>%"></div>
    </div>
    <div style="display:flex;justify-content:space-between;margin-top:.5rem;font-size:.76rem;color:#6c757d;">
      <span>Spent: UGX <?= number_format($totalSpent) ?></span>
      <span>Balance: UGX <?= number_format($totalBudget-$totalSpent) ?></span>
    </div>
  </div>
</div>

<!-- Chart + Table split -->
<div class="grid-3" style="margin-bottom:1.2rem;">
  <div class="card" style="grid-column:span 2;">
    <div class="card-header"><h5><span class="ch-icon">▦</span> Budget vs Expenditure by Department</h5></div>
    <div class="card-body"><div class="chart-container"><canvas id="deptBudgetChart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◉</span> Quick Stats</h5></div>
    <div class="card-body">
      <?php foreach ($deptChart as $dc):
        $dpct = $dc['budget']>0 ? round($dc['spent']/$dc['budget']*100,1) : 0;
        if ($dc['budget']<=0) continue; ?>
      <div style="margin-bottom:.9rem;">
        <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.2rem;">
          <span class="fw-600"><?= htmlspecialchars(substr($dc['name'],0,20)) ?></span>
          <span class="<?= $dpct>=90?'text-danger':($dpct>=70?'text-warning':'text-success') ?> fw-700"><?= $dpct ?>%</span>
        </div>
        <div class="progress" style="height:6px;"><div class="progress-bar <?= $dpct>=90?'bg-danger':($dpct>=70?'bg-warning':'bg-success') ?>" style="width:<?= min(100,$dpct) ?>%"></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Budget Lines Table -->
<div class="card">
  <div class="card-header"><h5><span class="ch-icon">▤</span> Budget Lines</h5></div>
  <div class="card-body p-0">
    <?php if ($budgets): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Code</th><th>Department</th><th>Category</th><th>Funding Source</th><th>FY</th><th class="text-right">Approved</th><th class="text-right">Spent</th><th class="text-right">Balance</th><th>Util%</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($budgets as $b):
          $bpct = $b['effective_budget']>0 ? round($b['spent_amount']/$b['effective_budget']*100,1) : 0; ?>
        <tr>
          <td class="fw-700 text-primary fs-sm"><?= htmlspecialchars($b['budget_code']) ?></td>
          <td class="fw-600"><?= htmlspecialchars($b['dept_name']) ?></td>
          <td class="fs-sm"><?= htmlspecialchars($b['budget_category'] ?? '—') ?></td>
          <td class="fs-sm"><?= htmlspecialchars($b['source_name'] ?? '—') ?></td>
          <td class="fs-sm"><?= $b['financial_year'] ?></td>
          <td class="text-right fw-600"><?= number_format($b['effective_budget']) ?></td>
          <td class="text-right"><?= number_format($b['spent_amount']) ?></td>
          <td class="text-right fw-700 <?= $b['balance_amount']<0?'text-danger':'text-success' ?>"><?= number_format($b['balance_amount']) ?></td>
          <td><span class="<?= $bpct>=90?'text-danger fw-700':($bpct>=70?'text-warning fw-600':'text-success') ?>"><?= $bpct ?>%</span></td>
          <td><?= getStatusBadge($b['status']) ?></td>
          <td>
            <?php if ($b['status']==='draft' && hasRole(['admin','town_clerk','finance_officer'])): ?>
            <form method="POST" style="display:inline;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="approve_budget">
              <input type="hidden" name="budget_id" value="<?= $b['id'] ?>">
              <button type="submit" class="btn btn-sm btn-success">Approve</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="5" class="text-right">TOTALS:</th><th class="text-right"><?= number_format($totalBudget) ?></th><th class="text-right"><?= number_format($totalSpent) ?></th><th class="text-right fw-700"><?= number_format($totalBudget-$totalSpent) ?></th><th colspan="3"></th></tr></tfoot>
      </table>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">▣</div><h5>No budgets for <?= $fy ?></h5><p>Create a budget using the button above.</p></div><?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?= renderPagination($pg,'?fy='.urlencode($fy)) ?></div><?php endif; ?>
</div>

<!-- Add Budget Modal -->
<div class="modal-backdrop" id="addBudgetModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Create Department Budget</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_budget">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Department <span class="req">*</span></label>
            <select name="department_id" class="form-select" required>
              <option value="">-- Select Department --</option>
              <?php foreach ($depts as $d): ?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Financial Year <span class="req">*</span></label>
            <select name="financial_year" class="form-select" required>
              <?php foreach ($fyears as $y): ?><option value="<?=$y?>" <?=$y===$fy?'selected':''?>><?=$y?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Budget Category</label>
            <input type="text" name="budget_category" class="form-control" placeholder="e.g. Wages, Operations, Development">
          </div>
          <div class="form-group">
            <label class="form-label">Funding Source</label>
            <select name="funding_source_id" class="form-select">
              <option value="">-- Select Source --</option>
              <?php foreach ($fSources as $fs): ?><option value="<?=$fs['id']?>"><?=htmlspecialchars($fs['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Approved Amount (UGX) <span class="req">*</span></label>
            <input type="number" name="approved_amount" class="form-control" required min="0" step="1">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Create Budget</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>';
$dLabels = json_encode(array_column($deptChart,'name'));
$dBudget = json_encode(array_column($deptChart,'budget'));
$dSpent  = json_encode(array_column($deptChart,'spent'));
?>
<script>
document.addEventListener('DOMContentLoaded',function(){
  makeBarChart('deptBudgetChart',<?=$dLabels?>,[
    {label:'Budget',data:<?=$dBudget?>,backgroundColor:'rgba(26,58,92,.7)',borderRadius:3},
    {label:'Spent', data:<?=$dSpent?>, backgroundColor:'rgba(200,168,75,.8)',borderRadius:3}
  ]);
});
</script>
<?php renderFooter(); ?>
