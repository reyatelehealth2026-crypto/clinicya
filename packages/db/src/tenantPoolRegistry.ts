import { createPool, type Pool as RawMysqlPool } from 'mysql2';
import { Kysely, MysqlDialect, sql } from 'kysely';
import { loadEnv } from '@reya/config';
import { getMasterDb } from './masterPool';

/**
 * tenantPoolRegistry.ts — LRU registry of per-tenant Kysely instances.
 *
 * Replaces Database::forTenant()'s per-request instance map. Plan §1.2:
 *   `getTenantDb(tenantId)` — lookup `db_name` from `master.tenants` (cached
 *   60s) → registry `Map<dbName, Kysely<TenantDB>>` as an LRU (~50 pools,
 *   idle-evict 10 min, connectionLimit 3-5/pool). Every new physical
 *   connection gets `SET time_zone='+07:00'` + utf8mb4, same as masterPool.ts.
 *
 * This is the thing that stands in for per-request PDO in PHP: instead of
 * opening+closing a connection every request, we keep a small number of
 * long-lived pools and evict the coldest ones once we're at capacity or a
 * tenant goes quiet, so N tenants never means N held-open pools.
 *
 * Each registry entry keeps BOTH the Kysely wrapper (what callers use to
 * build/run queries) and the raw `mysql2` pool it wraps (what the registry
 * itself uses for eviction) — see masterPool.ts's doc comment for why
 * eviction must go through the raw pool's `.end()` directly rather than
 * `Kysely#destroy()`.
 */

export class TenantNotFoundError extends Error {
  constructor(public readonly tenantId: number) {
    super(`Tenant id ${tenantId} not found in master.tenants — cannot route connection.`);
    this.name = 'TenantNotFoundError';
  }
}

const MIN_CONNECTION_LIMIT = 3;
const MAX_CONNECTION_LIMIT = 5;
const DEFAULT_MAX_POOLS = 50;
const DEFAULT_IDLE_EVICT_MS = 10 * 60 * 1000; // 10 minutes
const DEFAULT_DB_NAME_CACHE_TTL_MS = 60 * 1000; // 60 seconds

export interface TenantPoolRegistryConfig {
  /** LRU capacity — default 50 (plan §1.2). */
  maxPools?: number;
  /** Evict a pool after this many ms with no getTenantDb() touch — default 10 min. */
  idleEvictMs?: number;
  /** Passed straight through to mysql2 `createPool({ connectionLimit })`. Must be 3-5 (plan §1.2). */
  connectionLimit?: number;
  /** How long a resolved tenantId -> db_name lookup is cached — default 60s. */
  dbNameCacheTtlMs?: number;
}

interface PoolEntry {
  db: Kysely<any>;
  pool: RawMysqlPool;
  evictTimer: ReturnType<typeof setTimeout>;
}

interface DbNameCacheEntry {
  dbName: string;
  expiresAt: number;
}

function normalizeConnectionLimit(value: number | undefined): number {
  const limit = value ?? MAX_CONNECTION_LIMIT;
  if (!Number.isInteger(limit) || limit < MIN_CONNECTION_LIMIT || limit > MAX_CONNECTION_LIMIT) {
    throw new RangeError(
      `TenantPoolRegistry connectionLimit must be an integer between ${MIN_CONNECTION_LIMIT} and ` +
        `${MAX_CONNECTION_LIMIT} inclusive (plan §1.2), got ${String(value)}`
    );
  }
  return limit;
}

export class TenantPoolRegistry {
  private readonly maxPools: number;
  private readonly idleEvictMs: number;
  private readonly connectionLimit: number;
  private readonly dbNameCacheTtlMs: number;

  /**
   * Map iteration order tracks recency (oldest-first). Touching an entry
   * deletes + re-inserts it, moving it to the MRU (last) position — the
   * standard "Map as LRU" trick, avoids pulling in a dependency for this.
   */
  private readonly pools = new Map<string, PoolEntry>();
  private readonly dbNameCache = new Map<number, DbNameCacheEntry>();

  constructor(config: TenantPoolRegistryConfig = {}) {
    this.maxPools = config.maxPools ?? DEFAULT_MAX_POOLS;
    this.idleEvictMs = config.idleEvictMs ?? DEFAULT_IDLE_EVICT_MS;
    this.connectionLimit = normalizeConnectionLimit(config.connectionLimit);
    this.dbNameCacheTtlMs = config.dbNameCacheTtlMs ?? DEFAULT_DB_NAME_CACHE_TTL_MS;
  }

