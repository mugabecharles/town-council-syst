<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
if (!hasRole(['admin','town_clerk','auditor'])) { setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit; }
$user = getCurrentUser();
$db   = getDB();
$fy   = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);

// Voided receipts
$voided = $db->prepare("SELECT rp.*, p.full_name, p.payer_number, u.full_name AS voided_by_name FROM revenue_payments rp JOIN payers p ON rp.payer_id=p.id LEFT JOIN users u ON rp.voided_by=u.id WHERE rp.status='voided' AND rp.financial_year=? ORDER BY rp.voided_at DESC");
$voided->execute([$fy]); $voided = $voided->fetchAll();

// Rejected vouchers
$rejected = $db->prepare("SELECT pv.*, d.name AS dept_name, u.full_name AS preparer FROM payment_vouchers pv JOIN departments d ON pv.department_id=d.id JOIN users u ON pv.prepared_by=u.id WHERE pv.status IN ('rejected','returned') AND pv.financial_year=? ORDER BY pv.updated_at DESC");
$rejected->execute([$fy]); $rejected = $rejected->fetchAll();

// Top users by activity
$topUsers = $db->prepare("SELECT u.full_name, u.username, r.name AS role_name, COUNT(al.id) AS actions FROM audit_logs al JOIN users u ON al.user_id=u.id JOIN roles r ON u.role_id=r.id WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY al.user_id ORDER BY actions DESC LIMIT 10");
$topUsers->execute(); $topUsers = $topUsers->fetchAll();

$fyears = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

renderHead('Audit Reports');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Audit Reports','Transaction history and accountability');
renderPageStart('Audit Reports','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Audit Reports']
]);
$fyOpts=''; foreach ($fyears as $y) $fyOpts.="<option value='$y'".($y===$fy?' selected':'').">$y</option>";
renderPageActions("<form method='GET' style='display:flex;gap:.5rem;align-items:center;'><select name='fy' class='form-select' onchange='this.form.submit()' style='width:130px;font-size:.84rem;'>$fyOpts</select></form>
  <button class='btn btn-outline-secondary no-print' data-print>🖨 Print</button>");
renderFlashMessages();
?>

<div class="grid-3" style="margin-bottom:1.2rem;">
  <div class="stat-card red"><div class="stat-icon">✕</div><div class="stat-info"><div class="label">Voided Receipts</div><div class="value"><?=count($voided)?></div><div class="sub">FY <?=$fy?></div></div></div>
  <div class="stat-card amber"><div class="stat-icon">↩</div><div class="stat-info"><div class="label">Rejected/Returned Vouchers</div><div class="value"><?=count($rejected)?></div><div class="sub">FY <?=$fy?></div></div></div>
  <div class="stat-card"><div class="stat-icon">◭</div><div class="stat-info"><div class="label">Active Users</div><div class="value"><?=count($topUsers)?></div><div class="sub">Last 30 days</div></div></div>
</div>

<!-- Voided Receipts -->
<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-header"><h5><span class="ch-icon">✕</span> Voided Receipts — FY <?=$fy?></h5></div>
  <div class="card-body p-0">
    <?php if ($voided): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Receipt No.</th><th>Payer</th><th>Amount</th><th>Voided By</th><th>Date Voided</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($voided as $v): ?>
        <tr>
          <td class="fw-700 text-danger"><?=htmlspecialchars($v['receipt_number'])?></td>
          <td><?=htmlspecialchars($v['full_name'])?><br><span class="fs-xs text-muted"><?=htmlspecialchars($v['payer_number'])?></span></td>
          <td class="fw-700"><?=number_format($v['amount'])?></td>
          <td class="fs-sm"><?=htmlspecialchars($v['voided_by_name']??'—')?></td>
          <td class="fs-sm"><?=formatDateTime($v['voided_at']??'')?></td>
          <td class="fs-sm text-muted"><?=htmlspecialchars($v['void_reason']??'—')?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><div class="empty-state" style="padding:1.5rem;"><p>No voided receipts for <?=$fy?>.</p></div><?php endif; ?>
  </div>
</div>

<!-- Rejected Vouchers -->
<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-header"><h5><span class="ch-icon">↩</span> Rejected / Returned Vouchers — FY <?=$fy?></h5></div>
  <div class="card-body p-0">
    <?php if ($rejected): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>Voucher No.</th><th>Department</th><th>Payee</th><th>Amount</th><th>Prepared By</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rejected as $r): ?>
        <tr>
          <td class="fw-700 text-primary"><?=htmlspecialchars($r['voucher_number'])?></td>
          <td class="fs-sm"><?=htmlspecialchars($r['dept_name'])?></td>
          <td class="fw-600"><?=htmlspecialchars($r['payee_name'])?></td>
          <td class="fw-700"><?=number_format($r['amount'])?></td>
          <td class="fs-sm"><?=htmlspecialchars($r['preparer'])?></td>
          <td><?=getStatusBadge($r['status'])?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><div class="empty-state" style="padding:1.5rem;"><p>No rejected vouchers for <?=$fy?>.</p></div><?php endif; ?>
  </div>
</div>

<!-- User Activity -->
<div class="card">
  <div class="card-header"><h5><span class="ch-icon">◭</span> Most Active Users (Last 30 Days)</h5></div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>#</th><th>User</th><th>Role</th><th class="text-right">Actions Logged</th><th>Activity</th></tr></thead>
        <tbody>
        <?php $maxActions = $topUsers ? max(array_column($topUsers,'actions')) : 1; ?>
        <?php foreach ($topUsers as $i => $tu): ?>
        <tr>
          <td class="fs-xs text-muted"><?=$i+1?></td>
          <td><div class="fw-600"><?=htmlspecialchars($tu['full_name'])?></div><div class="fs-xs text-muted"><?=htmlspecialchars($tu['username'])?></div></td>
          <td><span class="badge badge-info"><?=htmlspecialchars($tu['role_name'])?></span></td>
          <td class="text-right fw-700"><?=number_format($tu['actions'])?></td>
          <td style="min-width:120px;"><div class="progress" style="height:7px;"><div class="progress-bar bg-primary" style="width:<?=round($tu['actions']/$maxActions*100)?>%"></div></div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:.8rem 1.2rem;text-align:right;">
      <a href="<?=APP_URL?>/modules/audit/index.php" class="btn btn-sm btn-outline-primary">View Full Audit Log</a>
    </div>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
