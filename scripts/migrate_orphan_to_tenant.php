<?php
/**
 * scripts/migrate_orphan_to_tenant.php
 *
 * Moves orphan rows from the legacy DB (zrismpsz_demo) to the correct tenant
 * DB based on platform.tenant_line_account_routes. Idempotent — runs use
 * natural-key dedup so re-running is safe.
 *
 * Usage:
 *   php migrate_orphan_to_tenant.php           # dry-run (default)
 *   php migrate_orphan_to_tenant.php --apply   # actually write
 */
declare(strict_types=1);
@set_time_limit(0);

require_once __DIR__ . '/../config/config.php';

$APPLY = in_array('--apply', $argv ?? [], true);

$opts = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
];

$legacy   = new PDO("mysql:host=" . DB_HOST . ";dbname=zrismpsz_demo;charset=utf8mb4", DB_USER, DB_PASS, $opts);
$platform = new PDO("mysql:host=" . DB_HOST . ";dbname=zrismpsz_reya_platform;charset=utf8mb4", DB_USER, DB_PASS, $opts);

$routes = $platform->query(
    'SELECT line_account_id, tenant_id, tenant_db_name, oa_name
     FROM tenant_line_account_routes WHERE is_active = 1 ORDER BY tenant_id'
)->fetchAll(PDO::FETCH_ASSOC);

echo ($APPLY ? "=== APPLY MODE ===" : "=== DRY RUN (no writes) ===") . PHP_EOL;
echo "Routes loaded: " . count($routes) . PHP_EOL . PHP_EOL;

// Flush output to log file as soon as we print, even in non-tty mode.
function flushOut(): void {
    @ob_flush();
    @flush();
}
function runStep(string $label, callable $fn): void {
    try {
        $fn();
    } catch (\Throwable $e) {
        echo "  !! {$label} FAILED: " . $e->getMessage() . " (line " . $e->getLine() . ")" . PHP_EOL;
        flushOut();
    }
}

$totalCopied = ['users' => 0, 'messages' => 0, 'transactions' => 0, 'dispensing_records' => 0];
$txnIdMap = []; // legacy txn id → new tenant txn id (per route, reset each loop)