  /**
   * Resolves tenantId -> db_name (60s-cached) -> a live Kysely instance for
   * that DB, creating/evicting the underlying pool as needed. Typed `any`
   * for the same reason as getMasterDb() — see its doc comment.
   */
  async getTenantDb(tenantId: number): Promise<Kysely<any>> {
    const dbName = await this.resolveDbName(tenantId);
    return this.touchOrCreatePool(dbName).db;
  }

  /** Current number of live pools — test/observability hook. */
  size(): number {
    return this.pools.size;
  }

  has(dbName: string): boolean {
    return this.pools.has(dbName);
  }

  /** Clears the 60s db_name cache — test hook (mirrors evicting stale master.tenants reads). */
  clearDbNameCache(): void {
    this.dbNameCache.clear();
  }

  /** Ends every live pool. Shutdown hook — not used mid-request. */
  async closeAll(): Promise<void> {
    const entries = [...this.pools.values()];
    this.pools.clear();
    await Promise.all(
      entries.map((entry) => {
        clearTimeout(entry.evictTimer);
        return endRawPool(entry.pool);
      })
    );
  }

  private async resolveDbName(tenantId: number): Promise<string> {
    const now = Date.now();
    const cached = this.dbNameCache.get(tenantId);
    if (cached && cached.expiresAt > now) {
      return cached.dbName;
    }

    const master = getMasterDb();
    const result = await sql<{ db_name: string }>`
      SELECT db_name FROM tenants WHERE id = ${tenantId} LIMIT 1
    `.execute(master);
    const dbName = result.rows[0]?.db_name;
    if (!dbName) {
      throw new TenantNotFoundError(tenantId);
    }

    this.dbNameCache.set(tenantId, { dbName, expiresAt: now + this.dbNameCacheTtlMs });
    return dbName;
  }

  private touchOrCreatePool(dbName: string): PoolEntry {
    const existing = this.pools.get(dbName);
    if (existing) {
      // Move to MRU position + push the idle-evict deadline back out.
      this.pools.delete(dbName);
      this.pools.set(dbName, existing);
      this.rescheduleEvict(dbName, existing);
      return existing;
    }

    if (this.pools.size >= this.maxPools) {
      this.evictLru();
    }

    const entry = this.createTenantPoolEntry(dbName);
    this.pools.set(dbName, entry);
    return entry;
  }

  private createTenantPoolEntry(dbName: string): PoolEntry {
    const env = loadEnv();
    const pool = createPool({
      host: env.DB_HOST,
      user: env.DB_USER,
      password: env.DB_PASS,
      database: dbName,
      charset: 'utf8mb4_unicode_ci',
      connectionLimit: this.connectionLimit,
      waitForConnections: true,
      queueLimit: 0,
    });
    // Same session init as masterPool.ts — Asia/Bangkok on every new connection.
    pool.on('connection', (connection) => {
      connection.query("SET time_zone = '+07:00'", () => {});
    });
    const db = new Kysely<any>({ dialect: new MysqlDialect({ pool }) });
    return { db, pool, evictTimer: this.scheduleEvictTimer(dbName) };
  }

  private scheduleEvictTimer(dbName: string): ReturnType<typeof setTimeout> {
    const timer = setTimeout(() => {
      this.evict(dbName);
    }, this.idleEvictMs);
    // Never let a pool's idle timer keep the Node process alive on its own.
    timer.unref?.();
    return timer;
  }

  private rescheduleEvict(dbName: string, entry: PoolEntry): void {
    clearTimeout(entry.evictTimer);
    entry.evictTimer = this.scheduleEvictTimer(dbName);
  }

  private evictLru(): void {
    const oldestKey = this.pools.keys().next().value as string | undefined;
    if (oldestKey !== undefined) {
      this.evict(oldestKey);
    }
  }

  private evict(dbName: string): void {
    const entry = this.pools.get(dbName);
    if (!entry) {
      return;
    }
    this.pools.delete(dbName);
    clearTimeout(entry.evictTimer);
    void endRawPool(entry.pool);
  }
}

function endRawPool(pool: RawMysqlPool): Promise<void> {
  return new Promise((resolve) => {
    pool.end(() => resolve()); // Best-effort — eviction must never throw into caller code.
  });
}

/** Process-wide default registry — what application code should use day to day. */
export const tenantPoolRegistry = new TenantPoolRegistry();

export function getTenantDb(tenantId: number): Promise<Kysely<any>> {
  return tenantPoolRegistry.getTenantDb(tenantId);
}
