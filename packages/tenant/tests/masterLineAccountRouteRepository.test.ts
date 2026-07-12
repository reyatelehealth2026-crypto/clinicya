import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createMasterLineAccountRouteRepository } from '../src/masterLineAccountRouteRepository';
import { makeFakeMysqlPool, type FakeMysqlPool } from './helpers/fakeMysqlPool';

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

describe('createMasterLineAccountRouteRepository (mocked mysql2)', () => {
  it('queries tenant_line_account_routes filtering is_active=1, ordered by id asc', async () => {
    currentQueryImpl = () => [{ tenant_id: 42 }];

    const repo = createMasterLineAccountRouteRepository();
    const tenantId = await repo.findTenantIdByLineAccountId(5);

    expect(lastPool!.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/tenant_line_account_routes[\s\S]*is_active = 1[\s\S]*ORDER BY id ASC/),
      [5],
      expect.any(Function)
    );
    expect(tenantId).toBe(42);
    expect(createPoolMock).toHaveBeenCalled();
  });

  it('returns null when no active route matches', async () => {
    currentQueryImpl = () => [];
    const repo = createMasterLineAccountRouteRepository();
    expect(await repo.findTenantIdByLineAccountId(999)).toBeNull();
  });

  it('touchLastSeen issues the last_seen_at UPDATE', async () => {
    currentQueryImpl = () => ({ insertId: 0, affectedRows: 1, changedRows: 0 });
    const repo = createMasterLineAccountRouteRepository();

    await repo.touchLastSeen?.(5, 42);

    expect(lastPool!.connection.query).toHaveBeenCalledWith(
      expect.stringMatching(/UPDATE tenant_line_account_routes SET last_seen_at = NOW\(\)/),
      [5, 42],
      expect.any(Function)
    );
  });
});
