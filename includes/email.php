<?php
/**
 * TCMS Email Notification Engine
 * Uses PHP's built-in mail() OR SMTP via stream socket (no libraries needed).
 * All emails go through the email_queue table for reliability.
 */

require_once __DIR__ . '/../config/database.php';

// ── Queue an email (non-blocking — always returns immediately) ────

function queueEmail(
    string $toEmail,
    string $toName,
    string $subject,
    string $bodyHtml,
    string $bodyText = '',
    string $module   = '',
    int    $relatedId = 0
): bool {
    try {
        $db = getDB();
        $db->prepare("INSERT INTO email_queue
            (to_email, to_name, subject, body_html, body_text, related_module, related_id, status)
            VALUES (?,?,?,?,?,?,?,'pending')")
           ->execute([$toEmail, $toName, $subject, $bodyHtml,
                      $bodyText ?: strip_tags($bodyHtml), $module, $relatedId]);
        return true;
    } catch (Exception $e) {
        error_log("Email queue failed: " . $e->getMessage());
        return false;
    }
}

// ── Process queue (call from a page load or cron) ─────────────────

function processEmailQueue(int $limit = 10): array {
    $results = ['sent' => 0, 'failed' => 0];
    try {
        $db = getDB();
        $enabled = getSystemSetting('email_enabled') ?? '0';
        if ($enabled !== '1') return $results;

        $rows = $db->query("SELECT * FROM email_queue WHERE status='pending' AND attempts < 3 ORDER BY created_at ASC LIMIT $limit")->fetchAll();

        foreach ($rows as $email) {
            $db->prepare("UPDATE email_queue SET attempts=attempts+1, last_attempt=NOW() WHERE id=?")->execute([$email['id']]);
            $sent = sendEmailSmtp(
                $email['to_email'], $email['to_name'],
                $email['subject'], $email['body_html'], $email['body_text']
            );
            if ($sent) {
                $db->prepare("UPDATE email_queue SET status='sent', sent_at=NOW() WHERE id=?")->execute([$email['id']]);
                $results['sent']++;
            } else {
                $db->prepare("UPDATE email_queue SET status='failed' WHERE id=? AND attempts >= 3")->execute([$email['id']]);
                $results['failed']++;
            }
        }
    } catch (Exception $e) {
        error_log("Email queue processing failed: " . $e->getMessage());
    }
    return $results;
}

// ── SMTP sender (pure PHP sockets — no library needed) ────────────

function sendEmailSmtp(
    string $toEmail, string $toName,
    string $subject, string $htmlBody, string $textBody = ''
): bool {
    $host     = getSystemSetting('smtp_host')     ?? '';
    $port     = (int)(getSystemSetting('smtp_port') ?? '587');
    $user     = getSystemSetting('smtp_user')     ?? '';
    $pass     = getSystemSetting('smtp_pass')     ?? '';
    $from     = getSystemSetting('smtp_from')     ?? $user;
    $fromName = getSystemSetting('smtp_from_name') ?? 'Kijura Town Council';

    if (empty($host) || empty($user)) {
        // Fallback to PHP mail()
        return sendEmailFallback($toEmail, $toName, $subject, $htmlBody, $from, $fromName);
    }

    try {
        $boundary = md5(uniqid('', true));
        $message  = buildMimeMessage($subject, $htmlBody, $textBody ?: strip_tags($htmlBody), $boundary);

        $smtp = new TcmsSmtp($host, $port);
        $smtp->connect();
        $smtp->auth($user, $pass);
        $smtp->mail("$fromName <$from>");
        $smtp->rcpt($toEmail);
        $smtp->data($message, $from, $fromName, $toEmail, $toName, $subject);
        $smtp->quit();
        return true;
    } catch (Exception $e) {
        error_log("SMTP send failed: " . $e->getMessage());
        // Fallback to PHP mail()
        return sendEmailFallback($toEmail, $toName, $subject, $htmlBody, $from, $fromName);
    }
}

function sendEmailFallback(
    string $to, string $toName, string $subject,
    string $html, string $from, string $fromName
): bool {
    if (empty($from)) return false;
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $fromName <$from>\r\n";
    $headers .= "Reply-To: $from\r\n";
    $headers .= "X-Mailer: TCMS/1.0\r\n";
    return @mail("$toName <$to>", $subject, $html, $headers);
}

// ── Minimal SMTP client (no STARTTLS for simplicity) ──────────────

class TcmsSmtp {
    private $sock;
    private string $host;
    private int $port;

    public function __construct(string $host, int $port) {
        $this->host = $host;
        $this->port = $port;
    }

    public function connect(): void {
        $ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $prefix = $this->port === 465 ? 'ssl://' : 'tcp://';
        $this->sock = stream_socket_client("$prefix{$this->host}:{$this->port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) throw new Exception("SMTP connect failed: $errstr ($errno)");
        stream_set_timeout($this->sock, 15);
        $this->read(); // 220 greeting
        $this->write("EHLO " . gethostname()); $this->read();
        if ($this->port === 587) {
            $this->write("STARTTLS"); $this->read();
            stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->write("EHLO " . gethostname()); $this->read();
        }
    }

    public function auth(string $user, string $pass): void {
        $this->write("AUTH LOGIN"); $this->read();
        $this->write(base64_encode($user)); $this->read();
        $this->write(base64_encode($pass));
        $r = $this->read();
        if (!str_starts_with($r, '235')) throw new Exception("SMTP auth failed: $r");
    }

    public function mail(string $from): void {
        $this->write("MAIL FROM:<$from>"); $this->read();
    }
    public function rcpt(string $to): void {
        $this->write("RCPT TO:<$to>"); $this->read();
    }

    public function data(string $msg, string $from, string $fromName, string $to, string $toName, string $subject): void {
        $this->write("DATA"); $this->read();
        $date    = date('r');
        $msgId   = '<' . uniqid('tcms') . '@' . gethostname() . '>';
        $headers = "From: $fromName <$from>\r\nTo: $toName <$to>\r\nSubject: $subject\r\nDate: $date\r\nMessage-ID: $msgId\r\n";
        $this->write($headers . $msg . "\r\n.");
        $r = $this->read();
        if (!str_starts_with($r, '250')) throw new Exception("SMTP data failed: $r");
    }

    public function quit(): void {
        $this->write("QUIT");
        fclose($this->sock);
    }

    private function write(string $cmd): void { fwrite($this->sock, $cmd . "\r\n"); }
    private function read(): string {
        $r = '';
        while ($line = fgets($this->sock, 512)) {
            $r .= $line;
            if ($line[3] === ' ') break;
        }
        return trim($r);
    }
}

function buildMimeMessage(string $subject, string $html, string $text, string $boundary): string {
    $msg  = "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n";
    $msg .= "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n\r\n";
    $msg .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n\r\n";
    $msg .= "--$boundary--";
    return $msg;
}

// ── Email Templates ───────────────────────────────────────────────

function emailTemplate(string $title, string $content, string $actionUrl = '', string $actionLabel = ''): string {
    $council  = getSystemSetting('council_name') ?? 'Kijura Town Council';
    $appUrl   = defined('APP_URL') ? APP_URL : '';
    $btn      = $actionUrl ? "
        <div style='text-align:center;margin:24px 0;'>
            <a href='$actionUrl' style='background:#1a3a5c;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:700;font-size:14px;display:inline-block;'>$actionLabel</a>
        </div>" : '';
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width'></head>
<body style='margin:0;padding:0;background:#f4f6f9;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f4f6f9;padding:30px 0;'>
<tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.12);'>
  <!-- Header -->
  <tr><td style='background:#1a3a5c;padding:24px 32px;border-bottom:4px solid #c8a84b;'>
    <table width='100%'><tr>
      <td style='width:50px;'><div style='width:44px;height:44px;background:#c8a84b;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;font-size:16px;font-weight:900;color:#1a3a5c;text-align:center;line-height:44px;'>TC</div></td>
      <td style='padding-left:14px;'><div style='color:#fff;font-size:15px;font-weight:700;'>$council</div><div style='color:rgba(255,255,255,.65);font-size:11px;margin-top:2px;'>Management System</div></td>
    </tr></table>
  </td></tr>
  <!-- Title -->
  <tr><td style='background:#f8f9fb;padding:20px 32px;border-bottom:1px solid #e9ecef;'>
    <h2 style='margin:0;color:#1a3a5c;font-size:17px;font-weight:800;'>$title</h2>
  </td></tr>
  <!-- Body -->
  <tr><td style='padding:28px 32px;color:#333;font-size:14px;line-height:1.7;'>
    $content
    $btn
  </td></tr>
  <!-- Footer -->
  <tr><td style='background:#f8f9fb;padding:16px 32px;border-top:1px solid #e9ecef;font-size:11px;color:#888;'>
    This is an automated notification from $council Management System.<br>
    Do not reply to this email. Generated: " . date('d/m/Y H:i') . "
  </td></tr>
</table>
</td></tr></table>
</body></html>";
}

// ── Specific email triggers ───────────────────────────────────────

function emailVoucherSubmitted(array $voucher, array $preparer): void {
    require_once __DIR__ . '/functions.php';
    if (getSystemSetting('email_enabled') !== '1') return;

    $db = getDB();
    // Get HOD email(s) for this department
    $hods = $db->prepare("SELECT u.email, u.full_name FROM users u JOIN roles r ON u.role_id=r.id
        WHERE r.slug='hod' AND u.department_id=? AND u.is_active=1 AND u.email IS NOT NULL AND u.email != ''");
    $hods->execute([$voucher['department_id']]);
    foreach ($hods->fetchAll() as $hod) {
        $appUrl = defined('APP_URL') ? APP_URL : '';
        $html = emailTemplate(
            'Payment Voucher Awaiting Your Approval',
            "<p>Dear {$hod['full_name']},</p>
            <p>A payment voucher has been submitted for your approval.</p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0;font-size:13px;'>
              <tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;width:40%;border:1px solid #dee2e6;'>Voucher No.</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$voucher['voucher_number']}</td></tr>
              <tr><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Payee</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$voucher['payee_name']}</td></tr>
              <tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Amount</td><td style='padding:8px 12px;border:1px solid #dee2e6;font-weight:700;'>UGX " . number_format($voucher['amount']) . "</td></tr>
              <tr><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Description</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>" . htmlspecialchars(substr($voucher['description'], 0, 150)) . "</td></tr>
              <tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Prepared By</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$preparer['full_name']}</td></tr>
              <tr><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Date</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>" . date('d/m/Y') . "</td></tr>
            </table>
            <p>Please log in to review and approve this voucher.</p>",
            "$appUrl/modules/expenditure/voucher_view.php?id={$voucher['id']}",
            'Review & Approve Voucher'
        );
        queueEmail($hod['email'], $hod['full_name'],
            "Action Required: Voucher {$voucher['voucher_number']} Awaiting Approval",
            $html, '', 'voucher', $voucher['id']);
    }
}

