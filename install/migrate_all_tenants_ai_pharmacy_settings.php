<?php
/**
 * Multi-tenant runner: apply ai_pharmacy_settings phantom-column fix to EVERY DB.
 *
 * SaaS is database-per-tenant, so the schema change must run across the legacy
 * DB *and* every reya_tenant_* database. This iterates all of them and applies
 * the same idempotent ALTERs as migration_2026-07-04_ai_pharmacy_settings_columns.sql.
 *
 * Run once on server:
 *   php install/migrate_all_tenants_ai_pharmacy_settings.php
 *
 * @spec issue-31-ai-pharmacy-settings-phantom-columns
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

/**
 * Apply the ai_pharmacy_settings column fixes to one database.
 * Idempotent and tolerant of databases that have no ai_pharmacy_settings table.
 *
 * @return string human-readable status
 */
function applyAiPharmacySettingsColumns(PDO $pdo): string
{
    $hasTable = $pdo->query("SHOW TABLES LIKE 'ai_pharmacy_settings'")->fetch();
    if (!$hasTable) {
        return 'skipped (no ai_pharmacy_settings table)';
    }

    $done = [];

    if (!$pdo->query("SHOW COLUMNS FROM ai_pharmacy_settings LIKE 'max_questions_per_session'")->fetch()) {
        $pdo->exec(
            "ALTER TABLE ai_pharmacy_settings
             ADD COLUMN max_questions_per_session INT DEFAULT 7 AFTER auto_recommend"
        );
        $done[] = 'max_questions_per_session';
    }

    if (!$pdo->query("SHOW COLUMNS FROM ai_pharmacy_settings LIKE 'require_pharmacist_approval'")->fetch()) {
        $pdo->exec(
            "ALTER TABLE ai_pharmacy_settings
             ADD COLUMN require_pharmacist_approval TINYINT(1) DEFAULT 0 AFTER auto_recommend"
        );
        $done[] = 'require_pharmacist_approval';
    }

    return $done ? ('MIGRATED (' . implode(', ', $done) . ')') : 'already migrated';
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

echo "=== AI Pharmacy Settings Columns — ALL TENANTS ===\n\n";

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

// Include the legacy/main DB (where the single-DB migration already ran).
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
        $status = applyAiPharmacySettingsColumns($pdo);
        if (strpos($status, 'MIGRATED') === 0) {
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
