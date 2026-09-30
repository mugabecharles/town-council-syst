<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

$fy  = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);
$fyears = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

// ── Load all data engines ──────────────────────────────────────────
$cf       = getCashFlowData($fy);
$forecast = getRevenueForecast($fy);
$alerts   = getActiveBudgetAlerts($fy);
$variance = getBudgetVarianceSummary($fy);

// Check and fire any new budget alerts
checkBudgetAlerts();

// ── Helpers ────────────────────────────────────────────────────────
$netPositionClass = ($cf['net_position'] ?? 0) >= 0 ? 'green' : 'red';
$weekChangeClass  = ($cf['week_rev_change'] ?? 0) >= 0 ? 'text-success' : 'text-danger';
$weekChangeIcon   = ($cf['week_rev_change'] ?? 0) >= 0 ? '▲' : '▼';
$onTrackClass     = ($forecast['on_track'] ?? false) ? 'green' : 'amber';

// Chart data
$weekLabels   = json_encode(array_column($cf['weekly_data'] ?? [], 'week_start'));
$weekRevIn    = json_encode(array_map(fn($w) => round($w['revenue_in'] + $w['govt_in']), $cf['weekly_data'] ?? []));
$weekExpOut   = json_encode(array_map(fn($w) => round($w['expenditure_out']), $cf['weekly_data'] ?? []));
$weekNet      = json_encode(array_map(fn($w) => round($w['net']), $cf['weekly_data'] ?? []));
$weekCum      = json_encode(array_map(fn($w) => round($w['cumulative']), $cf['weekly_data'] ?? []));

$daily30Labels = json_encode(array_map(fn($d) => date('d M', strtotime($d['d_date'])), $cf['daily_30'] ?? []));
$daily30Rev    = json_encode(array_column($cf['daily_30'] ?? [], 'revenue_in'));
$daily30Exp    = json_encode(array_column($cf['daily_30'] ?? [], 'exp_out'));

$monthLabels   = json_encode(array_column($forecast['monthly_trend'] ?? [], 'label'));
$monthValues   = json_encode(array_column($forecast['monthly_trend'] ?? [], 'revenue'));

$fundNames     = json_encode(array_column($cf['fund_balances'] ?? [], 'source_name'));
$fundReceived  = json_encode(array_column($cf['fund_balances'] ?? [], 'total_received'));
$fundUtilized  = json_encode(array_column($cf['fund_balances'] ?? [], 'total_utilized'));