function emailVoucherHodApproved(array $voucher, array $approver): void {
    if (getSystemSetting('email_enabled') !== '1') return;
    $db  = getDB();
    $tcs = $db->query("SELECT u.email, u.full_name FROM users u JOIN roles r ON u.role_id=r.id WHERE r.slug='town_clerk' AND u.is_active=1 AND u.email IS NOT NULL AND u.email != ''")->fetchAll();
    $appUrl = defined('APP_URL') ? APP_URL : '';
    foreach ($tcs as $tc) {
        $html = emailTemplate(
            'Voucher Approved by HOD — Awaiting Town Clerk Approval',
            "<p>Dear {$tc['full_name']},</p>
            <p>Payment voucher <strong>{$voucher['voucher_number']}</strong> has been approved by the Head of Department and is now awaiting your approval.</p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0;font-size:13px;'>
              <tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;width:40%;'>Voucher No.</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$voucher['voucher_number']}</td></tr>
              <tr><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Department</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$voucher['dept_name']}</td></tr>
              <tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Amount</td><td style='padding:8px 12px;border:1px solid #dee2e6;font-weight:700;color:#1a3a5c;'>UGX " . number_format($voucher['amount']) . "</td></tr>
              <tr><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>HOD Approved By</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$approver['full_name']}</td></tr>
            </table>",
            "$appUrl/modules/expenditure/voucher_view.php?id={$voucher['id']}",
            'Review & Approve Voucher'
        );
        queueEmail($tc['email'], $tc['full_name'],
            "Action Required: Voucher {$voucher['voucher_number']} — Town Clerk Approval",
            $html, '', 'voucher', $voucher['id']);
    }
}

