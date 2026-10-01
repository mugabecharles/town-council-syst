<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$db  = getDB();
$ref = trim($_GET['ref'] ?? '');
if (!$ref) { setFlash('danger', 'Invalid receipt reference.'); header('Location: payments.php'); exit; }

$stmt = $db->prepare("SELECT rp.*, p.full_name, p.business_name, p.payer_number, p.address,
    rs.name AS source_name, rs.description AS source_desc,
    w.name AS ward_name, u.full_name AS officer_name
    FROM revenue_payments rp
    JOIN payers p ON rp.payer_id=p.id
    JOIN revenue_sources rs ON rp.revenue_source_id=rs.id
    LEFT JOIN wards w ON rp.ward_id=w.id
    LEFT JOIN users u ON rp.collected_by=u.id
    WHERE rp.receipt_number=?");
$stmt->execute([$ref]);
$pay = $stmt->fetch();
if (!$pay) { setFlash('danger', 'Receipt not found.'); header('Location: payments.php'); exit; }

$councilName = getSystemSetting('council_name')    ?? 'Town Council';
$councilAddr = getSystemSetting('council_address') ?? '';
$councilTel  = getSystemSetting('council_phone')   ?? '';

renderHead('Receipt ' . $pay['receipt_number']);
$user = getCurrentUser();
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Payment Receipt', $pay['receipt_number']);
renderPageStart('Payment Receipt', '', [
    ['url' => APP_URL . '/dashboard.php', 'label' => 'Dashboard'],
    ['url' => APP_URL . '/modules/revenue/payments.php', 'label' => 'Payments'],
    ['url' => '#', 'label' => $pay['receipt_number']]
]);
renderPageActions('
  <button class="btn btn-primary no-print" data-print>🖨 Print Receipt</button>
  <a href="' . APP_URL . '/modules/revenue/receipt_pdf.php?ref=' . urlencode($pay['receipt_number']) . '" class="btn btn-outline-primary no-print" target="_blank">⬇ Download PDF</a>
  <a href="' . APP_URL . '/verify.php?ref=' . urlencode($pay['receipt_number']) . '" class="btn btn-outline-secondary no-print" target="_blank">🔍 Verify</a>
  <a href="payments.php" class="btn btn-outline-secondary no-print">Back</a>
');
renderFlashMessages();
?>

<?php if ($pay['status'] === 'voided'): ?>
<div class="alert alert-danger">
  <strong>VOIDED:</strong> This receipt has been voided. Reason: <?= htmlspecialchars($pay['void_reason']) ?><br>
  Voided on: <?= formatDateTime($pay['voided_at']) ?>
</div>
<?php endif; ?>

<div class="receipt-paper <?= $pay['status'] === 'voided' ? 'no-print' : '' ?>">

  <?php if ($pay['status'] === 'voided'): ?>
  <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);font-size:3rem;font-weight:900;color:rgba(189,33,48,.18);letter-spacing:4px;pointer-events:none;z-index:1;">VOIDED</div>
  <?php endif; ?>

  <div class="receipt-header">
    <div style="width:70px;height:70px;background:var(--primary);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;color:var(--accent);font-size:1.5rem;font-weight:800;">TC</div>
    <h2><?= htmlspecialchars($councilName) ?></h2>
    <?php if ($councilAddr): ?><p><?= htmlspecialchars($councilAddr) ?></p><?php endif; ?>
    <?php if ($councilTel):  ?><p>Tel: <?= htmlspecialchars($councilTel) ?></p><?php endif; ?>
  </div>

  <div class="receipt-title">OFFICIAL RECEIPT</div>

  <div style="display:flex;justify-content:space-between;margin:.8rem 0;font-size:.82rem;font-weight:700;">
    <span>Receipt No: <span style="color:var(--primary);"><?= htmlspecialchars($pay['receipt_number']) ?></span></span>
    <span>Date: <?= formatDate($pay['payment_date']) ?></span>
  </div>

  <hr style="border-color:var(--primary);margin:.8rem 0;">

  <div class="receipt-row"><span>Payer ID:</span><strong><?= htmlspecialchars($pay['payer_number']) ?></strong></div>
  <div class="receipt-row"><span>Payer Name:</span><strong><?= htmlspecialchars($pay['full_name']) ?></strong></div>
  <?php if ($pay['business_name']): ?>
  <div class="receipt-row"><span>Business Name:</span><strong><?= htmlspecialchars($pay['business_name']) ?></strong></div>
  <?php endif; ?>
  <?php if ($pay['ward_name']): ?>
  <div class="receipt-row"><span>Ward:</span><strong><?= htmlspecialchars($pay['ward_name']) ?></strong></div>
  <?php endif; ?>
  <div class="receipt-row"><span>Revenue Source:</span><strong><?= htmlspecialchars($pay['source_name']) ?></strong></div>
  <div class="receipt-row"><span>Financial Year:</span><strong><?= htmlspecialchars($pay['financial_year']) ?></strong></div>
  <div class="receipt-row"><span>Payment Method:</span><strong><?= ucwords(str_replace('_',' ',$pay['payment_method'])) ?></strong></div>
  <?php if ($pay['transaction_reference']): ?>
  <div class="receipt-row"><span>Transaction Ref:</span><strong><?= htmlspecialchars($pay['transaction_reference']) ?></strong></div>
  <?php endif; ?>
  <?php if ($pay['bank_name']): ?>
  <div class="receipt-row"><span>Bank:</span><strong><?= htmlspecialchars($pay['bank_name']) ?></strong></div>
  <?php endif; ?>

  <hr style="border-color:var(--primary);margin:.8rem 0;">

  <div class="receipt-amount"><?= CURRENCY ?> <?= number_format($pay['amount'], 0) ?></div>
  <div class="receipt-words"><em><?= numberToWords($pay['amount']) ?></em></div>

  <hr style="border-color:var(--primary);margin:1rem 0;">

  <div class="receipt-footer">
    <div class="receipt-sig">
      <div style="height:40px;"></div>
      <div class="sig-line">Revenue Officer</div>
      <div style="font-size:.75rem;color:#6c757d;margin-top:.3rem;"><?= htmlspecialchars($pay['officer_name']) ?></div>
    </div>
    <div style="text-align:center;">
      <!-- QR code / Verification link -->
      <div style="text-align:center;">
        <div style="width:90px;height:90px;border:2px solid var(--border);border-radius:6px;display:flex;flex-direction:column;align-items:center;justify-content:center;margin:0 auto;background:#f8f9fa;padding:6px;">
          <div style="font-size:.6rem;color:var(--text-muted);font-weight:700;letter-spacing:.5px;text-transform:uppercase;margin-bottom:2px;">Scan to Verify</div>
          <!-- QR data URL (Google Charts API for QR) -->
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=70x70&data=<?= urlencode(APP_URL . '/verify.php?ref=' . $pay['receipt_number']) ?>"
               alt="QR Code" width="70" height="70" style="display:block;">
        </div>
        <div style="font-size:.65rem;color:var(--text-muted);margin-top:.3rem;"><?= htmlspecialchars($pay['receipt_number']) ?></div>
      </div>
    </div>
    <div class="receipt-sig">
      <div style="height:40px;"></div>
      <div class="sig-line">Authorized Signatory</div>
      <div style="font-size:.75rem;color:#6c757d;margin-top:.3rem;"><?= htmlspecialchars($councilName) ?></div>
    </div>
  </div>

    <div style="text-align:center;margin-top:1.5rem;font-size:.72rem;color:var(--text-muted);border-top:1px dotted #ccc;padding-top:.8rem;">
      This is an official receipt of <?= htmlspecialchars($councilName) ?>. Keep for your records.<br>
      Verify online: <strong><?= APP_URL ?>/verify.php?ref=<?= urlencode($pay['receipt_number']) ?></strong><br>
      Printed: <?= date('d/m/Y H:i:s') ?> by <?= htmlspecialchars($user['full_name']) ?>
    </div>

</div>

<?php
renderPageEnd();
echo '</div></div>';
renderFooter();
?>
