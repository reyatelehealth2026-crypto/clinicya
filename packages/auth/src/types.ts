/**
 * types.ts — the @reya/auth public TypeScript surface, verbatim from the
 * Phase 1 batch 2 interface contract (mig-orc). mig-ui's apps/admin shell
 * builds its login Route Handler, layouts, and middleware-adjacent code
 * against these exact names/signatures/types — do not rename/reshape
 * anything below without flagging it back to mig-orc first.
 *
 * One additive, non-breaking deviation is called out at BridgeSyncPayload
 * below (a `sid` field) — see that doc comment for the "why".
 */

// ---------------------------------------------------------------------------
// Cookie names — literal strings, used nowhere else under different spellings.
// ---------------------------------------------------------------------------
export const TENANT_SESSION_COOKIE = 'reya_sid' as const;
export const PLATFORM_SESSION_COOKIE = 'reya_platform_sid' as const;

export type Realm = 'tenant' | 'platform';

/** reya_sid for 'tenant', reya_platform_sid for 'platform'. */
export function sessionCookieName(realm: Realm): string {
  return realm === 'tenant' ? TENANT_SESSION_COOKIE : PLATFORM_SESSION_COOKIE;
}

// ---------------------------------------------------------------------------
// Session shapes — mirror the exact $_SESSION keys read by
// includes/auth_check.php, classes/AdminAuth.php, admin/platform-login.php,
// admin/switch-tenant.php. Do not add/rename fields without checking those
// four files first.
// ---------------------------------------------------------------------------

export type TenantRole = 'super_admin' | 'admin' | 'pharmacist' | 'marketing' | 'tech' | 'staff';
export type PlatformRole = 'super_admin' | 'support' | 'readonly';

export interface TenantSession {
  realm: 'tenant';
  sid: string;
  adminUserId: number;
  tenantId: number | null; // mirrors $_SESSION['active_tenant_id']
  currentBotId: number | null; // mirrors $_SESSION['current_bot_id']
  role: TenantRole;
  username: string;
  displayName: string;
  createdAt: string; // ISO 8601
  lastSeenAt: string; // ISO 8601
  expiresAt: string; // ISO 8601
}

export interface PlatformSession {
  realm: 'platform';
  sid: string;
  platformUserId: number;
  platformRole: PlatformRole;
  email: string;
  name: string;
  impersonatedTenantId: number | null; // mirrors $_SESSION['admin_switched_to_tenant_id']
  createdAt: string;
  lastSeenAt: string;
  expiresAt: string;
}

export type Session = TenantSession | PlatformSession;

/**
 * The role field of a given Session subtype — TenantRole for TenantSession,
 * PlatformRole for PlatformSession. NOT part of the literal contract text
 * (which wrote requireRole's constraint as `S['role']`) — PlatformSession
 * has no `.role` field (it's `.platformRole`), so `S['role']` does not
 * actually type-check for S=PlatformSession/S=Session. This conditional
 * type is the minimal fix that keeps requireRole's call-site shape
 * (2 positional args, same names, same return type) identical while making
 * the generic constraint compile. Flagged to mig-orc in the build report.
 */
export type RoleOf<S extends Session> = S extends TenantSession
  ? TenantRole
  : S extends PlatformSession
    ? PlatformRole
    : never;

// ---------------------------------------------------------------------------
// Error / result shapes — expected auth failures (bad password, forbidden,
// expired session, bridge down) are values, never thrown exceptions. Only
// genuine infra faults (DB connection refused, programmer error) throw.
// ---------------------------------------------------------------------------

export type AuthError =
  | { code: 'invalid_credentials' }
  | { code: 'account_inactive' }
  | { code: 'not_found' }
  | { code: 'forbidden'; reason: string }
  | { code: 'session_expired' }
  | { code: 'bridge_unreachable' };

export type AuthResult<T> = { ok: true; value: T } | { ok: false; error: AuthError };

export interface SessionCookieDescriptor {
  name: string; // sessionCookieName(realm)
  value: string; // sid
  httpOnly: true;
  sameSite: 'lax';
  secure: boolean; // true outside NODE_ENV==='development'
  path: '/';
  maxAge: number; // seconds, matches node_sessions.expires_at - now
}

