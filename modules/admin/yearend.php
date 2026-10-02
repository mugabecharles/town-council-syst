<?php
/**
 * TCMS Year-End Closing Workflow
 * Close financial year, lock transactions, generate annual summary.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
if (!hasRole(['admin','town_clerk'])) {
    setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit;
}
$user = getCurrentUser();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'close_fy') {
        $fyId = (int)$_POST['fy_id'];
        $fyCode = trim($_POST['fy_code'] ?? '');
        // Double-confirm: user must type the FY code
        $fy = $db->prepare("SELECT * FROM financial_years WHERE id=? AND status='open'");
        $fy->execute([$fyId]); $fy=$fy->fetch();
        if (!$fy) { setFlash('danger','Financial year not found or already closed.'); header('Location: yearend.php'); exit; }
        if ($fyCode !== $fy['year_code']) {
            setFlash('danger','Year code confirmation does not match. Type the exact code to confirm closure.');
            header('Location: yearend.php'); exit;
        }
        // Close the year
        $db->prepare("UPDATE financial_years SET status='closed', closed_by=?, closed_at=NOW() WHERE id=?")->execute([$user['id'],$fyId]);
        // Resolve all budget alerts for this FY
        $db->prepare("UPDATE budget_alert_log SET is_resolved=1, resolved_at=NOW() WHERE financial_year=?")->execute([$fy['year_code']]);
        logAudit('CLOSE_FINANCIAL_YEAR','admin','financial_year',$fyId,$fy['year_code']);
        setFlash('success',"Financial year <strong>{$fy['year_code']}</strong> has been closed. All transactions are now locked.");
        header('Location: yearend.php'); exit;
    }

    if ($action === 'reopen_fy' && hasRole(['admin'])) {
        $fyId = (int)$_POST['fy_id'];
        $reason = trim($_POST['reason'] ?? '');
        if (empty($reason)) { setFlash('danger','Reason is required to reopen a closed year.'); header('Location: yearend.php'); exit; }
        $db->prepare("UPDATE financial_years SET status='open', closed_by=NULL, closed_at=NULL WHERE id=? AND status='closed'")->execute([$fyId]);
        logAudit('REOPEN_FINANCIAL_YEAR','admin','financial_year',$fyId,$reason);
        setFlash('warning',"Financial year reopened. Reason: $reason — This action has been logged.");
        header('Location: yearend.php'); exit;
    }
}

$fyears = $db->query("SELECT fy.*,u.full_name AS closed_by_name FROM financial_years fy LEFT JOIN users u ON fy.closed_by=u.id ORDER BY fy.start_date DESC")->fetchAll();

// Per-FY summaries
$fySummaries = [];
foreach ($fyears as $fy) {
    $code = $fy['year_code'];
    $rev   = (float)$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'")->execute([$code])?0:0;
    $rs=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'");$rs->execute([$code]);$rev=(float)$rs->fetchColumn();
    $exp   = (float)$db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE financial_year=? AND status IN ('paid','completed')")->execute([$code])?0:0;
    $es=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE financial_year=? AND status IN ('paid','completed')");$es->execute([$code]);$exp=(float)$es->fetchColumn();
    $govt  = (float)$db->prepare("SELECT COALESCE(SUM(amount_received),0) FROM government_funds WHERE financial_year=?")->execute([$code])?0:0;
    $gs=$db->prepare("SELECT COALESCE(SUM(amount_received),0) FROM government_funds WHERE financial_year=?");$gs->execute([$code]);$govt=(float)$gs->fetchColumn();
    $vouchers=(int)$db->prepare("SELECT COUNT(*) FROM payment_vouchers WHERE financial_year=?")->execute([$code])?0:0;
    $vs=$db->prepare("SELECT COUNT(*) FROM payment_vouchers WHERE financial_year=?");$vs->execute([$code]);$vouchers=(int)$vs->fetchColumn();
    $receipts=(int)$db->prepare("SELECT COUNT(*) FROM revenue_payments WHERE financial_year=? AND status='active'")->execute([$code])?0:0;
    $rcs=$db->prepare("SELECT COUNT(*) FROM revenue_payments WHERE financial_year=? AND status='active'");$rcs->execute([$code]);$receipts=(int)$rcs->fetchColumn();
    $fySummaries[$code] = compact('rev','exp','govt','vouchers','receipts');
}

renderHead('Year-End Closing');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Year-End Closing','Close financial years and lock historical records');
renderPageStart('Year-End Closing','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Year-End Closing'],
]);
renderPageActions('');
renderFlashMessages();
?>

<div class="alert alert-warning">
  <span>⚠</span>
  <div>
    <strong>Important:</strong> Closing a financial year <strong>locks all revenue and expenditure transactions</strong> for that year.
    Only the Town Clerk or Administrator can close a year. This action is permanent and logged in the audit trail.
    You must type the exact year code to confirm.
  </div>
</div>

<div class="card">
  <div class="card-header"><h5><span class="ch-icon">📅</span> Financial Years</h5></div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead>
          <tr>
            <th>Year Code</th><th>Start</th><th>End</th><th class="text-right">Revenue Collected</th>
            <th class="text-right">Expenditure</th><th class="text-right">Govt Funds</th>
            <th>Receipts</th><th>Vouchers</th><th>Status</th><th>Closed By</th><th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($fyears as $fy):
          $sum = $fySummaries[$fy['year_code']] ?? [];
        ?>
        <tr style="<?= $fy['status']==='closed'?'opacity:.85;background:#fafafa;':'' ?>">
          <td class="fw-700 text-primary"><?=htmlspecialchars($fy['year_code'])?></td>
          <td class="fs-sm"><?=formatDate($fy['start_date'])?></td>
          <td class="fs-sm"><?=formatDate($fy['end_date'])?></td>
          <td class="text-right fw-700 text-success"><?=number_format(($sum['rev']??0)/1000000,1)?>M</td>
          <td class="text-right text-danger"><?=number_format(($sum['exp']??0)/1000000,1)?>M</td>
          <td class="text-right text-info"><?=number_format(($sum['govt']??0)/1000000,1)?>M</td>
          <td class="text-center"><?=number_format($sum['receipts']??0)?></td>
          <td class="text-center"><?=number_format($sum['vouchers']??0)?></td>
          <td><?=getStatusBadge($fy['status'])?></td>
          <td class="fs-sm"><?=htmlspecialchars($fy['closed_by_name']??'—')?><?= $fy['closed_at']?'<br><span class="fs-xs text-muted">'.formatDate($fy['closed_at']).'</span>':'' ?></td>
          <td>
            <?php if($fy['status']==='open'): ?>
            <button class="btn btn-sm btn-warning" onclick="confirmClose('<?=htmlspecialchars($fy['id'])?>','<?=htmlspecialchars($fy['year_code'])?>')">Close Year</button>
            <?php elseif($fy['status']==='closed' && hasRole(['admin'])): ?>
            <button class="btn btn-sm btn-outline-danger" onclick="reopenYear('<?=htmlspecialchars($fy['id'])?>','<?=htmlspecialchars($fy['year_code'])?>')">Reopen</button>
            <?php else: ?>
            <span class="badge badge-dark">Locked</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Close Year Modal -->
<div class="modal-backdrop" id="closeYearModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Close Financial Year</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?><input type="hidden" name="action" value="close_fy">
      <input type="hidden" name="fy_id" id="close_fy_id">
      <div class="modal-body">
        <div class="alert alert-danger"><span>🚨</span><span><strong>This action is irreversible.</strong> Closing this year will lock all transactions. No new payments or vouchers can be posted to a closed year.</span></div>
        <p class="fs-sm" style="margin-bottom:1rem;">To confirm, type the financial year code below:</p>
        <div style="text-align:center;font-size:1.3rem;font-weight:800;color:var(--primary);background:#f8f9fa;padding:.6rem;border-radius:6px;margin-bottom:1rem;" id="close_fy_display"></div>
        <div class="form-group">
          <label class="form-label">Type the year code to confirm <span class="req">*</span></label>
          <input type="text" name="fy_code" class="form-control" required placeholder="e.g. 2025/2026" autocomplete="off">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-danger">Close Financial Year</button>
      </div>
    </form>
  </div>
</div>

<!-- Reopen Year Modal -->
<div class="modal-backdrop" id="reopenYearModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Reopen Financial Year</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?><input type="hidden" name="action" value="reopen_fy">
      <input type="hidden" name="fy_id" id="reopen_fy_id">
      <div class="modal-body">
        <div class="alert alert-warning"><span>⚠</span><span>Reopening <strong id="reopen_fy_display"></strong> will allow new transactions to be posted. This action is logged and requires a reason.</span></div>
        <div class="form-group"><label class="form-label">Reason for Reopening <span class="req">*</span></label><textarea name="reason" class="form-control" rows="3" required placeholder="State the authorised reason..."></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-warning">Reopen Year</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; ?>
<script>
function confirmClose(id,code){
  document.getElementById('close_fy_id').value=id;
  document.getElementById('close_fy_display').textContent=code;
  openModal('closeYearModal');
}
function reopenYear(id,code){
  document.getElementById('reopen_fy_id').value=id;
  document.getElementById('reopen_fy_display').textContent=code;
  openModal('reopenYearModal');
}
</script>
<?php renderFooter(); ?>
