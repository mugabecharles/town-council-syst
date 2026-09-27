<?php
// TCMS DB Connection Test — DELETE after debugging
require_once __DIR__ . '/config/database.php';
echo "<pre>\n";
echo "APP_ENV : " . APP_ENV . "\n";
echo "DB_HOST : " . DB_HOST . "\n";
echo "DB_NAME : " . DB_NAME . "\n";
echo "DB_USER : " . DB_USER . "\n";
echo "DB_PORT : " . env('DB_PORT','3306') . "\n";
echo "DB_SSL  : " . env('DB_SSL','false') . "\n";
echo "\nConnecting...\n";

// Override to show real error
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
} catch (Exception $e) {
    echo "FAILED:\n" . $e->getMessage() . "\n";
}
echo "</pre>";
