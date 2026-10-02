<?php
/**
 * TCMS Arrears Reminder Campaign
 * Bulk send SMS + Email reminders to all defaulters.
 */
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/sms.php';
require_once __DIR__ . '/../../includes/email.php';
requireLogin();
if (!hasRole(['admin','town_clerk','finance_officer','revenue_officer'])) {
    setFlash('danger','Access denied.'); header('Location: '.APP_URL.'/dashboard.php'); exit;
}
$user = getCurrentUser();
$db   = getDB();
$fy   = getSystemSetting('current_financial_year') ?? CURRENT_FINANCIAL_YEAR;

// ── Campaign history table ─────────────────────────────────────────
// (stored in audit_logs as REMINDER_CAMPAIGN)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'send_reminders') {
        $channel   = $_POST['channel'] ?? 'both'; // sms, email, both
        $minBal    = (float)str_replace(',','', $_POST['min_balance'] ?? 0);
        $wardId    = (int)($_POST['ward_id'] ?? 0);
        $srcId     = (int)($_POST['source_id'] ?? 0);
        $custMsg   = trim($_POST['custom_message'] ?? '');
        $fyFilter  = $_POST['fy'] ?? $fy;

        // Build query
        $where  = ["ra.status IN ('active','partial','overdue')", "ra.balance>0", "ra.financial_year=?"];
        $params = [$fyFilter];
        if ($minBal)  { $where[] = "ra.balance>=?";            $params[] = $minBal; }
        if ($wardId)  { $where[] = "p.ward_id=?";              $params[] = $wardId; }
        if ($srcId)   { $where[] = "ra.revenue_source_id=?";   $params[] = $srcId; }
        $wSQL = implode(' AND ', $where);

        $rows = $db->prepare("SELECT ra.balance, ra.due_date, ra.assessment_number,
            p.full_name, p.payer_number, p.phone, p.email AS payer_email,
            rs.name AS source_name
            FROM revenue_assessments ra
            JOIN payers p ON ra.payer_id=p.id
            JOIN revenue_sources rs ON ra.revenue_source_id=rs.id
            LEFT JOIN wards w ON p.ward_id=w.id
            WHERE $wSQL ORDER BY ra.balance DESC LIMIT 500");
        $rows->execute($params);
        $defaulters = $rows->fetchAll();

        $sentSms=0; $sentEmail=0; $skippedSms=0; $skippedEmail=0;
        $council = getSystemSetting('council_name') ?? 'Kijura TC';

        foreach ($defaulters as $d) {
            $balance  = number_format($d['balance'],0);
            $dueDate  = $d['due_date'] ? date('d/m/Y', strtotime($d['due_date'])) : '';
            $name     = $d['full_name'];
            $source   = $d['source_name'];

            // SMS
            if (in_array($channel, ['sms','both']) && !empty($d['phone'])) {
                $msg = $custMsg ?: "Dear $name, you have an outstanding balance of UGX $balance for $source.";
                if ($dueDate) $msg .= " Due: $dueDate.";
                $msg .= " Please pay at $council offices. Ref: {$d['assessment_number']}.";
                if (queueSms($d['phone'], $msg, $name, 'revenue')) $sentSms++;
                else $skippedSms++;
            } elseif (in_array($channel,['sms','both'])) { $skippedSms++; }

            // Email
            if (in_array($channel, ['email','both']) && !empty($d['payer_email'])) {
                $bodyMsg = $custMsg ?: "You have an outstanding obligation for <strong>$source</strong> amounting to <strong>UGX $balance</strong>.";
                if ($dueDate) $bodyMsg .= " Payment was due on <strong>$dueDate</strong>.";
                $html = emailTemplate(
                    'Outstanding Balance Reminder',
                    "<p>Dear $name,</p>
                    <p>$bodyMsg</p>
                    <p>Please settle this obligation at the $council offices to avoid additional penalties.</p>
                    <div style='background:#f8f9fa;padding:.8rem 1rem;border-radius:6px;margin:12px 0;font-size:13px;'>
                        <strong>Assessment Ref:</strong> {$d['assessment_number']}<br>
                        <strong>Payer ID:</strong> {$d['payer_number']}
                    </div>",
                    APP_URL.'/verify.php?ref='.$d['assessment_number'],
                    'View Your Account'
                );
                if (queueEmail($d['payer_email'], $name, "Outstanding Balance Notice — $council", $html, '', 'revenue')) $sentEmail++;
                else $skippedEmail++;
            } elseif (in_array($channel,['email','both'])) { $skippedEmail++; }
        }

        // Process queues
        if (in_array($channel,['sms','both']))   processSmsQueue(20);
        if (in_array($channel,['email','both'])) processEmailQueue(20);

        logAudit('REMINDER_CAMPAIGN','revenue','campaign',0,"$channel/$fyFilter/$sentSms-sms/$sentEmail-email");
        $msg = "Reminder campaign sent: <strong>$sentSms SMS</strong> queued, <strong>$sentEmail emails</strong> queued.";
        if ($skippedSms>0||$skippedEmail>0) $msg .= " Skipped (no contact): $skippedSms SMS, $skippedEmail email.";
        setFlash('success',$msg);
        header('Location: reminders.php'); exit;
    }
}

