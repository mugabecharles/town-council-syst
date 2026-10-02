<?php
// TCMS Migration v1.4 — Group 5: Payroll, Meetings, Asset Depreciation
require_once __DIR__ . '/config/database.php';
if (($_GET['secret'] ?? '') !== 'tcms_migrate_v14') { http_response_code(403); die('Access denied'); }
$db = getDB();
echo "<pre style='font-family:monospace;background:#111;color:#0f0;padding:1.5rem;font-size:13px;'>\n";
echo "TCMS Migration v1.4 — Payroll, Meetings, Asset Depreciation\n";
echo str_repeat("=", 55) . "\n\n";

$steps = [

'staff' => "CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_number VARCHAR(30) NOT NULL UNIQUE,
    user_id INT,
    department_id INT NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    designation VARCHAR(150),
    employment_type ENUM('permanent','contract','casual','intern') DEFAULT 'permanent',
    basic_salary DECIMAL(15,2) NOT NULL DEFAULT 0,
    bank_name VARCHAR(150), bank_account VARCHAR(50),
    nssf_number VARCHAR(30), tin_number VARCHAR(30),
    phone VARCHAR(20), email VARCHAR(200),
    date_joined DATE, is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id)
)",

'salary_components' => "CREATE TABLE IF NOT EXISTS salary_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    component_type ENUM('allowance','deduction','tax','contribution') NOT NULL,
    calculation_method ENUM('fixed','percent_basic','percent_gross') DEFAULT 'fixed',
    default_value DECIMAL(10,4) DEFAULT 0,
    is_mandatory TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",

'staff_salary_components' => "CREATE TABLE IF NOT EXISTS staff_salary_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    component_id INT NOT NULL,
    value DECIMAL(15,4) NOT NULL,
    effective_from DATE, effective_to DATE,
    UNIQUE KEY unique_staff_comp (staff_id, component_id),
    FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
    FOREIGN KEY (component_id) REFERENCES salary_components(id)
)",

'payroll_runs' => "CREATE TABLE IF NOT EXISTS payroll_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_number VARCHAR(30) NOT NULL UNIQUE,
    financial_year VARCHAR(20) NOT NULL,
    pay_month VARCHAR(7) NOT NULL,
    pay_date DATE, department_id INT,
    status ENUM('draft','computed','approved','paid','cancelled') DEFAULT 'draft',
    total_gross DECIMAL(15,2) DEFAULT 0, total_deductions DECIMAL(15,2) DEFAULT 0,
    total_net DECIMAL(15,2) DEFAULT 0, total_paye DECIMAL(15,2) DEFAULT 0,
    total_nssf_employee DECIMAL(15,2) DEFAULT 0, total_nssf_employer DECIMAL(15,2) DEFAULT 0,
    total_lst DECIMAL(15,2) DEFAULT 0, voucher_id INT,
    prepared_by INT NOT NULL, approved_by INT, approved_at TIMESTAMP NULL,
    notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (prepared_by) REFERENCES users(id)
)",

'payroll_items' => "CREATE TABLE IF NOT EXISTS payroll_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL, staff_id INT NOT NULL,
    basic_salary DECIMAL(15,2) NOT NULL, gross_salary DECIMAL(15,2) NOT NULL,
    paye DECIMAL(15,2) DEFAULT 0, nssf_employee DECIMAL(15,2) DEFAULT 0,
    nssf_employer DECIMAL(15,2) DEFAULT 0, lst DECIMAL(15,2) DEFAULT 0,
    other_deductions DECIMAL(15,2) DEFAULT 0, total_deductions DECIMAL(15,2) DEFAULT 0,
    net_salary DECIMAL(15,2) NOT NULL,
    allowances_json JSON, deductions_json JSON,
    bank_name VARCHAR(150), bank_account VARCHAR(50),
    status ENUM('active','revised','cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (staff_id) REFERENCES staff(id)
)",

'salary_components_data' => "INSERT INTO salary_components
    (name,component_type,calculation_method,default_value,is_mandatory) VALUES
    ('PAYE','tax','percent_gross',0,1),
    ('NSSF Employee (5%)','contribution','percent_basic',5.0,1),
    ('NSSF Employer (10%)','contribution','percent_basic',10.0,1),
    ('LST (Local Service Tax)','deduction','fixed',100000,0),
    ('Housing Allowance','allowance','percent_basic',20.0,0),
    ('Transport Allowance','allowance','fixed',150000,0),
    ('Medical Allowance','allowance','fixed',100000,0),
    ('Loan Deduction','deduction','fixed',0,0)
    ON DUPLICATE KEY UPDATE name=name",

'council_meetings' => "CREATE TABLE IF NOT EXISTS council_meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_number VARCHAR(30) NOT NULL UNIQUE,
    meeting_type ENUM('full_council','executive','committee','departmental','extraordinary','other') NOT NULL,
    title VARCHAR(300) NOT NULL, venue VARCHAR(300),
    meeting_date DATE NOT NULL, start_time TIME, end_time TIME,
    chairperson VARCHAR(200), secretary VARCHAR(200),
    quorum_met TINYINT(1) DEFAULT 1, attendees_count INT DEFAULT 0,
    status ENUM('scheduled','in_progress','completed','cancelled','adjourned') DEFAULT 'scheduled',
    agenda TEXT, summary TEXT, next_meeting_date DATE, financial_year VARCHAR(20),
    document_id INT, created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
)",

