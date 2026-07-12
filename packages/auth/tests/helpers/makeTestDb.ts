import { Kysely, MysqlDialect } from 'kysely';
import { makeFakeMysqlPool, type QueryImpl } from './fakeMysqlPool';

/**
 * Builds a Kysely<T> instance directly on top of a fake mysql2 pool — for
 * modules that take a `db: Kysely<MasterDB>` / `Kysely<TenantDB>`
 * constructor/call parameter directly (sessionStore.ts, rbac.ts's
 * canAccessBot, impersonation.ts's writeSuperAdminAudit), this is simpler
 * than module-mocking 'mysql2' + '@reya/config' just to go through
 * getMasterDb()/getTenantDb().
 *
 * Generic, parameterized by the caller (e.g. `makeTestDb<MasterDB>(...)`,
 * `makeTestDb<TenantDB>(...)`) rather than `any` — every call site in this
 * package now targets @reya/db's real generated interface. Every query in
 * this package goes through the `sql\`\`` raw-SQL escape hatch (never
 * Kysely's typed query builder), so `T` only affects what `db` is *typed
 * as* for callers, not what shape the fake queryImpl has to return.
 */
export function makeTestDb<T = unknown>(database = 'test_db', queryImpl: QueryImpl = () => []) {
  const pool = makeFakeMysqlPool(database, queryImpl);
  const db = new Kysely<T>({ dialect: new MysqlDialect({ pool: pool as any }) });
  return { db, pool };
}
