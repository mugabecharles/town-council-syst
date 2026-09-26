<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

// Determine which statuses this role can act on
$pendingStatuses = [];
if (hasRole(['admin','hod']))            $pendingStatuses[] = 'submitted';
if (hasRole(['admin','town_clerk']))     $pendingStatuses[] = 'hod_approved';
if (hasRole(['admin','finance_officer'])) {
    $pendingStatuses[] = 'tc_approved';
    $pendingStatuses[] = 'finance_verified';
    $pendingStatuses[] = 'finance_cleared';
}

$pendingIn = $pendingStatuses ? "'" . implode("','",$pendingStatuses) . "'" : "'none'";

$where = ["pv.status IN ($pendingIn)"]; $params = [];
if (hasRole(['hod']) && !hasRole(['admin'])) {
    $where[] = "pv.department_id=?"; $params[] = $user['department_id'];
}
$wSQL = implode(' AND ', $where);

$rows = $db->prepare("SELECT pv.*, d.name AS dept_name, u.full_name AS preparer
    FROM payment_vouchers pv JOIN departments d ON pv.department_id=d.id JOIN users u ON pv.prepared_by=u.id
    WHERE $wSQL ORDER BY pv.created_at ASC");
$rows->execute($params); $pending = $rows->fetchAll();

// Total value pending
$totalPending = array_sum(array_column($pending,'amount'));

renderHead('Pending Approvals');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Pending Approvals','Vouchers awaiting your action');
renderPageStart('Pending Approvals','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>APP_URL.'/modules/expenditure/vouchers.php','label'=>'Vouchers'],
    ['url'=>'#','label'=>'Pending Approvals']
]);
renderPageActions('');
renderFlashMessages();
?>

<div class="grid-3" style="margin-bottom:1.2rem;">
  <div class="stat-card red"><div class="stat-icon">⏳</div><div class="stat-info"><div class="label">Awaiting Action</div><div class="value"><?= count($pending) ?></div><div class="sub">Pending vouchers</div></div></div>
  <div class="stat-card amber"><div class="stat-icon">💰</div><div class="stat-info"><div class="label">Total Value</div><div class="value"><?= number_format($totalPending/1000000,1)?>M</div><div class="sub">UGX <?= number_format($totalPending) ?></div></div></div>
  <div class="stat-card"><div class="stat-icon">👤</div><div class="stat-info"><div class="label">Your Role</div><div class="value" style="font-size:1rem;"><?= htmlspecialchars($user['role_name']) ?></div><div class="sub">Approval authority</div></div></div>
</div>

<div class="card">
  <div class="card-header"><h5><span class="ch-icon">▥</span> Vouchers Awaiting Approval</h5></div>
  <div class="card-body p-0">
    <?php if ($pending): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Voucher No.</th><th>Department</th><th>Payee</th><th>Description</th><th class="text-right">Amount (UGX)</th><th>Prepared</th><th>Current Stage</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($pending as $v): ?>
        <tr>
          <td><a href="<?=APP_URL?>/modules/expenditure/voucher_view.php?id=<?=$v['id']?>" class="fw-700 text-primary"><?=htmlspecialchars($v['voucher_number'])?></a></td>
          <td class="fs-sm"><?=htmlspecialchars($v['dept_name'])?></td>
          <td class="fw-600"><?=htmlspecialchars($v['payee_name'])?></td>
          <td class="fs-sm text-muted"><?=htmlspecialchars(substr($v['description'],0,35))?>...</td>
          <td class="text-right fw-700"><?=number_format($v['amount'])?></td>
          <td class="fs-sm"><?=formatDate($v['prepared_date'])?><br><span class="fs-xs text-muted"><?=htmlspecialchars($v['preparer'])?></span></td>
          <td><?=getStatusBadge($v['status'])?></td>
          <td>
            <a href="<?=APP_URL?>/modules/expenditure/voucher_view.php?id=<?=$v['id']?>" class="btn btn-sm btn-primary">Review &amp; Act</a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="4" class="text-right">TOTAL VALUE:</th><th class="text-right"><?=number_format($totalPending)?></th><th colspan="3"></th></tr></tfoot>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">✅</div><h5>All clear</h5><p>No vouchers are awaiting your action.</p></div>
    <?php endif; ?>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
