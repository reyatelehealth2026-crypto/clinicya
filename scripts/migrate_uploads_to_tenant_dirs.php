<?php
declare(strict_types=1);

/**
 * migrate_uploads_to_tenant_dirs.php
 *
 * One-shot migration: move files in flat uploads/slips/ (and friends) into
 * per-tenant directories — uploads/tenant_NNNN/slips/... — and update the DB
 * rows that reference them. See ADR-001 + docs/file-storage-migration-plan.md.
 *
 * Why this exists:
 *   Today every tenant's payment slips live in ONE shared directory with
 *   predictable filenames (slip_<order_number>_<unixtime>.<ext>). Any admin
 *   that can guess a URL pattern can fetch ANY tenant's slip. After this
 *   script runs, each tenant's files live under uploads/tenant_NNNN/slips/
 *   and the matching DB row gets a tenant_id stamp.
 *
 * What it does:
 *   1. Scans uploads/slips/ for files matching slip_<token>_<unixtime>.<ext>
 *   2. Extracts the order/transaction/dispense token from the filename
 *   3. Looks the token up across:
 *        - dispensing_records.order_number  (DIS...)
 *        - transactions.order_number        (TXN...)
 *        - orders.order_number              (ORD..., if table exists)
 *        - payment_slips.image_url LIKE ?   (fallback — old rows store full URL)
 *      Each of those tables has a line_account_id column we can copy.
 *   4. If a matching tenant is found:
 *        mv uploads/slips/<file>  ->  uploads/tenant_NNNN/slips/<file>
 *      and update payment_slips.image_url so the new URL is used by the app.
 *   5. If no tenant can be inferred, the file is LEFT IN PLACE and logged to
 *      uploads/_orphan_slips.log so a human can decide what to do.
 *
 * Modes (all flags combinable, but --verify is read-only):
 *   --dry-run        Print every action; do not move or update anything.
 *   --verify         Read-only audit: list orphan files + DB rows whose
 *                    image_url points at a file that no longer exists on disk.
 *   --base-url=URL   Override BASE_URL detection (used when rebuilding the
 *                    image_url column). Defaults to BASE_URL constant.
 *   --tenant=N       Only migrate files belonging to tenant id N (others
 *                    are reported as skipped, not as orphans).
 *
 * Safety properties:
 *   - IDEMPOTENT — files already under tenant_NNNN/ are skipped (re-runs
 *     after a partial failure are safe).
 *   - VERBOSE — every action prints to stdout.
 *   - REVERSIBLE — every successful mv is appended to uploads/_migration_undo.sh
 *     as a literal `mv <new> <old>` line.
 *
 * NOT in scope for this script:
 *   - Touching uploads/products/  (already keyed by product_id; no leak today)
 *   - Touching uploads/cache/     (system files, no tenant data)
 *   - Touching uploads/payment_slips/ used by retail-api (handled in a sibling
 *     migration once retail-api is part of the tenant model)
 *
 * Reads the LEGACY shared DB directly via Database::getInstance() — does NOT
 * use TenantContext, because at the moment this runs there is no tenant scope yet.
 *
 * Usage:
 *   php scripts/migrate_uploads_to_tenant_dirs.php --dry-run
 *   php scripts/migrate_uploads_to_tenant_dirs.php --verify
 *   php scripts/migrate_uploads_to_tenant_dirs.php          # the real move
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/TenantFileStorage.php';

// ---------------------------------------------------------------------- args
$opts = [
    'dry_run'  => false,
    'verify'   => false,
    'base_url' => defined('BASE_URL') ? rtrim((string) constant('BASE_URL'), '/') : '',
    'tenant'   => null,
];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run')        { $opts['dry_run']  = true; continue; }
    if ($arg === '--verify')         { $opts['verify']   = true; continue; }
    if (str_starts_with($arg, '--base-url=')) { $opts['base_url'] = rtrim(substr($arg, 11), '/'); continue; }
    if (str_starts_with($arg, '--tenant='))   { $opts['tenant']   = (int) substr($arg, 9);        continue; }
    fwrite(STDERR, "Unknown arg: $arg\n");
    exit(2);
}

$mode = $opts['verify'] ? 'VERIFY' : ($opts['dry_run'] ? 'DRY-RUN' : 'LIVE');
out("=== migrate_uploads_to_tenant_dirs.php  mode=$mode ===");

// ---------------------------------------------------------------------- setup
$db = Database::getInstance()->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$projectRoot   = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$uploadsRoot   = $projectRoot . DIRECTORY_SEPARATOR . 'uploads';
$flatSlipsDir  = $uploadsRoot . DIRECTORY_SEPARATOR . 'slips';
$orphanLogPath = $uploadsRoot . DIRECTORY_SEPARATOR . '_orphan_slips.log';
$undoScript    = $uploadsRoot . DIRECTORY_SEPARATOR . '_migration_undo.sh';

if (!is_dir($flatSlipsDir)) {
    out("No flat slips directory at: $flatSlipsDir — nothing to do.");
    exit(0);
}

// ---------------------------------------------------------------------- verify
if ($opts['verify']) {
    runVerify($db, $flatSlipsDir, $uploadsRoot);
    exit(0);
}

// ---------------------------------------------------------------------- migrate
$counts = ['scanned' => 0, 'skipped_already_tenant' => 0, 'moved' => 0, 'orphan' => 0, 'errors' => 0, 'skipped_filter' => 0];

if (!$opts['dry_run']) {
    @file_put_contents($undoScript, "#!/usr/bin/env bash\n# auto-generated rollback for migrate_uploads_to_tenant_dirs.php\nset -euo pipefail\n", LOCK_EX);
    @chmod($undoScript, 0750);
}

$dh = opendir($flatSlipsDir);
if ($dh === false) {
    fwrite(STDERR, "Cannot open: $flatSlipsDir\n");
    exit(3);
}

while (($entry = readdir($dh)) !== false) {
    if ($entry === '.' || $entry === '..') continue;

    $fullPath = $flatSlipsDir . DIRECTORY_SEPARATOR . $entry;
    if (!is_file($fullPath)) continue;
    if (!preg_match('/\A[A-Za-z0-9._-]+\z/', $entry)) {
        out("  SKIP weird name: $entry");
        $counts['errors']++;
        continue;
    }

    // Skip our own log files
    if ($entry === '.htaccess' || $entry === '.gitkeep' || str_starts_with($entry, '_')) {
        continue;
    }

    $counts['scanned']++;

    $tenantId = lookupTenantForSlipFilename($db, $entry);
    if ($tenantId === null) {
        appendOrphan($orphanLogPath, $entry, $opts['dry_run']);
        $counts['orphan']++;
        out("  ORPHAN  $entry");
        continue;
    }

    if ($opts['tenant'] !== null && $tenantId !== $opts['tenant']) {
        out("  filter-skip $entry (tenant=$tenantId)");
        $counts['skipped_filter']++;
        continue;
    }

    $destDir  = $uploadsRoot . DIRECTORY_SEPARATOR . TenantFileStorage::tenantDirName($tenantId) . DIRECTORY_SEPARATOR . 'slips';
    $destFull = $destDir . DIRECTORY_SEPARATOR . $entry;

    if (is_file($destFull)) {
        out("  already-tenant  $entry -> tenant_{$tenantId}/slips/  (skip)");
        $counts['skipped_already_tenant']++;
        continue;
    }

    if ($opts['dry_run']) {
        out("  DRY mv $entry -> tenant_{$tenantId}/slips/");
        $counts['moved']++;
        continue;
    }

    try {
        TenantFileStorage::ensureDir($tenantId, 'slips');
        if (!@rename($fullPath, $destFull)) {
            throw new RuntimeException('rename failed');
        }
        @chmod($destFull, 0640);

        $rowsUpdated = updateSlipReferences($db, $entry, $tenantId, $opts['base_url']);
        appendUndo($undoScript, $destFull, $fullPath);

        out(sprintf('  MOVED %s -> tenant_%04d/slips/  (db rows updated: %d)', $entry, $tenantId, $rowsUpdated));
        $counts['moved']++;
    } catch (Throwable $e) {
        out('  ERROR ' . $entry . ' : ' . $e->getMessage());
        $counts['errors']++;
    }
}
closedir($dh);

out('--- summary ---');
foreach ($counts as $k => $v) {
    out(sprintf('  %-24s %d', $k, $v));
}
out("Done. Mode=$mode");
if (!$opts['dry_run'] && file_exists($undoScript)) {
    out("Rollback script written: $undoScript");
}
exit(0);

// ============================================================== helpers below

/**
 * Given a flat filename like "slip_DIS202512174335_1766132063.jpg",
 * find the line_account_id (= tenant id) that owns it.
 * Returns null if we cannot prove ownership.
 */
