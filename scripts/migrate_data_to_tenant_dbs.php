<?php
declare(strict_types=1);

/**
 * migrate_data_to_tenant_dbs.php
 *
 * One-shot data migration: split the legacy shared MariaDB schema
 * `zrismpsz_demo` into five per-tenant databases (`zrismpsz_reya_t_0001` ..
 * `zrismpsz_reya_t_0005`) as defined by ADR-001 (database-per-tenant
 * isolation) and the canonical tenant schema in
 * `database/migration_2026-05-25_tenant_template.sql`.
 *
 * Source DB: `zrismpsz_demo` on host 118.27.146.16 (port 3306 — SSH is 9922
 *            but MariaDB itself listens on 3306).
 *
 * Targets (one per row in source `line_accounts`):
 *   id=1 -> zrismpsz_reya_t_0001
 *   id=2 -> zrismpsz_reya_t_0002
 *   id=3 -> zrismpsz_reya_t_0003
 *   id=4 -> zrismpsz_reya_t_0004
 *   id=5 -> zrismpsz_reya_t_0005
 *
 * Behaviour:
 *   php migrate_data_to_tenant_dbs.php [--dry-run] [--tenant=N] [--table=tbl] [--verify]
 *
 *   --dry-run  Print every SQL that would run; execute nothing.
 *   --tenant=N Limit to one tenant id (still respects per-table rules).
 *   --table=t  Limit to one table name.
 *   --verify   After migration, compare source-vs-target row counts.
 *
 * Connections:
 *   - Source PDO: built explicitly from env or fallback constants below.
 *     The legacy DB is NOT in the tenant registry, so we DO NOT use
 *     Database::forTenant() / Database::platform() for it.
 *   - Target PDOs: one PDO per target DB, all using the same MariaDB user
 *     (which has been GRANTed on each tenant DB via cPanel uapi).
 *
 * IMPORTANT — Read this before running on prod:
 *   1. All five target databases MUST already exist with the canonical
 *      tenant template applied (database/migration_2026-05-25_tenant_template.sql).
 *   2. We migrate WITH FOREIGN_KEY_CHECKS = 0 so insert order does not matter.
 *      FK violations are NOT caught by this script — verify with mysqlcheck
 *      afterwards if you need to be sure.
 *   3. AUTO_INCREMENT ids are preserved verbatim (we INSERT the id column).
 *      Cross-table FK references stay intact within a single tenant DB.
 *   4. Per-table transactions: a single bad row rolls back THAT TABLE for
 *      THAT TENANT. Other tables/tenants keep going. Errors land in the
 *      JSON log + stdout summary.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/** Source DB credentials. Override via env vars to avoid hard-coding here. */
const SOURCE_DB_NAME = 'zrismpsz_demo';

$SOURCE_DB_HOST = getenv('REYA_SRC_DB_HOST') ?: '118.27.146.16';
$SOURCE_DB_PORT = (int) (getenv('REYA_SRC_DB_PORT') ?: 3306);
$SOURCE_DB_USER = getenv('REYA_SRC_DB_USER') ?: 'zrismpsz_demo';
$SOURCE_DB_PASS = getenv('REYA_SRC_DB_PASS') ?: '';

/** Target host (same MariaDB instance — cPanel-hosted). */
$TARGET_DB_HOST = getenv('REYA_TGT_DB_HOST') ?: $SOURCE_DB_HOST;
$TARGET_DB_PORT = (int) (getenv('REYA_TGT_DB_PORT') ?: $SOURCE_DB_PORT);
$TARGET_DB_USER = getenv('REYA_TGT_DB_USER') ?: $SOURCE_DB_USER;
$TARGET_DB_PASS = getenv('REYA_TGT_DB_PASS') ?: $SOURCE_DB_PASS;

/** tenant id -> target DB name. Hard-coded per the task brief. */
const TENANT_DB_MAP = [
    1 => 'zrismpsz_reya_t_0001',
    2 => 'zrismpsz_reya_t_0002',
    3 => 'zrismpsz_reya_t_0003',
    4 => 'zrismpsz_reya_t_0004',
    5 => 'zrismpsz_reya_t_0005',
];

/** Path to the canonical tenant schema — used to discover target columns. */
const TEMPLATE_SQL_PATH = __DIR__ . '/../database/migration_2026-05-25_tenant_template.sql';

/** Where logs land. */
const LOG_DIR = __DIR__ . '/logs';

