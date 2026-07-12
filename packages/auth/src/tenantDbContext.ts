import { AsyncLocalStorage } from 'node:async_hooks';
import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * tenantDbContext.ts — ambient "which tenant DB is this request for" context
 * for login()'s realm='tenant' branch.
 *
 * WHY THIS EXISTS: LoginInput for realm='tenant' is `{realm, username,
 * password}` — no tenantId (per the interfaceContract, verbatim). But
 * admin_users lives in a PER-TENANT database (ADR-001 database-per-tenant),
 * so looking up a username requires knowing which tenant DB to query. The
 * plan's own architecture (§1.3) already threads tenant scope through an
 * AsyncLocalStorage context set up by apps/admin/middleware.ts +
 * @reya/tenant's resolveTenant(); packages/tenant itself doesn't build that
 * context object in this batch (out of this batch's allowed paths), so
 * login()'s tenant branch needs a small ambient seam of its own to consume
 * it. This is that seam.
 *
 * REQUIRED INTEGRATION FOR mig-ui (flagged, see types.ts's consumption
 * notes too): the tenant-realm login Route Handler MUST resolve tenantId
 * from the request (the x-tenant-id header middleware.ts sets), call
 * @reya/db's getTenantDb(tenantId), and call
 *   runWithTenantDb({ tenantId, db }, () => login(input))
 * — login() throws (a programmer/wiring error, not an AuthResult) if this
 * context is missing when realm='tenant'. switchBot() does NOT need this:
 * it derives the tenant db straight from the existing session's tenantId.
 */

export interface TenantDbContext {
  tenantId: number;
  db: Kysely<TenantDB>;
}

const storage = new AsyncLocalStorage<TenantDbContext>();

export async function runWithTenantDb<T>(context: TenantDbContext, fn: () => Promise<T> | T): Promise<T> {
  return storage.run(context, fn);
}

export function getTenantDbContext(): TenantDbContext | null {
  return storage.getStore() ?? null;
}
