<?php
/**
 * Multi-tenant runner: create the core tables/column the tenant template omitted.
 *
 * SaaS is database-per-tenant, so the schema change must run across the legacy
 * DB *and* every reya_tenant_* database. This applies the same idempotent DDL as
 * database/migration_2026-08-17_tenant_missing_core_tables.sql.
 *
 * Run once on server:
 *   php install/migrate_all_tenants_missing_core_tables.php
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

/** Table bodies copied verbatim from database/schema_complete.sql. */
const CORE_TABLES = [
    'webhook_events' => "
        CREATE TABLE IF NOT EXISTS `webhook_events` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `event_id` VARCHAR(100) UNIQUE NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_webhook_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'dev_logs' => "
        CREATE TABLE IF NOT EXISTS `dev_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `log_type` ENUM('error', 'warning', 'info', 'debug', 'webhook') DEFAULT 'info',
            `source` VARCHAR(100),
            `message` TEXT,
            `data` LONGTEXT,
            `user_id` VARCHAR(100),
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_log_type` (`log_type`),
            INDEX `idx_log_source` (`source`),
            INDEX `idx_log_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'welcome_settings' => "
        CREATE TABLE IF NOT EXISTS `welcome_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `line_account_id` INT DEFAULT NULL,
            `is_enabled` TINYINT(1) DEFAULT 1,
            `message_type` ENUM('text', 'flex') DEFAULT 'text',
            `text_content` TEXT,
            `flex_content` JSON,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `unique_welcome_line_account` (`line_account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

/**
 * Apply the missing tables + users.member_tier to one database.
 * Idempotent; tolerant of a DB that has no `users` table at all.
 *
 * @return string human-readable status
 */
function applyMissingCoreTables(PDO $pdo): string
{
    $done = [];

    foreach (CORE_TABLES as $table => $ddl) {
        if (!$pdo->query("SHOW TABLES LIKE '{$table}'")->fetch()) {
            $pdo->exec($ddl);
            $done[] = $table;
        }
    }

    // Repair dev_logs created by the first cut of this migration, which copied a
    // stale definition (`type`/`level`) out of schema_complete.sql. Every caller
    // — webhook.php:18, :3270 and six read queries — uses `log_type`, so those
    // tenants logged "Unknown column 'log_type'" on every devLog() write.
    if ($pdo->query("SHOW TABLES LIKE 'dev_logs'")->fetch()
        && !$pdo->query("SHOW COLUMNS FROM dev_logs LIKE 'log_type'")->fetch()
    ) {
        if ($pdo->query("SHOW COLUMNS FROM dev_logs LIKE 'type'")->fetch()) {
            $pdo->exec(
                "ALTER TABLE dev_logs
                 CHANGE `type` `log_type`
                 ENUM('error', 'warning', 'info', 'debug', 'webhook') DEFAULT 'info'"
            );
        } else {
            $pdo->exec(
                "ALTER TABLE dev_logs
                 ADD COLUMN `log_type`
                 ENUM('error', 'warning', 'info', 'debug', 'webhook') DEFAULT 'info' AFTER `id`"
            );
        }
        $done[] = 'dev_logs.log_type';
    }

    if ($pdo->query("SHOW TABLES LIKE 'users'")->fetch()
        && !$pdo->query("SHOW COLUMNS FROM users LIKE 'member_tier'")->fetch()
    ) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN member_tier VARCHAR(50) DEFAULT 'bronze'
             COMMENT 'รหัสระดับสมาชิก (member_tiers.tier_code)'"
        );
        $done[] = 'users.member_tier';
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

echo "=== Tenant Missing Core Tables — ALL TENANTS ===\n\n";

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
        $status = applyMissingCoreTables($pdo);
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
