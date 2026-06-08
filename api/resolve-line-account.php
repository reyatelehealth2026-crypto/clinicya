<?php
/**
 * api/resolve-line-account.php — Map a LIFF id → line_account_id (+ tenant).
 *
 * WHY THIS EXISTS
 * ───────────────
 * The LINE Mini App is ONE static export shared by every tenant
 * (https://re-ya.com/miniapp/, basePath=/miniapp). It cannot bake a tenant id
 * at build time. At runtime it must learn which tenant it is serving.
 *
 * Two signals are available inside a LIFF session, in priority order:
 *   1. The deep link carries ?la={lineAccountId}  (preferred — emitted by
 *      includes/liff-helper.php for every Mini-App link the PHP side builds).
 *   2. Only the LIFF id is known (user opened the OA's default LIFF entry with
 *      no ?la=). The app reads liff.id / liff.getContext().liffId and asks THIS
 *      endpoint to map that LIFF id back to the owning line_account_id.
 *
 * RESOLUTION STRATEGY (platform-level — must NOT depend on subdomain/session)
 *   A. Fast path  — tenant_line_account_routes.liff_id (one indexed query on the
 *                   master DB). Populated by provisioning; see migration
 *                   database/migration_2026-06-02_route_liff_id.sql.
 *   B. Fallback   — scan active tenants' line_accounts.liff_id. Only runs when
 *                   the fast-path column is missing/empty (pre-backfill state).
 *                   Bounded + cached; result is written back to the route row so
 *                   subsequent lookups hit the fast path.
 *
 * RESPONSE  application/json
 *   { "success": true,  "line_account_id": 12, "tenant_id": 3, "tenant_slug": "tenant-0003" }
 *   { "success": false, "error": "not_found" }
 *
 * This endpoint is read-only and intentionally CORS-open (GET) — it returns only
 * a non-secret routing id, never tokens/PHI.
 */

declare(strict_types=1);

// Platform-level lookup: do NOT let subdomain resolution pin a tenant or 503 us.
define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true);

error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: public, max-age=300'); // liff→account mapping is stable

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/TenantContext.php';

/** Emit a JSON response and stop. */
function rla_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Validate a LIFF id. Real LINE LIFF ids look like "1234567890-AbCdEfGh".
 * We accept digits, letters, and hyphens, length-bounded, to avoid SQL/abuse.
 */
function rla_valid_liff_id(string $liffId): bool
{
    return $liffId !== ''
        && strlen($liffId) <= 64
        && preg_match('/^[A-Za-z0-9-]+$/', $liffId) === 1;
}

$liffId = trim((string) ($_GET['liff_id'] ?? ''));
if (!rla_valid_liff_id($liffId)) {
    rla_respond(['success' => false, 'error' => 'invalid_liff_id'], 400);
}

try {
    $master = \Database::platform()->getConnection();
} catch (\Throwable $e) {
    rla_respond(['success' => false, 'error' => 'platform_unavailable'], 503);
}

// ── A. Fast path: routing table carries the liff_id ──────────────────────────
try {
    $stmt = $master->prepare(
        'SELECT line_account_id, tenant_id, tenant_db_name
           FROM tenant_line_account_routes
          WHERE liff_id = ? AND is_active = 1
          ORDER BY id ASC LIMIT 1'
    );
    $stmt->execute([$liffId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if ($row && !empty($row['line_account_id'])) {
        rla_respond([
            'success'         => true,
            'line_account_id' => (int) $row['line_account_id'],
            'tenant_id'       => (int) $row['tenant_id'],
            'tenant_slug'     => rla_tenant_slug($master, (int) $row['tenant_id']),
        ]);
    }
} catch (\PDOException $e) {
    // Column may not exist yet (pre-migration) — fall through to the scan.
}

// ── B. Fallback: scan active tenants' line_accounts for the liff_id ───────────
try {
    $tenants = $master->query(
        "SELECT id, slug, db_name FROM tenants
          WHERE status NOT IN ('terminated','suspended')
          ORDER BY id ASC"
    )->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $tenants = [];
}

foreach ($tenants as $tenant) {
    $dbName = (string) ($tenant['db_name'] ?? '');
    if ($dbName === '') {
        continue;
    }
    try {
        $tenantPdo = new \PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . $dbName . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
        );
        $q = $tenantPdo->prepare(
            "SELECT id FROM line_accounts WHERE liff_id = ? AND liff_id IS NOT NULL AND liff_id != '' LIMIT 1"
        );
        $q->execute([$liffId]);
        $accountId = (int) ($q->fetchColumn() ?: 0);
        if ($accountId > 0) {
            // Best-effort backfill so the next call hits the fast path.
            rla_backfill_route_liff_id($master, $accountId, (int) $tenant['id'], $dbName, $liffId);
            rla_respond([
                'success'         => true,
                'line_account_id' => $accountId,
                'tenant_id'       => (int) $tenant['id'],
                'tenant_slug'     => (string) ($tenant['slug'] ?? ''),
            ]);
        }
    } catch (\Throwable $e) {
        // Skip unreachable tenant DB; keep scanning.
        continue;
    }
}

rla_respond(['success' => false, 'error' => 'not_found'], 404);

// ── helpers ──────────────────────────────────────────────────────────────────

function rla_tenant_slug(\PDO $master, int $tenantId): string
{
    try {
        $stmt = $master->prepare('SELECT slug FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([$tenantId]);
        return (string) ($stmt->fetchColumn() ?: '');
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * Write liff_id onto the existing route row (fast-path priming). No-op if the
 * column is absent or no matching route exists. Never throws.
 */
function rla_backfill_route_liff_id(\PDO $master, int $lineAccountId, int $tenantId, string $dbName, string $liffId): void
{
    try {
        $upd = $master->prepare(
            'UPDATE tenant_line_account_routes
                SET liff_id = ?
              WHERE line_account_id = ? AND tenant_id = ?'
        );
        $upd->execute([$liffId, $lineAccountId, $tenantId]);
    } catch (\Throwable $e) {
        // Column not present yet, or no route row — ignore.
    }
}
