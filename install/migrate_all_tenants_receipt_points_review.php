<?php
/**
 * Multi-tenant runner: apply the receipt-points-review schema to EVERY DB.
 *
 * SaaS is database-per-tenant, so the schema change must run across the legacy
 * DB *and* every reya_tenant_* database. Idempotent — safe to re-run; also
 * creates receipt_point_claims from scratch (full final column set) on any
 * tenant that has never processed a receipt claim yet.
 *
 * Run once on server:
 *   php install/migrate_all_tenants_receipt_points_review.php
 *
 * @spec docs/adr/0007-receipt-points-review.md
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

/**
 * Apply the receipt_point_claims schema to one database.
 * Idempotent; creates the table from scratch if it doesn't exist yet.
 *
 * @return string human-readable status
 */
function applyReceiptPointsReview(PDO $pdo): string
{
    $done = [];

    $hadTable = $pdo->query("SHOW TABLES LIKE 'receipt_point_claims'")->fetch();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `receipt_point_claims` (
          `id`              INT AUTO_INCREMENT PRIMARY KEY,
          `line_account_id` INT           DEFAULT NULL,
          `user_id`         INT           NOT NULL,
          `claim_key`       VARCHAR(255)  NOT NULL,
          `receipt_number`  VARCHAR(100)  DEFAULT NULL,
          `shop_name`       VARCHAR(255)  DEFAULT NULL,
          `total_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `points_awarded`  INT           NOT NULL DEFAULT 0,
          `created_at`      DATETIME      DEFAULT CURRENT_TIMESTAMP,
          `status`          VARCHAR(30)   DEFAULT 'approved',
          `image_hash`      CHAR(64)      DEFAULT NULL,
          `image_path`      VARCHAR(255)  DEFAULT NULL,
          `ocr_amount`      DECIMAL(10,2) DEFAULT NULL,
          `confidence`      VARCHAR(20)   DEFAULT NULL,
          `fail_reason`     VARCHAR(50)   DEFAULT NULL,
          `reviewed_by`     INT           DEFAULT NULL,
          `reviewed_at`     DATETIME      DEFAULT NULL,
          UNIQUE KEY `uk_claim` (`line_account_id`, `claim_key`),
          KEY `idx_user`    (`user_id`),
          KEY `idx_account` (`line_account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Auto receipt-scan loyalty point claims'
    ");
    if (!$hadTable) {
        return 'MIGRATED (created table with full schema)';
    }

    foreach (['status' => "VARCHAR(30) DEFAULT 'approved'",
              'image_hash' => 'CHAR(64) DEFAULT NULL',
              'image_path' => 'VARCHAR(255) DEFAULT NULL',
              'ocr_amount' => 'DECIMAL(10,2) DEFAULT NULL',
              'confidence' => 'VARCHAR(20) DEFAULT NULL',
              'fail_reason' => 'VARCHAR(50) DEFAULT NULL',
              'reviewed_by' => 'INT DEFAULT NULL',
              'reviewed_at' => 'DATETIME DEFAULT NULL'] as $col => $def) {
        if (!$pdo->query("SHOW COLUMNS FROM receipt_point_claims LIKE '$col'")->fetch()) {
            $pdo->exec("ALTER TABLE receipt_point_claims ADD COLUMN `$col` $def");
            $done[] = $col;
        }
    }

    $hasIdx = $pdo->query("SHOW INDEX FROM receipt_point_claims WHERE Key_name = 'idx_status'")->fetch();
    if (!$hasIdx) {
        try {
            $pdo->exec("ALTER TABLE receipt_point_claims ADD KEY idx_status (line_account_id, status, created_at)");
            $done[] = 'idx_status';
        } catch (\Throwable $e) {
            // Non-fatal: index may exist from a partial earlier run.
        }
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

echo "=== Receipt Points Review schema — ALL TENANTS ===\n\n";

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
        $status = applyReceiptPointsReview($pdo);
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
