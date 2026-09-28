-- ============================================================
-- TCMS - Town Council Management System
-- Complete Database Schema v1.0
-- ============================================================

CREATE DATABASE IF NOT EXISTS tcms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE tcms_db;

-- ============================================================
-- ADMINISTRATIVE TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(100) NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    UNIQUE KEY unique_perm (module, action)
);

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dept_code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    head_user_id INT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) UNIQUE,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(200) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(200) NOT NULL,
    phone VARCHAR(20),
    role_id INT NOT NULL,
    department_id INT,
    designation VARCHAR(150),
    is_active TINYINT(1) DEFAULT 1,
    must_change_password TINYINT(1) DEFAULT 0,
    last_login TIMESTAMP NULL,
    login_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    two_fa_enabled TINYINT(1) DEFAULT 0,
    two_fa_secret VARCHAR(100),
    profile_photo VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (department_id) REFERENCES departments(id)
);

-- ============================================================
-- GEOGRAPHICAL / ADMINISTRATIVE STRUCTURE
-- ============================================================

CREATE TABLE IF NOT EXISTS wards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ward_code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS parishes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ward_id INT NOT NULL,
    parish_code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ward_id) REFERENCES wards(id)
);

CREATE TABLE IF NOT EXISTS villages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parish_id INT NOT NULL,
    village_code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parish_id) REFERENCES parishes(id)
);

-- ============================================================
-- REVENUE TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS revenue_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(100),
    description TEXT,
    default_amount DECIMAL(15,2) DEFAULT 0,
    payment_frequency ENUM('daily','weekly','monthly','quarterly','annually','once') DEFAULT 'annually',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payer_number VARCHAR(30) NOT NULL UNIQUE,
    payer_type ENUM('individual','business','organization') DEFAULT 'individual',
    full_name VARCHAR(200) NOT NULL,
    business_name VARCHAR(200),
    national_id VARCHAR(50),
    tin_number VARCHAR(50),
    phone VARCHAR(20),
    phone2 VARCHAR(20),
    email VARCHAR(200),
    address TEXT,
    ward_id INT,
    parish_id INT,
    village_id INT,
    registration_date DATE NOT NULL,
    registered_by INT,
    status ENUM('active','inactive','suspended') DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (ward_id) REFERENCES wards(id),
    FOREIGN KEY (parish_id) REFERENCES parishes(id),
    FOREIGN KEY (village_id) REFERENCES villages(id),
    FOREIGN KEY (registered_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS revenue_assessments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assessment_number VARCHAR(30) NOT NULL UNIQUE,
    payer_id INT NOT NULL,
    revenue_source_id INT NOT NULL,
    financial_year VARCHAR(20) NOT NULL,
    period_from DATE,
    period_to DATE,
    assessed_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    penalty_amount DECIMAL(15,2) DEFAULT 0,
    total_due DECIMAL(15,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(15,2) DEFAULT 0,
    balance DECIMAL(15,2) AS (total_due - amount_paid) STORED,
    status ENUM('active','paid','partial','overdue','cancelled','waived') DEFAULT 'active',
    assessed_by INT,
    assessed_date DATE NOT NULL,
    due_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (payer_id) REFERENCES payers(id),
    FOREIGN KEY (revenue_source_id) REFERENCES revenue_sources(id),
    FOREIGN KEY (assessed_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS revenue_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(30) NOT NULL UNIQUE,
    payer_id INT NOT NULL,
    assessment_id INT,
    revenue_source_id INT NOT NULL,
    financial_year VARCHAR(20) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash','bank','mobile_money','cheque','electronic','other') NOT NULL,
    transaction_reference VARCHAR(100),
    bank_name VARCHAR(150),
    collected_by INT NOT NULL,
    ward_id INT,
    status ENUM('active','voided','reversed') DEFAULT 'active',
    void_reason TEXT,
    voided_by INT,
    voided_at TIMESTAMP NULL,
    void_authorized_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payer_id) REFERENCES payers(id),
    FOREIGN KEY (assessment_id) REFERENCES revenue_assessments(id),
    FOREIGN KEY (revenue_source_id) REFERENCES revenue_sources(id),
    FOREIGN KEY (collected_by) REFERENCES users(id),
    FOREIGN KEY (ward_id) REFERENCES wards(id)
);

CREATE TABLE IF NOT EXISTS revenue_targets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    financial_year VARCHAR(20) NOT NULL,
    ward_id INT,
    revenue_source_id INT,
    target_amount DECIMAL(15,2) NOT NULL,
    set_by INT,
    set_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ward_id) REFERENCES wards(id),
    FOREIGN KEY (revenue_source_id) REFERENCES revenue_sources(id)
);

