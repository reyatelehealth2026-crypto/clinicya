import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { makeFakeMysqlPool, type FakeMysqlPool } from './helpers/fakeMysqlPool';

const { createPoolMock, fakePools } = vi.hoisted(() => {
  const pools: FakeMysqlPool[] = [];
  return { createPoolMock: vi.fn(), fakePools: pools };
});

vi.mock('mysql2', () => ({
  createPool: createPoolMock,
}));

vi.mock('@reya/config', () => ({
  loadEnv: vi.fn(() => ({
    NODE_ENV: 'test',
    DB_HOST: 'db-host.internal',
    DB_USER: 'reya_user',
    DB_PASS: 'reya_pass',
    REYA_BASE_DOMAIN: 're-ya.com',
    REDIS_URL: 'redis://redis:6379',
  })),
  PLATFORM_DB_NAME: 'zrismpsz_reya_platform',
}));

describe('masterPool', () => {
  beforeEach(() => {
    vi.resetModules();
    createPoolMock.mockReset();
    fakePools.length = 0;
    createPoolMock.mockImplementation((options: { database: string }) => {
      const pool = makeFakeMysqlPool(options.database);
      fakePools.push(pool);
      return pool;
    });
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it('creates the pool with master DB name, connection limit 5, and utf8mb4 charset — never touches a real connection', async () => {
    const { getMasterDb } = await import('../src/masterPool');
    getMasterDb();

    expect(createPoolMock).toHaveBeenCalledTimes(1);
    const [options] = createPoolMock.mock.calls[0]!;
    expect(options).toMatchObject({
      host: 'db-host.internal',
      user: 'reya_user',
      password: 'reya_pass',
      database: 'zrismpsz_reya_platform',
      charset: 'utf8mb4_unicode_ci',
      connectionLimit: 5,
      waitForConnections: true,
    });
  });

  it('is a singleton — repeated calls do not create a second pool', async () => {
    const { getMasterDb } = await import('../src/masterPool');
    const first = getMasterDb();
    const second = getMasterDb({ connectionLimit: 3 });

    expect(first).toBe(second);
    expect(createPoolMock).toHaveBeenCalledTimes(1);
  });

  it('accepts a connectionLimit override on first creation', async () => {
    const { getMasterDb } = await import('../src/masterPool');
    getMasterDb({ connectionLimit: 3 });

    const [options] = createPoolMock.mock.calls[0]!;
    expect(options).toMatchObject({ connectionLimit: 3 });
  });

  it('registers a "connection" handler that runs SET time_zone on every new physical connection', async () => {
    const { getMasterDb } = await import('../src/masterPool');
    getMasterDb();

    const pool = fakePools[0]!;
    expect(pool.on).toHaveBeenCalledWith('connection', expect.any(Function));

    const onConnectionHandler = pool.on.mock.calls[0]![1] as (conn: unknown) => void;
    const fakeConnection = { query: vi.fn((_sql: string, cb: (err: null) => void) => cb(null)) };
    onConnectionHandler(fakeConnection);

    expect(fakeConnection.query).toHaveBeenCalledWith("SET time_zone = '+07:00'", expect.any(Function));
  });

  it('returns a usable Kysely instance — a raw SELECT via sql`` round-trips through the fake pool', async () => {
    const { getMasterDb } = await import('../src/masterPool');
    const { sql } = await import('kysely');
    createPoolMock.mockImplementationOnce((options: { database: string }) => {
      const pool = makeFakeMysqlPool(options.database, () => [{ answer: 42 }]);
      fakePools.push(pool);
      return pool;
    });

    const db = getMasterDb();
    const result = await sql<{ answer: number }>`SELECT 42 AS answer`.execute(db);

    expect(result.rows).toEqual([{ answer: 42 }]);
    expect(fakePools[0]!.getConnection).toHaveBeenCalled();
  });

  it('resetMasterDb() ends the current pool and clears the singleton so the next getMasterDb() creates a new one', async () => {
    const { getMasterDb, resetMasterDb } = await import('../src/masterPool');
    const first = getMasterDb();

    await resetMasterDb();
    expect(fakePools[0]!.end).toHaveBeenCalledTimes(1);

    const second = getMasterDb();
    expect(second).not.toBe(first);
    expect(createPoolMock).toHaveBeenCalledTimes(2);
  });

  it('resetMasterDb() is a no-op when no pool has been created yet', async () => {
    const { resetMasterDb } = await import('../src/masterPool');
    await expect(resetMasterDb()).resolves.toBeUndefined();
    expect(createPoolMock).not.toHaveBeenCalled();
  });
});
