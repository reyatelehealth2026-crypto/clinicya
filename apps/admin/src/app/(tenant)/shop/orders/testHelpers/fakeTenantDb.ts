import { Kysely, MysqlDialect } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * fakeTenantDb.ts — a fake callback-style `mysql2` Pool, faithful enough for
 * Kysely's MysqlDialect to drive end-to-end without ever opening a real
 * socket: `pool.getConnection(cb) -> connection.query(sql, params, cb) ->
 * connection.release()`.
 *
 * STANDALONE, non-hoisted duplicate of users/testHelpers/fakeTenantDb.ts
 * (byte-for-byte, save for this doc comment) — this batch's brief
 * deliberately keeps shop/orders/** fully disjoint from users/**'s parallel
 * work in the same worktree/monorepo batch, so this is duplicated rather
 * than imported, matching users/testHelpers/fakeTenantDb.ts's own stated
 * rationale for duplicating packages/auth/tests/helpers/{fakeMysqlPool,
 * makeTestDb}.ts in the first place: test-only code, not worth a
 * cross-package/cross-page devDependency.
 *
 * This lets shop/orders/queries.ts, actions.ts, and _lib/activityLog.ts run
 * their REAL Kysely query-building code in tests (not a hand-rolled mock of
 * the fluent builder) while capturing the exact SQL text + bound params that
 * would hit MySQL — which is what this batch's brief ("assert exact SQL
 * text + bound params") actually needs.
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
