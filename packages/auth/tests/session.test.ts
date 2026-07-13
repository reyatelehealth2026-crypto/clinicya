import { beforeEach, describe, expect, it, vi } from 'vitest';
import { makeFakeMysqlPool, type FakeMysqlPool, type QueryImpl } from './helpers/fakeMysqlPool';

const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';
const TENANT_DB_NAME = 'reya_tenant_0042';

// Real PHP-generated $2y$ bcrypt hash for the plaintext 'reya-auth-test-fixture-only'
// (php -r 'echo password_hash("reya-auth-test-fixture-only", PASSWORD_DEFAULT);').
const FIXTURE_PLAINTEXT = 'reya-auth-test-fixture-only';
const FIXTURE_BCRYPT_HASH = '$2y$12$aO5GPKoAjqHQ6eH3u62u9OiyZSswNnS2hsQJUCwRrjxQ5CImjNIba';

const FAKE_ENV = {
  NODE_ENV: 'test',
  DB_HOST: 'db-host.internal',
  DB_USER: 'reya_user',
  DB_PASS: 'reya_pass',
  REYA_BASE_DOMAIN: 're-ya.com',
  REDIS_URL: 'redis://redis:6379',
  SESSION_BRIDGE_URL: 'http://php-internal.test/internal/session-bridge.php',
  SESSION_BRIDGE_HMAC_SECRET: 'test-only-bridge-secret-not-real',
  NODE_SESSION_TTL_SECONDS: 86400,
};

const { createPoolMock } = vi.hoisted(() => ({ createPoolMock: vi.fn() }));

vi.mock('mysql2', () => ({ createPool: createPoolMock }));

vi.mock('@reya/config', () => ({
  loadEnv: vi.fn(() => FAKE_ENV),
  PLATFORM_DB_NAME: 'zrismpsz_reya_platform',
}));

// ioredis is ALWAYS unreachable in this suite — proves the whole login/
// getSession/switchBot/switchTenant/logout flow works end-to-end purely on
// the in-memory cache fallback, with zero real Redis required.
vi.mock('ioredis', () => {
  class FakeUnreachableRedis {
    on(): void {}
    disconnect(): void {}
    async get(): Promise<never> {
      throw new Error('ECONNREFUSED (fake ioredis, always unreachable in tests)');
    }
    async set(): Promise<never> {
      throw new Error('ECONNREFUSED (fake ioredis, always unreachable in tests)');
    }
    async del(): Promise<never> {
      throw new Error('ECONNREFUSED (fake ioredis, always unreachable in tests)');
    }
  }
  return { default: FakeUnreachableRedis };
});

// ---------------------------------------------------------------------------
// Fake master + tenant DB state, rebuilt fresh every test.
// ---------------------------------------------------------------------------

interface FakeState {
  platformUsers: Array<{ id: number; email: string; name: string; role: string; password_hash: string; is_active: number }>;
  tenants: Array<{ id: number; slug: string; display_name: string; status: string; db_name: string }>;
  adminUsers: Array<{ id: number; username: string; password: string; display_name: string; role: string; is_active: number }>;
  adminBotAccess: Array<{ admin_id: number; line_account_id: number; can_view: number; can_edit: number; can_broadcast: number; can_manage_users: number; can_manage_shop: number; can_view_analytics: number }>;
  nodeSessions: Map<string, Record<string, unknown>>;
  auditRows: Array<Record<string, unknown>>;
}

function makeFreshState(): FakeState {
  return {
    platformUsers: [
      { id: 9, email: 'owner@reya-platform.example', name: 'Platform Owner', role: 'support', password_hash: FIXTURE_BCRYPT_HASH, is_active: 1 },
      { id: 10, email: 'disabled@reya-platform.example', name: 'Disabled Owner', role: 'support', password_hash: FIXTURE_BCRYPT_HASH, is_active: 0 },
    ],
    tenants: [
      { id: 42, slug: 'demo-shop', display_name: 'Demo Pharmacy', status: 'active', db_name: TENANT_DB_NAME },
      { id: 99, slug: 'closed-shop', display_name: 'Closed Pharmacy', status: 'terminated', db_name: 'reya_tenant_0099' },
    ],
    adminUsers: [{ id: 1, username: 'pharmacist1', password: FIXTURE_BCRYPT_HASH, display_name: 'Pharmacist One', role: 'admin', is_active: 1 }],
    adminBotAccess: [],
    nodeSessions: new Map(),
    auditRows: [],
  };
}