function emailVoucherTcApproved(array $voucher, array $approver): void {
    if (getSystemSetting('email_enabled') !== '1') return;
    $db      = getDB();
    $finance = $db->query("SELECT u.email, u.full_name FROM users u JOIN roles r ON u.role_id=r.id WHERE r.slug='finance_officer' AND u.is_active=1 AND u.email IS NOT NULL AND u.email != ''")->fetchAll();
    $appUrl  = defined('APP_URL') ? APP_URL : '';
    foreach ($finance as $fo) {
        $html = emailTemplate(
            'Voucher Approved by Town Clerk — Awaiting Finance Verification',
            "<p>Dear {$fo['full_name']},</p>
            <p>Payment voucher <strong>{$voucher['voucher_number']}</strong> has been approved by the Town Clerk and is now awaiting your finance verification.</p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0;font-size:13px;'>
              <tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;width:40%;'>Voucher No.</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$voucher['voucher_number']}</td></tr>
              <tr><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Department</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$voucher['dept_name']}</td></tr>
              <tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Amount</td><td style='padding:8px 12px;border:1px solid #dee2e6;font-weight:700;color:#1a3a5c;'>UGX " . number_format($voucher['amount']) . "</td></tr>
              <tr><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Town Clerk</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$approver['full_name']}</td></tr>
            </table>",
            "$appUrl/modules/expenditure/voucher_view.php?id={$voucher['id']}",
            'Verify Voucher'
        );
        queueEmail($fo['email'], $fo['full_name'],
            "Action Required: Voucher {$voucher['voucher_number']} — Finance Verification",
            $html, '', 'voucher', $voucher['id']);
    }
}

