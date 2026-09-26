<?php
require_once __DIR__ . '/includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

// ─── Revenue Stats ────────────────────────────────────────────────────────────
$todayRev = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE DATE(payment_date)=CURDATE() AND status='active'");
$todayRev->execute(); $todayRev = (float)$todayRev->fetchColumn();

$monthRev = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE()) AND status='active'");
$monthRev->execute(); $monthRev = (float)$monthRev->fetchColumn();

$annualRev = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'");
$annualRev->execute([$fy]); $annualRev = (float)$annualRev->fetchColumn();

$annualTarget = $db->prepare("SELECT COALESCE(SUM(target_amount),0) FROM revenue_targets WHERE financial_year=?");
$annualTarget->execute([$fy]); $annualTarget = (float)$annualTarget->fetchColumn();
if (!$annualTarget) $annualTarget = 850000000;

$totalArrears = $db->prepare("SELECT COALESCE(SUM(balance),0) FROM revenue_assessments WHERE financial_year=? AND status IN ('active','partial','overdue')");
$totalArrears->execute([$fy]); $totalArrears = (float)$totalArrears->fetchColumn();

// ─── Expenditure Stats ────────────────────────────────────────────────────────
$totalExpend = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE financial_year=? AND status IN ('paid','completed')");
$totalExpend->execute([$fy]); $totalExpend = (float)$totalExpend->fetchColumn();

$pendingApprovals = $db->prepare("SELECT COUNT(*) FROM payment_vouchers WHERE status IN ('submitted','hod_approved','tc_approved','finance_verified')");
$pendingApprovals->execute(); $pendingApprovals = (int)$pendingApprovals->fetchColumn();

// ─── Government Funds ─────────────────────────────────────────────────────────
$govtReceived = $db->prepare("SELECT COALESCE(SUM(amount_received),0) FROM government_funds WHERE financial_year=?");
$govtReceived->execute([$fy]); $govtReceived = (float)$govtReceived->fetchColumn();

$govtUtilized = $db->prepare("SELECT COALESCE(SUM(amount_utilized),0) FROM government_funds WHERE financial_year=?");
$govtUtilized->execute([$fy]); $govtUtilized = (float)$govtUtilized->fetchColumn();

