import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * withTenant.ts is a thin pass-through wrapper (getTenantDb(tenantId) ->
 * fn(db)) — unlike forEachActiveTenant.test.ts, there's no Kysely
 * query-generation behaviour worth proving here, so this mocks '@reya/db'
 * wholesale rather than going through a fake mysql2 pool.
 */
const getTenantDbMock = vi.fn();

vi.mock('@reya/db', () => ({
  getTenantDb: getTenantDbMock,
}));

beforeEach(() => {
  getTenantDbMock.mockReset();
});

describe('withTenant', () => {
  it('resolves the tenant db via getTenantDb(tenantId) and passes it to fn', async () => {
    const fakeDb = { marker: 'tenant-db-42' };
    getTenantDbMock.mockResolvedValue(fakeDb);

    const { withTenant } = await import('../src/tenant/withTenant');
    const fn = vi.fn(async (db: unknown) => {
      expect(db).toBe(fakeDb);
      return 'handler-result';
    });

    const result = await withTenant(42, fn);

    expect(getTenantDbMock).toHaveBeenCalledWith(42);
    expect(fn).toHaveBeenCalledWith(fakeDb);
    expect(result).toBe('handler-result');
  });

  it('propagates a rejection from getTenantDb without calling fn', async () => {
    getTenantDbMock.mockRejectedValue(new Error('tenant db unreachable'));

    const { withTenant } = await import('../src/tenant/withTenant');
    const fn = vi.fn(async () => 'should-not-run');

    await expect(withTenant(99, fn)).rejects.toThrow('tenant db unreachable');
    expect(fn).not.toHaveBeenCalled();
  });

  it('propagates a rejection thrown by fn itself', async () => {
    getTenantDbMock.mockResolvedValue({});

    const { withTenant } = await import('../src/tenant/withTenant');
    const fn = vi.fn(async () => {
      throw new Error('handler blew up');
    });

    await expect(withTenant(1, fn)).rejects.toThrow('handler blew up');
  });
});
