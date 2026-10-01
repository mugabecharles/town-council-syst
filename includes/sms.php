<?php
/**
 * TCMS SMS Notification Engine
 * Supports Africa's Talking (primary) + direct HTTP fallback.
 * All SMS go through queue for reliability and audit.
 */

require_once __DIR__ . '/../config/database.php';

// ── Queue an SMS ──────────────────────────────────────────────────

function queueSms(
    string $phone,
    string $message,
    string $recipientName = '',
    string $module = '',
    int    $relatedId = 0
): bool {
    try {
        $phone = normalizeMsisdn($phone);
        if (!$phone) return false;
        $db = getDB();
        $db->prepare("INSERT INTO sms_queue
            (phone, recipient_name, message, related_module, related_id, status)
            VALUES (?,?,?,?,?,'pending')")
           ->execute([$phone, $recipientName, $message, $module, $relatedId]);
        return true;
    } catch (Exception $e) {
        error_log("SMS queue failed: " . $e->getMessage());
        return false;
    }
}

// ── Process queue (send pending SMS) ─────────────────────────────

function processSmsQueue(int $limit = 10): array {
    $results = ['sent' => 0, 'failed' => 0];
    try {
        $db = getDB();
        if ((getSystemSetting('sms_enabled') ?? '0') !== '1') return $results;

        $rows = $db->query("SELECT * FROM sms_queue WHERE status='pending' AND attempts < 3 ORDER BY created_at ASC LIMIT $limit")->fetchAll();

        foreach ($rows as $sms) {
            $db->prepare("UPDATE sms_queue SET attempts=attempts+1, last_attempt=NOW() WHERE id=?")->execute([$sms['id']]);
            $result = sendSmsNow($sms['phone'], $sms['message']);
            if ($result['success']) {
                $db->prepare("UPDATE sms_queue SET status='sent', sent_at=NOW(), gateway_ref=?, cost=? WHERE id=?")
                   ->execute([$result['ref'] ?? '', $result['cost'] ?? 0, $sms['id']]);
                $results['sent']++;
            } else {
                $db->prepare("UPDATE sms_queue SET error_message=? WHERE id=?")
                   ->execute([$result['error'] ?? 'Unknown error', $sms['id']]);
                if ((int)$sms['attempts'] >= 2) {
                    $db->prepare("UPDATE sms_queue SET status='failed' WHERE id=?")->execute([$sms['id']]);
                }
                $results['failed']++;
            }
        }
    } catch (Exception $e) {
        error_log("SMS queue processing failed: " . $e->getMessage());
    }
    return $results;
}

// ── Send via Africa's Talking API ────────────────────────────────

function sendSmsNow(string $phone, string $message): array {
    $gateway  = getSystemSetting('sms_gateway')   ?? 'africastalking';
    $apiKey   = getSystemSetting('sms_api_key')   ?? '';
    $username = getSystemSetting('sms_username')  ?? 'sandbox';
    $senderId = getSystemSetting('sms_sender_id') ?? '';

    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'SMS API key not configured'];
    }

    // Africa's Talking API
    $url  = $username === 'sandbox'
          ? 'https://api.sandbox.africastalking.com/version1/messaging'
          : 'https://api.africastalking.com/version1/messaging';

    $params = http_build_query([
        'username' => $username,
        'to'       => $phone,
        'message'  => $message,
        'from'     => $senderId ?: null,
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n" .
                         "apiKey: $apiKey\r\n" .
                         "Accept: application/json\r\n",
            'content' => $params,
            'timeout' => 15,
        ]
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        return ['success' => false, 'error' => 'HTTP request failed'];
    }

    $data = json_decode($response, true);
    $recipients = $data['SMSMessageData']['Recipients'] ?? [];

    if (!empty($recipients) && $recipients[0]['status'] === 'Success') {
        return [
            'success' => true,
            'ref'     => $recipients[0]['messageId'] ?? '',
            'cost'    => (float)ltrim($recipients[0]['cost'] ?? '0', 'KES '),
        ];
    }

    $errMsg = $data['SMSMessageData']['Message'] ?? ($recipients[0]['status'] ?? 'Unknown');
    return ['success' => false, 'error' => $errMsg];
}

