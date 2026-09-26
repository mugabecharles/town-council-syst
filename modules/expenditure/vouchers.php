<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_voucher') {
        $vnum = generateVoucherNumber();
        $db->prepare("INSERT INTO payment_vouchers
            (voucher_number,financial_year,department_id,budget_id,funding_source_id,payee_name,payee_contact,payee_account,description,amount,account_code,status,prepared_by,prepared_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $vnum, $fy, (int)$_POST['department_id'],
               !empty($_POST['budget_id'])?(int)$_POST['budget_id']:null,
               !empty($_POST['funding_source_id'])?(int)$_POST['funding_source_id']:null,
               trim($_POST['payee_name']), trim($_POST['payee_contact'] ?? ''),
               trim($_POST['payee_account'] ?? ''), trim($_POST['description']),
               (float)str_replace(',','', $_POST['amount']),
               trim($_POST['account_code'] ?? ''), 'draft', $user['id'], date('Y-m-d')
           ]);
        $vid = $db->lastInsertId();
        logAudit('CREATE','expenditure','voucher',(int)$vid,$vnum);
        setFlash('success',"Voucher created: {$vnum}");
        header('Location: voucher_view.php?id='.$vid); exit;
    } elseif ($action === 'submit_voucher') {
        $id = (int)$_POST['voucher_id'];
        $db->prepare("UPDATE payment_vouchers SET status='submitted' WHERE id=? AND status='draft'")->execute([$id]);
        logAudit('SUBMIT','expenditure','voucher',$id,'');
        setFlash('success','Voucher submitted for approval.');
    }
    header('Location: vouchers.php'); exit;
}

$statusFil = $_GET['status'] ?? '';
$deptFil   = (int)($_GET['dept_id'] ?? 0);
$fyFil     = $_GET['fy'] ?? $fy;
$search    = trim($_GET['search'] ?? '');
$page      = max(1,(int)($_GET['page'] ?? 1));

// HOD only sees own department
$where = ['1=1']; $params = [];
if (hasRole(['hod']) && $user['department_id']) { $where[] = "pv.department_id=?"; $params[] = $user['department_id']; }
elseif ($deptFil) { $where[] = "pv.department_id=?"; $params[] = $deptFil; }
if ($statusFil) { $where[] = "pv.status=?"; $params[] = $statusFil; }
$where[] = "pv.financial_year=?"; $params[] = $fyFil;
if ($search) { $where[] = "(pv.voucher_number LIKE ? OR pv.payee_name LIKE ?)"; $params = array_merge($params,["%$search%","%$search%"]); }
$wSQL = implode(' AND ',$where);

$cnt = $db->prepare("SELECT COUNT(*) FROM payment_vouchers pv WHERE $wSQL"); $cnt->execute($params); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total,$page);

