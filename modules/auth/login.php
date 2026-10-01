<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/twofa.php';
startSecureSession();

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/dashboard.php'); exit;
}

$error   = '';
$success = '';
$mode    = 'login'; // login | otp

// ── OTP Verification step ─────────────────────────────────────────
if (isset($_SESSION['pending_2fa_user'])) {
    $mode = 'otp';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_otp') {
        verifyCsrf();
        $otp    = trim($_POST['otp'] ?? '');
        $userId = (int)$_SESSION['pending_2fa_user'];

        $result = verify2FAToken($userId, $otp, 'login');
        if ($result['valid']) {
            // Complete login
            $db   = getDB();
            $user = $db->prepare("SELECT * FROM users WHERE id=?")->execute([$userId]) ?
                    $db->query("SELECT * FROM users WHERE id=$userId")->fetch() : null;

            $stmt = $db->prepare("SELECT * FROM users WHERE id=?");
            $stmt->execute([$userId]); $user = $stmt->fetch();

            session_regenerate_id(true);
            $_SESSION['user_id']       = $userId;
            $_SESSION['last_activity'] = time();
            unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_email']);

            $db->prepare("UPDATE users SET login_attempts=0, locked_until=NULL, last_login=NOW() WHERE id=?")
               ->execute([$userId]);
            $db->prepare("INSERT INTO login_logs (user_id, username, action, ip_address, user_agent) VALUES (?,?,?,?,?)")
               ->execute([$userId, $user['username'] ?? '', 'login', getClientIP(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
            logAudit('LOGIN_2FA', 'auth', 'user', $userId, $user['username'] ?? '');

            $redirect = $_SESSION['pending_2fa_redirect'] ?? APP_URL . '/dashboard.php';
            unset($_SESSION['pending_2fa_redirect']);
            header('Location: ' . $redirect); exit;
        } else {
            $error = $result['error'];
        }
    }

    // Resend OTP
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_otp') {
        verifyCsrf();
        $userId = (int)$_SESSION['pending_2fa_user'];
        $db     = getDB();
        $stmt   = $db->prepare("SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON u.role_id=r.id WHERE u.id=?");
        $stmt->execute([$userId]); $user = $stmt->fetch();
        if ($user && $user['email']) {
            $newOtp = generate2FAToken($userId, 'login');
            send2FAEmail($user, $newOtp);
            $success = 'A new verification code has been sent to your email.';
        }
    }
}

// ── Password login step ────────────────────────────────────────────
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip       = getClientIP();

    if (empty($username) || empty($password)) {
        $error = 'Please enter your username and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT u.*, r.slug AS role_slug, r.name AS role_name FROM users u JOIN roles r ON u.role_id=r.id WHERE (u.username=? OR u.email=?)");
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
            $error = "Account locked. Try again after {$until}.";
        } elseif (!verifyPassword($password, $user['password_hash'])) {
            $attempts    = $user['login_attempts'] + 1;
            $maxAttempts = (int)(getSystemSetting('max_login_attempts') ?? 5);
            $lockedUntil = $attempts >= $maxAttempts ? date('Y-m-d H:i:s', time() + 900) : null;
            $db->prepare("UPDATE users SET login_attempts=?, locked_until=? WHERE id=?")->execute([$attempts, $lockedUntil, $user['id']]);
            $db->prepare("INSERT INTO login_logs (user_id, username, action, ip_address, user_agent) VALUES (?,?,?,?,?)")
               ->execute([$user['id'], $user['username'], 'failed', $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
            $remaining = max(0, $maxAttempts - $attempts);
            $error = "Invalid password. {$remaining} attempt(s) remaining before lockout.";
        } else {
            // Credentials valid — check if 2FA required
            if (requires2FA($user) && !empty($user['email'])) {
                // Store pending state and send OTP
                $otp = generate2FAToken($user['id'], 'login');
                send2FAEmail($user, $otp);

                $_SESSION['pending_2fa_user']     = $user['id'];
                $_SESSION['pending_2fa_email']    = maskEmail($user['email']);
                $_SESSION['pending_2fa_redirect'] = $_GET['redirect'] ?? APP_URL . '/dashboard.php';

                header('Location: login.php'); exit;
            }

            // Direct login (no 2FA)
            session_regenerate_id(true);
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['last_activity'] = time();
            $db->prepare("UPDATE users SET login_attempts=0, locked_until=NULL, last_login=NOW() WHERE id=?")->execute([$user['id']]);
            $db->prepare("INSERT INTO login_logs (user_id, username, action, ip_address, user_agent) VALUES (?,?,?,?,?)")
               ->execute([$user['id'], $user['username'], 'login', $ip, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
            logAudit('LOGIN', 'auth', 'user', $user['id'], $user['username']);
            $redirect = $_GET['redirect'] ?? APP_URL . '/dashboard.php';
            header('Location: ' . $redirect); exit;
        }
    }
}

function maskEmail(string $email): string {
    $parts = explode('@', $email);
    $local = $parts[0];
    $domain= $parts[1] ?? '';
    $masked = substr($local, 0, 2) . str_repeat('*', max(2, strlen($local) - 4)) . substr($local, -2);
    return $masked . '@' . $domain;
}

$councilName     = getSystemSetting('council_name') ?? 'Kijura Town Council';
$maskedEmail     = $_SESSION['pending_2fa_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $mode === 'otp' ? 'Verify Code' : 'Sign In' ?> — <?= htmlspecialchars($councilName) ?></title>
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

        <?php if ($mode === 'otp'): ?>
        <!-- ── OTP VERIFICATION FORM ────────────────────────────── -->
        <div style="text-align:center;margin-bottom:1.5rem;">
          <div style="width:56px;height:56px;background:#f4f6f9;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .8rem;font-size:1.5rem;">🔐</div>
          <p style="font-size:.95rem;font-weight:700;color:#1a3a5c;">Two-Step Verification</p>
          <p style="font-size:.82rem;color:#6c757d;margin-top:.3rem;">
            A 6-digit code was sent to<br>
            <strong style="color:#1a3a5c;"><?= htmlspecialchars($maskedEmail) ?></strong>
          </p>
        </div>

        <form method="POST" autocomplete="off" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="action" value="verify_otp">
          <div class="form-group">
            <label class="form-label" for="otp">Verification Code</label>
            <input type="text" id="otp" name="otp" class="form-control"
                   inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                   placeholder="Enter 6-digit code" required autofocus
                   style="font-size:1.4rem;letter-spacing:8px;text-align:center;font-weight:700;">
            <div class="form-text" style="text-align:center;">Code expires in <?= getSystemSetting('otp_expiry_minutes') ?? '10' ?> minutes.</div>
          </div>
          <button type="submit" class="btn btn-primary btn-block btn-lg">Verify &amp; Sign In</button>
        </form>

        <div style="margin-top:1.2rem;text-align:center;">
          <form method="POST" style="display:inline;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="resend_otp">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Resend Code</button>
          </form>
          <span style="margin:0 .5rem;color:#ccc;">|</span>
          <a href="login.php?cancel=1" onclick="<?php
            echo "fetch('" . APP_URL . "/api/notifications.php?action=cancel_2fa',{method:'POST'});";
          ?>" class="btn btn-outline-secondary btn-sm"
            onclick="document.cookie='pending_2fa=;expires=Thu, 01 Jan 1970 00:00:00 GMT';">
            Use different account
          </a>
        </div>

        <?php else: ?>
        <!-- ── PASSWORD LOGIN FORM ───────────────────────────────── -->
        <div style="margin-bottom:1.5rem;">
          <p style="font-size:.9rem;font-weight:700;color:#1a3a5c;margin-bottom:.2rem;">Sign In to Your Account</p>
          <p style="font-size:.8rem;color:#6c757d;">Enter your credentials to access the system.</p>
        </div>

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
          </div>
          <button type="submit" class="btn btn-primary btn-block btn-lg">Sign In</button>
        </form>
        <?php endif; ?>
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
  var p = document.getElementById('password');
  var b = document.getElementById('togglePassBtn');
  p.type = p.type === 'password' ? 'text' : 'password';
  b.textContent = p.type === 'password' ? 'Show' : 'Hide';
}
// Cancel 2FA session if user navigates away
<?php if (isset($_GET['cancel'])): ?>
<?php startSecureSession(); unset($_SESSION['pending_2fa_user'],$_SESSION['pending_2fa_email'],$_SESSION['pending_2fa_redirect']); ?>
<?php endif; ?>
// Auto-format OTP input
var otpInput = document.getElementById('otp');
if (otpInput) {
  otpInput.addEventListener('input', function() {
    this.value = this.value.replace(/[^0-9]/g, '').substring(0, 6);
    if (this.value.length === 6) this.closest('form').querySelector('[type=submit]').focus();
  });
}
</script>
</body>
</html>