function makeMasterQueryImpl(state: FakeState): QueryImpl {
  return (sqlText: string, params: unknown[]) => {
    const s = sqlText.replace(/\s+/g, ' ').trim();

    if (s.includes('FROM platform_users')) {
      const row = state.platformUsers.find((u) => u.email === params[0]);
      return row ? [row] : [];
    }

    if (s.includes('SELECT db_name FROM tenants')) {
      const row = state.tenants.find((t) => t.id === params[0]);
      return row ? [{ db_name: row.db_name }] : [];
    }

    if (s.includes('FROM tenants WHERE id')) {
      const row = state.tenants.find((t) => t.id === params[0]);
      return row ? [{ id: row.id, slug: row.slug, display_name: row.display_name, status: row.status }] : [];
    }

    if (s.includes('INSERT INTO super_admin_audit')) {
      state.auditRows.push({
        platform_user_id: params[0],
        tenant_id: params[1],
        action: params[2],
        ip_address: params[3],
        user_agent: params[4],
        request_method: params[5],
        request_uri: params[6],
        metadata: params[7],
      });
      return { insertId: state.auditRows.length, affectedRows: 1 };
    }

    if (s.includes('INSERT INTO node_sessions')) {
      const [sid, realm, admin_user_id, platform_user_id, tenant_id, current_bot_id, platform_role, impersonated_tenant_id, payload, created_at, last_seen_at, expires_at] = params;
      state.nodeSessions.set(`${realm}:${sid}`, { sid, realm, admin_user_id, platform_user_id, tenant_id, current_bot_id, platform_role, impersonated_tenant_id, payload, created_at, last_seen_at, expires_at });
      return { insertId: state.nodeSessions.size, affectedRows: 1 };
    }

    // These "by identity" (realm + admin_user_id/platform_user_id) patterns
    // are used by BOTH findByIdentity() (a SELECT) and deleteAllForIdentity()
    // (a DELETE) — both texts also contain the generic substrings
    // 'SELECT * FROM node_sessions' / 'DELETE FROM node_sessions', so these
    // more-specific checks MUST run before the generic by-sid SELECT/DELETE
    // branches below, or findByIdentity's query gets misrouted into the
    // by-sid handler (wrong param positions, always returns []).
    if (s.includes("realm = 'tenant' AND admin_user_id")) {
      const [adminUserId] = params;
      const matches = [...state.nodeSessions.values()].filter(
        (row) => row.realm === 'tenant' && row.admin_user_id === adminUserId
      );
      if (s.startsWith('SELECT')) {
        return matches;
      }
      for (const row of matches) {
        state.nodeSessions.delete(`${row.realm}:${row.sid}`);
      }
      return { affectedRows: matches.length };
    }

    if (s.includes("realm = 'platform' AND platform_user_id")) {
      const [platformUserId] = params;
      const matches = [...state.nodeSessions.values()].filter(
        (row) => row.realm === 'platform' && row.platform_user_id === platformUserId
      );
      if (s.startsWith('SELECT')) {
        return matches;
      }
      for (const row of matches) {
        state.nodeSessions.delete(`${row.realm}:${row.sid}`);
      }
      return { affectedRows: matches.length };
    }

    if (s.includes('SELECT * FROM node_sessions')) {
      const [sid, realm] = params;
      const row = state.nodeSessions.get(`${realm}:${sid}`);
      return row ? [row] : [];
    }

    if (s.includes('UPDATE node_sessions SET last_seen_at')) {
      const [last_seen_at, sid, realm] = params;
      const row = state.nodeSessions.get(`${realm}:${sid}`);
      if (row) row.last_seen_at = last_seen_at;
      return { affectedRows: row ? 1 : 0 };
    }

    if (s.includes('UPDATE node_sessions SET tenant_id')) {
      const [tenant_id, current_bot_id, platform_role, impersonated_tenant_id, payload, last_seen_at, sid, realm] = params;
      const row = state.nodeSessions.get(`${realm}:${sid}`);
      if (row) Object.assign(row, { tenant_id, current_bot_id, platform_role, impersonated_tenant_id, payload, last_seen_at });
      return { affectedRows: row ? 1 : 0 };
    }

    if (s.includes('DELETE FROM node_sessions WHERE sid')) {
      const [sid, realm] = params;
      state.nodeSessions.delete(`${realm}:${sid}`);
      return { affectedRows: 1 };
    }

    throw new Error(`Unhandled SQL in fake master DB: ${s}`);
  };
}

