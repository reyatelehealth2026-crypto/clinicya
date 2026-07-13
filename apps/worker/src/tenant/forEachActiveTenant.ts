import { getMasterDb } from '@reya/db';
import { sql } from 'kysely';
import type { TenantStatus } from '@reya/tenant';

/**
 * forEachActiveTenant.ts — the tenant-fanout primitive for this worker's
 * jobs. Mirrors packages/tenant/src/masterTenantRepository.ts's own
 * `getMasterDb() + sql` tag pattern exactly, but does NOT add a new exported
 * function to packages/tenant itself — that package is a READ-ONLY input to
 * this batch (see this repo's mig-worker agent brief's "allowed paths").
 *
 * `WHERE status = 'active'` does the filtering in the query itself —
 * pending_setup/suspended/terminated tenants are excluded by the SQL, never
 * by a post-hoc JS `.filter()` over a full table scan.
 */

export interface ActiveTenantRow {
  id: number;
  status: TenantStatus;
  displayName: string;
}

interface TenantsTableRow {
  id: number;
  status: TenantStatus;
  display_name: string;
}

export async function forEachActiveTenant(callback: (tenant: ActiveTenantRow) => Promise<void>): Promise<void> {
  const db = getMasterDb();
  const result = await sql<TenantsTableRow>`
    SELECT id, status, display_name FROM tenants WHERE status = 'active'
  `.execute(db);

  // Sequential, not Promise.allSettled — this scaffold job's only concern is
  // proving the fan-out primitive end-to-end. Phase 8's real Odoo batching
  // job is where concurrent Promise.allSettled fan-out is a stated
  // requirement (this repo's mig-worker agent brief, responsibility #2); a
  // future job built on this primitive can choose to run its own callback
  // concurrently via Promise.allSettled(rows.map(...)) without changing this
  // function's signature.
  for (const row of result.rows) {
    await callback({ id: row.id, status: row.status, displayName: row.display_name });
  }
}
