<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
if (!hasRole(['admin','town_clerk'])) { setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit; }
$user = getCurrentUser();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $pwd = trim($_POST['password']);
        if (strlen($pwd) < 8) { setFlash('danger','Password must be at least 8 characters.'); header('Location: users.php'); exit; }
        try {
            $db->prepare("INSERT INTO users (employee_id,username,email,password_hash,full_name,phone,role_id,department_id,designation,is_active,must_change_password)
                VALUES (?,?,?,?,?,?,?,?,?,1,1)")
               ->execute([
                   trim($_POST['employee_id'] ?? ''), trim($_POST['username']), trim($_POST['email']),
                   hashPassword($pwd), trim($_POST['full_name']), trim($_POST['phone'] ?? ''),
                   (int)$_POST['role_id'],
                   !empty($_POST['department_id'])?(int)$_POST['department_id']:null,
                   trim($_POST['designation'] ?? '')
               ]);
            logAudit('CREATE','admin','user',(int)$db->lastInsertId(),$_POST['username']);
            setFlash('success','User created successfully. User must change password on first login.');
        } catch (PDOException $e) {
            setFlash('danger','Failed: '.(str_contains($e->getMessage(),'Duplicate')?'Username or email already exists.':$e->getMessage()));
        }
    } elseif ($action === 'toggle_user') {
        $uid = (int)$_POST['user_id'];
        if ($uid === $user['id']) { setFlash('danger','You cannot deactivate your own account.'); header('Location: users.php'); exit; }
        $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id=?")->execute([$uid]);
        logAudit('TOGGLE_ACTIVE','admin','user',$uid,'');
        setFlash('success','User status updated.');
    } elseif ($action === 'reset_password') {
        $uid = (int)$_POST['user_id'];
        $newpwd = trim($_POST['new_password']);
        if (strlen($newpwd)<8) { setFlash('danger','Password must be at least 8 characters.'); header('Location: users.php'); exit; }
        $db->prepare("UPDATE users SET password_hash=?, must_change_password=1, login_attempts=0, locked_until=NULL WHERE id=?")->execute([hashPassword($newpwd),$uid]);
        logAudit('RESET_PASSWORD','admin','user',$uid,'');
        setFlash('success','Password reset. User will be prompted to change on next login.');
    } elseif ($action === 'update_user') {
        $uid = (int)$_POST['user_id'];
        $db->prepare("UPDATE users SET full_name=?,email=?,phone=?,role_id=?,department_id=?,designation=? WHERE id=?")
           ->execute([
               trim($_POST['full_name']), trim($_POST['email']),
               trim($_POST['phone'] ?? ''), (int)$_POST['role_id'],
               !empty($_POST['department_id'])?(int)$_POST['department_id']:null,
               trim($_POST['designation'] ?? ''), $uid
           ]);
        logAudit('UPDATE','admin','user',$uid,'');
        setFlash('success','User updated.');
    }
    header('Location: users.php'); exit;
}

$search  = trim($_GET['search'] ?? '');
$roleFil = (int)($_GET['role_id'] ?? 0);
$page    = max(1,(int)($_GET['page'] ?? 1));

$where = ['1=1']; $params = [];
if ($search)  { $where[] = "(u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)"; $params=array_merge($params,["%$search%","%$search%","%$search%"]); }
if ($roleFil) { $where[] = "u.role_id=?"; $params[] = $roleFil; }
$wSQL = implode(' AND ',$where);

$cnt = $db->prepare("SELECT COUNT(*) FROM users u WHERE $wSQL"); $cnt->execute($params); $total=(int)$cnt->fetchColumn();
$pg  = paginate($total,$page);

$rows = $db->prepare("SELECT u.*, r.name AS role_name, d.name AS dept_name FROM users u JOIN roles r ON u.role_id=r.id LEFT JOIN departments d ON u.department_id=d.id WHERE $wSQL ORDER BY u.full_name LIMIT {$pg['per_page']} OFFSET {$pg['offset']}");
$rows->execute($params); $users = $rows->fetchAll();