function emailVoucherPaid(array $voucher, array $preparer): void {
    if (getSystemSetting('email_enabled') !== '1') return;
    if (empty($preparer['email'])) return;
    $appUrl = defined('APP_URL') ? APP_URL : '';
    $html = emailTemplate(
        'Payment Processed — Voucher Paid',
        "<p>Dear {$preparer['full_name']},</p>
        <p>Your payment voucher has been processed and payment has been made.</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;font-size:13px;'>
          <tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;width:40%;'>Voucher No.</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$voucher['voucher_number']}</td></tr>
          <tr><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Payee</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$voucher['payee_name']}</td></tr>
          <tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Amount Paid</td><td style='padding:8px 12px;border:1px solid #dee2e6;font-weight:700;color:#1e7e34;'>UGX " . number_format($voucher['amount']) . "</td></tr>
          <tr><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Payment Date</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>" . date('d/m/Y') . "</td></tr>
          " . (!empty($voucher['payment_reference']) ? "<tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Payment Reference</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$voucher['payment_reference']}</td></tr>" : '') . "
        </table>
        <p style='color:#1e7e34;font-weight:700;'>✓ This voucher has been fully processed.</p>",
        "$appUrl/modules/expenditure/voucher_view.php?id={$voucher['id']}",
        'View Voucher'
    );
    queueEmail($preparer['email'], $preparer['full_name'],
        "Payment Processed: Voucher {$voucher['voucher_number']} — UGX " . number_format($voucher['amount']),
        $html, '', 'voucher', $voucher['id']);
}

