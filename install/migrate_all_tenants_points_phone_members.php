<?php
/**
 * Multi-tenant runner: create points_merge_candidates on EVERY DB.
 *
 * SaaS is database-per-tenant, so the new flag table must exist in the legacy
 * DB *and* every reya_tenant_* database. This iterates all of them and applies
 * the same idempotent CREATE TABLE IF NOT EXISTS from
 * migration_2026-06-20_points_phone_members.sql.
 *
 * Run once on server:
 *   php install/migrate_all_tenants_points_phone_members.php
 *
 * @spec loyalty-phone-members
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

/**
 * Create the points_merge_candidates flag table on one database.
 * Idempotent (CREATE TABLE IF NOT EXISTS).
 *
 * @return string human-readable status
 */
function applyPointsMergeTable(PDO $pdo): string
{
    $existed = (bool) $pdo->query("SHOW TABLES LIKE 'points_merge_candidates'")->fetch();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `points_merge_candidates` (
          `id` INT NOT NULL AUTO_INCREMENT,
          `line_account_id` INT NOT NULL COMMENT 'tenant LINE OA scope — FK line_accounts.id',
          `phone` VARCHAR(20) NOT NULL COMMENT 'normalized phone shared by both records',
          `offline_user_id` INT NOT NULL COMMENT 'FK users.id — the phone-only ghost holding points to move',
          `line_user_id` INT NOT NULL COMMENT 'FK users.id — the LINE-linked target to merge INTO',
          `offline_points` INT NOT NULL DEFAULT 0 COMMENT 'snapshot of ghost available_points when flagged',
          `status` ENUM('pending','merged','dismissed') NOT NULL DEFAULT 'pending'
            COMMENT 'pending=awaiting pharmacist confirm, merged=points moved, dismissed=ignored',
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `resolved_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'when confirmed/dismissed',
          `resolved_by` INT NULL COMMENT 'FK admin_users.id — pharmacist who resolved',
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_pair` (`line_account_id`, `offline_user_id`, `line_user_id`),
          KEY `idx_account_status` (`line_account_id`, `status`),
          KEY `idx_phone` (`line_account_id`, `phone`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Pending phone->LINE loyalty merges awaiting pharmacist confirmation. Tenant-scoped.'
    ");

    return $existed ? 'already migrated' : 'MIGRATED (points_merge_candidates created)';
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

echo "=== Points Phone Members — ALL TENANTS ===\n\n";

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
        $status = applyPointsMergeTable($pdo);
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
