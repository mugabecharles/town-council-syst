<?php
// TCMS Migration v1.3 — Group 4: SMS, 2FA, Bulk Import
require_once __DIR__ . '/config/database.php';
if (($_GET['secret'] ?? '') !== 'tcms_migrate_v13') { http_response_code(403); die('Access denied'); }
$db = getDB();
echo "<pre style='font-family:monospace;background:#111;color:#0f0;padding:1.5rem;font-size:13px;'>\n";
echo "TCMS Migration v1.3 — SMS, 2FA, Bulk Import\n";
echo str_repeat("=", 52) . "\n\n";

$steps = [

'sms_queue' => "CREATE TABLE IF NOT EXISTS sms_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone VARCHAR(20) NOT NULL, recipient_name VARCHAR(200),
    message TEXT NOT NULL, related_module VARCHAR(50), related_id INT,
    status ENUM('pending','sent','failed') DEFAULT 'pending',
    attempts TINYINT DEFAULT 0, last_attempt TIMESTAMP NULL,
    error_message TEXT, gateway_ref VARCHAR(100), cost DECIMAL(8,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, sent_at TIMESTAMP NULL,
    INDEX idx_status (status), INDEX idx_phone (phone)
)",

'otp_tokens' => "CREATE TABLE IF NOT EXISTS otp_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(10) NOT NULL,
    purpose ENUM('login','password_reset','action_confirm') DEFAULT 'login',
    ip_address VARCHAR(50),
    attempts TINYINT DEFAULT 0,
    is_used TINYINT(1) DEFAULT 0,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_purpose (user_id, purpose)
)",

'bulk_import_logs' => "CREATE TABLE IF NOT EXISTS bulk_import_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_type VARCHAR(50) NOT NULL,
    file_name VARCHAR(255),
    total_rows INT DEFAULT 0,
    success_rows INT DEFAULT 0,
    failed_rows INT DEFAULT 0,
    errors JSON,
    imported_by INT NOT NULL,
    financial_year VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (imported_by) REFERENCES users(id)
)",

'sms_settings' => "INSERT INTO system_settings (setting_key,setting_value,setting_group,description) VALUES
    ('sms_enabled','0','sms','Enable SMS notifications (1=yes, 0=no)'),
    ('sms_gateway','africastalking','sms','SMS gateway: africastalking or vonage'),
    ('sms_api_key','','sms','Africa''s Talking API Key'),
    ('sms_username','','sms','Africa''s Talking Username (sandbox for testing)'),
    ('sms_sender_id','','sms','Sender ID (e.g. KIJURA_TC, max 11 chars)'),
    ('sms_country_code','256','sms','Country dial code (Uganda=256)')
    ON DUPLICATE KEY UPDATE setting_key=setting_key",

'twofa_settings' => "INSERT INTO system_settings (setting_key,setting_value,setting_group,description) VALUES
    ('two_fa_enabled','0','security','Require 2FA for senior users (1=yes, 0=no)'),
    ('two_fa_roles','admin,town_clerk,finance_officer','security','Roles that require 2FA (comma-separated slugs)'),
    ('otp_expiry_minutes','10','security','OTP expiry in minutes')
    ON DUPLICATE KEY UPDATE setting_key=setting_key",

'budget_alert_settings' => "INSERT INTO system_settings (setting_key,setting_value,setting_group,description) VALUES
    ('budget_alert_email','1','alerts','Send email for budget alerts (1=yes)'),
    ('budget_alert_sms','0','alerts','Send SMS for budget alerts (1=yes)')
    ON DUPLICATE KEY UPDATE setting_key=setting_key",
];

$ok = 0; $skip = 0; $fail = 0;
foreach ($steps as $name => $sql) {
    try {
        $db->exec($sql); echo "  [OK]   $name\n"; $ok++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg,'already exists') !== false || stripos($msg,'Duplicate') !== false ||
            stripos($msg,'1060') !== false) {
            echo "  [--]   $name (skipped)\n"; $skip++;
        } else {
            echo "  [ERR]  $name: " . substr($msg,0,80) . "\n"; $fail++;
        }
    }
}

$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "\n" . str_repeat("=",52) . "\n";
echo "Done — OK: $ok | Skipped: $skip | Failed: $fail\n";
echo "Total tables: " . count($tables) . "\n";
echo "\nDELETE this file after running!\n</pre>\n";
echo "<p><a href='/dashboard.php' style='font-weight:bold;'>→ Go to Dashboard</a></p>\n";
