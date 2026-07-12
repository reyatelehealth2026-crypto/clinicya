import { Kysely, MysqlDialect } from 'kysely';
import { makeFakeMysqlPool, type QueryImpl } from './fakeMysqlPool';

/**
 * Builds a Kysely<any> instance directly on top of a fake mysql2 pool — for
 * modules that take a `db: Kysely<any>` constructor/call parameter directly
 * (sessionStore.ts, rbac.ts's canAccessBot, impersonation.ts's
 * writeSuperAdminAudit), this is simpler than module-mocking 'mysql2' +
 * '@reya/config' just to go through getMasterDb()/getTenantDb().
 */
export function makeTestDb(database = 'test_db', queryImpl: QueryImpl = () => []) {
  const pool = makeFakeMysqlPool(database, queryImpl);
  const db = new Kysely<any>({ dialect: new MysqlDialect({ pool: pool as any }) });
  return { db, pool };
}
