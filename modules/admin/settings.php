<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
if (!hasRole(['admin','town_clerk'])) {
    setFlash('danger','Access denied.');
    header('Location: '.APP_URL.'/dashboard.php'); exit;
}
$user = getCurrentUser();
$db   = getDB();

/* ─── POST handlers ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'save_settings';

    /* Save general settings */
    if ($action === 'save_settings') {
        foreach ($_POST['settings'] ?? [] as $key => $val) {
            $db->prepare("UPDATE system_settings SET setting_value=?, updated_by=? WHERE setting_key=?")
               ->execute([trim($val), $user['id'], $key]);
        }
        logAudit('UPDATE_SETTINGS','admin','system_settings',0,'');
        setFlash('success','Settings saved successfully.');

    /* Add ward */
    } elseif ($action === 'add_ward') {
        $code = strtoupper(trim($_POST['ward_code'] ?? ''));
        $name = trim($_POST['ward_name'] ?? '');
        if ($code && $name) {
            try {
                $db->prepare("INSERT INTO wards (ward_code,name,is_active) VALUES (?,?,1)")
                   ->execute([$code, $name]);
                setFlash('success',"Ward added: $name");
            } catch (PDOException $e) {
                setFlash('danger', $e->getCode()==23000 ? "Ward code '$code' already exists." : $e->getMessage());
            }
        }

    /* Toggle ward active */
    } elseif ($action === 'toggle_ward') {
        $id = (int)$_POST['ward_id'];
        $db->prepare("UPDATE wards SET is_active = NOT is_active WHERE id=?")->execute([$id]);
        setFlash('success','Ward status updated.');

    /* Add parish */
    } elseif ($action === 'add_parish') {
        $wardId = (int)$_POST['ward_id'];
        $code   = strtoupper(trim($_POST['parish_code'] ?? ''));
        $name   = trim($_POST['parish_name'] ?? '');
        if ($wardId && $code && $name) {
            try {
                $db->prepare("INSERT INTO parishes (ward_id,parish_code,name,is_active) VALUES (?,?,?,1)")
                   ->execute([$wardId, $code, $name]);
                setFlash('success',"Parish added: $name");
            } catch (PDOException $e) {
                setFlash('danger', $e->getCode()==23000 ? "Parish code '$code' already exists." : $e->getMessage());
            }
        }

    /* Add village */
    } elseif ($action === 'add_village') {
        $parishId = (int)$_POST['parish_id'];
        $code     = strtoupper(trim($_POST['village_code'] ?? ''));
        $name     = trim($_POST['village_name'] ?? '');
        if ($parishId && $code && $name) {
            try {
                $db->prepare("INSERT INTO villages (parish_id,village_code,name,is_active) VALUES (?,?,?,1)")
                   ->execute([$parishId, $code, $name]);
                setFlash('success',"Village added: $name");
            } catch (PDOException $e) {
                setFlash('danger', $e->getCode()==23000 ? "Village code already exists." : $e->getMessage());
            }
        }

    /* Add financial year */
    } elseif ($action === 'add_fy') {
        $code  = trim($_POST['year_code'] ?? '');
        $start = trim($_POST['start_date'] ?? '');
        $end   = trim($_POST['end_date']   ?? '');
        if ($code && $start && $end) {
            try {
                $db->prepare("INSERT INTO financial_years (year_code,start_date,end_date,status) VALUES (?,?,?,'open')")
                   ->execute([$code, $start, $end]);
                logAudit('CREATE','admin','financial_year',0,$code);
                setFlash('success',"Financial year added: $code");
            } catch (PDOException $e) {
                setFlash('danger', $e->getCode()==23000 ? "Year '$code' already exists." : $e->getMessage());
            }
        }

    /* Close financial year */
    } elseif ($action === 'close_fy') {
        $id = (int)$_POST['fy_id'];
        if (!hasRole(['admin','town_clerk'])) {
            setFlash('danger','Only the Town Clerk or Administrator can close a financial year.');
        } else {
            $db->prepare("UPDATE financial_years SET status='closed', closed_by=?, closed_at=NOW() WHERE id=? AND status='open'")
               ->execute([$user['id'], $id]);
            logAudit('CLOSE_FY','admin','financial_year',$id,'');
            setFlash('success','Financial year closed. Historical records are now locked.');
        }

    /* Add revenue source */
    } elseif ($action === 'add_revenue_source') {
        $code = strtoupper(trim($_POST['source_code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        if ($code && $name) {
            try {
                $db->prepare("INSERT INTO revenue_sources (source_code,name,category,payment_frequency,default_amount,is_active) VALUES (?,?,?,?,?,1)")
                   ->execute([$code, $name, trim($_POST['category']??''), $_POST['payment_frequency']??'annually', (float)str_replace(',','', $_POST['default_amount']??0)]);
                setFlash('success',"Revenue source added: $name");
            } catch (PDOException $e) {
                setFlash('danger', $e->getCode()==23000 ? "Code '$code' already exists." : $e->getMessage());
            }
        }

    /* Toggle revenue source */
    } elseif ($action === 'toggle_source') {
        $id = (int)$_POST['source_id'];
        $db->prepare("UPDATE revenue_sources SET is_active = NOT is_active WHERE id=?")->execute([$id]);
        setFlash('success','Revenue source status updated.');

    /* Update revenue source */
    } elseif ($action === 'update_source') {
        $id = (int)$_POST['source_id'];
        $db->prepare("UPDATE revenue_sources SET name=?,category=?,payment_frequency=?,default_amount=? WHERE id=?")
           ->execute([trim($_POST['name']), trim($_POST['category']??''), $_POST['payment_frequency'], (float)str_replace(',','', $_POST['default_amount']??0), $id]);
        setFlash('success','Revenue source updated.');

    /* Add funding source */
    } elseif ($action === 'add_funding_source') {
        $code = strtoupper(trim($_POST['source_code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        if ($code && $name) {
            try {
                $db->prepare("INSERT INTO funding_sources (source_code,name,source_type,is_active) VALUES (?,?,?,1)")
                   ->execute([$code, $name, $_POST['source_type']??'other']);
                setFlash('success',"Funding source added: $name");
            } catch (PDOException $e) {
                setFlash('danger', $e->getCode()==23000 ? "Code already exists." : $e->getMessage());
            }
        }
    }

    header('Location: settings.php?tab='.urlencode($_POST['active_tab']??'general')); exit;
}

/* ─── Data loads ─────────────────────────────────────────────────── */
$allSettings = $db->query("SELECT * FROM system_settings ORDER BY setting_group, setting_key")->fetchAll();
$grouped     = [];
foreach ($allSettings as $s) $grouped[$s['setting_group']][] = $s;

$wards          = $db->query("SELECT w.*, (SELECT COUNT(*) FROM parishes WHERE ward_id=w.id) AS parishes FROM wards w ORDER BY w.name")->fetchAll();
$parishes       = $db->query("SELECT p.*, w.name AS ward_name FROM parishes p JOIN wards w ON p.ward_id=w.id ORDER BY w.name, p.name")->fetchAll();
$villages       = $db->query("SELECT v.*, p.name AS parish_name, w.name AS ward_name FROM villages v JOIN parishes p ON v.parish_id=p.id JOIN wards w ON p.ward_id=w.id ORDER BY w.name, p.name, v.name")->fetchAll();
$fyears         = $db->query("SELECT * FROM financial_years ORDER BY start_date DESC")->fetchAll();
$revSources     = $db->query("SELECT * FROM revenue_sources ORDER BY name")->fetchAll();
$fundSources    = $db->query("SELECT * FROM funding_sources ORDER BY name")->fetchAll();

$activeTab = $_GET['tab'] ?? 'general';

renderHead('System Settings');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('System Settings','Configure council, wards, financial years and revenue sources');
renderPageStart('System Settings','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Settings']
]);
renderPageActions('');
renderFlashMessages();
?>

<!-- Tab Navigation -->
<div class="tab-group" style="margin-bottom:1.2rem;">
  <div class="tab-nav">
    <button class="tab-link <?= $activeTab==='general'?'active':'' ?>"   data-tab="generalTab">General</button>
    <button class="tab-link <?= $activeTab==='wards'?'active':'' ?>"     data-tab="wardsTab">Wards & Parishes</button>
    <button class="tab-link <?= $activeTab==='fy'?'active':'' ?>"        data-tab="fyTab">Financial Years</button>
    <button class="tab-link <?= $activeTab==='revsrc'?'active':'' ?>"    data-tab="revsrcTab">Revenue Sources</button>
    <button class="tab-link <?= $activeTab==='fundsrc'?'active':'' ?>"   data-tab="fundsrcTab">Funding Sources</button>
  </div>
</div>

<!-- ══ GENERAL SETTINGS ════════════════════════════════════════════ -->
<div id="generalTab" class="tab-panel <?= $activeTab==='general'?'active':'' ?>">
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="active_tab" value="general">
    <div class="grid-2" style="align-items:start;">
      <?php foreach ($grouped as $group => $settings): ?>
      <div class="card">
        <div class="card-header">
          <h5><span class="ch-icon">◬</span> <?= ucwords(str_replace('_',' ',$group)) ?> Settings</h5>
        </div>
        <div class="card-body">
          <?php foreach ($settings as $s):
            $isSecret = str_contains($s['setting_key'],'password') || str_contains($s['setting_key'],'secret');
          ?>
          <div class="form-group">
            <label class="form-label"><?= htmlspecialchars(ucwords(str_replace('_',' ',$s['setting_key']))) ?></label>
            <input type="<?= $isSecret?'password':'text' ?>"
                   name="settings[<?= htmlspecialchars($s['setting_key']) ?>]"
                   class="form-control"
                   value="<?= $isSecret ? '' : htmlspecialchars($s['setting_value']??'') ?>"
                   placeholder="<?= $isSecret ? '(leave blank to keep current)' : '' ?>">
            <?php if ($s['description']): ?>
              <div class="form-text"><?= htmlspecialchars($s['description']) ?></div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:1.2rem; display:flex; gap:.6rem;">
      <button type="submit" class="btn btn-primary">Save All Settings</button>
      <a href="settings.php" class="btn btn-outline-secondary">Reset</a>
    </div>
  </form>
</div>

<!-- ══ WARDS & PARISHES ════════════════════════════════════════════ -->
<div id="wardsTab" class="tab-panel <?= $activeTab==='wards'?'active':'' ?>">

  <div class="grid-3" style="margin-bottom:1.2rem;">
    <div class="stat-card"><div class="stat-icon">◉</div><div class="stat-info"><div class="label">Wards</div><div class="value"><?= count($wards) ?></div></div></div>
    <div class="stat-card blue"><div class="stat-icon">◈</div><div class="stat-info"><div class="label">Parishes</div><div class="value"><?= count($parishes) ?></div></div></div>
    <div class="stat-card green"><div class="stat-icon">◆</div><div class="stat-info"><div class="label">Villages</div><div class="value"><?= count($villages) ?></div></div></div>
  </div>

  <div class="grid-2" style="align-items:start;">

    <!-- Wards -->
    <div class="card">
      <div class="card-header">
        <h5><span class="ch-icon">◉</span> Wards (<?= count($wards) ?>)</h5>
        <button class="btn btn-sm btn-primary" data-modal="addWardModal">+ Add Ward</button>
      </div>
      <div class="card-body p-0">
        <div class="table-wrapper">
          <table class="tcms-table">
            <thead><tr><th>Code</th><th>Ward Name</th><th>Parishes</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($wards as $w): ?>
            <tr>
              <td class="fw-700 text-primary"><?= htmlspecialchars($w['ward_code']) ?></td>
              <td class="fw-600"><?= htmlspecialchars($w['name']) ?></td>
              <td class="text-center"><?= $w['parishes'] ?></td>
              <td><?= getStatusBadge($w['is_active']?'active':'inactive') ?></td>
              <td>
                <form method="POST" style="display:inline;">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle_ward">
                  <input type="hidden" name="ward_id" value="<?= $w['id'] ?>">
                  <input type="hidden" name="active_tab" value="wards">
                  <button type="submit" class="btn btn-sm <?= $w['is_active']?'btn-outline-danger':'btn-outline-success' ?>">
                    <?= $w['is_active']?'Disable':'Enable' ?>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Parishes -->
    <div class="card">
      <div class="card-header">
        <h5><span class="ch-icon">◈</span> Parishes (<?= count($parishes) ?>)</h5>
        <button class="btn btn-sm btn-primary" data-modal="addParishModal">+ Add Parish</button>
      </div>
      <div class="card-body p-0">
        <div class="table-wrapper">
          <table class="tcms-table">
            <thead><tr><th>Code</th><th>Parish</th><th>Ward</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($parishes as $p): ?>
            <tr>
              <td class="fw-700 text-primary"><?= htmlspecialchars($p['parish_code']) ?></td>
              <td class="fw-600"><?= htmlspecialchars($p['name']) ?></td>
              <td class="fs-sm"><?= htmlspecialchars($p['ward_name']) ?></td>
              <td><?= getStatusBadge($p['is_active']?'active':'inactive') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$parishes): ?>
            <tr><td colspan="4" class="text-center text-muted fs-sm" style="padding:1rem;">No parishes added yet.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>

  <!-- Villages -->
  <div class="card" style="margin-top:1rem;">
    <div class="card-header">
      <h5><span class="ch-icon">◆</span> Villages (<?= count($villages) ?>)</h5>
      <button class="btn btn-sm btn-primary" data-modal="addVillageModal">+ Add Village</button>
    </div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Code</th><th>Village</th><th>Parish</th><th>Ward</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($villages as $v): ?>
          <tr>
            <td class="fw-700 text-primary fs-sm"><?= htmlspecialchars($v['village_code']) ?></td>
            <td class="fw-600"><?= htmlspecialchars($v['name']) ?></td>
            <td class="fs-sm"><?= htmlspecialchars($v['parish_name']) ?></td>
            <td class="fs-sm"><?= htmlspecialchars($v['ward_name']) ?></td>
            <td><?= getStatusBadge($v['is_active']?'active':'inactive') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$villages): ?>
          <tr><td colspan="5" class="text-center text-muted fs-sm" style="padding:1rem;">No villages added yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ══ FINANCIAL YEARS ═════════════════════════════════════════════ -->
