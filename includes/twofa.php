<?php
/**
 * TCMS Two-Factor Authentication (2FA)
 * Generates and verifies email OTP codes for senior users.
 * Roles that require 2FA are configurable in system_settings.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/email.php';

// ── Check if user's role requires 2FA ─────────────────────────────

function requires2FA(array $user): bool {
    if ((getSystemSetting('two_fa_enabled') ?? '0') !== '1') return false;
    $roles = array_map('trim', explode(',', getSystemSetting('two_fa_roles') ?? 'admin,town_clerk,finance_officer'));
    return in_array($user['role_slug'], $roles);
}

// ── Generate a 6-digit OTP and store it ───────────────────────────

function generate2FAToken(int $userId, string $purpose = 'login'): string {
    $db      = getDB();
    $token   = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiry  = (int)(getSystemSetting('otp_expiry_minutes') ?? '10');
    $expires = date('Y-m-d H:i:s', time() + $expiry * 60);

    // Invalidate any previous unused tokens for this user + purpose
    $db->prepare("UPDATE otp_tokens SET is_used=1 WHERE user_id=? AND purpose=? AND is_used=0")
       ->execute([$userId, $purpose]);

    $db->prepare("INSERT INTO otp_tokens (user_id, token, purpose, ip_address, expires_at)
        VALUES (?,?,?,?,?)")
       ->execute([$userId, $token, $purpose, getClientIP(), $expires]);

    return $token;
}

// ── Send OTP via email ─────────────────────────────────────────────

function send2FAEmail(array $user, string $otp): void {
    $council = getSystemSetting('council_name') ?? 'Kijura Town Council';
    $expiry  = getSystemSetting('otp_expiry_minutes') ?? '10';
    $appUrl  = defined('APP_URL') ? APP_URL : '';

    $html = emailTemplate(
        'Your Login Verification Code',
        "<p>Dear {$user['full_name']},</p>
        <p>A login attempt was made to your <strong>{$council} Management System</strong> account.</p>
        <p>Your one-time verification code is:</p>
        <div style='text-align:center;margin:24px 0;'>
            <div style='display:inline-block;background:#1a3a5c;color:#fff;font-size:36px;font-weight:900;
                        letter-spacing:12px;padding:16px 32px;border-radius:8px;border-bottom:4px solid #c8a84b;'>
                {$otp}
            </div>
        </div>
        <p style='text-align:center;color:#6c757d;font-size:12px;'>This code expires in <strong>{$expiry} minutes</strong>.</p>
        <div style='background:#fff3cd;border-left:4px solid #d39e00;padding:10px 14px;border-radius:0 6px 6px 0;margin-top:16px;font-size:13px;'>
            <strong>Security notice:</strong> If you did not attempt to log in, your account may be at risk.
            Contact your system administrator immediately.
        </div>"
    );

    queueEmail($user['email'], $user['full_name'],
        "$council: Login Verification Code — $otp",
        $html, "Your verification code is $otp. Expires in $expiry minutes.", 'auth');

    processEmailQueue(2);
}

// ── Verify submitted OTP ───────────────────────────────────────────

function verify2FAToken(int $userId, string $submittedToken, string $purpose = 'login'): array {
    $db    = getDB();
    $token = $db->prepare("SELECT * FROM otp_tokens
        WHERE user_id=? AND purpose=? AND is_used=0 AND expires_at > NOW()
        ORDER BY created_at DESC LIMIT 1");
    $token->execute([$userId, $purpose]);
    $row = $token->fetch();

    if (!$row) {
        return ['valid' => false, 'error' => 'No valid code found. Please request a new code.'];
    }

    if (!hash_equals($row['token'], trim($submittedToken))) {
        // Check attempts — if token used 3 times wrong, invalidate it
        $db->prepare("UPDATE otp_tokens SET attempts = attempts + 1 WHERE id=?")->execute([$row['id']]);
        $db->prepare("UPDATE otp_tokens SET is_used=1 WHERE id=? AND attempts >= 3")->execute([$row['id']]);
        return ['valid' => false, 'error' => 'Invalid verification code. Please check and try again.'];
    }

    // Mark token as used
    $db->prepare("UPDATE otp_tokens SET is_used=1, used_at=NOW() WHERE id=?")->execute([$row['id']]);
    return ['valid' => true];
}
