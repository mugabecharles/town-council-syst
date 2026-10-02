<?php
/**
 * TCMS Session & Login Management
 * View active sessions, login history, force-logout users.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
if (!hasRole(['admin','town_clerk'])) {
    setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit;
}
$user = getCurrentUser();
$db   = getDB();

// ── POST: Force logout ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'force_logout' && hasRole(['admin'])) {
        $uid = (int)$_POST['user_id'];
        if ($uid === $user['id']) { setFlash('danger','You cannot force-logout yourself.'); header('Location: sessions.php'); exit; }
        // Log it
        $db->prepare("INSERT INTO login_logs (user_id,username,action,ip_address) SELECT id,username,'locked',? FROM users WHERE id=?")
           ->execute([getClientIP(),$uid]);
        // Lock account briefly
        $db->prepare("UPDATE users SET locked_until=DATE_ADD(NOW(),INTERVAL 1 HOUR) WHERE id=?")->execute([$uid]);
        logAudit('FORCE_LOGOUT','admin','user',$uid,'');
        setFlash('success','User session terminated. Their account is locked for 1 hour.');
    } elseif ($action === 'unlock_user' && hasRole(['admin'])) {
        $uid = (int)$_POST['user_id'];
        $db->prepare("UPDATE users SET locked_until=NULL,login_attempts=0 WHERE id=?")->execute([$uid]);
        logAudit('UNLOCK_USER','admin','user',$uid,'');
        setFlash('success','User account unlocked.');
    } elseif ($action === 'clear_locks' && hasRole(['admin'])) {
        $db->exec("UPDATE users SET locked_until=NULL,login_attempts=0 WHERE locked_until IS NOT NULL AND locked_until < NOW()");
        setFlash('success','Expired locks cleared.');
    }
    header('Location: sessions.php'); exit;
}

// ── Data ──────────────────────────────────────────────────────────
// Recent logins
$recentLogins = $db->query("SELECT ll.*,u.full_name,r.name AS role_name FROM login_logs ll
    LEFT JOIN users u ON ll.user_id=u.id LEFT JOIN roles r ON u.role_id=r.id
    ORDER BY ll.created_at DESC LIMIT 100")->fetchAll();

// Currently locked accounts
$locked = $db->query("SELECT u.*,r.name AS role_name FROM users u JOIN roles r ON u.role_id=r.id
    WHERE u.locked_until IS NOT NULL AND u.locked_until > NOW() ORDER BY u.locked_until")->fetchAll();

// Failed login attempts (last 24h)
$failedAttempts = $db->query("SELECT username,ip_address,COUNT(*) cnt,MAX(created_at) last_attempt
    FROM login_logs WHERE action='failed' AND created_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)
    GROUP BY username,ip_address ORDER BY cnt DESC LIMIT 20")->fetchAll();

// All users with last login
$allUsers = $db->query("SELECT u.*,r.name AS role_name,d.name AS dept_name FROM users u
    JOIN roles r ON u.role_id=r.id LEFT JOIN departments d ON u.department_id=d.id
    ORDER BY u.last_login DESC LIMIT 50")->fetchAll();

// Stats
$totalLogins24h = (int)$db->query("SELECT COUNT(*) FROM login_logs WHERE action='login' AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
$totalFailed24h = (int)$db->query("SELECT COUNT(*) FROM login_logs WHERE action='failed' AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();
$lockedCount    = count($locked);
$uniqueUsers24h = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM login_logs WHERE action='login' AND created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR)")->fetchColumn();

renderHead('Session Management');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Session & Login Management','Monitor logins, active accounts and security events');
renderPageStart('Session & Login Management','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Sessions'],
]);
renderPageActions(hasRole(['admin'])?'
  <form method="POST" style="display:inline;">'.csrfField().'
    <input type="hidden" name="action" value="clear_locks">
    <button type="submit" class="btn btn-outline-secondary">Clear Expired Locks</button>
  </form>':'');
renderFlashMessages();
?>

<div class="grid-4" style="margin-bottom:1.2rem;">
  <div class="stat-card green"><div class="stat-icon">✓</div><div class="stat-info"><div class="label">Logins (24h)</div><div class="value"><?=$totalLogins24h?></div><div class="sub"><?=$uniqueUsers24h?> unique users</div></div></div>
  <div class="stat-card red"><div class="stat-icon">✕</div><div class="stat-info"><div class="label">Failed Attempts (24h)</div><div class="value"><?=$totalFailed24h?></div></div></div>
  <div class="stat-card <?=$lockedCount>0?'amber':''?>"><div class="stat-icon">🔒</div><div class="stat-info"><div class="label">Locked Accounts</div><div class="value"><?=$lockedCount?></div></div></div>
  <div class="stat-card blue"><div class="stat-icon">👥</div><div class="stat-info"><div class="label">Total Users</div><div class="value"><?=count($allUsers)?></div></div></div>
</div>

<div class="tab-group">
  <div class="tab-nav">
    <button class="tab-link active" data-tab="loginTab">Login History</button>
    <button class="tab-link" data-tab="usersTab">User Accounts</button>
    <?php if($lockedCount>0): ?><button class="tab-link" data-tab="lockedTab">Locked (<?=$lockedCount?>)</button><?php endif; ?>
    <button class="tab-link" data-tab="failedTab">Failed Attempts</button>
  </div>
</div>

<!-- Login History -->
<div id="loginTab" class="tab-panel active">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◮</span> Login / Logout History (Last 100)</h5></div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table table-sm">
          <thead><tr><th>Date / Time</th><th>User</th><th>Role</th><th>Action</th><th>IP Address</th></tr></thead>
          <tbody>
          <?php foreach($recentLogins as $l): ?>
          <tr>
            <td class="fs-xs"><?=formatDateTime($l['created_at'])?></td>
            <td><div class="fw-600 fs-sm"><?=htmlspecialchars($l['full_name']??$l['username']??'Unknown')?></div><div class="fs-xs text-muted"><?=htmlspecialchars($l['username']??'')?></div></td>
            <td class="fs-xs"><?=htmlspecialchars($l['role_name']??'—')?></td>
            <td>
              <?php $ac=['login'=>'badge-success','logout'=>'badge-secondary','failed'=>'badge-danger','locked'=>'badge-warning'];?>
              <span class="badge <?=$ac[$l['action']]??'badge-secondary'?>"><?=strtoupper($l['action'])?></span>
            </td>
            <td class="fs-xs text-muted"><?=htmlspecialchars($l['ip_address']??'—')?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- All Users -->
<div id="usersTab" class="tab-panel">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◭</span> User Accounts & Last Login</h5></div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>User</th><th>Role</th><th>Department</th><th>Last Login</th><th>Attempts</th><th>Status</th><?php if(hasRole(['admin'])): ?><th>Actions</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach($allUsers as $u): ?>
          <tr>
            <td><div class="fw-600"><?=htmlspecialchars($u['full_name'])?></div><div class="fs-xs text-muted"><?=htmlspecialchars($u['username'])?></div></td>
            <td><span class="badge badge-info"><?=htmlspecialchars($u['role_name'])?></span></td>
            <td class="fs-sm"><?=htmlspecialchars($u['dept_name']??'—')?></td>
            <td class="fs-sm"><?=$u['last_login']?formatDateTime($u['last_login']):'<span class="text-muted">Never</span>'?></td>
            <td class="text-center <?=$u['login_attempts']>=3?'text-danger fw-700':''?>"><?=$u['login_attempts']?></td>
            <td><?=getStatusBadge($u['is_active']?'active':'inactive')?><?php if($u['locked_until']&&strtotime($u['locked_until'])>time()): ?><br><span class="badge badge-warning fs-xs">Locked until <?=date('H:i',strtotime($u['locked_until']))?></span><?php endif; ?></td>
            <?php if(hasRole(['admin'])): ?>
            <td>
              <?php if($u['id']!==$user['id']): ?>
              <div style="display:flex;gap:.3rem;flex-wrap:wrap;">
                <form method="POST" style="display:inline;">
                  <?=csrfField()?><input type="hidden" name="action" value="force_logout"><input type="hidden" name="user_id" value="<?=$u['id']?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Terminate session and lock this user?">Lock</button>
                </form>
                <?php if($u['login_attempts']>0||($u['locked_until']&&strtotime($u['locked_until'])>time())): ?>
                <form method="POST" style="display:inline;">
                  <?=csrfField()?><input type="hidden" name="action" value="unlock_user"><input type="hidden" name="user_id" value="<?=$u['id']?>">
                  <button type="submit" class="btn btn-sm btn-outline-success">Unlock</button>
                </form>
                <?php endif; ?>
              </div>
              <?php else: ?><span class="fs-xs text-muted">You</span><?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Locked Accounts -->
<?php if($lockedCount>0): ?>
<div id="lockedTab" class="tab-panel">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">🔒</span> Currently Locked Accounts</h5></div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>User</th><th>Role</th><th>Locked Until</th><th>Attempts</th><?php if(hasRole(['admin'])): ?><th>Action</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach($locked as $lu): ?>
          <tr style="background:rgba(189,33,48,.03);">
            <td><div class="fw-600"><?=htmlspecialchars($lu['full_name'])?></div><div class="fs-xs text-muted"><?=htmlspecialchars($lu['username'])?></div></td>
            <td><span class="badge badge-danger"><?=htmlspecialchars($lu['role_name'])?></span></td>
            <td class="fw-700 text-danger"><?=formatDateTime($lu['locked_until'])?></td>
            <td class="text-center fw-700 text-danger"><?=$lu['login_attempts']?></td>
            <?php if(hasRole(['admin'])): ?>
            <td>
              <form method="POST" style="display:inline;">
                <?=csrfField()?><input type="hidden" name="action" value="unlock_user"><input type="hidden" name="user_id" value="<?=$lu['id']?>">
                <button type="submit" class="btn btn-sm btn-success">Unlock Now</button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Failed Attempts -->
<div id="failedTab" class="tab-panel">
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">✕</span> Failed Login Attempts — Last 24 Hours</h5></div>
    <div class="card-body p-0">
      <?php if($failedAttempts): ?>
      <div class="table-wrapper">
        <table class="tcms-table">
          <thead><tr><th>Username Attempted</th><th>IP Address</th><th class="text-right">Attempts</th><th>Last Attempt</th></tr></thead>
          <tbody>
          <?php foreach($failedAttempts as $f): ?>
          <tr style="<?=$f['cnt']>=5?'background:rgba(189,33,48,.04);':''?>">
            <td class="fw-600"><?=htmlspecialchars($f['username']??'—')?></td>
            <td class="fs-sm"><?=htmlspecialchars($f['ip_address']??'—')?></td>
            <td class="text-right fw-700 <?=$f['cnt']>=5?'text-danger':''?>"><?=$f['cnt']?><?=$f['cnt']>=5?' ⚠':''?></td>
            <td class="fs-sm"><?=formatDateTime($f['last_attempt'])?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?><div class="empty-state" style="padding:1.5rem;"><div class="empty-icon">✅</div><p>No failed attempts in the last 24 hours.</p></div><?php endif; ?>
    </div>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
