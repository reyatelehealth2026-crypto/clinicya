import { sql, type Kysely } from 'kysely';
import type { Realm, Session } from './types';

/**
 * sessionStore.ts — node_sessions persistence (master DB, source of truth)
 * wrapped by a read-through/write-through cache that falls back to an
 * in-memory Map automatically when Redis is unreachable.
 *
 * SessionStore is the interface every layer above (session.ts) codes
 * against; MySqlSessionStore is the only implementation that ever talks to
 * node_sessions directly; CachedSessionStore decorates any SessionStore with
 * caching and is what session.ts actually wires up by default.
 */

export interface SessionStore {
  /** Inserts a new session row. `session.sid` must not already exist. */
  create(session: Session): Promise<void>;
  /** Fetches a session by (sid, realm). Returns null if missing — does NOT interpret/enforce expiresAt (that's session.ts's getSession() job) or touch last_seen_at (that's touch()'s job). */
  get(sid: string, realm: Realm): Promise<Session | null>;
  /** Bumps last_seen_at only, in place, same sid. */
  touch(sid: string, realm: Realm, now: Date): Promise<void>;
  /** Persists mutated fields of an existing session (same sid, same realm) — e.g. switchBot()'s currentBotId change. */
  update(session: Session): Promise<void>;
  /** Atomically-in-intent (see module doc below) replaces the row at oldSid with newSession (a different sid, same realm) — used by login()'s and switchTenant()'s privilege-elevation rotation. */
  rotate(oldSid: string, newSession: Session): Promise<void>;
  /** Deletes a session row. Idempotent — deleting an already-missing sid is not an error. */
  delete(sid: string, realm: Realm): Promise<void>;
  /** Deletes every session row for one identity within a realm — the "invalidate ALL of one user's sessions in one query" mechanism the node_sessions migration's (realm, admin_user_id)/(realm, platform_user_id) indexes exist for. Used by login() for single-active-session enforcement; also the natural hook for future ACL-revocation call sites. */
  deleteAllForIdentity(realm: Realm, identity: { adminUserId?: number; platformUserId?: number }): Promise<void>;
  /** Every session row currently held by one identity within a realm. Used internally by CachedSessionStore.deleteAllForIdentity() to know exactly which cache keys (by sid) need evicting before the bulk DB delete — the identity itself isn't a cache key, only individual sids are. */
  findByIdentity(realm: Realm, identity: { adminUserId?: number; platformUserId?: number }): Promise<Session[]>;
}

// ---------------------------------------------------------------------------
// MySQL-backed implementation (source of truth).
// ---------------------------------------------------------------------------

interface NodeSessionRow {
  sid: string;
  realm: Realm;
  admin_user_id: number | null;
  platform_user_id: number | null;
  tenant_id: number | null;
  current_bot_id: number | null;
  platform_role: string | null;
  impersonated_tenant_id: number | null;
  payload: unknown; // JSON column — mysql2 typically hands back a parsed object, but tests/mocks may hand back a string; deserializeRow() handles both.
  created_at: unknown;
  last_seen_at: unknown;
  expires_at: unknown;
}

function toRow(session: Session): {
  sid: string;
  realm: Realm;
  admin_user_id: number | null;
  platform_user_id: number | null;
  tenant_id: number | null;
  current_bot_id: number | null;
  platform_role: string | null;
  impersonated_tenant_id: number | null;
  payload: string;
  created_at: string;
  last_seen_at: string;
  expires_at: string;
} {
  const base = {
    sid: session.sid,
    payload: JSON.stringify(session),
    created_at: session.createdAt,
    last_seen_at: session.lastSeenAt,
    expires_at: session.expiresAt,
  };

  if (session.realm === 'tenant') {
    return {
      ...base,
      realm: 'tenant',
      admin_user_id: session.adminUserId,
      platform_user_id: null,
      tenant_id: session.tenantId,
      current_bot_id: session.currentBotId,
      platform_role: null,
      impersonated_tenant_id: null,
    };
  }

  return {
    ...base,
    realm: 'platform',
    admin_user_id: null,
    platform_user_id: session.platformUserId,
    tenant_id: null,
    current_bot_id: null,
    platform_role: session.platformRole,
    impersonated_tenant_id: session.impersonatedTenantId,
  };
}

/**
 * The `payload` JSON column is the ground truth for reconstructing a
 * Session — the individual mirror columns exist purely for WHERE-clause
 * filtering/indexing, never as a second source to reconcile against.
 */
export function deserializeSessionRow(row: NodeSessionRow): Session {
  const parsed = typeof row.payload === 'string' ? JSON.parse(row.payload) : row.payload;
  return parsed as Session;
}

