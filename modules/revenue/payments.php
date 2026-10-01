<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/sms.php';
require_once __DIR__ . '/../../includes/email.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'record_payment') {
        $receiptNum = generateReceiptNumber();
        $payerId    = (int)$_POST['payer_id'];
        $sourceId   = (int)$_POST['revenue_source_id'];
        $amount     = (float)str_replace(',', '', $_POST['amount']);
        $assessId   = !empty($_POST['assessment_id']) ? (int)$_POST['assessment_id'] : null;

        // Get payer ward
        $payerStmt = $db->prepare("SELECT ward_id FROM payers WHERE id=?");
        $payerStmt->execute([$payerId]); $payer = $payerStmt->fetch();

        $db->prepare("INSERT INTO revenue_payments 
            (receipt_number,payer_id,assessment_id,revenue_source_id,financial_year,amount,payment_date,payment_method,transaction_reference,bank_name,collected_by,ward_id,notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $receiptNum, $payerId, $assessId, $sourceId, $fy,
               $amount, $_POST['payment_date'], $_POST['payment_method'],
               trim($_POST['transaction_reference'] ?? ''), trim($_POST['bank_name'] ?? ''),
               $user['id'], $payer['ward_id'] ?? null, trim($_POST['notes'] ?? '')
           ]);
        $paymentId = $db->lastInsertId();

        // Update assessment if linked
        if ($assessId) {
            $db->prepare("UPDATE revenue_assessments SET amount_paid = amount_paid + ? WHERE id=?")->execute([$amount, $assessId]);
            $db->prepare("UPDATE revenue_assessments SET status = CASE WHEN balance <= 0 THEN 'paid' WHEN amount_paid > 0 THEN 'partial' ELSE status END WHERE id=?")->execute([$assessId]);
        }

        logAudit('CREATE', 'revenue', 'payment', $paymentId, $receiptNum, [], ['amount' => $amount]);

        // ── SMS: payment confirmation to payer ────────────────────
        $payerFull = $db->prepare("SELECT full_name, phone FROM payers WHERE id=?");
        $payerFull->execute([$payerId]); $payerInfo = $payerFull->fetch();
        $sourceName = $db->prepare("SELECT name FROM revenue_sources WHERE id=?");
        $sourceName->execute([$sourceId]); $srcRow = $sourceName->fetch();
        if ($payerInfo && $payerInfo['phone']) {
            smsPaymentConfirmation(
                $payerInfo['phone'], $payerInfo['full_name'],
                $amount, $receiptNum, $srcRow['name'] ?? 'Revenue'
            );
            processSmsQueue(3);
        }

        setFlash('success', "Payment recorded. Receipt: <strong>{$receiptNum}</strong> &mdash; <a href='receipt.php?ref={$receiptNum}' target='_blank'>View Receipt</a> | <a href='" . APP_URL . "/modules/revenue/receipt_pdf.php?ref={$receiptNum}' target='_blank'>⬇ PDF</a>");
    } elseif ($action === 'void_payment') {
        $paymentId = (int)$_POST['payment_id'];
        $reason    = trim($_POST['void_reason'] ?? '');
        if (!hasRole(['admin','finance_officer','town_clerk'])) {
            setFlash('danger', 'You are not authorized to void payments.');
        } elseif (empty($reason)) {
            setFlash('danger', 'Void reason is required.');
        } else {
            $stmt = $db->prepare("SELECT * FROM revenue_payments WHERE id=?");
            $stmt->execute([$paymentId]); $pay = $stmt->fetch();
            if ($pay && $pay['status'] === 'active') {
                $db->prepare("UPDATE revenue_payments SET status='voided', void_reason=?, voided_by=?, voided_at=NOW(), void_authorized_by=? WHERE id=?")
                   ->execute([$reason, $user['id'], $user['id'], $paymentId]);
                if ($pay['assessment_id']) {
                    $db->prepare("UPDATE revenue_assessments SET amount_paid = GREATEST(0, amount_paid - ?), status='active' WHERE id=?")
                       ->execute([$pay['amount'], $pay['assessment_id']]);
                }
                logAudit('VOID', 'revenue', 'payment', $paymentId, $pay['receipt_number'], ['status'=>'active'], ['status'=>'voided','reason'=>$reason]);
                setFlash('success', "Payment {$pay['receipt_number']} voided successfully.");
            }
        }
    }
    header('Location: payments.php'); exit;
}

