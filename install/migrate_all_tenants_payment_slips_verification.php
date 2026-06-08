<?php
/**
 * Multi-tenant runner: apply payment_slips verification columns to EVERY DB.
 *
 * SaaS is database-per-tenant, so the schema change must run across the legacy
 * DB *and* every reya_tenant_* database. This iterates all of them and applies
 * the same idempotent ALTER as migration_payment_slips_verification.php.
 *
 * Run once on server:
 *   php install/migrate_all_tenants_payment_slips_verification.php
 *
 * @spec ghostx-slip-verification
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

/**
 * Apply the verification columns to one database's payment_slips table.
 * Idempotent and tolerant of databases that have no payment_slips table.
 *
 * @return string human-readable status
 */
function applyPaymentSlipsVerification(PDO $pdo): string
{
    $hasTable = $pdo->query("SHOW TABLES LIKE 'payment_slips'")->fetch();
    if (!$hasTable) {
        return 'skipped (no payment_slips table)';
    }

    $done = [];

    // Verify columns + unique index.
    if (!$pdo->query("SHOW COLUMNS FROM payment_slips LIKE 'verify_ref'")->fetch()) {
        $pdo->exec("
            ALTER TABLE payment_slips
            ADD COLUMN verify_ref VARCHAR(100) DEFAULT NULL COMMENT 'GhostX transactionRef (unique)' AFTER status,
            ADD COLUMN verify_amount DECIMAL(12,2) DEFAULT NULL COMMENT 'Amount confirmed by GhostX' AFTER verify_ref,
            ADD COLUMN verify_data JSON DEFAULT NULL COMMENT 'Full GhostX response payload' AFTER verify_amount,
            ADD COLUMN verified_at DATETIME DEFAULT NULL COMMENT 'When verification succeeded' AFTER verify_data
        ");
        try {
            $pdo->exec("ALTER TABLE payment_slips ADD UNIQUE INDEX uniq_verify_ref (verify_ref)");
        } catch (\Throwable $e) {
            // Index may already exist from a partial earlier run — non-fatal.
        }
        $done[] = 'verify cols';
    }

    // Raw QR payload the customer's app decoded at upload (lets admins re-verify).
    if (!$pdo->query("SHOW COLUMNS FROM payment_slips LIKE 'qr_payload'")->fetch()) {
        $pdo->exec("ALTER TABLE payment_slips ADD COLUMN qr_payload TEXT DEFAULT NULL COMMENT 'Raw QR string from the slip' AFTER verified_at");
        $done[] = 'qr_payload';
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

echo "=== Payment Slips Verification — ALL TENANTS ===\n\n";

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
        $status = applyPaymentSlipsVerification($pdo);
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