// ---------------------------------------------------------------------------
// Table classification
// ---------------------------------------------------------------------------

/**
 * Tables in the tenant template that the script must NEVER touch — they live
 * in the master DB (`reya_platform`), not per-tenant. Agent B excluded these
 * from the template, but if any future regeneration re-adds them by mistake,
 * we still skip here as a safety net.
 */
const PLATFORM_TABLES = [
    'admin_users',
    'admin_bot_access',
    'admin_activity_log',
    'dev_logs',
    'testing_results',
];

/**
 * Shared master-data tables: same rows copied AS-IS to every tenant DB.
 * No `line_account_id` column, no filtering. Single SELECT * from source,
 * five identical INSERTs.
 */
const REPLICATED_TABLES = [
    'red_flag_symptoms',
    'pharmacy_context_keywords',
];

/**
 * Child tables that are scoped via their PARENT FK, not directly. For these
 * the source has no `line_account_id` column, and even if Agent B added one
 * to the template we resolve tenant by JOINing to the parent in source.
 *
 * Format: child_table => [parent_table, child_fk_column, parent_pk_column]
 * (parent_pk_column is almost always 'id'; included for clarity).
 */
const CHILD_VIA_PARENT_FK = [
    'transaction_items'       => ['transactions',       'transaction_id', 'id'],
    'order_items'             => ['orders',             'order_id',       'id'],
    'goods_receive_items'     => ['goods_receives',     'gr_id',          'id'],
    'purchase_order_items'    => ['purchase_orders',    'po_id',          'id'],
    'business_document_items' => ['business_documents', 'document_id',    'id'],
    'pos_transaction_items'   => ['pos_transactions',   'transaction_id', 'id'],
    'pos_return_items'        => ['pos_returns',        'return_id',      'id'],
    'pos_payments'            => ['pos_transactions',   'transaction_id', 'id'],
    'wms_batch_pick_orders'   => ['wms_batch_picks',    'batch_pick_id',  'id'],
    'wms_pick_items'          => ['wms_batch_pick_orders', 'pick_order_id', 'id'],
    'stock_count_items'       => ['stock_count_sessions','session_id',    'id'],
    'pharmacist_holidays'     => ['pharmacist_schedules','pharmacist_id', 'pharmacist_id'],
    'triage_question_responses' => ['triage_sessions',  'triage_session_id', 'id'],
    'video_call_signals'      => ['video_calls',        'call_id',        'id'],
    'consultation_logs'       => ['appointments',       'appointment_id', 'id'],
    'cart_items'              => ['users',              'user_id',        'id'],
    'payment_slips'           => ['transactions',       'transaction_id', 'id'],
    'payment_proofs'          => ['transactions',       'transaction_id', 'id'],
    'slip_verifications'      => ['payment_slips',      'slip_id',        'id'],
    'prescription_items'      => ['prescription_records','prescription_id','id'],
    'prescription_approvals'  => ['prescription_records','prescription_id','id'],
    'reward_redemptions'      => ['rewards',            'reward_id',      'id'],
    'broadcast_clicks'        => ['broadcast_messages', 'broadcast_id',   'id'],
    'broadcast_items'         => ['broadcast_messages', 'broadcast_id',   'id'],
    'broadcast_queue'         => ['broadcast_messages', 'broadcast_id',   'id'],
    'segment_members'         => ['customer_segments',  'segment_id',     'id'],
    'link_clicks'             => ['tracked_links',      'tracked_link_id','id'],
    'coupon_usage'            => ['coupons',            'coupon_id',      'id'],
    'drip_campaign_logs'      => ['drip_campaigns',     'campaign_id',    'id'],
    'drip_campaign_progress'  => ['drip_campaigns',     'campaign_id',    'id'],
    'drip_campaign_queue'     => ['drip_campaigns',     'campaign_id',    'id'],
    'drip_campaign_steps'     => ['drip_campaigns',     'campaign_id',    'id'],
    'drip_queue'              => ['drip_campaigns',     'campaign_id',    'id'],
    'drip_steps'              => ['drip_campaigns',     'campaign_id',    'id'],
    'scheduled_report_logs'   => ['scheduled_reports',  'report_id',      'id'],
    'scheduled_report_recipients' => ['scheduled_reports','report_id',    'id'],
    'rich_menu_switch_pages'  => ['rich_menu_switch_sets','switch_set_id','id'],
    'auto_tag_logs'           => ['auto_tag_rules',     'rule_id',        'id'],
    'conversation_assignees'  => ['conversation_assignments','assignment_id','id'],
    'conversation_multi_assignees' => ['conversation_assignments','assignment_id','id'],
    'conversation_states'     => ['users',              'user_id',        'id'],
    'symptom_assessment_followups' => ['symptom_assessments','assessment_id','id'],
    'sla_tracking'            => ['conversation_assignments','assignment_id','id'],
    'medication_taken_history' => ['medication_reminders','reminder_id',  'id'],
    'medication_refill_tracking' => ['users',           'user_id',        'id'],
    'message_analytics'       => ['messages',           'message_id',     'id'],
    'image_analysis_results'  => ['messages',           'message_id',     'id'],
    'mims_conversation_state' => ['users',              'user_id',        'id'],
];

