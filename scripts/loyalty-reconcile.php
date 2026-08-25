<?php

/**
 * scripts/loyalty-reconcile.php — READ-ONLY loyalty balance reconciliation.
 *
 * Answers the question Phase 1 of the plan cannot ship without: for every
 * member, do the loyalty stores agree, and if not, how badly?
 *
 * The Phase 0 audit found eight places a point balance can live
 * (docs/plans/2026-08-26-loyalty-source-of-truth-matrix.md). This report reads
 * the four that are actually load-bearing and classifies every member into one
 * bucket, so an operator can size the migration before §34 moves anybody:
 *
 *   ledger_balance         = SUM(points_transactions.points)
 *   legacy_history_balance = SUM(points_history.points)
 *   users_available        = users.available_points
 *   users_points           = users.points
 *
 * BUCKETS
 *   EMPTY        nothing anywhere. Nothing to do.
 *   MATCHED      the ledger has rows and the cache agrees with it. Healthy.
 *   STALE_CACHE  the ledger has rows and nets to ZERO, but a legacy column still
 *                shows a balance. These are the members the pre-Batch-1
 *                getUserPoints() fallback would have handed phantom, spendable
 *                points to. Highest-signal bucket in the report.
 *   CONFLICT     the ledger has rows but the cache disagrees with the sum.
 *   LEGACY_ONLY  no ledger rows, but points_history has them — the balance was
 *                only ever written by api/member.php / api/points.php /
 *                shop/order-detail.php.
 *   CACHE_ONLY   no ledger rows and no history, yet a users column shows a
 *                balance. A number with no provenance at all.
 *
 * THIS SCRIPT NEVER WRITES. No INSERT, no UPDATE, no DELETE, no DDL. It is safe
 * to run against production at any time. Repairing what it finds is §34's job
 * and needs an explicit migration-authority decision per bucket.
 *
 * USAGE
 *   php scripts/loyalty-reconcile.php --all-tenants
 *   php scripts/loyalty-reconcile.php --tenant=7 --show=STALE_CACHE,CONFLICT
 *   php scripts/loyalty-reconcile.php --all-tenants --json > reconcile.json
 *   php scripts/loyalty-reconcile.php --tenant=7 --limit=50
 *
 * OPTIONS
 *   --all-tenants        scan every active tenant in the platform registry
 *   --tenant=<id>        scan one tenant
 *   --legacy             scan the legacy single-tenant DB (no TenantContext)
 *   --show=A,B           list individual members in these buckets (default:
 *                        STALE_CACHE,CONFLICT,CACHE_ONLY)
 *   --limit=<n>          cap the per-bucket member listing (default 25)
 *   --json               emit machine-readable JSON instead of a table
 *
 * Exit code is 0 when every member is EMPTY or MATCHED, 1 when any member needs
 * attention, 2 on a fatal error — so it can gate a deploy.
 *
 * @see docs/plans/2026-08-26-php-first-crm-loyalty-saas-plan.md §34
 */

declare(strict_types=1);

const RECONCILE_BUCKETS = [
    'EMPTY',
    'MATCHED',
    'STALE_CACHE',
    'CONFLICT',
    'LEGACY_ONLY',
    'CACHE_ONLY',
];

const RECONCILE_HEALTHY = ['EMPTY', 'MATCHED'];

/**
 * The whole script body. Kept in a function so the pure classification helpers
 * below can be unit-tested by requiring this file with REYA_RECONCILE_NO_MAIN
 * defined — no database, no config bootstrap.
 *
 * @param array<int, string> $argv
 * @return int process exit code
 */
