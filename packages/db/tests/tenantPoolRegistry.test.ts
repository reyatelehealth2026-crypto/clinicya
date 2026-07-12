import { beforeEach, describe, expect, it, vi } from 'vitest';
import { makeFakeMysqlPool, type FakeMysqlPool } from './helpers/fakeMysqlPool';

const PLATFORM_DB_NAME = 'zrismpsz_reya_platform';

const { createPoolMock, masterPoolRef, tenantPoolsCreated } = vi.hoisted(() => {
  const ref: { current: any } = { current: null };
  const created: any[] = [];
  const createPool = vi.fn();
  return { createPoolMock: createPool, masterPoolRef: ref, tenantPoolsCreated: created };
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
  PLATFORM_DB_NAME,
}));

const TENANT_DB_NAMES: Record<number, string> = {
  1: 'reya_tenant_0001',
  2: 'reya_tenant_0002',
  3: 'reya_tenant_0003',
};

/**
 * Wires createPool so the master DB name gets a queryable fake pool that
 * answers `SELECT db_name FROM tenants WHERE id = ?` from TENANT_DB_NAMES,
 * and every other (tenant) database gets a fresh, tracked fake pool.
 */
async function setUpMasterLookups(): Promise<FakeMysqlPool> {
  createPoolMock.mockImplementation((options: { database: string }) => {
    if (options.database === PLATFORM_DB_NAME) {
      const pool = makeFakeMysqlPool(options.database, (_sqlText, params) => {
        const tenantId = params[0] as number;
        const dbName = TENANT_DB_NAMES[tenantId];
        return dbName ? [{ db_name: dbName }] : [];
      });
      masterPoolRef.current = pool;
      return pool;
    }
    const pool = makeFakeMysqlPool(options.database);
    tenantPoolsCreated.push(pool);
    return pool;
  });

  const { getMasterDb } = await import('../src/masterPool');
  getMasterDb();
  return masterPoolRef.current as FakeMysqlPool;
}

beforeEach(() => {
  vi.resetModules();
  vi.useRealTimers();
  createPoolMock.mockReset();
  masterPoolRef.current = null;
  tenantPoolsCreated.length = 0;
});

describe('TenantPoolRegistry — never touches a real mysql2/TCP connection', () => {
  it('only ever calls the mocked mysql2 createPool — no real connection attempted', async () => {
    const master = await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry();

    await registry.getTenantDb(1);

    expect(createPoolMock).toHaveBeenCalled();
    expect(master.getConnection).toHaveBeenCalled();
  });
});

describe('TenantPoolRegistry — db_name resolution (60s cache)', () => {
  it('resolves db_name from master.tenants and creates a Kysely-wrapped pool for it', async () => {
    await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry();

    const db = await registry.getTenantDb(1);

    expect(db).toBeDefined();
    expect(tenantPoolsCreated[0]!.database).toBe('reya_tenant_0001');
  });

  it('caches db_name for dbNameCacheTtlMs — repeated getTenantDb() does not re-query master', async () => {
    vi.useFakeTimers();
    const master = await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry({ dbNameCacheTtlMs: 60_000 });

    await registry.getTenantDb(1);
    await registry.getTenantDb(1);
    expect(master.connection.query).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(60_001);
    await registry.getTenantDb(1);
    expect(master.connection.query).toHaveBeenCalledTimes(2);

    vi.useRealTimers();
  });

  it('throws TenantNotFoundError when master.tenants has no matching row', async () => {
    await setUpMasterLookups();
    const { TenantPoolRegistry, TenantNotFoundError } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry();

    await expect(registry.getTenantDb(999)).rejects.toThrow(TenantNotFoundError);
    expect(registry.size()).toBe(0);
  });
});

describe('TenantPoolRegistry — connectionLimit pass-through (plan §1.2: 3-5 per pool)', () => {
  it('defaults connectionLimit to 5 and passes it into the mysql2 pool config', async () => {
    await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry();

    await registry.getTenantDb(1);

    const tenantPoolCall = createPoolMock.mock.calls.find(([opts]) => opts.database === 'reya_tenant_0001')!;
    expect(tenantPoolCall[0]).toMatchObject({
      connectionLimit: 5,
      charset: 'utf8mb4_unicode_ci',
      waitForConnections: true,
      host: 'db-host.internal',
      user: 'reya_user',
      password: 'reya_pass',
    });
  });

  it('passes a custom connectionLimit (3-5) straight through', async () => {
    await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry({ connectionLimit: 3 });

    await registry.getTenantDb(1);

    const tenantPoolCall = createPoolMock.mock.calls.find(([opts]) => opts.database === 'reya_tenant_0001')!;
    expect(tenantPoolCall[0]).toMatchObject({ connectionLimit: 3 });
  });

  it.each([1, 2, 6, 10, -1, 4.5])('rejects a connectionLimit of %s (outside 3-5 or non-integer)', async (value) => {
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    expect(() => new TenantPoolRegistry({ connectionLimit: value })).toThrow(RangeError);
  });

  it('registers a "connection" handler that runs SET time_zone on every new physical connection', async () => {
    await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry();

    await registry.getTenantDb(1);

    const pool = tenantPoolsCreated[0]!;
    expect(pool.on).toHaveBeenCalledWith('connection', expect.any(Function));
    const handler = pool.on.mock.calls[0]![1] as (conn: unknown) => void;
    const fakeConnection = { query: vi.fn((_sql: string, cb: (err: null) => void) => cb(null)) };
    handler(fakeConnection);
    expect(fakeConnection.query).toHaveBeenCalledWith("SET time_zone = '+07:00'", expect.any(Function));
  });
});

