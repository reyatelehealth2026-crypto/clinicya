<?php
/**
 * Multi-tenant runner: create ai_usage_counters in EVERY DB (Phase 3, #19).
 *
 * Per-tenant Gemini/AI usage metering needs a counters table in every tenant
 * DB (and the legacy/main DB). This applies
 * database/migration_2026-07-04_ai_usage_counters.sql to the legacy DB *and*
 * every zrismpsz_reya_t_* tenant database.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, safe to re-run. AiUsageMeter also
 * lazily creates this table on first use as a resilience fallback.
 *
 * Run once on server:
 *   php install/migrate_all_tenants_ai_usage_counters.php
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

const AI_USAGE_COUNTERS_SQL = "CREATE TABLE IF NOT EXISTS ai_usage_counters (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-tenant, per-day AI API usage counters (Phase 3 metering, #19)'";

/**
 * Apply the ai_usage_counters table to one database. Returns a human-readable status.
 */
function applyAiUsageCounters(PDO $pdo, string $dbName): string
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([$dbName, 'ai_usage_counters']);
    if ($stmt->fetchColumn()) {
        return 'already exists';
    }

    $pdo->exec(AI_USAGE_COUNTERS_SQL);
    return 'CREATED ai_usage_counters';
}

function connectDb(string $dbName): PDO
{
    return new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

echo "=== AI Usage Counters — ALL TENANTS ===\n\n";

// Enumerate every tenant database + the legacy/main DB.
$dbNames = [];
try {
    $platform = connectDb(PLATFORM_DB_NAME);
    $stmt = $platform->prepare(
        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE ? ORDER BY SCHEMA_NAME'
    );
    $stmt->execute([TENANT_DB_PREFIX . '%']);
    $dbNames = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
} catch (\Throwable $e) {
    echo "! Could not enumerate tenant DBs via platform: {$e->getMessage()}\n";
}

// Include the legacy/main DB.
if (defined('DB_NAME') && !in_array(DB_NAME, $dbNames, true)) {
    array_unshift($dbNames, DB_NAME);
}

if (!$dbNames) {
    echo "No databases found to migrate.\n";
    exit(1);
}

echo "Databases to process: " . count($dbNames) . "\n\n";

$migrated = 0;
$failed = 0;
foreach ($dbNames as $dbName) {
    try {
        $pdo = connectDb($dbName);
        $status = applyAiUsageCounters($pdo, $dbName);
        if (strpos($status, 'CREATED') === 0) {
            $migrated++;
        }
        echo sprintf("  [%-26s] %s\n", $dbName, $status);
    } catch (\Throwable $e) {
        $failed++;
        echo sprintf("  [%-26s] ERROR: %s\n", $dbName, $e->getMessage());
    }
}

echo "\n=== Done: {$migrated} migrated, {$failed} failed, " . count($dbNames) . " total ===\n";
exit($failed > 0 ? 1 : 0);
