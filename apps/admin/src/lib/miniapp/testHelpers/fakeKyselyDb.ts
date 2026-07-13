import { Kysely, MysqlDialect } from 'kysely';

/**
 * fakeKyselyDb.ts — a fake callback-style `mysql2` Pool, faithful enough for
 * Kysely's MysqlDialect to drive end-to-end without ever opening a real
 * socket: `pool.getConnection(cb) -> connection.query(sql, params, cb) ->
 * connection.release()`.
 *
 * Generic over the Kysely row-map type so it backs BOTH `Kysely<TenantDB>`
 * (points-history/shop-products/health-profile routes) and `Kysely<MasterDB>`
 * (resolve-line-account, which is deliberately tenant-agnostic and talks to
 * the master DB directly). Same technique as
 * apps/admin/src/app/(tenant)/users/testHelpers/fakeTenantDb.ts (that one is
 * TenantDB-only) — duplicated deliberately rather than imported cross-route,
 * per this batch's allowed-paths boundary (this file lives under
 * apps/admin/src/lib/miniapp/**, which this agent owns).
 */

export interface RecordedQuery {
  sql: string;
  params: unknown[];
}

/**
 * `sqlDate('2026-01-15 10:22:03')` -> a real JS `Date` (via `new Date('2026-01-15T10:22:03')`), for
 * stubbing a fake row's DATE/DATETIME/TIMESTAMP column. mysql2 hydrates those columns as `Date` objects
 * by default (packages/db's pools configure no `dateStrings: true` anywhere — verified against
 * masterPool.ts/tenantPoolRegistry.ts), NOT as PHP PDO's raw `YYYY-MM-DD[ HH:MM:SS]` strings; a plain
 * literal string stub (`created_at: '2026-01-15 10:22:03'`) exercises a route handler's `string` branch
 * only and silently skips its `Date` branch, which is exactly the gap that let the mysql2-Date/PHP-string
 * serialization mismatch (contract drift on `birthday`/`registered_at`/`created_at`/`approved_at`, etc.
 * across member/rewards/wishlist) ship without a failing unit test. Prefer this helper over a literal
 * string whenever a test stubs a temporal column, so route-handler date-formatting bugs fail here instead
 * of only in the live api-parity harness. Accepts either a MySQL DATETIME string (`YYYY-MM-DD HH:MM:SS`)
 * or a DATE-only string (`YYYY-MM-DD`) — both parse fine via the `T`-separator swap.
 */
export function sqlDate(mysqlDateOrDateTime: string): Date {
  return new Date(mysqlDateOrDateTime.replace(' ', 'T'));
}

export type QueryImpl = (sqlText: string, params: unknown[]) => unknown;

export interface FakeKyselyDbHandle<DB> {
  db: Kysely<DB>;
  queries: RecordedQuery[];
  /** Swap in a new response-producing function mid-test (e.g. to answer a second, different query differently). */
  setQueryImpl: (impl: QueryImpl) => void;
}

/**
 * Builds a `Kysely<DB>` instance directly on top of a fake mysql2 pool.
 * `queryImpl` receives the compiled SQL text + bound params for every query
 * executed against the returned `db` and returns the rows Kysely should see
 * — default `() => []` (empty result set), override per test as needed (or
 * throw, to simulate a DB error). Every call is also appended to `queries`
 * so tests can assert on the exact SQL/params a branch produced.
 */
export function makeFakeKyselyDb<DB>(initialQueryImpl: QueryImpl = () => []): FakeKyselyDbHandle<DB> {
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
  const db = new Kysely<DB>({ dialect: new MysqlDialect({ pool: pool as any }) });

  return {
    db,
    queries,
    setQueryImpl: (impl: QueryImpl) => {
      queryImpl = impl;
    },
  };
}
