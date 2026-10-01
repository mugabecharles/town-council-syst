<?php
/**
 * TCMS Demand Notice Generator
 * Generates printable/downloadable demand letters for revenue defaulters.
 * Supports: single payer or bulk (all defaulters in a ward/source).
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();

if (!hasRole(['admin','town_clerk','finance_officer','revenue_officer'])) {
    setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit;
}

$user = getCurrentUser();
$db   = getDB();
$fy   = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);

// ── Filters ───────────────────────────────────────────────────────
$payerId = (int)($_GET['payer_id'] ?? 0);
$wardId  = (int)($_GET['ward_id']  ?? 0);
$srcId   = (int)($_GET['source_id']?? 0);
$minBal  = (float)($_GET['min_balance'] ?? 0);
$print   = isset($_GET['print']);
$pdf     = isset($_GET['pdf']);

// ── Load defaulters ───────────────────────────────────────────────
$where  = ["ra.status IN ('active','partial','overdue')", "ra.balance > 0", "ra.financial_year=?"];
$params = [$fy];
if ($payerId) { $where[] = "ra.payer_id=?";           $params[] = $payerId; }
if ($wardId)  { $where[] = "p.ward_id=?";             $params[] = $wardId; }
if ($srcId)   { $where[] = "ra.revenue_source_id=?";  $params[] = $srcId; }
if ($minBal)  { $where[] = "ra.balance >= ?";         $params[] = $minBal; }
$wSQL = implode(' AND ', $where);

$stmt = $db->prepare("
    SELECT ra.*, p.payer_number, p.full_name, p.business_name, p.phone, p.address,
           rs.name AS source_name, w.name AS ward_name,
           DATEDIFF(CURDATE(), ra.due_date) AS days_overdue
    FROM revenue_assessments ra
    JOIN payers p ON ra.payer_id = p.id
    JOIN revenue_sources rs ON ra.revenue_source_id = rs.id
    LEFT JOIN wards w ON p.ward_id = w.id
    WHERE $wSQL
    ORDER BY ra.balance DESC
    LIMIT 200
");
$stmt->execute($params);
$defaulters = $stmt->fetchAll();

$council    = getSystemSetting('council_name')    ?? 'Kijura Town Council';
$councilAddr= getSystemSetting('council_address') ?? 'P.O. Box 100, Kijura';
$councilTel = getSystemSetting('council_phone')   ?? '+256 414 000000';
$councilEmail=getSystemSetting('council_email')   ?? 'info@kijuratc.go.ug';
$today      = date('d F Y');
$wards      = $db->query("SELECT * FROM wards WHERE is_active=1 ORDER BY name")->fetchAll();
$sources    = $db->query("SELECT * FROM revenue_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears     = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

// ── Print / PDF mode ─────────────────────────────────────────────
if ($print || $pdf) {
    // Output printable HTML that browser can print to PDF
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Demand Notices — <?= htmlspecialchars($council) ?></title>
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: 'Times New Roman', Times, serif; font-size: 11pt; color: #000; background: #fff; }
    .page { width: 210mm; min-height: 297mm; padding: 20mm 20mm 20mm 25mm; page-break-after: always; position: relative; }
    .page:last-child { page-break-after: avoid; }
    .header-table { width: 100%; border-bottom: 3px double #1a3a5c; padding-bottom: 10px; margin-bottom: 16px; }
    .logo-box { width: 55px; height: 55px; background: #1a3a5c; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; color: #c8a84b; font-size: 16pt; font-weight: 900; text-align: center; line-height: 55px; vertical-align: middle; }
    .council-name { font-size: 14pt; font-weight: bold; color: #1a3a5c; text-transform: uppercase; letter-spacing: 1px; }
    .council-sub { font-size: 9pt; color: #555; margin-top: 3px; }
    .doc-title { text-align: center; font-size: 13pt; font-weight: bold; text-decoration: underline; text-transform: uppercase; color: #1a3a5c; margin: 14px 0 10px; letter-spacing: 2px; }
    .ref-date { display: flex; justify-content: space-between; margin-bottom: 14px; font-size: 10pt; }
    .salutation { margin-bottom: 10px; }
    .addressee { margin-bottom: 16px; line-height: 1.6; }
    .addressee strong { font-size: 11pt; }
    .body-text { line-height: 1.8; margin-bottom: 12px; text-align: justify; }
    .demand-table { width: 100%; border-collapse: collapse; margin: 14px 0; font-size: 10pt; }
    .demand-table th { background: #1a3a5c; color: #fff; padding: 6px 10px; text-align: left; border: 1px solid #1a3a5c; }
    .demand-table td { padding: 6px 10px; border: 1px solid #ccc; }
    .demand-table tr:nth-child(even) td { background: #f8f8f8; }
    .amount-row td { font-weight: bold; background: #fffbeb !important; border-top: 2px solid #c8a84b; }
    .warning-box { border: 2px solid #bd2130; padding: 10px 14px; margin: 14px 0; border-radius: 4px; background: #fff5f5; }
    .warning-box p { color: #721c24; font-size: 10pt; line-height: 1.6; }
    .payment-methods { margin: 12px 0; }
    .payment-methods li { line-height: 1.8; font-size: 10pt; }
    .signature-block { margin-top: 30px; display: flex; justify-content: space-between; }
    .sig-line { width: 200px; border-top: 1px solid #000; margin-top: 40px; font-size: 9pt; padding-top: 4px; }
    .footer { position: absolute; bottom: 15mm; left: 25mm; right: 20mm; border-top: 1px solid #ccc; padding-top: 6px; font-size: 8pt; color: #666; display: flex; justify-content: space-between; }
    .notice-num { position: absolute; top: 10mm; right: 20mm; font-size: 8pt; color: #999; }
    @media print {
      body { background: white; }
      .no-print { display: none; }
      @page { size: A4; margin: 0; }
    }
  </style>
</head>
<body>

<!-- Print controls — hidden when printing -->
<div class="no-print" style="background:#1a3a5c;padding:12px 20px;display:flex;gap:10px;align-items:center;">
  <span style="color:#fff;font-weight:700;font-family:sans-serif;">
    <?= count($defaulters) ?> Demand Notice<?= count($defaulters)!==1?'s':'' ?> — <?= htmlspecialchars($council) ?>
  </span>
  <button onclick="window.print()" style="background:#c8a84b;color:#1a3a5c;border:none;padding:8px 20px;border-radius:4px;font-weight:700;cursor:pointer;font-size:13px;margin-left:auto;">🖨 Print All</button>
  <a href="javascript:history.back()" style="background:rgba(255,255,255,.15);color:#fff;padding:8px 16px;border-radius:4px;text-decoration:none;font-size:13px;font-family:sans-serif;">← Back</a>
</div>

<?php if (empty($defaulters)): ?>
<div style="padding:40px;text-align:center;font-family:sans-serif;color:#666;">
  <h3>No defaulters found for the selected filters.</h3>
  <a href="demand_notice.php" style="color:#1a3a5c;">← Go Back</a>
</div>
<?php else: ?>

<?php
$noticeNum = 1;
foreach ($defaulters as $d):
  $refNum   = 'DN/' . date('Y') . '/' . str_pad($noticeNum, 4, '0', STR_PAD_LEFT);
  $dueDate  = $d['due_date'] ? date('d F Y', strtotime($d['due_date'])) : 'Immediately';
  $name     = $d['business_name'] ? "{$d['full_name']} / {$d['business_name']}" : $d['full_name'];
  $overdue  = (int)$d['days_overdue'];
  $noticeNum++;
?>
<div class="page">
  <span class="notice-num">Notice Ref: <?= $refNum ?></span>

  <!-- Letterhead -->
  <table class="header-table">
    <tr>
      <td style="width:70px;"><div class="logo-box">TC</div></td>
      <td style="padding-left:14px;">
        <div class="council-name"><?= htmlspecialchars($council) ?></div>
        <div class="council-sub"><?= htmlspecialchars($councilAddr) ?> | Tel: <?= htmlspecialchars($councilTel) ?> | <?= htmlspecialchars($councilEmail) ?></div>
      </td>
      <td style="text-align:right;vertical-align:bottom;font-size:9pt;color:#666;">
        Reference: <?= $refNum ?><br>
        Date: <?= $today ?>
      </td>
    </tr>
  </table>

  <div class="doc-title">DEMAND NOTICE FOR PAYMENT OF OUTSTANDING RATES & FEES</div>

  <!-- Addressee -->
  <div class="addressee">
    <strong><?= htmlspecialchars($name) ?></strong><br>
    Payer ID: <?= htmlspecialchars($d['payer_number']) ?><br>
    <?= $d['ward_name'] ? 'Ward: ' . htmlspecialchars($d['ward_name']) : '' ?>
    <?= $d['address'] ? '<br>' . htmlspecialchars($d['address']) : '' ?>
    <?= $d['phone'] ? '<br>Tel: ' . htmlspecialchars($d['phone']) : '' ?>
  </div>

  <div class="salutation">Dear <?= htmlspecialchars($d['full_name']) ?>,</div>

  <p class="body-text">
    <strong>RE: OUTSTANDING OBLIGATION — <?= htmlspecialchars($d['source_name']) ?> — FINANCIAL YEAR <?= $fy ?></strong>
  </p>

  <p class="body-text">
    Our records indicate that you have an outstanding obligation to <?= htmlspecialchars($council) ?> as detailed below. Despite previous reminders, this amount remains unpaid.
  </p>

  <!-- Amount table -->
  <table class="demand-table">
    <thead>
      <tr>
        <th>Description</th>
        <th>Assessment Ref.</th>
        <th style="text-align:right;">Total Due (UGX)</th>
        <th style="text-align:right;">Amount Paid (UGX)</th>
        <th style="text-align:right;">Outstanding (UGX)</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><?= htmlspecialchars($d['source_name']) ?></td>
        <td><?= htmlspecialchars($d['assessment_number']) ?></td>
        <td style="text-align:right;"><?= number_format($d['total_due']) ?></td>
        <td style="text-align:right;"><?= number_format($d['amount_paid']) ?></td>
        <td style="text-align:right;"><?= number_format($d['balance']) ?></td>
      </tr>
      <?php if ($d['penalty_amount'] > 0): ?>
      <tr>
        <td colspan="2">Penalty/Surcharge</td>
        <td style="text-align:right;"><?= number_format($d['penalty_amount']) ?></td>
        <td style="text-align:right;">—</td>
        <td style="text-align:right;"><?= number_format($d['penalty_amount']) ?></td>
      </tr>
      <?php endif; ?>
      <tr class="amount-row">
        <td colspan="4" style="text-align:right;"><strong>TOTAL OUTSTANDING AMOUNT:</strong></td>
        <td style="text-align:right;font-size:12pt;"><strong>UGX <?= number_format($d['balance']) ?></strong></td>
      </tr>
    </tbody>
  </table>

  <?php if ($overdue > 0): ?>
  <div class="warning-box">
    <p><strong>⚠ OVERDUE NOTICE:</strong> This obligation was due on <?= $dueDate ?> and is now <strong><?= $overdue ?> days overdue</strong>. Continued non-payment may attract additional penalties and legal recovery action.</p>
  </div>
  <?php else: ?>
  <p class="body-text">Payment is due by: <strong><?= $dueDate ?></strong>.</p>
  <?php endif; ?>

  <p class="body-text">
    You are hereby <strong>demanded to pay the above outstanding amount</strong> within <strong>14 days</strong> from the date of this notice. Failure to settle this outstanding balance will result in:
  </p>
  <ul style="margin:0 0 12px 20px;line-height:1.8;font-size:10pt;">
    <li>Imposition of additional penalties as provided under applicable local government laws.</li>
    <li>Suspension or cancellation of relevant permits and licenses.</li>
    <li>Legal recovery proceedings and enforcement action at your cost.</li>
  </ul>

  <!-- Payment methods -->
  <div class="payment-methods">
    <p style="font-weight:bold;margin-bottom:6px;">Payment may be made through the following channels:</p>
    <ul style="margin-left:20px;">
      <li>Cash payment at the Town Council Revenue Office during working hours (8:00am – 5:00pm).</li>
      <li>Bank transfer to the official council account — contact the Finance Office for details.</li>
      <li>Mobile Money — contact the Revenue Office for the designated collection number.</li>
    </ul>
  </div>

  <p class="body-text">
    After making payment, please bring your official receipt to the Revenue Office for updating of your records. For inquiries, contact us at <?= htmlspecialchars($councilTel) ?> or <?= htmlspecialchars($councilEmail) ?>.
  </p>

  <!-- Signatures -->
  <div class="signature-block">
    <div>
      <div class="sig-line">
        Revenue Officer<br>
        <?= htmlspecialchars($council) ?>
      </div>
    </div>
    <div>
      <div class="sig-line">
        Town Clerk<br>
        <?= htmlspecialchars($council) ?>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer">
    <span><?= htmlspecialchars($council) ?> | <?= htmlspecialchars($councilAddr) ?></span>
    <span>Page 1 of 1 | Ref: <?= $refNum ?></span>
    <span>Printed: <?= date('d/m/Y H:i') ?></span>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

</body>
</html>
    <?php
    exit;
}

// ── Normal page (filter + preview) ───────────────────────────────
renderHead('Demand Notices');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Demand Notice Generator', 'Generate official demand letters for defaulters');
renderPageStart('Demand Notice Generator', '', [
    ['url' => APP_URL . '/dashboard.php', 'label' => 'Dashboard'],
    ['url' => APP_URL . '/modules/revenue/arrears.php', 'label' => 'Arrears'],
    ['url' => '#', 'label' => 'Demand Notices'],
]);
renderPageActions('');
renderFlashMessages();
?>

<div class="grid-2" style="align-items:start;">

  <!-- Filter Panel -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◆</span> Generate Demand Notices</h5></div>
    <div class="card-body">
      <form method="GET" id="filterForm">
        <div class="form-group">
          <label class="form-label">Financial Year <span class="req">*</span></label>
          <select name="fy" class="form-select" required>
            <?php foreach ($fyears as $y): ?>
            <option value="<?= $y ?>" <?= $y===$fy?'selected':'' ?>><?= $y ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Ward (optional — leave blank for all wards)</label>
          <select name="ward_id" class="form-select">
            <option value="">All Wards</option>
            <?php foreach ($wards as $w): ?>
            <option value="<?= $w['id'] ?>" <?= $wardId==$w['id']?'selected':'' ?>><?= htmlspecialchars($w['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Revenue Source (optional)</label>
          <select name="source_id" class="form-select">
            <option value="">All Sources</option>
            <?php foreach ($sources as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $srcId==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Minimum Outstanding Balance (UGX)</label>
          <input type="number" name="min_balance" class="form-control" min="0" step="1000"
                 value="<?= $minBal > 0 ? $minBal : '' ?>" placeholder="e.g. 50000">
          <div class="form-text">Leave blank to include all outstanding balances.</div>
        </div>
        <div class="form-group">
          <label class="form-label">Specific Payer ID (for single notice)</label>
          <input type="number" name="payer_id" class="form-control" min="0"
                 value="<?= $payerId > 0 ? $payerId : '' ?>" placeholder="Leave blank for all defaulters">
        </div>

        <hr class="divider">

        <div style="display:flex;gap:.6rem;flex-direction:column;">
          <button type="submit" class="btn btn-outline-primary">Preview List</button>
          <button type="submit" name="print" value="1" class="btn btn-primary">
            🖨 Generate & Print Notices (<?= count($defaulters) ?> found)
          </button>
          <?php if (!empty($defaulters)): ?>
          <a href="<?= APP_URL ?>/api/export.php?type=revenue_arrears&fy=<?= urlencode($fy) ?>&ward_id=<?= $wardId ?>&source_id=<?= $srcId ?>"
             class="btn btn-outline-secondary">📊 Export to Excel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Preview List -->
  <div class="card">
    <div class="card-header">
      <h5><span class="ch-icon">▲</span> Defaulters Preview</h5>
      <span class="badge badge-danger"><?= count($defaulters) ?> defaulter<?= count($defaulters)!==1?'s':'' ?></span>
    </div>
    <div class="card-body p-0">
      <?php if (!empty($defaulters)): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead>
            <tr>
              <th>Payer</th>
              <th>Source</th>
              <th class="text-right">Outstanding</th>
              <th>Days OD</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($defaulters as $d): ?>
          <tr>
            <td>
              <div class="fw-600"><?= htmlspecialchars($d['full_name']) ?></div>
              <?php if ($d['business_name']): ?><div class="fs-xs text-muted"><?= htmlspecialchars($d['business_name']) ?></div><?php endif; ?>
              <div class="fs-xs text-primary"><?= htmlspecialchars($d['payer_number']) ?></div>
            </td>
            <td class="fs-sm"><?= htmlspecialchars($d['source_name']) ?></td>
            <td class="text-right fw-700 text-danger"><?= number_format($d['balance']) ?></td>
            <td class="text-center">
              <?php if ((int)$d['days_overdue'] > 0): ?>
              <span class="badge badge-danger"><?= (int)$d['days_overdue'] ?></span>
              <?php else: ?><span class="badge badge-warning">—</span><?php endif; ?>
            </td>
            <td>
              <a href="?fy=<?= urlencode($fy) ?>&payer_id=<?= $d['payer_id'] ?>&print=1"
                 target="_blank" class="btn btn-sm btn-outline-primary">Notice</a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="2" class="text-right">TOTAL OUTSTANDING:</th>
              <th class="text-right text-danger">UGX <?= number_format(array_sum(array_column($defaulters,'balance'))) ?></th>
              <th colspan="2"></th>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon">✅</div>
        <h5>No defaulters found</h5>
        <p>Adjust your filters or check the arrears page.</p>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php
renderPageEnd();
echo '</div></div>';
renderFooter();
?>