// Filters
$search  = trim($_GET['search'] ?? '');
$wardFil = (int)($_GET['ward_id'] ?? 0);
$srcFil  = (int)($_GET['source_id'] ?? 0);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';
$methodF  = $_GET['method'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));

$where  = ["rp.status != 'voided'"];
$params = [];
if ($search)   { $where[] = "(p.full_name LIKE ? OR rp.receipt_number LIKE ? OR rp.transaction_reference LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
if ($wardFil)  { $where[] = "rp.ward_id=?";               $params[] = $wardFil; }
if ($srcFil)   { $where[] = "rp.revenue_source_id=?";     $params[] = $srcFil; }
if ($dateFrom) { $where[] = "rp.payment_date >= ?";        $params[] = $dateFrom; }
if ($dateTo)   { $where[] = "rp.payment_date <= ?";        $params[] = $dateTo; }
if ($methodF)  { $where[] = "rp.payment_method=?";         $params[] = $methodF; }
$whereSQL = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*), COALESCE(SUM(rp.amount),0) FROM revenue_payments rp JOIN payers p ON rp.payer_id=p.id WHERE $whereSQL");
$totalStmt->execute($params);
$totRow = $totalStmt->fetch(PDO::FETCH_NUM);
$total  = (int)$totRow[0]; $grandTotal = (float)$totRow[1];
$pg     = paginate($total, $page);