// ── Preview defaulters ─────────────────────────────────────────────
$wardFil   = (int)($_GET['ward_id']   ?? 0);
$srcFil    = (int)($_GET['source_id'] ?? 0);
$minBal    = (float)($_GET['min_balance'] ?? 0);
$fyFilter  = $_GET['fy'] ?? $fy;

$where  = ["ra.status IN ('active','partial','overdue')", "ra.balance>0", "ra.financial_year=?"];
$params = [$fyFilter];
if ($minBal)  { $where[] = "ra.balance>=?";           $params[] = $minBal; }
if ($wardFil) { $where[] = "p.ward_id=?";             $params[] = $wardFil; }
if ($srcFil)  { $where[] = "ra.revenue_source_id=?";  $params[] = $srcFil; }
$wSQL = implode(' AND ', $where);

$total = (int)$db->prepare("SELECT COUNT(*) FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL")->execute($params)?0:0;
$cntS=$db->prepare("SELECT COUNT(*) FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL");$cntS->execute($params);$total=(int)$cntS->fetchColumn();
$totalBal=(float)$db->prepare("SELECT COALESCE(SUM(ra.balance),0) FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL")->execute($params)?0:0;
$balS=$db->prepare("SELECT COALESCE(SUM(ra.balance),0) FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL");$balS->execute($params);$totalBal=(float)$balS->fetchColumn();

$withPhone=(int)$db->prepare("SELECT COUNT(*) FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL AND p.phone IS NOT NULL AND p.phone!=''")->execute($params)?0:0;
$wpS=$db->prepare("SELECT COUNT(*) FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL AND p.phone IS NOT NULL AND p.phone!=''");$wpS->execute($params);$withPhone=(int)$wpS->fetchColumn();
$withEmail=(int)$db->prepare("SELECT COUNT(*) FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL AND p.email IS NOT NULL AND p.email!=''")->execute($params)?0:0;
$weS=$db->prepare("SELECT COUNT(*) FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id WHERE $wSQL AND p.email IS NOT NULL AND p.email!=''");$weS->execute($params);$withEmail=(int)$weS->fetchColumn();

$previewRows=$db->prepare("SELECT ra.balance,ra.due_date,p.full_name,p.payer_number,p.phone,p.email AS payer_email,rs.name source_name FROM revenue_assessments ra JOIN payers p ON ra.payer_id=p.id JOIN revenue_sources rs ON ra.revenue_source_id=rs.id LEFT JOIN wards w ON p.ward_id=w.id WHERE $wSQL ORDER BY ra.balance DESC LIMIT 50");
$previewRows->execute($params);$preview=$previewRows->fetchAll();

