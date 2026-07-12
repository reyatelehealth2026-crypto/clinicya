import { vi } from 'vitest';

/**
 * A fake callback-style `mysql2` Pool, faithful enough for Kysely's
 * MysqlDialect to drive end-to-end without ever opening a real socket:
 *   pool.getConnection(cb) -> connection.query(sql, params, cb) -> connection.release()
 *
 * Duplicated from packages/db/tests/helpers/fakeMysqlPool.ts (kept package-local
 * on purpose — this is test-only code, not worth a shared devDependency).
 */
export interface FakeMysqlConnection {
  query: ReturnType<typeof vi.fn>;
  release: ReturnType<typeof vi.fn>;
}

export interface FakeMysqlPool {
  database: string;
  getConnection: ReturnType<typeof vi.fn>;
  end: ReturnType<typeof vi.fn>;
  on: ReturnType<typeof vi.fn>;
  connection: FakeMysqlConnection;
}

export type QueryImpl = (sqlText: string, params: unknown[]) => unknown;

export function makeFakeMysqlPool(database: string, queryImpl: QueryImpl = () => []): FakeMysqlPool {
  const connection: FakeMysqlConnection = {
    query: vi.fn(
      (sqlText: string, params: unknown[], callback: (err: unknown, result?: unknown) => void) => {
        try {
          callback(null, queryImpl(sqlText, params));
        } catch (err) {
          callback(err);
        }
      }
    ),
    release: vi.fn(),
  };

  return {
    database,
    getConnection: vi.fn((callback: (err: unknown, connection?: FakeMysqlConnection) => void) => {
      callback(null, connection);
    }),
    end: vi.fn((callback?: (err?: unknown) => void) => callback?.()),
    on: vi.fn(),
    connection,
  };
}
