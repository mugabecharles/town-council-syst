<?php
/**
 * TCMS Asset Register + Depreciation + Maintenance
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();
$fy   = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

// ══════════════════════════════════════════════════════════════════
// POST HANDLERS
// ══════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Add Asset ─────────────────────────────────────────────────
    if ($action === 'add_asset') {
        $cnt  = (int)$db->query("SELECT COUNT(*)+1 FROM assets")->fetchColumn();
        $anum = 'AST-'.date('Y').'-'.str_pad($cnt,5,'0',STR_PAD_LEFT);
        $purchaseVal = (float)str_replace(',','',$_POST['purchase_value']??0);
        $db->prepare("INSERT INTO assets
            (asset_number,name,description,category_id,department_id,location,
             purchase_date,purchase_value,current_value,funding_source_id,
             responsible_officer,serial_number,useful_life_years,residual_value,
             depreciation_method,condition_rating,status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([
               $anum, trim($_POST['name']), trim($_POST['description']??''),
               !empty($_POST['category_id'])?(int)$_POST['category_id']:null,
               !empty($_POST['department_id'])?(int)$_POST['department_id']:null,
               trim($_POST['location']??''),
               $_POST['purchase_date']??null,
               $purchaseVal, $purchaseVal, // current = purchase initially
               !empty($_POST['funding_source_id'])?(int)$_POST['funding_source_id']:null,
               !empty($_POST['responsible_officer'])?(int)$_POST['responsible_officer']:null,
               trim($_POST['serial_number']??''),
               (int)($_POST['useful_life_years']??5),
               (float)str_replace(',','',$_POST['residual_value']??0),
               $_POST['depreciation_method']??'straight_line',
               $_POST['condition_rating']??'good',
               'active'
           ]);
        $aid = (int)$db->lastInsertId();
        logAudit('CREATE','assets','asset',$aid,$anum);
        setFlash('success',"Asset registered: $anum");
        header('Location: index.php?view='.$aid); exit;
    }

    // ── Record Maintenance ────────────────────────────────────────
    if ($action === 'add_maintenance') {
        $aid = (int)$_POST['asset_id'];
        $cost= (float)str_replace(',','',$_POST['cost']??0);
        $db->prepare("INSERT INTO asset_maintenance
            (asset_id,maintenance_type,description,maintenance_date,cost,
             service_provider,performed_by,next_maintenance_date,recorded_by)
            VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([
               $aid,$_POST['maintenance_type'],trim($_POST['description']),
               $_POST['maintenance_date'],$cost,
               trim($_POST['service_provider']??''),trim($_POST['performed_by']??''),
               !empty($_POST['next_maintenance_date'])?$_POST['next_maintenance_date']:null,
               $user['id']
           ]);
        // Update current value if major overhaul adds value
        if ($_POST['maintenance_type']==='major_overhaul' && $cost>0) {
            $db->prepare("UPDATE assets SET current_value=current_value+? WHERE id=?")->execute([$cost,$aid]);
        }
        // Mark as disposed
        if ($_POST['maintenance_type']==='disposal') {
            $db->prepare("UPDATE assets SET status='disposed',disposal_date=?,
                disposal_reason=?,disposal_proceeds=?,condition_rating='disposed' WHERE id=?")
               ->execute([$_POST['maintenance_date'],trim($_POST['description']),$cost,$aid]);
        }
        logAudit('ADD_MAINTENANCE','assets','asset',$aid,'');
        setFlash('success','Maintenance record added.');
        header('Location: index.php?view='.$aid.'#maintenance'); exit;
    }

    // ── Calculate Depreciation ────────────────────────────────────
    if ($action === 'calc_depreciation' && hasRole(['admin','finance_officer','town_clerk'])) {
        $aid    = (int)$_POST['asset_id'];
        $fyCalc = $_POST['financial_year'];

        $asset = $db->prepare("SELECT * FROM assets WHERE id=?"); $asset->execute([$aid]); $asset=$asset->fetch();
        if (!$asset || !$asset['purchase_value']) {
            setFlash('danger','Asset has no purchase value.'); header('Location: index.php?view='.$aid); exit;
        }

        // Get accumulated depreciation so far
        $accum = (float)$db->prepare("SELECT COALESCE(SUM(depreciation_amount),0) FROM asset_depreciation WHERE asset_id=? AND financial_year < ?")->execute([$aid,$fyCalc]) ?
                 $db->query("SELECT COALESCE(SUM(depreciation_amount),0) FROM asset_depreciation WHERE asset_id=$aid AND financial_year < '$fyCalc'")->fetchColumn() : 0;
        $accumStmt = $db->prepare("SELECT COALESCE(SUM(depreciation_amount),0) FROM asset_depreciation WHERE asset_id=? AND financial_year < ?");
        $accumStmt->execute([$aid,$fyCalc]); $accum=(float)$accumStmt->fetchColumn();

        $usefulLife  = max(1,(int)$asset['useful_life_years']);
        $residual    = (float)$asset['residual_value'];
        $purchaseVal = (float)$asset['purchase_value'];
        $depreciable = max(0,$purchaseVal - $residual);
        $openVal     = max(0,$purchaseVal - $accum);

        $method = $asset['depreciation_method'] ?? 'straight_line';
        if ($method === 'straight_line') {
            $rate   = round(100/$usefulLife,4);
            $depAmt = min($openVal-$residual, round($depreciable/$usefulLife,2));
        } else {
            // Declining balance (double declining)
            $rate   = round(200/$usefulLife,4);
            $depAmt = min($openVal-$residual, round($openVal*$rate/100,2));
        }
        $depAmt   = max(0,$depAmt);
        $closeVal = max($residual,$openVal-$depAmt);

        $db->prepare("INSERT INTO asset_depreciation
            (asset_id,financial_year,depreciation_method,useful_life_years,residual_value,
             opening_value,depreciation_rate,depreciation_amount,closing_value,
             accumulated_depreciation,calculated_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE depreciation_amount=VALUES(depreciation_amount),
            closing_value=VALUES(closing_value), accumulated_depreciation=VALUES(accumulated_depreciation),
            calculated_by=VALUES(calculated_by)")
           ->execute([
               $aid,$fyCalc,$method,$usefulLife,$residual,
               $openVal,$rate,$depAmt,$closeVal,$accum+$depAmt,$user['id']
           ]);
        $db->prepare("UPDATE assets SET current_value=? WHERE id=?")->execute([$closeVal,$aid]);

        logAudit('CALC_DEPRECIATION','assets','depreciation',$aid,"$aid/$fyCalc");
        setFlash('success',"Depreciation calculated for FY $fyCalc: UGX ".number_format($depAmt)." | Closing value: UGX ".number_format($closeVal));
        header('Location: index.php?view='.$aid.'#depreciation'); exit;
    }
}

// ── View single asset ──────────────────────────────────────────────
$viewId = (int)($_GET['view'] ?? 0);
if ($viewId) {
    $asset = $db->prepare("SELECT a.*, ac.name AS cat_name, d.name AS dept_name,
        fs.name AS fund_name, u.full_name AS officer_name
        FROM assets a LEFT JOIN asset_categories ac ON a.category_id=ac.id
        LEFT JOIN departments d ON a.department_id=d.id
        LEFT JOIN funding_sources fs ON a.funding_source_id=fs.id
        LEFT JOIN users u ON a.responsible_officer=u.id
        WHERE a.id=?");
    $asset->execute([$viewId]); $asset=$asset->fetch();
    if (!$asset) { setFlash('danger','Asset not found.'); header('Location: index.php'); exit; }

    $depHistory = $db->prepare("SELECT * FROM asset_depreciation WHERE asset_id=? ORDER BY financial_year DESC");
    $depHistory->execute([$viewId]); $depHistory=$depHistory->fetchAll();

    $maintenance = $db->prepare("SELECT m.*, u.full_name AS recorder FROM asset_maintenance m
        LEFT JOIN users u ON m.recorded_by=u.id WHERE m.asset_id=? ORDER BY m.maintenance_date DESC");
    $maintenance->execute([$viewId]); $maintenance=$maintenance->fetchAll();

    $totalMaintCost = array_sum(array_column($maintenance,'cost'));
    $totalDepreciation = array_sum(array_column($depHistory,'depreciation_amount'));
    $fyears = $db->query("SELECT year_code FROM financial_years WHERE status='open' ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

    renderHead('Asset: '.$asset['asset_number']);
    echo '<body><div class="app-wrapper">';
    renderSidebar($user);
    echo '<div class="main-content">';
    renderTopbar('Asset Register',htmlspecialchars($asset['asset_number']));
    renderPageStart(htmlspecialchars($asset['name']),'', [
        ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
        ['url'=>APP_URL.'/modules/assets_mgmt/index.php','label'=>'Assets'],
        ['url'=>'#','label'=>$asset['asset_number']],
    ]);
    renderPageActions('<a href="index.php" class="btn btn-outline-secondary">← Back</a>');
    renderFlashMessages();
    ?>

<div class="grid-3" style="align-items:start;">

<!-- Left: Asset details -->
<div style="grid-column:span 2;">
  <div class="card" style="margin-bottom:1rem;">
    <div class="card-header">
      <h5><span class="ch-icon">◒</span> <?= htmlspecialchars($asset['asset_number']) ?></h5>
      <div style="display:flex;gap:.4rem;">
        <?= getStatusBadge($asset['status']) ?>
        <?php if ($asset['condition_rating']): ?>
        <span class="badge badge-<?= $asset['condition_rating']==='excellent'?'success':($asset['condition_rating']==='good'?'info':($asset['condition_rating']==='fair'?'warning':($asset['condition_rating']==='poor'?'danger':'dark'))) ?>">
          <?= ucfirst($asset['condition_rating']) ?>
        </span>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <div class="grid-2" style="gap:.5rem;">
        <div class="receipt-row"><span class="text-muted fs-sm">Category:</span><strong><?= htmlspecialchars($asset['cat_name']??'—') ?></strong></div>
        <div class="receipt-row"><span class="text-muted fs-sm">Department:</span><strong><?= htmlspecialchars($asset['dept_name']??'—') ?></strong></div>
        <div class="receipt-row"><span class="text-muted fs-sm">Location:</span><strong><?= htmlspecialchars($asset['location']??'—') ?></strong></div>
        <div class="receipt-row"><span class="text-muted fs-sm">Serial No.:</span><strong><?= htmlspecialchars($asset['serial_number']??'—') ?></strong></div>
        <div class="receipt-row"><span class="text-muted fs-sm">Purchase Date:</span><strong><?= $asset['purchase_date']?formatDate($asset['purchase_date']):'—' ?></strong></div>
        <div class="receipt-row"><span class="text-muted fs-sm">Responsible Officer:</span><strong><?= htmlspecialchars($asset['officer_name']??'—') ?></strong></div>
        <div class="receipt-row"><span class="text-muted fs-sm">Funding Source:</span><strong><?= htmlspecialchars($asset['fund_name']??'—') ?></strong></div>
        <div class="receipt-row"><span class="text-muted fs-sm">Useful Life:</span><strong><?= $asset['useful_life_years'] ?> years</strong></div>
        <div class="receipt-row"><span class="text-muted fs-sm">Depreciation Method:</span><strong><?= ucwords(str_replace('_',' ',$asset['depreciation_method']??'—')) ?></strong></div>
        <div class="receipt-row"><span class="text-muted fs-sm">Residual Value:</span><strong>UGX <?= number_format($asset['residual_value']) ?></strong></div>
      </div>
      <?php if ($asset['description']): ?>
      <p style="margin-top:.8rem;background:#f8f9fa;padding:.7rem;border-radius:4px;font-size:.86rem;"><?= htmlspecialchars($asset['description']) ?></p>
      <?php endif; ?>

      <!-- Value summary -->
      <div class="grid-4" style="margin-top:1rem;gap:.5rem;">
        <div style="text-align:center;padding:.8rem;background:#f8f9fa;border-radius:8px;">
          <div class="fs-xs text-muted">Purchase Value</div>
          <div class="fw-700"><?= number_format($asset['purchase_value']) ?></div>
        </div>
        <div style="text-align:center;padding:.8rem;background:#fff3cd;border-radius:8px;">
          <div class="fs-xs text-muted">Total Depreciation</div>
          <div class="fw-700 text-warning"><?= number_format($totalDepreciation) ?></div>
        </div>
        <div style="text-align:center;padding:.8rem;background:#f8d7da;border-radius:8px;">
          <div class="fs-xs text-muted">Maintenance Cost</div>
          <div class="fw-700 text-danger"><?= number_format($totalMaintCost) ?></div>
        </div>
        <div style="text-align:center;padding:.8rem;background:#d4edda;border-radius:8px;">
          <div class="fs-xs text-muted">Current Book Value</div>
          <div class="fw-700 text-success"><?= number_format($asset['current_value']) ?></div>
        </div>
      </div>

      <!-- Value bar -->
      <?php if ($asset['purchase_value']>0):
        $depPct = round(($totalDepreciation/$asset['purchase_value'])*100,1); ?>
      <div style="margin-top:.8rem;">
        <div style="display:flex;justify-content:space-between;font-size:.75rem;color:#6c757d;margin-bottom:.2rem;">
          <span>Asset value remaining: <?= 100-$depPct ?>%</span>
          <span>Depreciated: <?= $depPct ?>%</span>
        </div>
        <div class="progress" style="height:10px;">
          <div class="progress-bar bg-success" style="width:<?= max(0,100-$depPct) ?>%"></div>
          <div style="flex:1;background:rgba(211,158,0,.4);"></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Depreciation History -->
  <div class="card" id="depreciation" style="margin-bottom:1rem;">
    <div class="card-header">
      <h5><span class="ch-icon">📉</span> Depreciation Schedule</h5>
      <?php if (hasRole(['admin','finance_officer','town_clerk'])): ?>
      <button class="btn btn-sm btn-primary" data-modal="calcDepModal">Calculate FY Depreciation</button>
      <?php endif; ?>
    </div>
    <div class="card-body p-0">
      <?php if ($depHistory): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>FY</th><th>Method</th><th class="text-right">Opening Value</th><th class="text-right">Rate %</th><th class="text-right">Depreciation</th><th class="text-right">Closing Value</th><th class="text-right">Accumulated</th></tr></thead>
          <tbody>
          <?php foreach ($depHistory as $dep): ?>
          <tr>
            <td class="fw-700"><?= $dep['financial_year'] ?></td>
            <td class="fs-sm"><?= ucwords(str_replace('_',' ',$dep['depreciation_method'])) ?></td>
            <td class="text-right"><?= number_format($dep['opening_value']) ?></td>
            <td class="text-right"><?= $dep['depreciation_rate'] ?>%</td>
            <td class="text-right text-warning fw-700"><?= number_format($dep['depreciation_amount']) ?></td>
            <td class="text-right text-success fw-700"><?= number_format($dep['closing_value']) ?></td>
            <td class="text-right text-danger"><?= number_format($dep['accumulated_depreciation']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="4" class="text-right">TOTAL DEPRECIATED:</th>
              <th class="text-right"><?= number_format($totalDepreciation) ?></th>
              <th class="text-right"><?= number_format($asset['current_value']) ?></th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php else: ?><div class="empty-state" style="padding:1.5rem;"><p>No depreciation calculated yet.</p></div><?php endif; ?>
    </div>
  </div>

  <!-- Maintenance Log -->
  <div class="card" id="maintenance">
    <div class="card-header">
      <h5><span class="ch-icon">🔧</span> Maintenance Log (<?= count($maintenance) ?>)</h5>
      <button class="btn btn-sm btn-primary" data-modal="addMaintenanceModal">+ Record</button>
    </div>
    <div class="card-body p-0">
      <?php if ($maintenance): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Provider</th><th class="text-right">Cost</th><th>Next Due</th></tr></thead>
          <tbody>
          <?php foreach ($maintenance as $m): ?>
          <tr>
            <td class="fs-sm"><?= formatDate($m['maintenance_date']) ?></td>
            <td><span class="badge badge-info"><?= ucwords(str_replace('_',' ',$m['maintenance_type'])) ?></span></td>
            <td class="fs-sm"><?= htmlspecialchars(substr($m['description'],0,50)) ?></td>
            <td class="fs-sm"><?= htmlspecialchars($m['service_provider']??'—') ?></td>
            <td class="text-right fw-600"><?= $m['cost']>0?number_format($m['cost']):'—' ?></td>
            <td class="fs-sm"><?= $m['next_maintenance_date']?formatDate($m['next_maintenance_date']):'—' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th colspan="4" class="text-right">TOTAL MAINTENANCE COST:</th><th class="text-right"><?= number_format($totalMaintCost) ?></th><th></th></tr></tfoot>
        </table>
      </div>
      <?php else: ?><div class="empty-state" style="padding:1.5rem;"><p>No maintenance records yet.</p></div><?php endif; ?>
    </div>
  </div>
</div>

<!-- Right: Quick actions -->
<div>
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◉</span> Asset Summary</h5></div>
    <div class="card-body">
      <div class="receipt-row"><span class="text-muted fs-sm">Asset No.:</span><strong class="text-primary"><?= htmlspecialchars($asset['asset_number']) ?></strong></div>
      <div class="receipt-row"><span class="text-muted fs-sm">Purchase Value:</span><strong>UGX <?= number_format($asset['purchase_value']) ?></strong></div>
      <div class="receipt-row"><span class="text-muted fs-sm">Book Value:</span><strong class="text-success">UGX <?= number_format($asset['current_value']) ?></strong></div>
      <div class="receipt-row"><span class="text-muted fs-sm">Age:</span><strong><?= $asset['purchase_date'] ? floor((time()-strtotime($asset['purchase_date']))/86400/365).' yrs' : '—' ?></strong></div>
      <div class="receipt-row"><span class="text-muted fs-sm">Useful Life:</span><strong><?= $asset['useful_life_years'] ?> yrs</strong></div>

      <?php if ($asset['purchase_date'] && $asset['useful_life_years']):
        $endDate = date('Y-m-d', strtotime($asset['purchase_date'].' +'.$asset['useful_life_years'].' years'));
        $daysLeft= (strtotime($endDate)-time())/86400;
        $lifeUsed= max(0,min(100,round((time()-strtotime($asset['purchase_date']))/(strtotime($endDate)-strtotime($asset['purchase_date']))*100)));
      ?>
      <div style="margin:1rem 0;">
        <div style="font-size:.75rem;color:#6c757d;margin-bottom:.3rem;">Life used: <?= $lifeUsed ?>% | Expires: <?= formatDate($endDate) ?></div>
        <div class="progress" style="height:8px;">
          <div class="progress-bar <?= $lifeUsed>=90?'bg-danger':($lifeUsed>=70?'bg-warning':'bg-success') ?>" style="width:<?= $lifeUsed ?>%"></div>
        </div>
        <?php if ($daysLeft<365 && $daysLeft>0): ?>
        <div style="font-size:.75rem;color:var(--warning);margin-top:.3rem;">⚠ <?= round($daysLeft) ?> days to end of useful life</div>
        <?php elseif ($daysLeft<=0): ?>
        <div style="font-size:.75rem;color:var(--danger);margin-top:.3rem;">⚠ Useful life exceeded</div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <hr class="divider">
      <div style="display:flex;flex-direction:column;gap:.5rem;">
        <button class="btn btn-sm btn-outline-primary" data-modal="addMaintenanceModal">+ Maintenance Record</button>
        <?php if (hasRole(['admin','finance_officer','town_clerk'])): ?>
        <button class="btn btn-sm btn-outline-secondary" data-modal="calcDepModal">Calculate Depreciation</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

</div>

<!-- Calculate Depreciation Modal -->
<div class="modal-backdrop" id="calcDepModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Calculate Annual Depreciation</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="calc_depreciation"><input type="hidden" name="asset_id" value="<?= $viewId ?>">
      <div class="modal-body">
        <div style="background:#f8f9fa;padding:.8rem 1rem;border-radius:6px;margin-bottom:1rem;font-size:.83rem;">
          <div><strong><?= htmlspecialchars($asset['name']) ?></strong></div>
          <div class="text-muted">Method: <?= ucwords(str_replace('_',' ',$asset['depreciation_method']??'straight_line')) ?> | Useful life: <?= $asset['useful_life_years'] ?> yrs</div>
          <div class="text-muted">Current book value: UGX <?= number_format($asset['current_value']) ?></div>
        </div>
        <div class="form-group">
          <label class="form-label">Financial Year <span class="req">*</span></label>
          <select name="financial_year" class="form-select" required>
            <?php foreach ($fyears as $y): ?><option value="<?= $y ?>" <?= $y===$fy?'selected':'' ?>><?= $y ?></option><?php endforeach; ?>
          </select>
          <div class="form-text">Depreciation will be calculated and the closing value updated on the asset record.</div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Calculate</button></div>
    </form>
  </div>
</div>

<!-- Add Maintenance Modal -->
<div class="modal-backdrop" id="addMaintenanceModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Record Maintenance / Event</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_maintenance"><input type="hidden" name="asset_id" value="<?= $viewId ?>">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Event Type <span class="req">*</span></label>
          <select name="maintenance_type" class="form-select" required>
            <option value="routine">Routine Maintenance</option>
            <option value="repair">Repair</option>
            <option value="major_overhaul">Major Overhaul (adds value)</option>
            <option value="inspection">Inspection</option>
            <option value="disposal">Disposal</option>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Description <span class="req">*</span></label><textarea name="description" class="form-control" rows="3" required></textarea></div>
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Date <span class="req">*</span></label><input type="date" name="maintenance_date" class="form-control" required value="<?= date('Y-m-d') ?>"></div>
          <div class="form-group"><label class="form-label">Cost (UGX)</label><input type="number" name="cost" class="form-control" min="0" step="1" value="0"></div>
          <div class="form-group"><label class="form-label">Service Provider</label><input type="text" name="service_provider" class="form-control"></div>
          <div class="form-group"><label class="form-label">Performed By</label><input type="text" name="performed_by" class="form-control"></div>
          <div class="form-group"><label class="form-label">Next Due Date</label><input type="date" name="next_maintenance_date" class="form-control"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Save Record</button></div>
    </form>
  </div>
</div>

    <?php renderPageEnd(); echo '</div></div>'; renderFooter(); exit; ?>
<?php } ?>

<?php
// ══════════════════════════════════════════════════════════════════
// LIST VIEW
// ══════════════════════════════════════════════════════════════════
$statusFil = $_GET['status']  ?? '';
$catFil    = (int)($_GET['cat_id'] ?? 0);
$deptFil   = (int)($_GET['dept_id']?? 0);
$page      = max(1,(int)($_GET['page']??1));

$where = ['1=1']; $params = [];
if ($statusFil) { $where[] = 'a.status=?';        $params[] = $statusFil; }
if ($catFil)    { $where[] = 'a.category_id=?';   $params[] = $catFil; }
if ($deptFil)   { $where[] = 'a.department_id=?'; $params[] = $deptFil; }
$wSQL = implode(' AND ',$where);

$cnt = $db->prepare("SELECT COUNT(*) FROM assets a WHERE $wSQL"); $cnt->execute($params); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total,$page);
$rows = $db->prepare("SELECT a.*, ac.name AS cat_name, d.name AS dept_name, u.full_name AS officer_name
    FROM assets a LEFT JOIN asset_categories ac ON a.category_id=ac.id
    LEFT JOIN departments d ON a.department_id=d.id LEFT JOIN users u ON a.responsible_officer=u.id
    WHERE $wSQL ORDER BY a.created_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $assets = $rows->fetchAll();

$categories = $db->query("SELECT * FROM asset_categories ORDER BY name")->fetchAll();
$depts      = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$fSources   = $db->query("SELECT * FROM funding_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$allUsers   = $db->query("SELECT id,full_name FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();

// Summary stats
$totalAssets  = (int)$db->query("SELECT COUNT(*) FROM assets WHERE status='active'")->fetchColumn();
$totalValue   = (float)$db->query("SELECT COALESCE(SUM(purchase_value),0) FROM assets WHERE status='active'")->fetchColumn();
$currentValue = (float)$db->query("SELECT COALESCE(SUM(current_value),0) FROM assets WHERE status='active'")->fetchColumn();
$needsMaint   = (int)$db->query("SELECT COUNT(DISTINCT asset_id) FROM asset_maintenance WHERE next_maintenance_date IS NOT NULL AND next_maintenance_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();

renderHead('Assets Register');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Asset Register','Council assets, depreciation and maintenance');
renderPageStart('Asset Register','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Assets'],
]);
renderPageActions('<button class="btn btn-primary" data-modal="addAssetModal">+ Register Asset</button>
  <a href="'.APP_URL.'/api/export.php?type=assets" class="btn btn-outline-secondary no-print">📊 Export</a>');
renderFlashMessages();
?>

<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card"><div class="stat-icon">◒</div><div class="stat-info"><div class="label">Active Assets</div><div class="value"><?= number_format($totalAssets) ?></div></div></div>
  <div class="stat-card blue"><div class="stat-icon">💰</div><div class="stat-info"><div class="label">Total Purchase Value</div><div class="value"><?= number_format($totalValue/1000000,1)?>M</div></div></div>
  <div class="stat-card green"><div class="stat-icon">📊</div><div class="stat-info"><div class="label">Current Book Value</div><div class="value"><?= number_format($currentValue/1000000,1)?>M</div></div></div>
  <div class="stat-card <?= $needsMaint>0?'amber':'' ?>"><div class="stat-icon">🔧</div><div class="stat-info"><div class="label">Due for Maintenance</div><div class="value"><?= $needsMaint ?></div><div class="sub">within 30 days</div></div></div>
</div>

<form method="GET">
<div class="filter-row">
  <div class="form-group"><label>Category</label>
    <select name="cat_id" class="form-select"><option value="">All</option><?php foreach ($categories as $c): ?><option value="<?=$c['id']?>" <?=$catFil==$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?></select>
  </div>
  <div class="form-group"><label>Department</label>
    <select name="dept_id" class="form-select"><option value="">All</option><?php foreach ($depts as $d): ?><option value="<?=$d['id']?>" <?=$deptFil==$d['id']?'selected':''?>><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select>
  </div>
  <div class="form-group"><label>Status</label>
    <select name="status" class="form-select"><option value="">All</option>
      <?php foreach (['active','maintenance','disposed','lost','damaged'] as $s): ?><option value="<?=$s?>" <?=$statusFil===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label>&nbsp;</label><div style="display:flex;gap:.4rem;"><button type="submit" class="btn btn-primary">Filter</button><a href="index.php" class="btn btn-outline-secondary">Reset</a></div></div>
</div>
</form>

<div class="card">
  <div class="card-header"><h5><span class="ch-icon">◒</span> Asset Register (<?= number_format($total) ?>)</h5></div>
  <div class="card-body p-0">
    <?php if ($assets): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>#</th><th>Asset No.</th><th>Name</th><th>Category</th><th>Dept</th><th>Location</th><th class="text-right">Purchase Value</th><th class="text-right">Book Value</th><th>Condition</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($assets as $i => $a): ?>
        <tr>
          <td class="fs-xs text-muted"><?=$pg['offset']+$i+1?></td>
          <td class="fw-700 text-primary"><?=htmlspecialchars($a['asset_number'])?></td>
          <td><div class="fw-600"><?=htmlspecialchars($a['name'])?></div><?php if ($a['serial_number']): ?><div class="fs-xs text-muted">S/N: <?=htmlspecialchars($a['serial_number'])?></div><?php endif; ?></td>
          <td class="fs-sm"><?=htmlspecialchars($a['cat_name']??'—')?></td>
          <td class="fs-sm"><?=htmlspecialchars($a['dept_name']??'—')?></td>
          <td class="fs-sm"><?=htmlspecialchars($a['location']??'—')?></td>
          <td class="text-right"><?=number_format($a['purchase_value'])?></td>
          <td class="text-right fw-700 <?=$a['current_value']<$a['purchase_value']*0.2?'text-danger':($a['current_value']<$a['purchase_value']*0.5?'text-warning':'text-success')?>"><?=number_format($a['current_value'])?></td>
          <td>
            <?php if ($a['condition_rating']): ?>
            <span class="badge badge-<?=$a['condition_rating']==='excellent'?'success':($a['condition_rating']==='good'?'info':($a['condition_rating']==='fair'?'warning':($a['condition_rating']==='poor'?'danger':'dark')))?>"><?=ucfirst($a['condition_rating'])?></span>
            <?php endif; ?>
          </td>
          <td><?=getStatusBadge($a['status'])?></td>
          <td><a href="?view=<?=$a['id']?>" class="btn btn-sm btn-outline-primary">Open</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="6" class="text-right">TOTALS:</th>
            <th class="text-right"><?=number_format(array_sum(array_column($assets,'purchase_value')))?></th>
            <th class="text-right fw-700"><?=number_format(array_sum(array_column($assets,'current_value')))?></th>
            <th colspan="3"></th>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">◒</div><h5>No assets found</h5><p>Register an asset using the button above.</p></div><?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?=renderPagination($pg,'?cat_id='.$catFil.'&dept_id='.$deptFil.'&status='.urlencode($statusFil))?></div><?php endif; ?>
</div>

<!-- Add Asset Modal -->
<div class="modal-backdrop" id="addAssetModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Register New Asset</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?><input type="hidden" name="action" value="add_asset">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group" style="grid-column:span 2;"><label class="form-label">Asset Name / Description <span class="req">*</span></label><input type="text" name="name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Category</label>
            <select name="category_id" class="form-select"><option value="">—</option><?php foreach ($categories as $c): ?><option value="<?=$c['id']?>"><?=htmlspecialchars($c['name'])?></option><?php endforeach; ?></select>
          </div>
          <div class="form-group"><label class="form-label">Department</label>
            <select name="department_id" class="form-select"><option value="">—</option><?php foreach ($depts as $d): ?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?></select>
          </div>
          <div class="form-group"><label class="form-label">Location</label><input type="text" name="location" class="form-control"></div>
          <div class="form-group"><label class="form-label">Serial Number</label><input type="text" name="serial_number" class="form-control"></div>
          <div class="form-group"><label class="form-label">Purchase Date</label><input type="date" name="purchase_date" class="form-control"></div>
          <div class="form-group"><label class="form-label">Purchase Value (UGX) <span class="req">*</span></label><input type="number" name="purchase_value" class="form-control" required min="0" step="1"></div>
          <div class="form-group"><label class="form-label">Useful Life (years)</label><input type="number" name="useful_life_years" class="form-control" min="1" max="100" value="5"></div>
          <div class="form-group"><label class="form-label">Residual Value (UGX)</label><input type="number" name="residual_value" class="form-control" min="0" step="1" value="0"></div>
          <div class="form-group"><label class="form-label">Depreciation Method</label>
            <select name="depreciation_method" class="form-select"><option value="straight_line">Straight Line</option><option value="declining_balance">Declining Balance</option></select>
          </div>
          <div class="form-group"><label class="form-label">Condition</label>
            <select name="condition_rating" class="form-select"><option value="excellent">Excellent</option><option value="good" selected>Good</option><option value="fair">Fair</option><option value="poor">Poor</option></select>
          </div>
          <div class="form-group"><label class="form-label">Funding Source</label>
            <select name="funding_source_id" class="form-select"><option value="">—</option><?php foreach ($fSources as $fs): ?><option value="<?=$fs['id']?>"><?=htmlspecialchars($fs['name'])?></option><?php endforeach; ?></select>
          </div>
          <div class="form-group"><label class="form-label">Responsible Officer</label>
            <select name="responsible_officer" class="form-select"><option value="">—</option><?php foreach ($allUsers as $u): ?><option value="<?=$u['id']?>"><?=htmlspecialchars($u['full_name'])?></option><?php endforeach; ?></select>
          </div>
        </div>
        <div class="form-group"><label class="form-label">Additional Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button><button type="submit" class="btn btn-primary">Register Asset</button></div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