$wards   = $db->query("SELECT * FROM wards WHERE is_active=1 ORDER BY name")->fetchAll();
$sources = $db->query("SELECT * FROM revenue_sources WHERE is_active=1 ORDER BY name")->fetchAll();
$fyears  = $db->query("SELECT year_code FROM financial_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

$smsEnabled   = getSystemSetting('sms_enabled')   === '1';
$emailEnabled = getSystemSetting('email_enabled') === '1';

renderHead('Arrears Reminder Campaign');
echo '<body><div class="app-wrapper">';
renderSidebar($user);
echo '<div class="main-content">';
renderTopbar('Arrears Reminder Campaign','Bulk SMS & email reminders to revenue defaulters');
renderPageStart('Arrears Reminder Campaign','', [
    ['url'=>APP_URL.'/dashboard.php','label'=>'Dashboard'],
    ['url'=>APP_URL.'/modules/revenue/arrears.php','label'=>'Arrears'],
    ['url'=>'#','label'=>'Reminders'],
]);
renderPageActions('<a href="'.APP_URL.'/modules/revenue/demand_notice.php?fy='.urlencode($fyFilter).'" class="btn btn-outline-secondary">📋 Generate Demand Notices</a>');
renderFlashMessages();
?>

<?php if (!$smsEnabled && !$emailEnabled): ?>
<div class="alert alert-warning">
  <span>⚠</span>
  <div><strong>Neither SMS nor Email is enabled.</strong> Go to <a href="<?=APP_URL?>/modules/admin/settings.php?tab=sms">SMS Settings</a> or <a href="<?=APP_URL?>/modules/admin/settings.php?tab=email">Email Settings</a> to configure and enable notifications before sending reminders.</div>
</div>
<?php endif; ?>

<div class="grid-2" style="align-items:start;">

  <!-- Send Campaign -->
  <div class="card">
    <div class="card-header"><h5><span class="ch-icon">◉</span> Send Reminder Campaign</h5></div>
    <div class="card-body">
      <form method="POST" id="campaignForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="send_reminders">

        <div class="form-group">
          <label class="form-label">Financial Year <span class="req">*</span></label>
          <select name="fy" class="form-select" required onchange="this.form.submit()">
            <?php foreach($fyears as $y): ?><option value="<?=$y?>" <?=$y===$fyFilter?'selected':''?>><?=$y?></option><?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Channel <span class="req">*</span></label>
          <select name="channel" class="form-select" required>
            <option value="sms" <?=!$smsEnabled?'disabled':''?>>SMS only <?=!$smsEnabled?'(disabled)':''?></option>
            <option value="email" <?=!$emailEnabled?'disabled':''?>>Email only <?=!$emailEnabled?'(disabled)':''?></option>
            <option value="both" selected <?=(!$smsEnabled||!$emailEnabled)?'disabled':''?>>SMS + Email <?=(!$smsEnabled||!$emailEnabled)?'(configure both first)':''?></option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Minimum Outstanding Balance (UGX)</label>
          <input type="number" name="min_balance" class="form-control" min="0" step="1000"
                 value="<?=$minBal>0?$minBal:''?>" placeholder="Leave blank for all">
        </div>

        <div class="form-group">
          <label class="form-label">Filter by Ward (optional)</label>
          <select name="ward_id" class="form-select">
            <option value="">All Wards</option>
            <?php foreach($wards as $w): ?><option value="<?=$w['id']?>" <?=$wardFil==$w['id']?'selected':''?>><?=htmlspecialchars($w['name'])?></option><?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Filter by Revenue Source (optional)</label>
          <select name="source_id" class="form-select">
            <option value="">All Sources</option>
            <?php foreach($sources as $s): ?><option value="<?=$s['id']?>" <?=$srcFil==$s['id']?'selected':''?>><?=htmlspecialchars($s['name'])?></option><?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">Custom Message (optional)</label>
          <textarea name="custom_message" class="form-control" rows="3"
                    placeholder="Leave blank to use the default reminder template. The payer name, amount, source and council name will be included automatically."></textarea>
          <div class="form-text">Default template: "Dear [Name], you have UGX [Balance] outstanding for [Source]. Please pay at [Council] offices."</div>
        </div>

        <!-- Preview stats -->
        <div style="background:#f8f9fa;border-radius:8px;padding:1rem;margin-bottom:1rem;">
          <div class="fw-700 fs-sm" style="margin-bottom:.6rem;">Campaign Preview</div>
          <div class="grid-2" style="gap:.4rem;font-size:.82rem;">
            <div class="receipt-row"><span class="text-muted">Total defaulters:</span><strong><?=number_format($total)?></strong></div>
            <div class="receipt-row"><span class="text-muted">Total outstanding:</span><strong class="text-danger">UGX <?=number_format($totalBal)?></strong></div>
            <div class="receipt-row"><span class="text-muted">With phone (SMS):</span><strong class="<?=$withPhone>0?'text-success':'text-muted'?>"><?=number_format($withPhone)?></strong></div>
            <div class="receipt-row"><span class="text-muted">With email:</span><strong class="<?=$withEmail>0?'text-success':'text-muted'?>"><?=number_format($withEmail)?></strong></div>
          </div>
        </div>

        <?php if($total > 0): ?>
        <button type="submit" class="btn btn-primary btn-block"
                data-confirm="Send reminders to <?=number_format($total)?> defaulter(s)? This will queue <?=number_format($withPhone)?> SMS and <?=number_format($withEmail)?> emails.">
          📣 Send Reminder Campaign
        </button>
        <?php else: ?>
        <div class="alert alert-info"><span>ℹ</span><span>No defaulters match the selected filters.</span></div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Preview List -->
  <div class="card">
    <div class="card-header">
      <h5><span class="ch-icon">▲</span> Defaulters Preview (top 50)</h5>
      <span class="badge badge-danger"><?=number_format($total)?> total</span>
    </div>
    <div class="card-body p-0">
      <?php if($preview): ?>
      <div class="table-wrapper">
        <table class="tcms-table table-sm">
          <thead><tr><th>Payer</th><th>Source</th><th class="text-right">Balance</th><th>SMS</th><th>Email</th></tr></thead>
          <tbody>
          <?php foreach($preview as $p): ?>
          <tr>
            <td>
              <div class="fw-600 fs-sm"><?=htmlspecialchars($p['full_name'])?></div>
              <div class="fs-xs text-muted"><?=htmlspecialchars($p['payer_number'])?></div>
            </td>
            <td class="fs-xs"><?=htmlspecialchars($p['source_name'])?></td>
            <td class="text-right fw-700 text-danger"><?=number_format($p['balance'])?></td>
            <td class="text-center">
              <?php if($p['phone']): ?><span class="badge badge-success">✓</span><?php else: ?><span class="badge badge-secondary">—</span><?php endif; ?>
            </td>
            <td class="text-center">
              <?php if($p['payer_email']): ?><span class="badge badge-success">✓</span><?php else: ?><span class="badge badge-secondary">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if($total>50): ?><div style="padding:.6rem 1rem;font-size:.78rem;color:#6c757d;background:#f8f9fa;">Showing 50 of <?=number_format($total)?> defaulters. All will receive reminders.</div><?php endif; ?>
      <?php else: ?><div class="empty-state" style="padding:1.5rem;"><p>No defaulters match the filters.</p></div><?php endif; ?>
    </div>
  </div>

</div>

<?php renderPageEnd(); echo '</div></div>'; renderFooter(); ?>
