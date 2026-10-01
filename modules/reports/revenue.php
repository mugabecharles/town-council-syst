<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

$fy       = $_GET['fy']        ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);
$rpt      = $_GET['report']    ?? 'summary';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$wardFil  = (int)($_GET['ward_id']   ?? 0);
$srcFil   = (int)($_GET['source_id'] ?? 0);
$methodF  = trim($_GET['method'] ?? '');

// ─── Grand total filters ──────────────────────────────────────────
$where  = ["rp.status='active'"];
$params = [];
if ($fy)       { $where[] = "rp.financial_year=?";       $params[] = $fy; }
if ($dateFrom) { $where[] = "rp.payment_date >= ?";       $params[] = $dateFrom; }
if ($dateTo)   { $where[] = "rp.payment_date <= ?";       $params[] = $dateTo; }
if ($wardFil)  { $where[] = "rp.ward_id=?";              $params[] = $wardFil; }
if ($srcFil)   { $where[] = "rp.revenue_source_id=?";    $params[] = $srcFil; }
if ($methodF)  { $where[] = "rp.payment_method=?";       $params[] = $methodF; }
$wSQL = implode(' AND ', $where);

// Grand totals
$gtStmt = $db->prepare("SELECT COALESCE(SUM(amount),0), COUNT(*) FROM revenue_payments rp WHERE $wSQL");
$gtStmt->execute($params);
$gtRow      = $gtStmt->fetch(PDO::FETCH_NUM);
$grandTotal = (float)$gtRow[0];
$txnCount   = (int)$gtRow[1];

// ─── Report-specific data ─────────────────────────────────────────
$reportData  = [];
$reportTitle = 'Revenue Collection Summary';