describe('TenantPoolRegistry — LRU eviction at capacity', () => {
  it('evicts the least-recently-used pool once maxPools is exceeded', async () => {
    await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry({ maxPools: 2 });

    await registry.getTenantDb(1); // A
    await registry.getTenantDb(2); // B
    expect(registry.size()).toBe(2);

    await registry.getTenantDb(3); // over capacity -> evict LRU (A)

    expect(registry.size()).toBe(2);
    expect(registry.has('reya_tenant_0001')).toBe(false);
    expect(registry.has('reya_tenant_0002')).toBe(true);
    expect(registry.has('reya_tenant_0003')).toBe(true);
    expect(tenantPoolsCreated[0]!.end).toHaveBeenCalledTimes(1); // pool A ended
  });

  it('touching a pool moves it to MRU, protecting it from the next eviction', async () => {
    await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry({ maxPools: 2 });

    await registry.getTenantDb(1); // A created (LRU)
    await registry.getTenantDb(2); // B created (MRU)
    await registry.getTenantDb(1); // touch A -> A is now MRU, B is now LRU

    await registry.getTenantDb(3); // over capacity -> evict LRU (B, not A)

    expect(registry.has('reya_tenant_0001')).toBe(true);
    expect(registry.has('reya_tenant_0002')).toBe(false);
    expect(registry.has('reya_tenant_0003')).toBe(true);
  });

  it('re-creates a pool on the next access after it has been evicted', async () => {
    await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry({ maxPools: 1 });

    await registry.getTenantDb(1);
    await registry.getTenantDb(2); // evicts tenant 1's pool
    expect(registry.has('reya_tenant_0001')).toBe(false);

    const poolsBefore = tenantPoolsCreated.length;
    await registry.getTenantDb(1);
    expect(tenantPoolsCreated.length).toBe(poolsBefore + 1); // brand-new pool, not reused
  });
});

describe('TenantPoolRegistry — idle eviction (plan §1.2: 10 minutes)', () => {
  it('evicts a pool after idleEvictMs with no access', async () => {
    vi.useFakeTimers();
    await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry({ idleEvictMs: 5_000 });

    await registry.getTenantDb(1);
    expect(registry.size()).toBe(1);
    const pool = tenantPoolsCreated[0]!;

    await vi.advanceTimersByTimeAsync(4_999);
    expect(registry.size()).toBe(1);
    expect(pool.end).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(2);
    expect(registry.size()).toBe(0);
    expect(pool.end).toHaveBeenCalledTimes(1);

    await registry.getTenantDb(1);
    expect(tenantPoolsCreated.length).toBe(2); // brand-new pool after eviction

    vi.useRealTimers();
  });

  it('touching a pool before the deadline resets the idle timer', async () => {
    vi.useFakeTimers();
    await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry({ idleEvictMs: 5_000 });

    await registry.getTenantDb(1);
    await vi.advanceTimersByTimeAsync(4_000);
    await registry.getTenantDb(1); // touch resets the 5s deadline
    await vi.advanceTimersByTimeAsync(4_000); // total 8s since creation, but only 4s since the touch

    expect(registry.size()).toBe(1);
    expect(tenantPoolsCreated[0]!.end).not.toHaveBeenCalled();

    vi.useRealTimers();
  });
});

describe('TenantPoolRegistry — closeAll()', () => {
  it('ends every live pool and clears the registry', async () => {
    await setUpMasterLookups();
    const { TenantPoolRegistry } = await import('../src/tenantPoolRegistry');
    const registry = new TenantPoolRegistry();

    await registry.getTenantDb(1);
    await registry.getTenantDb(2);
    await registry.closeAll();

    expect(registry.size()).toBe(0);
    expect(tenantPoolsCreated[0]!.end).toHaveBeenCalledTimes(1);
    expect(tenantPoolsCreated[1]!.end).toHaveBeenCalledTimes(1);
  });
});