$rows = $db->prepare("SELECT pv.*, d.name AS dept_name, u.full_name AS preparer
    FROM payment_vouchers pv JOIN departments d ON pv.department_id=d.id JOIN users u ON pv.prepared_by=u.id
    WHERE $wSQL ORDER BY pv.created_at DESC
    LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $vouchers = $rows->fetchAll();

$sumStmt = $db->prepare("SELECT COALESCE(SUM(pv.amount),0) FROM payment_vouchers pv WHERE $wSQL"); $sumStmt->execute($params); $grandTotal=(float)$sumStmt->fetchColumn();

$depts   = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$budgets = $db->query("SELECT b.*, d.name AS dept_name FROM budgets b JOIN departments d ON b.department_id=d.id WHERE b.status IN ('approved','active') AND b.financial_year='$fy' ORDER BY d.name")->fetchAll();
$fSources= $db->query("SELECT * FROM funding_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

$statuses = ['draft','submitted','hod_approved','tc_approved','finance_verified','finance_cleared','paid','completed','rejected','returned','cancelled'];

renderHead('Payment Vouchers');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Payment Vouchers','Manage expenditure vouchers');
renderPageStart('Payment Vouchers','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Payment Vouchers']
]);
renderPageActions('<button class="btn btn-primary" data-modal="createVoucherModal">+ Create Voucher</button>
  <a href="'.APP_URL.'/modules/expenditure/approvals.php" class="btn btn-outline-secondary">Pending Approvals</a>');
renderFlashMessages();
?>

<form method="GET">
<div class="filter-row">
  <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" value="<?=htmlspecialchars($search)?>" placeholder="Voucher No / Payee..."></div>
  <div class="form-group"><label>Status</label>
    <select name="status" class="form-select"><option value="">All</option>
      <?php foreach ($statuses as $s): ?><option value="<?=$s?>" <?=$statusFil===$s?'selected':''?>><?=ucwords(str_replace('_',' ',$s))?></option><?php endforeach; ?>
    </select>
  </div>
  <?php if (!hasRole(['hod'])): ?>
  <div class="form-group"><label>Department</label>
    <select name="dept_id" class="form-select"><option value="">All</option>
      <?php foreach ($depts as $d): ?><option value="<?=$d['id']?>" <?=$deptFil==$d['id']?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div class="form-group"><label>FY</label>
    <select name="fy" class="form-select"><?php foreach ($fyears as $y): ?><option value="<?=$y?>" <?=$y===$fyFil?'selected':''?>><?=$y?></option><?php endforeach; ?></select>
  </div>
  <div class="form-group"><label>&nbsp;</label><div style="display:flex;gap:.4rem;"><button type="submit" class="btn btn-primary">Filter</button><a href="vouchers.php" class="btn btn-outline-secondary">Reset</a></div></div>
</div>
</form>

<div class="card">
  <div class="card-header">
    <h5><span class="ch-icon">▤</span> Payment Vouchers <span style="font-weight:400;font-size:.82rem;color:#6c757d;">(<?=number_format($total)?> records | Total: UGX <?=number_format($grandTotal)?>)</span></h5>
  </div>
  <div class="card-body p-0">
    <?php if ($vouchers): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>#</th><th>Voucher No.</th><th>Department</th><th>Payee</th><th>Description</th><th class="text-right">Amount (UGX)</th><th>Date</th><th>Prepared By</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($vouchers as $i => $v): ?>
        <tr>
          <td class="fs-xs text-muted"><?=$pg['offset']+$i+1?></td>
          <td><a href="voucher_view.php?id=<?=$v['id']?>" class="fw-700 text-primary"><?=htmlspecialchars($v['voucher_number'])?></a></td>
          <td class="fs-sm"><?=htmlspecialchars($v['dept_name'])?></td>
          <td class="fw-600"><?=htmlspecialchars(substr($v['payee_name'],0,25))?></td>
          <td class="fs-sm text-muted"><?=htmlspecialchars(substr($v['description'],0,30))?>...</td>
          <td class="text-right fw-700"><?=number_format($v['amount'])?></td>
          <td class="fs-sm"><?=formatDate($v['prepared_date'])?></td>
          <td class="fs-sm"><?=htmlspecialchars(substr($v['preparer'],0,20))?></td>
          <td><?=getStatusBadge($v['status'])?></td>
          <td>
            <div style="display:flex;gap:.3rem;">
              <a href="voucher_view.php?id=<?=$v['id']?>" class="btn btn-sm btn-outline-primary">View</a>
              <?php if ($v['status']==='draft' && ($v['prepared_by']==$user['id'] || hasRole(['admin']))): ?>
              <form method="POST" style="display:inline;">
                <?=csrfField()?>
                <input type="hidden" name="action" value="submit_voucher">
                <input type="hidden" name="voucher_id" value="<?=$v['id']?>">
                <button type="submit" class="btn btn-sm btn-success" data-confirm="Submit this voucher for approval?">Submit</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="5" class="text-right">TOTAL:</th><th class="text-right"><?=number_format($grandTotal)?></th><th colspan="4"></th></tr></tfoot>
      </table>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">▤</div><h5>No vouchers found</h5><p>Create a payment voucher using the button above.</p></div><?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?=renderPagination($pg,'?status='.urlencode($statusFil).'&dept_id='.$deptFil.'&fy='.urlencode($fyFil).'&search='.urlencode($search))?></div><?php endif; ?>
</div>

<!-- Create Voucher Modal -->
<div class="modal-backdrop" id="createVoucherModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Create Payment Voucher</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="create_voucher">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Department <span class="req">*</span></label>
            <select name="department_id" class="form-select" required>
              <option value="">-- Select Department --</option>
              <?php foreach ($depts as $d): ?>
              <option value="<?=$d['id']?>" <?=($user['department_id']==$d['id'])?'selected':''?>><?=htmlspecialchars($d['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Budget Line</label>
            <select name="budget_id" class="form-select">
              <option value="">-- Select Budget Line --</option>
              <?php foreach ($budgets as $b): ?><option value="<?=$b['id']?>"><?=htmlspecialchars($b['dept_name'].' — '.$b['budget_category'].' (UGX '.number_format($b['approved_amount']).')')?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Payee Name <span class="req">*</span></label>
            <input type="text" name="payee_name" class="form-control" required placeholder="Name of payee/supplier">
          </div>
          <div class="form-group">
            <label class="form-label">Payee Contact / Account</label>
            <input type="text" name="payee_contact" class="form-control" placeholder="Phone / Account number">
          </div>
          <div class="form-group">
            <label class="form-label">Amount (UGX) <span class="req">*</span></label>
            <input type="number" name="amount" class="form-control" required min="1" step="1">
          </div>
          <div class="form-group">
            <label class="form-label">Funding Source</label>
            <select name="funding_source_id" class="form-select">
              <option value="">-- Select Source --</option>
              <?php foreach ($fSources as $fs): ?><option value="<?=$fs['id']?>"><?=htmlspecialchars($fs['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Account Code</label>
            <input type="text" name="account_code" class="form-control">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Description / Purpose <span class="req">*</span></label>
          <textarea name="description" class="form-control" rows="3" required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Create Voucher</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
