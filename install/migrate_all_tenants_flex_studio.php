<?php
/**
 * Multi-tenant runner: apply the Flex Studio schema to EVERY DB.
 *
 * SaaS is database-per-tenant, so the schema change must run across the legacy
 * DB *and* every reya_tenant_* database. This iterates all of them and applies
 * the same idempotent changes as migration_2026-07-10_flex_studio.sql:
 *   1. CREATE TABLE flex_brand_settings (per-shop brand tokens)
 *   2. flex_templates.slot_key + is_active + idx_flex_slot (only where the table exists)
 *
 * Every step is guarded (IF NOT EXISTS / SHOW COLUMNS / SHOW INDEX) so re-runs
 * and tenants without a flex_templates table are non-fatal.
 *
 * Run once on server:
 *   php install/migrate_all_tenants_flex_studio.php
 *
 * @spec flex-studio
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

/**
 * Apply the Flex Studio schema to one database.
 * Idempotent and tolerant of databases that have no flex_templates table.
 *
 * @return string human-readable status
 */
function applyFlexStudio(PDO $pdo): string
{
    $done = [];

    // 1) Per-shop brand tokens. Always safe (CREATE IF NOT EXISTS).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `flex_brand_settings` (
          `line_account_id`   INT(11)      NOT NULL COMMENT 'FK line_accounts.id — one row per shop',
          `primary_color`     VARCHAR(9)   DEFAULT NULL,
          `primary_dark`      VARCHAR(9)   DEFAULT NULL,
          `accent_color`      VARCHAR(9)   DEFAULT NULL,
          `logo_url`          VARCHAR(500) DEFAULT NULL,
          `sender_name`       VARCHAR(255) DEFAULT NULL,
          `sender_icon_url`   VARCHAR(500) DEFAULT NULL,
          `shop_display_name` VARCHAR(255) DEFAULT NULL,
          `footer_text`       VARCHAR(500) DEFAULT NULL,
          `corner_style`      VARCHAR(20)  DEFAULT NULL,
          `updated_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`line_account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Per-shop Flex brand tokens'
    ");
    $done[] = 'flex_brand_settings';

    // 2) flex_templates slot binding — only where the table already exists.
    $hasTable = $pdo->query("SHOW TABLES LIKE 'flex_templates'")->fetch();
    if (!$hasTable) {
        return 'MIGRATED (' . implode(', ', $done) . '; flex_templates absent — slot cols skipped)';
    }

    if (!$pdo->query("SHOW COLUMNS FROM flex_templates LIKE 'slot_key'")->fetch()) {
        $pdo->exec("ALTER TABLE `flex_templates` ADD COLUMN `slot_key` VARCHAR(64) DEFAULT NULL COMMENT 'known Flex slot this template overrides'");
        $done[] = 'slot_key';
    }
    if (!$pdo->query("SHOW COLUMNS FROM flex_templates LIKE 'is_active'")->fetch()) {
        $pdo->exec("ALTER TABLE `flex_templates` ADD COLUMN `is_active` TINYINT(1) DEFAULT 0 COMMENT '1 = this override is live for its slot'");
        $done[] = 'is_active';
    }
    $hasIdx = $pdo->query("SHOW INDEX FROM flex_templates WHERE Key_name = 'idx_flex_slot'")->fetch();
    if (!$hasIdx) {
        try {
            $pdo->exec("ALTER TABLE `flex_templates` ADD KEY `idx_flex_slot` (`line_account_id`, `slot_key`, `is_active`)");
            $done[] = 'idx_flex_slot';
        } catch (\Throwable $e) {
            // Non-fatal: index may exist from a partial earlier run.
        }
    }

    return 'MIGRATED (' . implode(', ', $done) . ')';
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

echo "=== Flex Studio schema — ALL TENANTS ===\n\n";

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
        $status = applyFlexStudio($pdo);
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
