<?php
/**
 * cron/adherence_reminder.php — per-dispense medication-adherence (days-supply
 * runout) reminder (Phase 2).
 *
 * Distinct from cron/reorder_reminder.php (which predicts a customer's next
 * *visit* from their average repurchase interval across many past orders).
 * This job looks at each individual dispense's quantity + daily dosage,
 * computes the day that specific supply runs out via AdherenceReminder, and
 * sends a "ใกล้ยาหมด — เติมยา" LINE Flex reminder a few days BEFORE that
 * runout date.
 *
 * Data source: `medication_refill_tracking` (one row per user+product,
 * updated on every dispense by classes/RefillTrackingHelper::trackFromDispense()
 * — see inbox-v2.php / messages.php dispense flow). This job re-derives the
 * runout date from quantity_purchased / daily_dosage via
 * AdherenceReminder::computeRunout() (rather than trusting the stored
 * estimated_end_date column, which a different, single-tenant legacy cron
 * — cron/medication_refill_reminder.php — also reads/writes) so this job's
 * pure logic is independently testable and tenant-safe.
 *
 * Run: php cron/adherence_reminder.php
 * Schedule: Daily, e.g. 0 9 * * * (09:00 Asia/Bangkok)
 *
 * Multi-tenant: iterates every active tenant DB via TenantContext, per
 * CLAUDE.md convention. CLI only.
 *
 * Idempotent: sends are recorded in `adherence_reminders_sent` (auto-created,
 * one row per user+product+runout-date) so a re-run / daily cron does not
 * re-notify the same customer for the same runout cycle.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/TenantContext.php';
require_once __DIR__ . '/../classes/AdherenceReminder.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/NotificationGate.php';
require_once __DIR__ . '/../classes/FlexTemplates.php';

/** How many days before the computed runout date to start reminding. */
const ADHERENCE_REMINDER_LEAD_DAYS = 3;

$today = date('Y-m-d');