/**
 * Orphan tables that the source has no FK path to a tenant-scoped row.
 * These are skipped and logged. Human must decide.
 */
const TRULY_ORPHAN_TABLES = [
    // Add table names here if discovered during dry-run. Currently empty —
    // the CHILD_VIA_PARENT_FK map above resolves all known orphans.
];

// ---------------------------------------------------------------------------
// CLI args
// ---------------------------------------------------------------------------

$opts = [
    'dry_run' => false,
    'tenant'  => null,    // null = all
    'table'   => null,    // null = all
    'verify'  => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')                  { $opts['dry_run'] = true; continue; }
    if ($arg === '--verify')                   { $opts['verify']  = true; continue; }
    if (str_starts_with($arg, '--tenant='))    { $opts['tenant'] = (int) substr($arg, 9); continue; }
    if (str_starts_with($arg, '--table='))     { $opts['table']  = substr($arg, 8); continue; }
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2500));
        exit(0);
    }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(2);
}

$tenantsToRun = $opts['tenant'] !== null
    ? (isset(TENANT_DB_MAP[$opts['tenant']]) ? [$opts['tenant']] : [])
    : array_keys(TENANT_DB_MAP);

if (empty($tenantsToRun)) {
    fwrite(STDERR, "Unknown tenant id: {$opts['tenant']}\n");
    exit(2);
}

$mode = $opts['verify'] ? 'VERIFY' : ($opts['dry_run'] ? 'DRY-RUN' : 'LIVE');

if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0755, true);
}
$startedAt = date('Ymd_His');
$logPath   = LOG_DIR . DIRECTORY_SEPARATOR . "migrate_data_{$startedAt}.json";
$orphanLog = LOG_DIR . DIRECTORY_SEPARATOR . 'orphan_rows_' . date('Ymd') . '.log';

out("=== migrate_data_to_tenant_dbs.php  mode=$mode  started=$startedAt ===");
out("Source DB: " . SOURCE_DB_NAME . "@" . $SOURCE_DB_HOST . ":" . $SOURCE_DB_PORT);
out("Tenants: " . implode(',', $tenantsToRun));
if ($opts['table'] !== null) {
    out("Table filter: {$opts['table']}");
}
out("Log file:  $logPath");
out('');

// ---------------------------------------------------------------------------
// Connections
// ---------------------------------------------------------------------------

$sourcePdo = connect(SOURCE_DB_NAME, $SOURCE_DB_HOST, $SOURCE_DB_PORT, $SOURCE_DB_USER, $SOURCE_DB_PASS);

/** @var array<int,\PDO> */
$targetPdos = [];
foreach ($tenantsToRun as $tid) {
    $dbName = TENANT_DB_MAP[$tid];
    $targetPdos[$tid] = connect($dbName, $TARGET_DB_HOST, $TARGET_DB_PORT, $TARGET_DB_USER, $TARGET_DB_PASS);
}

// ---------------------------------------------------------------------------
// Discover table set + columns
// ---------------------------------------------------------------------------

$templateTables = parseTemplateTables(TEMPLATE_SQL_PATH);
out("Template tables: " . count($templateTables));

if ($opts['table'] !== null) {
    if (!in_array($opts['table'], $templateTables, true)) {
        fwrite(STDERR, "Table '{$opts['table']}' is not in the tenant template.\n");
        exit(2);
    }
    $templateTables = [$opts['table']];
}

$sourceColumns = discoverColumns($sourcePdo, SOURCE_DB_NAME);

