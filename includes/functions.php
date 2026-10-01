<?php
/**
 * TCMS Core Functions
 */

require_once __DIR__ . '/../config/database.php';

// ─── Security ────────────────────────────────────────────────────────────────

function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateToken(): string {
    return bin2hex(random_bytes(32));
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function getClientIP(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            return explode(',', $_SERVER[$k])[0];
        }
    }
    return '0.0.0.0';
}

// ─── Session ──────────────────────────────────────────────────────────────────

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.gc_maxlifetime', 86400);
        ini_set('session.save_path', sys_get_temp_dir());
        session_set_cookie_params([
            'lifetime' => 86400,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    startSecureSession();
    if (!isset($_SESSION['user_id'])) return false;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset(); session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user !== null) return $user;
    $db = getDB();
    $stmt = $db->prepare("SELECT u.*, r.slug AS role_slug, r.name AS role_name, d.name AS dept_name 
                          FROM users u 
                          JOIN roles r ON u.role_id = r.id 
                          LEFT JOIN departments d ON u.department_id = d.id 
                          WHERE u.id = ? AND u.is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/index.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function requirePermission(string $module, string $action): void {
    requireLogin();
    if (!hasPermission($module, $action)) {
        setFlash('error', 'You do not have permission to access this resource.');
        header('Location: ' . APP_URL . '/dashboard.php');
        exit;
    }
}

function hasPermission(string $module, string $action): bool {
    $user = getCurrentUser();
    if (!$user) return false;
    if ($user['role_slug'] === 'admin') return true;
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM role_permissions rp 
                          JOIN permissions p ON rp.permission_id = p.id 
                          WHERE rp.role_id = ? AND p.module = ? AND p.action = ?");
    $stmt->execute([$user['role_id'], $module, $action]);
    return (int)$stmt->fetchColumn() > 0;
}

function hasRole($roles): bool {
    $user = getCurrentUser();
    if (!$user) return false;
    $roles = is_array($roles) ? $roles : [$roles];
    return in_array($user['role_slug'], $roles);
}

// ─── Flash Messages ──────────────────────────────────────────────────────────

function setFlash(string $type, string $message): void {
    startSecureSession();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlash(): array {
    startSecureSession();
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

// ─── Number Generation ───────────────────────────────────────────────────────

function generateReceiptNumber(): string {
    $db = getDB();
    $prefix = getSystemSetting('receipt_prefix') ?? 'RCP';
    $year   = date('Y');
    $stmt   = $db->query("SELECT COUNT(*) FROM revenue_payments WHERE YEAR(created_at) = $year");
    $count  = (int)$stmt->fetchColumn() + 1;
    return $prefix . '-' . $year . '-' . str_pad($count, 6, '0', STR_PAD_LEFT);
}

function generateVoucherNumber(): string {
    $db = getDB();
    $prefix = getSystemSetting('voucher_prefix') ?? 'PV';
    $year   = date('Y');
    $stmt   = $db->query("SELECT COUNT(*) FROM payment_vouchers WHERE YEAR(created_at) = $year");
    $count  = (int)$stmt->fetchColumn() + 1;
    return $prefix . '-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
}

function generatePayerNumber(int $wardId): string {
    $db = getDB();
    $prefix = getSystemSetting('payer_prefix') ?? 'TC';
    $stmt   = $db->prepare("SELECT ward_code FROM wards WHERE id = ?");
    $stmt->execute([$wardId]);
    $ward = $stmt->fetch();
    $wardCode = $ward ? str_replace('W', 'W', $ward['ward_code']) : 'W00';
    $stmt2 = $db->prepare("SELECT COUNT(*) FROM payers WHERE ward_id = ?");
    $stmt2->execute([$wardId]);
    $count = (int)$stmt2->fetchColumn() + 1;
    return $prefix . '-' . $wardCode . '-' . str_pad($count, 6, '0', STR_PAD_LEFT);
}

function generateAssessmentNumber(): string {
    $db = getDB();
    $year  = date('Y');
    $stmt  = $db->query("SELECT COUNT(*) FROM revenue_assessments WHERE YEAR(created_at) = $year");
    $count = (int)$stmt->fetchColumn() + 1;
    return 'ASM-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
}

function generateDocNumber(): string {
    $db = getDB();
    $year  = date('Y');
    $stmt  = $db->query("SELECT COUNT(*) FROM documents WHERE YEAR(uploaded_at) = $year");
    $count = (int)$stmt->fetchColumn() + 1;
    return 'DOC-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);
}

// ─── Formatting ──────────────────────────────────────────────────────────────

function formatCurrency(float $amount): string {
    return CURRENCY . ' ' . number_format($amount, 0, '.', ',');
}

function formatDate(string $date, string $format = 'd/m/Y'): string {
    if (empty($date) || $date === '0000-00-00') return 'N/A';
    return date($format, strtotime($date));
}

function formatDateTime(string $datetime): string {
    if (empty($datetime)) return 'N/A';
    return date('d/m/Y H:i', strtotime($datetime));
}

function numberToWords(float $number): string {
    $ones = ['','one','two','three','four','five','six','seven','eight','nine',
             'ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen',
             'seventeen','eighteen','nineteen'];
    $tens = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
    $number = (int)$number;
    if ($number == 0) return 'zero';
    $words = '';
    if ($number >= 1000000000) {
        $words .= numberToWords((int)($number / 1000000000)) . ' billion ';
        $number %= 1000000000;
    }
    if ($number >= 1000000) {
        $words .= numberToWords((int)($number / 1000000)) . ' million ';
        $number %= 1000000;
    }
    if ($number >= 1000) {
        $words .= numberToWords((int)($number / 1000)) . ' thousand ';
        $number %= 1000;
    }
    if ($number >= 100) {
        $words .= $ones[(int)($number / 100)] . ' hundred ';
        $number %= 100;
    }
    if ($number >= 20) {
        $words .= $tens[(int)($number / 10)] . ' ';
        $number %= 10;
    }
    if ($number > 0) $words .= $ones[$number] . ' ';
    return ucfirst(trim($words)) . ' Uganda Shillings Only';
}

function getStatusBadge(string $status): string {
    $map = [
        'active'            => 'badge-success',
        'paid'              => 'badge-success',
        'completed'         => 'badge-success',
        'approved'          => 'badge-success',
        'hod_approved'      => 'badge-info',
        'tc_approved'       => 'badge-info',
        'finance_verified'  => 'badge-info',
        'finance_cleared'   => 'badge-primary',
        'draft'             => 'badge-secondary',
        'submitted'         => 'badge-primary',
        'pending'           => 'badge-warning',
        'partial'           => 'badge-warning',
        'overdue'           => 'badge-danger',
        'rejected'          => 'badge-danger',
        'cancelled'         => 'badge-dark',
        'voided'            => 'badge-dark',
        'returned'          => 'badge-warning',
        'inactive'          => 'badge-secondary',
        'suspended'         => 'badge-danger',
        'open'              => 'badge-success',
        'closed'            => 'badge-secondary',
        'locked'            => 'badge-dark',
    ];
    $class  = $map[strtolower($status)] ?? 'badge-secondary';
    $label  = ucwords(str_replace('_', ' ', $status));
    return "<span class=\"badge {$class}\">{$label}</span>";
}

// ─── Audit Trail ─────────────────────────────────────────────────────────────

function logAudit(string $action, string $module = '', string $recordType = '', 
                  int $recordId = 0, string $recordRef = '', 
                  array $oldValues = [], array $newValues = []): void {
    try {
        $user = getCurrentUser();
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO audit_logs 
            (user_id, username, action, module, record_type, record_id, record_reference, old_values, new_values, ip_address, user_agent) 
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $user['id'] ?? null,
            $user['username'] ?? 'system',
            $action, $module, $recordType, $recordId, $recordRef,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            getClientIP(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)
        ]);
    } catch (Exception $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}

// ─── Notifications ───────────────────────────────────────────────────────────

function sendNotification(int $userId, string $title, string $message, 
                          string $type = 'info', string $module = '', int $relatedId = 0): void {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO notifications (user_id, title, message, type, module, related_id) VALUES (?,?,?,?,?,?)")
           ->execute([$userId, $title, $message, $type, $module, $relatedId]);
    } catch (Exception $e) {
        error_log("Notification failed: " . $e->getMessage());
    }
}

function getUnreadNotificationCount(): int {
    $user = getCurrentUser();
    if (!$user) return 0;
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    return (int)$stmt->fetchColumn();
}

function getRecentNotifications(int $limit = 10): array {
    $user = getCurrentUser();
    if (!$user) return [];
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$user['id'], $limit]);
    return $stmt->fetchAll();
}

