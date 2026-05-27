<?php
/**
 * AI endpoint rate limiter — DB-backed, hourly sliding window per
 * (endpoint, identifier_type, identifier).
 *
 * Used by:
 *  - api/ai-chat-vision.php
 *  - api/ai-chat-summary.php (future)
 *
 * Storage: `ai_rate_limits` (see database/migration_2026-05-24_ai_rate_limits.sql).
 * Window: 1 hour. When the row's window_start is older than 1h, the counter
 * is reset to 1; otherwise it is incremented.
 *
 * Returns true if the request is allowed, false if the cap has been reached.
 *
 * Failure mode: on any DB error this helper FAILS-OPEN (returns true) and
 * logs the failure — we'd rather serve the user than 500 the page. Callers
 * that need fail-closed semantics should wrap the call themselves.
 */

declare(strict_types=1);

if (!function_exists('checkAndIncrementRateLimit')) {

    /**
     * @param \PDO    $db
     * @param string  $endpoint    Logical endpoint name (e.g. 'vision').
     * @param string  $identifier  line_user_id or IP string. Empty → allowed.
     * @param string  $type        'user' | 'ip'.
     * @param int     $maxPerHour  Cap; non-positive → allowed.
     * @return bool   true=allowed, false=cap exceeded.
     */
    function checkAndIncrementRateLimit(
        \PDO $db,
        string $endpoint,
        string $identifier,
        string $type,
        int $maxPerHour
    ): bool {
        $identifier = trim($identifier);
        if ($identifier === '' || $maxPerHour <= 0) {
            return true;
        }
        if (!in_array($type, ['user', 'ip'], true)) {
            return true;
        }
        // Hard cap on identifier length matching column width.
        if (strlen($identifier) > 128) {
            $identifier = substr($identifier, 0, 128);
        }
        if (strlen($endpoint) > 64) {
            $endpoint = substr($endpoint, 0, 64);
        }

        try {
            // Atomic UPSERT:
            //   - If row missing → insert with count=1, window=NOW.
            //   - If existing row's window_start is older than 1h → reset.
            //   - Otherwise → increment.
            // MySQL has no clean "reset-if-old OR increment" in one INSERT,
            // so we use INSERT ... ON DUPLICATE KEY UPDATE with a CASE.
            $sql = "INSERT INTO ai_rate_limits
                        (endpoint, identifier, identifier_type, request_count, window_start)
                    VALUES (:e, :i, :t, 1, NOW())
                    ON DUPLICATE KEY UPDATE
                        request_count = CASE
                            WHEN window_start < (NOW() - INTERVAL 1 HOUR) THEN 1
                            ELSE request_count + 1
                        END,
                        window_start = CASE
                            WHEN window_start < (NOW() - INTERVAL 1 HOUR) THEN NOW()
                            ELSE window_start
                        END";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':e' => $endpoint,
                ':i' => $identifier,
                ':t' => $type,
            ]);

            // Read back the current count for this window.
            $sel = $db->prepare(
                'SELECT request_count
                   FROM ai_rate_limits
                  WHERE endpoint = :e
                    AND identifier_type = :t
                    AND identifier = :i
                  LIMIT 1'
            );
            $sel->execute([':e' => $endpoint, ':t' => $type, ':i' => $identifier]);
            $current = (int) ($sel->fetchColumn() ?: 0);

            return $current <= $maxPerHour;
        } catch (\Throwable $e) {
            error_log('[ai-rate-limit] fail-open: ' . $e->getMessage());
            return true;
        }
    }
}