function makeTenantQueryImpl(state: FakeState): QueryImpl {
  return (sqlText: string, params: unknown[]) => {
    const s = sqlText.replace(/\s+/g, ' ').trim();

    if (s.includes('FROM admin_users')) {
      const row = state.adminUsers.find((u) => u.username === params[0] && u.is_active === 1);
      return row ? [row] : [];
    }

    if (s.includes('FROM admin_bot_access')) {
      const row = state.adminBotAccess.find((a) => a.admin_id === params[0] && a.line_account_id === params[1]);
      return row ? [row] : [];
    }

    throw new Error(`Unhandled SQL in fake tenant DB: ${s}`);
  };
}

let state: FakeState;
let masterPool: FakeMysqlPool;
let tenantPool: FakeMysqlPool;

beforeEach(() => {
  vi.resetModules();
  createPoolMock.mockReset();
  state = makeFreshState();
  masterPool = makeFakeMysqlPool(PLATFORM_DB_NAME, makeMasterQueryImpl(state));
  tenantPool = makeFakeMysqlPool(TENANT_DB_NAME, makeTenantQueryImpl(state));

  createPoolMock.mockImplementation((options: { database: string }) => {
    if (options.database === PLATFORM_DB_NAME) return masterPool;
    if (options.database === TENANT_DB_NAME) return tenantPool;
    return makeFakeMysqlPool(options.database, () => []);
  });

  vi.stubGlobal(
    'fetch',
    vi.fn(async () => new Response(JSON.stringify({ acknowledged: true }), { status: 200 }))
  );
});

async function loadKernel() {
  return import('../src/index');
}

describe('login() — tenant realm', () => {
  it('rejects an unknown username with invalid_credentials', async () => {
    const auth = await loadKernel();
    const { getTenantDb } = await import('@reya/db');
    const tenantDb = await getTenantDb(42);

    const result = await auth.runWithTenantDb({ tenantId: 42, db: tenantDb }, () =>
      auth.login({ realm: 'tenant', username: 'ghost', password: FIXTURE_PLAINTEXT })
    );

    expect(result).toEqual({ ok: false, error: { code: 'invalid_credentials' } });
  });

  it('rejects a wrong password with invalid_credentials', async () => {
    const auth = await loadKernel();
    const { getTenantDb } = await import('@reya/db');
    const tenantDb = await getTenantDb(42);

    const result = await auth.runWithTenantDb({ tenantId: 42, db: tenantDb }, () =>
      auth.login({ realm: 'tenant', username: 'pharmacist1', password: 'wrong-password' })
    );

    expect(result).toEqual({ ok: false, error: { code: 'invalid_credentials' } });
  });

  it('throws (not an AuthResult) when called outside runWithTenantDb() — programmer/wiring error', async () => {
    const auth = await loadKernel();
    await expect(
      auth.login({ realm: 'tenant', username: 'pharmacist1', password: FIXTURE_PLAINTEXT })
    ).rejects.toThrow(/runWithTenantDb/);
  });

  it('succeeds, returns a reya_sid cookie descriptor, and syncs the bridge', async () => {
    const auth = await loadKernel();
    const { getTenantDb } = await import('@reya/db');
    const tenantDb = await getTenantDb(42);

    const result = await auth.runWithTenantDb({ tenantId: 42, db: tenantDb }, () =>
      auth.login({ realm: 'tenant', username: 'pharmacist1', password: FIXTURE_PLAINTEXT })
    );

    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(result.value.session).toMatchObject({
      realm: 'tenant',
      adminUserId: 1,
      tenantId: 42,
      role: 'admin',
      username: 'pharmacist1',
    });
    expect(result.value.cookie).toMatchObject({
      name: 'reya_sid',
      value: result.value.session.sid,
      httpOnly: true,
      sameSite: 'lax',
      path: '/',
    });
    expect(result.value.bridgeSynced).toBe(true);
  });
});