function lookupTenantForSlipFilename(PDO $db, string $filename): ?int
{
    // Extract the token between "slip_" and the trailing "_<unixtime>.<ext>"
    if (!preg_match('/\Aslip_(.+?)_\d{6,}\.[A-Za-z0-9]+\z/', $filename, $m)) {
        return null;
    }
    $token = $m[1];

    // dispensing_records uses 'DIS' prefix and stores order_number + line_account_id
    if (str_starts_with($token, 'DIS')) {
        if (($t = scalarTenant($db, "SELECT line_account_id FROM dispensing_records WHERE order_number = ? LIMIT 1", [$token])) !== null) {
            return $t;
        }
    }

    // transactions uses 'TXN' prefix
    if (str_starts_with($token, 'TXN')) {
        if (($t = scalarTenant($db, "SELECT line_account_id FROM transactions WHERE order_number = ? LIMIT 1", [$token])) !== null) {
            return $t;
        }
    }

    // orders uses 'ORD' prefix (table only present on some deployments)
    if (str_starts_with($token, 'ORD')) {
        try {
            if (($t = scalarTenant($db, "SELECT line_account_id FROM orders WHERE order_number = ? LIMIT 1", [$token])) !== null) {
                return $t;
            }
        } catch (PDOException $e) {
            // orders table may not exist on every deployment — fall through
        }
        // Some checkouts also stash ORD-prefixed numbers in transactions
        if (($t = scalarTenant($db, "SELECT line_account_id FROM transactions WHERE order_number = ? LIMIT 1", [$token])) !== null) {
            return $t;
        }
    }

    // Last-resort: walk payment_slips.image_url for a row containing this filename.
    // payment_slips has line_account_id since schema_complete.sql 2025-mid.
    try {
        $sql = "SELECT line_account_id FROM payment_slips WHERE image_url LIKE ? AND line_account_id IS NOT NULL LIMIT 1";
        if (($t = scalarTenant($db, $sql, ['%/' . $filename])) !== null) {
            return $t;
        }
    } catch (PDOException $e) {
        // payment_slips.line_account_id may not exist on older deployments — ignore
    }

    return null;
}

