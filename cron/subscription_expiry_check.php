<?php
/**
 * cron/subscription_expiry_check.php — daily subscription trial/renewal sweep (T5.2).
 *
 * Billing state is DATE-DERIVED (no stored status). Each daily run:
 *   - sends trial/renewal reminders at fixed day-offsets (T-3, T-1, T0 before due;
 *     T+1, T+3, T+7 after due) to the billing/owner email
 *   - auto-suspends tenants past the grace window ONLY when
 *     tenant_subscriptions.auto_suspend_enabled = 1
 *   - writes a best-effort super_admin_audit row for any suspend
 *
 * Idempotent: reminders are keyed to EXACT day-offsets, so a once-daily cron sends
 * each reminder exactly once. CLI only.
 *
 * Suggested crontab (server):
 *   15 8 * * *  /usr/local/bin/php /home/zrismpsz/public_html/cron/subscription_expiry_check.php >> /home/zrismpsz/public_html/logs/subscription_expiry.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/platform-billing-helpers.php';
require_once __DIR__ . '/../classes/TenantProvisioning.php';
require_once __DIR__ . '/../classes/EmailService.php';

$today     = date('Y-m-d');
$graceDays = defined('SUBSCRIPTION_GRACE_DAYS') ? (int) SUBSCRIPTION_GRACE_DAYS : 7;

$REMIND_BEFORE = [3, 1, 0]; // days before anchor
$REMIND_AFTER  = [1, 3, 7]; // days after anchor

try {
    $platformDb = Database::platform()->getConnection();
} catch (\Throwable $e) {
    fwrite(STDERR, '[subscription_expiry] platform DB unreachable: ' . $e->getMessage() . "\n");
    exit(1);
}

$mailer = new EmailService($platformDb);

try {
    $rows = $platformDb->query(
        "SELECT t.id, t.slug, t.display_name, t.owner_email, t.owner_name, t.status,
                s.trial_ends_at, s.next_due_date, s.last_paid_date, s.amount_thb,
                s.billing_cycle, s.billing_contact_email, s.auto_suspend_enabled
           FROM tenants t
           JOIN tenant_subscriptions s ON s.tenant_id = t.id
          WHERE t.status <> 'terminated'"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    fwrite(STDERR, '[subscription_expiry] query failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$checked = 0; $reminders = 0; $suspended = 0;

foreach ($rows as $r) {
    $checked++;
    $tid = (int) $r['id'];

    $state = function_exists('subscriptionState')
        ? subscriptionState($platformDb, $tid, $today)
        : null;

    // Trial while a future trial_ends_at exists and nothing has been paid yet.
    $isTrial = (($state['state'] ?? '') === 'trial')
        || (empty($r['last_paid_date']) && !empty($r['trial_ends_at']));

    // Anchor: trial end while trialing, otherwise the next due date.
    $anchor = $isTrial ? ($r['trial_ends_at'] ?: $r['next_due_date']) : $r['next_due_date'];
    if (!$anchor) {
        continue;
    }

    $daysLeft = (int) floor((strtotime($anchor . ' 00:00:00') - strtotime($today . ' 00:00:00')) / 86400);
    $to       = trim((string) ($r['billing_contact_email'] ?: $r['owner_email']));

    // --- reminders ---
    $shouldRemind = ($daysLeft >= 0 && in_array($daysLeft, $REMIND_BEFORE, true))
                 || ($daysLeft < 0 && in_array(-$daysLeft, $REMIND_AFTER, true));
    if ($shouldRemind && $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        if (reya_send_billing_reminder($mailer, $r, $isTrial, $daysLeft)) {
            $reminders++;
            echo "[remind] tenant #{$tid} ({$r['slug']}) — {$daysLeft}d (" . ($isTrial ? 'trial' : 'renewal') . ")\n";
        }
    }

    // --- auto-suspend past grace (opt-in per tenant) ---
    $pastGrace = $daysLeft < -$graceDays;
    if ($pastGrace && (int) $r['auto_suspend_enabled'] === 1 && $r['status'] === 'active') {
        try {
            TenantProvisioning::suspend($tid);
            reya_audit_expiry_suspend($platformDb, $tid, $daysLeft);
            $suspended++;
            echo "[suspend] tenant #{$tid} ({$r['slug']}) — {$daysLeft}d past due\n";
        } catch (\Throwable $e) {
            error_log('[subscription_expiry suspend] tenant ' . $tid . ': ' . $e->getMessage());
        }
    }
}

echo sprintf(
    "[subscription_expiry] %s — checked=%d reminders=%d suspended=%d\n",
    $today, $checked, $reminders, $suspended
);

// ---------------------------------------------------------------------------
// File-local helpers (named functions are hoisted; safe to declare below use)
// ---------------------------------------------------------------------------

/**
 * Send a Thai trial/renewal reminder. Returns true on send. Never throws.
 */