// ── Phone normalization ───────────────────────────────────────────

function normalizeMsisdn(string $phone): string {
    $phone   = preg_replace('/[^0-9+]/', '', $phone);
    $country = getSystemSetting('sms_country_code') ?? '256';

    // Already has country code
    if (substr($phone, 0, 1) === '+') return $phone;
    if (substr($phone, 0, strlen($country)) === $country) return '+' . $phone;

    // Remove leading zero, add country code
    $phone = ltrim($phone, '0');
    return '+' . $country . $phone;
}

// ── Message templates ─────────────────────────────────────────────

function smsPaymentConfirmation(string $phone, string $name, float $amount, string $receiptNo, string $source): void {
    if ((getSystemSetting('sms_enabled') ?? '0') !== '1') return;
    $council = getSystemSetting('council_name') ?? 'Kijura TC';
    $msg = "Dear $name, your payment of UGX " . number_format($amount, 0) .
           " for $source has been received. Receipt: $receiptNo. Thank you - $council.";
    queueSms($phone, $msg, $name, 'revenue');
}

function smsArrearsReminder(string $phone, string $name, float $balance, string $source, string $dueDate = ''): void {
    if ((getSystemSetting('sms_enabled') ?? '0') !== '1') return;
    $council = getSystemSetting('council_name') ?? 'Kijura TC';
    $due     = $dueDate ? " Due: $dueDate." : '';
    $msg = "Dear $name, you have an outstanding balance of UGX " . number_format($balance, 0) .
           " for $source.$due Please pay at $council offices to avoid penalties.";
    queueSms($phone, $msg, $name, 'revenue');
}

function smsVoucherApproval(string $phone, string $name, string $voucherNo, string $status, string $comment = ''): void {
    if ((getSystemSetting('sms_enabled') ?? '0') !== '1') return;
    $council = getSystemSetting('council_name') ?? 'Kijura TC';
    $cmt     = $comment ? " Comment: $comment." : '';
    $msg = "$council: Voucher $voucherNo has been $status.$cmt Please log in to the system for details.";
    queueSms($phone, $msg, $name, 'expenditure');
}

function smsBudgetAlert(string $phone, string $name, string $deptName, float $pct): void {
    if ((getSystemSetting('sms_budget_alert') ?? '0') !== '1') return;
    $council = getSystemSetting('council_name') ?? 'Kijura TC';
    $level   = $pct >= 100 ? 'EXCEEDED (100%)' : "at {$pct}%";
    $msg = "$council ALERT: $deptName budget is $level. Immediate action required. Log in for details.";
    queueSms($phone, $msg, $name, 'budget');
}

function smsOtpCode(string $phone, string $name, string $otp): void {
    $council = getSystemSetting('council_name') ?? 'Kijura TC';
    $msg = "$council: Your login verification code is $otp. Valid for 10 minutes. Do not share this code.";
    queueSms($phone, $msg, $name, 'auth');
    // Also attempt immediate send for OTP
    processSmsQueue(1);
}

// ── SMS stats ─────────────────────────────────────────────────────

function getSmsStats(): array {
    try {
        $db   = getDB();
        $rows = $db->query("SELECT status, COUNT(*) cnt FROM sms_queue GROUP BY status")->fetchAll();
        $map  = array_column($rows, 'cnt', 'status');
        return [
            'pending' => (int)($map['pending'] ?? 0),
            'sent'    => (int)($map['sent']    ?? 0),
            'failed'  => (int)($map['failed']  ?? 0),
            'total'   => array_sum($map),
        ];
    } catch (Exception $e) {
        return ['pending' => 0, 'sent' => 0, 'failed' => 0, 'total' => 0];
    }
}
