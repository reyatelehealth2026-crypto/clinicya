import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * getAdmins.ts — literal port of api/inbox-v2.php's `case 'get_admins':`
 * (lines ~2434-2456):
 *
 * ```php
 * case 'get_admins':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     try {
 *         $stmt = $db->prepare("
 *             SELECT id, username, display_name, role
 *             FROM admin_users
 *             WHERE (line_account_id = ? OR line_account_id IS NULL)
 *             AND is_active = 1
 *             ORDER BY display_name ASC
 *         ");
 *         $stmt->execute([$lineAccountId]);
 *         $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *         sendResponse(['success' => true, 'data' => $admins]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to get admin list: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CONFIRMED FINDING — `admin_users` has no Kysely interface, and does not
 * exist in a tenant DB built from the committed template
 * ═══════════════════════════════════════════════════════════════════════
 * `admin_users` is a PLATFORM-level table (see
 * database/migration_2026-05-25_tenant_template.sql's own header: "Platform-
 * level tables (admin_users, dev_logs, etc.) live in `reya_platform` and
 * are defined by a separate migration") — it does NOT exist inside a tenant
 * DB built from the committed template, and there is correspondingly no
 * `AdminUsers` interface anywhere in
 * packages/db/src/generated/tenant-db.d.ts or master-db.d.ts. Same root
 * cause, already independently confirmed and documented at
 * (tenant)/settings/_lib/shop-tax-queries.ts's `resolveLineAccountId()` doc
 * and (tenant)/settings/_lib/consent-queries.ts's module doc — this is the
 * third confirmed sighting of the identical schema-drift gap, not a new
 * finding. Out of scope to fix here (database/** is off-limits to this
 * batch).
 *
 * UNLIKE those two precedents (which each locally swallow the resulting
 * "table doesn't exist" throw in a `try { } catch { }` and silently fall
 * through to a further-tier default), the PHP source being ported HERE does
 * the opposite: `case 'get_admins':`'s own try/catch converts ANY exception
 * — including a missing-table error — into `sendError('Failed to get admin
 * list: ' . $e->getMessage())`, i.e. a clean HTTP 400 JSON error, not a
 * silent empty-array fallback. This module therefore does NOT try/catch the
 * query itself: the raw `sql` tagged-template call below is left to throw
 * on a missing table, and it is route.ts's own try/catch (mirroring PHP's
 * `case 'get_admins':` try/catch) that turns that throw into the clean JSON
 * error response — never an unhandled 500 crash, and never a silently-empty
 * `data: []` on a genuinely broken schema.
 *
 * Every column read (`id, username, display_name, role`) is issued via a
 * raw `sql` tagged template rather than `.selectFrom('admin_users')` —
 * there is no type-safe Kysely path onto this table, matching the brief.
 */

export interface AdminRow {
  id: number;
  username: string;
  display_name: string | null;
  role: string;
}

/**
 * `lineAccountId` is `session.currentBotId ?? 1` — see route.ts's own doc
 * for why this uses the established `conversations/route.ts` precedent
 * (`session.currentBotId ?? 1`) rather than PHP's full 4-tier
 * `$_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ??
 * $_GET['line_account_id'] ?? $_POST['line_account_id'] ?? 1` fallback
 * chain (per this batch's brief).
 */
export async function getAdmins(db: Kysely<TenantDB>, lineAccountId: number): Promise<AdminRow[]> {
  const result = await sql<AdminRow>`
    SELECT id, username, display_name, role
    FROM admin_users
    WHERE (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
    AND is_active = 1
    ORDER BY display_name ASC
  `.execute(db);

  return result.rows;
}
