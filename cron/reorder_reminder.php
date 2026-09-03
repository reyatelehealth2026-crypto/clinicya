<?php
/**
 * cron/reorder_reminder.php — per-customer reorder-cycle refill reminder (Phase 2).
 *
 * Instead of a fixed 90-day refill window, this looks at each customer's own
 * purchase history in `transactions` (order_number-level "visits", scoped by
 * user_id) and predicts their next reorder date via ReorderCycle::predict().
 * Customers whose predicted next-due-date falls within a small window of
 * today — and who have not already been reminded recently — get a LINE Flex
 * "ถึงเวลาเติมยา" (time to refill) push message.
 *
 * Task 2.5 (Phase 2): the reminder is personalized where possible — the
 * customer's previously-bought products (still active/sellable) are mapped
 * via `ReorderFlexBuilder` into a product carousel with order CTAs, so the
 * customer can reorder the exact item(s) in one tap. Falls back to the
 * original generic "ถึงเวลาเติมยา" Flex when no orderable products can be
 * found (e.g. all previously-bought items were removed/deactivated).
 *
 * Run: php cron/reorder_reminder.php
 * Schedule: Daily, e.g. 0 9 * * * (09:00 Asia/Bangkok)
 *
 * Idempotent: sends are recorded in `reorder_reminders_sent` (auto-created,
 * one row per user per predicted due-date) so a re-run / daily cron does not
 * re-notify the same customer for the same cycle.
 *
 * Multi-tenant: iterates every active tenant DB via TenantContext, per
 * CLAUDE.md convention. CLI only.
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
require_once __DIR__ . '/../classes/ReorderCycle.php';
require_once __DIR__ . '/../classes/ReorderFlexBuilder.php';
require_once __DIR__ . '/../classes/LineAPI.php';
require_once __DIR__ . '/../classes/NotificationGate.php';
require_once __DIR__ . '/../classes/FlexTemplates.php';

/** How many of a customer's previously-bought products to offer in the reorder carousel. */
const REORDER_REMINDER_MAX_PRODUCTS = 5;

/** How many days ahead of / behind the predicted due-date still counts as "due now". */
const REORDER_REMINDER_WINDOW_DAYS = 3;

/** Minimum days between reminders for the same customer, regardless of cycle. */
const REORDER_REMINDER_MIN_GAP_DAYS = 14;

$today = date('Y-m-d');

echo "=== Reorder Cycle Refill Reminder ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

