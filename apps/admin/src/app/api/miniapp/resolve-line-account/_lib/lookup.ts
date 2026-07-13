import { sql, type Kysely } from 'kysely';
import type { MasterDB, TenantDB } from '@reya/db';
import { isValidLiffId, type ResolveLineAccountResponse } from '@reya/contracts';

/**
 * lookup.ts — TypeScript port of api/resolve-line-account.php's resolution
 * strategy (read in full — 186 lines — before writing this file).
 * Deliberately platform-level: does NOT go through
 * `resolveMiniappTenantContext()` (mirrors `REYA_SKIP_SUBDOMAIN_RESOLUTION`)
 * — see this route's own route.ts doc comment.
 *
 * `deps.getTenantDb` is injected (rather than calling @reya/db's
 * `getTenantDb` directly) so tests can drive this with a fake per-tenant DB
 * without a real mysql2 socket — same rationale as every other ported query
 * module in this codebase (e.g. user-detail/_lib/loyalty.ts takes `db` as a
 * parameter rather than importing a singleton).
 *
 * CONNECTIVITY-CHECK ADAPTATION (flagged, not a literal line-for-line port):
 * PHP's `platform_unavailable` (503) branch comes from a SEPARATE raw
 * `new PDO(...)` connect step that happens BEFORE either DB operation below.
 * Kysely/mysql2's pool is lazy (no bare "connect now" primitive exposed by
 * @reya/db, which this batch must not extend) — so a trivial `SELECT 1`
 * against master stands in for that isolated connect step. This keeps the
 * more commonly-hit branch faithful: the FAST-PATH query's own failure
 * (e.g. `liff_id` column missing pre-migration) still falls through to the
 * scan, exactly like PHP's inner `catch (\PDOException $e) { /* fall
 * through *\/ }`, rather than being conflated with "master is down".
 */

export interface ResolveLineAccountLookupDeps {
  master: Kysely<MasterDB>;
  getTenantDb: (tenantId: number) => Promise<Kysely<TenantDB>>;
}

interface RouteRow {
  line_account_id: number;
  tenant_id: number;
}

interface TenantRow {
  id: number;
  slug: string;
  db_name: string;
}

export async function resolveLineAccountByLiffId(
  rawLiffId: string,
  deps: ResolveLineAccountLookupDeps
): Promise<ResolveLineAccountResponse> {
  if (!isValidLiffId(rawLiffId)) {
    return { success: false, error: 'invalid_liff_id' };
  }
  const liffId = rawLiffId;
  const { master } = deps;

  // ── connectivity check (see module doc) ────────────────────────────────
  try {
    await sql`SELECT 1 AS ok`.execute(master);
  } catch {
    return { success: false, error: 'platform_unavailable' };
  }

  // ── A. Fast path: routing table carries the liff_id ────────────────────
  try {
    const result = await sql<RouteRow>`
      SELECT line_account_id, tenant_id
        FROM tenant_line_account_routes
       WHERE liff_id = ${liffId} AND is_active = 1
       ORDER BY id ASC LIMIT 1
    `.execute(master);
    const row = result.rows[0];
    if (row && row.line_account_id) {
      const slug = await tenantSlug(master, row.tenant_id);
      return {
        success: true,
        line_account_id: Number(row.line_account_id),
        tenant_id: Number(row.tenant_id),
        tenant_slug: slug,
      };
    }
  } catch {
    // Column may not exist yet (pre-migration) — fall through to the scan, exactly like PHP.
  }

  // ── B. Fallback: scan active tenants' line_accounts for the liff_id ────
  let tenants: TenantRow[] = [];
  try {
    const result = await sql<TenantRow>`
      SELECT id, slug, db_name FROM tenants
       WHERE status NOT IN ('terminated','suspended')
       ORDER BY id ASC
    `.execute(master);
    tenants = result.rows;
  } catch {
    tenants = [];
  }

  for (const tenant of tenants) {
    if (!tenant.db_name) {
      continue;
    }
    try {
      const tenantDb = await deps.getTenantDb(tenant.id);
      const result = await sql<{ id: number }>`
        SELECT id FROM line_accounts WHERE liff_id = ${liffId} AND liff_id IS NOT NULL AND liff_id != '' LIMIT 1
      `.execute(tenantDb);
      const accountId = Number(result.rows[0]?.id ?? 0);
      if (accountId > 0) {
        await backfillRouteLiffId(master, accountId, tenant.id, liffId);
        return {
          success: true,
          line_account_id: accountId,
          tenant_id: tenant.id,
          tenant_slug: tenant.slug ?? '',
        };
      }
    } catch {
      // Skip unreachable tenant DB; keep scanning — mirrors PHP's per-tenant catch(\Throwable).
      continue;
    }
  }

  return { success: false, error: 'not_found' };
}

async function tenantSlug(master: Kysely<MasterDB>, tenantId: number): Promise<string> {
  try {
    const result = await sql<{ slug: string }>`SELECT slug FROM tenants WHERE id = ${tenantId} LIMIT 1`.execute(
      master
    );
    return result.rows[0]?.slug ?? '';
  } catch {
    return '';
  }
}

/** Best-effort route-row priming so the next lookup hits the fast path. Never throws. */
async function backfillRouteLiffId(
  master: Kysely<MasterDB>,
  lineAccountId: number,
  tenantId: number,
  liffId: string
): Promise<void> {
  try {
    await sql`
      UPDATE tenant_line_account_routes
         SET liff_id = ${liffId}
       WHERE line_account_id = ${lineAccountId} AND tenant_id = ${tenantId}
    `.execute(master);
  } catch {
    // Column not present yet, or no route row — ignore, matches PHP's rla_backfill_route_liff_id().
  }
}