describe('login() — platform realm', () => {
  it('rejects an unknown email with invalid_credentials', async () => {
    const auth = await loadKernel();
    const result = await auth.login({ realm: 'platform', email: 'ghost@example.com', password: FIXTURE_PLAINTEXT });
    expect(result).toEqual({ ok: false, error: { code: 'invalid_credentials' } });
  });

  it('rejects an inactive account with account_inactive (after password verifies)', async () => {
    const auth = await loadKernel();
    const result = await auth.login({
      realm: 'platform',
      email: 'disabled@reya-platform.example',
      password: FIXTURE_PLAINTEXT,
    });
    expect(result).toEqual({ ok: false, error: { code: 'account_inactive' } });
  });

  it('succeeds and returns a reya_platform_sid cookie descriptor', async () => {
    const auth = await loadKernel();
    const result = await auth.login({
      realm: 'platform',
      email: 'owner@reya-platform.example',
      password: FIXTURE_PLAINTEXT,
    });

    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(result.value.session).toMatchObject({ realm: 'platform', platformUserId: 9, platformRole: 'support' });
    expect(result.value.cookie.name).toBe('reya_platform_sid');
  });
});

describe('session rotation (session-fixation defeat)', () => {
  it('two sequential platform logins for the same identity produce different sids, and the OLD sid stops resolving via getSession()', async () => {
    const auth = await loadKernel();

    const first = await auth.login({ realm: 'platform', email: 'owner@reya-platform.example', password: FIXTURE_PLAINTEXT });
    expect(first.ok).toBe(true);
    if (!first.ok) return;
    const oldSid = first.value.session.sid;

    const second = await auth.login({ realm: 'platform', email: 'owner@reya-platform.example', password: FIXTURE_PLAINTEXT });
    expect(second.ok).toBe(true);
    if (!second.ok) return;
    const newSid = second.value.session.sid;

    expect(newSid).not.toBe(oldSid);
    expect(await auth.getSession(oldSid, 'platform')).toBeNull();
    expect(await auth.getSession(newSid, 'platform')).not.toBeNull();
  });

  it("switchTenant({type:'enter'}) rotates the sid — the OLD platform sid stops resolving afterward", async () => {
    const auth = await loadKernel();
    const login = await auth.login({ realm: 'platform', email: 'owner@reya-platform.example', password: FIXTURE_PLAINTEXT });
    expect(login.ok).toBe(true);
    if (!login.ok) return;
    const oldSid = login.value.session.sid;

    const entered = await auth.switchTenant(oldSid, { type: 'enter', tenantId: 42 });
    expect(entered.ok).toBe(true);
    if (!entered.ok) return;

    expect(entered.value.session.sid).not.toBe(oldSid);
    expect(await auth.getSession(oldSid, 'platform')).toBeNull();
    expect(await auth.getSession(entered.value.session.sid, 'platform')).not.toBeNull();
  });
});

describe('getSession()', () => {
  it('returns null for a missing sid — not an error', async () => {
    const auth = await loadKernel();
    expect(await auth.getSession('does-not-exist', 'tenant')).toBeNull();
  });

  it('returns null for undefined/null input', async () => {
    const auth = await loadKernel();
    expect(await auth.getSession(undefined, 'tenant')).toBeNull();
    expect(await auth.getSession(null, 'platform')).toBeNull();
  });

  it('returns null (and GCs the row) once expiresAt has passed', async () => {
    // Real time advance via fake timers — NOT a direct mutation of the fake
    // DB row — because a direct row mutation would only fool the DB layer:
    // the session is already write-through cached (in-memory fallback,
    // since ioredis is mocked unreachable throughout this file) at login
    // time, and that cached copy would still show the pre-mutation
    // (non-expired) expiresAt, masking the very GC behavior this test
    // exists to verify.
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      const auth = await loadKernel();
      const login = await auth.login({ realm: 'platform', email: 'owner@reya-platform.example', password: FIXTURE_PLAINTEXT });
      expect(login.ok).toBe(true);
      if (!login.ok) return;
      const sid = login.value.session.sid;

      vi.advanceTimersByTime((FAKE_ENV.NODE_SESSION_TTL_SECONDS + 60) * 1000);

      expect(await auth.getSession(sid, 'platform')).toBeNull();
      expect(state.nodeSessions.has(`platform:${sid}`)).toBe(false);
    } finally {
      vi.useRealTimers();
    }
  });
});