try {
    $platformDb = Database::platform()->getConnection();
} catch (\Throwable $e) {
    fwrite(STDERR, '[reorder_reminder] platform DB unreachable: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $tenants = $platformDb->query(
        "SELECT id, slug, db_name FROM tenants WHERE status = 'active'"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    fwrite(STDERR, '[reorder_reminder] tenant list query failed: ' . $e->getMessage() . "\n");
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
        $result = reya_process_tenant_reorder_reminders($db, $tenantId, $today);
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
echo "Customers checked: {$totalChecked}\n";
echo "Reminders sent: {$totalNotified}\n";
echo "Errors: {$totalErrors}\n";
echo "Done!\n";

/**
 * Process one tenant DB: find customers due for reorder and send reminders.
 *
 * @return array{checked:int, notified:int, errors:int}
 */
function reya_process_tenant_reorder_reminders(PDO $db, int $tenantId, string $today): array
{
    reya_ensure_reorder_reminders_table($db);

    // Purchase history per user: distinct order dates from `transactions`
    // (canonical purchase/order record — see CLAUDE.md Dispense System).
    // Only "real" completed-ish purchases count as reorder-cycle signal;
    // cancelled/refunded orders don't represent an actual consumption event.
    $rows = $db->query(
        "SELECT t.user_id, DATE(t.created_at) AS purchase_date,
                u.line_user_id, u.display_name, u.line_account_id,
                la.channel_access_token
           FROM transactions t
           JOIN users u ON u.id = t.user_id
           LEFT JOIN line_accounts la ON la.id = u.line_account_id
          WHERE t.status NOT IN ('cancelled', 'refunded')
            AND u.line_user_id IS NOT NULL
          ORDER BY t.user_id, t.created_at ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $byUser = [];
    foreach ($rows as $row) {
        $uid = (int) $row['user_id'];
        if (!isset($byUser[$uid])) {
            $byUser[$uid] = [
                'dates' => [],
                'line_user_id' => $row['line_user_id'],
                'display_name' => $row['display_name'],
                'line_account_id' => $row['line_account_id'],
                'channel_access_token' => $row['channel_access_token'],
            ];
        }
        $byUser[$uid]['dates'][] = $row['purchase_date'];
    }

    $checked = 0;
    $notified = 0;
    $errors = 0;

    foreach ($byUser as $userId => $info) {
        $checked++;

        $prediction = ReorderCycle::predict($info['dates']);
        if ($prediction === null) {
            continue; // not enough purchase history yet
        }

        if (!ReorderCycle::isDueWithin($prediction, $today, REORDER_REMINDER_WINDOW_DAYS)) {
            continue;
        }

        if (empty($info['channel_access_token']) || empty($info['line_user_id'])) {
            continue; // no way to message this customer
        }

        if (reya_already_reminded_recently($db, $userId, $prediction['next_due_date'], $today)) {
            continue;
        }

        $products = reya_fetch_reorder_products($db, $userId, REORDER_REMINDER_MAX_PRODUCTS);
        $flex = ReorderFlexBuilder::build($info['display_name'], $prediction, $products)
            ?? reya_build_reorder_reminder_flex($info['display_name'], $prediction);

        try {
            $gate = new NotificationGate($db);
            $sent = $gate->send([
                'user_id' => $userId,
                'line_user_id' => $info['line_user_id'],
                'line_account_id' => $info['line_account_id'] ?? null,
                'channel_access_token' => $info['channel_access_token'],
                'event_type' => 'reorder',
                'dedupe_key' => 'reorder:' . $userId . ':' . $prediction['next_due_date'],
                'messages' => [$flex],
            ])['sent'];

            if ($sent) {
                reya_record_reminder_sent($db, $userId, $prediction['next_due_date'], $prediction['average_interval_days']);
                $notified++;
            } else {
                $errors++;
            }
        } catch (\Throwable $e) {
            error_log("[reorder_reminder] tenant {$tenantId} user {$userId}: " . $e->getMessage());
            $errors++;
        }
    }

    return ['checked' => $checked, 'notified' => $notified, 'errors' => $errors];
}

/**
 * Fetch a customer's previously-bought products, most-frequently-bought
 * first, joined against the live `business_items` row so the carousel shows
 * current price/stock/image rather than a stale snapshot from
 * `transaction_items`. Only active, sellable items are offered — a
 * discontinued/disabled product can't be reordered.
 *
 * @return array<int, array{id:int, name:string, price:float, sale_price:float|null, image_url:string|null, stock:int|null}>
 */
function reya_fetch_reorder_products(PDO $db, int $userId, int $limit): array
{
    $stmt = $db->prepare(
        "SELECT bi.id, bi.name, bi.price, bi.sale_price, bi.image_url, bi.stock,
                COUNT(*) AS times_bought
           FROM transaction_items ti
           JOIN transactions t ON t.id = ti.transaction_id
           JOIN business_items bi ON bi.id = ti.product_id
          WHERE t.user_id = ?
            AND t.status NOT IN ('cancelled', 'refunded')
            AND bi.is_active = 1
            AND bi.enable = 1
          GROUP BY bi.id, bi.name, bi.price, bi.sale_price, bi.image_url, bi.stock
          ORDER BY times_bought DESC, MAX(t.created_at) DESC
          LIMIT ?"
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Idempotency table: one row per (user, predicted due-date). A cron re-run
 * or daily schedule will not re-send for the same computed cycle. Also
 * enforces a minimum gap between reminders regardless of due-date drift.
 */
function reya_ensure_reorder_reminders_table(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS reorder_reminders_sent (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        predicted_due_date DATE NOT NULL,
        average_interval_days DECIMAL(6,2) DEFAULT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_due_date (user_id, predicted_due_date),
        INDEX idx_user (user_id),
        INDEX idx_sent_at (sent_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * True if this exact (user, due-date) cycle was already reminded, OR if the
 * user received any reorder reminder within REORDER_REMINDER_MIN_GAP_DAYS.
 */
function reya_already_reminded_recently(PDO $db, int $userId, string $dueDate, string $today): bool
{
    $stmt = $db->prepare(
        "SELECT 1 FROM reorder_reminders_sent
          WHERE user_id = ?
            AND (predicted_due_date = ? OR sent_at >= DATE_SUB(?, INTERVAL " . REORDER_REMINDER_MIN_GAP_DAYS . " DAY))
          LIMIT 1"
    );
    $stmt->execute([$userId, $dueDate, $today]);
    return (bool) $stmt->fetchColumn();
}

function reya_record_reminder_sent(PDO $db, int $userId, string $dueDate, float $avgIntervalDays): void
{
    $stmt = $db->prepare(
        "INSERT INTO reorder_reminders_sent (user_id, predicted_due_date, average_interval_days)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE sent_at = NOW(), average_interval_days = VALUES(average_interval_days)"
    );
    $stmt->execute([$userId, $dueDate, $avgIntervalDays]);
}

/**
 * Build the "ถึงเวลาเติมยา" (time to refill) Flex reminder message.
 *
 * @param array{average_interval_days:float, next_due_date:string, purchase_count:int} $prediction
 */
function reya_build_reorder_reminder_flex(?string $displayName, array $prediction): array
{
    $name = $displayName ?: 'คุณลูกค้า';
    $dueDateThai = date('d/m/Y', strtotime($prediction['next_due_date']));
    $avgDays = (int) round($prediction['average_interval_days']);

    $bubble = [
        'type' => 'bubble',
        'size' => 'kilo',
        'header' => [
            'type' => 'box',
            'layout' => 'vertical',
            'backgroundColor' => '#11B0A6',
            'paddingAll' => '15px',
            'contents' => [
                [
                    'type' => 'box',
                    'layout' => 'horizontal',
                    'contents' => [
                        ['type' => 'text', 'text' => '🔁', 'size' => 'xxl'],
                        [
                            'type' => 'box',
                            'layout' => 'vertical',
                            'margin' => 'md',
                            'contents' => [
                                ['type' => 'text', 'text' => 'ถึงเวลาเติมยา', 'color' => '#FFFFFF', 'weight' => 'bold', 'size' => 'lg'],
                                ['type' => 'text', 'text' => $dueDateThai, 'color' => '#FFFFFF', 'size' => 'xs', 'margin' => 'xs'],
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
                    'text' => "จากประวัติการซื้อของคุณ ตอนนี้น่าจะถึงรอบเติมยาแล้วนะคะ (เฉลี่ยทุก {$avgDays} วัน)",
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
                        ['type' => 'text', 'text' => '📅 ครบกำหนดเติมยา', 'size' => 'sm', 'color' => '#888888', 'flex' => 2],
                        ['type' => 'text', 'text' => $dueDateThai, 'size' => 'sm', 'weight' => 'bold', 'align' => 'end', 'flex' => 2],
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
                        'label' => '💬 ติดต่อเภสัชกรเพื่อสั่งซ้ำ',
                        'text' => 'ขอเติมยาตามรอบเดิมค่ะ',
                    ],
                    'style' => 'primary',
                    'color' => '#11B0A6',
                    'height' => 'sm',
                ],
            ],
        ],
    ];

    return FlexTemplates::toMessage($bubble, "🔁 ถึงเวลาเติมยา ({$dueDateThai})");
}
