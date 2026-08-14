import { vi } from 'vitest';
import { Kysely, MysqlDialect } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * fakeTenantDb.ts — a fake callback-style `mysql2` Pool, faithful enough for
 * Kysely's MysqlDialect (incl. `.transaction()`, which issues `begin`/
 * `commit`/`rollback` as raw queries through the same connection — see
 * kysely's mysql-driver.js) to drive end-to-end without ever opening a real
 * socket: `pool.getConnection(cb) -> connection.query(sql, params, cb) ->
 * connection.release()`.
 *
 * Same technique as packages/db/tests/helpers/fakeMysqlPool.ts and
 * apps/admin's various `_lib/testHelpers/fakeTenantDb.ts` copies —
 * duplicated deliberately per this codebase's established convention
 * (packages/{db,auth,tenant} each keep their own copy; see those files'
 * doc comments) rather than imported cross-package.
 */

export interface RecordedQuery {
  sql: string;
  params: unknown[];
}

export type QueryImpl = (sqlText: string, params: unknown[]) => unknown;

export interface FakeTenantDbHandle {
  db: Kysely<TenantDB>;
  queries: RecordedQuery[];
  setQueryImpl: (impl: QueryImpl) => void;
}

export function makeFakeTenantDb(initialQueryImpl: QueryImpl = () => []): FakeTenantDbHandle {
  const queries: RecordedQuery[] = [];
  let queryImpl = initialQueryImpl;

  interface FakeConnection {
    query: (sqlText: string, params: unknown[], callback: (err: unknown, result?: unknown) => void) => void;
    release: () => void;
  }

  const connection: FakeConnection = {
    query: vi.fn((sqlText: string, params: unknown[], callback: (err: unknown, result?: unknown) => void) => {
      queries.push({ sql: sqlText, params });
      try {
        callback(null, queryImpl(sqlText, params));
      } catch (err) {
        callback(err);
      }
    }),
    release: vi.fn(),
  };

  const pool = {
    getConnection: vi.fn((callback: (err: unknown, connection?: FakeConnection) => void) => {
      callback(null, connection);
    }),
    end: vi.fn((callback?: (err?: unknown) => void) => callback?.()),
    on: vi.fn(),
  };

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const db = new Kysely<TenantDB>({ dialect: new MysqlDialect({ pool: pool as any }) });

  return {
    db,
    queries,
    setQueryImpl: (impl: QueryImpl) => {
      queryImpl = impl;
    },
  };
}
