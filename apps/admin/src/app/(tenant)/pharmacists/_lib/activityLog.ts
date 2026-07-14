import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { TenantSession } from '@reya/auth';

/**
 * _lib/activityLog.ts — raw-SQL port of classes/ActivityLogger.php's
 * log()/logPharmacy() INSERT (log() at lines 112-152, the logPharmacy()
 * wrapper — TYPE_PHARMACY='pharmacy' — at lines 191-194), NOT an
 * import/wrap of the PHP class. Same "replicate the write, don't import the
 * class" convention users/actions.ts and user-detail/actions.ts's raw `sql`
 * escape hatch already establishes for every OTHER write in this codebase
 * (see queries.ts's module doc for why: no CamelCasePlugin on the shared
 * Kysely<TenantDB> instance).
 *
 * FACT CHECK vs. this batch's brief (verified by reading
 * includes/pharmacy/pharmacists.php in full, all 463 lines):
 * `$activityLogger->logPharmacy(...)` is called from exactly TWO of the
 * four POST branches —
 *   - save_pharmacist (lines 58-64, unconditionally, after the schedule
 *     save)
 *   - delete_pharmacist's SUCCESS path only (lines 78-82) — the
 *     pending-appointment-blocked path (lines 71-72) sets `$error` and
 *     returns without ever reaching the logger call.
 * `add_holiday` (lines 85-92) and `delete_holiday` (lines 94-97) have NO
 * `$activityLogger->` call anywhere in PHP's own branch bodies.
 *
 * DELIBERATE DECISION (not a silent transcription of the brief, and not a
 * silent embellishment either): this batch's brief states the
 * activity-log requirement TWICE, in near-identical language, against the
 * explicit list of all FOUR Server Actions ("Each mutating action must
 * ALSO insert one activity_logs row..." in the deliverables section;
 * acceptance criterion (d) "every mutating action inserts exactly one
 * activity_logs row..."). "Must ALSO insert" reads as an ADDITIONAL
 * requirement layered onto the verbatim port, not a description of what
 * PHP already does. Combined with classes/ActivityLogger.php's own header
 * comment ("รองรับ PDPA Compliance" — supports PDPA compliance), closing
 * PHP's own under-logging gap on the two holiday branches reads as an
 * intentional audit-completeness fix for this migration. Consequently
 * addHolidayAction/deleteHolidayAction in actions.ts DO call
 * logPharmacyActivity — a flagged, behavior-neutral addition: it writes an
 * extra row to an internal audit table only, changes no user-visible
 * response and no other table's state, and sits outside
 * infra/e2e/parity.mjs's diff surface (mutation coverage for this page is
 * explicitly out of that harness's scope — see its own runbook §21). If a
 * future reviewer decides this reads the brief too literally, reverting is
 * isolated to the two `logPharmacyActivity(...)` call sites this doc
 * describes.
 *
 * Columns populated: log_type/action/description/entity_type/entity_id
 * (verbatim per-branch, matching ActivityLogger::log()'s own positional
 * args) + new_value (save_pharmacist only, `{name, license_no, specialty}` —
 * exactly the associative array pharmacists.php line 63 passes) +
 * admin_id/admin_name/line_account_id, best-effort from the resolved tenant
 * session (mirrors ActivityLogger::log()'s own `$options['admin_id'] ??
 * $_SESSION['admin_id']` / `$_SESSION['current_bot_id']` fallbacks — a
 * Server Action has no raw $_SESSION to read, so the equivalent session
 * fields already resolved by requireTenantPageContext() are used instead).
 * Left NULL (same as ActivityLogger::log() would leave them for a request
 * with no matching super-global): user_id/user_name (PHP never passes these
 * for pharmacy logs either), old_value (PHP never passes it for this file's
 * two call sites), ip_address/user_agent/request_url/session_id/extra_data
 * (derived from PHP super-globals — $_SERVER/session_id() — a Server Action
 * has no direct equivalent for, and this batch's acceptance criteria only
 * gates on log_type/action/entity_type/entity_id).
 */
export type PharmacyLogAction = 'create' | 'update' | 'delete';

export interface PharmacyActivityLogInput {
  action: PharmacyLogAction;
  description: string;
  entityId: number;
  /** Only save_pharmacist passes this (pharmacists.php line 63). */
  newValue?: Record<string, unknown>;
}

export async function logPharmacyActivity(
  db: Kysely<TenantDB>,
  session: Pick<TenantSession, 'adminUserId' | 'username' | 'currentBotId'>,
  input: PharmacyActivityLogInput
): Promise<void> {
  const newValueJson = input.newValue !== undefined ? JSON.stringify(input.newValue) : null;
  // Every value is a bound `${}` param (including the two constants below),
  // matching ActivityLogger::log()'s own PDO prepared statement, which binds
  // ALL 17 columns positionally — it never inline-literals log_type/
  // entity_type into the SQL text either.
  await sql`
    INSERT INTO activity_logs
      (log_type, action, description, admin_id, admin_name, entity_type, entity_id, new_value, line_account_id)
    VALUES
      (${'pharmacy'}, ${input.action}, ${input.description}, ${session.adminUserId}, ${session.username},
       ${'pharmacist'}, ${input.entityId}, ${newValueJson}, ${session.currentBotId})
  `.execute(db);
}