function reya_reconcile_main(array $argv): int
{
    $options = reya_reconcile_parse_argv($argv);

    if ($options['invalid']) {
        foreach ($options['errors'] as $error) {
            fwrite(STDERR, '[loyalty-reconcile] ' . $error . "\n");
        }
        reya_reconcile_usage();

        return 2;
    }

    if ($options['help']) {
        reya_reconcile_usage();

        return 0;
    }

    if (!$options['all'] && $options['tenant'] === null && !$options['legacy']) {
        fwrite(STDERR, "[loyalty-reconcile] pick a target: --all-tenants, --tenant=<id> or --legacy\n\n");
        reya_reconcile_usage();

        return 2;
    }

    $report = [
        'generated_at' => date('c'),
        'tenants' => [],
        'totals' => array_fill_keys(RECONCILE_BUCKETS, 0),
        'point_liability' => ['ledger' => 0, 'cache' => 0, 'legacy_history' => 0, 'retail' => 0],
    ];

    try {
        $targets = reya_reconcile_targets($options);
    } catch (Throwable $e) {
        fwrite(STDERR, '[loyalty-reconcile] ' . $e->getMessage() . "\n");
        return 2;
    }

    if ($targets === []) {
        fwrite(STDERR, "[loyalty-reconcile] no tenants selected — pass --all-tenants, --tenant=<id> or --legacy\n");
        return 2;
    }

    foreach ($targets as $target) {
        try {
            if ($target['id'] !== null) {
                TenantContext::setCurrentTenantId($target['id']);
                $db = Database::forTenant($target['id'])->getConnection();
            } else {
                $db = Database::getInstance()->getConnection();
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "[loyalty-reconcile] {$target['label']}: cannot connect — " . $e->getMessage() . "\n");
            $report['tenants'][] = [
                'tenant' => $target['label'],
                'error' => $e->getMessage(),
            ];
            continue;
        }

        try {
            $tenantReport = reya_reconcile_tenant($db, $options);
        } catch (Throwable $e) {
            fwrite(STDERR, "[loyalty-reconcile] {$target['label']}: scan failed — " . $e->getMessage() . "\n");
            $report['tenants'][] = [
                'tenant' => $target['label'],
                'error' => $e->getMessage(),
            ];
            continue;
        }

        $tenantReport['tenant'] = $target['label'];
        $tenantReport['tenant_id'] = $target['id'];
        $report['tenants'][] = $tenantReport;

        foreach (RECONCILE_BUCKETS as $bucket) {
            $report['totals'][$bucket] += $tenantReport['counts'][$bucket];
        }
        foreach (['ledger', 'cache', 'legacy_history', 'retail'] as $store) {
            $report['point_liability'][$store] += $tenantReport['point_liability'][$store] ?? 0;
        }
    }

    TenantContext::reset();

    $needsAttention = 0;
    foreach (RECONCILE_BUCKETS as $bucket) {
        if (!in_array($bucket, RECONCILE_HEALTHY, true)) {
            $needsAttention += $report['totals'][$bucket];
        }
    }

    if ($options['json']) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    } else {
        reya_reconcile_render($report, $options, $needsAttention);
    }

    return $needsAttention > 0 ? 1 : 0;
}

// Run only when invoked directly; requiring this file for its pure helpers
// (see tests/Loyalty/ReconcileClassificationTest.php) must have no side effects.
if (!defined('REYA_RECONCILE_NO_MAIN')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit("CLI only\n");
    }

    define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/TenantContext.php';

    exit(reya_reconcile_main($argv));
}



// ---------------------------------------------------------------------------
// Scanning
// ---------------------------------------------------------------------------

/**
 * Classify every member in one tenant DB.
 *
 * @return array{
 *     counts:array<string,int>, members:array<string,array<int,array<string,mixed>>>,
 *     point_liability:array<string,int>, scanned:int, notes:array<int,string>
 * }
 */
