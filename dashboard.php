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

// ─── Financial Intelligence (new) ────────────────────────────────────────────
$budgetAlerts   = checkBudgetAlerts();          // fires new alert notifications
$activeAlerts   = getActiveBudgetAlerts($fy);   // all unresolved alerts
$forecast       = getRevenueForecast($fy);       // revenue forecast
$cashFlow       = getCashFlowData($fy);          // cash position
$budgetVariance = getBudgetVarianceSummary($fy); // all dept budgets

// Departments over 80%
$deptWarnings = array_filter($budgetVariance, fn($b) => (float)$b['utilization_pct'] >= 80);
$deptExceeded = array_filter($budgetVariance, fn($b) => (float)$b['utilization_pct'] >= 100);

renderHead('Dashboard');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Management Dashboard', 'Financial Year: ' . $fy . ' | ' . date('l, d F Y'));
renderPageStart('Management Dashboard', '');
renderPageActions('<span style="font-size:.82rem;color:#6c757d;">Last updated: ' . date('H:i:s') . '</span>
  <a href="' . APP_URL . '/modules/reports/cashflow.php" class="btn btn-outline-primary">📊 Cash Flow & Intelligence</a>');
renderFlashMessages();

// ── Budget Alert Banners ──────────────────────────────────────────────────────
?>
<?php if (!empty($activeAlerts)): ?>
<div style="margin-bottom:1.2rem;" id="alertContainer">
  <?php foreach (array_slice($activeAlerts, 0, 3) as $al):
    $cfg = [
      'warning_80'   => ['cls'=>'alert-warning', 'icon'=>'⚠',  'lbl'=>'80% Budget Warning'],
      'warning_90'   => ['cls'=>'alert-danger',  'icon'=>'🔴', 'lbl'=>'90% Critical Alert'],
      'exceeded_100' => ['cls'=>'alert-danger',  'icon'=>'🚨', 'lbl'=>'Budget Exceeded'],
    ][$al['alert_level']] ?? ['cls'=>'alert-warning','icon'=>'⚠','lbl'=>'Alert'];
  ?>
  <div class="alert <?= $cfg['cls'] ?>" data-auto-dismiss="12000">
    <span><?= $cfg['icon'] ?></span>
    <div>
      <strong><?= $cfg['lbl'] ?>: <?= htmlspecialchars($al['dept_name']) ?></strong>
      — <?= $al['utilization_pct'] ?>% utilization
      (UGX <?= number_format($al['spent_amount']) ?> spent of UGX <?= number_format($al['effective_budget']) ?>)
    </div>
    <a href="<?= APP_URL ?>/modules/reports/cashflow.php" class="btn btn-sm btn-outline-secondary no-print" style="margin-left:auto;white-space:nowrap;">View Details</a>
    <button class="alert-close">✕</button>
  </div>
  <?php endforeach; ?>
  <?php if (count($activeAlerts) > 3): ?>
  <div class="alert alert-warning">
    <span>ℹ</span>
    <span>And <?= count($activeAlerts) - 3 ?> more budget alerts. <a href="<?= APP_URL ?>/modules/reports/cashflow.php">View all on Cash Flow page.</a></span>
    <button class="alert-close">✕</button>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php
?>

<!-- ═══ FINANCIAL INTELLIGENCE PANEL ═════════════════════════════════════════ -->
<div class="grid-3" style="margin-bottom:1.2rem;">

  <!-- Revenue Forecast Card -->
  <div class="card" style="border-top:3px solid <?= ($forecast['on_track'] ?? false) ? 'var(--success)' : 'var(--warning)' ?>;">
    <div class="card-header">
      <h5><span class="ch-icon">🎯</span> Revenue Forecast</h5>
      <span class="badge <?= ($forecast['on_track'] ?? false) ? 'badge-success' : 'badge-warning' ?>">
        <?= ($forecast['on_track'] ?? false) ? 'ON TRACK' : 'BELOW TARGET' ?>
      </span>
    </div>
    <div class="card-body">
      <div style="font-size:.85rem;color:var(--text-muted);margin-bottom:.6rem;">At current pace:</div>
      <div style="font-size:1.2rem;font-weight:800;color:var(--primary);margin-bottom:.3rem;">
        UGX <?= number_format(($forecast['projected_annual'] ?? 0) / 1000000, 1) ?>M projected
      </div>
      <div style="font-size:.82rem;margin-bottom:.8rem;color:<?= ($forecast['on_track'] ?? false) ? 'var(--success)' : 'var(--danger)' ?>;">
        <?= ($forecast['on_track'] ?? false)
          ? '▲ Surplus: UGX ' . number_format($forecast['surplus'] ?? 0)
          : '▼ Shortfall: UGX ' . number_format($forecast['shortfall'] ?? 0) ?>
      </div>
      <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:.5rem;">
        Daily average: UGX <?= number_format($forecast['daily_rate'] ?? 0) ?>
        · <?= $forecast['days_remaining'] ?? 0 ?> days left
      </div>
      <div class="progress" style="height:8px;">
        <div class="progress-bar <?= ($forecast['on_track'] ?? false) ? 'bg-success' : 'bg-warning' ?>"
             style="width:<?= min(100, $forecast['target_pct_collected'] ?? 0) ?>%"></div>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--text-muted);margin-top:.3rem;">
        <span><?= $forecast['target_pct_collected'] ?? 0 ?>% collected</span>
        <span><?= $forecast['year_pct_elapsed'] ?? 0 ?>% of year elapsed</span>
      </div>
      <div style="margin-top:.8rem;padding-top:.8rem;border-top:1px solid var(--border);">
        <div style="font-size:.75rem;color:var(--text-muted);">Performance Index</div>
        <div style="font-size:1.1rem;font-weight:800;color:<?= ($forecast['performance_index'] ?? 0) >= 100 ? 'var(--success)' : 'var(--warning)' ?>;">
          <?= $forecast['performance_index'] ?? 0 ?>%
          <span style="font-size:.72rem;font-weight:400;color:var(--text-muted);">of pace target</span>
        </div>
      </div>
      <a href="<?= APP_URL ?>/modules/reports/cashflow.php" class="btn btn-outline-primary btn-sm btn-block" style="margin-top:.8rem;">Full Forecast →</a>
    </div>
  </div>

  <!-- Net Cash Position Card -->
  <div class="card" style="border-top:3px solid <?= ($cashFlow['net_position'] ?? 0) >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
    <div class="card-header">
      <h5><span class="ch-icon">⚖</span> Cash Position</h5>
      <span class="badge <?= ($cashFlow['net_position'] ?? 0) >= 0 ? 'badge-success' : 'badge-danger' ?>">
        <?= ($cashFlow['net_position'] ?? 0) >= 0 ? 'SURPLUS' : 'DEFICIT' ?>
      </span>
    </div>
    <div class="card-body">
      <div style="text-align:center;padding:.8rem;background:<?= ($cashFlow['net_position'] ?? 0) >= 0 ? 'rgba(30,126,52,.07)' : 'rgba(189,33,48,.07)' ?>;border-radius:8px;margin-bottom:.8rem;">
        <div style="font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);">Net Position FY <?= $fy ?></div>
        <div style="font-size:1.5rem;font-weight:900;color:<?= ($cashFlow['net_position'] ?? 0) >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
          UGX <?= number_format(abs($cashFlow['net_position'] ?? 0) / 1000000, 1) ?>M
        </div>
      </div>
      <div style="font-size:.82rem;">
        <div class="receipt-row"><span class="text-muted">Local Revenue</span><strong class="text-success"><?= number_format(($cashFlow['local_revenue'] ?? 0) / 1000000, 1) ?>M</strong></div>
        <div class="receipt-row"><span class="text-muted">Govt Funds</span><strong class="text-info"><?= number_format(($cashFlow['total_govt'] ?? 0) / 1000000, 1) ?>M</strong></div>
        <div class="receipt-row"><span class="text-muted">Expenditure</span><strong class="text-danger"><?= number_format(($cashFlow['total_expenditure'] ?? 0) / 1000000, 1) ?>M</strong></div>
      </div>
      <div style="margin-top:.8rem;padding-top:.8rem;border-top:1px solid var(--border);font-size:.82rem;">
        <div class="receipt-row">
          <span class="text-muted">This week revenue</span>
          <strong class="<?= ($cashFlow['week_rev_change'] ?? 0) >= 0 ? 'text-success' : 'text-danger' ?>">
            <?= ($cashFlow['week_rev_change'] ?? 0) >= 0 ? '▲' : '▼' ?> <?= abs($cashFlow['week_rev_change'] ?? 0) ?>% vs last week
          </strong>
        </div>
      </div>
      <a href="<?= APP_URL ?>/modules/reports/cashflow.php" class="btn btn-outline-primary btn-sm btn-block" style="margin-top:.8rem;">Full Cash Flow →</a>
    </div>
  </div>

  <!-- Budget Variance Card -->
  <div class="card" style="border-top:3px solid <?= !empty($deptExceeded) ? 'var(--danger)' : (!empty($deptWarnings) ? 'var(--warning)' : 'var(--success)') ?>;">
    <div class="card-header">
      <h5><span class="ch-icon">▣</span> Budget Alerts</h5>
      <?php if (!empty($deptExceeded)): ?>
        <span class="badge badge-danger"><?= count($deptExceeded) ?> Exceeded</span>
      <?php elseif (!empty($deptWarnings)): ?>
        <span class="badge badge-warning"><?= count($deptWarnings) ?> Warning</span>
      <?php else: ?>
        <span class="badge badge-success">All Normal</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if (!empty($deptWarnings)): ?>
        <?php foreach (array_slice($deptWarnings, 0, 4) as $bv):
          $pct   = (float)$bv['utilization_pct'];
          $barCls = $pct >= 100 ? 'bg-danger' : ($pct >= 90 ? 'bg-danger' : 'bg-warning');
          $txtCls = $pct >= 90 ? 'text-danger' : 'text-warning';
        ?>
        <div style="margin-bottom:.8rem;">
          <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.2rem;">
            <span class="fw-600"><?= htmlspecialchars(substr($bv['dept_name'], 0, 20)) ?></span>
            <span class="fw-700 <?= $txtCls ?>"><?= $pct ?>%</span>
          </div>
          <div class="progress" style="height:8px;">
            <div class="progress-bar <?= $barCls ?>" style="width:<?= min(100, $pct) ?>%"></div>
          </div>
          <div style="font-size:.72rem;color:var(--text-muted);margin-top:.15rem;">
            Balance: UGX <?= number_format($bv['balance']) ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (count($deptWarnings) > 4): ?>
        <div style="font-size:.78rem;color:var(--text-muted);margin-bottom:.5rem;">
          + <?= count($deptWarnings) - 4 ?> more departments with warnings
        </div>
        <?php endif; ?>
      <?php else: ?>
        <div style="text-align:center;padding:1rem;color:var(--success);">
          <div style="font-size:2rem;">✓</div>
          <div class="fs-sm fw-600">All budgets within limits</div>
          <div class="fs-xs text-muted">No departments over 80%</div>
        </div>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/modules/reports/cashflow.php#variance" class="btn btn-outline-primary btn-sm btn-block" style="margin-top:.8rem;">Full Variance Report →</a>
    </div>
  </div>

