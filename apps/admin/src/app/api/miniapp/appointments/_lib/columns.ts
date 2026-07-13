import type { Kysely } from 'kysely';
import { sql } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * columns.ts — dynamic-column detection for the `appointments` table, ported from
 * api/appointments.php's `handleBook()`/`handleCancel()`.
 *
 * WHY THIS IS GENUINELY PORTED (not skipped-as-no-op): verified against
 * packages/db/src/generated/tenant-db.d.ts's `Appointments` interface AND the literal
 * `CREATE TABLE appointments` in database/migration_2026-05-25_tenant_template.sql — of every optional
 * column `handleBook()`'s dynamic INSERT and `handleCancel()`'s dynamic UPDATE check for
 * (`appointment_id`, `end_time`, `duration`, `type`, `symptoms`, `consultation_fee`, `cancelled_by`),
 * only `line_account_id` and `cancelled_reason` actually exist on the committed template. This is NOT
 * the same outcome as `pharmacists` (see handlers.ts's own doc comment — that table's dynamic check IS
 * a verified no-op and is correctly skipped). Following the SAME reasoning member.php's own
 * `_lib/columns.ts` documents for `users` ("some tenant DBs have drifted from the canonical template"),
 * this file re-runs the real `SHOW COLUMNS FROM appointments` at request time so a drifted tenant DB
 * that DOES have these columns still gets them populated, instead of permanently hard-coding the
 * reduced column set this ONE harness tenant happens to expose.
 */

/**
 * Best-effort `SHOW COLUMNS FROM appointments` -> Set of column names. Empty set on any failure.
 *
 * MINOR, CURRENTLY-UNREACHABLE DIVERGENCE FROM PHP (documented rather than "fixed" defensively): PHP's
 * `handleBook()`/`handleCancel()` do NOT locally catch a `SHOW COLUMNS FROM appointments` failure — if
 * that query itself throws, the exception propagates up to the outer per-request `try/catch` in
 * api/appointments.php's main switch (replicated in route.ts's `dispatchGet`/`dispatchPost`), producing
 * `{success:false, message:<exception message>}` rather than silently proceeding with an empty column
 * set. This function instead swallows locally and returns `new Set()`, which — given `appointments`
 * always exists on the committed tenant template — is unreachable in practice (both behaviors are
 * identical: the query never actually fails here). Flagged for completeness, not fixed, since a `SHOW
 * COLUMNS` failure this deep would almost certainly mean the earlier `SELECT ... FROM users` query in
 * the same handler already failed too.
 */
export async function getExistingAppointmentColumns(db: Kysely<TenantDB>): Promise<Set<string>> {
  try {
    const result = await sql<{ Field: string }>`SHOW COLUMNS FROM appointments`.execute(db);
    return new Set(result.rows.map((row) => row.Field));
  } catch {
    return new Set();
  }
}
