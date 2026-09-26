<?php
require_once __DIR__ . '/../../includes/functions.php';
startSecureSession();

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/dashboard.php'); exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip       = getClientIP();

    if (empty($username) || empty($password)) {
        $error = 'Please enter your username and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON u.role_id = r.id WHERE (u.username = ? OR u.email = ?)");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if (!$user) {
            $error = 'Invalid username or password.';
            $db->prepare("INSERT INTO login_logs (username, action, ip_address, user_agent) VALUES (?,?,?,?)")
               ->execute([$username, 'failed', $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
        } elseif (!$user['is_active']) {
            $error = 'Your account has been deactivated. Please contact the administrator.';
        } elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $until = date('H:i', strtotime($user['locked_until']));
            $error = "Account locked due to too many failed attempts. Try again after {$until}.";
        } elseif (!verifyPassword($password, $user['password_hash'])) {
            $attempts = $user['login_attempts'] + 1;
            $maxAttempts = (int)(getSystemSetting('max_login_attempts') ?? 5);
            $lockedUntil = null;
            if ($attempts >= $maxAttempts) {
                $lockedUntil = date('Y-m-d H:i:s', time() + 900); // 15 min
            }
            $db->prepare("UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?")
               ->execute([$attempts, $lockedUntil, $user['id']]);
            $db->prepare("INSERT INTO login_logs (user_id, username, action, ip_address, user_agent) VALUES (?,?,?,?,?)")
               ->execute([$user['id'], $user['username'], 'failed', $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
            $remaining = max(0, $maxAttempts - $attempts);
            $error = "Invalid password. {$remaining} attempt(s) remaining before account lockout.";
        } else {
            // Successful login
            session_regenerate_id(true);
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['last_activity'] = time();

            $db->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")
               ->execute([$user['id']]);
            $db->prepare("INSERT INTO login_logs (user_id, username, action, ip_address, user_agent) VALUES (?,?,?,?,?)")
               ->execute([$user['id'], $user['username'], 'login', $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
            logAudit('LOGIN', 'auth', 'user', $user['id'], $user['username']);

            $redirect = $_GET['redirect'] ?? APP_URL . '/dashboard.php';
            header('Location: ' . $redirect); exit;
        }
    }
}

$councilName = getSystemSetting('council_name') ?? 'Town Council';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — <?= htmlspecialchars($councilName) ?> Management System</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/tcms.css">
</head>
<body>
<div class="login-page">
  <div style="width:100%;max-width:480px;">

    <div class="login-card">
      <div class="login-header">
        <div class="council-emblem">TC</div>
        <h1><?= htmlspecialchars($councilName) ?></h1>
        <p>Revenue, Finance &amp; Administration Management System</p>
      </div>

      <div class="login-body">
        <div style="margin-bottom:1.5rem;">
          <p style="font-size:.9rem;font-weight:700;color:#1a3a5c;margin-bottom:.2rem;">Sign In to Your Account</p>
          <p style="font-size:.8rem;color:#6c757d;">Enter your credentials to access the system.</p>
        </div>

        <?php if ($error): ?>
          <div class="alert alert-danger" data-auto-dismiss="8000">
            <span>⚠</span><span><?= htmlspecialchars($error) ?></span>
            <button class="alert-close">✕</button>
          </div>
        <?php endif; ?>
        <?php if ($success): ?>
          <div class="alert alert-success">
            <span>✓</span><span><?= htmlspecialchars($success) ?></span>
          </div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off" novalidate>
          <?= csrfField() ?>
          <div class="form-group">
            <label class="form-label" for="username">Username or Email</label>
            <input type="text" id="username" name="username" class="form-control"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              placeholder="Enter your username" required autofocus autocomplete="username">
          </div>
          <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <div style="position:relative;">
              <input type="password" id="password" name="password" class="form-control"
                placeholder="Enter your password" required autocomplete="current-password">
              <button type="button" onclick="togglePass()" tabindex="-1"
                style="position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#6c757d;font-size:.85rem;" id="togglePassBtn">Show</button>
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;">
            <label style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;cursor:pointer;">
              <input type="checkbox" name="remember"> Remember me
            </label>
            <a href="forgot_password.php" style="font-size:.82rem;color:#1a3a5c;">Forgot Password?</a>
          </div>
          <button type="submit" class="btn btn-primary btn-block btn-lg">Sign In</button>
        </form>
      </div>

      <div class="login-footer">
        <strong><?= htmlspecialchars($councilName) ?></strong> Management System &mdash; v1.0<br>
        Authorized users only. All activity is logged and monitored.
      </div>
    </div>

    <p style="text-align:center;margin-top:1.2rem;font-size:.78rem;color:rgba(255,255,255,.5);">
      &copy; <?= date('Y') ?> <?= htmlspecialchars($councilName) ?>. All rights reserved.
    </p>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/js/tcms.js"></script>
<script>
function togglePass() {
  const p = document.getElementById('password');
  const b = document.getElementById('togglePassBtn');
  if (p.type === 'password') { p.type = 'text'; b.textContent = 'Hide'; }
  else { p.type = 'password'; b.textContent = 'Show'; }
}
</script>
</body>
</html>
