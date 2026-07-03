<?php
/**
 * AiUsageMeter - per-tenant AI API usage counters (Phase 3 metering, #19)
 *
 * Each Gemini/OpenAI call is attributed to the LINE OA (tenant) that made
 * it, so usage/billing/quota can be reported per tenant instead of lumped
 * across the whole platform. One row per (line_account_id, day, provider,
 * model); `calls` increments atomically via INSERT ... ON DUPLICATE KEY
 * UPDATE.
 *
 * Never throws — a metering failure must not break the AI call it is
 * counting. Auto-creates its table on first use (matches the repo's
 * existing auto-create pattern: dispensing_records, consultation_audit, ...).
 */
class AiUsageMeter
{
    /** @var bool ป้องกัน ensureTable() ทำงานซ้ำในหนึ่ง process */
    private static bool $tableEnsured = false;

    /**
     * Record one API call for a tenant/provider/model on today's date
     * (Asia/Bangkok). Safe to call from any request path — never throws.
     */
    public static function increment(\PDO $db, ?int $lineAccountId, string $provider, string $model): void
    {
        try {
            self::ensureTable($db);

            $day = date('Y-m-d');
            $stmt = $db->prepare(
                'INSERT INTO ai_usage_counters (line_account_id, day, provider, model, calls)
                 VALUES (:acc, :day, :provider, :model, 1)
                 ON DUPLICATE KEY UPDATE calls = calls + 1'
            );
            $stmt->execute([
                ':acc'      => $lineAccountId,
                ':day'      => $day,
                ':provider' => $provider,
                ':model'    => $model,
            ]);
        } catch (\Throwable $e) {
            error_log('[AiUsageMeter] increment failed: ' . $e->getMessage());
        }
    }

    /**
     * Total calls for a tenant across an optional date range (inclusive).
     * Returns 0 on any failure instead of throwing.
     *
     * @return array<int,array{day:string,provider:string,model:string,calls:int}>
     */
    public static function getUsage(\PDO $db, ?int $lineAccountId, ?string $fromDate = null, ?string $toDate = null): array
    {
        try {
            self::ensureTable($db);

            $sql = 'SELECT day, provider, model, calls FROM ai_usage_counters WHERE line_account_id <=> :acc';
            $params = [':acc' => $lineAccountId];

            if ($fromDate !== null) {
                $sql .= ' AND day >= :from';
                $params[':from'] = $fromDate;
            }
            if ($toDate !== null) {
                $sql .= ' AND day <= :to';
                $params[':to'] = $toDate;
            }
            $sql .= ' ORDER BY day DESC, provider, model';

            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[AiUsageMeter] getUsage failed: ' . $e->getMessage());
            return [];
        }
    }

    /** Sum of `calls` for a tenant across an optional date range. 0 on failure. */
    public static function getTotalCalls(\PDO $db, ?int $lineAccountId, ?string $fromDate = null, ?string $toDate = null): int
    {
        $total = 0;
        foreach (self::getUsage($db, $lineAccountId, $fromDate, $toDate) as $row) {
            $total += (int) ($row['calls'] ?? 0);
        }
        return $total;
    }

    private static function ensureTable(\PDO $db): void
    {
        if (self::$tableEnsured) {
            return;
        }
        self::$tableEnsured = true;
        $db->exec(
            "CREATE TABLE IF NOT EXISTS ai_usage_counters (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                line_account_id INT NULL,
                day             DATE NOT NULL,
                provider        VARCHAR(20) NOT NULL DEFAULT 'gemini',
                model           VARCHAR(50) NOT NULL,
                calls           INT UNSIGNED NOT NULL DEFAULT 0,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_account_day_provider_model (line_account_id, day, provider, model),
                KEY idx_account_day (line_account_id, day),
                KEY idx_day (day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