function reya_send_billing_reminder(EmailService $mailer, array $r, bool $isTrial, int $daysLeft): bool
{
    try {
        $slug   = (string) $r['slug'];
        $shop   = (string) $r['display_name'];
        $amount = number_format((float) $r['amount_thb'], 0);
        $to     = trim((string) ($r['billing_contact_email'] ?: $r['owner_email']));
        $url    = "https://{$slug}.re-ya.com/billing.php";
        $e      = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        if ($daysLeft > 0) {
            $headline = $isTrial
                ? "ทดลองใช้ REYA เหลืออีก {$daysLeft} วัน"
                : "ครบกำหนดชำระค่าบริการอีก {$daysLeft} วัน";
        } elseif ($daysLeft === 0) {
            $headline = $isTrial ? 'วันนี้เป็นวันสุดท้ายของการทดลองใช้' : 'วันนี้ครบกำหนดชำระค่าบริการ';
        } else {
            $headline = 'เลยกำหนดชำระค่าบริการแล้ว — กรุณาชำระเพื่อใช้งานต่อ';
        }

        $bank = '';
        if (defined('SUBSCRIPTION_BANK_NAME') && SUBSCRIPTION_BANK_NAME !== '') {
            $bank = '<p style="margin:6px 0;font-size:14px">โอนเข้า: <strong>' . $e(SUBSCRIPTION_BANK_NAME)
                  . '</strong> ' . $e(defined('SUBSCRIPTION_BANK_ACCT_NO') ? SUBSCRIPTION_BANK_ACCT_NO : '')
                  . ' (' . $e(defined('SUBSCRIPTION_BANK_ACCT_NAME') ? SUBSCRIPTION_BANK_ACCT_NAME : '') . ')</p>';
        }

        $subject = "REYA · {$headline} — ร้าน {$shop}";
        $body = '<div style="font-family:Sarabun,Arial,sans-serif;max-width:540px;margin:0 auto;color:#0f172a">'
            . '<div style="background:linear-gradient(135deg,#064e3b,#059669);padding:22px;border-radius:14px 14px 0 0;color:#fff">'
            . '<h2 style="margin:0;font-size:18px">' . $e($headline) . '</h2></div>'
            . '<div style="border:1px solid #e2e8f0;border-top:none;border-radius:0 0 14px 14px;padding:20px">'
            . '<p>ร้าน <strong>' . $e($shop) . '</strong></p>'
            . '<p style="font-size:15px">ยอดค่าบริการรอบนี้: <strong>' . $e($amount) . ' บาท</strong></p>'
            . $bank
            . '<p style="margin:16px 0"><a href="' . $e($url) . '" style="background:#059669;color:#fff;'
            . 'text-decoration:none;padding:10px 18px;border-radius:10px;font-weight:600">ชำระเงิน / อัปโหลดสลิป</a></p>'
            . '<p style="font-size:12px;color:#94a3b8">REYA Platform · re-ya.com</p>'
            . '</div></div>';

        return (bool) $mailer->send($to, $subject, $body, true);
    } catch (\Throwable $ex) {
        error_log('[subscription_expiry reminder] ' . $ex->getMessage());
        return false;
    }
}

/**
 * Best-effort audit row for an auto-suspend. Never throws.
 */
function reya_audit_expiry_suspend(\PDO $db, int $tenantId, int $daysLeft): void
{
    try {
        $stmt = $db->prepare(
            'INSERT INTO super_admin_audit
                (platform_user_id, tenant_id, action, ip_address, user_agent,
                 request_method, request_uri, metadata, created_at)
             VALUES (NULL, ?, ?, NULL, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $tenantId,
            'auto_suspend_overdue',
            'cron/subscription_expiry_check.php',
            'CLI',
            'cron',
            json_encode(['days_past_due' => abs($daysLeft)], JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $e) {
        error_log('[subscription_expiry audit] ' . $e->getMessage());
    }
}
