import { randomBytes } from 'node:crypto';
import { sql } from 'kysely';
import { getMasterDb, getTenantDb } from '@reya/db';
import { loadEnv } from '@reya/config';
import { verifyLegacyPassword } from './passwords';
import { canAccessBot } from './rbac';
import { writeSuperAdminAudit } from './impersonation';
import { syncToPhpBridge } from './bridgeClient';
import { CachedSessionStore, SessionCache, createMySqlSessionStore, type SessionStore } from './sessionStore';
import { getRedisClient } from './redisClient';
import { getTenantDbContext } from './tenantDbContext';
import {
  sessionCookieName,
  type AuthResult,
  type LoginInput,
  type LoginValue,
  type LogoutValue,
  type PlatformRole,
  type PlatformSession,
  type Realm,
  type Session,
  type SessionCookieDescriptor,
  type SwitchBotValue,
  type SwitchTenantInput,
  type SwitchTenantValue,
  type TenantRole,
  type TenantSession,
} from './types';

/**
 * session.ts — the @reya/auth public session API: login(), logout(),
 * getSession(), switchBot(), switchTenant(). (requireRole() lives in
 * rbac.ts and is re-exported from index.ts — it's pure/no-I/O so it doesn't
 * belong next to the DB-touching functions here.)
 */

// ---------------------------------------------------------------------------
// Default store wiring — lazy singleton, same shape as @reya/db's
// masterPool.ts getMasterDb()/resetMasterDb() pair. Tests mock 'mysql2' and
// 'ioredis' at the module level (see tests/helpers) so this real wiring path
// never touches a socket in this batch's test suite.
// ---------------------------------------------------------------------------

let store: SessionStore | null = null;

function getStore(): SessionStore {
  if (store) {
    return store;
  }
  const mysqlStore = createMySqlSessionStore(getMasterDb());
  let redisLikeClient = null;
  try {
    redisLikeClient = getRedisClient();
  } catch {
    // Constructing the ioredis client itself failed (bad REDIS_URL, etc.) —
    // SessionCache is fine with a null client, it just always uses the
    // in-memory fallback.
    redisLikeClient = null;
  }
  store = new CachedSessionStore(mysqlStore, new SessionCache(redisLikeClient));
  return store;
}

/** Test hook — mirrors @reya/db's resetMasterDb(). Also exposed so a caller can inject a fully fake SessionStore, bypassing MySQL/Redis entirely. */
export function configureSessionStore(override: SessionStore | null): void {
  store = override;
}

function generateSid(): string {
  return randomBytes(32).toString('hex');
}

function computeExpiresAt(now: Date, ttlSeconds: number): string {
  return new Date(now.getTime() + ttlSeconds * 1000).toISOString();
}

function buildCookie(realm: Realm, sid: string, ttlSeconds: number): SessionCookieDescriptor {
  const env = loadEnv();
  return {
    name: sessionCookieName(realm),
    value: sid,
    httpOnly: true,
    sameSite: 'lax',
    // Secure by default everywhere but development. SESSION_COOKIE_INSECURE=1
    // is a deliberate, documented opt-out for HTTP-only trial stacks — without
    // it the browser discards the cookie over plain http and login silently
    // never sticks (see the env var's doc comment in packages/config).
    secure: env.NODE_ENV !== 'development' && env.SESSION_COOKIE_INSECURE !== '1',
    path: '/',
    maxAge: ttlSeconds,
  };
}

function nowUnixSeconds(): number {
  return Math.floor(Date.now() / 1000);
}

// ---------------------------------------------------------------------------
// login()
// ---------------------------------------------------------------------------

interface AdminUserRow {
  id: number;
  username: string;
  password: string;
  display_name: string | null;
  role: string;
  is_active: number;
}

interface PlatformUserRow {
  id: number;
  email: string;
  name: string;
  role: string;
  password_hash: string;
  is_active: number;
}