<div id="fyTab" class="tab-panel <?= $activeTab==='fy'?'active':'' ?>">
  <div class="card">
    <div class="card-header">
      <h5><span class="ch-icon">📅</span> Financial Years</h5>
      <button class="btn btn-sm btn-primary" data-modal="addFyModal">+ Add Year</button>
    </div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Year Code</th><th>Start Date</th><th>End Date</th><th>Status</th><th>Closed By</th><th>Closed At</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($fyears as $fy):
            $closedByStmt = $db->prepare("SELECT full_name FROM users WHERE id=?");
            $closedByStmt->execute([$fy['closed_by']]); $closedBy = $closedByStmt->fetchColumn();
          ?>
          <tr>
            <td class="fw-700 text-primary"><?= htmlspecialchars($fy['year_code']) ?></td>
            <td><?= formatDate($fy['start_date']) ?></td>
            <td><?= formatDate($fy['end_date']) ?></td>
            <td><?= getStatusBadge($fy['status']) ?></td>
            <td class="fs-sm"><?= htmlspecialchars($closedBy ?: '—') ?></td>
            <td class="fs-sm"><?= $fy['closed_at'] ? formatDateTime($fy['closed_at']) : '—' ?></td>
            <td>
              <?php if ($fy['status']==='open' && hasRole(['admin','town_clerk'])): ?>
              <form method="POST" style="display:inline;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="close_fy">
                <input type="hidden" name="fy_id" value="<?= $fy['id'] ?>">
                <input type="hidden" name="active_tab" value="fy">
                <button type="submit" class="btn btn-sm btn-warning"
                  data-confirm="Close financial year <?= htmlspecialchars($fy['year_code']) ?>? This will lock all transactions for this year.">
                  Close Year
                </button>
              </form>
              <?php else: ?>
              <span class="badge badge-secondary">Closed</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="alert alert-warning" style="margin-top:1rem;">
    <span>⚠</span>
    <span><strong>Important:</strong> Closing a financial year locks all revenue and expenditure records for that year. Only the Town Clerk or Administrator can close a year. This action is logged in the audit trail.</span>
  </div>
