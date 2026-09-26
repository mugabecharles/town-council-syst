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

function hasRole(string|array $roles): bool {
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
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}