-- ============================================================
-- FINANCIAL / BUDGET TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS financial_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_code VARCHAR(20) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('open','closed','locked') DEFAULT 'open',
    closed_by INT,
    closed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (closed_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS funding_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source_code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    source_type ENUM('local_revenue','central_government','donor','loan','grant','other') NOT NULL,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS government_funds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    funding_id VARCHAR(30) NOT NULL UNIQUE,
    funding_source_id INT NOT NULL,
    ministry_agency VARCHAR(200),
    programme_name VARCHAR(200),
    fund_type ENUM('conditional','unconditional','development','equalization','other') NOT NULL,
    financial_year VARCHAR(20) NOT NULL,
    department_id INT,
    amount_received DECIMAL(15,2) NOT NULL,
    date_received DATE NOT NULL,
    bank_account VARCHAR(150),
    reference_number VARCHAR(100),
    purpose TEXT,
    approved_allocation DECIMAL(15,2) DEFAULT 0,
    amount_utilized DECIMAL(15,2) DEFAULT 0,
    status ENUM('received','allocated','partially_utilized','fully_utilized') DEFAULT 'received',
    recorded_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (funding_source_id) REFERENCES funding_sources(id),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    budget_code VARCHAR(30) NOT NULL UNIQUE,
    financial_year VARCHAR(20) NOT NULL,
    department_id INT NOT NULL,
    funding_source_id INT,
    budget_category VARCHAR(150),
    description TEXT,
    approved_amount DECIMAL(15,2) NOT NULL,
    revised_amount DECIMAL(15,2),
    committed_amount DECIMAL(15,2) DEFAULT 0,
    spent_amount DECIMAL(15,2) DEFAULT 0,
    status ENUM('draft','approved','active','exhausted','closed') DEFAULT 'draft',
    approved_by INT,
    approved_date DATE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (funding_source_id) REFERENCES funding_sources(id)
);

CREATE TABLE IF NOT EXISTS budget_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    budget_id INT NOT NULL,
    account_code VARCHAR(30),
    line_item VARCHAR(200) NOT NULL,
    description TEXT,
    budgeted_amount DECIMAL(15,2) NOT NULL,
    committed_amount DECIMAL(15,2) DEFAULT 0,
    spent_amount DECIMAL(15,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (budget_id) REFERENCES budgets(id)
);

-- ============================================================
-- EXPENDITURE / VOUCHER TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS payment_vouchers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    voucher_number VARCHAR(30) NOT NULL UNIQUE,
    financial_year VARCHAR(20) NOT NULL,
    department_id INT NOT NULL,
    budget_id INT,
    budget_line_id INT,
    funding_source_id INT,
    payee_name VARCHAR(200) NOT NULL,
    payee_contact VARCHAR(100),
    payee_account VARCHAR(100),
    description TEXT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_date DATE,
    payment_method ENUM('cash','bank_transfer','cheque','mobile_money','other'),
    payment_reference VARCHAR(100),
    account_code VARCHAR(50),
    status ENUM('draft','submitted','hod_approved','tc_approved','finance_verified','finance_cleared','paid','completed','rejected','returned','cancelled') DEFAULT 'draft',
    rejection_reason TEXT,
    prepared_by INT NOT NULL,
    prepared_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (budget_id) REFERENCES budgets(id),
    FOREIGN KEY (funding_source_id) REFERENCES funding_sources(id),
    FOREIGN KEY (prepared_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS voucher_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    voucher_id INT NOT NULL,
    approver_id INT NOT NULL,
    approver_role VARCHAR(100),
    approval_stage ENUM('hod','town_clerk','finance_verify','finance_clear','payment') NOT NULL,
    action ENUM('approved','rejected','returned','noted') NOT NULL,
    comments TEXT,
    ip_address VARCHAR(50),
    approved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (voucher_id) REFERENCES payment_vouchers(id),
    FOREIGN KEY (approver_id) REFERENCES users(id)
);

-- ============================================================
-- DOCUMENT MANAGEMENT
-- ============================================================

CREATE TABLE IF NOT EXISTS document_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    parent_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES document_categories(id)
);

CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_number VARCHAR(30) NOT NULL UNIQUE,
    title VARCHAR(300) NOT NULL,
    description TEXT,
    category_id INT,
    doc_type ENUM('voucher','receipt','invoice','report','contract','minutes','procurement','bank_statement','accountability','photo','other') NOT NULL,
    related_module VARCHAR(50),
    related_id INT,
    department_id INT,
    financial_year VARCHAR(20),
    file_name VARCHAR(255),
    file_path VARCHAR(500),
    file_size INT,
    file_type VARCHAR(50),
    version INT DEFAULT 1,
    is_latest TINYINT(1) DEFAULT 1,
    parent_doc_id INT,
    is_confidential TINYINT(1) DEFAULT 0,
    uploaded_by INT NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES document_categories(id),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

-- ============================================================
-- PROJECTS
-- ============================================================

CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(300) NOT NULL,
    description TEXT,
    location TEXT,
    department_id INT,
    funding_source_id INT,
    government_fund_id INT,
    contractor_name VARCHAR(200),
    contractor_contact VARCHAR(100),
    budget_amount DECIMAL(15,2) NOT NULL,
    amount_spent DECIMAL(15,2) DEFAULT 0,
    financial_year VARCHAR(20),
    start_date DATE,
    expected_end_date DATE,
    actual_end_date DATE,
    progress_percent TINYINT DEFAULT 0,
    status ENUM('planned','active','on_hold','completed','cancelled') DEFAULT 'planned',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (funding_source_id) REFERENCES funding_sources(id)
);

-- ============================================================
-- PROCUREMENT
-- ============================================================

CREATE TABLE IF NOT EXISTS suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    contact_person VARCHAR(150),
    phone VARCHAR(20),
    email VARCHAR(200),
    address TEXT,
    tin_number VARCHAR(50),
    category VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS procurement_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(30) NOT NULL UNIQUE,
    department_id INT NOT NULL,
    requested_by INT NOT NULL,
    financial_year VARCHAR(20) NOT NULL,
    title VARCHAR(300) NOT NULL,
    description TEXT,
    estimated_amount DECIMAL(15,2),
    budget_id INT,
    status ENUM('draft','submitted','approved','lpo_issued','delivered','invoiced','paid','closed','rejected') DEFAULT 'draft',
    required_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (requested_by) REFERENCES users(id)
);

-- ============================================================
-- ASSETS
-- ============================================================

CREATE TABLE IF NOT EXISTS asset_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    description TEXT
);

CREATE TABLE IF NOT EXISTS assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_number VARCHAR(30) NOT NULL UNIQUE,
    category_id INT,
    name VARCHAR(200) NOT NULL,
    description TEXT,
    department_id INT,
    location VARCHAR(200),
    purchase_date DATE,
    purchase_value DECIMAL(15,2),
    current_value DECIMAL(15,2),
    funding_source_id INT,
    responsible_officer INT,
    serial_number VARCHAR(100),
    status ENUM('active','maintenance','disposed','lost','damaged') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES asset_categories(id),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (responsible_officer) REFERENCES users(id)
);

-- ============================================================
-- NOTIFICATIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(300) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info','warning','success','danger','approval','reminder') DEFAULT 'info',
    module VARCHAR(50),
    related_id INT,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ============================================================
-- AUDIT TRAIL
-- ============================================================

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(100),
    action VARCHAR(100) NOT NULL,
    module VARCHAR(100),
    record_type VARCHAR(100),
    record_id INT,
    record_reference VARCHAR(100),
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(50),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_module (module),
    INDEX idx_created (created_at)
);

CREATE TABLE IF NOT EXISTS login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(100),
    action ENUM('login','logout','failed','locked') NOT NULL,
    ip_address VARCHAR(50),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- SYSTEM SETTINGS
-- ============================================================

CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_group VARCHAR(100),
    description TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ============================================================
-- DEFAULT DATA
-- ============================================================

