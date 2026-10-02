<?php
/**
 * TCMS System Health Dashboard
 * Database stats, table sizes, queue status, PHP info.
 */
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
if (!hasRole(['admin'])) {
    setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit;
}
$user = getCurrentUser();
$db   = getDB();

// ── Database stats ─────────────────────────────────────────────────
$tables = $db->query("SHOW TABLE STATUS")->fetchAll();
$totalRows    = array_sum(array_column($tables,'Rows'));
$totalDataMB  = round(array_sum(array_column($tables,'Data_length'))/1024/1024,2);
$totalIndexMB = round(array_sum(array_column($tables,'Index_length'))/1024/1024,2);
$tableCount   = count($tables);
$dbVersion    = $db->query("SELECT VERSION()")->fetchColumn();

// Sort tables by rows desc
usort($tables, fn($a,$b)=>$b['Rows']-$a['Rows']);

// ── Queue stats ────────────────────────────────────────────────────
$emailStats = $db->query("SELECT status,COUNT(*) cnt FROM email_queue GROUP BY status")->fetchAll();
$emailMap   = array_column($emailStats,'cnt','status');
$smsStats   = $db->query("SELECT status,COUNT(*) cnt FROM sms_queue GROUP BY status")->fetchAll();
$smsMap     = array_column($smsStats,'cnt','status');

// ── Audit log stats ────────────────────────────────────────────────
$auditTotal  = (int)$db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$auditToday  = (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$loginTotal  = (int)$db->query("SELECT COUNT(*) FROM login_logs")->fetchColumn();

// ── Data totals ────────────────────────────────────────────────────
$dataCounts = [
    'Users'          => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'Payers'         => $db->query("SELECT COUNT(*) FROM payers")->fetchColumn(),
    'Payments'       => $db->query("SELECT COUNT(*) FROM revenue_payments")->fetchColumn(),
    'Vouchers'       => $db->query("SELECT COUNT(*) FROM payment_vouchers")->fetchColumn(),
    'Assessments'    => $db->query("SELECT COUNT(*) FROM revenue_assessments")->fetchColumn(),
    'Documents'      => $db->query("SELECT COUNT(*) FROM documents")->fetchColumn(),
    'Staff'          => $db->query("SELECT COUNT(*) FROM staff")->fetchColumn(),
    'Projects'       => $db->query("SELECT COUNT(*) FROM projects")->fetchColumn(),
    'Meetings'       => $db->query("SELECT COUNT(*) FROM council_meetings")->fetchColumn(),
    'Resolutions'    => $db->query("SELECT COUNT(*) FROM council_resolutions")->fetchColumn(),
    'Notifications'  => $db->query("SELECT COUNT(*) FROM notifications")->fetchColumn(),
    'Audit Logs'     => $auditTotal,
];

// ── Upload folder sizes ────────────────────────────────────────────
function getFolderSize(string $path): int {
    if (!is_dir($path)) return 0;
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}
$uploadPath = defined('UPLOAD_PATH') ? UPLOAD_PATH : __DIR__.'/../../uploads/';
$uploadSize = getFolderSize($uploadPath);
$uploadMB   = round($uploadSize/1024/1024,2);

// ── PHP info ───────────────────────────────────────────────────────
$phpInfo = [
    'PHP Version'        => PHP_VERSION,
    'Memory Limit'       => ini_get('memory_limit'),
    'Max Upload Size'    => ini_get('upload_max_filesize'),
    'Post Max Size'      => ini_get('post_max_size'),
    'Max Execution Time' => ini_get('max_execution_time').'s',
    'Session Path'       => ini_get('session.save_path') ?: sys_get_temp_dir(),
    'Error Reporting'    => ini_get('display_errors')?'ON (dev)':'OFF (prod)',
    'OPcache'            => function_exists('opcache_get_status')?'Enabled':'Disabled',
];

renderHead('System Health');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('System Health','Database, queues, PHP and storage status');
renderPageStart('System Health','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>'#','label'=>'System Health'],
]);
renderPageActions('<a href="health.php" class="btn btn-outline-secondary">↻ Refresh</a>');
renderFlashMessages();
?>

<!-- Top stat cards -->
<div class="grid-5" style="margin-bottom:1.2rem;">
  <div class="stat-card green"><div class="stat-icon">🗄</div><div class="stat-info"><div class="label">DB Tables</div><div class="value"><?=$tableCount?></div><div class="sub"><?=$dbVersion?></div></div></div>
  <div class="stat-card blue"><div class="stat-icon">📊</div><div class="stat-info"><div class="label">Total Rows</div><div class="value"><?=number_format($totalRows)?></div></div></div>
  <div class="stat-card"><div class="stat-icon">💾</div><div class="stat-info"><div class="label">DB Size</div><div class="value"><?=($totalDataMB+$totalIndexMB)?>MB</div><div class="sub">Data: <?=$totalDataMB?>MB · Idx: <?=$totalIndexMB?>MB</div></div></div>
  <div class="stat-card"><div class="stat-icon">📁</div><div class="stat-info"><div class="label">Upload Storage</div><div class="value"><?=$uploadMB?>MB</div></div></div>
  <div class="stat-card blue"><div class="stat-icon">◮</div><div class="stat-info"><div class="label">Audit Events</div><div class="value"><?=number_format($auditTotal)?></div><div class="sub"><?=$auditToday?> today</div></div></div>
