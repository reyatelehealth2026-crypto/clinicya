import { describe, expect, it, vi } from 'vitest';
import type { MasterDB } from '@reya/db';
import {
  CachedSessionStore,
  SessionCache,
  createMySqlSessionStore,
  type RedisLikeClient,
} from '../src/sessionStore';
import type { TenantSession } from '../src/types';
import { makeTestDb } from './helpers/makeTestDb';

function tenantSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid-abc',
    adminUserId: 1,
    tenantId: 42,
    currentBotId: null,
    role: 'admin',
    username: 'pharmacist1',
    displayName: 'Pharmacist One',
    createdAt: '2026-07-12T00:00:00.000Z',
    lastSeenAt: '2026-07-12T00:00:00.000Z',
    expiresAt: '2026-07-13T00:00:00.000Z',
    ...overrides,
  };
}

describe('createMySqlSessionStore (node_sessions CRUD)', () => {
  it('create() INSERTs the full row including the JSON payload', async () => {
    const { db, pool } = makeTestDb<MasterDB>('master', () => ({ insertId: 1, affectedRows: 1 }));
    const store = createMySqlSessionStore(db);
    const session = tenantSession();

    await store.create(session);

    expect(pool.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/INSERT INTO node_sessions/),
      expect.arrayContaining([session.sid, 'tenant', session.adminUserId, null, session.tenantId]),
      expect.any(Function)
    );
  });

  it('create() converts the ISO 8601 createdAt/lastSeenAt/expiresAt into MariaDB DATETIME literals (Bangkok +07:00, not raw ISO) — regression test for the strict-mode "Incorrect datetime value" write failure', async () => {
    const { db, pool } = makeTestDb<MasterDB>('master', () => ({ insertId: 1, affectedRows: 1 }));
    const store = createMySqlSessionStore(db);
    const session = tenantSession({
      createdAt: '2026-07-12T00:00:00.000Z',
      lastSeenAt: '2026-07-12T00:00:00.000Z',
      expiresAt: '2026-07-13T00:00:00.000Z',
    });

    await store.create(session);

    const [, params] = (pool.connection.query as unknown as { mock: { calls: unknown[][] } }).mock.calls[0];
    // Last 3 bound params, per the fixed column order in the INSERT text:
    // ..., payload, created_at, last_seen_at, expires_at.
    const [createdAt, lastSeenAt, expiresAt] = (params as unknown[]).slice(-3);
    expect(createdAt).toBe('2026-07-12 07:00:00');
    expect(lastSeenAt).toBe('2026-07-12 07:00:00');
    expect(expiresAt).toBe('2026-07-13 07:00:00');
    // None of the three raw ISO instants (with 'T'/'Z') reach the mysql2 driver.
    for (const value of [createdAt, lastSeenAt, expiresAt]) {
      expect(value).not.toMatch(/[TZ]/);
    }
  });

  it('get() returns null when no row matches', async () => {
    const { db } = makeTestDb<MasterDB>('master', () => []);
    const store = createMySqlSessionStore(db);
    expect(await store.get('missing-sid', 'tenant')).toBeNull();
  });

  it('get() deserializes the JSON payload column back into a Session', async () => {
    const session = tenantSession();
    const { db } = makeTestDb<MasterDB>('master', () => [{ payload: JSON.stringify(session) }]);
    const store = createMySqlSessionStore(db);
    expect(await store.get(session.sid, 'tenant')).toEqual(session);
  });

  it('get() also handles a driver that hands back an already-parsed payload object (not a string)', async () => {
    const session = tenantSession();
    const { db } = makeTestDb<MasterDB>('master', () => [{ payload: session }]);
    const store = createMySqlSessionStore(db);
    expect(await store.get(session.sid, 'tenant')).toEqual(session);
  });

  it('touch() UPDATEs last_seen_at scoped to sid+realm', async () => {
    const { db, pool } = makeTestDb<MasterDB>('master', () => ({ affectedRows: 1 }));
    const store = createMySqlSessionStore(db);
    const now = new Date('2026-07-12T01:00:00.000Z');

    await store.touch('sid-abc', 'tenant', now);

    // now = 2026-07-12T01:00:00.000Z -> stored as the MariaDB DATETIME
    // literal for the Bangkok (+07:00) wall-clock equivalent (08:00), since
    // every connection runs `SET time_zone = '+07:00'` (packages/db) and a
    // raw ISO string ('...T...Z') is rejected outright by strict-mode
    // MariaDB. See toMySqlDateTime()'s doc comment in sessionStore.ts.
    expect(pool.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/UPDATE node_sessions SET last_seen_at/),
      ['2026-07-12 08:00:00', 'sid-abc', 'tenant'],
      expect.any(Function)
    );
  });

  it('rotate() deletes the old sid then inserts the new session row', async () => {
    const calls: string[] = [];
    const { db, pool } = makeTestDb<MasterDB>('master', (sqlText) => {
      calls.push(sqlText);
      return { affectedRows: 1 };
    });
    const store = createMySqlSessionStore(db);
    const newSession = tenantSession({ sid: 'sid-new' });

    await store.rotate('sid-old', newSession);

    expect(calls[0]).toMatch(/DELETE FROM node_sessions WHERE sid = \? AND realm = \?/);
    expect(calls[1]).toMatch(/INSERT INTO node_sessions/);
    expect(pool.connection.query).toHaveBeenNthCalledWith(
      1,
      expect.stringMatching(/DELETE/),
      ['sid-old', 'tenant'],
      expect.any(Function)
    );
  });

  it('delete() is a plain DELETE scoped to sid+realm', async () => {
    const { db, pool } = makeTestDb<MasterDB>('master', () => ({ affectedRows: 1 }));
    const store = createMySqlSessionStore(db);

    await store.delete('sid-abc', 'tenant');

    expect(pool.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/DELETE FROM node_sessions WHERE sid = \? AND realm = \?/),
      ['sid-abc', 'tenant'],
      expect.any(Function)
    );
  });

  it('deleteAllForIdentity() scopes by realm + admin_user_id for the tenant realm', async () => {
    const { db, pool } = makeTestDb<MasterDB>('master', () => ({ affectedRows: 2 }));
    const store = createMySqlSessionStore(db);

    await store.deleteAllForIdentity('tenant', { adminUserId: 7 });

    expect(pool.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/realm = 'tenant' AND admin_user_id = \?/),
      [7],
      expect.any(Function)
    );
  });

  it('deleteAllForIdentity() scopes by realm + platform_user_id for the platform realm', async () => {
    const { db, pool } = makeTestDb<MasterDB>('master', () => ({ affectedRows: 1 }));
    const store = createMySqlSessionStore(db);

    await store.deleteAllForIdentity('platform', { platformUserId: 3 });

    expect(pool.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/realm = 'platform' AND platform_user_id = \?/),
      [3],
      expect.any(Function)
    );
  });

  it('findByIdentity() returns every session row for one identity, deserialized', async () => {
    const s1 = tenantSession({ sid: 'sid-1' });
    const s2 = tenantSession({ sid: 'sid-2' });
    const { db } = makeTestDb<MasterDB>('master', () => [{ payload: JSON.stringify(s1) }, { payload: JSON.stringify(s2) }]);
    const store = createMySqlSessionStore(db);

    const rows = await store.findByIdentity('tenant', { adminUserId: 1 });
    expect(rows).toEqual([s1, s2]);
  });

  it('findByIdentity() returns [] when the identity field for the given realm is missing', async () => {
    const { db } = makeTestDb<MasterDB>('master', () => {
      throw new Error('should not query');
    });
    const store = createMySqlSessionStore(db);
    expect(await store.findByIdentity('tenant', {})).toEqual([]);
  });
});