</div>

<!-- ══ REVENUE SOURCES ═════════════════════════════════════════════ -->
<div id="revsrcTab" class="tab-panel <?= $activeTab==='revsrc'?'active':'' ?>">
  <div class="card">
    <div class="card-header">
      <h5><span class="ch-icon">◈</span> Revenue Sources (<?= count($revSources) ?>)</h5>
      <button class="btn btn-sm btn-primary" data-modal="addSrcModal">+ Add Source</button>
    </div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Frequency</th><th class="text-right">Default Amount</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($revSources as $s): ?>
          <tr>
            <td class="fw-700 text-primary"><?= htmlspecialchars($s['source_code']) ?></td>
            <td class="fw-600"><?= htmlspecialchars($s['name']) ?></td>
            <td class="fs-sm"><?= htmlspecialchars($s['category']??'—') ?></td>
            <td class="fs-sm"><?= ucfirst($s['payment_frequency']) ?></td>
            <td class="text-right fs-sm"><?= $s['default_amount']>0 ? number_format($s['default_amount']) : '—' ?></td>
            <td><?= getStatusBadge($s['is_active']?'active':'inactive') ?></td>
            <td>
              <div style="display:flex;gap:.3rem;">
                <button class="btn btn-sm btn-outline-primary"
                  onclick="editSource(<?= htmlspecialchars(json_encode($s)) ?>)">Edit</button>
                <form method="POST" style="display:inline;">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle_source">
                  <input type="hidden" name="source_id" value="<?= $s['id'] ?>">
                  <input type="hidden" name="active_tab" value="revsrc">
                  <button type="submit" class="btn btn-sm <?= $s['is_active']?'btn-outline-danger':'btn-outline-success' ?>">
                    <?= $s['is_active']?'Disable':'Enable' ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ══ FUNDING SOURCES ═════════════════════════════════════════════ -->