foreach ($routes as $r) {
    $la       = (int) $r['line_account_id'];
    $tenantDb = $r['tenant_db_name'];
    $tenant   = new PDO("mysql:host=" . DB_HOST . ";dbname={$tenantDb};charset=utf8mb4", DB_USER, DB_PASS, $opts);
    $txnIdMap = [];

    echo "── line_account={$la} → {$tenantDb} ({$r['oa_name']}) ──" . PHP_EOL;

    // -------------------------------------------------------------------
    // 1. USERS — natural key (line_account_id, line_user_id)
    // -------------------------------------------------------------------
    $cols = getCols($legacy, 'zrismpsz_demo', 'users');
    $colsT = getCols($tenant, $tenantDb, 'users');
    $cols = array_values(array_intersect($cols, $colsT));   // common columns only
    $colsNoId = array_values(array_filter($cols, fn ($c) => $c !== 'id'));
    $placeholders = implode(', ', array_fill(0, count($colsNoId), '?'));
    $colList = '`' . implode('`, `', $colsNoId) . '`';

    $sel = $legacy->prepare("SELECT * FROM users WHERE line_account_id = ?");
    $sel->execute([$la]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    $check = $tenant->prepare("SELECT id FROM users WHERE line_account_id = ? AND line_user_id = ? LIMIT 1");
    $ins   = $tenant->prepare("INSERT INTO users ({$colList}) VALUES ({$placeholders})");
    $copied = 0;
    foreach ($rows as $row) {
        $check->execute([$row['line_account_id'], $row['line_user_id']]);
        if ($check->fetchColumn()) continue;
        if ($APPLY) {
            $vals = array_map(fn ($c) => $row[$c] ?? null, $colsNoId);
            $ins->execute($vals);
        }
        $copied++;
    }
    echo "  users:              " . count($rows) . " in legacy, would copy {$copied}" . PHP_EOL;
    $totalCopied['users'] += $copied;

    // -------------------------------------------------------------------
    // 2. MESSAGES — natural key (line_account_id, line_user_id, created_at, hash(message_text))
    // -------------------------------------------------------------------
    $cols = getCols($legacy, 'zrismpsz_demo', 'messages');
    $colsT = getCols($tenant, $tenantDb, 'messages');
    $cols = array_values(array_intersect($cols, $colsT));
    $colsNoId = array_values(array_filter($cols, fn ($c) => $c !== 'id'));
    $placeholders = implode(', ', array_fill(0, count($colsNoId), '?'));
    $colList = '`' . implode('`, `', $colsNoId) . '`';

    $sel = $legacy->prepare("SELECT * FROM messages WHERE line_account_id = ?");
    $sel->execute([$la]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    // Messages schema uses user_id + content (not line_user_id + message_text).
    $check = $tenant->prepare(
        "SELECT id FROM messages
         WHERE line_account_id <=> ? AND user_id <=> ?
           AND created_at <=> ?
           AND LEFT(COALESCE(content, ''), 100) <=> LEFT(COALESCE(?, ''), 100)
         LIMIT 1"
    );
    $ins = $tenant->prepare("INSERT INTO messages ({$colList}) VALUES ({$placeholders})");
    $copied = 0;
    foreach ($rows as $row) {
        $check->execute([
            $row['line_account_id'] ?? null,
            $row['user_id'] ?? null,
            $row['created_at'] ?? null,
            $row['content'] ?? null,
        ]);
        if ($check->fetchColumn()) continue;
        if ($APPLY) {
            $vals = array_map(fn ($c) => $row[$c] ?? null, $colsNoId);
            $ins->execute($vals);
        }
        $copied++;
    }
    echo "  messages:           " . count($rows) . " in legacy, would copy {$copied}" . PHP_EOL;
    $totalCopied['messages'] += $copied;

    // -------------------------------------------------------------------
    // 3. TRANSACTIONS — natural key (line_account_id, line_user_id, created_at, total)
    //    Track id mapping so we can migrate transaction_items afterwards.
    // -------------------------------------------------------------------
    $cols = getCols($legacy, 'zrismpsz_demo', 'transactions');
    $colsT = getCols($tenant, $tenantDb, 'transactions');
    $cols = array_values(array_intersect($cols, $colsT));
    $colsNoId = array_values(array_filter($cols, fn ($c) => $c !== 'id'));
    $placeholders = implode(', ', array_fill(0, count($colsNoId), '?'));
    $colList = '`' . implode('`, `', $colsNoId) . '`';

    $sel = $legacy->prepare("SELECT * FROM transactions WHERE line_account_id = ?");
    $sel->execute([$la]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    // transactions use order_number as natural key; fall back to (line_user_id, created_at, grand_total).
    $check = $tenant->prepare(
        "SELECT id FROM transactions
         WHERE line_account_id = ?
           AND (
             (order_number IS NOT NULL AND order_number <=> ?)
             OR (line_user_id <=> ? AND created_at <=> ? AND grand_total <=> ?)
           )
         LIMIT 1"
    );
    $ins = $tenant->prepare("INSERT INTO transactions ({$colList}) VALUES ({$placeholders})");
    $copied = 0;
    foreach ($rows as $row) {
        $check->execute([
            $row['line_account_id'],
            $row['order_number'] ?? null,
            $row['line_user_id'] ?? null,
            $row['created_at'] ?? null,
            $row['grand_total'] ?? null,
        ]);
        $existingId = $check->fetchColumn();
        if ($existingId) {
            $txnIdMap[(int) $row['id']] = (int) $existingId;
            continue;
        }
        if ($APPLY) {
            $vals = array_map(fn ($c) => $row[$c] ?? null, $colsNoId);
            $ins->execute($vals);
            $txnIdMap[(int) $row['id']] = (int) $tenant->lastInsertId();
        }
        $copied++;
    }
    echo "  transactions:       " . count($rows) . " in legacy, would copy {$copied}" . PHP_EOL;
    $totalCopied['transactions'] += $copied;

    // -------------------------------------------------------------------
    // 4. TRANSACTION_ITEMS — follow the id map from step 3
    // -------------------------------------------------------------------
    if (tableExists($legacy, 'zrismpsz_demo', 'transaction_items') && tableExists($tenant, $tenantDb, 'transaction_items') && !empty($txnIdMap)) {
        $cols = getCols($legacy, 'zrismpsz_demo', 'transaction_items');
        $colsT = getCols($tenant, $tenantDb, 'transaction_items');
        $cols = array_values(array_intersect($cols, $colsT));
        $colsNoId = array_values(array_filter($cols, fn ($c) => $c !== 'id'));
        $placeholders = implode(', ', array_fill(0, count($colsNoId), '?'));
        $colList = '`' . implode('`, `', $colsNoId) . '`';

        $copied = 0;
        $skipped = 0;
        foreach ($txnIdMap as $oldId => $newId) {
            if ($oldId === $newId) continue; // existed already, items also exist
            $sel2 = $legacy->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
            $sel2->execute([$oldId]);
            $items = $sel2->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) continue;
            // Skip if tenant already has items for this new txn id
            $tcheck = $tenant->prepare("SELECT COUNT(*) FROM transaction_items WHERE transaction_id = ?");
            $tcheck->execute([$newId]);
            if ((int) $tcheck->fetchColumn() > 0) { $skipped++; continue; }

            if ($APPLY) {
                $ins2 = $tenant->prepare("INSERT INTO transaction_items ({$colList}) VALUES ({$placeholders})");
                foreach ($items as $it) {
                    $it['transaction_id'] = $newId;
                    $vals = array_map(fn ($c) => $it[$c] ?? null, $colsNoId);
                    $ins2->execute($vals);
                }
            }
            $copied += count($items);
        }
        echo "  transaction_items:  would copy {$copied} (linked via txn id map, {$skipped} already had items)" . PHP_EOL;
    }

    // -------------------------------------------------------------------
    // 5. DISPENSING_RECORDS — natural key (line_account_id, user_id|line_user_id, created_at)
    // -------------------------------------------------------------------
    if (tableExists($legacy, 'zrismpsz_demo', 'dispensing_records') && tableExists($tenant, $tenantDb, 'dispensing_records')) {
        $cols = getCols($legacy, 'zrismpsz_demo', 'dispensing_records');
        $colsT = getCols($tenant, $tenantDb, 'dispensing_records');
        $cols = array_values(array_intersect($cols, $colsT));
        $colsNoId = array_values(array_filter($cols, fn ($c) => $c !== 'id'));
        $placeholders = implode(', ', array_fill(0, count($colsNoId), '?'));
        $colList = '`' . implode('`, `', $colsNoId) . '`';

        $sel = $legacy->prepare("SELECT * FROM dispensing_records WHERE line_account_id = ?");
        $sel->execute([$la]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

        $userIdCol = in_array('user_id', $cols, true) ? 'user_id' : 'line_user_id';
        $check = $tenant->prepare(
            "SELECT id FROM dispensing_records
             WHERE line_account_id = ? AND {$userIdCol} <=> ?
               AND created_at <=> ?
             LIMIT 1"
        );
        $ins = $tenant->prepare("INSERT INTO dispensing_records ({$colList}) VALUES ({$placeholders})");
        $copied = 0;
        foreach ($rows as $row) {
            $check->execute([$row['line_account_id'], $row[$userIdCol] ?? null, $row['created_at'] ?? null]);
            if ($check->fetchColumn()) continue;
            if ($APPLY) {
                $vals = array_map(fn ($c) => $row[$c] ?? null, $colsNoId);
                $ins->execute($vals);
            }
            $copied++;
        }
        echo "  dispensing_records: " . count($rows) . " in legacy, would copy {$copied}" . PHP_EOL;
        $totalCopied['dispensing_records'] += $copied;
    }

    echo PHP_EOL;
}

echo "=== SUMMARY (" . ($APPLY ? "applied" : "would copy") . ") ===" . PHP_EOL;
foreach ($totalCopied as $tbl => $n) {
    echo "  {$tbl}: {$n}" . PHP_EOL;
}
echo PHP_EOL;
if (!$APPLY) {
    echo "Re-run with --apply to actually write." . PHP_EOL;
}

// -----------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------
function getCols(PDO $pdo, string $db, string $table): array
{
    $s = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
    $s->execute([$db, $table]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}
function tableExists(PDO $pdo, string $db, string $table): bool
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    $s->execute([$db, $table]);
    return ((int) $s->fetchColumn()) > 0;
}
