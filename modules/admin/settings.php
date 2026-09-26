<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
if (!hasRole(['admin','town_clerk'])) { setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit; }
$user = getCurrentUser();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $settings = $_POST['settings'] ?? [];
    foreach ($settings as $key => $val) {
        $db->prepare("UPDATE system_settings SET setting_value=?, updated_by=? WHERE setting_key=?")->execute([trim($val), $user['id'], $key]);
    }
    logAudit('UPDATE_SETTINGS','admin','system_settings',0,'');
    setFlash('success','Settings saved successfully.');
    header('Location: settings.php'); exit;
}

$allSettings = $db->query("SELECT * FROM system_settings ORDER BY setting_group, setting_key")->fetchAll();
$grouped = [];
foreach ($allSettings as $s) $grouped[$s['setting_group']][] = $s;

// Ward management
$wards = $db->query("SELECT * FROM wards ORDER BY name")->fetchAll();
// Financial years
$fyears = $db->query("SELECT * FROM financial_years ORDER BY start_date DESC")->fetchAll();
// Revenue sources
$sources = $db->query("SELECT * FROM revenue_sources ORDER BY name")->fetchAll();

// Handle ward/FY/source actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') { /* already handled above */ }

renderHead('System Settings');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('System Settings','Configure council and system settings');
renderPageStart('System Settings','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Settings']
]);
renderPageActions('');
renderFlashMessages();
?>

<div class="tab-group">
<div class="tab-nav">
  <button class="tab-link active" data-tab="generalTab">General Settings</button>
  <button class="tab-link" data-tab="wardTab">Wards</button>
  <button class="tab-link" data-tab="fyTab">Financial Years</button>
  <button class="tab-link" data-tab="srcTab">Revenue Sources</button>
</div>
</div>

<!-- General Settings -->
<div id="generalTab" class="tab-panel active">
  <form method="POST">
    <?=csrfField()?>
    <div class="grid-2" style="align-items:start;">
      <?php foreach ($grouped as $group => $settings): ?>
      <div class="card">
        <div class="card-header"><h5><?=ucwords(str_replace('_',' ',$group))?> Settings</h5></div>
        <div class="card-body">
          <?php foreach ($settings as $s): ?>
          <div class="form-group">
            <label class="form-label"><?=htmlspecialchars(ucwords(str_replace('_',' ',$s['setting_key'])))?></label>
            <input type="text" name="settings[<?=htmlspecialchars($s['setting_key'])?>]" class="form-control" value="<?=htmlspecialchars($s['setting_value']??'')?>">
            <?php if ($s['description']): ?><div class="form-text"><?=htmlspecialchars($s['description'])?></div><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:1rem;">
      <button type="submit" class="btn btn-primary">Save All Settings</button>
    </div>
  </form>
</div>

<!-- Wards -->
<div id="wardTab" class="tab-panel">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◉</span> Ward Management</h5>
      <button class="btn btn-sm btn-primary" data-modal="addWardModal">+ Add Ward</button>
    </div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Ward Code</th><th>Ward Name</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($wards as $w): ?>
          <tr><td class="fw-700 text-primary"><?=htmlspecialchars($w['ward_code'])?></td><td class="fw-600"><?=htmlspecialchars($w['name'])?></td><td><?=getStatusBadge($w['is_active']?'active':'inactive')?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Financial Years -->
<div id="fyTab" class="tab-panel">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">📅</span> Financial Years</h5></div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Year Code</th><th>Start Date</th><th>End Date</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($fyears as $fy): ?>
          <tr><td class="fw-700 text-primary"><?=htmlspecialchars($fy['year_code'])?></td><td><?=formatDate($fy['start_date'])?></td><td><?=formatDate($fy['end_date'])?></td><td><?=getStatusBadge($fy['status'])?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Revenue Sources -->
<div id="srcTab" class="tab-panel">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◈</span> Revenue Sources</h5>
      <button class="btn btn-sm btn-primary" data-modal="addSrcModal">+ Add Source</button>
    </div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Frequency</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($sources as $s): ?>
          <tr>
            <td class="fw-700 text-primary"><?=htmlspecialchars($s['source_code'])?></td>
            <td class="fw-600"><?=htmlspecialchars($s['name'])?></td>
            <td class="fs-sm"><?=htmlspecialchars($s['category']??'—')?></td>
            <td class="fs-sm"><?=ucfirst($s['payment_frequency'])?></td>
            <td><?=getStatusBadge($s['is_active']?'active':'inactive')?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Add Ward Modal -->
<div class="modal-backdrop" id="addWardModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Add Ward</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST" action="<?=APP_URL?>/api/settings.php">
      <?=csrfField()?>
      <input type="hidden" name="action" value="add_ward">
      <input type="hidden" name="redirect" value="<?=APP_URL?>/modules/admin/settings.php">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Ward Code <span class="req">*</span></label><input type="text" name="ward_code" class="form-control" required placeholder="e.g. W006"></div>
        <div class="form-group"><label class="form-label">Ward Name <span class="req">*</span></label><input type="text" name="ward_name" class="form-control" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Add Ward</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Revenue Source Modal -->
<div class="modal-backdrop" id="addSrcModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Add Revenue Source</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST" action="<?=APP_URL?>/api/settings.php">
      <?=csrfField()?>
      <input type="hidden" name="action" value="add_revenue_source">
      <input type="hidden" name="redirect" value="<?=APP_URL?>/modules/admin/settings.php">
      <div class="modal-body">
        <div class="form-group"><label class="form-label">Source Code <span class="req">*</span></label><input type="text" name="source_code" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Name <span class="req">*</span></label><input type="text" name="name" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Category</label><input type="text" name="category" class="form-control"></div>
        <div class="form-group"><label class="form-label">Payment Frequency</label>
          <select name="payment_frequency" class="form-select">
            <?php foreach (['daily','weekly','monthly','quarterly','annually','once'] as $f): ?><option value="<?=$f?>"><?=ucfirst($f)?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Add Source</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
