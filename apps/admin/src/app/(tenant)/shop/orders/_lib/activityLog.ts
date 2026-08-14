import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { TenantSession } from '@reya/auth';

/**
 * _lib/activityLog.ts — raw-SQL port of classes/ActivityLogger.php's
 * log()/logOrder() INSERT (log() at lines 112-152; the logOrder() wrapper —
 * TYPE_ORDER='order' — at lines 186-189), NOT an import/wrap of the PHP
 * class. Same "replicate the write, don't import the class" convention this
 * codebase already establishes for every raw write against activity_logs
 * (see e.g. pharmacists/_lib/activityLog.ts, api/inbox/actions/send-message/
 * _lib/sendMessage.ts) — no CamelCasePlugin is registered on the shared
 * Kysely<TenantDB> instance, so the typed `.insertInto('activityLogs')`
 * builder's camelCase property names would compile to nonexistent camelCase
 * SQL identifiers instead of the real snake_case columns; the raw `sql`
 * escape hatch is used instead.
 *
 * shop/orders.php calls `$activityLogger->logOrder(...)` from exactly TWO
 * places (both POST branches in actions.ts, ported 1:1 here):
 *   - update_status (line 275): ACTION_UPDATE ('update'), description
 *     'อัพเดทสถานะคำสั่งซื้อ', entity_type 'order', entity_id = order id,
 *     new_value = {status: newStatus}
 *   - approve_payment (line 313): ACTION_APPROVE ('approve'), description
 *     'อนุมัติการชำระเงิน', entity_type 'order', entity_id = order id,
 *     new_value = {payment_status: 'paid', status: 'paid'}
 *
 * Columns populated: log_type/action/description/entity_type/entity_id/
 * new_value (verbatim per-branch, matching the two logOrder() calls' own
 * $options arrays) + admin_id/admin_name/line_account_id, from
 * ActivityLogger::log()'s own fallback chain — `$options['admin_id'] ??
 * ($_SESSION['admin_id'] ?? null)`, `$options['admin_name'] ??
 * ($_SESSION['admin_user']['username'] ?? $_SESSION['username'] ?? null)`,
 * `$options['line_account_id'] ?? ($_SESSION['current_bot_id'] ?? null)` —
 * since neither logOrder() call passes admin_id/admin_name/line_account_id
 * explicitly, ActivityLogger::log() always falls all the way through to
 * these session reads. A Server Action has no raw $_SESSION to read, so the
 * equivalent already-resolved TenantSession fields are used instead:
 * session.adminUserId, session.username, session.currentBotId.
 *
 * IMPORTANT: session.currentBotId is read here RAW (nullable), NOT the
 * page/actions-level `currentBotId = session.currentBotId ?? 1` default
 * shop/orders.php's own top-of-file `$currentBotId` variable applies
 * everywhere else (queries, the tenant-guarded UPDATE, the LINE account
 * lookup). ActivityLogger::log()'s own internal fallback reads
 * `$_SESSION['current_bot_id']` directly — a SEPARATE PHP expression from
 * orders.php's local `$currentBotId ?? 1`— so it does NOT inherit that
 * default. Reproduced faithfully: this file's `line_account_id` column can
 * legitimately be NULL even on a request where every other query in this
 * batch used botId=1.
 *
 * Left NULL (same as ActivityLogger::log() would leave them for this call
 * shape): user_id/user_name (PHP never passes these for order logs),
 * old_value (PHP never passes it for either of this file's two call sites),
 * ip_address/user_agent/request_url/session_id/extra_data (derived from PHP
 * super-globals — $_SERVER/session_id() — a Server Action has no direct
 * equivalent for; out of scope per this batch's acceptance criteria, which
 * only gates on log_type/action/entity_type/entity_id/new_value).
 */
export type OrderLogAction = 'update' | 'approve';

export interface OrderActivityLogInput {
  action: OrderLogAction;
  description: string;
  entityId: number;
  newValue: Record<string, unknown>;
}

export async function logOrderActivity(
  db: Kysely<TenantDB>,
  session: Pick<TenantSession, 'adminUserId' | 'username' | 'currentBotId'>,
  input: OrderActivityLogInput
): Promise<void> {
  const newValueJson = JSON.stringify(input.newValue);
  // Every value is a bound `${}` param (including the two constants below),
  // matching ActivityLogger::log()'s own PDO prepared statement, which binds
  // ALL 17 columns positionally — it never inline-literals log_type/
  // entity_type into the SQL text either.
  await sql`
    INSERT INTO activity_logs
      (log_type, action, description, admin_id, admin_name, entity_type, entity_id, new_value, line_account_id)
    VALUES
      (${'order'}, ${input.action}, ${input.description}, ${session.adminUserId}, ${session.username},
       ${'order'}, ${input.entityId}, ${newValueJson}, ${session.currentBotId})
  `.execute(db);
}
