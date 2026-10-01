<?php
/**
 * TCMS Receipt Verification Page
 * Public — no login required.
 * Accessed via QR code on printed receipts.
 * URL: /verify.php?ref=RCP-2026-000001
 */
require_once __DIR__ . '/config/database.php';

// APP_URL may not be defined if accessed before full boot — derive it
if (!defined('APP_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('APP_URL', $scheme . '://' . $host);
}

$ref     = trim($_GET['ref'] ?? '');
$receipt = null;
$error   = '';

$council     = getSystemSetting('council_name')    ?? 'Kijura Town Council';
$councilAddr = getSystemSetting('council_address') ?? '';
$councilTel  = getSystemSetting('council_phone')   ?? '';
$appUrl      = defined('APP_URL') ? APP_URL : '';

if ($ref) {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT rp.receipt_number, rp.amount, rp.payment_date,
                   rp.payment_method, rp.status, rp.financial_year,
                   rp.transaction_reference, rp.created_at,
                   p.full_name, p.business_name, p.payer_number,
                   rs.name AS source_name, w.name AS ward_name,
                   u.full_name AS officer_name
            FROM revenue_payments rp
            JOIN payers p  ON rp.payer_id = p.id
            JOIN revenue_sources rs ON rp.revenue_source_id = rs.id
            LEFT JOIN wards w ON rp.ward_id = w.id
            LEFT JOIN users u ON rp.collected_by = u.id
            WHERE rp.receipt_number = ?
        ");
        $stmt->execute([$ref]);
        $receipt = $stmt->fetch();
        if (!$receipt) {
            $error = 'No receipt found with reference: <strong>' . htmlspecialchars($ref) . '</strong>. This receipt may not exist or the reference is incorrect.';
        }
    } catch (Exception $e) {
        $error = 'Verification service temporarily unavailable. Please try again later.';
    }
}