// ─── Revenue by Ward ──────────────────────────────────────────────────────────
$wardData = $db->prepare("SELECT w.name, COALESCE(SUM(rp.amount),0) AS collected 
    FROM wards w LEFT JOIN revenue_payments rp ON rp.ward_id=w.id AND rp.financial_year=? AND rp.status='active'
    GROUP BY w.id ORDER BY collected DESC LIMIT 8");
$wardData->execute([$fy]); $wardData = $wardData->fetchAll();

// ─── Monthly Revenue (last 6 months) ─────────────────────────────────────────
$monthlyData = $db->query("SELECT DATE_FORMAT(payment_date,'%b %Y') AS month_label, 
    SUM(CASE WHEN status='active' THEN amount ELSE 0 END) AS revenue
    FROM revenue_payments WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY YEAR(payment_date), MONTH(payment_date) ORDER BY payment_date ASC")->fetchAll();

// ─── Revenue by Source (top 6) ───────────────────────────────────────────────
$sourceData = $db->prepare("SELECT rs.name, COALESCE(SUM(rp.amount),0) AS total 
    FROM revenue_sources rs LEFT JOIN revenue_payments rp ON rp.revenue_source_id=rs.id AND rp.financial_year=? AND rp.status='active'
    GROUP BY rs.id ORDER BY total DESC LIMIT 6");
$sourceData->execute([$fy]); $sourceData = $sourceData->fetchAll();

// ─── Recent Payments ─────────────────────────────────────────────────────────
$recentPayments = $db->prepare("SELECT rp.receipt_number, p.full_name, rs.name AS source, rp.amount, rp.payment_date, rp.payment_method
    FROM revenue_payments rp JOIN payers p ON rp.payer_id=p.id JOIN revenue_sources rs ON rp.revenue_source_id=rs.id
    WHERE rp.status='active' ORDER BY rp.created_at DESC LIMIT 8");
$recentPayments->execute(); $recentPayments = $recentPayments->fetchAll();

// ─── Pending Vouchers ─────────────────────────────────────────────────────────
$pendingVouchers = $db->prepare("SELECT pv.voucher_number, d.name AS dept, pv.payee_name, pv.amount, pv.status, pv.prepared_date
    FROM payment_vouchers pv JOIN departments d ON pv.department_id=d.id
    WHERE pv.status IN ('submitted','hod_approved','tc_approved','finance_verified') ORDER BY pv.created_at DESC LIMIT 6");
$pendingVouchers->execute(); $pendingVouchers = $pendingVouchers->fetchAll();

// ─── Active Projects ─────────────────────────────────────────────────────────
$activeProjects = $db->query("SELECT name, progress_percent, budget_amount, amount_spent, status FROM projects WHERE status='active' LIMIT 5")->fetchAll();

$collectionRate = $annualTarget > 0 ? round(($annualRev / $annualTarget) * 100, 1) : 0;
$govtUtilRate   = $govtReceived > 0 ? round(($govtUtilized / $govtReceived) * 100, 1) : 0;

renderHead('Dashboard');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Management Dashboard', 'Financial Year: ' . $fy . ' | ' . date('l, d F Y'));
renderPageStart('Management Dashboard', '');
renderPageActions('<span style="font-size:.82rem;color:#6c757d;">Last updated: ' . date('H:i:s') . '</span>');
renderFlashMessages();
?>

<!-- ═══ KPI STAT CARDS ════════════════════════════════════════════════════════ -->
<div class="grid-5" style="margin-bottom:1.2rem;">

  <div class="stat-card green">
    <div class="stat-icon">💰</div>
    <div class="stat-info">
      <div class="label">Today's Revenue</div>
      <div class="value"><?= number_format($todayRev/1000000, 1) ?>M</div>
      <div class="sub">UGX <?= number_format($todayRev) ?></div>
    </div>
  </div>

  <div class="stat-card blue">
    <div class="stat-icon">📅</div>
    <div class="stat-info">
      <div class="label">This Month</div>
      <div class="value"><?= number_format($monthRev/1000000, 1) ?>M</div>
      <div class="sub">UGX <?= number_format($monthRev) ?></div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon">🎯</div>
    <div class="stat-info">
      <div class="label">Annual Collected</div>
      <div class="value"><?= number_format($annualRev/1000000, 1) ?>M</div>
      <div class="sub up"><?= $collectionRate ?>% of target</div>
    </div>
  </div>

  <div class="stat-card amber">
    <div class="stat-icon">⏳</div>
    <div class="stat-info">
      <div class="label">Outstanding</div>
      <div class="value"><?= number_format($totalArrears/1000000, 1) ?>M</div>
      <div class="sub">Revenue arrears</div>
    </div>
  </div>

  <div class="stat-card red">
    <div class="stat-icon">⚖</div>
    <div class="stat-info">
      <div class="label">Pending Approvals</div>
      <div class="value"><?= $pendingApprovals ?></div>
      <div class="sub">Vouchers awaiting action</div>
    </div>
  </div>

</div>

<!-- ═══ SECOND ROW ══════════════════════════════════════════════════════════ -->
<div class="grid-4" style="margin-bottom:1.2rem;">

  <div class="stat-card">
    <div class="stat-icon">🏛</div>
    <div class="stat-info">
      <div class="label">Govt Funds Received</div>
      <div class="value"><?= number_format($govtReceived/1000000, 1) ?>M</div>
      <div class="sub">FY <?= $fy ?></div>
    </div>
  </div>

  <div class="stat-card green">
    <div class="stat-icon">✅</div>
    <div class="stat-info">
      <div class="label">Govt Funds Utilized</div>
      <div class="value"><?= number_format($govtUtilized/1000000, 1) ?>M</div>
      <div class="sub up"><?= $govtUtilRate ?>% utilization</div>
    </div>
  </div>

  <div class="stat-card amber">
    <div class="stat-icon">📤</div>
    <div class="stat-info">
      <div class="label">Total Expenditure</div>
      <div class="value"><?= number_format($totalExpend/1000000, 1) ?>M</div>
      <div class="sub">FY <?= $fy ?></div>
    </div>
  </div>

  <div class="stat-card blue">
    <div class="stat-icon">🎯</div>
    <div class="stat-info">
      <div class="label">Annual Target</div>
      <div class="value"><?= number_format($annualTarget/1000000, 0) ?>M</div>
      <div class="sub">Revenue target FY</div>
    </div>
  </div>

</div>

<!-- ═══ REVENUE TARGET PROGRESS ═════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-header">
    <h5><span class="ch-icon">◈</span> Annual Revenue Collection Progress — FY <?= $fy ?></h5>
    <a href="<?= APP_URL ?>/modules/revenue/dashboard.php" class="btn btn-sm btn-outline-primary">View Details</a>
  </div>
  <div class="card-body">
    <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
      <span style="font-size:.84rem;font-weight:600;">UGX <?= number_format($annualRev) ?> collected of UGX <?= number_format($annualTarget) ?> target</span>
      <span style="font-size:.9rem;font-weight:800;color:<?= $collectionRate >= 75 ? 'var(--success)' : ($collectionRate >= 50 ? 'var(--warning)' : 'var(--danger)') ?>;"><?= $collectionRate ?>%</span>
    </div>
    <div class="progress" style="height:14px;">
      <div class="progress-bar <?= $collectionRate >= 75 ? 'bg-success' : ($collectionRate >= 50 ? 'bg-warning' : 'bg-danger') ?>"
           style="width:<?= min(100,$collectionRate) ?>%"></div>
    </div>
    <div style="display:flex;justify-content:space-between;margin-top:.4rem;font-size:.75rem;color:#6c757d;">
      <span>Remaining: UGX <?= number_format(max(0,$annualTarget - $annualRev)) ?></span>
      <span>Arrears: UGX <?= number_format($totalArrears) ?></span>
    </div>
  </div>
</div>

<!-- ═══ CHARTS ROW ═══════════════════════════════════════════════════════════ -->
<div class="grid-3" style="margin-bottom:1.2rem;">

  <!-- Monthly Revenue -->
  <div class="card" style="grid-column:span 2;">
    <div class="card-header">
      <h5><span class="ch-icon">📈</span> Monthly Revenue Trend</h5>
    </div>
    <div class="card-body">
      <div class="chart-container"><canvas id="monthlyChart"></canvas></div>
    </div>
  </div>

  <!-- Revenue by Source -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◎</span> Revenue by Source</h5></div>
    <div class="card-body">
      <div class="chart-container"><canvas id="sourceChart"></canvas></div>
    </div>
  </div>

</div>

<!-- ═══ WARD PERFORMANCE + PENDING VOUCHERS ══════════════════════════════════ -->
<div class="grid-2" style="margin-bottom:1.2rem;">

  <!-- Ward Performance Table -->
  <div class="card">
    <div class="card-header">
      <h5><span class="ch-icon">▣</span> Revenue by Ward — FY <?= $fy ?></h5>
      <a href="<?= APP_URL ?>/modules/revenue/dashboard.php" class="btn btn-sm btn-outline-primary">Full Report</a>
    </div>
    <div class="card-body p-0">
      <?php if ($wardData): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Ward</th><th class="text-right">Collected (UGX)</th><th>Progress</th></tr></thead>
          <tbody>
          <?php foreach ($wardData as $w):
            $wPct = $annualRev > 0 ? round(($w['collected'] / max($annualRev,1)) * 100) : 0; ?>
          <tr>
            <td class="fw-600"><?= htmlspecialchars($w['name']) ?></td>
            <td class="text-right"><?= number_format($w['collected']) ?></td>
            <td style="min-width:100px;">
              <div class="progress" style="height:6px;">
                <div class="progress-bar bg-primary" style="width:<?= min(100,$wPct) ?>%"></div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <div class="empty-state"><div class="empty-icon">◈</div><p>No ward data yet.</p></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Pending Vouchers -->
  <div class="card">
    <div class="card-header">
      <h5><span class="ch-icon">▤</span> Pending Payment Vouchers</h5>
      <a href="<?= APP_URL ?>/modules/expenditure/approvals.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body p-0">
      <?php if ($pendingVouchers): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Voucher</th><th>Department</th><th class="text-right">Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($pendingVouchers as $v): ?>
          <tr>
            <td><a href="<?= APP_URL ?>/modules/expenditure/voucher_view.php?id=<?= $v['voucher_number'] ?>" class="fw-600 text-primary"><?= htmlspecialchars($v['voucher_number']) ?></a>
              <div class="fs-xs text-muted"><?= htmlspecialchars(substr($v['payee_name'],0,25)) ?></div>
            </td>
            <td class="fs-sm"><?= htmlspecialchars($v['dept']) ?></td>
            <td class="text-right fw-600"><?= number_format($v['amount']) ?></td>
            <td><?= getStatusBadge($v['status']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <div class="empty-state"><div class="empty-icon">✅</div><h5>All clear</h5><p>No pending approvals.</p></div>
      <?php endif; ?>
    </div>
  </div>

</div>

<!-- ═══ RECENT PAYMENTS + ACTIVE PROJECTS ════════════════════════════════════ -->
<div class="grid-2" style="margin-bottom:1.2rem;">

  <!-- Recent Payments -->
  <div class="card">
    <div class="card-header">
      <h5><span class="ch-icon">◇</span> Recent Revenue Payments</h5>
      <a href="<?= APP_URL ?>/modules/revenue/payments.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body p-0">
      <?php if ($recentPayments): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Receipt</th><th>Payer</th><th>Source</th><th class="text-right">Amount</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach ($recentPayments as $p): ?>
          <tr>
            <td><span class="fw-600 text-primary"><?= htmlspecialchars($p['receipt_number']) ?></span></td>
            <td class="fs-sm"><?= htmlspecialchars(substr($p['full_name'],0,22)) ?></td>
            <td class="fs-sm"><?= htmlspecialchars(substr($p['source'],0,18)) ?></td>
            <td class="text-right fw-600"><?= number_format($p['amount']) ?></td>
            <td class="fs-sm"><?= formatDate($p['payment_date']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <div class="empty-state"><div class="empty-icon">◇</div><p>No payments recorded yet.</p></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Active Projects -->
  <div class="card">
    <div class="card-header">
      <h5><span class="ch-icon">◐</span> Active Projects</h5>
      <a href="<?= APP_URL ?>/modules/projects/index.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body">
      <?php if ($activeProjects): ?>
        <?php foreach ($activeProjects as $proj): ?>
        <div style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid #f0f0f0;">
          <div style="display:flex;justify-content:space-between;margin-bottom:.3rem;">
            <span class="fw-600 fs-sm"><?= htmlspecialchars(substr($proj['name'],0,40)) ?></span>
            <span class="fs-sm fw-600 text-primary"><?= $proj['progress_percent'] ?>%</span>
          </div>
          <div class="progress" style="height:7px;margin-bottom:.3rem;">
            <div class="progress-bar <?= $proj['progress_percent'] >= 75 ? 'bg-success' : ($proj['progress_percent'] >= 40 ? 'bg-primary' : 'bg-warning') ?>"
                 style="width:<?= $proj['progress_percent'] ?>%"></div>
          </div>
          <div class="fs-xs text-muted">Budget: UGX <?= number_format($proj['budget_amount']) ?> | Spent: UGX <?= number_format($proj['amount_spent']) ?></div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state"><div class="empty-icon">◐</div><p>No active projects.</p></div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php
renderPageEnd();
echo '</div></div>'; // close main-content, app-wrapper

// Prepare chart data
$monthLabels = json_encode(array_column($monthlyData, 'month_label'));
$monthValues = json_encode(array_column($monthlyData, 'revenue'));
$srcLabels   = json_encode(array_column($sourceData, 'name'));
$srcValues   = json_encode(array_column($sourceData, 'total'));
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  makeLineChart('monthlyChart',
    <?= $monthLabels ?>,
    [{
      label: 'Revenue Collected (UGX)',
      data: <?= $monthValues ?>,
      borderColor: '#1a3a5c',
      backgroundColor: 'rgba(26,58,92,.08)',
      borderWidth: 2.5,
      fill: true,
      tension: .4,
      pointBackgroundColor: '#1a3a5c',
      pointRadius: 4
    }]
  );
  makeDoughnutChart('sourceChart',
    <?= $srcLabels ?>,
    <?= $srcValues ?>
  );
});
</script>
<?php renderFooter(); ?>