function emailVoucherReturned(array $voucher, array $preparer, string $returnNote): void {
    if (getSystemSetting('email_enabled') !== '1') return;
    if (empty($preparer['email'])) return;
    $appUrl = defined('APP_URL') ? APP_URL : '';
    $html = emailTemplate(
        'Voucher Returned for Correction',
        "<p>Dear {$preparer['full_name']},</p>
        <p>Your payment voucher <strong>{$voucher['voucher_number']}</strong> has been <strong style='color:#bd2130;'>returned for corrections</strong>.</p>
        <div style='background:#fff3cd;border-left:4px solid #d39e00;padding:12px 16px;border-radius:0 6px 6px 0;margin:16px 0;'>
            <strong>Reason for Return:</strong><br>
            " . htmlspecialchars($returnNote) . "
        </div>
        <p>Please log in, make the required corrections, and resubmit the voucher.</p>",
        "$appUrl/modules/expenditure/voucher_view.php?id={$voucher['id']}",
        'Correct & Resubmit Voucher'
    );
    queueEmail($preparer['email'], $preparer['full_name'],
        "Action Required: Voucher {$voucher['voucher_number']} Returned for Correction",
        $html, '', 'voucher', $voucher['id']);
}

function emailRequisitionStatusChange(array $req, array $notifyUser, string $status, string $comment = ''): void {
    if (getSystemSetting('email_enabled') !== '1') return;
    if (empty($notifyUser['email'])) return;
    $appUrl = defined('APP_URL') ? APP_URL : '';
    $statusLabels = [
        'submitted'    => 'Submitted — Awaiting HOD Approval',
        'hod_approved' => 'Approved by HOD — Awaiting Town Clerk',
        'tc_approved'  => 'Approved by Town Clerk — Proceeding to LPO',
        'lpo_issued'   => 'LPO Issued',
        'delivered'    => 'Delivery Confirmed',
        'invoiced'     => 'Invoice Received',
        'rejected'     => 'Rejected',
        'cancelled'    => 'Cancelled',
    ];
    $label = $statusLabels[$status] ?? ucwords(str_replace('_', ' ', $status));
    $isRejected = in_array($status, ['rejected','cancelled']);

    $html = emailTemplate(
        "Requisition $label",
        "<p>Dear {$notifyUser['full_name']},</p>
        <p>The status of requisition <strong>{$req['req_number']}</strong> has been updated.</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0;font-size:13px;'>
          <tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;width:40%;'>Requisition No.</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>{$req['req_number']}</td></tr>
          <tr><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Title</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>" . htmlspecialchars($req['title']) . "</td></tr>
          <tr style='background:#f8f9fa;'><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>New Status</td><td style='padding:8px 12px;border:1px solid #dee2e6;font-weight:700;color:" . ($isRejected ? '#bd2130' : '#1a3a5c') . ";'>$label</td></tr>
          <tr><td style='padding:8px 12px;font-weight:700;border:1px solid #dee2e6;'>Est. Amount</td><td style='padding:8px 12px;border:1px solid #dee2e6;'>UGX " . number_format($req['estimated_amount']) . "</td></tr>
        </table>
        " . ($comment ? "<div style='background:#f8f9fa;border-left:4px solid #1a3a5c;padding:10px 14px;border-radius:0 6px 6px 0;margin:8px 0;'><strong>Comment:</strong> " . htmlspecialchars($comment) . "</div>" : ""),
        "$appUrl/modules/expenditure/requisitions.php",
        'View Requisition'
    );
    queueEmail($notifyUser['email'], $notifyUser['full_name'],
        "Requisition {$req['req_number']}: $label",
        $html, '', 'requisition', $req['id']);
}