/**
 * Verifies against admin_users.password / platform_users.password_hash via
 * bcryptjs — NEVER re-hashes. Issues a freshly-rotated sid (session-fixation
 * defeat, mirrors admin/platform-login.php's session_regenerate_id(true))
 * and best-effort syncs internal/session-bridge.php (action 'login-sync').
 *
 * Also enforces single-active-session-per-identity: a fresh login deletes
 * any OTHER node_sessions rows already held by this same identity+realm
 * (uses the idx_node_sessions_realm_admin_user / _platform_user indexes) —
 * this is the property packages/auth/tests/session.test.ts's rotation test
 * exercises ("a getSession() call with the OLD sid afterward returns null").
 */
export async function login(input: LoginInput): Promise<AuthResult<LoginValue>> {
  const env = loadEnv();
  const ttlSeconds = env.NODE_SESSION_TTL_SECONDS;
  const sessionStore = getStore();
  const now = new Date();

  if (input.realm === 'tenant') {
    const ctx = getTenantDbContext();
    if (!ctx) {
      // Programmer/wiring error, not a user-facing auth failure — see
      // tenantDbContext.ts's module doc: the caller MUST wrap this in
      // runWithTenantDb({tenantId, db}, ...) before calling login() for
      // realm='tenant'.
      throw new Error(
        "login(): no tenant DB context — wrap this call in runWithTenantDb({ tenantId, db }, () => login(input)); see packages/auth/src/tenantDbContext.ts"
      );
    }

    const result = await sql<AdminUserRow>`
      SELECT id, username, password, display_name, role, is_active
      FROM admin_users
      WHERE username = ${input.username} AND is_active = 1
      LIMIT 1
    `.execute(ctx.db);
    const row = result.rows[0];

    if (!row) {
      return { ok: false, error: { code: 'invalid_credentials' } };
    }

    const passwordOk = await verifyLegacyPassword(input.password, row.password);
    if (!passwordOk) {
      return { ok: false, error: { code: 'invalid_credentials' } };
    }

    const role = row.role as TenantRole; // admin_users.role is free-text VARCHAR in PHP too — AdminAuth never validates it either.
    const displayName = row.display_name ?? row.username;
    const sid = generateSid();

    const session: TenantSession = {
      realm: 'tenant',
      sid,
      adminUserId: row.id,
      tenantId: ctx.tenantId,
      currentBotId: null,
      role,
      username: row.username,
      displayName,
      createdAt: now.toISOString(),
      lastSeenAt: now.toISOString(),
      expiresAt: computeExpiresAt(now, ttlSeconds),
    };

    await sessionStore.deleteAllForIdentity('tenant', { adminUserId: row.id });
    await sessionStore.create(session);

    const bridgeResult = await syncToPhpBridge({
      action: 'login-sync',
      sid,
      phpSessionKeys: {
        admin_user: { id: row.id, username: row.username, display_name: displayName, role },
        current_bot_id: null,
        active_tenant_id: ctx.tenantId,
      },
      issuedAt: nowUnixSeconds(),
    });

    return {
      ok: true,
      value: { session, cookie: buildCookie('tenant', sid, ttlSeconds), bridgeSynced: bridgeResult.ok },
    };
  }

  // realm === 'platform'
  const master = getMasterDb();
  const result = await sql<PlatformUserRow>`
    SELECT id, email, name, role, password_hash, is_active
    FROM platform_users
    WHERE email = ${input.email}
    LIMIT 1
  `.execute(master);
  const row = result.rows[0];

  if (!row) {
    return { ok: false, error: { code: 'invalid_credentials' } };
  }

  const passwordOk = await verifyLegacyPassword(input.password, row.password_hash);
  if (!passwordOk) {
    return { ok: false, error: { code: 'invalid_credentials' } };
  }
  if (Number(row.is_active) !== 1) {
    return { ok: false, error: { code: 'account_inactive' } };
  }

  const sid = generateSid();
  const session: PlatformSession = {
    realm: 'platform',
    sid,
    platformUserId: row.id,
    platformRole: row.role as PlatformRole,
    email: row.email,
    name: row.name,
    impersonatedTenantId: null,
    createdAt: now.toISOString(),
    lastSeenAt: now.toISOString(),
    expiresAt: computeExpiresAt(now, ttlSeconds),
  };

  await sessionStore.deleteAllForIdentity('platform', { platformUserId: row.id });
  await sessionStore.create(session);

  const bridgeResult = await syncToPhpBridge({
    action: 'login-sync',
    sid,
    phpSessionKeys: {
      platform_user_id: row.id,
      platform_user_email: row.email,
      platform_user_name: row.name,
      platform_user_role: row.role,
      admin_switched_to_tenant_id: null,
    },
    issuedAt: nowUnixSeconds(),
  });

  return {
    ok: true,
    value: { session, cookie: buildCookie('platform', sid, ttlSeconds), bridgeSynced: bridgeResult.ok },
  };
}

