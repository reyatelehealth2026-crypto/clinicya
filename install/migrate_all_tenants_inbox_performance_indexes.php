<?php
/**
 * Multi-tenant runner: add Inbox V2 performance indexes to EVERY DB.
 *
 * The tenant template created messages/users/conversation tables with only a
 * PRIMARY KEY, so every inbox query (conversation list, chat open, unread
 * counts, the 5-second poll) was a full table scan. This applies the indexes
 * from database/migration_2026-07-03_inbox_performance_indexes.sql to the
 * legacy DB *and* every zrismpsz_reya_t_* tenant database.
 *
 * Idempotent: checks information_schema before each ADD INDEX, skips tables
 * or columns that don't exist, and tolerates partially-migrated databases.
 * ADD INDEX is InnoDB online DDL (INPLACE) — chat keeps working while it runs.
 *
 * Run once on server:
 *   php install/migrate_all_tenants_inbox_performance_indexes.php
 */

define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
require_once __DIR__ . '/../config/config.php';

const TENANT_DB_PREFIX = 'zrismpsz_reya_t_';
const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

/** [table => [indexName => [columns...]]] */
const INBOX_INDEXES = [
    'messages' => [
        'idx_msg_user'          => ['user_id'],
        'idx_msg_user_created'  => ['user_id', 'created_at'],
        'idx_msg_user_dir_read' => ['user_id', 'direction', 'is_read'],
        'idx_msg_line_created'  => ['line_account_id', 'created_at'],
    ],
    'users' => [
        'idx_users_line_account' => ['line_account_id'],
        'idx_users_line_user'    => ['line_user_id'],
    ],
    'conversation_assignments' => [
        'idx_ca_user' => ['user_id'],
    ],
    'conversation_multi_assignees' => [
        'idx_cma_user_status' => ['user_id', 'status'],
    ],
    'user_tag_assignments' => [
        'idx_uta_user' => ['user_id', 'tag_id'],
    ],
    'account_followers' => [
        'idx_af_line_following' => ['line_account_id', 'is_following', 'user_id'],
    ],
];

/**
 * Apply the inbox indexes to one database. Returns a human-readable status.
 */
function applyInboxIndexes(PDO $pdo, string $dbName): string
{
    $added = [];
    $skipped = [];

    foreach (INBOX_INDEXES as $table => $indexes) {
        // Table must exist in this DB
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $stmt->execute([$dbName, $table]);
        if (!$stmt->fetchColumn()) {
            $skipped[] = "$table (no table)";
            continue;
        }

        // Existing columns + existing index names for this table
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $stmt->execute([$dbName, $table]);
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare(
            'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
        );
        $stmt->execute([$dbName, $table]);
        $existingIndexes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($indexes as $indexName => $indexCols) {
            if (in_array($indexName, $existingIndexes, true)) {
                continue; // already there
            }
            $missingCols = array_diff($indexCols, $columns);
            if ($missingCols) {
                $skipped[] = "$table.$indexName (no col: " . implode(',', $missingCols) . ')';
                continue;
            }
            $colSql = '`' . implode('`, `', $indexCols) . '`';
            $pdo->exec("ALTER TABLE `$table` ADD INDEX `$indexName` ($colSql)");
            $added[] = "$table.$indexName";
        }
    }

    $parts = [];
    if ($added) {
        $parts[] = 'ADDED ' . count($added) . ' (' . implode(', ', $added) . ')';
    }
    if ($skipped) {
        $parts[] = 'skipped ' . count($skipped);
    }
    return $parts ? implode('; ', $parts) : 'already migrated';
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

echo "=== Inbox V2 Performance Indexes — ALL TENANTS ===\n\n";

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
        $status = applyInboxIndexes($pdo, $dbName);
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
