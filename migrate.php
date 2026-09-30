<?php
// TCMS Migration v1.1 — Financial Intelligence Tables
// Run once, then DELETE this file
require_once __DIR__ . '/config/database.php';

$secret = $_GET['secret'] ?? '';
if ($secret !== 'tcms_migrate_v11') {
    http_response_code(403); die('Access denied');
}

$db = getDB();
echo "<pre style='font-family:monospace;background:#1a1a1a;color:#0f0;padding:1.5rem;'>\n";
echo "TCMS Migration v1.1 — Financial Intelligence Tables\n";
echo str_repeat("=", 55) . "\n\n";

$migrations = [

'budget_alert_log' => "CREATE TABLE IF NOT EXISTS budget_alert_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    budget_id INT NOT NULL,
    department_id INT NOT NULL,
    financial_year VARCHAR(20) NOT NULL,
    alert_level ENUM('warning_80','warning_90','exceeded_100') NOT NULL,
    budget_amount DECIMAL(15,2) NOT NULL,
    spent_amount DECIMAL(15,2) NOT NULL,
    utilization_pct DECIMAL(5,2) NOT NULL,
    notified_users JSON,
    is_resolved TINYINT(1) DEFAULT 0,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_alert (budget_id, alert_level, financial_year),
    FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id)
)",

'revenue_forecasts' => "CREATE TABLE IF NOT EXISTS revenue_forecasts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    financial_year VARCHAR(20) NOT NULL,
    forecast_date DATE NOT NULL,
    annual_target DECIMAL(15,2) NOT NULL,
    collected_to_date DECIMAL(15,2) NOT NULL,
    projected_annual DECIMAL(15,2) NOT NULL,
    collection_rate_daily DECIMAL(15,2) NOT NULL,
    days_elapsed INT NOT NULL,
    days_remaining INT NOT NULL,
    confidence_pct DECIMAL(5,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_forecast (financial_year, forecast_date)
)",

'cashflow_snapshots' => "CREATE TABLE IF NOT EXISTS cashflow_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_date DATE NOT NULL,
    financial_year VARCHAR(20) NOT NULL,
    week_number INT NOT NULL,
    revenue_in DECIMAL(15,2) DEFAULT 0,
    govt_funds_in DECIMAL(15,2) DEFAULT 0,
    expenditure_out DECIMAL(15,2) DEFAULT 0,
    net_position DECIMAL(15,2) DEFAULT 0,
    cumulative_position DECIMAL(15,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_snapshot (financial_year, week_number)
)",

'settings_alerts' => "INSERT INTO system_settings
    (setting_key, setting_value, setting_group, description) VALUES
    ('budget_alert_80',  '1', 'alerts', 'Alert at 80% budget utilization'),
    ('budget_alert_90',  '1', 'alerts', 'Alert at 90% budget utilization'),
    ('budget_alert_100', '1', 'alerts', 'Alert when budget exceeded'),
    ('alert_email_finance', '', 'alerts', 'Finance officer alert email'),
    ('alert_email_tc',   '', 'alerts', 'Town Clerk alert email')
    ON DUPLICATE KEY UPDATE setting_key=setting_key",
];

$ok = 0; $fail = 0;
foreach ($migrations as $name => $sql) {
    try {
        $db->exec($sql);
        echo "  [OK]  $name\n";
        $ok++;
    } catch (PDOException $e) {
        // Table already exists = fine
        if (strpos($e->getMessage(), 'already exists') !== false ||
            strpos($e->getMessage(), 'Duplicate') !== false) {
            echo "  [--]  $name (already exists — skipped)\n";
        } else {
            echo "  [ERR] $name: " . $e->getMessage() . "\n";
            $fail++;
        }
    }
}

echo "\n" . str_repeat("=", 55) . "\n";
echo "Migration complete — OK: $ok | Failed: $fail\n\n";

// Verify tables exist
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$newTables = array_intersect(['budget_alert_log','revenue_forecasts','cashflow_snapshots'], $tables);
echo "New tables confirmed: " . implode(', ', $newTables) . "\n";
echo "\nTotal tables in DB: " . count($tables) . "\n";
echo "\nDone. DELETE this file now.\n";
echo "</pre>\n";
echo "<p><a href='/dashboard.php' style='font-weight:bold;'>Go to Dashboard</a></p>\n";