// ---------------------------------------------------------------------------
// logout()
// ---------------------------------------------------------------------------

/** Destroys the node_sessions row for `sid` and best-effort syncs the bridge (action 'destroy'). Idempotent — calling with an already-invalid sid is not an error. */
export async function logout(sid: string, realm: Realm): Promise<AuthResult<LogoutValue>> {
  const sessionStore = getStore();
  await sessionStore.delete(sid, realm);

  const phpSessionKeys =
    realm === 'tenant'
      ? { admin_user: null, current_bot_id: null, active_tenant_id: null }
      : {
          platform_user_id: null,
          platform_user_email: null,
          platform_user_name: null,
          platform_user_role: null,
          admin_switched_to_tenant_id: null,
        };

  const bridgeResult = await syncToPhpBridge({
    action: 'destroy',
    sid,
    phpSessionKeys,
    issuedAt: nowUnixSeconds(),
  });

  return { ok: true, value: { bridgeSynced: bridgeResult.ok } };
}

// ---------------------------------------------------------------------------
// getSession()
// ---------------------------------------------------------------------------

/** Reads-through the Redis/in-memory cache to node_sessions; returns null (not an error) for a missing/expired sid — callers distinguish "not logged in" from real failures this way. Touches last_seen_at. */
export async function getSession(sidRaw: string | undefined | null, realm: Realm): Promise<Session | null> {
  if (!sidRaw) {
    return null;
  }

  const sessionStore = getStore();
  const session = await sessionStore.get(sidRaw, realm);
  if (!session) {
    return null;
  }

  const now = new Date();
  if (new Date(session.expiresAt).getTime() <= now.getTime()) {
    await sessionStore.delete(sidRaw, realm); // best-effort GC on read
    return null;
  }

  await sessionStore.touch(sidRaw, realm, now);
  return { ...session, lastSeenAt: now.toISOString() } as Session;
}

// ---------------------------------------------------------------------------
// switchBot()
// ---------------------------------------------------------------------------

/** ACL-checked against admin_bot_access (mirrors classes/AdminAuth.php::canAccessBot()/setCurrentBot() exactly — super_admin short-circuits true). Reuses the existing sid (no rotation — same privilege level). Best-effort syncs the bridge (action 'set_bot'). */
export async function switchBot(sid: string, botId: number): Promise<AuthResult<SwitchBotValue>> {
  const session = await getSession(sid, 'tenant');
  if (!session) {
    return { ok: false, error: { code: 'session_expired' } };
  }
  if (session.realm !== 'tenant') {
    return { ok: false, error: { code: 'forbidden', reason: 'switchBot is tenant-realm only' } };
  }
  if (session.tenantId === null) {
    return { ok: false, error: { code: 'forbidden', reason: 'session has no active tenant' } };
  }

  const tenantDb = await getTenantDb(session.tenantId);
  const allowed = await canAccessBot(tenantDb, session.adminUserId, session.role, botId, 'can_view');
  if (!allowed) {
    return { ok: false, error: { code: 'forbidden', reason: 'no admin_bot_access row for this bot (or can_view=0)' } };
  }

  const updated: TenantSession = { ...session, currentBotId: botId };
  await getStore().update(updated);

  const bridgeResult = await syncToPhpBridge({
    action: 'set_bot',
    sid,
    phpSessionKeys: { current_bot_id: botId },
    issuedAt: nowUnixSeconds(),
  });

  return { ok: true, value: { session: updated, bridgeSynced: bridgeResult.ok } };
}

// ---------------------------------------------------------------------------
// switchTenant()
// ---------------------------------------------------------------------------

interface TenantLookupRow {
  id: number;
  slug: string;
  display_name: string;
  status: string;
}