/** target column maps keyed by tenant id then table name */
$targetColumns = [];
foreach ($targetPdos as $tid => $pdo) {
    $targetColumns[$tid] = discoverColumns($pdo, TENANT_DB_MAP[$tid]);
}

// ---------------------------------------------------------------------------
// Verify-only mode
// ---------------------------------------------------------------------------

if ($opts['verify']) {
    runVerify($sourcePdo, $targetPdos, $templateTables, $sourceColumns, $targetColumns, $logPath, $tenantsToRun);
    exit(0);
}

// ---------------------------------------------------------------------------
// Migration loop
// ---------------------------------------------------------------------------

/** Per-tenant + per-table accumulator for the JSON log. */
$report = [
    'mode'        => $mode,
    'started_at'  => $startedAt,
    'source_db'   => SOURCE_DB_NAME,
    'tenants'     => [],
    'orphan_log'  => $orphanLog,
];

$tStart = microtime(true);

foreach ($tenantsToRun as $tid) {
    $targetDb  = TENANT_DB_MAP[$tid];
    $targetPdo = $targetPdos[$tid];

    out(str_repeat('-', 72));
    out("Tenant $tid -> $targetDb");
    out(str_repeat('-', 72));

    $tenantReport = [
        'target_db' => $targetDb,
        'tables'    => [],
        'totals'    => ['copied' => 0, 'skipped' => 0, 'errors' => 0, 'replicated' => 0],
    ];

    if (!$opts['dry_run']) {
        $targetPdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $targetPdo->exec("SET time_zone = '+07:00'");
    }

    foreach ($templateTables as $table) {
        // Skip platform tables defensively.
        if (in_array($table, PLATFORM_TABLES, true)) {
            $tenantReport['tables'][$table] = ['status' => 'skipped_platform', 'rows' => 0];
            continue;
        }

        if (!isset($sourceColumns[$table])) {
            $tenantReport['tables'][$table] = ['status' => 'skipped_not_on_source', 'rows' => 0];
            continue;
        }

        try {
            // ----- 1. Replicated master data -----
            if (in_array($table, REPLICATED_TABLES, true)) {
                $n = migrateReplicated($sourcePdo, $targetPdo, $table, $sourceColumns[$table], $targetColumns[$tid][$table] ?? [], $opts['dry_run']);
                $tenantReport['tables'][$table] = ['status' => 'replicated', 'rows' => $n];
                $tenantReport['totals']['replicated'] += $n;
                out(sprintf("  [REPL] %-40s %5d rows", $table, $n));
                continue;
            }

            // ----- 2. The line_accounts row itself -----
            if ($table === 'line_accounts') {
                $n = migrateLineAccountsRow($sourcePdo, $targetPdo, $tid, $sourceColumns['line_accounts'], $targetColumns[$tid]['line_accounts'] ?? [], $opts['dry_run']);
                $tenantReport['tables'][$table] = ['status' => 'line_accounts_row', 'rows' => $n];
                $tenantReport['totals']['copied'] += $n;
                out(sprintf("  [LA  ] %-40s %5d rows", $table, $n));
                continue;
            }

            // ----- 3. Child-via-parent-FK tables -----
            if (isset(CHILD_VIA_PARENT_FK[$table])) {
                [$parent, $childFk, $parentPk] = CHILD_VIA_PARENT_FK[$table];

                if (!isset($sourceColumns[$parent])) {
                    appendOrphanLog($orphanLog, "$table: parent '$parent' not on source");
                    $tenantReport['tables'][$table] = ['status' => 'skipped_no_parent', 'rows' => 0];
                    continue;
                }

                $n = migrateChildViaParent(
                    $sourcePdo,
                    $targetPdo,
                    $table,
                    $parent,
                    $childFk,
                    $parentPk,
                    $tid,
                    $sourceColumns[$table],
                    $targetColumns[$tid][$table] ?? [],
                    $opts['dry_run']
                );
                $tenantReport['tables'][$table] = ['status' => 'child_via_parent', 'rows' => $n, 'parent' => $parent];
                $tenantReport['totals']['copied'] += $n;
                out(sprintf("  [JOIN] %-40s %5d rows  (via %s.%s)", $table, $n, $parent, $childFk));
                continue;
            }

            // ----- 4. Truly orphan tables we couldn't resolve -----
            if (in_array($table, TRULY_ORPHAN_TABLES, true)) {
                $rowCount = (int) $sourcePdo->query("SELECT COUNT(*) FROM `" . SOURCE_DB_NAME . "`.`$table`")->fetchColumn();
                appendOrphanLog($orphanLog, "$table: {$rowCount} orphan rows — no FK path to tenant");
                $tenantReport['tables'][$table] = ['status' => 'orphan_skipped', 'rows' => $rowCount];
                out(sprintf("  [SKIP] %-40s %5d rows  (truly orphan)", $table, $rowCount));
                continue;
            }

            // ----- 5. Natively scoped: source has line_account_id -----
            if (in_array('line_account_id', $sourceColumns[$table], true)) {
                $n = migrateNativeScoped(
                    $sourcePdo,
                    $targetPdo,
                    $table,
                    $tid,
                    $sourceColumns[$table],
                    $targetColumns[$tid][$table] ?? [],
                    $opts['dry_run']
                );
                $tenantReport['tables'][$table] = ['status' => 'native_scoped', 'rows' => $n];
                $tenantReport['totals']['copied'] += $n;
                out(sprintf("  [NATV] %-40s %5d rows", $table, $n));
                continue;
            }

            // ----- 6. Fallback: source has no line_account_id and we have no plan -----
            $rowCount = (int) $sourcePdo->query("SELECT COUNT(*) FROM `" . SOURCE_DB_NAME . "`.`$table`")->fetchColumn();
            if ($rowCount === 0) {
                $tenantReport['tables'][$table] = ['status' => 'skipped_empty', 'rows' => 0];
                continue;
            }
            appendOrphanLog($orphanLog, "$table: {$rowCount} rows on source, no resolve plan");
            $tenantReport['tables'][$table] = ['status' => 'orphan_unhandled', 'rows' => $rowCount];
            out(sprintf("  [ORPH] %-40s %5d rows  (NEEDS HUMAN)", $table, $rowCount));
        } catch (\Throwable $e) {
            $tenantReport['tables'][$table] = ['status' => 'error', 'message' => $e->getMessage()];
            $tenantReport['totals']['errors']++;
            out(sprintf("  [ERR ] %-40s %s", $table, $e->getMessage()));
        }
    }

    if (!$opts['dry_run']) {
        $targetPdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    $report['tenants'][$tid] = $tenantReport;
}

$report['finished_at']   = date('Ymd_His');
$report['runtime_sec']   = round(microtime(true) - $tStart, 2);

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

out('');
out('=== SUMMARY ===');
foreach ($report['tenants'] as $tid => $tr) {
    $copied = $tr['totals']['copied'];
    $repl   = $tr['totals']['replicated'];
    $errs   = $tr['totals']['errors'];
    out(sprintf("Tenant %d (%s): copied=%d replicated=%d errors=%d", $tid, $tr['target_db'], $copied, $repl, $errs));
}
out(sprintf("Total runtime: %.1fs", $report['runtime_sec']));
out("JSON log: $logPath");
@file_put_contents($logPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');

exit(0);

// ===========================================================================
// Functions
// ===========================================================================

/**
 * Connect to a single DB with strict settings (exceptions on, no emulated prepares).
 */
function connect(string $dbName, string $host, int $port, string $user, string $pass): \PDO
{
    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
    $pdo = new \PDO(
        $dsn,
        $user,
        $pass,
        [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]
    );
    $pdo->exec("SET time_zone = '+07:00'");
    return $pdo;
}

/**
 * Parse the canonical tenant template SQL to get the authoritative
 * ordered list of table names.
 */
function parseTemplateTables(string $path): array
{
    if (!is_file($path)) {
        throw new \RuntimeException("Template SQL not found: $path");
    }
    $sql = (string) file_get_contents($path);
    $tables = [];
    if (preg_match_all('/^CREATE TABLE IF NOT EXISTS\s+`([a-z0-9_]+)`/mi', $sql, $m)) {
        $tables = array_values(array_unique($m[1]));
    }
    if (empty($tables)) {
        throw new \RuntimeException("Parsed 0 tables from template — file format unexpected.");
    }
    return $tables;
}

/**
 * Build [tableName => [col1, col2, ...]] for one schema.
 */
function discoverColumns(\PDO $pdo, string $schema): array
{
    $sql = "SELECT TABLE_NAME, COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME, ORDINAL_POSITION";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$schema]);
    $out = [];
    foreach ($stmt as $row) {
        $out[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
    }
    return $out;
}

/**
 * Replicated master data — same rows copied to every tenant DB.
 * Uses INSERT IGNORE because the row may already be present from a previous
 * provisioning seed.
 */
function migrateReplicated(\PDO $src, \PDO $tgt, string $table, array $srcCols, array $tgtCols, bool $dryRun): int
{
    $commonCols = array_values(array_intersect($srcCols, $tgtCols));
    if (empty($commonCols)) {
        throw new \RuntimeException("$table: no common columns between source and target");
    }
    $colList = '`' . implode('`, `', $commonCols) . '`';
    $select  = "SELECT $colList FROM `" . SOURCE_DB_NAME . "`.`$table`";

    if ($dryRun) {
        out("    DRY: INSERT IGNORE INTO `$table` ($colList) VALUES ... (from: $select)");
        return (int) $src->query("SELECT COUNT(*) FROM `" . SOURCE_DB_NAME . "`.`$table`")->fetchColumn();
    }

    $rows = $src->query($select)->fetchAll(\PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return 0;
    }
    $placeholders = '(' . implode(', ', array_fill(0, count($commonCols), '?')) . ')';
    $insertSql = "INSERT IGNORE INTO `$table` ($colList) VALUES $placeholders";

    return insertManyTransactional($tgt, $table, $insertSql, $rows, $commonCols);
}

/**
 * Insert the ONE line_accounts row that matches this tenant id.
 * The tenant DB starts with an empty (or template-seeded) line_accounts table.
 */
function migrateLineAccountsRow(\PDO $src, \PDO $tgt, int $tenantId, array $srcCols, array $tgtCols, bool $dryRun): int
{
    $commonCols = array_values(array_intersect($srcCols, $tgtCols));
    if (empty($commonCols)) {
        throw new \RuntimeException("line_accounts: no common columns between source and target");
    }
    $colList = '`' . implode('`, `', $commonCols) . '`';
    $select  = "SELECT $colList FROM `" . SOURCE_DB_NAME . "`.`line_accounts` WHERE id = ?";

    if ($dryRun) {
        out("    DRY: INSERT IGNORE INTO `line_accounts` ($colList) SELECT ... WHERE id=$tenantId");
        return 1;
    }

    $stmt = $src->prepare($select);
    $stmt->execute([$tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }
    $placeholders = '(' . implode(', ', array_fill(0, count($commonCols), '?')) . ')';
    $insertSql = "INSERT IGNORE INTO `line_accounts` ($colList) VALUES $placeholders";

    return insertManyTransactional($tgt, 'line_accounts', $insertSql, [$row], $commonCols);
}

/**
 * Source table already has `line_account_id` — straight scoped copy.
 *
 * Columns we INSERT are the intersection of source and target columns.
 * If the template added new columns (e.g. orphan-fix line_account_id on an
 * orphan that ALSO already had line_account_id — shouldn't happen, but safe),
 * the target gets DEFAULT for any column not in the intersection.
 */
function migrateNativeScoped(\PDO $src, \PDO $tgt, string $table, int $tenantId, array $srcCols, array $tgtCols, bool $dryRun): int
{
    $commonCols = array_values(array_intersect($srcCols, $tgtCols));
    if (empty($commonCols)) {
        throw new \RuntimeException("$table: no common columns between source and target");
    }
    $colList = '`' . implode('`, `', $commonCols) . '`';

    // Some prod rows leave line_account_id NULL. Match them to tenant 1 (the
    // historical default before multi-account).
    $whereLa = $tenantId === 1
        ? "(`line_account_id` = ? OR `line_account_id` IS NULL)"
        : "`line_account_id` = ?";

    $select = "SELECT $colList FROM `" . SOURCE_DB_NAME . "`.`$table` WHERE $whereLa";

    if ($dryRun) {
        $st = $src->prepare("SELECT COUNT(*) FROM `" . SOURCE_DB_NAME . "`.`$table` WHERE $whereLa");
        $st->execute([$tenantId]);
        $cnt = (int) $st->fetchColumn();
        out("    DRY: INSERT INTO `$table` ($colList) VALUES ... (count=$cnt, tenant=$tenantId)");
        return $cnt;
    }

    $stmt = $src->prepare($select);
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return 0;
    }

    // Force line_account_id = $tenantId for rows that came in as NULL.
    if (in_array('line_account_id', $commonCols, true)) {
        foreach ($rows as &$r) {
            if ($r['line_account_id'] === null) {
                $r['line_account_id'] = $tenantId;
            }
        }
        unset($r);
    }

    $placeholders = '(' . implode(', ', array_fill(0, count($commonCols), '?')) . ')';
    $insertSql = "INSERT INTO `$table` ($colList) VALUES $placeholders";

    return insertManyTransactional($tgt, $table, $insertSql, $rows, $commonCols);
}

/**
 * Resolve the tenant via a JOIN through the parent table, then copy.
 *
 * SELECT child.<cols> FROM source.child
 *   JOIN source.parent ON parent.<pk> = child.<fk>
 *  WHERE parent.line_account_id = :tid
 *
 * For the rare case where the parent itself has no line_account_id (e.g.
 * the parent is also an orphan), we recurse one level: parent's parent_fk
 * resolved via its own CHILD_VIA_PARENT_FK entry. Currently the map is
 * shallow enough that one extra JOIN level is sufficient.
 */
function migrateChildViaParent(
    \PDO $src,
    \PDO $tgt,
    string $child,
    string $parent,
    string $childFk,
    string $parentPk,
    int $tenantId,
    array $childCols,
    array $tgtCols,
    bool $dryRun
): int {
    $commonCols = array_values(array_intersect($childCols, $tgtCols));
    if (empty($commonCols)) {
        throw new \RuntimeException("$child: no common columns between source and target");
    }
    // Strip line_account_id from SELECT — we set it explicitly to $tenantId.
    // The template guarantees it's present on the target.
    $selectCols = array_values(array_filter($commonCols, fn($c) => $c !== 'line_account_id'));
    $childQualified = implode(', ', array_map(fn($c) => "`c`.`$c`", $selectCols));

    $whereTenant = "(`p`.`line_account_id` = ? OR `p`.`line_account_id` IS NULL)";
    $tenantParam = $tenantId;

    // Recurse one level if parent itself is a child-via-parent table.
    if (!parentHasLineAccountId($src, $parent)) {
        if (isset(CHILD_VIA_PARENT_FK[$parent])) {
            [$grandparent, $parentFk, $gpPk] = CHILD_VIA_PARENT_FK[$parent];
            $select =
                "SELECT $childQualified FROM `" . SOURCE_DB_NAME . "`.`$child` AS c
                  JOIN `" . SOURCE_DB_NAME . "`.`$parent`      AS p  ON p.`$parentPk` = c.`$childFk`
                  JOIN `" . SOURCE_DB_NAME . "`.`$grandparent` AS gp ON gp.`$gpPk`    = p.`$parentFk`
                 WHERE (`gp`.`line_account_id` = ? OR `gp`.`line_account_id` IS NULL)";
        } else {
            throw new \RuntimeException("$child -> $parent: parent has no line_account_id and no resolve plan");
        }
    } else {
        $select =
            "SELECT $childQualified FROM `" . SOURCE_DB_NAME . "`.`$child`  AS c
              JOIN `" . SOURCE_DB_NAME . "`.`$parent` AS p ON p.`$parentPk` = c.`$childFk`
             WHERE $whereTenant";
    }

    if ($dryRun) {
        // Replace only the leading SELECT-list with COUNT(*) to keep the
        // count query unambiguous regardless of column-list contents.
        $countSelect = preg_replace('/^SELECT\s+.*?\s+FROM/si', 'SELECT COUNT(*) FROM', $select, 1);
        $st = $src->prepare($countSelect);
        $st->execute([$tenantParam]);
        $cnt = (int) $st->fetchColumn();
        out("    DRY: $select  -- count=$cnt tenant=$tenantId");
        return $cnt;
    }

    $stmt = $src->prepare($select);
    $stmt->execute([$tenantParam]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    if (empty($rows)) {
        return 0;
    }

    // Append synthetic line_account_id column to every row.
    if (in_array('line_account_id', $commonCols, true)) {
        foreach ($rows as &$r) {
            $r['line_account_id'] = $tenantId;
        }
        unset($r);
        // Reorder rows to match $commonCols order for the placeholder map.
    }

    $insertCols   = $commonCols;
    $colList      = '`' . implode('`, `', $insertCols) . '`';
    $placeholders = '(' . implode(', ', array_fill(0, count($insertCols), '?')) . ')';
    $insertSql    = "INSERT INTO `$child` ($colList) VALUES $placeholders";

    return insertManyTransactional($tgt, $child, $insertSql, $rows, $insertCols);
}

/**
 * Cached probe — does table T on source have a `line_account_id` column?
 */
function parentHasLineAccountId(\PDO $src, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $stmt = $src->prepare(
        "SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = 'line_account_id' LIMIT 1"
    );
    $stmt->execute([SOURCE_DB_NAME, $table]);
    return $cache[$table] = (bool) $stmt->fetchColumn();
}

/**
 * Wrap a bulk INSERT in a transaction. On failure roll back THIS TABLE
 * (the connection-wide FOREIGN_KEY_CHECKS = 0 stays in effect).
 */
function insertManyTransactional(\PDO $tgt, string $table, string $sql, array $rows, array $colsInOrder): int
{
    if (empty($rows)) {
        return 0;
    }
    $tgt->beginTransaction();
    try {
        $stmt = $tgt->prepare($sql);
        $n = 0;
        foreach ($rows as $row) {
            $values = [];
            foreach ($colsInOrder as $c) {
                $values[] = $row[$c] ?? null;
            }
            $stmt->execute($values);
            $n++;
        }
        $tgt->commit();
        return $n;
    } catch (\Throwable $e) {
        $tgt->rollBack();
        throw new \RuntimeException("Insert failed for $table: " . $e->getMessage(), 0, $e);
    }
}

/**
 * Verify mode: source row count (filtered by tenant) vs target row count.
 */
function runVerify(\PDO $src, array $targetPdos, array $tables, array $srcCols, array $tgtCols, string $logPath, array $tenants): void
{
    $report = ['mode' => 'VERIFY', 'tenants' => []];
    out('=== VERIFY ===');
    foreach ($tenants as $tid) {
        $targetPdo = $targetPdos[$tid];
        $dbName    = TENANT_DB_MAP[$tid];
        out("Tenant $tid -> $dbName");
        $tenantOut = [];
        foreach ($tables as $table) {
            if (in_array($table, PLATFORM_TABLES, true)) {
                continue;
            }
            if (!isset($srcCols[$table])) {
                continue;
            }

            $expected = 0;
            if (in_array($table, REPLICATED_TABLES, true)) {
                $expected = (int) $src->query("SELECT COUNT(*) FROM `" . SOURCE_DB_NAME . "`.`$table`")->fetchColumn();
            } elseif ($table === 'line_accounts') {
                $st = $src->prepare("SELECT COUNT(*) FROM `" . SOURCE_DB_NAME . "`.`line_accounts` WHERE id = ?");
                $st->execute([$tid]);
                $expected = (int) $st->fetchColumn();
            } elseif (isset(CHILD_VIA_PARENT_FK[$table])) {
                [$parent, $childFk, $parentPk] = CHILD_VIA_PARENT_FK[$table];
                if (!isset($srcCols[$parent])) { continue; }
                if (parentHasLineAccountId($src, $parent)) {
                    $sql = "SELECT COUNT(*) FROM `" . SOURCE_DB_NAME . "`.`$table` c
                              JOIN `" . SOURCE_DB_NAME . "`.`$parent` p ON p.`$parentPk` = c.`$childFk`
                             WHERE (p.line_account_id = ? OR p.line_account_id IS NULL)";
                    $st = $src->prepare($sql);
                    $st->execute([$tid]);
                    $expected = (int) $st->fetchColumn();
                }
            } elseif (in_array('line_account_id', $srcCols[$table], true)) {
                $sql = "SELECT COUNT(*) FROM `" . SOURCE_DB_NAME . "`.`$table` WHERE line_account_id = ?"
                    . ($tid === 1 ? " OR line_account_id IS NULL" : "");
                $st = $src->prepare($sql);
                $st->execute([$tid]);
                $expected = (int) $st->fetchColumn();
            } else {
                continue;
            }

            $actual = (int) $targetPdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $ok = ($expected === $actual);
            $tenantOut[$table] = ['expected' => $expected, 'actual' => $actual, 'ok' => $ok];
            if (!$ok || $expected > 0) {
                out(sprintf("  %s %-40s expected=%d actual=%d", $ok ? 'OK ' : 'MISMATCH', $table, $expected, $actual));
            }
        }
        $report['tenants'][$tid] = $tenantOut;
    }
    @file_put_contents($logPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
    out("Verify log: $logPath");
}

function appendOrphanLog(string $path, string $line): void
{
    @file_put_contents($path, '[' . date('c') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}