<div id="fundsrcTab" class="tab-panel <?= $activeTab==='fundsrc'?'active':'' ?>">
  <div class="card">
    <div class="card-header">
      <h5><span class="ch-icon">◎</span> Funding Sources (<?= count($fundSources) ?>)</h5>
      <button class="btn btn-sm btn-primary" data-modal="addFundSrcModal">+ Add Source</button>
    </div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($fundSources as $fs): ?>
          <tr>
            <td class="fw-700 text-primary"><?= htmlspecialchars($fs['source_code']) ?></td>
            <td class="fw-600"><?= htmlspecialchars($fs['name']) ?></td>
            <td><span class="badge badge-info"><?= ucwords(str_replace('_',' ',$fs['source_type'])) ?></span></td>
            <td><?= getStatusBadge($fs['is_active']?'active':'inactive') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════ MODALS ═══════════════════════════════════════ -->

<!-- Add Ward -->
<div class="modal-backdrop" id="addWardModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Add Ward</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_ward"><input type="hidden" name="active_tab" value="wards">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Ward Code <span class="req">*</span></label>
          <input type="text" name="ward_code" class="form-control" required placeholder="e.g. W006" style="text-transform:uppercase;" maxlength="10"></div>
        <div class="form-group"><label class="form-label">Ward Name <span class="req">*</span></label>
          <input type="text" name="ward_name" class="form-control" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Add Ward</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Parish -->