// ---------------------------------------------------------------------------
// Core session API
// ---------------------------------------------------------------------------

export type LoginInput =
  | { realm: 'tenant'; username: string; password: string }
  | { realm: 'platform'; email: string; password: string };

export interface LoginValue {
  session: Session;
  cookie: SessionCookieDescriptor;
  /** false if internal/session-bridge.php could not be reached — Node-side login still succeeded (Node is source of truth in Phase 1, plan §1.4); caller should surface a soft warning, not fail the request. */
  bridgeSynced: boolean;
}

export interface LogoutValue {
  bridgeSynced: boolean;
}

export interface SwitchBotValue {
  session: TenantSession;
  bridgeSynced: boolean;
}

export type SwitchTenantInput = { type: 'enter'; tenantId: number; reason?: string } | { type: 'exit' };

export interface SwitchTenantValue {
  session: PlatformSession;
  bridgeSynced: boolean;
}

// ---------------------------------------------------------------------------
// PHP session-bridge sync (Next session state -> $_SESSION on the PHP side)
// ---------------------------------------------------------------------------

export type BridgeAction = 'login-sync' | 'set_bot' | 'set_tenant' | 'destroy' | 'introspect';

export interface BridgePhpSessionKeys {
  admin_user: Record<string, unknown> | null; // full admin_users row minus password, or null on destroy
  current_bot_id: number | null;
  active_tenant_id: number | null;
  platform_user_id: number | null;
  platform_user_email: string | null;
  platform_user_name: string | null;
  platform_user_role: string | null;
  admin_switched_to_tenant_id: number | null;
}

export interface BridgeSyncPayload {
  action: BridgeAction;
  phpSessionKeys: Partial<BridgePhpSessionKeys>;
  issuedAt: number; // unix seconds — HMAC-covered, checked against a short replay window server-side
  /**
   * ADDITIVE FIELD — not in the literal interface contract text (which only
   * listed action/phpSessionKeys/issuedAt). The contract never specifies how
   * internal/session-bridge.php knows WHICH PHP session ($_SESSION) to
   * mutate; SessionCookieDescriptor only carries the Node-side cookie, no
   * separate PHPSESSID. The natural, minimal-surface-area fix: reuse the
   * Node opaque sid as the PHP session id too (the bridge does
   * `session_id($sid)` before `session_start()`), so this field carries that
   * sid. Required (not optional) — every real call site has one. Flagged to
   * mig-orc: for a browser to actually SEE the bridged $_SESSION on a
   * legacy PHP page, apps/admin's login Route Handler (mig-ui) needs to also
   * set a `PHPSESSID` cookie equal to this same value — that cookie-naming
   * decision lives outside packages/auth (apps/admin shell / nginx
   * strangler config), not implemented in this batch.
   */
  sid: string;
}

// ---------------------------------------------------------------------------
// Consumption notes for apps/admin (mig-ui):
//   - middleware.ts does NOT call any of the above — it only calls
//     @reya/tenant's resolveTenant(), which is unrelated and already built.
//   - The login Route Handler calls login(), then cookies().set(cookie.name,
//     cookie.value, cookie) using the returned SessionCookieDescriptor as-is.
//   - (tenant)/layout.tsx and (platform)/layout.tsx call getSession(cookieValue,
//     realm) then requireRole() to gate; both must handle `null` and every
//     AuthError variant without crashing (render a login redirect / 403 page).
//   - Every one of the *Value interfaces carries bridgeSynced:boolean — the UI
//     should not hard-fail on false, only surface a non-blocking notice.
//   - NEW REQUIREMENT surfaced by this batch (see tenantDbContext.ts): before
//     calling login({realm:'tenant', ...}), the Route Handler MUST resolve
//     the tenantId from the request (the x-tenant-id header middleware.ts
//     already sets via @reya/tenant's resolveTenant()), call @reya/db's
//     getTenantDb(tenantId), and wrap the login() call in
//     runWithTenantDb({tenantId, db}, () => login(input)) — login() has no
//     tenantId parameter (per contract) so it reads this ambient context
//     instead. switchBot() does NOT need this — it derives the tenant db
//     from the existing session's tenantId internally via @reya/db.
// ---------------------------------------------------------------------------