export function createMySqlSessionStore(db: Kysely<any>): SessionStore {
  return {
    async create(session: Session): Promise<void> {
      const r = toRow(session);
      await sql`
        INSERT INTO node_sessions
          (sid, realm, admin_user_id, platform_user_id, tenant_id, current_bot_id,
           platform_role, impersonated_tenant_id, payload, created_at, last_seen_at, expires_at)
        VALUES
          (${r.sid}, ${r.realm}, ${r.admin_user_id}, ${r.platform_user_id}, ${r.tenant_id}, ${r.current_bot_id},
           ${r.platform_role}, ${r.impersonated_tenant_id}, ${r.payload}, ${r.created_at}, ${r.last_seen_at}, ${r.expires_at})
      `.execute(db);
    },

    async get(sid: string, realm: Realm): Promise<Session | null> {
      const result = await sql<NodeSessionRow>`
        SELECT * FROM node_sessions WHERE sid = ${sid} AND realm = ${realm} LIMIT 1
      `.execute(db);
      const row = result.rows[0];
      return row ? deserializeSessionRow(row) : null;
    },

    async touch(sid: string, realm: Realm, now: Date): Promise<void> {
      await sql`
        UPDATE node_sessions SET last_seen_at = ${now.toISOString()} WHERE sid = ${sid} AND realm = ${realm}
      `.execute(db);
    },

    async update(session: Session): Promise<void> {
      const r = toRow(session);
      await sql`
        UPDATE node_sessions
        SET tenant_id = ${r.tenant_id},
            current_bot_id = ${r.current_bot_id},
            platform_role = ${r.platform_role},
            impersonated_tenant_id = ${r.impersonated_tenant_id},
            payload = ${r.payload},
            last_seen_at = ${r.last_seen_at}
        WHERE sid = ${r.sid} AND realm = ${r.realm}
      `.execute(db);
    },

    async rotate(oldSid: string, newSession: Session): Promise<void> {
      // NOTE: sequential DELETE + INSERT, not wrapped in a DB transaction —
      // a real (small) race window exists between the two statements. Phase
      // 1 scope tradeoff (see packages/auth build report); revisit with
      // db.transaction().execute() once kysely-codegen types exist and this
      // is exercised against a real pool, not just the offline mysql2 mock.
      await sql`DELETE FROM node_sessions WHERE sid = ${oldSid} AND realm = ${newSession.realm}`.execute(db);
      const r = toRow(newSession);
      await sql`
        INSERT INTO node_sessions
          (sid, realm, admin_user_id, platform_user_id, tenant_id, current_bot_id,
           platform_role, impersonated_tenant_id, payload, created_at, last_seen_at, expires_at)
        VALUES
          (${r.sid}, ${r.realm}, ${r.admin_user_id}, ${r.platform_user_id}, ${r.tenant_id}, ${r.current_bot_id},
           ${r.platform_role}, ${r.impersonated_tenant_id}, ${r.payload}, ${r.created_at}, ${r.last_seen_at}, ${r.expires_at})
      `.execute(db);
    },

    async delete(sid: string, realm: Realm): Promise<void> {
      await sql`DELETE FROM node_sessions WHERE sid = ${sid} AND realm = ${realm}`.execute(db);
    },

    async deleteAllForIdentity(
      realm: Realm,
      identity: { adminUserId?: number; platformUserId?: number }
    ): Promise<void> {
      if (realm === 'tenant' && identity.adminUserId !== undefined) {
        await sql`
          DELETE FROM node_sessions WHERE realm = 'tenant' AND admin_user_id = ${identity.adminUserId}
        `.execute(db);
        return;
      }
      if (realm === 'platform' && identity.platformUserId !== undefined) {
        await sql`
          DELETE FROM node_sessions WHERE realm = 'platform' AND platform_user_id = ${identity.platformUserId}
        `.execute(db);
        return;
      }
      // Missing the relevant identity field for the given realm is a no-op,
      // not an error — callers always pass the matching field (session.ts
      // does), this guard just avoids an accidental unscoped DELETE.
    },

    async findByIdentity(
      realm: Realm,
      identity: { adminUserId?: number; platformUserId?: number }
    ): Promise<Session[]> {
      if (realm === 'tenant' && identity.adminUserId !== undefined) {
        const result = await sql<NodeSessionRow>`
          SELECT * FROM node_sessions WHERE realm = 'tenant' AND admin_user_id = ${identity.adminUserId}
        `.execute(db);
        return result.rows.map(deserializeSessionRow);
      }
      if (realm === 'platform' && identity.platformUserId !== undefined) {
        const result = await sql<NodeSessionRow>`
          SELECT * FROM node_sessions WHERE realm = 'platform' AND platform_user_id = ${identity.platformUserId}
        `.execute(db);
        return result.rows.map(deserializeSessionRow);
      }
      return [];
    },
  };
}

// ---------------------------------------------------------------------------
// Redis-with-automatic-in-memory-fallback cache.
// ---------------------------------------------------------------------------

/**
 * The minimal shape of an ioredis client this module needs — deliberately
 * NOT importing ioredis's own types here so tests can construct a trivial
 * fake object literal without pulling in the real client. redisClient.ts's
 * real ioredis instance satisfies this structurally.
 */
export interface RedisLikeClient {
  get(key: string): Promise<string | null>;
  set(key: string, value: string, mode: 'EX', ttlSeconds: number): Promise<unknown>;
  del(key: string): Promise<unknown>;
}

interface MemoryEntry {
  value: string;
  expiresAt: number;
}

