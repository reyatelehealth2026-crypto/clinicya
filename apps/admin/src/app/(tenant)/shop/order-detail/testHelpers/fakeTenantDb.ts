import { Kysely, MysqlDialect } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * fakeTenantDb.ts — standalone duplicate of
 * `(tenant)/users/testHelpers/fakeTenantDb.ts` for `(tenant)/shop/order-detail`.
 * Same rationale as that file's own doc comment (and the rest of this
 * codebase's per-feature copies): test-only code, not worth a cross-directory
 * import that would violate this batch's "orderDetail and ordersList stay
 * fully disjoint" boundary.
 *
 * A fake callback-style `mysql2` Pool, faithful enough for Kysely's
 * MysqlDialect to drive end-to-end without ever opening a real socket:
 * `pool.getConnection(cb) -> connection.query(sql, params, cb) ->
 * connection.release()`.
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
 * SQL/params a branch produced (e.g. the tenant-guard enumeration test).
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
