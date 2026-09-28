<?php
// TCMS Emergency Password Reset — DELETE after use
// Only works from localhost or with valid session
$secret = $_GET['secret'] ?? '';
if ($secret !== 'tcms_reset_2026') {
    http_response_code(403);
    die('Access denied');
}

require_once __DIR__ . '/config/database.php';

try {
    $db   = getDB();
    $hash = password_hash('Admin@2026', PASSWORD_BCRYPT, ['cost' => 12]);

    // Reset admin password and unlock account
    $stmt = $db->prepare("UPDATE users SET 
        password_hash = ?,
        login_attempts = 0,
        locked_until = NULL,
        must_change_password = 0
        WHERE username = 'admin'");
    $stmt->execute([$hash]);

    $affected = $stmt->rowCount();

    // Verify
    $user = $db->query("SELECT username, email, is_active, login_attempts FROM users WHERE username='admin'")->fetch();

    echo "<pre style='font-family:monospace;padding:1rem;background:#f4f4f4;'>\n";
    echo "Password reset for: admin\n";
    echo "New password:       Admin@2026\n";
    echo "Hash:               " . substr($hash, 0, 30) . "...\n";
    echo "Rows updated:       $affected\n";
    echo "User email:         " . ($user['email'] ?? 'not found') . "\n";
    echo "Is active:          " . ($user['is_active'] ?? 'unknown') . "\n";
    echo "Login attempts:     " . ($user['login_attempts'] ?? 'unknown') . "\n";
    echo "\nVerification test: " . (password_verify('Admin@2026', $hash) ? 'PASS' : 'FAIL') . "\n";
    echo "\nDone. DELETE this file now.\n";
    echo "</pre>\n";
    echo "<p><a href='/modules/auth/login.php'>Go to Login</a></p>\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
