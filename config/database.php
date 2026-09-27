<?php
/**
 * TCMS - Database Configuration
 * Reads from environment variables when available (Render / production),
 * falls back to local development defaults.
 */

// Load a .env file if it exists (local dev only)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\n\r\"'");
        if (!array_key_exists($k, $_ENV) && !array_key_exists($k, $_SERVER)) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

function env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

defined('DB_HOST')    || define('DB_HOST',    env('DB_HOST',    'localhost'));
defined('DB_NAME')    || define('DB_NAME',    env('DB_NAME',    'tcms_db'));
defined('DB_USER')    || define('DB_USER',    env('DB_USER',    'root'));
defined('DB_PASS')    || define('DB_PASS',    env('DB_PASS',    ''));
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');
// ── Application ───────────────────────────────────────────────────
defined('APP_NAME')       || define('APP_NAME',        'Town Council Management System');
defined('APP_SHORT_NAME') || define('APP_SHORT_NAME',  'TCMS');
defined('COUNCIL_NAME')   || define('COUNCIL_NAME',    env('COUNCIL_NAME', 'Kira Town Council'));
defined('COUNCIL_SLOGAN') || define('COUNCIL_SLOGAN',  'Serving Our Community with Integrity');
defined('CURRENCY')       || define('CURRENCY',        'UGX');
defined('CURRENCY_SYMBOL')|| define('CURRENCY_SYMBOL', 'UGX');
defined('APP_VERSION')    || define('APP_VERSION',     '1.0.0');
defined('APP_ENV')        || define('APP_ENV',         env('APP_ENV', 'development'));

// Derive APP_URL: honour explicit env var, else auto-detect from request
if (!defined('APP_URL')) {
    if (env('APP_URL')) {
        define('APP_URL', rtrim(env('APP_URL'), '/'));
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        // On Render/cloud: app is at root. Locally: may be in a subdirectory.
        $host   = $_SERVER['HTTP_HOST'];
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        // If running from webroot (Render), base is empty
        $parts  = explode('/', trim($script, '/'));
        // Remove filename — keep only directory parts
        array_pop($parts);
        $base = !empty($parts) ? '/' . implode('/', $parts) : '';
        define('APP_URL', $scheme . '://' . $host . $base);
    } else {
        define('APP_URL', 'http://localhost');
    }
}

defined('APP_PATH')             || define('APP_PATH', realpath(__DIR__ . '/..'));

// ── Upload Settings ───────────────────────────────────────────────
defined('UPLOAD_PATH')        || define('UPLOAD_PATH',        APP_PATH . '/uploads/');
defined('MAX_FILE_SIZE')      || define('MAX_FILE_SIZE',      10 * 1024 * 1024);
defined('ALLOWED_EXTENSIONS') || define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx']);

// ── Session & Security ────────────────────────────────────────────
defined('SESSION_TIMEOUT')    || define('SESSION_TIMEOUT',    (int)env('SESSION_TIMEOUT', '1800'));

// ── Pagination ────────────────────────────────────────────────────
defined('RECORDS_PER_PAGE')   || define('RECORDS_PER_PAGE', 25);

// ── Financial Year ────────────────────────────────────────────────
defined('CURRENT_FINANCIAL_YEAR') || define('CURRENT_FINANCIAL_YEAR', '2026/2027');

/**
 * Get (singleton) PDO database connection.
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $port = env('DB_PORT', '3306');
            $ssl  = env('DB_SSL', 'false') === 'true';

            $dsn = 'mysql:host=' . DB_HOST
                 . ';port=' . $port
                 . ';dbname=' . DB_NAME
                 . ';charset=' . DB_CHARSET;

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            // Aiven and managed MySQL require SSL — no cert verification needed
            // (they use a trusted CA; we skip local CA file verification)
            if ($ssl) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                $options[PDO::MYSQL_ATTR_INIT_COMMAND]           = "SET NAMES utf8mb4";
            }

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $msg = APP_ENV === 'production'
                ? 'Database connection error. Please contact the administrator.'
                : 'Database connection failed: ' . $e->getMessage();
            if (php_sapi_name() === 'cli') { fwrite(STDERR, $msg . "\n"); exit(1); }
            http_response_code(503);
            die('<h2 style="font-family:sans-serif;color:#c00;">System Unavailable</h2><p>' . htmlspecialchars($msg) . '</p>');
        }
    }
    return $pdo;
}
