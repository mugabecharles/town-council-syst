<?php
// TCMS DB Test + Password Reset — DELETE after use
require_once __DIR__ . '/config/database.php';

echo "<pre>\n";
echo "APP_ENV : " . APP_ENV . "\n";
echo "DB_HOST : " . DB_HOST . "\n";
echo "DB_NAME : " . DB_NAME . "\n";
echo "DB_USER : " . DB_USER . "\n";
echo "DB_PORT : " . env('DB_PORT','3306') . "\n";
echo "DB_SSL  : " . env('DB_SSL','false') . "\n";
echo "\nConnecting...\n";

try {
    $port = env('DB_PORT', '3306');
    $ssl  = env('DB_SSL','false') === 'true';
    $dsn  = 'mysql:host='.DB_HOST.';port='.$port.';dbname='.DB_NAME.';charset=utf8mb4';
    $opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
    if ($ssl) $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    echo "SUCCESS — MySQL: " . $pdo->query("SELECT VERSION()")->fetchColumn() . "\n";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables (" . count($tables) . "): " . implode(', ', array_slice($tables,0,8)) . "\n";

    // ── RESET ADMIN PASSWORD ──────────────────────────────────────
    echo "\nResetting admin password...\n";
    $newHash = password_hash('Admin@2026', PASSWORD_BCRYPT, ['cost'=>12]);
    $stmt = $pdo->prepare("UPDATE users SET password_hash=?, login_attempts=0, locked_until=NULL WHERE username='admin'");
    $stmt->execute([$newHash]);
    echo "Rows updated: " . $stmt->rowCount() . "\n";

    // Verify hash works
    echo "Verify hash: " . (password_verify('Admin@2026', $newHash) ? 'PASS' : 'FAIL') . "\n";

    // Show user record
    $u = $pdo->query("SELECT username, email, is_active, login_attempts, role_id FROM users WHERE username='admin'")->fetch();
    echo "User: " . json_encode($u) . "\n";

    echo "\nPassword reset complete.\n";
    echo "Login with: admin / Admin@2026\n";

} catch (Exception $e) {
    echo "FAILED:\n" . $e->getMessage() . "\n";
}
echo "</pre>\n";
echo '<p><a href="/modules/auth/login.php" style="font-size:1.2rem;font-weight:bold;">Go to Login Page</a></p>';