$isValid  = $receipt && $receipt['status'] === 'active';
$isVoided = $receipt && $receipt['status'] === 'voided';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Receipt Verification — <?= htmlspecialchars($council) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #1a3a5c; --accent: #c8a84b;
      --success: #1e7e34; --danger: #bd2130; --warning: #d39e00;
      --bg: #f4f6f9; --card: #fff; --border: #dee2e6;
      --text: #1c2b3a; --muted: #6c757d;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
    .page-wrap { min-height: 100vh; display: flex; flex-direction: column; }

    /* ── Top Bar ── */
    .top-bar { background: var(--primary); padding: 14px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 4px solid var(--accent); }
    .logo-box { width: 40px; height: 40px; background: var(--accent); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 900; color: var(--primary); flex-shrink: 0; }
    .top-bar-text h1 { color: #fff; font-size: 15px; font-weight: 800; }
    .top-bar-text p  { color: rgba(255,255,255,.6); font-size: 11px; }

    /* ── Content ── */
    .content { flex: 1; padding: 30px 16px; display: flex; flex-direction: column; align-items: center; }
    .card { background: var(--card); border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,.1); width: 100%; max-width: 580px; overflow: hidden; }

    /* ── Search form ── */
    .search-section { padding: 30px; }
    .search-section h2 { font-size: 17px; font-weight: 800; color: var(--primary); margin-bottom: 6px; }
    .search-section p  { font-size: 13px; color: var(--muted); margin-bottom: 20px; }
    .search-row { display: flex; gap: 8px; }
    .search-row input { flex: 1; padding: 10px 14px; border: 2px solid var(--border); border-radius: 6px; font-size: 14px; font-family: inherit; transition: border-color .2s; }
    .search-row input:focus { outline: none; border-color: var(--primary); }
    .search-row button { background: var(--primary); color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; transition: background .2s; }
    .search-row button:hover { background: #245080; }

    /* ── Status badge ── */
    .status-bar { padding: 18px 30px; display: flex; align-items: center; gap: 14px; }
    .status-bar.valid   { background: #d4edda; border-top: 3px solid var(--success); }
    .status-bar.voided  { background: #f8d7da; border-top: 3px solid var(--danger); }
    .status-bar.invalid { background: #fff3cd; border-top: 3px solid var(--warning); }
    .status-icon { font-size: 28px; flex-shrink: 0; }
    .status-text strong { display: block; font-size: 15px; font-weight: 800; }
    .status-text span   { font-size: 12px; }
    .status-bar.valid   .status-text strong { color: var(--success); }
    .status-bar.voided  .status-text strong { color: var(--danger); }
    .status-bar.invalid .status-text strong { color: #856404; }

    /* ── Receipt details ── */
    .receipt-body { padding: 24px 30px; border-top: 1px solid var(--border); }
    .receipt-header { text-align: center; border-bottom: 2px solid var(--primary); padding-bottom: 16px; margin-bottom: 16px; }
    .receipt-header h3 { font-size: 16px; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 1px; }
    .receipt-header p  { font-size: 11px; color: var(--muted); margin-top: 3px; }
    .detail-row { display: flex; justify-content: space-between; padding: 7px 0; border-bottom: 1px dotted #e0e0e0; font-size: 13px; }
    .detail-row:last-child { border: none; }
    .detail-row .label { color: var(--muted); }
    .detail-row .value { font-weight: 600; color: var(--text); text-align: right; }
    .amount-box { background: var(--primary); border-radius: 8px; padding: 16px; text-align: center; margin: 16px 0; }
    .amount-box .amt-label { font-size: 11px; color: rgba(255,255,255,.7); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 4px; }
    .amount-box .amt-value { font-size: 26px; font-weight: 900; color: #fff; }
    .verified-stamp { text-align: center; margin: 16px 0 8px; padding: 12px; border: 2px dashed var(--success); border-radius: 8px; }
    .verified-stamp .stamp-text { font-size: 13px; font-weight: 700; color: var(--success); }
    .verified-stamp .stamp-sub  { font-size: 11px; color: var(--muted); margin-top: 3px; }
    .voided-stamp { text-align: center; margin: 16px 0 8px; padding: 12px; border: 2px dashed var(--danger); border-radius: 8px; background: #fff5f5; }
    .voided-stamp .stamp-text { font-size: 13px; font-weight: 700; color: var(--danger); }

    /* ── Error ── */
    .error-box { padding: 24px 30px; }
    .error-alert { background: #fff3cd; border-left: 4px solid var(--warning); padding: 14px 16px; border-radius: 0 6px 6px 0; font-size: 13px; line-height: 1.6; }
    .error-alert strong { display: block; margin-bottom: 6px; color: #856404; }

    /* ── Footer ── */
    .page-footer { background: var(--primary); padding: 14px 20px; text-align: center; font-size: 11px; color: rgba(255,255,255,.5); margin-top: auto; }
    .page-footer a { color: var(--accent); }

    @media (max-width: 480px) {
      .content { padding: 16px 10px; }
      .search-section, .receipt-body, .status-bar, .error-box { padding-left: 16px; padding-right: 16px; }
      .search-row { flex-direction: column; }
    }
  </style>
</head>
<body>
<div class="page-wrap">

  <!-- Top bar -->
  <div class="top-bar">
    <div class="logo-box">TC</div>
    <div class="top-bar-text">
      <h1><?= htmlspecialchars($council) ?></h1>
      <p>Official Receipt Verification System</p>
    </div>
  </div>

  <div class="content">
    <div class="card">

      <!-- Search -->
      <div class="search-section">
        <h2>Verify Receipt Authenticity</h2>
        <p>Enter the receipt number printed on your official payment receipt to verify its authenticity.</p>
        <form method="GET" action="">
          <div class="search-row">
            <input type="text" name="ref" value="<?= htmlspecialchars($ref) ?>"
                   placeholder="e.g. RCP-2026-000001" required
                   autocomplete="off" autocapitalize="characters">
            <button type="submit">Verify</button>
          </div>
        </form>
      </div>

      <!-- Result -->
      <?php if ($receipt): ?>

        <!-- Status banner -->
        <div class="status-bar <?= $isValid ? 'valid' : ($isVoided ? 'voided' : 'invalid') ?>">
          <div class="status-icon"><?= $isValid ? '✅' : ($isVoided ? '❌' : '⚠') ?></div>
          <div class="status-text">
            <?php if ($isValid): ?>
            <strong>VERIFIED — AUTHENTIC RECEIPT</strong>
            <span>This receipt is valid and recorded in the <?= htmlspecialchars($council) ?> system.</span>
            <?php elseif ($isVoided): ?>
            <strong>VOIDED RECEIPT</strong>
            <span>This receipt has been officially voided. It is no longer a valid payment document.</span>
            <?php else: ?>
            <strong>UNKNOWN STATUS</strong>
            <span>Receipt status: <?= htmlspecialchars($receipt['status']) ?></span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Receipt details -->
        <div class="receipt-body">
          <div class="receipt-header">
            <h3><?= htmlspecialchars($council) ?></h3>
            <p><?= htmlspecialchars($councilAddr) ?><?= $councilTel ? ' | Tel: ' . htmlspecialchars($councilTel) : '' ?></p>
          </div>

          <div class="amount-box">
            <div class="amt-label">Amount Paid</div>
            <div class="amt-value">UGX <?= number_format($receipt['amount'], 0) ?></div>
          </div>

          <?php
          $details = [
              'Receipt Number'   => $receipt['receipt_number'],
              'Payment Date'     => date('d F Y', strtotime($receipt['payment_date'])),
              'Payer Name'       => $receipt['full_name'] . ($receipt['business_name'] ? ' / ' . $receipt['business_name'] : ''),
              'Payer ID'         => $receipt['payer_number'],
              'Revenue Source'   => $receipt['source_name'],
              'Ward'             => $receipt['ward_name'] ?? '—',
              'Financial Year'   => $receipt['financial_year'],
              'Payment Method'   => ucwords(str_replace('_', ' ', $receipt['payment_method'])),
          ];
          if ($receipt['transaction_reference']) $details['Transaction Ref'] = $receipt['transaction_reference'];
          $details['Revenue Officer'] = $receipt['officer_name'] ?? '—';
          $details['Recorded At']     = date('d/m/Y H:i', strtotime($receipt['created_at']));
          foreach ($details as $label => $value): ?>
          <div class="detail-row">
            <span class="label"><?= $label ?></span>
            <span class="value"><?= htmlspecialchars($value) ?></span>
          </div>
          <?php endforeach; ?>

          <?php if ($isValid): ?>
          <div class="verified-stamp">
            <div class="stamp-text">✓ VERIFIED BY <?= strtoupper(htmlspecialchars($council)) ?></div>
            <div class="stamp-sub">Verified on <?= date('d F Y') ?> at <?= date('H:i:s') ?></div>
          </div>
          <?php elseif ($isVoided): ?>
          <div class="voided-stamp">
            <div class="stamp-text">✕ THIS RECEIPT HAS BEEN VOIDED</div>
            <div class="stamp-sub" style="color:var(--danger);">Do not accept this receipt as proof of payment.</div>
          </div>
          <?php endif; ?>
        </div>

      <?php elseif ($error): ?>
        <div class="error-box">
          <div class="error-alert">
            <strong>Receipt Not Found</strong>
            <?= $error ?>
          </div>
          <div style="margin-top:14px;font-size:12px;color:var(--muted);">
            <strong>Tips:</strong>
            <ul style="margin:6px 0 0 16px;line-height:1.8;">
              <li>Check that the receipt number is entered exactly as printed (e.g. RCP-2026-000001).</li>
              <li>Receipt numbers are case-sensitive. Use capital letters.</li>
              <li>If you believe this receipt is genuine, contact <?= htmlspecialchars($council) ?> at <?= htmlspecialchars($councilTel) ?>.</li>
            </ul>
          </div>
        </div>

      <?php elseif ($ref): ?>
        <div class="error-box">
          <div class="error-alert" style="background:#f8d7da;border-left-color:var(--danger);">
            <strong style="color:#721c24;">Verification Service Error</strong>
            Unable to verify receipt at this time. Please try again or contact the council directly.
          </div>
        </div>
      <?php endif; ?>

    </div>

    <!-- Security notice -->
    <div style="max-width:580px;width:100%;margin-top:16px;padding:0 4px;">
      <p style="font-size:11px;color:var(--muted);text-align:center;line-height:1.7;">
        🔒 This verification system is provided by <?= htmlspecialchars($council) ?>.
        Only receipts issued through the official management system can be verified here.
        Fraudulent receipts cannot be verified. Report suspected fraud to <?= htmlspecialchars($councilTel) ?>.
      </p>
    </div>
  </div>

  <!-- Footer -->
  <div class="page-footer">
    <?= htmlspecialchars($council) ?> &mdash; Official Receipt Verification &mdash;
    &copy; <?= date('Y') ?> |
    <a href="<?= $appUrl ?>">Staff Login</a>
  </div>

</div>
</body>
</html>
