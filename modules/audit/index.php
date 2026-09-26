<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
if (!hasRole(['admin','town_clerk','auditor'])) { setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit; }
$user = getCurrentUser();
$db   = getDB();

$search   = trim($_GET['search'] ?? '');
$module   = $_GET['module'] ?? '';
$action   = $_GET['action_type'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$page     = max(1,(int)($_GET['page'] ?? 1));

$where = ['created_at BETWEEN ? AND ?']; $params = [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'];
if ($search)  { $where[] = "(username LIKE ? OR record_reference LIKE ?)"; $params=array_merge($params,["%$search%","%$search%"]); }
if ($module)  { $where[] = "module=?"; $params[] = $module; }
if ($action)  { $where[] = "action=?"; $params[] = $action; }
$wSQL = implode(' AND ', $where);

$cnt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE $wSQL"); $cnt->execute($params); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total,$page);

$rows = $db->prepare("SELECT al.*, u.full_name FROM audit_logs al LEFT JOIN users u ON al.user_id=u.id WHERE $wSQL ORDER BY al.created_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $logs = $rows->fetchAll();

$modules = $db->query("SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$actions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

// Login logs (last 50)
$loginLogs = $db->query("SELECT ll.*, u.full_name FROM login_logs ll LEFT JOIN users u ON ll.user_id=u.id ORDER BY ll.created_at DESC LIMIT 50")->fetchAll();

renderHead('Audit Trail');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Audit Trail','Complete system activity log');
renderPageStart('Audit Trail','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Audit Trail']
]);
renderPageActions('<button class="btn btn-outline-secondary no-print" data-print>🖨 Print</button>');
renderFlashMessages();
?>

<div class="tab-group">
<div class="tab-nav">
  <button class="tab-link active" data-tab="auditTab">System Audit Log</button>
  <button class="tab-link" data-tab="loginTab">Login / Logout Log</button>
</div>
</div>

<div id="auditTab" class="tab-panel active">
  <form method="GET">
  <div class="filter-row">
    <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" value="<?=htmlspecialchars($search)?>" placeholder="Username / Reference..."></div>
    <div class="form-group"><label>Module</label>
      <select name="module" class="form-select"><option value="">All Modules</option>
        <?php foreach ($modules as $m): ?><option value="<?=$m?>" <?=$module===$m?'selected':''?>><?=ucfirst($m)?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Action</label>
      <select name="action_type" class="form-select"><option value="">All Actions</option>
        <?php foreach ($actions as $a): ?><option value="<?=$a?>" <?=$action===$a?'selected':''?>><?=$a?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>From</label><input type="date" name="date_from" class="form-control" value="<?=$dateFrom?>"></div>
    <div class="form-group"><label>To</label><input type="date" name="date_to" class="form-control" value="<?=$dateTo?>"></div>
    <div class="form-group"><label>&nbsp;</label><div style="display:flex;gap:.4rem;"><button type="submit" class="btn btn-primary">Filter</button><a href="index.php" class="btn btn-outline-secondary">Reset</a></div></div>
  </div>
  </form>

  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◮</span> Audit Log (<?=number_format($total)?> entries)</h5></div>
    <div class="card-body p-0">
      <?php if ($logs): ?>
      <div class="table-wrapper">
        <table class="tcms-table table-sm">
          <thead><tr><th>Date / Time</th><th>User</th><th>Action</th><th>Module</th><th>Record Type</th><th>Reference</th><th>IP Address</th></tr></thead>
          <tbody>
          <?php foreach ($logs as $l): ?>
          <tr>
            <td class="fs-xs"><?=formatDateTime($l['created_at'])?></td>
            <td class="fs-sm">
              <div class="fw-600"><?=htmlspecialchars($l['full_name']??$l['username']??'System')?></div>
              <div class="fs-xs text-muted"><?=htmlspecialchars($l['username']??'')?></div>
            </td>
            <td>
              <?php
              $actionColors = ['CREATE'=>'badge-success','UPDATE'=>'badge-info','DELETE'=>'badge-danger','VOID'=>'badge-danger','LOGIN'=>'badge-primary','LOGOUT'=>'badge-secondary','APPROVE'=>'badge-success','REJECT'=>'badge-danger','SUBMIT'=>'badge-warning','UPLOAD'=>'badge-info'];
              $ac = $actionColors[$l['action']] ?? 'badge-secondary';
              ?>
              <span class="badge <?=$ac?>"><?=htmlspecialchars($l['action'])?></span>
            </td>
            <td class="fs-sm"><?=htmlspecialchars($l['module']??'—')?></td>
            <td class="fs-sm"><?=htmlspecialchars($l['record_type']??'—')?></td>
            <td class="fw-600 fs-sm text-primary"><?=htmlspecialchars($l['record_reference']??'—')?></td>
            <td class="fs-xs text-muted"><?=htmlspecialchars($l['ip_address']??'—')?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state"><div class="empty-icon">◮</div><h5>No audit entries found</h5></div><?php endif; ?>
    </div>
    <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?=renderPagination($pg,'?search='.urlencode($search).'&module='.urlencode($module).'&action_type='.urlencode($action).'&date_from='.$dateFrom.'&date_to='.$dateTo)?></div><?php endif; ?>
  </div>
</div>

<div id="loginTab" class="tab-panel">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◭</span> Login / Logout History (Last 50)</h5></div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table table-sm">
          <thead><tr><th>Date / Time</th><th>User</th><th>Action</th><th>IP Address</th></tr></thead>
          <tbody>
          <?php foreach ($loginLogs as $l): ?>
          <tr>
            <td class="fs-xs"><?=formatDateTime($l['created_at'])?></td>
            <td>
              <div class="fw-600"><?=htmlspecialchars($l['full_name']??$l['username']??'—')?></div>
              <div class="fs-xs text-muted"><?=htmlspecialchars($l['username']??'')?></div>
            </td>
            <td><?php
              $lmap = ['login'=>'badge-success','logout'=>'badge-secondary','failed'=>'badge-danger','locked'=>'badge-warning'];
              echo '<span class="badge '.($lmap[$l['action']]??'badge-secondary').'">'.strtoupper($l['action']).'</span>';
            ?></td>
            <td class="fs-xs text-muted"><?=htmlspecialchars($l['ip_address']??'—')?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
