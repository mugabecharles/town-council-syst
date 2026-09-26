<?php
/**
 * TCMS - Database Setup / Installer
 * Run this ONCE to create the database and tables.
 * DELETE this file after successful setup.
 */

// Only allow direct access with the correct key
$setupKey = 'TCMS_SETUP_2026';
if (($_GET['key'] ?? '') !== $setupKey) {
    die('<h2>Access Denied</h2><p>Provide the correct setup key: <code>?key=TCMS_SETUP_2026</code></p>');
}

$host    = 'localhost';
$dbUser  = 'root';
$dbPass  = '';
$dbName  = 'tcms_db';

echo '<!DOCTYPE html><html><head><title>TCMS Setup</title><style>
body{font-family:Arial,sans-serif;max-width:800px;margin:40px auto;padding:0 20px;}
h1{color:#1a3a5c;border-bottom:3px solid #c8a84b;padding-bottom:10px;}
.ok{color:#1e7e34;font-weight:bold;} .err{color:#bd2130;font-weight:bold;}
pre{background:#f4f6f9;padding:10px;border-radius:6px;font-size:.85rem;}
.btn{background:#1a3a5c;color:#fff;padding:10px 24px;border:none;border-radius:6px;cursor:pointer;font-size:1rem;text-decoration:none;display:inline-block;margin-top:1rem;}
</style></head><body>';

echo '<h1>TCMS — Database Setup</h1>';

try {
    // Connect without selecting a DB first
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    echo '<p class="ok">✓ Connected to MySQL server</p>';

    // Read and execute schema
    $schemaFile = __DIR__ . '/config/tcms_schema.sql';
    if (!file_exists($schemaFile)) {
        die('<p class="err">✗ Schema file not found: config/tcms_schema.sql</p>');
    }

    $sql = file_get_contents($schemaFile);

    // Split into statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    $success = 0; $errors = 0;
    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        try {
            $pdo->exec($stmt);
            $success++;
        } catch (PDOException $e) {
            // Ignore "already exists" errors
            if (!in_array($e->getCode(), ['42S01','42000']) && !str_contains($e->getMessage(), 'already exists') && !str_contains($e->getMessage(), 'Duplicate')) {
                echo "<p class='err'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
                $errors++;
            }
        }
    }

    echo "<p class='ok'>✓ Schema executed: $success statements ($errors errors ignored)</p>";

    // Verify tables
    $pdo->exec("USE $dbName");
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "<p class='ok'>✓ " . count($tables) . " tables created</p>";
    echo "<pre>" . implode(', ', $tables) . "</pre>";

    echo "<h2>Setup Complete!</h2>";
    echo "<p>Default admin account:</p>";
    echo "<pre>Username: admin\nPassword: Admin@2026</pre>";
    echo "<p class='err'><strong>IMPORTANT:</strong> Delete or rename this setup.php file immediately after setup!</p>";
    echo "<a href='modules/auth/login.php' class='btn'>Go to Login</a>";

} catch (PDOException $e) {
    echo '<p class="err">✗ Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>Check your database credentials in <code>config/database.php</code></p>';
}

echo '</body></html>';