// ---------------------------------------------------------------------------
// SessionCache — Redis-with-automatic-in-memory-fallback.
// ---------------------------------------------------------------------------

function makeWorkingFakeRedis(): RedisLikeClient {
  const map = new Map<string, string>();
  return {
    get: vi.fn(async (key: string) => map.get(key) ?? null),
    set: vi.fn(async (key: string, value: string) => {
      map.set(key, value);
      return 'OK';
    }),
    del: vi.fn(async (key: string) => {
      map.delete(key);
      return 1;
    }),
  };
}

function makeUnreachableFakeRedis(): RedisLikeClient {
  return {
    get: vi.fn(async () => {
      throw new Error('ECONNREFUSED');
    }),
    set: vi.fn(async () => {
      throw new Error('ECONNREFUSED');
    }),
    del: vi.fn(async () => {
      throw new Error('ECONNREFUSED');
    }),
  };
}

describe('SessionCache', () => {
  it('with a working Redis client, get() after set() round-trips through Redis', async () => {
    const redis = makeWorkingFakeRedis();
    const cache = new SessionCache(redis);

    await cache.set('k', 'v', 60);
    expect(await cache.get('k')).toBe('v');
    expect(redis.set).toHaveBeenCalledWith('k', 'v', 'EX', 60);
  });

  it('with client=null, falls back to the in-memory Map transparently', async () => {
    const cache = new SessionCache(null);
    await cache.set('k', 'v', 60);
    expect(await cache.get('k')).toBe('v');
  });

  it('automatically falls back to memory when Redis.get() rejects (unreachable)', async () => {
    const redis = makeUnreachableFakeRedis();
    const cache = new SessionCache(redis);

    // Prime the memory fallback via a set() call that also hits the (failing) Redis first.
    await cache.set('k', 'v', 60);
    expect(await cache.get('k')).toBe('v'); // served from memory, not Redis
    expect(redis.get).toHaveBeenCalled(); // Redis WAS tried first
  });

  it('memory entries expire after their TTL', async () => {
    vi.useFakeTimers();
    try {
      const cache = new SessionCache(null);
      await cache.set('k', 'v', 1); // 1 second TTL
      expect(await cache.get('k')).toBe('v');
      vi.advanceTimersByTime(1500);
      expect(await cache.get('k')).toBeNull();
    } finally {
      vi.useRealTimers();
    }
  });

  it('del() clears both Redis and the memory fallback', async () => {
    const redis = makeWorkingFakeRedis();
    const cache = new SessionCache(redis);
    await cache.set('k', 'v', 60);
    await cache.del('k');
    expect(await cache.get('k')).toBeNull();
  });
});

