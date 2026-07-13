import type { Kysely } from 'kysely';
import { getTenantDb, type TenantDB } from '@reya/db';

/**
 * withTenant.ts — local scaffold seam standing in for the plan's (§1.3)
 * "job payload พก {tenantId} แล้ว wrap withTenant()" tenant-fanout pattern.
 *
 * Doc-comment honesty note, same posture as packages/auth/src/tenantDbContext.ts's
 * own doc comment about being an ambient seam: this is NOT the shared
 * packages/tenant helper the plan eventually wants — packages/tenant is a
 * READ-ONLY input to this batch (see this repo's mig-worker agent brief's
 * "allowed paths"), so this is a local stand-in only, scoped to
 * apps/worker/src, until a future batch promotes this pattern into
 * @reya/tenant proper for every consumer (this worker, apps/admin, etc.) to
 * share.
 */
export async function withTenant<T>(tenantId: number, fn: (db: Kysely<TenantDB>) => Promise<T>): Promise<T> {
  const db = await getTenantDb(tenantId);
  return fn(db);
}
