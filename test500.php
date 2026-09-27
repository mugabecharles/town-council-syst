<?php
// Step-by-step boot test — shows exactly where crash happens
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "Step 1: PHP OK — version " . PHP_VERSION . "<br>\n";

// Test env vars
echo "Step 2: ENV — DB_HOST=" . (getenv('DB_HOST') ?: 'NOT SET') . "<br>\n";
echo "        DB_PORT=" . (getenv('DB_PORT') ?: 'NOT SET') . "<br>\n";
echo "        DB_SSL="  . (getenv('DB_SSL')  ?: 'NOT SET') . "<br>\n";
echo "        APP_ENV=" . (getenv('APP_ENV') ?: 'NOT SET') . "<br>\n";

// Test config load
echo "Step 3: Loading config/database.php...<br>\n";
try {
    require_once __DIR__ . '/config/database.php';
    echo "Step 3: OK — APP_URL=" . APP_URL . "<br>\n";
} catch (Throwable $e) {
    echo "Step 3: FAIL — " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "<br>\n";
    exit;
}

// Test DB connection
echo "Step 4: Connecting to DB...<br>\n";
try {
    $port = getenv('DB_PORT') ?: '3306';
    $ssl  = getenv('DB_SSL') === 'true';
    $host = getenv('DB_HOST');
    $name = getenv('DB_NAME') ?: 'defaultdb';
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');

    $dsn  = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    $opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
    if ($ssl) $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;

    $pdo = new PDO($dsn, $user, $pass, $opts);
    echo "Step 4: DB connected — MySQL " . $pdo->query("SELECT VERSION()")->fetchColumn() . "<br>\n";

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Step 4: Tables found: " . count($tables) . " — " . implode(', ', array_slice($tables,0,5)) . "<br>\n";
} catch (Throwable $e) {
    echo "Step 4: DB FAIL — " . $e->getMessage() . "<br>\n";
}

echo "<br><strong>All steps complete.</strong><br>\n";