// ---------------------------------------------------------------------------
// CachedSessionStore — decorator wiring.
// ---------------------------------------------------------------------------

function makeFakeInnerStore() {
  const rows = new Map<string, TenantSession>();
  return {
    rows,
    create: vi.fn(async (session: TenantSession) => {
      rows.set(`${session.realm}:${session.sid}`, session);
    }),
    get: vi.fn(async (sid: string, realm: string) => rows.get(`${realm}:${sid}`) ?? null),
    touch: vi.fn(async () => {}),
    update: vi.fn(async (session: TenantSession) => {
      rows.set(`${session.realm}:${session.sid}`, session);
    }),
    rotate: vi.fn(async (oldSid: string, newSession: TenantSession) => {
      rows.delete(`${newSession.realm}:${oldSid}`);
      rows.set(`${newSession.realm}:${newSession.sid}`, newSession);
    }),
    delete: vi.fn(async (sid: string, realm: string) => {
      rows.delete(`${realm}:${sid}`);
    }),
    deleteAllForIdentity: vi.fn(async (realm: string, identity: { adminUserId?: number }) => {
      for (const [key, row] of rows) {
        if (row.realm === realm && row.adminUserId === identity.adminUserId) {
          rows.delete(key);
        }
      }
    }),
    findByIdentity: vi.fn(async (realm: string, identity: { adminUserId?: number }) =>
      [...rows.values()].filter((row) => row.realm === realm && row.adminUserId === identity.adminUserId)
    ),
  };
}

describe('CachedSessionStore', () => {
  it('get() is a cache miss on first read (goes to inner), then a cache hit on the second read', async () => {
    const inner = makeFakeInnerStore();
    const session = tenantSession();
    await inner.create(session);
    const cache = new SessionCache(null);
    const store = new CachedSessionStore(inner, cache);

    const first = await store.get(session.sid, 'tenant');
    expect(first).toEqual(session);
    expect(inner.get).toHaveBeenCalledTimes(1);

    const second = await store.get(session.sid, 'tenant');
    expect(second).toEqual(session);
    expect(inner.get).toHaveBeenCalledTimes(1); // not called again — served from cache
  });

  it('rotate() invalidates the old cache key and writes-through the new one', async () => {
    const inner = makeFakeInnerStore();
    const session = tenantSession();
    await inner.create(session);
    const cache = new SessionCache(null);
    const store = new CachedSessionStore(inner, cache);

    await store.get(session.sid, 'tenant'); // warm the cache for the OLD sid

    const rotated = { ...session, sid: 'sid-rotated' };
    await store.rotate(session.sid, rotated);

    // Old sid must be a cache+DB miss now.
    inner.get.mockClear();
    expect(await store.get(session.sid, 'tenant')).toBeNull();

    // New sid resolves straight from the (write-through) cache, no inner.get() call.
    inner.get.mockClear();
    expect(await store.get('sid-rotated', 'tenant')).toEqual(rotated);
    expect(inner.get).not.toHaveBeenCalled();
  });

  it('delete() clears the cache entry too', async () => {
    const inner = makeFakeInnerStore();
    const session = tenantSession();
    await inner.create(session);
    const cache = new SessionCache(null);
    const store = new CachedSessionStore(inner, cache);

    await store.get(session.sid, 'tenant');
    await store.delete(session.sid, 'tenant');

    expect(await store.get(session.sid, 'tenant')).toBeNull();
  });

  it('deleteAllForIdentity() evicts the CACHE entry for a previously-cached sid too, not just the DB row — the bug this decorator exists to prevent', async () => {
    const inner = makeFakeInnerStore();
    const session = tenantSession({ sid: 'sid-old' });
    await inner.create(session);
    const cache = new SessionCache(null);
    const store = new CachedSessionStore(inner, cache);

    // Warm the cache for the old sid (simulates login() having created +
    // cached it earlier in the same process).
    expect(await store.get('sid-old', 'tenant')).toEqual(session);

    await store.deleteAllForIdentity('tenant', { adminUserId: session.adminUserId });

    // Without the findByIdentity()-based eviction, this would incorrectly
    // still return the stale cached session even though inner.rows no
    // longer has it.
    expect(await store.get('sid-old', 'tenant')).toBeNull();
  });
});