function reya_reconcile_tenant(PDO $db, array $options): array
{
    $notes = [];
    $hasLedger = reya_reconcile_table_exists($db, 'points_transactions');
    $hasHistory = reya_reconcile_table_exists($db, 'points_history');

    if (!$hasLedger) {
        $notes[] = 'points_transactions is absent — every member will read as CACHE_ONLY or EMPTY';
    }
    if (!$hasHistory) {
        $notes[] = 'points_history is absent — the LEGACY_ONLY bucket cannot be populated';
    }

    $userColumns = [];
    foreach (['points', 'total_points', 'available_points', 'used_points'] as $column) {
        if (reya_reconcile_column_exists($db, 'users', $column)) {
            $userColumns[] = $column;
        }
    }

    // One pass per store, keyed by user id, rather than a correlated subquery
    // per member — a tenant with 100k members would otherwise take minutes.
    $ledger = $hasLedger
        ? reya_reconcile_fetch_sums($db, 'SELECT user_id, COUNT(*) AS rows_count, COALESCE(SUM(points), 0) AS balance FROM points_transactions GROUP BY user_id')
        : [];
    $history = $hasHistory
        ? reya_reconcile_fetch_sums($db, 'SELECT user_id, COUNT(*) AS rows_count, COALESCE(SUM(points), 0) AS balance FROM points_history GROUP BY user_id')
        : [];

    $select = 'SELECT id' . ($userColumns === [] ? '' : ', `' . implode('`, `', $userColumns) . '`') . ' FROM users';
    $stmt = $db->query($select);

    $counts = array_fill_keys(RECONCILE_BUCKETS, 0);
    $members = array_fill_keys(RECONCILE_BUCKETS, []);
    $liability = ['ledger' => 0, 'cache' => 0, 'legacy_history' => 0, 'retail' => 0];
    $scanned = 0;

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $scanned++;
        $userId = (int) $row['id'];

        $ledgerRows = (int) ($ledger[$userId]['rows_count'] ?? 0);
        $ledgerBalance = (int) ($ledger[$userId]['balance'] ?? 0);
        $historyRows = (int) ($history[$userId]['rows_count'] ?? 0);
        $historyBalance = (int) ($history[$userId]['balance'] ?? 0);
        $usersAvailable = (int) ($row['available_points'] ?? 0);
        $usersPoints = (int) ($row['points'] ?? 0);

        $liability['ledger'] += max(0, $ledgerBalance);
        $liability['cache'] += max(0, $usersAvailable);
        $liability['legacy_history'] += max(0, $historyBalance);

        $bucket = reya_reconcile_classify(
            $ledgerRows,
            $ledgerBalance,
            $historyRows,
            $usersAvailable,
            $usersPoints
        );
        $counts[$bucket]++;

        if (
            in_array($bucket, $options['show'], true)
            && count($members[$bucket]) < $options['limit']
        ) {
            $members[$bucket][] = [
                'user_id' => $userId,
                'ledger_rows' => $ledgerRows,
                'ledger_balance' => $ledgerBalance,
                'users_available' => $usersAvailable,
                'users_points' => $usersPoints,
                'legacy_history_rows' => $historyRows,
                'legacy_history_balance' => $historyBalance,
                'drift' => $ledgerBalance - $usersAvailable,
            ];
        }
    }

    // The EIGHTH point store. `retail_customers.points_balance` is credited by
    // api/retail-payment.php and read by api/retail-cart.php — a fully parallel
    // loyalty system that nothing reconciles against points_transactions. It has
    // NO CREATE TABLE anywhere in the repository, so whether it exists at all is
    // per-deployment; report it when present so an operator can see the exposure.
    $retail = reya_reconcile_retail_store($db);
    if ($retail !== null) {
        $liability['retail'] = $retail['liability'];
        $notes[] = sprintf(
            'retail_customers is present: %d customer(s), %d point(s) of liability, reconciled against nothing',
            $retail['customers'],
            $retail['liability']
        );
    }

    return [
        'counts' => $counts,
        'members' => $members,
        'point_liability' => $liability,
        'retail' => $retail,
        'scanned' => $scanned,
        'notes' => $notes,
    ];
}

/**
 * The retail-side point store, if this deployment has one.
 *
 * @return array{customers:int, liability:int}|null null when the table is absent
 */