switch ($rpt) {

    case 'by_ward':
        $reportTitle = 'Revenue by Ward';
        $stmt = $db->prepare("
            SELECT w.name AS label,
                   COALESCE(SUM(rp.amount),0)  AS total,
                   COUNT(rp.id)                AS txns
            FROM wards w
            LEFT JOIN revenue_payments rp
                ON rp.ward_id = w.id
                AND rp.status = 'active'
                AND rp.financial_year = ?
                AND rp.payment_date BETWEEN ? AND ?
            GROUP BY w.id
            ORDER BY total DESC");
        $stmt->execute([$fy, $dateFrom, $dateTo]);
        $reportData = $stmt->fetchAll();
        break;

    case 'by_source':
        $reportTitle = 'Revenue by Source';
        $stmt = $db->prepare("
            SELECT rs.name AS label,
                   COALESCE(SUM(rp.amount),0) AS total,
                   COUNT(rp.id)               AS txns
            FROM revenue_sources rs
            LEFT JOIN revenue_payments rp
                ON rp.revenue_source_id = rs.id
                AND rp.status = 'active'
                AND rp.financial_year = ?
                AND rp.payment_date BETWEEN ? AND ?
            GROUP BY rs.id
            ORDER BY total DESC");
        $stmt->execute([$fy, $dateFrom, $dateTo]);
        $reportData = $stmt->fetchAll();
        break;

    case 'by_method':
        $reportTitle = 'Revenue by Payment Method';
        $stmt = $db->prepare("
            SELECT payment_method AS label,
                   SUM(amount)    AS total,
                   COUNT(*)       AS txns
            FROM revenue_payments
            WHERE status='active'
              AND financial_year=?
              AND payment_date BETWEEN ? AND ?
            GROUP BY payment_method
            ORDER BY total DESC");
        $stmt->execute([$fy, $dateFrom, $dateTo]);
        $reportData = $stmt->fetchAll();
        break;

    case 'by_officer':
        $reportTitle = 'Revenue by Officer';
        $stmt = $db->prepare("
            SELECT u.full_name AS label,
                   COALESCE(SUM(rp.amount),0) AS total,
                   COUNT(rp.id)               AS txns
            FROM users u
            LEFT JOIN revenue_payments rp
                ON rp.collected_by = u.id
                AND rp.status = 'active'
                AND rp.financial_year = ?
                AND rp.payment_date BETWEEN ? AND ?
            GROUP BY u.id
            ORDER BY total DESC");
        $stmt->execute([$fy, $dateFrom, $dateTo]);
        $reportData = $stmt->fetchAll();
        break;

    case 'target_vs_actual':
        $reportTitle = 'Revenue Target vs Actual';
        $stmt = $db->prepare("
            SELECT rs.name                          AS source,
                   COALESCE(rt.target_amount, 0)   AS target,
                   COALESCE(SUM(rp.amount), 0)     AS collected
            FROM revenue_sources rs
            LEFT JOIN revenue_targets rt
                ON rt.revenue_source_id = rs.id AND rt.financial_year = ?
            LEFT JOIN revenue_payments rp
                ON rp.revenue_source_id = rs.id
                AND rp.status = 'active'
                AND rp.financial_year = ?
            GROUP BY rs.id
            ORDER BY target DESC");
        $stmt->execute([$fy, $fy]);
        $reportData = $stmt->fetchAll();
        break;

    case 'arrears':
        $reportTitle = 'Revenue Arrears Report';
        $stmt = $db->prepare("
            SELECT p.full_name, p.payer_number, p.phone,
                   w.name AS ward_name, rs.name AS source_name,
                   ra.assessment_number, ra.total_due,
                   ra.amount_paid, ra.balance,
                   DATEDIFF(CURDATE(), ra.due_date) AS days_overdue
            FROM revenue_assessments ra
            JOIN payers p          ON ra.payer_id = p.id
            JOIN revenue_sources rs ON ra.revenue_source_id = rs.id
            LEFT JOIN wards w      ON p.ward_id = w.id
            WHERE ra.financial_year = ?
              AND ra.status IN ('active','partial','overdue')
              AND ra.balance > 0
            ORDER BY ra.balance DESC
            LIMIT 500");
        $stmt->execute([$fy]);
        $reportData = $stmt->fetchAll();
        break;

    default: // summary
        $reportTitle = 'Revenue Collection Summary';
        $stmt = $db->prepare("
            SELECT payment_method,
                   COALESCE(SUM(amount),0) AS total,
                   COUNT(*)                AS txns
            FROM revenue_payments
            WHERE status='active'
              AND financial_year=?
              AND payment_date BETWEEN ? AND ?
            GROUP BY payment_method
            ORDER BY total DESC");
        $stmt->execute([$fy, $dateFrom, $dateTo]);
        $reportData = $stmt->fetchAll();
        break;
}

// ─── Supporting data for filters ─────────────────────────────────
$wards   = $db->query("SELECT * FROM wards WHERE is_active=1 ORDER BY name")->fetchAll();
$sources = $db->query("SELECT * FROM revenue_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);
$council = getSystemSetting('council_name') ?? COUNCIL_NAME;

// ─── Render ───────────────────────────────────────────────────────
renderHead('Revenue Reports');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Revenue Reports', 'Generate and export revenue reports');
renderPageStart('Revenue Reports', '', [
    ['url' => APP_URL . '/dashboard.php', 'label' => 'Dashboard'],
    ['url' => '#', 'label' => 'Revenue Reports']
]);
renderPageActions('
  <button class="btn btn-outline-secondary no-print" data-print>🖨 Print</button>
  <div class="dropdown no-print" style="position:relative;display:inline-block;">
    <button class="btn btn-primary" onclick="this.nextElementSibling.classList.toggle(\'show\')" style="gap:.4rem;">
      📊 Export ▾
    </button>
    <div id="exportDrop" style="display:none;position:absolute;right:0;top:38px;background:#fff;border:1px solid #dee2e6;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.15);z-index:200;min-width:200px;padding:.4rem 0;">
      <a href="' . APP_URL . '/api/export.php?type=revenue_payments&fy=' . urlencode($fy) . '&date_from=' . $dateFrom . '&date_to=' . $dateTo . '" class="dropdown-link">📊 Payments — Excel</a>
      <a href="' . APP_URL . '/api/export.php?type=revenue_payments&fy=' . urlencode($fy) . '&date_from=' . $dateFrom . '&date_to=' . $dateTo . '&format=csv" class="dropdown-link">📄 Payments — CSV</a>
      <hr style="margin:.3rem 0;border-color:#f0f0f0;">
      <a href="' . APP_URL . '/api/export.php?type=revenue_by_ward&fy=' . urlencode($fy) . '" class="dropdown-link">📊 By Ward — Excel</a>
      <a href="' . APP_URL . '/api/export.php?type=revenue_by_source&fy=' . urlencode($fy) . '" class="dropdown-link">📊 By Source — Excel</a>
      <a href="' . APP_URL . '/api/export.php?type=revenue_arrears&fy=' . urlencode($fy) . '" class="dropdown-link">📊 Arrears — Excel</a>
      <hr style="margin:.3rem 0;border-color:#f0f0f0;">
      <a href="' . APP_URL . '/api/export.php?type=payers" class="dropdown-link">📊 Payer Registry — Excel</a>
    </div>
  </div>
  <style>.dropdown-link{display:block;padding:.5rem 1rem;font-size:.82rem;color:#1a3a5c;text-decoration:none;white-space:nowrap;}.dropdown-link:hover{background:#f4f6f9;}</style>
  <script>document.addEventListener("click",function(e){var d=document.getElementById("exportDrop");if(d&&!e.target.closest(".dropdown"))d.style.display="none";});</script>
');
renderFlashMessages();
?>

<!-- Tab navigation -->
<div class="tab-nav" style="margin-bottom:1.2rem;">
  <?php
  $tabs = [
      'summary'        => 'Summary',
      'by_ward'        => 'By Ward',
      'by_source'      => 'By Source',
      'by_method'      => 'By Method',
      'by_officer'     => 'By Officer',
      'target_vs_actual' => 'Target vs Actual',
      'arrears'        => 'Arrears',
  ];
  foreach ($tabs as $key => $label):
  ?>
  <a href="?report=<?= $key ?>&fy=<?= urlencode($fy) ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
     class="tab-link <?= $rpt === $key ? 'active' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<form method="GET">
  <input type="hidden" name="report" value="<?= htmlspecialchars($rpt) ?>">
  <div class="filter-row">
    <div class="form-group">
      <label>Financial Year</label>
      <select name="fy" class="form-select">
        <?php foreach ($fyears as $y): ?>
        <option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>From</label><input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>"></div>
    <div class="form-group"><label>To</label>  <input type="date" name="date_to"   class="form-control" value="<?= $dateTo ?>"></div>
    <?php if (in_array($rpt, ['summary','arrears','by_ward'])): ?>
    <div class="form-group">
      <label>Ward</label>
      <select name="ward_id" class="form-select">
        <option value="">All Wards</option>
        <?php foreach ($wards as $w): ?>
        <option value="<?= $w['id'] ?>" <?= $wardFil == $w['id'] ? 'selected' : '' ?>><?= htmlspecialchars($w['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Source</label>
      <select name="source_id" class="form-select">
        <option value="">All Sources</option>
        <?php foreach ($sources as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $srcFil == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if ($rpt === 'summary'): ?>
    <div class="form-group">
      <label>Method</label>
      <select name="method" class="form-select">
        <option value="">All</option>
        <?php foreach (['cash','bank','mobile_money','cheque','electronic','other'] as $m): ?>
        <option value="<?= $m ?>" <?= $methodF === $m ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$m)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="form-group">
      <label>&nbsp;</label>
      <button type="submit" class="btn btn-primary">Generate</button>
    </div>
  </div>
</form>

<!-- Report Header -->
<div class="card" style="margin-bottom:1rem;">
  <div class="card-body">
    <div style="text-align:center;border-bottom:2px solid var(--primary);padding-bottom:1rem;margin-bottom:1rem;">
      <strong style="font-size:1.1rem;color:var(--primary);"><?= htmlspecialchars($council) ?></strong>
      <div style="font-size:.9rem;font-weight:700;color:var(--accent);margin:.3rem 0;"><?= $reportTitle ?> — OFFICIAL REPORT</div>
      <div class="fs-sm text-muted">Financial Year: <?= $fy ?> | Period: <?= formatDate($dateFrom) ?> to <?= formatDate($dateTo) ?></div>
      <div class="fs-xs text-muted">Generated: <?= date('d/m/Y H:i') ?> by <?= htmlspecialchars($user['full_name']) ?></div>
    </div>
    <div class="grid-3">
      <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:6px;">
        <div class="fs-xs text-muted">TOTAL REVENUE</div>
        <div style="font-size:1.3rem;font-weight:800;color:var(--primary);"><?= CURRENCY ?> <?= number_format($grandTotal) ?></div>
      </div>
      <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:6px;">
        <div class="fs-xs text-muted">TRANSACTIONS</div>
        <div style="font-size:1.3rem;font-weight:800;color:var(--success);"><?= number_format($txnCount) ?></div>
      </div>
      <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:6px;">
        <div class="fs-xs text-muted">AVG PER TRANSACTION</div>
        <div style="font-size:1.3rem;font-weight:800;color:var(--info);"><?= CURRENCY ?> <?= $txnCount > 0 ? number_format($grandTotal / $txnCount) : '0' ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Report Table -->
<div class="card">
  <div class="card-header"><h5><span class="ch-icon">▨</span> <?= $reportTitle ?></h5></div>
  <div class="card-body p-0">
    <?php if (!empty($reportData)): ?>
    <div class="table-wrapper">
      <table class="tcms-table">

        <?php if ($rpt === 'arrears'): ?>
        <thead><tr><th>Payer</th><th>ID</th><th>Phone</th><th>Ward</th><th>Source</th><th class="text-right">Due</th><th class="text-right">Paid</th><th class="text-right">Balance</th><th>Days Overdue</th></tr></thead>
        <tbody>
        <?php foreach ($reportData as $r): ?>
        <tr>
          <td class="fw-600"><?= htmlspecialchars($r['full_name']) ?></td>
          <td class="fs-sm text-primary"><?= htmlspecialchars($r['payer_number']) ?></td>
          <td class="fs-sm"><?= htmlspecialchars($r['phone'] ?? '—') ?></td>
          <td class="fs-sm"><?= htmlspecialchars($r['ward_name'] ?? '—') ?></td>
          <td class="fs-sm"><?= htmlspecialchars($r['source_name']) ?></td>
          <td class="text-right"><?= number_format($r['total_due']) ?></td>
          <td class="text-right text-success"><?= number_format($r['amount_paid']) ?></td>
          <td class="text-right fw-700 text-danger"><?= number_format($r['balance']) ?></td>
          <td class="text-center">
            <?php if ($r['days_overdue'] > 0): ?>
            <span class="badge badge-danger"><?= $r['days_overdue'] ?> days</span>
            <?php else: ?><span class="badge badge-warning">Not due</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th colspan="5">TOTAL</th><th class="text-right"><?= number_format(array_sum(array_column($reportData,'total_due'))) ?></th><th class="text-right"><?= number_format(array_sum(array_column($reportData,'amount_paid'))) ?></th><th class="text-right text-danger fw-700"><?= number_format(array_sum(array_column($reportData,'balance'))) ?></th><th></th></tr></tfoot>

        <?php elseif ($rpt === 'target_vs_actual'): ?>
        <thead><tr><th>Revenue Source</th><th class="text-right">Target (UGX)</th><th class="text-right">Collected (UGX)</th><th class="text-right">Variance</th><th>Achievement</th></tr></thead>
        <tbody>
        <?php foreach ($reportData as $r):
          $achv = $r['target'] > 0 ? round(($r['collected'] / $r['target']) * 100, 1) : 0; ?>
        <tr>
          <td class="fw-600"><?= htmlspecialchars($r['source']) ?></td>
          <td class="text-right"><?= number_format($r['target']) ?></td>
          <td class="text-right fw-700"><?= number_format($r['collected']) ?></td>
          <td class="text-right <?= $r['collected'] >= $r['target'] ? 'text-success' : 'text-danger' ?>"><?= number_format($r['collected'] - $r['target']) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:.5rem;">
              <div class="progress" style="flex:1;height:7px;">
                <div class="progress-bar <?= $achv >= 100 ? 'bg-success' : ($achv >= 75 ? 'bg-primary' : 'bg-warning') ?>" style="width:<?= min(100, $achv) ?>%"></div>
              </div>
              <span class="fs-xs fw-700"><?= $achv ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th>TOTAL</th><th class="text-right"><?= number_format(array_sum(array_column($reportData,'target'))) ?></th><th class="text-right"><?= number_format(array_sum(array_column($reportData,'collected'))) ?></th><th colspan="2"></th></tr></tfoot>

        <?php elseif ($rpt === 'summary'): ?>
        <thead><tr><th>Payment Method</th><th class="text-right">Amount (UGX)</th><th class="text-right">Transactions</th><th>% Share</th></tr></thead>
        <tbody>
        <?php foreach ($reportData as $r):
          $share = $grandTotal > 0 ? round(($r['total'] / $grandTotal) * 100, 1) : 0; ?>
        <tr>
          <td class="fw-600"><?= ucwords(str_replace('_', ' ', $r['payment_method'])) ?></td>
          <td class="text-right fw-700"><?= number_format($r['total']) ?></td>
          <td class="text-right"><?= number_format($r['txns']) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:.5rem;">
              <div class="progress" style="flex:1;height:6px;"><div class="progress-bar bg-primary" style="width:<?= $share ?>%"></div></div>
              <span class="fs-xs"><?= $share ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th>TOTAL</th><th class="text-right"><?= number_format($grandTotal) ?></th><th class="text-right"><?= number_format($txnCount) ?></th><th>100%</th></tr></tfoot>

        <?php else: ?>
        <thead>
          <tr>
            <th><?= $rpt === 'by_ward' ? 'Ward' : ($rpt === 'by_source' ? 'Revenue Source' : ($rpt === 'by_officer' ? 'Officer' : 'Category')) ?></th>
            <th class="text-right">Amount (UGX)</th>
            <th class="text-right">Transactions</th>
            <th>% Share</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($reportData as $r):
          $share = $grandTotal > 0 ? round(($r['total'] / $grandTotal) * 100, 1) : 0; ?>
        <tr>
          <td class="fw-600"><?= htmlspecialchars($r['label'] ?? '') ?></td>
          <td class="text-right fw-700"><?= number_format($r['total']) ?></td>
          <td class="text-right"><?= number_format($r['txns'] ?? 0) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:.5rem;">
              <div class="progress" style="flex:1;height:6px;"><div class="progress-bar bg-primary" style="width:<?= min(100, $share) ?>%"></div></div>
              <span class="fs-xs"><?= $share ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th>TOTAL</th><th class="text-right"><?= number_format($grandTotal) ?></th><th class="text-right"><?= number_format($txnCount) ?></th><th>100%</th></tr></tfoot>
        <?php endif; ?>

      </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon">▨</div>
      <h5>No data for selected period</h5>
      <p>Try changing the date range or financial year filter.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
renderPageEnd();
echo '</div></div>';
renderFooter();
?>
