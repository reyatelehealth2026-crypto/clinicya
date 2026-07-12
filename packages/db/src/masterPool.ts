import { createPool, type Pool as RawMysqlPool, type PoolOptions } from 'mysql2';
import { Kysely, MysqlDialect } from 'kysely';
import { loadEnv, PLATFORM_DB_NAME } from '@reya/config';

/**
 * masterPool.ts — Kysely instance for the platform/master DB (zrismpsz_reya_platform).
 *
 * Replaces classes/TenantContext.php::getMasterPdo() + Database::platform().
 * Plan §1.2: "Kysely + mysql2 pool registry (ไม่ใช้ Prisma)" — Kysely wraps a
 * plain (callback-style) `mysql2` `Pool` via `MysqlDialect`; the raw pool is
 * kept around ourselves (not just handed to Kysely and forgotten) because
 * Kysely's driver only opens/inits it lazily on first query and its
 * `destroy()` is a no-op if no query ever ran — connection lifecycle
 * (creation, `SET time_zone`, shutdown) has to be deterministic regardless of
 * query traffic, so masterPool.ts manages the raw pool directly and Kysely
 * just rides on top of it for query building.
 *
 * Unlike PHP-FPM (one PDO per request, thrown away at request end), the Node
 * process is long-lived, so this is a true process-wide singleton rather than
 * a per-request connection — see plan §1.2.
 *
 * Every new physical connection gets `SET time_zone = '+07:00'` + utf8mb4,
 * mirroring modules/Core/Database.php's constructor exactly.
 */

export interface MasterPoolConfig {
  /** Default 5 — the platform DB is low-QPS relative to the tenant pool fan-out. */
  connectionLimit?: number;
}

let rawPool: RawMysqlPool | null = null;
let db: Kysely<any> | null = null;

function buildPoolOptions(config: MasterPoolConfig): PoolOptions {
  const env = loadEnv();
  return {
    host: env.DB_HOST,
    user: env.DB_USER,
    password: env.DB_PASS,
    database: PLATFORM_DB_NAME,
    charset: 'utf8mb4_unicode_ci',
    connectionLimit: config.connectionLimit ?? 5,
    waitForConnections: true,
    queueLimit: 0,
  };
}

function attachSessionInit(pool: RawMysqlPool): RawMysqlPool {
  // Mirrors `$this->connection->exec("SET time_zone = '+07:00'")` in
  // modules/Core/Database.php — run once per NEW physical connection, not per
  // query (mysql2 pools reuse connections across many logical queries).
  pool.on('connection', (connection) => {
    connection.query("SET time_zone = '+07:00'", () => {
      // Best-effort: a failed SET must not crash the pool. The connection is
      // still usable, just running in the server's default session tz until
      // it is recycled — matches the fail-safe posture of the PHP bootstrap.
    });
  });
  return pool;
}

/**
 * Returns the process-wide master DB Kysely instance, creating its
 * underlying pool on first call. Subsequent calls (even with a different
 * `config`) return the SAME instance — call resetMasterDb() first if you
 * need to reconfigure it (tests only).
 *
 * Typed `any` for now: no kysely-codegen output exists yet (no live DB to
 * introspect from this container). Once packages/db/scripts/codegen.sh has
 * been run for real, swap the return type for the generated `Database`
 * interface — every call site threads the type through, so it's a
 * one-line change here, not a call-site migration. See packages/db/README.md.
 */
export function getMasterDb(config: MasterPoolConfig = {}): Kysely<any> {
  if (db) {
    return db;
  }
  rawPool = attachSessionInit(createPool(buildPoolOptions(config)));
  db = new Kysely<any>({ dialect: new MysqlDialect({ pool: rawPool }) });
  return db;
}

/**
 * Test/shutdown hook — mirrors Database::resetAll() for the platform pool.
 * Ends the underlying raw pool directly (NOT via `db.destroy()` — see the
 * module doc comment for why: Kysely's destroy() no-ops if no query ever
 * ran through it) and clears the singleton so the next getMasterDb() call
 * creates a fresh one.
 */
export async function resetMasterDb(): Promise<void> {
  if (!rawPool) {
    return;
  }
  const poolToClose = rawPool;
  rawPool = null;
  db = null;
  await new Promise<void>((resolve) => {
    poolToClose.end(() => resolve()); // Ending an already-broken pool shouldn't throw during teardown.
  });
}