$roles = $db->query("SELECT * FROM roles ORDER BY name")->fetchAll();
$depts = $db->query("SELECT * FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();

renderHead('User Management');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('User Management','Manage system users and access');
renderPageStart('User Management','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'Users']
]);
renderPageActions('<button class="btn btn-primary" data-modal="addUserModal">+ Add User</button>');
renderFlashMessages();
?>

<form method="GET">
<div class="filter-row">
  <div class="form-group"><label>Search</label><input type="text" name="search" class="form-control" value="<?=htmlspecialchars($search)?>" placeholder="Name, username, email..."></div>
  <div class="form-group"><label>Role</label>
    <select name="role_id" class="form-select"><option value="">All Roles</option>
      <?php foreach ($roles as $r): ?><option value="<?=$r['id']?>" <?=$roleFil==$r['id']?'selected':''?>><?=htmlspecialchars($r['name'])?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="form-group"><label>&nbsp;</label><div style="display:flex;gap:.4rem;"><button type="submit" class="btn btn-primary">Filter</button><a href="users.php" class="btn btn-outline-secondary">Reset</a></div></div>
</div>
</form>

<div class="card">
  <div class="card-header"><h5><span class="ch-icon">◭</span> System Users (<?=number_format($total)?>)</h5></div>
  <div class="card-body p-0">
    <?php if ($users): ?>
    <div class="table-wrapper">
      <table class="tcms-table">
        <thead><tr><th>#</th><th>Employee ID</th><th>Full Name</th><th>Username</th><th>Email</th><th>Role</th><th>Department</th><th>Last Login</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $i => $u): ?>
        <tr>
          <td class="fs-xs text-muted"><?=$pg['offset']+$i+1?></td>
          <td class="fs-sm"><?=htmlspecialchars($u['employee_id']??'—')?></td>
          <td>
            <div class="fw-700"><?=htmlspecialchars($u['full_name'])?></div>
            <div class="fs-xs text-muted"><?=htmlspecialchars($u['designation']??'')?></div>
          </td>
          <td class="fw-600 text-primary"><?=htmlspecialchars($u['username'])?></td>
          <td class="fs-sm"><?=htmlspecialchars($u['email'])?></td>
          <td><span class="badge badge-info"><?=htmlspecialchars($u['role_name'])?></span></td>
          <td class="fs-sm"><?=htmlspecialchars($u['dept_name']??'—')?></td>
          <td class="fs-xs"><?=$u['last_login']?formatDateTime($u['last_login']):'Never'?></td>
          <td><?=getStatusBadge($u['is_active']?'active':'inactive')?></td>
          <td>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap;">
              <button class="btn btn-sm btn-outline-primary" onclick="editUser(<?=htmlspecialchars(json_encode($u))?>)">Edit</button>
              <?php if ($u['id'] !== $user['id']): ?>
              <form method="POST" style="display:inline;">
                <?=csrfField()?>
                <input type="hidden" name="action" value="toggle_user">
                <input type="hidden" name="user_id" value="<?=$u['id']?>">
                <button type="submit" class="btn btn-sm btn-outline-<?=$u['is_active']?'danger':'success'?>" data-confirm="<?=$u['is_active']?'Deactivate':'Activate'?> this user?"><?=$u['is_active']?'Deactivate':'Activate'?></button>
              </form>
              <button class="btn btn-sm btn-outline-secondary" onclick="resetPwd(<?=$u['id']?>, '<?=htmlspecialchars($u['full_name'])?>')">Reset Pwd</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?><div class="empty-state"><div class="empty-icon">◭</div><h5>No users found</h5></div><?php endif; ?>
  </div>
  <?php if ($pg['total_pages']>1): ?><div class="card-footer"><?=renderPagination($pg,'?search='.urlencode($search).'&role_id='.$roleFil)?></div><?php endif; ?>
