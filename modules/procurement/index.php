<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if ($_POST['action'] === 'add_request') {
        $rnum = 'PRQ-'.date('Y').'-'.str_pad($db->query("SELECT COUNT(*)+1 FROM procurement_requests")->fetchColumn(),4,'0',STR_PAD_LEFT);
        $db->prepare("INSERT INTO procurement_requests (request_number,department_id,requested_by,financial_year,title,description,estimated_amount,status,required_date)
            VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$rnum, (int)$_POST['department_id'], $user['id'],
               getSystemSetting('current_financial_year'), trim($_POST['title']),
               trim($_POST['description']??''), (float)str_replace(',','',$_POST['estimated_amount']??0),
               'draft', $_POST['required_date']??null]);
        logAudit('CREATE','procurement','request',(int)$db->lastInsertId(),$rnum);
        setFlash('success',"Procurement request created: $rnum");
    }
    header('Location: index.php'); exit;
}

$page = max(1,(int)($_GET['page']??1));
$where = ['1=1']; $params = [];
if (hasRole(['hod']) && !hasRole(['admin'])) { $where[] = "pr.department_id=?"; $params[] = $user['department_id']; }
$wSQL = implode(' AND ',$where);
$cnt = $db->prepare("SELECT COUNT(*) FROM procurement_requests pr WHERE $wSQL"); $cnt->execute($params); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total,$page);
$rows = $db->prepare("SELECT pr.*, d.name AS dept_name FROM procurement_requests pr JOIN departments d ON pr.department_id=d.id WHERE $wSQL ORDER BY pr.created_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $requests = $rows->fetchAll();

$depts   = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();

renderHead('Procurement');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Procurement','Manage procurement requests and orders');
renderPageStart('Procurement','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Procurement']
]);
renderPageActions('<button class="btn btn-primary" data-modal="addPRModal">+ New Request</button>');
renderFlashMessages();
?>

<div class="card">
  <div class="card-header"><h5><span class="ch-icon">◑</span> Procurement Requests (<?=number_format($total)?>)</h5></div>
  <div class="card-body p-0">
    <?php if ($requests): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>#</th><th>Request No.</th><th>Title</th><th>Department</th><th class="text-right">Est. Amount</th><th>Required By</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($requests as $i => $r): ?>
        <tr>
          <td class="fs-xs text-muted"><?=$pg['offset']+$i+1?></td>
          <td class="fw-700 text-primary"><?=htmlspecialchars($r['request_number'])?></td>
          <td class="fw-600"><?=htmlspecialchars($r['title'])?></td>
          <td class="fs-sm"><?=htmlspecialchars($r['dept_name'])?></td>
          <td class="text-right fw-700"><?=number_format($r['estimated_amount'])?></td>
          <td class="fs-sm"><?=$r['required_date']?formatDate($r['required_date']):'—'?></td>
          <td><?=getStatusBadge($r['status'])?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">◑</div><h5>No procurement requests</h5><p>Create a new request using the button above.</p></div><?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?=renderPagination($pg,'?')?></div><?php endif; ?>
</div>

<!-- Add PR Modal -->
<div class="modal-backdrop" id="addPRModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>New Procurement Request</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="add_request">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Title / Item Description <span class="req">*</span></label><input type="text" name="title" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Department <span class="req">*</span></label>
            <select name="department_id" class="form-select" required><option value="">—</option><?php foreach ($depts as $d): ?><option value="<?=$d['id']?>" <?=$user['department_id']==$d['id']?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select>
          </div>
          <div class="form-group"><label class="form-label">Estimated Amount (UGX)</label><input type="number" name="estimated_amount" class="form-control" min="0" step="1"></div>
          <div class="form-group"><label class="form-label">Required Date</label><input type="date" name="required_date" class="form-control"></div>
        </div>
        <div class="form-group"><label class="form-label">Specifications / Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Submit Request</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