describe('logout()', () => {
  it('is idempotent — logging out an already-invalid sid is not an error', async () => {
    const auth = await loadKernel();
    const result = await auth.logout('never-existed', 'tenant');
    expect(result.ok).toBe(true);
  });

  it('deletes the session — a subsequent getSession() returns null', async () => {
    const auth = await loadKernel();
    const login = await auth.login({ realm: 'platform', email: 'owner@reya-platform.example', password: FIXTURE_PLAINTEXT });
    expect(login.ok).toBe(true);
    if (!login.ok) return;

    const result = await auth.logout(login.value.session.sid, 'platform');
    expect(result).toEqual({ ok: true, value: { bridgeSynced: true } });
    expect(await auth.getSession(login.value.session.sid, 'platform')).toBeNull();
  });
});

describe('switchBot() — admin_bot_access ACL', () => {
  async function loginTenant(auth: Awaited<ReturnType<typeof loadKernel>>) {
    const { getTenantDb } = await import('@reya/db');
    const tenantDb = await getTenantDb(42);
    const result = await auth.runWithTenantDb({ tenantId: 42, db: tenantDb }, () =>
      auth.login({ realm: 'tenant', username: 'pharmacist1', password: FIXTURE_PLAINTEXT })
    );
    if (!result.ok) throw new Error('login fixture failed');
    return result.value.session;
  }

  it('denies a bot with no admin_bot_access row', async () => {
    const auth = await loadKernel();
    const session = await loginTenant(auth);

    const result = await auth.switchBot(session.sid, 777);
    expect(result.ok).toBe(false);
    if (!result.ok) expect(result.error.code).toBe('forbidden');
  });

  it('denies a bot with can_view=0', async () => {
    state.adminBotAccess.push({ admin_id: 1, line_account_id: 777, can_view: 0, can_edit: 1, can_broadcast: 1, can_manage_users: 1, can_manage_shop: 1, can_view_analytics: 1 });
    const auth = await loadKernel();
    const session = await loginTenant(auth);

    const result = await auth.switchBot(session.sid, 777);
    expect(result.ok).toBe(false);
  });

  it('allows a bot with can_view=1 and updates currentBotId + syncs the bridge', async () => {
    state.adminBotAccess.push({ admin_id: 1, line_account_id: 777, can_view: 1, can_edit: 0, can_broadcast: 0, can_manage_users: 0, can_manage_shop: 0, can_view_analytics: 0 });
    const auth = await loadKernel();
    const session = await loginTenant(auth);

    const result = await auth.switchBot(session.sid, 777);
    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(result.value.session.currentBotId).toBe(777);
    expect(result.value.bridgeSynced).toBe(true);

    const reread = await auth.getSession(session.sid, 'tenant');
    expect(reread).toMatchObject({ currentBotId: 777 });
  });

  it('super_admin bypasses admin_bot_access entirely', async () => {
    state.adminUsers.push({ id: 2, username: 'root', password: FIXTURE_BCRYPT_HASH, display_name: 'Root', role: 'super_admin', is_active: 1 });
    const auth = await loadKernel();
    const { getTenantDb } = await import('@reya/db');
    const tenantDb = await getTenantDb(42);
    const login = await auth.runWithTenantDb({ tenantId: 42, db: tenantDb }, () =>
      auth.login({ realm: 'tenant', username: 'root', password: FIXTURE_PLAINTEXT })
    );
    if (!login.ok) throw new Error('login fixture failed');

    const result = await auth.switchBot(login.value.session.sid, 999999);
    expect(result.ok).toBe(true);
  });
});

