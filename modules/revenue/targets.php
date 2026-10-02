<?php
/**
 * TCMS Revenue Target Management
 * Set annual revenue targets per ward and per source.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
if (!hasRole(['admin','town_clerk','finance_officer'])) {
    setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit;
}
$user = getCurrentUser();
$db   = getDB();
$fy   = $_GET['fy'] ?? (getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Save all targets at once (bulk upsert) ─────────────────────
    if ($action === 'save_targets') {
        $fyPost = $_POST['financial_year'];
        $targets = $_POST['targets'] ?? [];
        $saved = 0;
        foreach ($targets as $key => $amount) {
            $amount = (float)str_replace(',','',$amount);
            // Key format: "ward_W1" or "src_S1" or "ward_W1_src_S1"
            $parts     = explode('_', $key, 4);
            $wardId    = null; $srcId = null;
            if ($parts[0]==='ward' && isset($parts[1])) $wardId=(int)$parts[1];
            if ($parts[0]==='src'  && isset($parts[1])) $srcId =(int)$parts[1];
            if ($parts[0]==='ward' && isset($parts[2]) && $parts[2]==='src') { $wardId=(int)$parts[1]; $srcId=(int)$parts[3]; }
            if ($amount <= 0) continue;
            $db->prepare("INSERT INTO revenue_targets (financial_year,ward_id,revenue_source_id,target_amount,set_by,set_date)
                VALUES (?,?,?,?,?,CURDATE())
                ON DUPLICATE KEY UPDATE target_amount=VALUES(target_amount), set_by=VALUES(set_by), set_date=CURDATE()")
               ->execute([$fyPost,$wardId,$srcId,$amount,$user['id']]);
            $saved++;
        }
        logAudit('SET_TARGETS','revenue','revenue_targets',0,$fyPost);
        setFlash('success',"Revenue targets saved: $saved record(s) for FY $fyPost.");
        header('Location: targets.php?fy='.urlencode($fyPost)); exit;
    }

    // ── Copy targets from previous FY ─────────────────────────────
    if ($action === 'copy_targets') {
        $fromFy = $_POST['from_fy'];
        $toFy   = $_POST['to_fy'];
        $factor = (float)($_POST['growth_factor'] ?? 1.0);
        $existing = $db->prepare("SELECT * FROM revenue_targets WHERE financial_year=?");
        $existing->execute([$fromFy]); $existing=$existing->fetchAll();
        $copied = 0;
        foreach ($existing as $t) {
            $newAmount = round($t['target_amount'] * $factor, 0);
            $db->prepare("INSERT INTO revenue_targets (financial_year,ward_id,revenue_source_id,target_amount,set_by,set_date)
                VALUES (?,?,?,?,?,CURDATE())
                ON DUPLICATE KEY UPDATE target_amount=VALUES(target_amount), set_by=VALUES(set_by)")
               ->execute([$toFy,$t['ward_id'],$t['revenue_source_id'],$newAmount,$user['id']]);
            $copied++;
        }
        setFlash('success',"Copied $copied targets from $fromFy to $toFy with growth factor ".round(($factor-1)*100,1)."%.");
        header('Location: targets.php?fy='.urlencode($toFy)); exit;
    }
}

// ── Load existing targets for this FY ─────────────────────────────
$existing = $db->prepare("SELECT financial_year,ward_id,revenue_source_id,target_amount FROM revenue_targets WHERE financial_year=?");
$existing->execute([$fy]); $existing=$existing->fetchAll();
$targetMap = [];
foreach ($existing as $t) {
    $wk = $t['ward_id']  ?? 'null';
    $sk = $t['revenue_source_id'] ?? 'null';
    $targetMap["{$wk}_{$sk}"] = $t['target_amount'];
}

// Grand total target
$grandTarget = array_sum(array_column($existing,'target_amount'));

// Actual collected
$collected = (float)$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'")->execute([$fy])?0:0;
$colS=$db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'");$colS->execute([$fy]);$collected=(float)$colS->fetchColumn();

// Per-ward collected
$wardCollected = $db->prepare("SELECT ward_id,SUM(amount) collected FROM revenue_payments WHERE financial_year=? AND status='active' GROUP BY ward_id");
$wardCollected->execute([$fy]); $wardCollectedMap=[];
foreach ($wardCollected->fetchAll() as $r) $wardCollectedMap[$r['ward_id']]=(float)$r['collected'];

// Per-source collected
$srcCollected = $db->prepare("SELECT revenue_source_id,SUM(amount) collected FROM revenue_payments WHERE financial_year=? AND status='active' GROUP BY revenue_source_id");
$srcCollected->execute([$fy]); $srcCollectedMap=[];
foreach ($srcCollected->fetchAll() as $r) $srcCollectedMap[$r['revenue_source_id']]=(float)$r['collected'];

$wards   = $db->query("SELECT * FROM wards WHERE is_active=1 ORDER BY name")->fetchAll();
$sources = $db->query("SELECT * FROM revenue_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

renderHead('Revenue Targets');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Revenue Target Management','Set annual revenue targets per ward and source');
renderPageStart('Revenue Targets','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>APP_URL.'/modules/revenue/dashboard.php','label'=>'Revenue'],
    ['url'=>'#','label'=>'Targets'],
]);
$fyOpts='';foreach($fyears as $y)$fyOpts.="<option value='$y'".($y===$fy?' selected':'').">$y</option>";
renderPageActions("
  <form method='GET' style='display:flex;gap:.5rem;align-items:center;'>
    <label class='fs-sm fw-600'>FY:</label>
    <select name='fy' class='form-select' onchange='this.form.submit()' style='width:130px;'>$fyOpts</select>
  </form>
  <button class='btn btn-outline-secondary' data-modal='copyTargetsModal'>Copy from Previous FY</button>
");
renderFlashMessages();
?>

<!-- Summary -->
<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card"><div class="stat-icon">🎯</div><div class="stat-info"><div class="label">Total Target</div><div class="value"><?=number_format($grandTarget/1000000,1)?>M</div><div class="sub">UGX <?=number_format($grandTarget)?></div></div></div>
  <div class="stat-card green"><div class="stat-icon">💰</div><div class="stat-info"><div class="label">Collected</div><div class="value"><?=number_format($collected/1000000,1)?>M</div><div class="sub"><?=$grandTarget>0?round($collected/$grandTarget*100,1):0?>% of target</div></div></div>
  <div class="stat-card amber"><div class="stat-icon">⏳</div><div class="stat-info"><div class="label">Remaining</div><div class="value"><?=number_format(max(0,$grandTarget-$collected)/1000000,1)?>M</div></div></div>
  <div class="stat-card blue"><div class="stat-icon">📊</div><div class="stat-info"><div class="label">Sources Targeted</div><div class="value"><?=count($existing)?></div></div></div>
</div>

<form method="POST">
  <?=csrfField()?>
  <input type="hidden" name="action" value="save_targets">
  <input type="hidden" name="financial_year" value="<?=htmlspecialchars($fy)?>">

  <!-- Ward Targets -->
  <div class="card" style="margin-bottom:1.2rem;">
    <div class="card-header"><h5><span class="ch-icon">◉</span> Revenue Targets by Ward — FY <?=$fy?></h5></div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Ward</th><th class="text-right" style="width:220px;">Annual Target (UGX)</th><th class="text-right">Collected</th><th class="text-right">Achievement</th><th>Progress</th></tr></thead>
          <tbody>
          <?php foreach($wards as $w):
            $key    = "{$w['id']}_null";
            $target = $targetMap[$key] ?? 0;
            $coll   = $wardCollectedMap[$w['id']] ?? 0;
            $wpct   = $target>0?round($coll/$target*100,1):0;
          ?>
          <tr>
            <td class="fw-600"><?=htmlspecialchars($w['name'])?></td>
            <td>
              <input type="number" name="targets[ward_<?=$w['id']?>]"
                     class="form-control text-right" min="0" step="1"
                     value="<?php echo $target>0?(int)$target:''; ?>" placeholder="0"
                     style="text-align:right;">
            </td>
            <td class="text-right"><?=$coll>0?number_format($coll):'—'?></td>
            <td class="text-right fw-700 <?=$wpct>=75?'text-success':($wpct>=50?'text-warning':'text-muted')?>"><?=$target>0?"$wpct%":'—'?></td>
            <td style="min-width:100px;">
              <?php if($target>0): ?>
              <div class="progress" style="height:6px;"><div class="progress-bar <?=$wpct>=75?'bg-success':($wpct>=50?'bg-warning':'bg-danger')?>" style="width:<?=min(100,$wpct)?>%"></div></div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <tfoot>
            <tr>
              <th>TOTAL</th>
              <th class="text-right"><?=$grandTarget>0?number_format($grandTarget):'(unsaved)'?></th>
              <th class="text-right"><?=number_format($collected)?></th>
              <th class="text-right fw-700"><?=$grandTarget>0?round($collected/$grandTarget*100,1).'%':'—'?></th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- Source Targets -->
  <div class="card" style="margin-bottom:1.2rem;">
    <div class="card-header"><h5><span class="ch-icon">◈</span> Revenue Targets by Source — FY <?=$fy?></h5></div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Revenue Source</th><th>Category</th><th class="text-right" style="width:220px;">Annual Target (UGX)</th><th class="text-right">Collected</th><th class="text-right">Achievement</th></tr></thead>
          <tbody>
          <?php foreach($sources as $s):
            $key    = "null_{$s['id']}";
            $target = $targetMap[$key] ?? 0;
            $coll   = $srcCollectedMap[$s['id']] ?? 0;
            $spct   = $target>0?round($coll/$target*100,1):0;
          ?>
          <tr>
            <td class="fw-600"><?=htmlspecialchars($s['name'])?></td>
            <td class="fs-sm"><?=htmlspecialchars($s['category']??'—')?></td>
            <td>
              <input type="number" name="targets[src_<?=$s['id']?>]"
                     class="form-control text-right" min="0" step="1"
                     value="<?=$target>0?(int)$target:''?>" placeholder="0"
                     style="text-align:right;">
            </td>
            <td class="text-right"><?=$coll>0?number_format($coll):'—'?></td>
            <td class="text-right fw-700 <?=$spct>=75?'text-success':($spct>=50?'text-warning':'text-muted')?>"><?=$target>0?"$spct%":'—'?></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>

  <div style="display:flex;gap:.6rem;padding:1rem 0;">
    <button type="submit" class="btn btn-primary">💾 Save All Targets</button>
    <a href="targets.php?fy=<?=urlencode($fy)?>" class="btn btn-outline-secondary">Reset</a>
  </div>
</form>

<!-- Copy from previous FY modal -->
<div class="modal-backdrop" id="copyTargetsModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Copy Targets from Previous FY</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="copy_targets">
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Copy FROM Financial Year <span class="req">*</span></label>
          <select name="from_fy" class="form-select" required>
            <?php foreach($fyears as $y): if($y===$fy)continue; ?><option value="<?=$y?>"><?=$y?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Copy TO Financial Year <span class="req">*</span></label>
          <select name="to_fy" class="form-select" required>
            <?php foreach($fyears as $y): ?><option value="<?=$y?>" <?=$y===$fy?'selected':''?>><?=$y?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Growth Factor</label>
          <input type="number" name="growth_factor" class="form-control" step="0.01" min="0.5" max="3" value="1.10">
          <div class="form-text">1.10 = 10% increase. 1.00 = same as previous year. 0.95 = 5% decrease.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Copy Targets</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