'meeting_attendees' => "CREATE TABLE IF NOT EXISTS meeting_attendees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL,
    attendee_type ENUM('staff','councillor','external') DEFAULT 'staff',
    name VARCHAR(200) NOT NULL, designation VARCHAR(150), department VARCHAR(150),
    attended TINYINT(1) DEFAULT 1, apology TINYINT(1) DEFAULT 0,
    FOREIGN KEY (meeting_id) REFERENCES council_meetings(id) ON DELETE CASCADE
)",

'meeting_agenda' => "CREATE TABLE IF NOT EXISTS meeting_agenda (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL, item_number INT NOT NULL,
    title VARCHAR(300) NOT NULL, description TEXT,
    presenter VARCHAR(200), duration_minutes INT DEFAULT 15,
    status ENUM('pending','discussed','deferred','withdrawn') DEFAULT 'pending',
    outcome TEXT,
    FOREIGN KEY (meeting_id) REFERENCES council_meetings(id) ON DELETE CASCADE
)",

'council_resolutions' => "CREATE TABLE IF NOT EXISTS council_resolutions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resolution_number VARCHAR(30) NOT NULL UNIQUE,
    meeting_id INT NOT NULL, agenda_id INT, financial_year VARCHAR(20),
    title VARCHAR(300) NOT NULL, resolution_text TEXT NOT NULL,
    responsible_dept INT, responsible_officer VARCHAR(200),
    target_date DATE, status ENUM('pending','in_progress','implemented','deferred','cancelled') DEFAULT 'pending',
    implementation_notes TEXT, implemented_date DATE,
    priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (meeting_id) REFERENCES council_meetings(id)
)",

'asset_depreciation' => "CREATE TABLE IF NOT EXISTS asset_depreciation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL, financial_year VARCHAR(20) NOT NULL,
    depreciation_method ENUM('straight_line','declining_balance','units_of_production') DEFAULT 'straight_line',
    useful_life_years INT DEFAULT 5, residual_value DECIMAL(15,2) DEFAULT 0,
    opening_value DECIMAL(15,2) NOT NULL, depreciation_rate DECIMAL(8,4) NOT NULL,
    depreciation_amount DECIMAL(15,2) NOT NULL, closing_value DECIMAL(15,2) NOT NULL,
    accumulated_depreciation DECIMAL(15,2) DEFAULT 0,
    calculated_by INT, approved_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_asset_fy (asset_id, financial_year),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
)",

'asset_maintenance' => "CREATE TABLE IF NOT EXISTS asset_maintenance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    maintenance_type ENUM('routine','repair','major_overhaul','inspection','disposal') NOT NULL,
    description TEXT NOT NULL, maintenance_date DATE NOT NULL,
    cost DECIMAL(15,2) DEFAULT 0, service_provider VARCHAR(200),
    performed_by VARCHAR(200), next_maintenance_date DATE,
    document_id INT, recorded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id)
)",

'alter_assets_useful_life' => "ALTER TABLE assets ADD COLUMN IF NOT EXISTS useful_life_years INT DEFAULT 5 AFTER current_value",
'alter_assets_residual'    => "ALTER TABLE assets ADD COLUMN IF NOT EXISTS residual_value DECIMAL(15,2) DEFAULT 0 AFTER useful_life_years",
'alter_assets_dep_method'  => "ALTER TABLE assets ADD COLUMN IF NOT EXISTS depreciation_method ENUM('straight_line','declining_balance') DEFAULT 'straight_line' AFTER residual_value",
'alter_assets_disposal_dt' => "ALTER TABLE assets ADD COLUMN IF NOT EXISTS disposal_date DATE AFTER depreciation_method",
'alter_assets_disposal_rsn'=> "ALTER TABLE assets ADD COLUMN IF NOT EXISTS disposal_reason TEXT AFTER disposal_date",
'alter_assets_disposal_proc'=>"ALTER TABLE assets ADD COLUMN IF NOT EXISTS disposal_proceeds DECIMAL(15,2) DEFAULT 0 AFTER disposal_reason",
'alter_assets_condition'   => "ALTER TABLE assets ADD COLUMN IF NOT EXISTS condition_rating ENUM('excellent','good','fair','poor','disposed') DEFAULT 'good' AFTER disposal_proceeds",

'asset_categories_data' => "INSERT INTO asset_categories (name,description) VALUES
    ('Vehicles','Motor vehicles, motorcycles'),
    ('Computers & IT','Computers, printers, servers'),
    ('Furniture','Chairs, tables, desks'),
    ('Buildings','Office buildings, stores'),
    ('Land','Council land'),
    ('Equipment','Office and field equipment'),
    ('Infrastructure','Roads, bridges, drainage'),
    ('Other','Miscellaneous assets')
    ON DUPLICATE KEY UPDATE name=name",
];

$ok = 0; $skip = 0; $fail = 0;
foreach ($steps as $name => $sql) {
    try {
        $db->exec($sql); echo "  [OK]   $name\n"; $ok++;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (stripos($msg,'already exists')!==false || stripos($msg,'Duplicate')!==false ||
            stripos($msg,'1060')!==false || stripos($msg,'1061')!==false) {
            echo "  [--]   $name (skipped)\n"; $skip++;
        } else {
            echo "  [ERR]  $name: ".substr($msg,0,90)."\n"; $fail++;
        }
    }
}

$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "\n".str_repeat("=",55)."\n";
echo "Done — OK: $ok | Skipped: $skip | Failed: $fail\n";
echo "Total tables: ".count($tables)."\n";
echo "\nDELETE this file after running!\n</pre>\n";
echo "<p><a href='/dashboard.php' style='font-weight:bold;'>→ Go to Dashboard</a></p>\n";
