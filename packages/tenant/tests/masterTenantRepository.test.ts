import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMasterTenantRepository } from '../src/masterTenantRepository';
import { makeFakeMysqlPool, type FakeMysqlPool } from './helpers/fakeMysqlPool';

// This exercises the DEFAULT repository implementation, which goes through
// @reya/db's getMasterDb() -> Kysely -> mysql2 createPool(). Mocking mysql2
// here (rather than just injecting a fake TenantRepository) proves the
// wiring down to the SQL text never touches a real connection.
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

beforeEach(async () => {
  const { resetMasterDb } = await import('@reya/db');
  await resetMasterDb();
  createPoolMock.mockReset();
  currentQueryImpl = () => [];
  createPoolMock.mockImplementation((options: { database: string }) => {
    const pool = makeFakeMysqlPool(options.database, (sqlText, params) => currentQueryImpl(sqlText, params));
    lastPool = pool;
    return pool;
  });
});

describe('createMasterTenantRepository (mocked mysql2)', () => {
  it('queries master.tenants by slug with the exact PHP-equivalent SQL', async () => {
    currentQueryImpl = () => [{ id: 1, status: 'active', display_name: 'Demo Pharmacy' }];

    const repo = createMasterTenantRepository();
    const row = await repo.findBySlug('demo-shop');

    expect(lastPool!.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/SELECT id, status, display_name FROM tenants WHERE slug = \?/),
      ['demo-shop'],
      expect.any(Function)
    );
    expect(row).toEqual({ id: 1, status: 'active', displayName: 'Demo Pharmacy' });
    expect(createPoolMock).toHaveBeenCalled(); // never a real connection — always the mocked factory
  });

  it('returns null when no row matches', async () => {
    currentQueryImpl = () => [];
    const repo = createMasterTenantRepository();
    expect(await repo.findBySlug('ghost')).toBeNull();
  });
});
