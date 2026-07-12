import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { AuthResult, PlatformRole, RoleOf, Session, TenantRole } from './types';

/**
 * rbac.ts — role hierarchies + admin_bot_access ACL, port of
 * classes/AdminAuth.php::canAccessBot() (bot ACL) and the informal role
 * hierarchy implied by includes/auth_check.php's isSuperAdmin()/isAdmin()/
 * isStaff() helpers (super_admin > admin > pharmacist/marketing/tech >
 * staff). The hierarchy arrays are supplementary (not part of the
 * interfaceContract) — useful for a future "at least this role" check;
 * requireRole() below (the contract function) only ever does an exact
 * allow-list membership test, matching every existing PHP `in_array(...)`
 * gate exactly (no PHP code in this repo does hierarchical role comparison
 * either — every check is an explicit allow-list).
 */

export const TENANT_ROLE_HIERARCHY: readonly TenantRole[] = [
  'super_admin',
  'admin',
  'pharmacist',
  'marketing',
  'tech',
  'staff',
];

export const PLATFORM_ROLE_HIERARCHY: readonly PlatformRole[] = ['super_admin', 'support', 'readonly'];

function roleOfSession(session: Session): string {
  return session.realm === 'tenant' ? session.role : session.platformRole;
}

/**
 * requireRole — pure, no I/O. `allowed` is TenantRole[] for a TenantSession
 * check or PlatformRole[] for a PlatformSession check (mixing realms is a
 * caller bug, not this function's problem to detect at the type level beyond
 * what the union naturally does). Returns {ok:false,error:{code:'session_expired'}}
 * for a null session, {code:'forbidden'} for a wrong-role session.
 */
export function requireRole<S extends Session>(session: S | null, allowed: readonly RoleOf<S>[]): AuthResult<S> {
  if (!session) {
    return { ok: false, error: { code: 'session_expired' } };
  }

  const currentRole = roleOfSession(session);
  const allowedRoles = allowed as readonly string[];

  if (!allowedRoles.includes(currentRole)) {
    return {
      ok: false,
      error: { code: 'forbidden', reason: `role '${currentRole}' is not in [${allowedRoles.join(', ')}]` },
    };
  }

  return { ok: true, value: session };
}

// ---------------------------------------------------------------------------
// admin_bot_access ACL — exact port of AdminAuth::canAccessBot().
// ---------------------------------------------------------------------------

export type BotPermission =
  | 'can_view'
  | 'can_edit'
  | 'can_broadcast'
  | 'can_manage_users'
  | 'can_manage_shop'
  | 'can_view_analytics';

export interface AdminBotAccessRow {
  can_view: number;
  can_edit: number;
  can_broadcast: number;
  can_manage_users: number;
  can_manage_shop: number;
  can_view_analytics: number;
}

/**
 * canAccessBot — mirrors AdminAuth::canAccessBot($lineAccountId, $permission)
 * branch for branch:
 *   - no current user -> false (not applicable here; caller already has a
 *     resolved TenantSession by the time this is called)
 *   - role === 'super_admin' -> true, short-circuit, no query
 *   - else: SELECT * FROM admin_bot_access WHERE admin_id=? AND line_account_id=?
 *     -> no row -> false; row found -> `$access[$permission] ?? false`
 */
export async function canAccessBot(
  tenantDb: Kysely<TenantDB>,
  adminUserId: number,
  role: TenantRole,
  lineAccountId: number,
  permission: BotPermission = 'can_view'
): Promise<boolean> {
  if (role === 'super_admin') {
    return true;
  }

  const result = await sql<AdminBotAccessRow>`
    SELECT can_view, can_edit, can_broadcast, can_manage_users, can_manage_shop, can_view_analytics
    FROM admin_bot_access
    WHERE admin_id = ${adminUserId} AND line_account_id = ${lineAccountId}
    LIMIT 1
  `.execute(tenantDb);

  const row = result.rows[0];
  if (!row) {
    return false;
  }

  return !!row[permission];
}