</div>

<div class="grid-3" style="margin-bottom:1.2rem;">

  <!-- Data Counts -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">📊</span> Record Counts</h5></div>
    <div class="card-body">
      <?php foreach($dataCounts as $lbl=>$cnt): ?>
      <div class="receipt-row" style="padding:.4rem 0;">
        <span class="fs-sm text-muted"><?=$lbl?></span>
        <strong class="fs-sm"><?=number_format((int)$cnt)?></strong>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Queues -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◎</span> Queue Status</h5></div>
    <div class="card-body">
      <div class="fw-700 fs-sm" style="margin-bottom:.5rem;color:var(--primary);">Email Queue</div>
      <?php foreach(['pending'=>'badge-warning','sent'=>'badge-success','failed'=>'badge-danger'] as $s=>$cls): ?>
      <div class="receipt-row"><span class="fs-sm"><?=ucfirst($s)?></span><span class="badge <?=$cls?>"><?=$emailMap[$s]??0?></span></div>
      <?php endforeach; ?>
      <hr class="divider">
      <div class="fw-700 fs-sm" style="margin-bottom:.5rem;color:var(--primary);">SMS Queue</div>
      <?php foreach(['pending'=>'badge-warning','sent'=>'badge-success','failed'=>'badge-danger'] as $s=>$cls): ?>
      <div class="receipt-row"><span class="fs-sm"><?=ucfirst($s)?></span><span class="badge <?=$cls?>"><?=$smsMap[$s]??0?></span></div>
      <?php endforeach; ?>
      <hr class="divider">
      <div class="fw-700 fs-sm" style="margin-bottom:.5rem;color:var(--primary);">OTP Tokens</div>
      <?php
      $otpStats=$db->query("SELECT is_used,COUNT(*) cnt FROM otp_tokens GROUP BY is_used")->fetchAll();
      $otpMap=array_column($otpStats,'cnt','is_used');
      ?>
      <div class="receipt-row"><span class="fs-sm">Active (unused)</span><span class="badge badge-warning"><?=$otpMap[0]??0?></span></div>
      <div class="receipt-row"><span class="fs-sm">Used</span><span class="badge badge-secondary"><?=$otpMap[1]??0?></span></div>
    </div>
  </div>

  <!-- PHP Info -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◆</span> PHP Environment</h5></div>
    <div class="card-body">
      <?php foreach($phpInfo as $k=>$v): ?>
      <div class="receipt-row" style="padding:.4rem 0;">
        <span class="fs-sm text-muted"><?=$k?></span>
        <code style="font-size:.78rem;background:#f8f9fa;padding:1px 5px;border-radius:3px;"><?=htmlspecialchars($v)?></code>
      </div>
      <?php endforeach; ?>
      <hr class="divider">
      <div class="fw-700 fs-sm" style="margin-bottom:.4rem;color:var(--primary);">Loaded Extensions</div>
      <?php
      $needed = ['pdo_mysql','gd','zip','mbstring','json','openssl'];
      foreach($needed as $ext): ?>
      <div class="receipt-row" style="padding:.3rem 0;">
        <span class="fs-xs"><?=$ext?></span>
        <?php if(extension_loaded($ext)): ?>
        <span class="badge badge-success">✓ Loaded</span>
        <?php else: ?>
        <span class="badge badge-danger">✕ Missing</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<!-- DB Tables detail -->
<div class="card">
  <div class="card-header"><h5><span class="ch-icon">🗄</span> Database Tables (<?=$tableCount?> tables)</h5></div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table class="tcms-table table-sm">
        <thead><tr><th>Table</th><th>Engine</th><th class="text-right">Rows</th><th class="text-right">Data (KB)</th><th class="text-right">Index (KB)</th><th>Created</th></tr></thead>
        <tbody>
        <?php foreach($tables as $t): ?>
        <tr>
          <td class="fw-600 fs-sm"><?=htmlspecialchars($t['Name'])?></td>
          <td class="fs-xs"><?=htmlspecialchars($t['Engine']??'InnoDB')?></td>
          <td class="text-right"><?=number_format((int)$t['Rows'])?></td>
          <td class="text-right fs-xs"><?=round((int)$t['Data_length']/1024,1)?></td>
          <td class="text-right fs-xs"><?=round((int)$t['Index_length']/1024,1)?></td>
          <td class="fs-xs text-muted"><?=$t['Create_time']?date('d/m/Y',strtotime($t['Create_time'])):'—'?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th>TOTAL (<?=$tableCount?> tables)</th>
            <th></th>
            <th class="text-right"><?=number_format($totalRows)?></th>
            <th class="text-right"><?=$totalDataMB?>MB</th>
            <th class="text-right"><?=$totalIndexMB?>MB</th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
