<?php
/**
 * TenantActivity — central feed of shop (tenant) activity for the Platform Owner.
 *
 * Called from inside TENANT requests (login, points award, LINE connect …) to:
 *   1) append a row to the platform DB `tenant_activity_log`, and
 *   2) (optionally) fire a Telegram alert via SiteNotifier.
 *
 * Best-effort by design: every failure is swallowed to error_log so it can NEVER
 * break the host flow (a login / points award must succeed even if logging fails).
 *
 * Usage:
 *   TenantActivity::log($tenantId, 'login', 'somchai@shop.com', 'เข้าสู่ระบบ');
 *   TenantActivity::log($tid, 'points_award', $customer, '+100 แต้ม', true, 300); // throttle TG 5 min
 */
declare(strict_types=1);

class TenantActivity
{
    private const LABEL = [
        'login'        => ['เข้าสู่ระบบ', '🔑'],
        'points_award' => ['แจกแต้ม', '⭐'],
        'line_connect' => ['เชื่อม LINE', '💬'],
    ];

    /**
     * @param int    $tenantId    tenants.id
     * @param string $event       event_type (login|points_award|line_connect|…)
     * @param string $actor       who did it (admin email/name or customer)
     * @param string $detail      short human detail
     * @param bool   $notify      also send a Telegram alert
     * @param int    $throttleSec if >0, skip the Telegram when a notified row of the
     *                            same tenant+event exists within this many seconds
     *                            (collapses bursts of high-frequency events; the row
     *                            is still written to the feed).
     */
    public static function log(
        int $tenantId,
        string $event,
        string $actor = '',
        string $detail = '',
        bool $notify = true,
        int $throttleSec = 0
    ): void {
        if ($tenantId <= 0 || $event === '') {
            return;
        }
        try {
            $pdo = Database::platform()->getConnection();
            self::ensureTable($pdo);

            // throttle decision (per tenant+event window)
            $sendTg = $notify;
            if ($sendTg && $throttleSec > 0) {
                $chk = $pdo->prepare(
                    'SELECT 1 FROM tenant_activity_log
                      WHERE tenant_id = ? AND event_type = ? AND notified = 1
                        AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND) LIMIT 1'
                );
                $chk->execute([$tenantId, $event, $throttleSec]);
                if ($chk->fetchColumn()) {
                    $sendTg = false;
                }
            }

            $pdo->prepare(
                'INSERT INTO tenant_activity_log (tenant_id, event_type, actor, detail, notified, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            )->execute([
                $tenantId, $event,
                ($actor !== '' ? substr($actor, 0, 150) : null),
                ($detail !== '' ? substr($detail, 0, 255) : null),
                $sendTg ? 1 : 0,
            ]);

            if ($sendTg) {
                self::telegram($pdo, $tenantId, $event, $actor, $detail);
            }
        } catch (\Throwable $e) {
            error_log('[TenantActivity] ' . $e->getMessage());
        }
    }

    private static function telegram(\PDO $pdo, int $tenantId, string $event, string $actor, string $detail): void
    {
        try {
            $file = __DIR__ . '/SiteNotifier.php';
            if (!is_file($file)) {
                return;
            }
            require_once $file;

            $shop = '';
            try {
                $st = $pdo->prepare('SELECT display_name FROM tenants WHERE id = ? LIMIT 1');
                $st->execute([$tenantId]);
                $shop = (string) ($st->fetchColumn() ?: '');
            } catch (\Throwable $e) { /* ignore */ }

            [$label, $icon] = self::LABEL[$event] ?? [$event, '🔔'];
            $e = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            $msg = "{$icon} <b>{$e($shop)}</b> · {$e($label)}\n"
                 . ($detail !== '' ? "📝 {$e($detail)}\n" : '')
                 . ($actor !== '' ? "👤 {$e($actor)}\n" : '')
                 . "🆔 ร้าน #{$tenantId} · " . date('H:i');
            SiteNotifier::sendTelegram($msg);
        } catch (\Throwable $e) {
            error_log('[TenantActivity] telegram: ' . $e->getMessage());
        }
    }

    private static function ensureTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tenant_activity_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT NOT NULL,
                event_type VARCHAR(40) NOT NULL,
                actor VARCHAR(150) NULL,
                detail VARCHAR(255) NULL,
                notified TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                KEY idx_tal_created (created_at),
                KEY idx_tal_tenant_event (tenant_id, event_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** Best-effort tenant id from the current request context. */
    public static function currentTenantId(): int
    {
        if (class_exists('TenantContext')) {
            $tid = \TenantContext::getCurrentTenantId();
            if ($tid) {
                return (int) $tid;
            }
        }
        return (int) ($_SESSION['active_tenant_id'] ?? 0);
    }
}
