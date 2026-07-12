import { Kysely, MysqlDialect } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * fakeTenantDb.ts — a fake callback-style `mysql2` Pool, faithful enough for
 * Kysely's MysqlDialect to drive end-to-end without ever opening a real
 * socket: `pool.getConnection(cb) -> connection.query(sql, params, cb) ->
 * connection.release()`.
 *
 * Jest port of packages/auth/tests/helpers/{fakeMysqlPool,makeTestDb}.ts
 * (that pair is Vitest-based — `vi.fn()` — and lives in a package this
 * batch doesn't own). Duplicated deliberately, same rationale those files
 * give for their own duplication across packages/{db,auth,tenant}: test-only
 * code, not worth a cross-package devDependency. This lets
 * users/queries.ts and user-detail/queries.ts run their REAL Kysely
 * query-building code in tests (not a hand-rolled mock of the fluent
 * builder) while capturing the exact SQL text + bound params that would hit
 * MySQL, which is what the "every filter branch" test coverage this batch's
 * brief asks for actually needs.
 */

export interface RecordedQuery {
  sql: string;
  params: unknown[];
}

export type QueryImpl = (sqlText: string, params: unknown[]) => unknown;

export interface FakeTenantDbHandle {
  db: Kysely<TenantDB>;
  queries: RecordedQuery[];
  /** Swap in a new response-producing function mid-test (e.g. to answer a second, different query differently). */
  setQueryImpl: (impl: QueryImpl) => void;
}

/**
 * Builds a Kysely<TenantDB> instance directly on top of a fake mysql2 pool.
 * `queryImpl` receives the compiled SQL text + bound params for every query
 * executed against the returned `db` and returns the rows Kysely should see
 * — default `() => []` (empty result set), override per test as needed.
 * Every call is also appended to `queries` so tests can assert on the exact
 * SQL/params a filter branch produced.
 */
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