function reya_reconcile_retail_store(PDO $db): ?array
{
    if (!reya_reconcile_table_exists($db, 'retail_customers')
        || !reya_reconcile_column_exists($db, 'retail_customers', 'points_balance')) {
        return null;
    }

    try {
        $row = $db->query(
            'SELECT COUNT(*) AS customers, COALESCE(SUM(GREATEST(points_balance, 0)), 0) AS liability
               FROM retail_customers'
        )->fetch(PDO::FETCH_ASSOC);

        return [
            'customers' => (int) ($row['customers'] ?? 0),
            'liability' => (int) ($row['liability'] ?? 0),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Which bucket a single member falls into. Ordered most-specific first: a member
 * who is both stale-cached and drifting is reported as STALE_CACHE, because that
 * is the actionable diagnosis.
 */
function reya_reconcile_classify(
    int $ledgerRows,
    int $ledgerBalance,
    int $historyRows,
    int $usersAvailable,
    int $usersPoints
): string {
    if ($ledgerRows > 0) {
        // The exact shape of the pre-Batch-1 phantom-points bug: a ledger that
        // legitimately nets to zero sitting next to a non-zero legacy column.
        if ($ledgerBalance === 0 && ($usersPoints > 0 || $usersAvailable > 0)) {
            return 'STALE_CACHE';
        }

        return $ledgerBalance === $usersAvailable ? 'MATCHED' : 'CONFLICT';
    }

    if ($historyRows > 0) {
        return 'LEGACY_ONLY';
    }

    if ($usersAvailable > 0 || $usersPoints > 0) {
        return 'CACHE_ONLY';
    }

    return 'EMPTY';
}

/** @return array<int, array{rows_count:int, balance:int}> keyed by user id */
function reya_reconcile_fetch_sums(PDO $db, string $sql): array
{
    $out = [];
    $stmt = $db->query($sql);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $out[(int) $row['user_id']] = [
            'rows_count' => (int) $row['rows_count'],
            'balance' => (int) $row['balance'],
        ];
    }

    return $out;
}

// ---------------------------------------------------------------------------
// Targets, introspection, CLI plumbing
// ---------------------------------------------------------------------------

/** @return array<int, array{id:int|null, label:string}> */
function reya_reconcile_targets(array $options): array
{
    if ($options['legacy']) {
        return [['id' => null, 'label' => 'legacy (DB_NAME)']];
    }

    $platformDb = Database::platform()->getConnection();

    if ($options['tenant'] !== null) {
        $stmt = $platformDb->prepare('SELECT id, slug FROM tenants WHERE id = ?');
        $stmt->execute([$options['tenant']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('no such tenant: ' . $options['tenant']);
        }

        return [['id' => (int) $row['id'], 'label' => '#' . $row['id'] . ' ' . $row['slug']]];
    }

    $targets = [];
    $rows = $platformDb->query("SELECT id, slug FROM tenants WHERE status = 'active' ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $targets[] = ['id' => (int) $row['id'], 'label' => '#' . $row['id'] . ' ' . $row['slug']];
    }

    return $targets;
}

function reya_reconcile_table_exists(PDO $db, string $table): bool
{
    try {
        $stmt = $db->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);

        return $stmt->fetch() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function reya_reconcile_column_exists(PDO $db, string $table, string $column): bool
{
    try {
        $stmt = $db->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
        $stmt->execute([$column]);

        return $stmt->fetch() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/** @return array<string, mixed> */
function reya_reconcile_parse_argv(array $argv): array
{
    $options = [
        'all' => false,
        'tenant' => null,
        'legacy' => false,
        'json' => false,
        'limit' => 25,
        'show' => ['STALE_CACHE', 'CONFLICT', 'CACHE_ONLY'],
        'help' => false,
        'invalid' => false,
        'errors' => [],
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--all-tenants') {
            $options['all'] = true;
        } elseif ($arg === '--legacy') {
            $options['legacy'] = true;
        } elseif ($arg === '--json') {
            $options['json'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif (strpos($arg, '--tenant=') === 0) {
            $options['tenant'] = (int) substr($arg, 9);
        } elseif (strpos($arg, '--limit=') === 0) {
            $options['limit'] = max(0, (int) substr($arg, 8));
        } elseif (strpos($arg, '--show=') === 0) {
            $requested = array_filter(array_map('trim', explode(',', strtoupper(substr($arg, 7)))));
            $options['show'] = array_values(array_intersect($requested, RECONCILE_BUCKETS));
        } else {
            $options['invalid'] = true;
            $options['errors'][] = 'unknown option: ' . $arg;
        }
    }

    return $options;
}

function reya_reconcile_usage(): void
{
    echo "Usage: php scripts/loyalty-reconcile.php [--all-tenants | --tenant=<id> | --legacy]\n";
    echo "                                         [--show=BUCKET,BUCKET] [--limit=<n>] [--json]\n\n";
    echo 'Buckets: ' . implode(', ', RECONCILE_BUCKETS) . "\n";
    echo "Read-only. Exit 0 = all healthy, 1 = members need attention, 2 = fatal error.\n";
}

function reya_reconcile_render(array $report, array $options, int $needsAttention): void
{
    echo "=== Loyalty balance reconciliation (READ-ONLY) ===\n";
    echo 'Generated: ' . $report['generated_at'] . "\n\n";

    foreach ($report['tenants'] as $tenant) {
        if (isset($tenant['error'])) {
            echo "[{$tenant['tenant']}] ERROR: {$tenant['error']}\n\n";
            continue;
        }

        echo "[{$tenant['tenant']}] {$tenant['scanned']} members\n";
        foreach ($tenant['notes'] as $note) {
            echo "  ! {$note}\n";
        }

        foreach (RECONCILE_BUCKETS as $bucket) {
            $count = $tenant['counts'][$bucket];
            if ($count === 0) {
                continue;
            }
            $flag = in_array($bucket, RECONCILE_HEALTHY, true) ? ' ' : '*';
            printf("  %s %-12s %6d\n", $flag, $bucket, $count);
        }

        foreach ($options['show'] as $bucket) {
            $rows = $tenant['members'][$bucket] ?? [];
            if ($rows === []) {
                continue;
            }
            echo "\n  -- {$bucket} (first " . count($rows) . ") --\n";
            printf(
                "  %8s %7s %10s %10s %10s %8s\n",
                'user_id',
                'lgr_rows',
                'ledger',
                'available',
                'users.pts',
                'drift'
            );
            foreach ($rows as $member) {
                printf(
                    "  %8d %7d %10d %10d %10d %8d\n",
                    $member['user_id'],
                    $member['ledger_rows'],
                    $member['ledger_balance'],
                    $member['users_available'],
                    $member['users_points'],
                    $member['drift']
                );
            }
        }
        echo "\n";
    }

    echo "=== Totals ===\n";
    foreach (RECONCILE_BUCKETS as $bucket) {
        $flag = in_array($bucket, RECONCILE_HEALTHY, true) ? ' ' : '*';
        printf("%s %-12s %8d\n", $flag, $bucket, $report['totals'][$bucket]);
    }

    echo "\n=== Outstanding point liability by store ===\n";
    printf("  %-16s %12d\n", 'ledger', $report['point_liability']['ledger']);
    printf("  %-16s %12d\n", 'users cache', $report['point_liability']['cache']);
    printf("  %-16s %12d\n", 'legacy history', $report['point_liability']['legacy_history']);
    printf("  %-16s %12d  %s\n", 'retail (8th)', $report['point_liability']['retail'],
        $report['point_liability']['retail'] > 0 ? '* parallel store, reconciled against nothing' : '');

    echo "\n";
    if ($needsAttention === 0) {
        echo "OK — every member is EMPTY or MATCHED.\n";

        return;
    }

    echo "{$needsAttention} member(s) need attention (marked *).\n";
    echo "Nothing was modified. See §34 of the plan before repairing any bucket.\n";
}