echo "=== Medication Adherence (Days-Supply Runout) Reminder ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $platformDb = Database::platform()->getConnection();
} catch (\Throwable $e) {
    fwrite(STDERR, '[adherence_reminder] platform DB unreachable: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $tenants = $platformDb->query(
        "SELECT id, slug, db_name FROM tenants WHERE status = 'active'"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    fwrite(STDERR, '[adherence_reminder] tenant list query failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$totalChecked = 0;
$totalNotified = 0;
$totalErrors = 0;

foreach ($tenants as $tenant) {
    $tenantId = (int) $tenant['id'];
    TenantContext::setCurrentTenantId($tenantId);

    try {
        $db = Database::forTenant($tenantId)->getConnection();
    } catch (\Throwable $e) {
        echo "[tenant #{$tenantId} {$tenant['slug']}] ERROR connecting: " . $e->getMessage() . "\n";
        $totalErrors++;
        continue;
    }

    try {
        $result = reya_process_tenant_adherence_reminders($db, $tenantId, $today);
    } catch (\Throwable $e) {
        echo "[tenant #{$tenantId} {$tenant['slug']}] ERROR: " . $e->getMessage() . "\n";
        $totalErrors++;
        continue;
    }

    $totalChecked += $result['checked'];
    $totalNotified += $result['notified'];
    $totalErrors += $result['errors'];

    if ($result['checked'] > 0 || $result['notified'] > 0) {
        echo "[tenant #{$tenantId} {$tenant['slug']}] checked={$result['checked']} "
            . "notified={$result['notified']} errors={$result['errors']}\n";
    }
}

TenantContext::reset();

echo "\n=== Summary ===\n";
echo "Tenants scanned: " . count($tenants) . "\n";
echo "Dispenses checked: {$totalChecked}\n";
echo "Reminders sent: {$totalNotified}\n";
echo "Errors: {$totalErrors}\n";
echo "Done!\n";

/**
 * Process one tenant DB: find dispenses nearing their days-supply runout and
 * send reminders.
 *
 * @return array{checked:int, notified:int, errors:int}
 */
function reya_process_tenant_adherence_reminders(PDO $db, int $tenantId, string $today): array
{
    reya_ensure_adherence_reminders_table($db);

    if (!reya_table_exists($db, 'medication_refill_tracking')) {
        return ['checked' => 0, 'notified' => 0, 'errors' => 0];
    }

    // One row per (user, product) dispense-derived supply, kept fresh by
    // RefillTrackingHelper::trackFromDispense() on every dispense.
    $rows = $db->query(
        "SELECT mrt.id, mrt.user_id, mrt.product_id, mrt.product_name,
                mrt.quantity_purchased, mrt.daily_dosage, mrt.purchase_date,
                u.line_user_id, u.display_name, u.line_account_id,
                la.channel_access_token,
                unp.drug_reminders
           FROM medication_refill_tracking mrt
           JOIN users u ON u.id = mrt.user_id
           LEFT JOIN line_accounts la ON la.id = mrt.line_account_id
           LEFT JOIN user_notification_preferences unp ON unp.user_id = mrt.user_id
          WHERE u.line_user_id IS NOT NULL"
    )->fetchAll(PDO::FETCH_ASSOC);

    $checked = 0;
    $notified = 0;
    $errors = 0;

    foreach ($rows as $row) {
        $checked++;

        if (isset($row['drug_reminders']) && $row['drug_reminders'] !== null && (int) $row['drug_reminders'] === 0) {
            continue; // customer opted out
        }
        if (empty($row['channel_access_token']) || empty($row['line_user_id'])) {
            continue; // no way to message this customer
        }

        $runout = AdherenceReminder::computeRunout(
            $row['quantity_purchased'],
            $row['daily_dosage'],
            $row['purchase_date'] ?: $today
        );
        if ($runout === null) {
            continue; // missing/invalid quantity or dosage — skip, don't guess
        }

        if (!AdherenceReminder::shouldRemindNow($runout, $today, ADHERENCE_REMINDER_LEAD_DAYS)) {
            continue;
        }

        if (reya_already_reminded(
            $db,
            (int) $row['user_id'],
            (int) $row['product_id'],
            $runout['runout_date']
        )) {
            continue;
        }

        $flex = reya_build_adherence_reminder_flex($row['display_name'], $row['product_name'], $runout);

        try {
            $gate = new NotificationGate($db);
            $sent = $gate->send([
                'user_id' => (int) $row['user_id'],
                'line_user_id' => $row['line_user_id'],
                'line_account_id' => $row['line_account_id'] ?? null,
                'channel_access_token' => $row['channel_access_token'],
                'event_type' => 'medication_refill',
                // namespace เดียวกับ cron/medication_refill_reminder.php โดยตั้งใจ
                // (ดูคอมเมนต์ที่นั่น) — กันลูกค้าได้ข้อความ "ยาใกล้หมด" ของยา
                // ตัวเดียวกันซ้ำในวันเดียวจากสอง cron ที่ทำนายคนละวิธี
                'dedupe_key' => 'refill:' . (int) $row['user_id'] . ':' . (int) $row['product_id'] . ':' . date('Y-m-d'),
                'messages' => [$flex],
            ])['sent'];

            if ($sent) {
                reya_record_reminder_sent($db, (int) $row['user_id'], (int) $row['product_id'], $runout['runout_date']);
                $notified++;
            } else {
                $errors++;
            }
        } catch (\Throwable $e) {
            error_log("[adherence_reminder] tenant {$tenantId} user {$row['user_id']} product {$row['product_id']}: " . $e->getMessage());
            $errors++;
        }
    }

    return ['checked' => $checked, 'notified' => $notified, 'errors' => $errors];
}

function reya_table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Idempotency table: one row per (user, product, runout-date). A cron re-run
 * or daily schedule will not re-send for the same computed runout cycle.
 * Separate from the legacy `medication_refill_tracking.reminder_sent_at`
 * column (owned by cron/medication_refill_reminder.php) so the two jobs
 * don't stomp on each other's state.
 */
function reya_ensure_adherence_reminders_table(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS adherence_reminders_sent (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        runout_date DATE NOT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_product_runout (user_id, product_id, runout_date),
        INDEX idx_user (user_id),
        INDEX idx_sent_at (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function reya_already_reminded(PDO $db, int $userId, int $productId, string $runoutDate): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM adherence_reminders_sent
          WHERE user_id = ? AND product_id = ? AND runout_date = ?
          LIMIT 1"
    );
    $stmt->execute([$userId, $productId, $runoutDate]);
    return (bool) $stmt->fetchColumn();
}

function reya_record_reminder_sent(PDO $db, int $userId, int $productId, string $runoutDate): void
{
    $stmt = $db->prepare(
        "INSERT INTO adherence_reminders_sent (user_id, product_id, runout_date)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE sent_at = NOW()"
    );
    $stmt->execute([$userId, $productId, $runoutDate]);
}

/**
 * Build the "ใกล้ยาหมด — เติมยา" (medicine running low — refill) Flex
 * reminder message.
 *
 * @param array{days_supply:int, runout_date:string} $runout
 */
function reya_build_adherence_reminder_flex(?string $displayName, ?string $productName, array $runout): array
{
    $name = $displayName ?: 'คุณลูกค้า';
    $drug = $productName ?: 'ยาของคุณ';
    $runoutDateThai = date('d/m/Y', strtotime($runout['runout_date']));

    $bubble = [
        'type' => 'bubble',
        'size' => 'kilo',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#F59E0B',
            'paddingAll' => '15px',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => '💊', 'size' => 'xxl'],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'contents' => [
                                ['type' => 'text', 'text' => 'ใกล้ยาหมด', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg'],
                                ['type' => 'text', 'text' => $drug, 'color' => '#FFFFFF', 'size' => 'xs', 'margin' => 'xs', 'wrap' => true],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'body' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '15px',
            'contents' => [
                ['type' => 'text', 'text' => "สวัสดีค่ะ {$name}", 'weight' => 'bold', 'size' => 'md', 'wrap' => true],
                [
                    'type' => 'text',
                    'text' => "ยา \"{$drug}\" ของคุณใกล้จะหมดแล้ว กรุณาเติมยาก่อนหมดนะคะ",
                    'size' => 'sm',
                    'color' => '#888888',
                    'wrap' => true,
                    'margin' => 'md',
                ],
                ['type' => 'separator', 'margin' => 'lg'],
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'lg',
                    'contents' => [
                        ['type' => 'text', 'text' => '📅 ยาจะหมดประมาณ', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                        ['type' => 'text', 'text' => $runoutDateThai, 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2],
                    ],
                ],
            ],
        ],
        'footer' => [
            'type' => 'box',
            'layout' => 'vertical',
            'paddingAll' => '15px',
            'contents' => [
                [
                    'type' => 'button',
                    'action' => [
                        'type' => 'message',
                        'label' => '💬 ติดต่อเภสัชกรเพื่อเติมยา',
                        'text' => "ขอเติมยา: {$drug}",
                    ],
                    'style' => 'primary',
                    'color' => '#F59E0B',
                    'height' => 'sm',
                ],
            ],
        ],
    ];

    return FlexTemplates::toMessage($bubble, "💊 ใกล้ยาหมด: {$drug} ({$runoutDateThai})");
}
