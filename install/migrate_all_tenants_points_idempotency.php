<?php
/**
 * Multi-tenant runner: points ledger idempotency key + indexes, EVERY DB.
 *
 * Applies database/migration_2026-09-07_points_idempotency.sql to the legacy
 * DB *and* every zrismpsz_reya_t_* tenant database (ADR-008 phase 4):
 *
 *   - points_transactions.idempotency_key + a UNIQUE index on it, so a
 *     redelivered Odoo webhook, a re-approved receipt claim or a
 *     double-clicked order approval credits points once instead of twice.
 *   - idx_pt_user — the table had ONLY a PRIMARY KEY, so every balance read
 *     (SUM(points) WHERE user_id = ?) was a full table scan, and since
 *     ADR-008 that scan runs holding the user row lock.
 *   - idx_pt_expires for expiry sweeps.
 *
 * Idempotent: checks information_schema before every ALTER, skips databases
 * with no points_transactions table, and tolerates partially-migrated ones.
 * ADD COLUMN / ADD INDEX are InnoDB online DDL (INPLACE) — awards keep
 * working while it runs.
 *
 * Run once on server:
 *   php install/migrate_all_tenants_points_idempotency.php
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';
const LEDGER_TABLE = 'points_transactions';

/** [indexName => ['cols' => [...], 'unique' => bool]] */
const LEDGER_INDEXES = [
    'uniq_points_idem' => ['cols' => ['idempotency_key'], 'unique' => true],
    'idx_pt_user'      => ['cols' => ['user_id'], 'unique' => false],
    'idx_pt_expires'   => ['cols' => ['expires_at'], 'unique' => false],
];

/**
 * Apply the ledger column + indexes to one database. Returns a status line.
 */
function applyLedgerIdempotency(PDO $pdo, string $dbName): string
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([$dbName, LEDGER_TABLE]);
    if (!$stmt->fetchColumn()) {
        return 'no points_transactions table — skipped';
    }

    $added = [];

    // Column first: the unique index below depends on it.
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([$dbName, LEDGER_TABLE]);
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('idempotency_key', $columns, true)) {
        $pdo->exec(
            'ALTER TABLE `' . LEDGER_TABLE . '`'
            . ' ADD COLUMN `idempotency_key` VARCHAR(190) NULL'
            . " COMMENT 'กันให้แต้มซ้ำ: <line_account_id>:<source>:<id> — NULL = ไม่มีคีย์ธรรมชาติ'"
            . ' AFTER `reference_id`'
        );
        $added[] = 'idempotency_key';
        $columns[] = 'idempotency_key';
    }

    $stmt = $pdo->prepare(
        'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $stmt->execute([$dbName, LEDGER_TABLE]);
    $existingIndexes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach (LEDGER_INDEXES as $indexName => $spec) {
        if (in_array($indexName, $existingIndexes, true)) {
            continue;
        }
        if (array_diff($spec['cols'], $columns)) {
            continue; // column isn't there on this tenant
        }
        $colSql = '`' . implode('`, `', $spec['cols']) . '`';
        $unique = $spec['unique'] ? 'UNIQUE ' : '';
        $pdo->exec('ALTER TABLE `' . LEDGER_TABLE . "` ADD {$unique}INDEX `$indexName` ($colSql)");
        $added[] = $indexName;
    }

    return $added ? 'ADDED ' . count($added) . ' (' . implode(', ', $added) . ')' : 'already migrated';
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

echo "=== Points ledger idempotency + indexes — ALL TENANTS ===\n\n";

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

echo 'Databases to process: ' . count($dbNames) . "\n\n";

$migrated = 0;
$failed = 0;
foreach ($dbNames as $dbName) {
    try {
        $pdo = connectDb($dbName);
        $status = applyLedgerIdempotency($pdo, $dbName);
        if (strpos($status, 'ADDED') === 0) {
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