renderHead('Cash Flow & Financial Intelligence');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Cash Flow & Financial Intelligence', 'Real-time financial position — FY ' . $fy);
renderPageStart('Cash Flow & Financial Intelligence', '', [
    ['url' => APP_URL . '/dashboard.php', 'label' => 'Dashboard'],
    ['url' => '#', 'label' => 'Cash Flow & Intelligence'],
]);
$fyOpts = '';
foreach ($fyears as $y) $fyOpts .= "<option value='$y'" . ($y === $fy ? ' selected' : '') . ">$y</option>";
renderPageActions("
  <form method='GET' style='display:flex;gap:.5rem;align-items:center;'>
    <label class='fs-sm fw-600'>FY:</label>
    <select name='fy' class='form-select' onchange='this.form.submit()' style='width:130px;'>$fyOpts</select>
  </form>
  <button class='btn btn-outline-secondary no-print' data-print>🖨 Print</button>
");
renderFlashMessages();
?>

<!-- ══ BUDGET ALERTS BANNER ══════════════════════════════════════ -->
<?php if (!empty($alerts)): ?>
<div style="margin-bottom:1.2rem;">
  <?php foreach ($alerts as $alert):
    $lvlConfig = [
        'warning_80'   => ['class'=>'alert-warning', 'icon'=>'⚠', 'label'=>'80% Warning'],
        'warning_90'   => ['class'=>'alert-danger',  'icon'=>'🔴', 'label'=>'90% Critical'],
        'exceeded_100' => ['class'=>'alert-danger',  'icon'=>'🚨', 'label'=>'EXCEEDED'],
    ];
    $cfg = $lvlConfig[$alert['alert_level']] ?? ['class'=>'alert-warning','icon'=>'⚠','label'=>'Alert'];
  ?>
  <div class="alert <?= $cfg['class'] ?>">
    <span><?= $cfg['icon'] ?></span>
    <div>
      <strong><?= $cfg['label'] ?>: <?= htmlspecialchars($alert['dept_name']) ?></strong>
      — Used <strong><?= $alert['utilization_pct'] ?>%</strong>
      of budget (UGX <?= number_format($alert['spent_amount']) ?>
      of UGX <?= number_format($alert['effective_budget']) ?>)
      · FY <?= $alert['financial_year'] ?>
    </div>
    <a href="<?= APP_URL ?>/modules/budget/index.php" class="btn btn-sm btn-outline-secondary" style="margin-left:auto;">View Budget</a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ KPI ROW 1 — NET POSITION ══════════════════════════════════ -->
<div class="grid-5" style="margin-bottom:1.2rem;">

  <div class="stat-card <?= $netPositionClass ?>">
    <div class="stat-icon">⚖</div>
    <div class="stat-info">
      <div class="label">Net Financial Position</div>
      <div class="value" style="font-size:1.1rem;"><?= number_format(abs($cf['net_position'] ?? 0) / 1000000, 1) ?>M</div>
      <div class="sub <?= ($cf['net_position'] ?? 0) >= 0 ? 'up' : 'down' ?>">
        <?= ($cf['net_position'] ?? 0) >= 0 ? '▲ Surplus' : '▼ Deficit' ?> · UGX <?= number_format(abs($cf['net_position'] ?? 0)) ?>
      </div>
    </div>
  </div>

  <div class="stat-card blue">
    <div class="stat-icon">📥</div>
    <div class="stat-info">
      <div class="label">Total Inflows FY</div>
      <div class="value"><?= number_format(($cf['total_inflow'] ?? 0) / 1000000, 1) ?>M</div>
      <div class="sub">Revenue + Govt Funds</div>
    </div>
  </div>

  <div class="stat-card red">
    <div class="stat-icon">📤</div>
    <div class="stat-info">
      <div class="label">Total Outflows FY</div>
      <div class="value"><?= number_format(($cf['total_expenditure'] ?? 0) / 1000000, 1) ?>M</div>
      <div class="sub">Paid vouchers</div>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-icon">📅</div>
    <div class="stat-info">
      <div class="label">This Week Revenue</div>
      <div class="value"><?= number_format(($cf['this_week_rev'] ?? 0) / 1000000, 2) ?>M</div>
      <div class="sub <?= $weekChangeClass ?>">
        <?= $weekChangeIcon ?> <?= abs($cf['week_rev_change'] ?? 0) ?>% vs last week
      </div>
    </div>
  </div>

  <div class="stat-card <?= $onTrackClass ?>">
    <div class="stat-icon">🎯</div>
    <div class="stat-info">
      <div class="label">Revenue Forecast</div>
      <div class="value"><?= number_format(($forecast['projected_annual'] ?? 0) / 1000000, 0) ?>M</div>
      <div class="sub <?= ($forecast['on_track'] ?? false) ? 'up' : 'down' ?>">
        <?= ($forecast['on_track'] ?? false) ? '▲ On track' : '▼ Below target' ?>
        · <?= $forecast['projected_pct'] ?? 0 ?>% of target
      </div>
    </div>
  </div>

</div>

<!-- ══ REVENUE FORECAST PANEL ═══════════════════════════════════ -->
<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-header">
    <h5><span class="ch-icon">🎯</span> Revenue Forecast — FY <?= $fy ?></h5>
    <span class="badge <?= ($forecast['on_track'] ?? false) ? 'badge-success' : 'badge-warning' ?>">
      <?= ($forecast['on_track'] ?? false) ? 'ON TRACK' : 'BELOW TARGET' ?>
    </span>
  </div>
  <div class="card-body">

    <!-- Main forecast statement -->
    <div style="background:<?= ($forecast['on_track'] ?? false) ? 'rgba(30,126,52,.06)' : 'rgba(211,158,0,.08)' ?>;
                border-left:4px solid <?= ($forecast['on_track'] ?? false) ? 'var(--success)' : 'var(--warning)' ?>;
                padding:1rem 1.2rem;border-radius:0 6px 6px 0;margin-bottom:1.2rem;">
      <div style="font-size:1rem;font-weight:700;color:var(--primary);margin-bottom:.4rem;">
        At the current collection pace,
        <?php if ($forecast['on_track'] ?? false): ?>
          Kijura Town Council will collect approximately
          <span style="color:var(--success);">UGX <?= number_format($forecast['projected_annual'] ?? 0) ?></span>
          — exceeding the annual target by
          <span style="color:var(--success);">UGX <?= number_format($forecast['surplus'] ?? 0) ?></span>.
        <?php else: ?>
          Kijura Town Council is projected to collect
          <span style="color:var(--warning);">UGX <?= number_format($forecast['projected_annual'] ?? 0) ?></span>
          — falling short of the UGX <?= number_format($forecast['annual_target'] ?? 0) ?> target by
          <span style="color:var(--danger);">UGX <?= number_format($forecast['shortfall'] ?? 0) ?></span>.
        <?php endif; ?>
      </div>
      <div class="fs-sm text-muted">
        Based on <?= $forecast['days_elapsed'] ?? 0 ?> days elapsed ·
        Daily average: UGX <?= number_format($forecast['daily_rate'] ?? 0) ?> ·
        <?= $forecast['days_remaining'] ?? 0 ?> days remaining in FY
      </div>
    </div>

    <div class="grid-4" style="margin-bottom:1.2rem;">
      <div style="text-align:center;padding:.9rem;background:#f8f9fa;border-radius:8px;">
        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.5px;">Annual Target</div>
        <div style="font-size:1.3rem;font-weight:800;color:var(--primary);">UGX <?= number_format(($forecast['annual_target'] ?? 0) / 1000000, 0) ?>M</div>
      </div>
      <div style="text-align:center;padding:.9rem;background:#f8f9fa;border-radius:8px;">
        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.5px;">Collected to Date</div>
        <div style="font-size:1.3rem;font-weight:800;color:var(--success);">UGX <?= number_format(($forecast['collected_to_date'] ?? 0) / 1000000, 1) ?>M</div>
        <div class="fs-xs text-muted"><?= $forecast['target_pct_collected'] ?? 0 ?>% of target</div>
      </div>
      <div style="text-align:center;padding:.9rem;background:#f8f9fa;border-radius:8px;">
        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.5px;">Projected Annual</div>
        <div style="font-size:1.3rem;font-weight:800;color:<?= ($forecast['on_track'] ?? false) ? 'var(--success)' : 'var(--warning)' ?>;">
          UGX <?= number_format(($forecast['projected_annual'] ?? 0) / 1000000, 1) ?>M
        </div>
        <div class="fs-xs text-muted"><?= $forecast['projected_pct'] ?? 0 ?>% of target</div>
      </div>
      <div style="text-align:center;padding:.9rem;background:#f8f9fa;border-radius:8px;">
        <div class="fs-xs text-muted" style="text-transform:uppercase;letter-spacing:.5px;">Performance Index</div>
        <div style="font-size:1.3rem;font-weight:800;color:<?= ($forecast['performance_index'] ?? 0) >= 100 ? 'var(--success)' : 'var(--warning)' ?>;">
          <?= $forecast['performance_index'] ?? 0 ?>%
        </div>
        <div class="fs-xs text-muted">of pace target</div>
      </div>
    </div>

    <!-- Progress bars -->
    <div style="margin-bottom:.5rem;display:flex;justify-content:space-between;">
      <span class="fs-sm fw-600">Year elapsed: <?= $forecast['year_pct_elapsed'] ?? 0 ?>%</span>
      <span class="fs-sm fw-600">Revenue collected: <?= $forecast['target_pct_collected'] ?? 0 ?>%</span>
    </div>
    <div style="position:relative;height:22px;background:#e9ecef;border-radius:30px;overflow:hidden;margin-bottom:.8rem;">
      <!-- Year elapsed bar (grey) -->
      <div style="position:absolute;top:0;left:0;height:100%;width:<?= min(100, $forecast['year_pct_elapsed'] ?? 0) ?>%;background:rgba(108,117,125,.3);border-radius:30px;"></div>
      <!-- Revenue collected bar -->
      <div style="position:absolute;top:0;left:0;height:100%;width:<?= min(100, $forecast['target_pct_collected'] ?? 0) ?>%;background:<?= ($forecast['on_track'] ?? false) ? 'var(--success)' : 'var(--warning)' ?>;border-radius:30px;transition:width .6s;"></div>
      <div style="position:absolute;top:0;left:0;right:0;bottom:0;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.4);">
        <?= $forecast['target_pct_collected'] ?? 0 ?>% collected · <?= $forecast['year_pct_elapsed'] ?? 0 ?>% of year elapsed
      </div>
    </div>

    <!-- Monthly trend chart -->
    <div class="chart-container" style="height:200px;">
      <canvas id="forecastChart"></canvas>
    </div>
  </div>
</div>

<!-- ══ CASH FLOW CHARTS ROW ═══════════════════════════════════════ -->
<div class="grid-2" style="margin-bottom:1.2rem;">

  <!-- Weekly Cash Flow -->
  <div class="card">
    <div class="card-header">
      <h5><span class="ch-icon">📊</span> Weekly Cash Flow — Last 8 Weeks</h5>
    </div>
    <div class="card-body">
      <div class="grid-3" style="margin-bottom:1rem;gap:.5rem;">
        <div style="text-align:center;padding:.6rem;background:#f0f7ff;border-radius:6px;">
          <div class="fs-xs text-muted">This Week In</div>
          <div class="fw-700 text-success"><?= number_format(($cf['this_week_rev'] ?? 0) / 1000000, 2) ?>M</div>
        </div>
        <div style="text-align:center;padding:.6rem;background:#fff5f5;border-radius:6px;">
          <div class="fs-xs text-muted">This Week Out</div>
          <div class="fw-700 text-danger"><?= number_format(($cf['this_week_exp'] ?? 0) / 1000000, 2) ?>M</div>
        </div>
        <div style="text-align:center;padding:.6rem;background:<?= (($cf['this_week_rev'] ?? 0) - ($cf['this_week_exp'] ?? 0)) >= 0 ? '#f0fff4' : '#fff5f5' ?>;border-radius:6px;">
          <div class="fs-xs text-muted">Net This Week</div>
          <div class="fw-700 <?= (($cf['this_week_rev'] ?? 0) - ($cf['this_week_exp'] ?? 0)) >= 0 ? 'text-success' : 'text-danger' ?>">
            <?= number_format((($cf['this_week_rev'] ?? 0) - ($cf['this_week_exp'] ?? 0)) / 1000000, 2) ?>M
          </div>
        </div>
      </div>
      <div class="chart-container" style="height:220px;">
        <canvas id="weeklyChart"></canvas>
      </div>
    </div>
  </div>

  <!-- 30-Day Rolling Trend -->
  <div class="card">
    <div class="card-header">
      <h5><span class="ch-icon">📈</span> 30-Day Rolling Trend</h5>
    </div>
    <div class="card-body">
      <div class="chart-container" style="height:265px;">
        <canvas id="daily30Chart"></canvas>
      </div>
    </div>
  </div>

</div>

<!-- ══ FUNDING SOURCE BALANCES ═══════════════════════════════════ -->
<div class="grid-3" style="margin-bottom:1.2rem;">

  <div class="card" style="grid-column:span 2;">
    <div class="card-header">
      <h5><span class="ch-icon">◎</span> Available Balance by Funding Source — FY <?= $fy ?></h5>
    </div>
    <div class="card-body p-0">
      <?php if (!empty($cf['fund_balances'])): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead>
            <tr>
              <th>Funding Source</th>
              <th>Type</th>
              <th class="text-right">Received (UGX)</th>
              <th class="text-right">Utilized (UGX)</th>
              <th class="text-right">Available Balance</th>
              <th>Utilization</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($cf['fund_balances'] as $fb):
            $fpct = $fb['total_received'] > 0
                  ? round(($fb['total_utilized'] / $fb['total_received']) * 100, 1) : 0;
            $balClass = $fb['balance'] > 0 ? 'text-success' : 'text-muted';
          ?>
          <tr>
            <td class="fw-600"><?= htmlspecialchars($fb['source_name']) ?></td>
            <td><span class="badge badge-info"><?= ucwords(str_replace('_', ' ', $fb['source_type'])) ?></span></td>
            <td class="text-right"><?= number_format($fb['total_received']) ?></td>
            <td class="text-right"><?= number_format($fb['total_utilized']) ?></td>
            <td class="text-right fw-700 <?= $balClass ?>">
              <?= $fb['total_received'] > 0 ? number_format($fb['balance']) : '—' ?>
            </td>
            <td style="min-width:120px;">
              <?php if ($fb['total_received'] > 0): ?>
              <div style="display:flex;align-items:center;gap:.4rem;">
                <div class="progress" style="flex:1;height:7px;">
                  <div class="progress-bar <?= $fpct >= 90 ? 'bg-danger' : ($fpct >= 70 ? 'bg-warning' : 'bg-success') ?>"
                       style="width:<?= min(100, $fpct) ?>%"></div>
                </div>
                <span class="fs-xs fw-600"><?= $fpct ?>%</span>
              </div>
              <?php else: ?>
              <span class="text-muted fs-xs">No funds recorded</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="2">TOTAL</th>
              <th class="text-right"><?= number_format(array_sum(array_column($cf['fund_balances'], 'total_received'))) ?></th>
              <th class="text-right"><?= number_format(array_sum(array_column($cf['fund_balances'], 'total_utilized'))) ?></th>
              <th class="text-right fw-700 text-success"><?= number_format(array_sum(array_column($cf['fund_balances'], 'balance'))) ?></th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state"><div class="empty-icon">◎</div><p>No government fund records for <?= $fy ?>.</p></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Summary card -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">⚖</span> Financial Summary</h5></div>
    <div class="card-body">

      <div style="margin-bottom:1rem;">
        <div class="fs-xs text-muted mb-1" style="text-transform:uppercase;letter-spacing:.5px;">Total Inflows</div>
        <div class="receipt-row"><span class="fs-sm">Local Revenue</span><strong class="text-success"><?= number_format($cf['local_revenue'] ?? 0) ?></strong></div>
        <div class="receipt-row"><span class="fs-sm">Govt Funds</span><strong class="text-info"><?= number_format($cf['total_govt'] ?? 0) ?></strong></div>
        <div style="border-top:2px solid var(--primary);padding-top:.5rem;margin-top:.5rem;">
          <div class="receipt-row"><span class="fw-700">Total In</span><strong class="text-primary" style="font-size:1.05rem;"><?= number_format($cf['total_inflow'] ?? 0) ?></strong></div>
        </div>
      </div>

      <div style="margin-bottom:1rem;">
        <div class="fs-xs text-muted mb-1" style="text-transform:uppercase;letter-spacing:.5px;">Total Outflows</div>
        <div class="receipt-row"><span class="fw-700">Paid Vouchers</span><strong class="text-danger"><?= number_format($cf['total_expenditure'] ?? 0) ?></strong></div>
      </div>

      <div style="background:<?= ($cf['net_position'] ?? 0) >= 0 ? '#d4edda' : '#f8d7da' ?>;border-radius:8px;padding:1rem;text-align:center;">
        <div class="fs-xs" style="text-transform:uppercase;letter-spacing:.5px;margin-bottom:.3rem;">NET POSITION</div>
        <div style="font-size:1.6rem;font-weight:900;color:<?= ($cf['net_position'] ?? 0) >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
          UGX <?= number_format(abs($cf['net_position'] ?? 0)) ?>
        </div>
        <div class="fs-xs" style="margin-top:.2rem;color:<?= ($cf['net_position'] ?? 0) >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
          <?= ($cf['net_position'] ?? 0) >= 0 ? '▲ SURPLUS' : '▼ DEFICIT' ?>
        </div>
      </div>

      <div style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
        <div class="chart-container" style="height:160px;">
          <canvas id="fundPieChart"></canvas>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- ══ BUDGET VARIANCE TABLE ═════════════════════════════════════ -->
<div class="card" style="margin-bottom:1.2rem;">
  <div class="card-header">
    <h5><span class="ch-icon">▣</span> Budget Variance by Department — FY <?= $fy ?></h5>
    <a href="<?= APP_URL ?>/modules/budget/index.php" class="btn btn-sm btn-outline-primary">Full Budget View</a>
  </div>
  <div class="card-body p-0">
    <?php if (!empty($variance)): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead>
          <tr>
            <th>Department</th>
            <th>Budget Code</th>
            <th class="text-right">Budget (UGX)</th>
            <th class="text-right">Spent (UGX)</th>
            <th class="text-right">Balance</th>
            <th>Utilization</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($variance as $v):
          $pct = (float)$v['utilization_pct'];
          $statusLabel = $pct >= 100 ? 'exceeded' : ($pct >= 90 ? 'critical' : ($pct >= 80 ? 'warning' : 'normal'));
          $statusBadge = [
              'exceeded' => '<span class="badge badge-danger">EXCEEDED</span>',
              'critical' => '<span class="badge badge-danger">90%+ Critical</span>',
              'warning'  => '<span class="badge badge-warning">80%+ Warning</span>',
              'normal'   => '<span class="badge badge-success">Normal</span>',
          ];
          $barClass = $pct >= 100 ? 'bg-danger' : ($pct >= 90 ? 'bg-danger' : ($pct >= 80 ? 'bg-warning' : 'bg-success'));
        ?>
        <tr style="<?= $pct >= 90 ? 'background:rgba(189,33,48,.03);' : ($pct >= 80 ? 'background:rgba(211,158,0,.04);' : '') ?>">
          <td class="fw-600"><?= htmlspecialchars($v['dept_name']) ?></td>
          <td class="fs-sm text-primary"><?= htmlspecialchars($v['budget_code']) ?></td>
          <td class="text-right"><?= number_format($v['effective_budget']) ?></td>
          <td class="text-right fw-700"><?= number_format($v['spent_amount']) ?></td>
          <td class="text-right fw-700 <?= $v['balance'] < 0 ? 'text-danger' : 'text-success' ?>">
            <?= number_format($v['balance']) ?>
          </td>
          <td style="min-width:140px;">
            <div style="display:flex;align-items:center;gap:.5rem;">
              <div class="progress" style="flex:1;height:10px;">
                <div class="progress-bar <?= $barClass ?>" style="width:<?= min(100, $pct) ?>%"></div>
              </div>
              <span class="fs-xs fw-700 <?= $pct >= 90 ? 'text-danger' : ($pct >= 80 ? 'text-warning' : 'text-success') ?>"><?= $pct ?>%</span>
            </div>
          </td>
          <td><?= $statusBadge[$statusLabel] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><div class="empty-icon">▣</div><p>No approved budgets found for FY <?= $fy ?>. <a href="<?= APP_URL ?>/modules/budget/index.php">Create budgets</a>.</p></div>
    <?php endif; ?>
  </div>
</div>

<!-- ══ WARD GROWTH RATES ══════════════════════════════════════════ -->
<?php if (!empty($forecast['ward_growth'])): ?>
<div class="card">
  <div class="card-header">
    <h5><span class="ch-icon">◉</span> Month-over-Month Revenue Growth by Ward</h5>
    <span class="fs-sm text-muted"><?= date('F Y') ?> vs <?= date('F Y', strtotime('-1 month')) ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead>
          <tr>
            <th>Ward</th>
            <th class="text-right">Last Month (UGX)</th>
            <th class="text-right">This Month (UGX)</th>
            <th class="text-right">Change (UGX)</th>
            <th>Growth Rate</th>
            <th>Trend</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($forecast['ward_growth'] as $wg):
          $change = (float)$wg['this_month'] - (float)$wg['last_month'];
          $gr     = (float)$wg['growth_rate'];
        ?>
        <tr>
          <td class="fw-600"><?= htmlspecialchars($wg['ward']) ?></td>
          <td class="text-right"><?= number_format($wg['last_month']) ?></td>
          <td class="text-right fw-700"><?= number_format($wg['this_month']) ?></td>
          <td class="text-right <?= $change >= 0 ? 'text-success' : 'text-danger' ?> fw-700">
            <?= ($change >= 0 ? '+' : '') . number_format($change) ?>
          </td>
          <td>
            <span class="fw-700 <?= $gr >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= ($gr >= 0 ? '▲' : '▼') ?> <?= abs($gr) ?>%
            </span>
          </td>
          <td>
            <div style="width:80px;height:8px;background:#e9ecef;border-radius:4px;overflow:hidden;">
              <div style="height:100%;width:<?= min(100, abs($gr)) ?>%;background:<?= $gr >= 0 ? 'var(--success)' : 'var(--danger)' ?>;border-radius:4px;"></div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
renderPageEnd();
echo '</div></div>';
?>

<script>
document.addEventListener('DOMContentLoaded', function () {

  // ── Monthly forecast trend ──────────────────────────────────────
  makeBarChart('forecastChart',
    <?= $monthLabels ?>,
    [{
      label: 'Monthly Revenue (UGX)',
      data: <?= $monthValues ?>,
      backgroundColor: 'rgba(26,58,92,.75)',
      borderRadius: 4
    }]
  );

  // ── Weekly cash flow (grouped bar) ─────────────────────────────
  makeBarChart('weeklyChart',
    <?= $weekLabels ?>,
    [
      { label: 'Inflows',  data: <?= $weekRevIn ?>,  backgroundColor: 'rgba(30,126,52,.75)', borderRadius: 4 },
      { label: 'Outflows', data: <?= $weekExpOut ?>,  backgroundColor: 'rgba(189,33,48,.7)',  borderRadius: 4 }
    ]
  );

  // ── 30-day rolling (line) ───────────────────────────────────────
  makeLineChart('daily30Chart',
    <?= $daily30Labels ?>,
    [
      { label: 'Revenue In', data: <?= $daily30Rev ?>, borderColor: 'var(--success)', backgroundColor: 'rgba(30,126,52,.08)', fill: true, tension: .3, borderWidth: 2 },
      { label: 'Expenditure Out', data: <?= $daily30Exp ?>, borderColor: 'var(--danger)', backgroundColor: 'rgba(189,33,48,.05)', fill: true, tension: .3, borderWidth: 2 }
    ]
  );

  // ── Fund balance doughnut ───────────────────────────────────────
  <?php if (!empty($cf['fund_balances'])): ?>
  makeDoughnutChart('fundPieChart',
    <?= $fundNames ?>,
    <?= $fundReceived ?>
  );
  <?php endif; ?>

});
</script>

<?php renderFooter(); ?>