</div>

<!-- Add User Modal -->
<div class="modal-backdrop" id="addUserModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Add New User</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="add_user">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Employee ID</label><input type="text" name="employee_id" class="form-control"></div>
          <div class="form-group"><label class="form-label">Full Name <span class="req">*</span></label><input type="text" name="full_name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Username <span class="req">*</span></label><input type="text" name="username" class="form-control" required autocomplete="off"></div>
          <div class="form-group"><label class="form-label">Email <span class="req">*</span></label><input type="email" name="email" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control"></div>
          <div class="form-group"><label class="form-label">Designation</label><input type="text" name="designation" class="form-control"></div>
          <div class="form-group"><label class="form-label">Role <span class="req">*</span></label>
            <select name="role_id" class="form-select" required>
              <option value="">-- Select Role --</option>
              <?php foreach ($roles as $r): ?><option value="<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Department</label>
            <select name="department_id" class="form-select"><option value="">-- None --</option>
              <?php foreach ($depts as $d): ?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="grid-column:span 2;">
            <label class="form-label">Temporary Password <span class="req">*</span></label>
            <input type="password" name="password" class="form-control" required minlength="8" autocomplete="new-password">
            <div class="form-text">Minimum 8 characters. User will be required to change on first login.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Create User</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal-backdrop" id="editUserModal">
  <div class="modal-box modal-lg">
    <div class="modal-header"><h5>Edit User</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="update_user">
      <input type="hidden" name="user_id" id="eu_id">
      <div class="modal-body">
        <div class="grid-2">
          <div class="form-group"><label class="form-label">Full Name <span class="req">*</span></label><input type="text" name="full_name" id="eu_full_name" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Email <span class="req">*</span></label><input type="email" name="email" id="eu_email" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Phone</label><input type="text" name="phone" id="eu_phone" class="form-control"></div>
          <div class="form-group"><label class="form-label">Designation</label><input type="text" name="designation" id="eu_designation" class="form-control"></div>
          <div class="form-group"><label class="form-label">Role <span class="req">*</span></label>
            <select name="role_id" id="eu_role_id" class="form-select" required>
              <?php foreach ($roles as $r): ?><option value="<?=$r['id']?>"><?=htmlspecialchars($r['name'])?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Department</label>
            <select name="department_id" id="eu_dept_id" class="form-select"><option value="">-- None --</option>
              <?php foreach ($depts as $d): ?><option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-backdrop" id="resetPwdModal">
  <div class="modal-box">
    <div class="modal-header"><h5>Reset Password</h5><button class="modal-close" data-modal-close>✕</button></div>
    <form method="POST">
      <?=csrfField()?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="rp_uid">
      <div class="modal-body">
        <p class="fs-sm text-muted" style="margin-bottom:1rem;">Reset password for: <strong id="rp_name"></strong></p>
        <div class="form-group"><label class="form-label">New Password <span class="req">*</span></label><input type="password" name="new_password" class="form-control" required minlength="8"><div class="form-text">Minimum 8 characters. User will be required to change on next login.</div></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn-warning">Reset Password</button>
      </div>
    </form>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; ?>
<script>
function editUser(u) {
  document.getElementById('eu_id').value          = u.id;
  document.getElementById('eu_full_name').value   = u.full_name;
  document.getElementById('eu_email').value        = u.email;
  document.getElementById('eu_phone').value        = u.phone || '';
  document.getElementById('eu_designation').value  = u.designation || '';
  document.getElementById('eu_role_id').value      = u.role_id;
  document.getElementById('eu_dept_id').value      = u.department_id || '';
  openModal('editUserModal');
}
function resetPwd(id, name) {
  document.getElementById('rp_uid').value  = id;
  document.getElementById('rp_name').textContent = name;
  openModal('resetPwdModal');
}
</script>
<?php renderFooter(); ?>
