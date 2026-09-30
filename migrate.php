<?php
// TCMS Migration v1.2 — Group 2: Workflow Enhancements
require_once __DIR__ . '/config/database.php';
if (($_GET['secret'] ?? '') !== 'tcms_migrate_v12') { http_response_code(403); die('Access denied'); }
$db = getDB();
echo "<pre style='font-family:monospace;background:#111;color:#0f0;padding:1.5rem;'>\n";
echo "TCMS Migration v1.2 — Workflow Enhancements\n";
echo str_repeat("=", 52) . "\n\n";

$steps = [

'email_queue' => "CREATE TABLE IF NOT EXISTS email_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(255) NOT NULL, to_name VARCHAR(200),
    subject VARCHAR(300) NOT NULL, body_html TEXT NOT NULL, body_text TEXT,
    related_module VARCHAR(50), related_id INT,
    status ENUM('pending','sent','failed') DEFAULT 'pending',
    attempts TINYINT DEFAULT 0, last_attempt TIMESTAMP NULL,
    error_message TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, sent_at TIMESTAMP NULL,
    INDEX idx_status (status)
)",

'expenditure_requisitions' => "CREATE TABLE IF NOT EXISTS expenditure_requisitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    req_number VARCHAR(30) NOT NULL UNIQUE,
    financial_year VARCHAR(20) NOT NULL,
    department_id INT NOT NULL,
    budget_id INT, funding_source_id INT,
    title VARCHAR(300) NOT NULL, description TEXT, justification TEXT,
    estimated_amount DECIMAL(15,2) NOT NULL, final_amount DECIMAL(15,2),
    status ENUM('draft','submitted','hod_approved','tc_approved','lpo_issued',
                'delivered','invoiced','voucher_created','completed','rejected','cancelled') DEFAULT 'draft',
    priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
    required_date DATE, supplier_id INT, supplier_name VARCHAR(200),
    lpo_number VARCHAR(50), lpo_date DATE,
    delivery_date DATE, delivery_note VARCHAR(100),
    invoice_number VARCHAR(100), invoice_date DATE, invoice_amount DECIMAL(15,2),
    voucher_id INT, prepared_by INT NOT NULL, prepared_date DATE NOT NULL,
    hod_approved_by INT, hod_approved_at TIMESTAMP NULL,
    tc_approved_by INT, tc_approved_at TIMESTAMP NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (prepared_by) REFERENCES users(id)
)",

'requisition_log' => "CREATE TABLE IF NOT EXISTS requisition_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requisition_id INT NOT NULL, user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL, comment TEXT,
    old_status VARCHAR(50), new_status VARCHAR(50), ip_address VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requisition_id) REFERENCES expenditure_requisitions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id)
)",

'requisition_documents' => "CREATE TABLE IF NOT EXISTS requisition_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requisition_id INT NOT NULL,
    doc_type ENUM('quotation','specification','lpo','delivery_note','invoice','other') NOT NULL,
    title VARCHAR(255) NOT NULL, file_name VARCHAR(255),
    file_path VARCHAR(500), file_size INT, file_type VARCHAR(20),
    uploaded_by INT NOT NULL, uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requisition_id) REFERENCES expenditure_requisitions(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
)",

'alter_vouchers_1' => "ALTER TABLE payment_vouchers ADD COLUMN IF NOT EXISTS correction_note TEXT AFTER rejection_reason",
'alter_vouchers_2' => "ALTER TABLE payment_vouchers ADD COLUMN IF NOT EXISTS correction_count TINYINT DEFAULT 0 AFTER correction_note",
'alter_vouchers_3' => "ALTER TABLE payment_vouchers ADD COLUMN IF NOT EXISTS original_amount DECIMAL(15,2) AFTER correction_count",
'alter_vouchers_4' => "ALTER TABLE payment_vouchers ADD COLUMN IF NOT EXISTS requisition_id INT AFTER original_amount",
'alter_vouchers_5' => "ALTER TABLE payment_vouchers ADD COLUMN IF NOT EXISTS returned_by INT AFTER requisition_id",
'alter_vouchers_6' => "ALTER TABLE payment_vouchers ADD COLUMN IF NOT EXISTS returned_at TIMESTAMP NULL AFTER returned_by",

'email_settings' => "INSERT INTO system_settings (setting_key,setting_value,setting_group,description) VALUES
    ('smtp_host','','email','SMTP Host'),
    ('smtp_port','587','email','SMTP Port'),
    ('smtp_user','','email','SMTP Username'),
    ('smtp_pass','','email','SMTP Password'),
    ('smtp_from','','email','From Email'),
    ('smtp_from_name','Kijura Town Council','email','From Name'),
    ('email_enabled','0','email','Enable email (1=yes, 0=no)')
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
            echo "  [--]   $name (skipped — already exists)\n"; $skip++;
        } else {
            echo "  [ERR]  $name: " . substr($msg, 0, 80) . "\n"; $fail++;
        }
    }
}

echo "\n" . str_repeat("=",52) . "\n";
echo "Done — OK: $ok | Skipped: $skip | Failed: $fail\n";
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Total tables: " . count($tables) . "\n";
echo "\nDELETE this file after running!\n</pre>\n";
echo "<p><a href='/dashboard.php' style='font-weight:bold;'>→ Go to Dashboard</a></p>\n";