/**
 * Helper: returns the tenant id of the first matching row, or null.
 * Casts to (int) only when the value is non-null.
 */
function scalarTenant(PDO $db, string $sql, array $params): ?int
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $val = $stmt->fetchColumn();
    if ($val === false || $val === null || $val === '') {
        return null;
    }
    return (int) $val;
}

/**
 * Updates DB references for a single filename so the app starts serving the
 * new tenant-scoped URL. Returns the number of rows touched.
 *
 * Strategy: payment_slips.image_url is the single authoritative pointer the
 * app reads. We rewrite it so it embeds the tenant dir. We also stamp
 * line_account_id on the row if it was previously NULL (defensive — should
 * already be set on new deployments).
 */
function updateSlipReferences(PDO $db, string $filename, int $tenantId, string $baseUrl): int
{
    $newPath = '/uploads/' . TenantFileStorage::tenantDirName($tenantId) . '/slips/' . $filename;
    $newUrl  = $baseUrl !== '' ? $baseUrl . $newPath : $newPath;
    $touched = 0;

    try {
        $stmt = $db->prepare("UPDATE payment_slips SET image_url = ?, line_account_id = COALESCE(line_account_id, ?) WHERE image_url LIKE ?");
        $stmt->execute([$newUrl, $tenantId, '%/uploads/slips/' . $filename]);
        $touched += $stmt->rowCount();
    } catch (PDOException $e) {
        // line_account_id column may not exist — retry without it
        $stmt = $db->prepare("UPDATE payment_slips SET image_url = ? WHERE image_url LIKE ?");
        $stmt->execute([$newUrl, '%/uploads/slips/' . $filename]);
        $touched += $stmt->rowCount();
    }

    return $touched;
}

/** Append a line to the orphan log. Created if missing. */
function appendOrphan(string $path, string $filename, bool $dryRun): void
{
    if ($dryRun) return;
    $line = date('c') . "\t$filename\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/** Append a `mv` line to the rollback script. */
function appendUndo(string $path, string $newFull, string $oldFull): void
{
    $line = sprintf("mv -n %s %s\n", escapeshellarg($newFull), escapeshellarg($oldFull));
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

/**
 * --verify mode: read-only audit.
 *   1. Lists any leftover flat-dir slips that we cannot map to a tenant.
 *   2. Lists payment_slips rows whose image_url points at a file that does
 *      not exist on disk (under the new tenant path).
 */
function runVerify(PDO $db, string $flatSlipsDir, string $uploadsRoot): void
{
    out('--- verify: leftover flat-dir files ---');
    $orphans = 0;
    $dh = opendir($flatSlipsDir);
    if ($dh !== false) {
        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.') || str_starts_with($entry, '_')) continue;
            $full = $flatSlipsDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($full)) continue;
            $tid = lookupTenantForSlipFilename($db, $entry);
            if ($tid === null) {
                out("  orphan-no-tenant  $entry");
                $orphans++;
            } else {
                out("  unmoved  $entry (tenant=$tid)");
            }
        }
        closedir($dh);
    }
    out("orphans: $orphans");

    out('--- verify: DB rows pointing at missing files ---');
    $missing = 0;
    try {
        $stmt = $db->query("SELECT id, image_url FROM payment_slips WHERE image_url IS NOT NULL AND image_url <> ''");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $url = (string) $row['image_url'];
            $localPath = urlToLocalPath($url, $uploadsRoot);
            if ($localPath !== null && !is_file($localPath)) {
                out(sprintf('  missing  id=%d  %s', (int) $row['id'], $url));
                $missing++;
            }
        }
    } catch (PDOException $e) {
        out('  (payment_slips read failed: ' . $e->getMessage() . ')');
    }
    out("missing: $missing");
}

/**
 * Maps a public URL like "https://example.com/uploads/tenant_0001/slips/x.jpg"
 * to the local filesystem path under $uploadsRoot. Returns null for unknown URLs.
 */
function urlToLocalPath(string $url, string $uploadsRoot): ?string
{
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path)) return null;
    $marker = '/uploads/';
    $pos = strpos($path, $marker);
    if ($pos === false) return null;
    $rel = substr($path, $pos + strlen($marker));
    // Reject anything trying to escape — only basenames + tenant_NNNN/slips/...
    if (str_contains($rel, '..')) return null;
    return $uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

/** Line-flushed stdout writer. */
function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}
