import { beforeEach, describe, expect, it, vi } from 'vitest';
import { makeFakeMysqlPool, type FakeMysqlPool } from './helpers/fakeMysqlPool';

/**
 * Mocks 'mysql2' + '@reya/config' exactly like
 * packages/tenant/tests/masterTenantRepository.test.ts and
 * packages/db/tests/tenantPoolRegistry.test.ts do, so @reya/db's real
 * source (aliased in vitest.config.ts) drives a genuine Kysely query-build
 * pass through a fake mysql2 pool — never a real socket.
 */
let currentQueryImpl: (sqlText: string, params: unknown[]) => unknown = () => [];
let lastPool: FakeMysqlPool | undefined;

const { createPoolMock } = vi.hoisted(() => ({ createPoolMock: vi.fn() }));

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

const MIXED_TENANT_FIXTURE = [
  { id: 1, status: 'active', display_name: 'Active Pharmacy One' },
  { id: 2, status: 'suspended', display_name: 'Suspended Pharmacy' },
  { id: 3, status: 'terminated', display_name: 'Terminated Pharmacy' },
  { id: 4, status: 'active', display_name: 'Active Pharmacy Two' },
  { id: 5, status: 'pending_setup', display_name: 'Pending Setup Pharmacy' },
];

beforeEach(async () => {
  vi.resetModules();
  const { resetMasterDb } = await import('@reya/db');
  await resetMasterDb();
  createPoolMock.mockReset();
  lastPool = undefined;
  currentQueryImpl = () => [];
  createPoolMock.mockImplementation((options: { database: string }) => {
    const pool = makeFakeMysqlPool(options.database, (sqlText, params) => currentQueryImpl(sqlText, params));
    lastPool = pool;
    return pool;
  });
});

describe('forEachActiveTenant', () => {
  it('queries master.tenants filtered WHERE status = \'active\' (DB-side filter, not post-filtering)', async () => {
    currentQueryImpl = () => MIXED_TENANT_FIXTURE.filter((row) => row.status === 'active');

    const { forEachActiveTenant } = await import('../src/tenant/forEachActiveTenant');
    const visited: Array<{ id: number; status: string; displayName: string }> = [];
    await forEachActiveTenant(async (tenant) => {
      visited.push(tenant);
    });

    expect(lastPool!.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/SELECT id, status, display_name FROM tenants WHERE status = 'active'/),
      [],
      expect.any(Function)
    );
    expect(visited).toHaveLength(2);
    expect(visited.map((t) => t.id).sort()).toEqual([1, 4]);
  });

  it('excludes suspended/terminated/pending_setup tenants from a mixed fixture', async () => {
    // Simulates real DB-side filtering: only rows already matching
    // `status = 'active'` are ever returned by the mock, mirroring what a
    // real MySQL WHERE clause would do.
    currentQueryImpl = () => MIXED_TENANT_FIXTURE.filter((row) => row.status === 'active');

    const { forEachActiveTenant } = await import('../src/tenant/forEachActiveTenant');
    const visitedIds: number[] = [];
    await forEachActiveTenant(async (tenant) => {
      visitedIds.push(tenant.id);
    });

    expect(visitedIds).not.toContain(2); // suspended
    expect(visitedIds).not.toContain(3); // terminated
    expect(visitedIds).not.toContain(5); // pending_setup
    expect(visitedIds.sort()).toEqual([1, 4]);
  });

  it('invokes the callback once per active tenant with id/status/displayName mapped from snake_case columns', async () => {
    currentQueryImpl = () => [{ id: 7, status: 'active', display_name: 'Snake Case Pharmacy' }];

    const { forEachActiveTenant } = await import('../src/tenant/forEachActiveTenant');
    const visited: unknown[] = [];
    await forEachActiveTenant(async (tenant) => {
      visited.push(tenant);
    });

    expect(visited).toEqual([{ id: 7, status: 'active', displayName: 'Snake Case Pharmacy' }]);
  });

  it('calls the callback zero times when no tenant is active', async () => {
    currentQueryImpl = () => [];

    const { forEachActiveTenant } = await import('../src/tenant/forEachActiveTenant');
    const callback = vi.fn(async () => {});
    await forEachActiveTenant(callback);

    expect(callback).not.toHaveBeenCalled();
  });
});