<div class="modal-backdrop" id="addParishModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Add Parish</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_parish"><input type="hidden" name="active_tab" value="wards">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Ward <span class="req">*</span></label>
          <select name="ward_id" class="form-select" required>
            <option value="">-- Select Ward --</option>
            <?php foreach ($wards as $w): ?><option value="<?= $w['id'] ?>"><?= htmlspecialchars($w['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label">Parish Code <span class="req">*</span></label>
          <input type="text" name="parish_code" class="form-control" required placeholder="e.g. P001" style="text-transform:uppercase;" maxlength="10"></div>
        <div class="form-group"><label class="form-label">Parish Name <span class="req">*</span></label>
          <input type="text" name="parish_name" class="form-control" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Add Parish</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Village -->
<div class="modal-backdrop" id="addVillageModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Add Village</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_village"><input type="hidden" name="active_tab" value="wards">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Parish <span class="req">*</span></label>
          <select name="parish_id" class="form-select" required>
            <option value="">-- Select Parish --</option>
            <?php foreach ($parishes as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['ward_name'].' → '.$p['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label">Village Code <span class="req">*</span></label>
          <input type="text" name="village_code" class="form-control" required placeholder="e.g. V001" style="text-transform:uppercase;" maxlength="10"></div>
        <div class="form-group"><label class="form-label">Village Name <span class="req">*</span></label>
          <input type="text" name="village_name" class="form-control" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Add Village</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Financial Year -->
<div class="modal-backdrop" id="addFyModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Add Financial Year</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_fy"><input type="hidden" name="active_tab" value="fy">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Year Code <span class="req">*</span></label>
          <input type="text" name="year_code" class="form-control" required placeholder="e.g. 2027/2028"></div>
        <div class="form-group"><label class="form-label">Start Date <span class="req">*</span></label>
          <input type="date" name="start_date" class="form-control" required></div>
        <div class="form-group"><label class="form-label">End Date <span class="req">*</span></label>
          <input type="date" name="end_date" class="form-control" required></div>
        <div class="alert alert-info"><span>ℹ</span><span>New financial years are created with <strong>Open</strong> status and can be closed at year end.</span></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Add Financial Year</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Revenue Source -->
<div class="modal-backdrop" id="addSrcModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Add Revenue Source</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_revenue_source"><input type="hidden" name="active_tab" value="revsrc">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Source Code <span class="req">*</span></label>
          <input type="text" name="source_code" class="form-control" required style="text-transform:uppercase;" placeholder="e.g. MKT002"></div>
        <div class="form-group"><label class="form-label">Name <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Category</label>
          <input type="text" name="category" class="form-control" placeholder="e.g. Market, Business, Property"></div>
        <div class="form-group"><label class="form-label">Payment Frequency</label>
          <select name="payment_frequency" class="form-select">
            <?php foreach (['daily','weekly','monthly','quarterly','annually','once'] as $f): ?>
            <option value="<?= $f ?>"><?= ucfirst($f) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label">Default Amount (UGX)</label>
          <input type="number" name="default_amount" class="form-control" min="0" step="1" value="0"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Add Source</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Revenue Source -->
<div class="modal-backdrop" id="editSrcModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Edit Revenue Source</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="update_source"><input type="hidden" name="active_tab" value="revsrc">
      <input type="hidden" name="source_id" id="es_id">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Source Code</label>
          <input type="text" id="es_code" class="form-control" disabled></div>
        <div class="form-group"><label class="form-label">Name <span class="req">*</span></label>
          <input type="text" name="name" id="es_name" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Category</label>
          <input type="text" name="category" id="es_category" class="form-control"></div>
        <div class="form-group"><label class="form-label">Payment Frequency</label>
          <select name="payment_frequency" id="es_freq" class="form-select">
            <?php foreach (['daily','weekly','monthly','quarterly','annually','once'] as $f): ?>
            <option value="<?= $f ?>"><?= ucfirst($f) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label">Default Amount (UGX)</label>
          <input type="number" name="default_amount" id="es_amount" class="form-control" min="0" step="1"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Funding Source -->
<div class="modal-backdrop" id="addFundSrcModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Add Funding Source</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?= csrfField() ?><input type="hidden" name="action" value="add_funding_source"><input type="hidden" name="active_tab" value="fundsrc">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Source Code <span class="req">*</span></label>
          <input type="text" name="source_code" class="form-control" required style="text-transform:uppercase;"></div>
        <div class="form-group"><label class="form-label">Name <span class="req">*</span></label>
          <input type="text" name="name" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Type <span class="req">*</span></label>
          <select name="source_type" class="form-select" required>
            <?php foreach (['local_revenue','central_government','donor','loan','grant','other'] as $t): ?>
            <option value="<?= $t ?>"><?= ucwords(str_replace('_',' ',$t)) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Add Funding Source</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; ?>
<script>
function editSource(s) {
  document.getElementById('es_id').value       = s.id;
  document.getElementById('es_code').value     = s.source_code;
  document.getElementById('es_name').value     = s.name;
  document.getElementById('es_category').value = s.category || '';
  document.getElementById('es_freq').value     = s.payment_frequency;
  document.getElementById('es_amount').value   = s.default_amount || 0;
  openModal('editSrcModal');
}

// Activate correct tab on page load (from URL param or server redirect)
document.addEventListener('DOMContentLoaded', function () {
  const tabMap = {
    'general':  'generalTab',
    'wards':    'wardsTab',
    'fy':       'fyTab',
    'revsrc':   'revsrcTab',
    'fundsrc':  'fundsrcTab'
  };
  const param = new URLSearchParams(window.location.search).get('tab');
  if (param && tabMap[param]) {
    document.querySelectorAll('.tab-link').forEach(l => l.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    const btn = document.querySelector(`[data-tab="${tabMap[param]}"]`);
    const panel = document.getElementById(tabMap[param]);
    if (btn) btn.classList.add('active');
    if (panel) panel.classList.add('active');
  }
});
</script>
<?php renderFooter(); ?>
