import type { JobDefinition } from './types';
import { forEachActiveTenant } from '../tenant/forEachActiveTenant';
import { withTenant } from '../tenant/withTenant';

/**
 * heartbeat.ts — the one real end-to-end job this scaffolding batch ships.
 *
 * It exercises the tenant-fanout seam for real: forEachActiveTenant() lists
 * every `status = 'active'` row in master.tenants, and withTenant() opens
 * that tenant's own DB pool for the write — one `activity_logs` INSERT per
 * active tenant, per firing.
 *
 * Deliberately targets the tenant DB's ALREADY-GENERATED `activity_logs`
 * table (packages/db/src/generated/tenant-db.d.ts), not `dev_logs`.
 * `dev_logs` is claimed by database/migration_2026-05-25_tenant_template.sql's
 * own header comment to live in the MASTER db via a migration that does not
 * actually exist yet in packages/db/migrations/master nor in the generated
 * master-db.d.ts types — a real schema-governance gap, flagged here rather
 * than silently worked around or created in this batch.
 *
 * Fan-out shape note: this job does the tenant fan-out IN-PROCESS (a loop
 * inside one handler invocation), not via BullMQ per-tenant child jobs. The
 * plan's eventual pattern for real Phase 8/10 jobs is expected to enqueue
 * one child job per tenant (so one slow/broken tenant can retry
 * independently of the others) — that refinement is out of scope for this
 * pure-scaffolding batch. `tenantFanout: true` below signals "this job's
 * work is tenant-scoped" to the registry/health metrics; it does not yet
 * imply literal per-tenant BullMQ jobs.
 */

export const HEARTBEAT_JOB_NAME = 'worker-heartbeat';

/** No meaningful payload — the job fans out to every active tenant itself. */
export type HeartbeatPayload = Record<string, never>;

export const heartbeatJob: JobDefinition<HeartbeatPayload> = {
  name: HEARTBEAT_JOB_NAME,
  // index.ts overrides `everyMs` with env.WORKER_HEARTBEAT_INTERVAL_MS at
  // enqueue time (queue.add(..., {repeat: {every: ...}})) — this default is
  // only meaningful when this definition object is constructed/inspected
  // standalone (e.g. in tests), not when it's actually scheduled.
  trigger: { type: 'repeat', everyMs: 60_000 },
  tenantFanout: true,
  retry: { attempts: 3, backoff: { type: 'exponential', delayMs: 5_000 } },

  async handler(_payload, _ctx) {
    await forEachActiveTenant(async (tenant) => {
      await withTenant(tenant.id, async (db) => {
        await db
          .insertInto('activity_logs')
          .values({
            action: 'worker.heartbeat',
            log_type: 'system',
            description: 'apps/worker heartbeat scaffold job',
            extra_data: JSON.stringify({ ranAt: new Date().toISOString(), tenantId: tenant.id }),
          })
          .execute();
      });
    });
  },
};