$dataStmt = $db->prepare("SELECT rp.*, p.full_name, p.business_name, p.payer_number, rs.name AS source_name, w.name AS ward_name,
    u.full_name AS officer_name
    FROM revenue_payments rp 
    JOIN payers p ON rp.payer_id=p.id 
    JOIN revenue_sources rs ON rp.revenue_source_id=rs.id
    LEFT JOIN wards w ON rp.ward_id=w.id
    LEFT JOIN users u ON rp.collected_by=u.id
    WHERE $whereSQL ORDER BY rp.created_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$dataStmt->execute($params);
$payments = $dataStmt->fetchAll();

$wards   = $db->query("SELECT * FROM wards WHERE is_active=1 ORDER BY name")->fetchAll();
$sources = $db->query("SELECT * FROM revenue_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$activePayers = $db->query("SELECT id, payer_number, full_name, business_name FROM payers WHERE status='active' ORDER BY full_name LIMIT 1000")->fetchAll();

renderHead('Payments & Receipts');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Payments & Receipts', 'Record and manage revenue payments');
renderPageStart('Payments & Receipts', '', [
    ['url' => APP_URL . '/dashboard.php', 'label' => 'Dashboard'],
    ['url' => '#', 'label' => 'Payments & Receipts']
]);
renderPageActions('<button class="btn btn-primary" data-modal="recordPaymentModal">+ Record Payment</button>
  <a href="' . APP_URL . '/modules/revenue/dashboard.php" class="btn btn-outline-secondary">Revenue Dashboard</a>
  <a href="' . APP_URL . '/api/export.php?type=revenue_payments&fy=' . urlencode($fy) . '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo) . '" class="btn btn-outline-primary no-print">📊 Export</a>
');
renderFlashMessages();
?>

<!-- Summary Cards -->
<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card green">
    <div class="stat-icon">💰</div>
    <div class="stat-info"><div class="label">Total (Filtered)</div><div class="value"><?= number_format($grandTotal/1000000,1) ?>M</div><div class="sub">UGX <?= number_format($grandTotal) ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">🧾</div>
    <div class="stat-info"><div class="label">Transactions</div><div class="value"><?= number_format($total) ?></div><div class="sub">Payment records</div></div>
  </div>
  <div class="stat-card blue">
    <div class="stat-icon">📅</div>
    <div class="stat-info"><div class="label">Today</div>
    <?php
      $todayAmt = $db->query("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE DATE(payment_date)=CURDATE() AND status='active'")->fetchColumn();
    ?>
    <div class="value"><?= number_format((float)$todayAmt/1000000,1) ?>M</div><div class="sub">UGX <?= number_format((float)$todayAmt) ?></div></div>
  </div>
  <div class="stat-card amber">
    <div class="stat-icon">📆</div>
    <div class="stat-info"><div class="label">This Month</div>
    <?php
      $mthAmt = $db->query("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE()) AND status='active'")->fetchColumn();
    ?>
    <div class="value"><?= number_format((float)$mthAmt/1000000,1) ?>M</div><div class="sub">UGX <?= number_format((float)$mthAmt) ?></div></div>
  </div>
</div>

<!-- Filters -->
<form method="GET">
<div class="filter-row">
  <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Receipt, Payer, Ref..."></div>
  <div class="form-group"><label>Ward</label>
    <select name="ward_id" class="form-select"><option value="">All Wards</option>
      <?php foreach ($wards as $w): ?><option value="<?= $w['id'] ?>" <?= $wardFil==$w['id']?'selected':'' ?>><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label>Revenue Source</label>
    <select name="source_id" class="form-select"><option value="">All Sources</option>
      <?php foreach ($sources as $s): ?><option value="<?= $s['id'] ?>" <?= $srcFil==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label>Payment Method</label>
    <select name="method" class="form-select"><option value="">All Methods</option>
      <?php foreach (['cash','bank','mobile_money','cheque','electronic','other'] as $m): ?><option value="<?= $m ?>" <?= $methodF===$m?'selected':'' ?>><?= ucwords(str_replace('_',' ',$m)) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label>From</label><input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>"></div>
  <div class="form-group"><label>To</label><input type="date" name="date_to" class="form-control" value="<?= $dateTo ?>"></div>
  <div class="form-group"><label>&nbsp;</label><div style="display:flex;gap:.4rem;"><button type="submit" class="btn btn-primary">Filter</button><a href="payments.php" class="btn btn-outline-secondary">Reset</a></div></div>
</div>
</form>

<!-- Table -->
<div class="card">
  <div class="card-header">
    <h5><span class="ch-icon">◇</span> Payment Records <span style="font-weight:400;font-size:.82rem;color:#6c757d;">(<?= number_format($total) ?> records | Total: UGX <?= number_format($grandTotal) ?>)</span></h5>
    <a href="<?= APP_URL ?>/modules/reports/revenue.php?report=payments&<?= http_build_query(['search'=>$search,'ward_id'=>$wardFil,'source_id'=>$srcFil,'date_from'=>$dateFrom,'date_to'=>$dateTo]) ?>" class="btn btn-sm btn-outline-secondary">Export</a>
  </div>
  <div class="card-body p-0">
    <?php if ($payments): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead>
          <tr><th>#</th><th>Receipt No.</th><th>Payer</th><th>Revenue Source</th><th>Ward</th><th>Method</th><th class="text-right">Amount (UGX)</th><th>Date</th><th>Officer</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($payments as $i => $pay): ?>
        <tr>
          <td class="fs-xs text-muted"><?= $pg['offset']+$i+1 ?></td>
          <td><a href="<?= APP_URL ?>/modules/revenue/receipt.php?ref=<?= urlencode($pay['receipt_number']) ?>" target="_blank" class="fw-700 text-primary"><?= htmlspecialchars($pay['receipt_number']) ?></a></td>
          <td>
            <div class="fw-600"><?= htmlspecialchars($pay['full_name']) ?></div>
            <?php if ($pay['business_name']): ?><div class="fs-xs text-muted"><?= htmlspecialchars($pay['business_name']) ?></div><?php endif; ?>
            <div class="fs-xs text-muted"><?= htmlspecialchars($pay['payer_number']) ?></div>
          </td>
          <td class="fs-sm"><?= htmlspecialchars($pay['source_name']) ?></td>
          <td class="fs-sm"><?= htmlspecialchars($pay['ward_name'] ?? '—') ?></td>
          <td class="fs-sm"><?= ucwords(str_replace('_',' ',$pay['payment_method'])) ?></td>
          <td class="text-right fw-700"><?= number_format($pay['amount']) ?></td>
          <td class="fs-sm"><?= formatDate($pay['payment_date']) ?></td>
          <td class="fs-sm"><?= htmlspecialchars($pay['officer_name'] ?? '—') ?></td>
          <td>
            <div style="display:flex;gap:.3rem;">
              <a href="<?= APP_URL ?>/modules/revenue/receipt.php?ref=<?= urlencode($pay['receipt_number']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">Receipt</a>
              <a href="<?= APP_URL ?>/modules/revenue/receipt_pdf.php?ref=<?= urlencode($pay['receipt_number']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Download PDF">⬇</a>
              <?php if ($pay['status'] === 'active' && hasRole(['admin','finance_officer','town_clerk'])): ?>
              <button class="btn btn-sm btn-outline-secondary" onclick="voidPayment(<?= $pay['id'] ?>, '<?= htmlspecialchars($pay['receipt_number']) ?>')">Void</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><th colspan="6" class="text-right">TOTAL:</th><th class="text-right"><?= number_format($grandTotal) ?></th><th colspan="3"></th></tr>
        </tfoot>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">◇</div><h5>No payments found</h5><p>Record a payment using the button above.</p></div>
    <?php endif; ?>
  </div>
  <?php if ($pg['total_pages'] > 1): ?>
  <div class="card-footer"><?= renderPagination($pg, '?search='.urlencode($search).'&ward_id='.$wardFil.'&source_id='.$srcFil.'&date_from='.$dateFrom.'&date_to='.$dateTo.'&method='.$methodF) ?></div>
  <?php endif; ?>
</div>

<!-- ── Record Payment Modal ──────────────────────────────────────────────── -->
<div class="modal-backdrop" id="recordPaymentModal">
  <div class="modal-box modal-lg">
    <div class="modal-header">
      <h5>Record Revenue Payment</h5>
      <button class="modal-close" data-modal-close>✕</button>
    </div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="record_payment">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Payer <span class="req">*</span></label>
            <select name="payer_id" id="pm_payer_id" class="form-select" required onchange="loadAssessments(this.value)">
              <option value="">-- Search / Select Payer --</option>
              <?php foreach ($activePayers as $ap): ?>
              <option value="<?= $ap['id'] ?>"><?= htmlspecialchars($ap['payer_number'] . ' — ' . $ap['full_name'] . ($ap['business_name'] ? ' (' . $ap['business_name'] . ')' : '')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Revenue Source <span class="req">*</span></label>
            <select name="revenue_source_id" class="form-select" required>
              <option value="">-- Select Source --</option>
              <?php foreach ($sources as $s): ?>
              <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Link to Assessment (Optional)</label>
            <select name="assessment_id" id="pm_assessment_id" class="form-select">
              <option value="">-- None / Select Assessment --</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Amount (UGX) <span class="req">*</span></label>
            <input type="number" name="amount" class="form-control" required min="1" step="1" placeholder="0">
          </div>
          <div class="form-group">
            <label class="form-label">Payment Date <span class="req">*</span></label>
            <input type="date" name="payment_date" class="form-control" required value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Payment Method <span class="req">*</span></label>
            <select name="payment_method" class="form-select" required onchange="toggleRef(this.value)">
              <option value="cash">Cash</option>
              <option value="bank">Bank</option>
              <option value="mobile_money">Mobile Money</option>
              <option value="cheque">Cheque</option>
              <option value="electronic">Electronic</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="form-group" id="refGroup" style="display:none;">
            <label class="form-label">Transaction / Reference Number</label>
            <input type="text" name="transaction_reference" class="form-control" placeholder="Bank ref / MM transaction ID">
          </div>
          <div class="form-group" id="bankGroup" style="display:none;">
            <label class="form-label">Bank Name</label>
            <input type="text" name="bank_name" class="form-control" placeholder="Bank name">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Record & Generate Receipt</button>
      </div>
    </form>
  </div>
</div>

<!-- Void Modal -->
<div class="modal-backdrop" id="voidModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Void Payment</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="void_payment">
      <input type="hidden" name="payment_id" id="void_payment_id">
      <div class="modal-body">
        <p style="margin-bottom:1rem;">You are about to void receipt <strong id="void_receipt_num"></strong>. This action is permanent and will be logged.</p>
        <div class="form-group">
          <label class="form-label">Reason for Voiding <span class="req">*</span></label>
          <textarea name="void_reason" class="form-control" rows="3" required placeholder="State the reason for voiding this payment..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-danger">Void Payment</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; ?>
<script>
function voidPayment(id, ref) {
  document.getElementById('void_payment_id').value = id;
  document.getElementById('void_receipt_num').textContent = ref;
  openModal('voidModal');
}
function toggleRef(method) {
  const show = ['bank','mobile_money','cheque','electronic'].includes(method);
  document.getElementById('refGroup').style.display  = show ? '' : 'none';
  document.getElementById('bankGroup').style.display = method === 'bank' ? '' : 'none';
}
function loadAssessments(payerId) {
  const sel = document.getElementById('pm_assessment_id');
  sel.innerHTML = '<option value="">-- Loading --</option>';
  if (!payerId) { sel.innerHTML = '<option value="">-- None --</option>'; return; }
  fetch(`<?= APP_URL ?>/api/assessments.php?payer_id=${payerId}`)
    .then(r => r.json())
    .then(data => {
      sel.innerHTML = '<option value="">-- None --</option>';
      data.forEach(a => {
        sel.innerHTML += `<option value="${a.id}">${a.assessment_number} — ${a.source_name} | Due: ${Number(a.balance).toLocaleString()}</option>`;
      });
    });
}
</script>
<?php renderFooter(); ?>