</div>

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
      <?php if ($wardData):
        // Build ward growth lookup
        $wardGrowthMap = [];
        foreach ($forecast['ward_growth'] ?? [] as $wg) {
            $wardGrowthMap[$wg['ward']] = $wg;
        }
      ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Ward</th><th class="text-right">Collected</th><th>MoM Growth</th><th>Progress</th></tr></thead>
          <tbody>
          <?php foreach ($wardData as $w):
            $wPct = $annualRev > 0 ? round(($w['collected'] / max($annualRev,1)) * 100) : 0;
            $wGrow = $wardGrowthMap[$w['name']] ?? null;
            $gr    = $wGrow ? (float)$wGrow['growth_rate'] : null;
          ?>
          <tr>
            <td class="fw-600"><?= htmlspecialchars($w['name']) ?></td>
            <td class="text-right"><?= number_format($w['collected']) ?></td>
            <td class="text-center">
              <?php if ($gr !== null): ?>
              <span class="fw-700 <?= $gr >= 0 ? 'text-success' : 'text-danger' ?>" style="font-size:.8rem;">
                <?= $gr >= 0 ? '▲' : '▼' ?> <?= abs($gr) ?>%
              </span>
              <?php else: ?><span class="text-muted fs-xs">—</span><?php endif; ?>
            </td>
            <td style="min-width:80px;">
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