describe('switchTenant()', () => {
  async function loginPlatform(auth: Awaited<ReturnType<typeof loadKernel>>) {
    const result = await auth.login({ realm: 'platform', email: 'owner@reya-platform.example', password: FIXTURE_PLAINTEXT });
    if (!result.ok) throw new Error('login fixture failed');
    return result.value.session;
  }

  it('forbids a TenantSession sid (wrong realm)', async () => {
    const auth = await loadKernel();
    const { getTenantDb } = await import('@reya/db');
    const tenantDb = await getTenantDb(42);
    const tenantLogin = await auth.runWithTenantDb({ tenantId: 42, db: tenantDb }, () =>
      auth.login({ realm: 'tenant', username: 'pharmacist1', password: FIXTURE_PLAINTEXT })
    );
    if (!tenantLogin.ok) throw new Error('login fixture failed');

    const result = await auth.switchTenant(tenantLogin.value.session.sid, { type: 'enter', tenantId: 42 });
    expect(result.ok).toBe(false);
    if (!result.ok) expect(result.error.code).toBe('forbidden');
  });

  it('enter: not_found for a nonexistent tenant id', async () => {
    const auth = await loadKernel();
    const session = await loginPlatform(auth);
    const result = await auth.switchTenant(session.sid, { type: 'enter', tenantId: 424242 });
    expect(result).toEqual({ ok: false, error: { code: 'not_found' } });
  });

  it('enter: forbidden for a terminated tenant', async () => {
    const auth = await loadKernel();
    const session = await loginPlatform(auth);
    const result = await auth.switchTenant(session.sid, { type: 'enter', tenantId: 99 });
    expect(result.ok).toBe(false);
    if (!result.ok) expect(result.error.code).toBe('forbidden');
  });

  it('enter: succeeds, sets impersonatedTenantId, rotates sid, writes exactly one switch_tenant_in audit row', async () => {
    const auth = await loadKernel();
    const session = await loginPlatform(auth);

    const result = await auth.switchTenant(session.sid, { type: 'enter', tenantId: 42, reason: 'support ticket #123' });
    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(result.value.session.impersonatedTenantId).toBe(42);
    expect(result.value.session.sid).not.toBe(session.sid);

    const auditRows = state.auditRows.filter((r) => r.action === 'switch_tenant_in');
    expect(auditRows).toHaveLength(1);
    expect(auditRows[0]).toMatchObject({ platform_user_id: 9, tenant_id: 42, action: 'switch_tenant_in' });
    const metadata = JSON.parse(auditRows[0]!.metadata as string);
    expect(metadata).toEqual({
      tenant_slug: 'demo-shop',
      tenant_display_name: 'Demo Pharmacy',
      tenant_status: 'active',
      reason: 'support ticket #123',
    });
  });

  it('exit: clears impersonatedTenantId, rotates sid, writes exactly one switch_tenant_out audit row', async () => {
    const auth = await loadKernel();
    const session = await loginPlatform(auth);
    const entered = await auth.switchTenant(session.sid, { type: 'enter', tenantId: 42 });
    if (!entered.ok) throw new Error('enter fixture failed');

    const exited = await auth.switchTenant(entered.value.session.sid, { type: 'exit' });
    expect(exited.ok).toBe(true);
    if (!exited.ok) return;
    expect(exited.value.session.impersonatedTenantId).toBeNull();
    expect(exited.value.session.sid).not.toBe(entered.value.session.sid);

    const outRows = state.auditRows.filter((r) => r.action === 'switch_tenant_out');
    expect(outRows).toHaveLength(1);
    expect(outRows[0]).toMatchObject({ platform_user_id: 9, tenant_id: 42, action: 'switch_tenant_out' });
  });
});

describe('bridge unreachable — Node-side state changes still complete', () => {
  it('login() still succeeds with bridgeSynced:false when fetch fails', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new Error('ECONNREFUSED');
      })
    );
    const auth = await loadKernel();
    const result = await auth.login({ realm: 'platform', email: 'owner@reya-platform.example', password: FIXTURE_PLAINTEXT });
    expect(result.ok).toBe(true);
    if (result.ok) expect(result.value.bridgeSynced).toBe(false);
  });

  it('switchTenant() still rotates + audits with bridgeSynced:false when fetch fails', async () => {
    const auth = await loadKernel();
    const login = await auth.login({ realm: 'platform', email: 'owner@reya-platform.example', password: FIXTURE_PLAINTEXT });
    if (!login.ok) throw new Error('login fixture failed');

    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        throw new Error('ECONNREFUSED');
      })
    );

    const result = await auth.switchTenant(login.value.session.sid, { type: 'enter', tenantId: 42 });
    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(result.value.bridgeSynced).toBe(false);
    expect(state.auditRows.filter((r) => r.action === 'switch_tenant_in')).toHaveLength(1);
  });
});