/**
 * SessionCache — tries the constructor-injected Redis client first; ANY
 * rejection from it (unreachable, timeout, auth failure, or `client` being
 * null to begin with) makes this call fall back to an in-memory Map
 * transparently. This is deliberately a fallback, not a two-tier
 * always-both-checked cache: while Redis is healthy, the in-memory Map is
 * never consulted, so an entry written during a Redis outage can become
 * briefly invisible again once Redis recovers (a plain cache miss — safe,
 * just not maximally efficient; the read-through to MySQL below always
 * produces a correct result either way).
 */
export class SessionCache {
  private readonly memory = new Map<string, MemoryEntry>();

  constructor(private readonly redis: RedisLikeClient | null) {}

  async get(key: string): Promise<string | null> {
    if (this.redis) {
      try {
        return await this.redis.get(key);
      } catch {
        // Redis unreachable — fall through to the in-memory fallback below.
      }
    }
    const entry = this.memory.get(key);
    if (!entry) {
      return null;
    }
    if (entry.expiresAt <= Date.now()) {
      this.memory.delete(key);
      return null;
    }
    return entry.value;
  }

  async set(key: string, value: string, ttlSeconds: number): Promise<void> {
    if (this.redis) {
      try {
        await this.redis.set(key, value, 'EX', ttlSeconds);
        return;
      } catch {
        // Redis unreachable — fall through to the in-memory fallback below.
      }
    }
    this.memory.set(key, { value, expiresAt: Date.now() + ttlSeconds * 1000 });
  }

  async del(key: string): Promise<void> {
    if (this.redis) {
      try {
        await this.redis.del(key);
      } catch {
        // Best-effort — still clear the in-memory copy below for consistency.
      }
    }
    this.memory.delete(key);
  }
}

// ---------------------------------------------------------------------------
// SessionStore decorator — read-through/write-through the SessionCache.
// ---------------------------------------------------------------------------

function cacheKey(realm: Realm, sid: string): string {
  return `reya:node_session:${realm}:${sid}`;
}

export class CachedSessionStore implements SessionStore {
  constructor(
    private readonly inner: SessionStore,
    private readonly cache: SessionCache
  ) {}

  async create(session: Session): Promise<void> {
    await this.inner.create(session);
    await this.writeThrough(session);
  }

  async get(sid: string, realm: Realm): Promise<Session | null> {
    const cached = await this.cache.get(cacheKey(realm, sid));
    if (cached) {
      try {
        return JSON.parse(cached) as Session;
      } catch {
        // Corrupt cache entry — fall through to the DB read below.
      }
    }

    const session = await this.inner.get(sid, realm);
    if (session) {
      await this.writeThrough(session);
    }
    return session;
  }

  async touch(sid: string, realm: Realm, now: Date): Promise<void> {
    // Cache entries are left to age out naturally on their own TTL rather
    // than paying a read-modify-write round trip on every touch — lastSeenAt
    // is a low-value field to keep perfectly fresh in cache, and the DB
    // remains the correct source of truth for it either way.
    await this.inner.touch(sid, realm, now);
  }

  async update(session: Session): Promise<void> {
    await this.inner.update(session);
    await this.writeThrough(session);
  }

  async rotate(oldSid: string, newSession: Session): Promise<void> {
    await this.inner.rotate(oldSid, newSession);
    await this.cache.del(cacheKey(newSession.realm, oldSid));
    await this.writeThrough(newSession);
  }

  async delete(sid: string, realm: Realm): Promise<void> {
    await this.inner.delete(sid, realm);
    await this.cache.del(cacheKey(realm, sid));
  }

  async findByIdentity(
    realm: Realm,
    identity: { adminUserId?: number; platformUserId?: number }
  ): Promise<Session[]> {
    // Not cached — this is only ever called internally by
    // deleteAllForIdentity() below, immediately before the rows it returns
    // get deleted; caching it would just be dead weight.
    return this.inner.findByIdentity(realm, identity);
  }

  async deleteAllForIdentity(
    realm: Realm,
    identity: { adminUserId?: number; platformUserId?: number }
  ): Promise<void> {
    // The cache is keyed by sid, not by identity, so a bulk-by-identity
    // delete can't compute which cache keys to purge without first knowing
    // which sids exist. Read them from the source of truth, evict each
    // one's cache entry, THEN delete the rows — this is exactly what makes
    // this correct instead of "best-effort": without it, a stale sid's
    // session would keep resolving out of the in-memory/Redis cache after
    // its DB row was deleted (this is the exact mechanism login()'s
    // single-active-session enforcement depends on — a fresh login must
    // make the previous sid unusable immediately, not eventually on TTL).
    const existing = await this.inner.findByIdentity(realm, identity);
    await Promise.all(existing.map((session) => this.cache.del(cacheKey(realm, session.sid))));
    await this.inner.deleteAllForIdentity(realm, identity);
  }

  private async writeThrough(session: Session): Promise<void> {
    const ttlSeconds = Math.max(1, Math.floor((new Date(session.expiresAt).getTime() - Date.now()) / 1000));
    await this.cache.set(cacheKey(session.realm, session.sid), JSON.stringify(session), ttlSeconds);
  }
}
