<?php
/**
 * inbox-response-time-collector.php
 *
 * Response time collector for LINE inbox.
 * Calculates time between incoming messages and first outgoing reply.
 * Excludes non-business hours (before 08:00, after 18:00) from wait time.
 * Writes to message_analytics table only (no existing files modified).
 *
 * Cron schedule: every 15 minutes (07,22,37,52)
 * Run: /www/server/php/83/bin/php /home/zrismpsz/public_html/cron/inbox-response-time-collector.php
 */

set_time_limit(120);
ini_set('memory_limit', '128M');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

$lookbackHours = 4;
$processed = 0;
$duplicates = 0;
$errors = 0;

// Business hours config
$bizStart = 8;   // 08:00
$bizEnd = 17;    // 18:00

$ts = date('Y-m-d H:i:s');
echo "[{$ts}] Starting response time collection (lookback={$lookbackHours}h, biz={$bizStart}:00-{$bizEnd}:00)...\n";

/**
 * Calculate business-time-adjusted response time in seconds.
 * Only counts time within 09:00-17:00 Mon-Sat (Sun excluded).
 * Returns null if should be skipped (e.g. entirely outside biz hours).
 */
function calcBusinessTime($incomingStr, $responseStr, $bizStart, $bizEnd) {
    $in = new DateTime($incomingStr);
    $out = new DateTime($responseStr);
    $inDow = (int) $in->format('w');
    $outDow = (int) $out->format('w');

    // If both on Sunday (dow=0), skip entirely
    if ($inDow === 0 && $outDow === 0) {
        return null;
    }

    $bizSeconds = 0;
    $current = clone $in;

    while ($current < $out) {
        $dow = (int) $current->format('w');

        // Skip Sundays
        if ($dow === 0) {
            $current->setTime(0, 0, 0);
            $current->modify('+1 day');
            $current->setTime($bizStart, 0, 0);
            continue;
        }

        $hour = (int) $current->format('H');
        $min = (int) $current->format('i');
        $sec = (int) $current->format('s');
        $currentDayStart = clone $current;
        $currentDayStart->setTime($bizStart, 0, 0);
        $currentDayEnd = clone $current;
        $currentDayEnd->setTime($bizEnd, 0, 0);

        // Before business hours → jump to biz start
        if ($current < $currentDayStart) {
            $diff = $currentDayStart->getTimestamp() - $current->getTimestamp();
            $current->modify("+{$diff} seconds");
            continue;
        }

        // After business hours → jump to next day biz start
        if ($current >= $currentDayEnd) {
            $current->setTime(0, 0, 0);
            $current->modify('+1 day');
            $current->setTime($bizStart, 0, 0);
            continue;
        }

        // Within business hours — count until end of biz day or response time
        $endOfCount = ($currentDayEnd < $out) ? $currentDayEnd : $out;
        $segment = $endOfCount->getTimestamp() - $current->getTimestamp();
        $bizSeconds += $segment;
        $current = $endOfCount;
    }

    return $bizSeconds;
}

try {
    $stmt = $db->prepare("
        SELECT m.id AS message_id, m.user_id, m.created_at
        FROM messages m
        WHERE m.direction = 'incoming'
          AND m.message_type = 'text'
          AND m.content IS NOT NULL
          AND LENGTH(m.content) >= 2
          AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
          AND NOT EXISTS (
              SELECT 1 FROM message_analytics ma WHERE ma.message_id = m.id
          )
        ORDER BY m.created_at ASC
        LIMIT 500
    ");
    $stmt->execute([$lookbackHours]);
    $incomingMessages = $stmt->fetchAll();
    $total = count($incomingMessages);

    if ($total === 0) {
        echo "[{$ts}] No new messages to process.\n";
        exit(0);
    }

    echo "[{$ts}] Found {$total} new incoming messages.\n";

    $insertStmt = $db->prepare("
        INSERT IGNORE INTO message_analytics (message_id, user_id, admin_id, response_time_seconds, created_at)
        VALUES (:message_id, :user_id, :admin_id, :response_time, :created_at)
    ");

    foreach ($incomingMessages as $msg) {
        try {
            // Look for response within 24 biz-hours window
            $respStmt = $db->prepare("
                SELECT id, sent_by, created_at
                FROM messages
                WHERE user_id = :user_id
                  AND direction = 'outgoing'
                  AND created_at > :incoming_time
                  AND created_at <= DATE_ADD(:incoming_time2, INTERVAL 24 HOUR)
                  AND sent_by IS NOT NULL
                ORDER BY created_at ASC
                LIMIT 1
            ");
            $respStmt->execute([
                ':user_id' => $msg['user_id'],
                ':incoming_time' => $msg['created_at'],
                ':incoming_time2' => $msg['created_at'],
            ]);

            $response = $respStmt->fetch();

            if ($response) {
                $bizTime = calcBusinessTime($msg['created_at'], $response['created_at'], $bizStart, $bizEnd);

                if ($bizTime !== null && $bizTime >= 0 && $bizTime <= 28800) {
                    $adminRaw = $response['sent_by'];
                    $adminId = (int) preg_replace('/[^0-9]/', '', $adminRaw) ?: null;

                    $insertStmt->execute([
                        ':message_id' => $msg['message_id'],
                        ':user_id' => $msg['user_id'],
                        ':admin_id' => $adminId,
                        ':response_time' => $bizTime,
                        ':created_at' => date('Y-m-d H:i:s'),
                    ]);

                    if ($insertStmt->rowCount() > 0) {
                        $processed++;
                    } else {
                        $duplicates++;
                    }
                } else {
                    $duplicates++;
                }
            }

        } catch (Exception $e) {
            $errors++;
            echo "[{$ts}] Error msg={$msg['message_id']}: " . $e->getMessage() . "\n";
        }
    }

    $cleanupStmt = $db->prepare("
        DELETE FROM message_analytics
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $cleanupStmt->execute();
    $cleanupCount = $cleanupStmt->rowCount();

    $skipped = $total - $processed - $errors - $duplicates;
    echo "[{$ts}] Done: processed={$processed}, no_response={$skipped}, dup={$duplicates}, errors={$errors}, cleaned={$cleanupCount}\n";

} catch (Exception $e) {
    echo "[{$ts}] Fatal: " . $e->getMessage() . "\n";
    exit(1);
}
