<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $db = getDB();

    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone     = trim($_POST['phone'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        if ($full_name && $email) {
            $db->prepare("UPDATE users SET full_name=?, phone=?, email=? WHERE id=?")
               ->execute([$full_name, $phone, $email, $user['id']]);
            logAudit('UPDATE_PROFILE', 'auth', 'user', $user['id'], $user['username']);
            setFlash('success', 'Profile updated successfully.');
        }
    } elseif ($action === 'change_password') {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        if (!verifyPassword($current, $user['password_hash'])) {
            setFlash('danger', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            setFlash('danger', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            setFlash('danger', 'Passwords do not match.');
        } else {
            $hash = hashPassword($new);
            $db->prepare("UPDATE users SET password_hash=?, must_change_password=0 WHERE id=?")->execute([$hash, $user['id']]);
            logAudit('CHANGE_PASSWORD', 'auth', 'user', $user['id'], $user['username']);
            setFlash('success', 'Password changed successfully.');
        }
    }
    header('Location: profile.php'); exit;
}

// Reload user
$user = getCurrentUser();

renderHead('My Profile');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('My Profile', $user['full_name']);
renderPageStart('My Profile', '', [
    ['url' => APP_URL . '/dashboard.php', 'label' => 'Dashboard'],
    ['url' => '#', 'label' => 'My Profile']
]);
renderPageActions('');
renderFlashMessages();
?>

<div class="grid-2" style="align-items:start;">

  <!-- Profile Info -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◉</span> Profile Information</h5></div>
    <div class="card-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_profile">
        <div class="form-group">
          <label class="form-label">Full Name <span class="req">*</span></label>
          <input type="text" name="full_name" class="form-control" value="<?= htmlspecialchars($user['full_name']) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Email <span class="req">*</span></label>
          <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Username</label>
          <input type="text" class="form-control" value="<?= htmlspecialchars($user['username']) ?>" disabled>
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <input type="text" class="form-control" value="<?= htmlspecialchars($user['role_name']) ?>" disabled>
        </div>
        <div class="form-group">
          <label class="form-label">Department</label>
          <input type="text" class="form-control" value="<?= htmlspecialchars($user['dept_name'] ?? 'N/A') ?>" disabled>
        </div>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </form>
    </div>
  </div>

  <!-- Change Password -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">🔒</span> Change Password</h5></div>
    <div class="card-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-group">
          <label class="form-label">Current Password <span class="req">*</span></label>
          <input type="password" name="current_password" class="form-control" required>
        </div>
        <div class="form-group">
          <label class="form-label">New Password <span class="req">*</span></label>
          <input type="password" name="new_password" class="form-control" required minlength="8">
          <div class="form-text">Minimum 8 characters.</div>
        </div>
        <div class="form-group">
          <label class="form-label">Confirm New Password <span class="req">*</span></label>
          <input type="password" name="confirm_password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-warning">Change Password</button>
      </form>
    </div>
  </div>

</div>

<?php
renderPageEnd();
echo '</div></div>';
renderFooter();
?>