/** Platform realm only — {ok:false,error:{code:'forbidden'}} if sid resolves to a TenantSession. Ports admin/switch-tenant.php: 'enter' rejects a terminated target tenant, rotates sid (privilege elevation), sets impersonatedTenantId, and writes ONE super_admin_audit row (action='switch_tenant_in', metadata: {tenant_slug, tenant_display_name, tenant_status, reason}). 'exit' clears impersonatedTenantId, rotates sid, writes ONE super_admin_audit row (action='switch_tenant_out'). Best-effort syncs the bridge (action 'set_tenant'). */
export async function switchTenant(sid: string, input: SwitchTenantInput): Promise<AuthResult<SwitchTenantValue>> {
  const session = await getSession(sid, 'platform');
  if (!session) {
    // node_sessions rows are realm-scoped, so a sid that resolves to a
    // TenantSession is simply absent under realm='platform'. The contract
    // explicitly distinguishes that case ({code:'forbidden'}) from a truly
    // unknown/expired sid ({code:'session_expired'}) — probe the tenant
    // realm too before concluding it's really just expired/unknown.
    const asTenantSession = await getSession(sid, 'tenant');
    if (asTenantSession) {
      return { ok: false, error: { code: 'forbidden', reason: 'switchTenant is platform-realm only' } };
    }
    return { ok: false, error: { code: 'session_expired' } };
  }
  if (session.realm !== 'platform') {
    // Unreachable in practice — getSession(sid, 'platform') only ever
    // resolves a PlatformSession|null since node_sessions rows are
    // realm-scoped (the actual cross-realm case is handled above). Kept as
    // a type-narrowing guard: TypeScript can't infer that from getSession()'s
    // generic `Session` return type, and PlatformSession-only fields
    // (platformUserId, impersonatedTenantId) are used below.
    return { ok: false, error: { code: 'forbidden', reason: 'switchTenant is platform-realm only' } };
  }

  const master = getMasterDb();
  const sessionStore = getStore();

  if (input.type === 'enter') {
    const result = await sql<TenantLookupRow>`
      SELECT id, slug, display_name, status FROM tenants WHERE id = ${input.tenantId} LIMIT 1
    `.execute(master);
    const target = result.rows[0];

    if (!target) {
      return { ok: false, error: { code: 'not_found' } };
    }
    if (target.status === 'terminated') {
      return { ok: false, error: { code: 'forbidden', reason: 'tenant is terminated — permanently blocked' } };
    }

    const newSid = generateSid();
    const newSession: PlatformSession = { ...session, sid: newSid, impersonatedTenantId: target.id };
    await sessionStore.rotate(sid, newSession);

    const reason = input.reason && input.reason.trim() !== '' ? input.reason.trim() : null;
    await writeSuperAdminAudit(master, {
      platformUserId: session.platformUserId,
      tenantId: target.id,
      action: 'switch_tenant_in',
      metadata: {
        tenant_slug: target.slug,
        tenant_display_name: target.display_name,
        tenant_status: target.status,
        reason,
      },
    });

    const bridgeResult = await syncToPhpBridge({
      action: 'set_tenant',
      sid: newSid,
      phpSessionKeys: { admin_switched_to_tenant_id: target.id },
      issuedAt: nowUnixSeconds(),
    });

    return { ok: true, value: { session: newSession, bridgeSynced: bridgeResult.ok } };
  }

  // input.type === 'exit'
  const previousTenantId = session.impersonatedTenantId;
  const newSid = generateSid();
  const newSession: PlatformSession = { ...session, sid: newSid, impersonatedTenantId: null };
  await sessionStore.rotate(sid, newSession);

  await writeSuperAdminAudit(master, {
    platformUserId: session.platformUserId,
    tenantId: previousTenantId,
    action: 'switch_tenant_out',
  });

  const bridgeResult = await syncToPhpBridge({
    action: 'set_tenant',
    sid: newSid,
    phpSessionKeys: { admin_switched_to_tenant_id: null },
    issuedAt: nowUnixSeconds(),
  });

  return { ok: true, value: { session: newSession, bridgeSynced: bridgeResult.ok } };
}
