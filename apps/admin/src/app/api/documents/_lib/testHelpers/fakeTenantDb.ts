import { Kysely, MysqlDialect } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * fakeTenantDb.ts — a fake callback-style `mysql2` Pool, faithful enough for
 * Kysely's MysqlDialect (incl. `.transaction()`, which issues `begin`/
 * `commit`/`rollback` as raw queries through the same connection) to drive
 * end-to-end without ever opening a real socket: `pool.getConnection(cb) ->
 * connection.query(sql, params, cb) -> connection.release()`.
 *
 * Local copy of api/inbox/actions/notes/_lib/testHelpers/fakeTenantDb.ts —
 * duplicated deliberately per this codebase's established convention (see
 * that file's own doc comment: packages/{db,auth,tenant} each keep their
 * own copy too). Test-only code, not worth a cross-feature import — also
 * keeps apps/admin/src/app/api/documents/** fully disjoint from
 * apps/admin/src/app/api/inbox/** per this batch's ownership boundary.
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
    query: jest.fn((sqlText: string, params: unknown[], callback: (err: unknown, result?: unknown) => void) => {
      queries.push({ sql: sqlText, params });
      try {
        callback(null, queryImpl(sqlText, params));
      } catch (err) {
        callback(err);
      }
    }),
    release: jest.fn(),
  };

  const pool = {
    getConnection: jest.fn((callback: (err: unknown, connection?: FakeConnection) => void) => {
      callback(null, connection);
    }),
    end: jest.fn((callback?: (err?: unknown) => void) => callback?.()),
    on: jest.fn(),
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