// ─── System Settings ─────────────────────────────────────────────────────────

function getSystemSetting(string $key): ?string {
    static $settings = null;
    if ($settings === null) {
        try {
            $db = getDB();
            $rows = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
            $settings = array_column($rows, 'setting_value', 'setting_key');
        } catch (Exception $e) {
            return null;
        }
    }
    return $settings[$key] ?? null;
}

// ─── File Upload ──────────────────────────────────────────────────────────────

function handleFileUpload(array $file, string $subfolder = ''): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Upload error code: ' . $file['error']];
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => 'File exceeds maximum size of 10MB.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        return ['success' => false, 'error' => 'File type not allowed. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS)];
    }
    $uploadDir = UPLOAD_PATH . ($subfolder ? rtrim($subfolder, '/') . '/' : '');
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $newName  = uniqid('', true) . '.' . $ext;
    $destPath = $uploadDir . $newName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['success' => false, 'error' => 'Failed to save file.'];
    }
    return [
        'success'   => true,
        'file_name' => $file['name'],
        'file_path' => ($subfolder ? $subfolder . '/' : '') . $newName,
        'file_size' => $file['size'],
        'file_type' => $ext,
    ];
}

// ─── Pagination ──────────────────────────────────────────────────────────────

function paginate(int $totalRecords, int $currentPage, int $perPage = RECORDS_PER_PAGE): array {
    $totalPages = max(1, (int)ceil($totalRecords / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    return [
        'total'        => $totalRecords,
        'per_page'     => $perPage,
        'current_page' => $currentPage,
        'total_pages'  => $totalPages,
        'offset'       => ($currentPage - 1) * $perPage,
        'has_prev'     => $currentPage > 1,
        'has_next'     => $currentPage < $totalPages,
    ];
}

function renderPagination(array $paginate, string $url): string {
    if ($paginate['total_pages'] <= 1) return '';
    $html = '<nav><ul class="pagination justify-content-end">';
    $html .= '<li class="page-item ' . (!$paginate['has_prev'] ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $url . '&page=' . ($paginate['current_page'] - 1) . '">Previous</a></li>';
    $start = max(1, $paginate['current_page'] - 2);
    $end   = min($paginate['total_pages'], $paginate['current_page'] + 2);
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $paginate['current_page'] ? 'active' : '';
        $html .= "<li class=\"page-item {$active}\"><a class=\"page-link\" href=\"{$url}&page={$i}\">{$i}</a></li>";
    }
    $html .= '<li class="page-item ' . (!$paginate['has_next'] ? 'disabled' : '') . '">';
    $html .= '<a class="page-link" href="' . $url . '&page=' . ($paginate['current_page'] + 1) . '">Next</a></li>';
    $html .= '</ul></nav>';
    return $html;
}

// ─── CSRF ─────────────────────────────────────────────────────────────────────

function csrfToken(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || !hash_equals(csrfToken(), $token)) {
        // CSRF failed — regenerate and redirect back
        setFlash('danger', 'Your session expired. Please try again.');
        $redirect = $_SERVER['HTTP_REFERER'] ?? APP_URL . '/modules/auth/login.php';
        header('Location: ' . $redirect);
        exit;
    }
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}


// ══════════════════════════════════════════════════════════════════
// FINANCIAL INTELLIGENCE ENGINE
// ══════════════════════════════════════════════════════════════════

// ─── Budget Variance Alert Engine ────────────────────────────────

/**
 * Check all active budgets for threshold breaches.
 * Call this on dashboard load — it's fast (uses existing budget data).
 * Sends in-app notifications to Town Clerk + Finance Officers.
 */
function checkBudgetAlerts(): array {
    static $checked = false;
    if ($checked) return [];
    $checked = true;

    try {
        $db  = getDB();
        $fy  = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;
        $alerts = [];

        $budgets = $db->prepare("
            SELECT b.id, b.budget_code, b.department_id, b.financial_year,
                   COALESCE(b.revised_amount, b.approved_amount) AS effective_budget,
                   b.spent_amount,
                   d.name AS dept_name
            FROM budgets b
            JOIN departments d ON b.department_id = d.id
            WHERE b.financial_year = ? AND b.status IN ('approved','active')
              AND COALESCE(b.revised_amount, b.approved_amount) > 0
        ");
        $budgets->execute([$fy]);
        $budgets = $budgets->fetchAll();

        foreach ($budgets as $b) {
            $pct = round(($b['spent_amount'] / $b['effective_budget']) * 100, 1);

            $level = null;
            if ($pct >= 100) $level = 'exceeded_100';
            elseif ($pct >= 90) $level = 'warning_90';
            elseif ($pct >= 80) $level = 'warning_80';

            if (!$level) continue;

            // Check if this alert was already sent
            $existing = $db->prepare("SELECT id FROM budget_alert_log WHERE budget_id=? AND alert_level=? AND financial_year=? AND is_resolved=0");
            $existing->execute([$b['id'], $level, $fy]);
            if ($existing->fetch()) continue; // already alerted

            // Log the alert
            $db->prepare("INSERT INTO budget_alert_log (budget_id,department_id,financial_year,alert_level,budget_amount,spent_amount,utilization_pct) VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE spent_amount=VALUES(spent_amount), utilization_pct=VALUES(utilization_pct)")
               ->execute([$b['id'], $b['department_id'], $fy, $level, $b['effective_budget'], $b['spent_amount'], $pct]);

            // Build alert message
            $levelLabels = [
                'warning_80'   => '80% Budget Utilization Warning',
                'warning_90'   => '90% Budget Utilization — Critical',
                'exceeded_100' => 'BUDGET EXCEEDED',
            ];
            $types = ['warning_80'=>'warning','warning_90'=>'danger','exceeded_100'=>'danger'];

            $title   = $levelLabels[$level] . ': ' . $b['dept_name'];
            $message = $b['dept_name'] . ' has used ' . $pct . '% of its ' . $fy . ' budget. '
                     . 'Spent: UGX ' . number_format($b['spent_amount'])
                     . ' of UGX ' . number_format($b['effective_budget']) . '.';

            // Notify all Town Clerks and Finance Officers (in-app)
            $recipients = $db->query("
                SELECT u.id, u.email, u.full_name FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE r.slug IN ('town_clerk','finance_officer','admin') AND u.is_active = 1
            ")->fetchAll();

            foreach ($recipients as $rec) {
                sendNotification((int)$rec['id'], $title, $message, $types[$level], 'budget', $b['id']);
            }

            // ── Email alert ────────────────────────────────────────
            if ((getSystemSetting('budget_alert_email') ?? '1') === '1') {
                _sendBudgetAlertEmail($b, $pct, $level, $fy, $recipients);
            }

            // ── SMS alert ──────────────────────────────────────────
            if ((getSystemSetting('budget_alert_sms') ?? '0') === '1') {
                foreach ($recipients as $rec) {
                    if (!empty($rec['phone'] ?? '')) {
                        // sms.php may not be loaded here — check first
                        if (function_exists('smsBudgetAlert')) {
                            smsBudgetAlert($rec['phone'] ?? '', $rec['full_name'], $b['dept_name'], $pct);
                        }
                    }
                }
                if (function_exists('processSmsQueue')) processSmsQueue(5);
            }

            logAudit('BUDGET_ALERT', 'budget', 'budget', $b['id'], $b['budget_code'],
                [], ['level' => $level, 'pct' => $pct]);

            $alerts[] = [
                'dept'    => $b['dept_name'],
                'level'   => $level,
                'pct'     => $pct,
                'spent'   => $b['spent_amount'],
                'budget'  => $b['effective_budget'],
                'code'    => $b['budget_code'],
            ];
        }

        return $alerts;
    } catch (Exception $e) {
        error_log("Budget alert check failed: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all active (unresolved) budget alerts for display.
 */
function getActiveBudgetAlerts(string $fy = ''): array {
    try {
        $db = getDB();
        if (!$fy) $fy = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

        $stmt = $db->prepare("
            SELECT ba.*, d.name AS dept_name, b.budget_code,
                   COALESCE(b.revised_amount, b.approved_amount) AS effective_budget
            FROM budget_alert_log ba
            JOIN departments d ON ba.department_id = d.id
            JOIN budgets b     ON ba.budget_id = b.id
            WHERE ba.financial_year = ? AND ba.is_resolved = 0
            ORDER BY ba.alert_level DESC, ba.utilization_pct DESC
        ");
        $stmt->execute([$fy]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// ─── Revenue Forecasting Engine ──────────────────────────────────

/**
 * Calculate revenue forecast for the current financial year.
 * Returns projection based on daily collection rate so far.
 */
function getRevenueForecast(string $fy = ''): array {
    try {
        $db = getDB();
        if (!$fy) $fy = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

        // Get FY start/end dates
        $fyData = $db->prepare("SELECT start_date, end_date FROM financial_years WHERE year_code=?");
        $fyData->execute([$fy]);
        $fyRow = $fyData->fetch();

        $startDate = $fyRow ? $fyRow['start_date'] : date('Y-m-d', strtotime('first day of July'));
        $endDate   = $fyRow ? $fyRow['end_date']   : date('Y-m-d', strtotime('last day of June next year'));

        $today       = date('Y-m-d');
        $daysElapsed = max(1, (int)((strtotime($today) - strtotime($startDate)) / 86400));
        $totalDays   = max(1, (int)((strtotime($endDate) - strtotime($startDate)) / 86400));
        $daysRemaining = max(0, $totalDays - $daysElapsed);

        // Get annual target
        $targetStmt = $db->prepare("SELECT COALESCE(SUM(target_amount),0) FROM revenue_targets WHERE financial_year=?");
        $targetStmt->execute([$fy]);
        $annualTarget = (float)$targetStmt->fetchColumn();
        if ($annualTarget <= 0) $annualTarget = 850000000; // fallback

        // Collected so far this FY
        $collStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'");
        $collStmt->execute([$fy]);
        $collectedToDate = (float)$collStmt->fetchColumn();

        // Daily collection rate
        $dailyRate = $collectedToDate / $daysElapsed;

        // Projected annual = collected + (remaining days × daily rate)
        $projectedAnnual = $collectedToDate + ($dailyRate * $daysRemaining);

        // Achievement percentage at current pace
        $projectedPct = $annualTarget > 0 ? round(($projectedAnnual / $annualTarget) * 100, 1) : 0;

        // Collection rate (% of year elapsed vs % of target collected)
        $yearPctElapsed    = round(($daysElapsed / $totalDays) * 100, 1);
        $targetPctCollected = $annualTarget > 0 ? round(($collectedToDate / $annualTarget) * 100, 1) : 0;
        $performanceIndex  = $yearPctElapsed > 0 ? round($targetPctCollected / $yearPctElapsed * 100, 1) : 0;

        // Monthly breakdown — last 6 months collected
        $monthlyStmt = $db->prepare("
            SELECT DATE_FORMAT(payment_date,'%Y-%m') AS ym,
                   DATE_FORMAT(payment_date,'%b %Y') AS label,
                   SUM(amount) AS revenue
            FROM revenue_payments
            WHERE financial_year=? AND status='active'
              AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(payment_date,'%Y-%m')
            ORDER BY ym ASC
        ");
        $monthlyStmt->execute([$fy]);
        $monthly = $monthlyStmt->fetchAll();

        // Month-over-month growth rate
        $growthRate = 0;
        if (count($monthly) >= 2) {
            $last  = (float)end($monthly)['revenue'];
            prev($monthly);
            $prev  = (float)current($monthly)['revenue'];
            $growthRate = $prev > 0 ? round((($last - $prev) / $prev) * 100, 1) : 0;
        }

        // Ward growth rates (current month vs last month)
        $wardGrowth = $db->prepare("
            SELECT w.name AS ward,
                   SUM(CASE WHEN MONTH(rp.payment_date)=MONTH(CURDATE()) AND YEAR(rp.payment_date)=YEAR(CURDATE()) THEN rp.amount ELSE 0 END) AS this_month,
                   SUM(CASE WHEN MONTH(rp.payment_date)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(rp.payment_date)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) THEN rp.amount ELSE 0 END) AS last_month
            FROM wards w
            LEFT JOIN revenue_payments rp ON rp.ward_id=w.id AND rp.status='active'
            GROUP BY w.id ORDER BY w.name
        ");
        $wardGrowth->execute();
        $wardGrowth = $wardGrowth->fetchAll();

        foreach ($wardGrowth as &$wg) {
            $wg['growth_rate'] = $wg['last_month'] > 0
                ? round((($wg['this_month'] - $wg['last_month']) / $wg['last_month']) * 100, 1)
                : ($wg['this_month'] > 0 ? 100 : 0);
        }
        unset($wg);

        return [
            'fy'                  => $fy,
            'annual_target'       => $annualTarget,
            'collected_to_date'   => $collectedToDate,
            'projected_annual'    => $projectedAnnual,
            'projected_pct'       => $projectedPct,
            'daily_rate'          => $dailyRate,
            'days_elapsed'        => $daysElapsed,
            'days_remaining'      => $daysRemaining,
            'total_days'          => $totalDays,
            'year_pct_elapsed'    => $yearPctElapsed,
            'target_pct_collected'=> $targetPctCollected,
            'performance_index'   => $performanceIndex,
            'monthly_trend'       => $monthly,
            'growth_rate_mom'     => $growthRate,
            'ward_growth'         => $wardGrowth,
            'shortfall'           => max(0, $annualTarget - $projectedAnnual),
            'surplus'             => max(0, $projectedAnnual - $annualTarget),
            'on_track'            => $projectedAnnual >= $annualTarget,
        ];
    } catch (Exception $e) {
        error_log("Revenue forecast failed: " . $e->getMessage());
        return [];
    }
}

// ─── Cash Flow Engine ─────────────────────────────────────────────

/**
 * Get cash flow data: weekly position, 30-day rolling trend,
 * available balance per funding source.
 */
function getCashFlowData(string $fy = ''): array {
    try {
        $db = getDB();
        if (!$fy) $fy = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

        // ── Weekly cash position (last 8 weeks) ────────────────────
        $weekly = $db->prepare("
            SELECT
                YEAR(d)  AS yr,
                WEEK(d, 3) AS wk,
                DATE_FORMAT(MIN(d), '%d %b') AS week_start,
                SUM(rev) AS revenue_in,
                SUM(gvt) AS govt_in,
                SUM(exp) AS expenditure_out
            FROM (
                SELECT payment_date AS d, amount AS rev, 0 AS gvt, 0 AS exp
                FROM revenue_payments
                WHERE status='active' AND financial_year=?
                  AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
                UNION ALL
                SELECT date_received AS d, 0, amount_received, 0
                FROM government_funds
                WHERE financial_year=?
                  AND date_received >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
                UNION ALL
                SELECT payment_date AS d, 0, 0, amount
                FROM payment_vouchers
                WHERE status IN ('paid','completed') AND financial_year=?
                  AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
                  AND payment_date IS NOT NULL
            ) combined
            GROUP BY YEAR(d), WEEK(d, 3)
            ORDER BY yr ASC, wk ASC
        ");
        $weekly->execute([$fy, $fy, $fy]);
        $weeklyData = $weekly->fetchAll();

        // Calculate net and cumulative
        $cumulative = 0;
        foreach ($weeklyData as &$w) {
            $w['net']  = (float)$w['revenue_in'] + (float)$w['govt_in'] - (float)$w['expenditure_out'];
            $cumulative += $w['net'];
            $w['cumulative'] = $cumulative;
        }
        unset($w);

        // ── 30-day daily rolling trend ──────────────────────────────
        $daily30 = $db->prepare("
            SELECT d_date,
                   SUM(rev_in)  AS revenue_in,
                   SUM(exp_out) AS exp_out
            FROM (
                SELECT DATE(payment_date) AS d_date, amount AS rev_in, 0 AS exp_out
                FROM revenue_payments
                WHERE status='active'
                  AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                UNION ALL
                SELECT DATE(payment_date), 0, amount
                FROM payment_vouchers
                WHERE status IN ('paid','completed')
                  AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  AND payment_date IS NOT NULL
            ) t
            GROUP BY d_date ORDER BY d_date ASC
        ");
        $daily30->execute();
        $daily30Data = $daily30->fetchAll();

        // ── Funding source balances ─────────────────────────────────
        $fundBalances = $db->prepare("
            SELECT fs.name AS source_name, fs.source_type,
                   COALESCE(SUM(gf.amount_received),0)  AS total_received,
                   COALESCE(SUM(gf.amount_utilized),0)  AS total_utilized,
                   COALESCE(SUM(gf.amount_received),0) - COALESCE(SUM(gf.amount_utilized),0) AS balance
            FROM funding_sources fs
            LEFT JOIN government_funds gf ON gf.funding_source_id=fs.id AND gf.financial_year=?
            GROUP BY fs.id
            ORDER BY total_received DESC
        ");
        $fundBalances->execute([$fy]);
        $fundBalances = $fundBalances->fetchAll();

        // ── Local revenue balance ───────────────────────────────────
        $localRevStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE financial_year=? AND status='active'");
        $localRevStmt->execute([$fy]);
        $localRev = (float)$localRevStmt->fetchColumn();

        $totalExpStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE financial_year=? AND status IN ('paid','completed')");
        $totalExpStmt->execute([$fy]);
        $totalExp = (float)$totalExpStmt->fetchColumn();

        $totalGovt = array_sum(array_column($fundBalances, 'total_received'));
        $totalInflow  = $localRev + $totalGovt;
        $netPosition  = $totalInflow - $totalExp;

        // ── This week vs last week ──────────────────────────────────
        $thisWeekRev = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE status='active' AND YEARWEEK(payment_date,3)=YEARWEEK(CURDATE(),3)")->fetchColumn();
        $lastWeekRev = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM revenue_payments WHERE status='active' AND YEARWEEK(payment_date,3)=YEARWEEK(DATE_SUB(CURDATE(),INTERVAL 1 WEEK),3)")->fetchColumn();
        $thisWeekExp = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payment_vouchers WHERE status IN ('paid','completed') AND YEARWEEK(payment_date,3)=YEARWEEK(CURDATE(),3)")->fetchColumn();

        $weekRevChange = $lastWeekRev > 0 ? round((($thisWeekRev - $lastWeekRev) / $lastWeekRev) * 100, 1) : 0;

        return [
            'weekly_data'      => $weeklyData,
            'daily_30'         => $daily30Data,
            'fund_balances'    => $fundBalances,
            'local_revenue'    => $localRev,
            'total_govt'       => $totalGovt,
            'total_inflow'     => $totalInflow,
            'total_expenditure'=> $totalExp,
            'net_position'     => $netPosition,
            'this_week_rev'    => $thisWeekRev,
            'last_week_rev'    => $lastWeekRev,
            'this_week_exp'    => $thisWeekExp,
            'week_rev_change'  => $weekRevChange,
            'fy'               => $fy,
        ];
    } catch (Exception $e) {
        error_log("Cash flow data failed: " . $e->getMessage());
        return [];
    }
}

/**
 * Get budget variance summary for dashboard cards.
 */
function getBudgetVarianceSummary(string $fy = ''): array {
    try {
        $db = getDB();
        if (!$fy) $fy = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

        $stmt = $db->prepare("
            SELECT d.name AS dept_name,
                   b.budget_code,
                   b.id AS budget_id,
                   COALESCE(b.revised_amount, b.approved_amount) AS effective_budget,
                   b.spent_amount,
                   ROUND((b.spent_amount / COALESCE(b.revised_amount, b.approved_amount)) * 100, 1) AS utilization_pct,
                   (COALESCE(b.revised_amount, b.approved_amount) - b.spent_amount) AS balance
            FROM budgets b
            JOIN departments d ON b.department_id = d.id
            WHERE b.financial_year = ?
              AND b.status IN ('approved','active')
              AND COALESCE(b.revised_amount, b.approved_amount) > 0
            ORDER BY utilization_pct DESC
        ");
        $stmt->execute([$fy]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}


// ─── Budget Alert Email Helper ────────────────────────────────────────────────

function _sendBudgetAlertEmail(array $b, float $pct, string $level, string $fy, array $recipients): void {
    // Lazy-load email engine (only if not already loaded)
    if (!function_exists('queueEmail')) {
        $emailFile = __DIR__ . '/email.php';
        if (file_exists($emailFile)) require_once $emailFile;
        else return;
    }

    $council  = getSystemSetting('council_name') ?? 'Kijura Town Council';
    $appUrl   = defined('APP_URL') ? APP_URL : '';

    $levelConfig = [
        'warning_80'   => ['label' => '⚠ 80% Budget Utilization Warning', 'color' => '#d39e00', 'bg' => '#fff3cd'],
        'warning_90'   => ['label' => '🔴 90% Budget Utilization — Critical Alert', 'color' => '#bd2130', 'bg' => '#f8d7da'],
        'exceeded_100' => ['label' => '🚨 BUDGET EXCEEDED — Immediate Action Required', 'color' => '#bd2130', 'bg' => '#f8d7da'],
    ];
    $cfg = $levelConfig[$level] ?? $levelConfig['warning_80'];

    $balance     = $b['effective_budget'] - $b['spent_amount'];
    $balanceStr  = 'UGX ' . number_format($balance);
    $spentStr    = 'UGX ' . number_format($b['spent_amount']);
    $budgetStr   = 'UGX ' . number_format($b['effective_budget']);

    $bodyContent = "
        <div style='background:{$cfg['bg']};border-left:4px solid {$cfg['color']};padding:14px 16px;border-radius:0 8px 8px 0;margin-bottom:20px;'>
            <strong style='color:{$cfg['color']};font-size:15px;'>{$cfg['label']}</strong>
        </div>
        <p>The following department has reached a budget threshold that requires your attention:</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;font-size:13px;'>
            <tr style='background:#f8f9fa;'>
                <td style='padding:9px 12px;font-weight:700;border:1px solid #dee2e6;width:40%;'>Department</td>
                <td style='padding:9px 12px;border:1px solid #dee2e6;font-weight:700;color:#1a3a5c;'>{$b['dept_name']}</td>
            </tr>
            <tr>
                <td style='padding:9px 12px;font-weight:700;border:1px solid #dee2e6;'>Financial Year</td>
                <td style='padding:9px 12px;border:1px solid #dee2e6;'>$fy</td>
            </tr>
            <tr style='background:#f8f9fa;'>
                <td style='padding:9px 12px;font-weight:700;border:1px solid #dee2e6;'>Budget Code</td>
                <td style='padding:9px 12px;border:1px solid #dee2e6;'>{$b['budget_code']}</td>
            </tr>
            <tr>
                <td style='padding:9px 12px;font-weight:700;border:1px solid #dee2e6;'>Approved Budget</td>
                <td style='padding:9px 12px;border:1px solid #dee2e6;'>$budgetStr</td>
            </tr>
            <tr style='background:#f8f9fa;'>
                <td style='padding:9px 12px;font-weight:700;border:1px solid #dee2e6;'>Amount Spent</td>
                <td style='padding:9px 12px;border:1px solid #dee2e6;font-weight:700;color:{$cfg['color']};'>$spentStr ($pct%)</td>
            </tr>
            <tr>
                <td style='padding:9px 12px;font-weight:700;border:1px solid #dee2e6;'>Remaining Balance</td>
                <td style='padding:9px 12px;border:1px solid #dee2e6;" . ($balance < 0 ? "color:#bd2130;font-weight:700;" : "") . "'>$balanceStr</td>
            </tr>
        </table>
        " . ($level === 'exceeded_100' ? "
        <div style='background:#f8d7da;border:1px solid #f5c6cb;padding:12px 16px;border-radius:6px;margin:12px 0;'>
            <strong style='color:#721c24;'>Action Required:</strong>
            <span style='color:#721c24;'>This department has exceeded its approved budget. No further expenditure should be approved without a budget revision or additional allocation.</span>
        </div>" : "") . "
        <p>Please log in to review the department's expenditure and take appropriate action.</p>";

    $subject = "[{$council}] {$cfg['label']}: {$b['dept_name']} — FY $fy";

    foreach ($recipients as $rec) {
        if (empty($rec['email'])) continue;
        $html = emailTemplate($cfg['label'], $bodyContent, "$appUrl/modules/reports/cashflow.php", 'View Budget Dashboard');
        queueEmail($rec['email'], $rec['full_name'], $subject, $html, '', 'budget', $b['id']);
    }

    // Process immediately (non-blocking, max 3)
    if (function_exists('processEmailQueue')) processEmailQueue(3);
}