INSERT INTO roles (name, slug, description) VALUES
('System Administrator', 'admin', 'Full system access and configuration'),
('Town Clerk', 'town_clerk', 'Senior management and approval authority'),
('Finance Officer', 'finance_officer', 'Financial management and payment processing'),
('Revenue Officer', 'revenue_officer', 'Revenue collection and payer management'),
('Head of Department', 'hod', 'Departmental management and expenditure requests'),
('Auditor', 'auditor', 'Read-only audit and accountability access'),
('Council Management', 'management', 'Dashboard and reporting access')
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO departments (dept_code, name, description) VALUES
('FIN', 'Finance', 'Finance and Accounts Department'),
('WORKS', 'Works', 'Works and Infrastructure Department'),
('HEALTH', 'Health', 'Health Services Department'),
('EDUC', 'Education', 'Education Department'),
('COMM', 'Community Development', 'Community Development and Social Services'),
('ADMIN', 'Administration', 'General Administration'),
('PROD', 'Production', 'Production and Natural Resources'),
('PLAN', 'Planning', 'Planning and Development'),
('ENV', 'Environment', 'Environment and Sanitation'),
('PROC', 'Procurement', 'Procurement and Disposal Unit')
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO revenue_sources (source_code, name, category, payment_frequency) VALUES
('TL001', 'Trading Licenses', 'Business', 'annually'),
('MD001', 'Market Dues', 'Market', 'monthly'),
('LST001', 'Local Service Tax', 'Tax', 'annually'),
('PF001', 'Parking Fees', 'Transport', 'daily'),
('BP001', 'Business Permits', 'Business', 'annually'),
('ADV001', 'Advertising Fees', 'Business', 'monthly'),
('RI001', 'Rental Income', 'Property', 'monthly'),
('GR001', 'Ground Rent', 'Property', 'annually'),
('AF001', 'Application Fees', 'Administrative', 'once'),
('PEN001', 'Penalties & Fines', 'Regulatory', 'once'),
('OTH001', 'Other Local Revenue', 'Other', 'once')
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO funding_sources (source_code, name, source_type) VALUES
('LR001', 'Local Revenue', 'local_revenue'),
('ULGF', 'Uganda Local Government Finance Commission', 'central_government'),
('PAF', 'Poverty Action Fund', 'central_government'),
('LGDP', 'Local Government Development Programme', 'donor'),
('COND001', 'Conditional Grants - Health', 'central_government'),
('COND002', 'Conditional Grants - Education', 'central_government'),
('UNCOND', 'Unconditional Grant', 'central_government')
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO financial_years (year_code, start_date, end_date, status) VALUES
('2024/2025', '2024-07-01', '2025-06-30', 'closed'),
('2025/2026', '2025-07-01', '2026-06-30', 'open'),
('2026/2027', '2026-07-01', '2027-06-30', 'open')
ON DUPLICATE KEY UPDATE year_code=year_code;

INSERT INTO wards (ward_code, name) VALUES
('W001', 'Ward 1 - Central'),
('W002', 'Ward 2 - Northern'),
('W003', 'Ward 3 - Southern'),
('W004', 'Ward 4 - Eastern'),
('W005', 'Ward 5 - Western')
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO system_settings (setting_key, setting_value, setting_group, description) VALUES
('council_name', 'Kira Town Council', 'general', 'Full Council Name'),
('council_address', 'P.O. Box 100, Kira', 'general', 'Physical/Postal Address'),
('council_phone', '+256 414 000000', 'general', 'Main Phone Number'),
('council_email', 'info@kiratc.go.ug', 'general', 'Official Email'),
('current_financial_year', '2026/2027', 'financial', 'Active Financial Year'),
('receipt_prefix', 'RCP', 'revenue', 'Receipt Number Prefix'),
('voucher_prefix', 'PV', 'expenditure', 'Voucher Number Prefix'),
('payer_prefix', 'TC', 'revenue', 'Payer ID Prefix'),
('session_timeout', '1800', 'security', 'Session timeout in seconds'),
('max_login_attempts', '5', 'security', 'Max failed login attempts before lockout')
ON DUPLICATE KEY UPDATE setting_key=setting_key;

-- Default admin user (password: Admin@2026)
INSERT INTO users (employee_id, username, email, password_hash, full_name, phone, role_id, department_id, designation, is_active) VALUES
('EMP001', 'admin', 'admin@tcms.local', '$2y$12$IUJGV4GO4P9SQkmV9sRmpe7sjbWiAv9BMvijd1aJeKm7idl7Jrl22', 'System Administrator', '+256700000000', 1, 6, 'System Administrator', 1)
ON DUPLICATE KEY UPDATE password_hash='$2y$12$IUJGV4GO4P9SQkmV9sRmpe7sjbWiAv9BMvijd1aJeKm7idl7Jrl22';
