/**
 * @jest-environment node
 */
import type { MasterDB, TenantDB } from '@reya/db';
import { makeFakeKyselyDb } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { resolveLineAccountByLiffId } from './lookup';

const LIFF_ID = '2008477880-wmRN2Aln';

describe('resolveLineAccountByLiffId', () => {
  it('invalid liff_id (empty) -> invalid_liff_id, no DB touched at all', async () => {
    const { db: master, queries } = makeFakeKyselyDb<MasterDB>();
    const getTenantDb = jest.fn();

    const result = await resolveLineAccountByLiffId('', { master, getTenantDb });

    expect(result).toEqual({ success: false, error: 'invalid_liff_id' });
    expect(queries).toHaveLength(0);
    expect(getTenantDb).not.toHaveBeenCalled();
  });

  it('invalid liff_id (bad characters) -> invalid_liff_id', async () => {
    const { db: master } = makeFakeKyselyDb<MasterDB>();
    const result = await resolveLineAccountByLiffId('bad;chars!', { master, getTenantDb: jest.fn() });
    expect(result).toEqual({ success: false, error: 'invalid_liff_id' });
  });

  it('master connectivity check fails -> platform_unavailable, nothing else attempted', async () => {
    const { db: master } = makeFakeKyselyDb<MasterDB>(() => {
      throw new Error('ECONNREFUSED');
    });

    const result = await resolveLineAccountByLiffId(LIFF_ID, { master, getTenantDb: jest.fn() });

    expect(result).toEqual({ success: false, error: 'platform_unavailable' });
  });

  it('fast path: route row found -> success, tenant scan never attempted', async () => {
    const { db: master } = makeFakeKyselyDb<MasterDB>((sqlText) => {
      if (sqlText.includes('SELECT 1 AS ok')) return [{ ok: 1 }];
      if (sqlText.includes('FROM tenant_line_account_routes')) {
        return [{ line_account_id: 12, tenant_id: 3 }];
      }
      if (sqlText.includes('SELECT slug FROM tenants')) return [{ slug: 'tenant-0003' }];
      return [];
    });
    const getTenantDb = jest.fn();

    const result = await resolveLineAccountByLiffId(LIFF_ID, { master, getTenantDb });

    expect(result).toEqual({ success: true, line_account_id: 12, tenant_id: 3, tenant_slug: 'tenant-0003' });
    expect(getTenantDb).not.toHaveBeenCalled();
  });

  it('fast path query throws (e.g. liff_id column missing pre-migration) -> falls through to the scan, NOT platform_unavailable', async () => {
    const { db: master } = makeFakeKyselyDb<MasterDB>((sqlText) => {
      if (sqlText.includes('SELECT 1 AS ok')) return [{ ok: 1 }];
      if (sqlText.includes('FROM tenant_line_account_routes')) {
        throw new Error("Unknown column 'liff_id'");
      }
      if (sqlText.includes('FROM tenants')) {
        return [{ id: 5, slug: 'tenant-0005', db_name: 'reya_tenant_0005' }];
      }
      return [];
    });
    const { db: tenantDb } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('FROM line_accounts')) return [{ id: 9 }];
      return [];
    });
    const getTenantDb = jest.fn().mockResolvedValue(tenantDb);

    const result = await resolveLineAccountByLiffId(LIFF_ID, { master, getTenantDb });

    expect(result).toEqual({ success: true, line_account_id: 9, tenant_id: 5, tenant_slug: 'tenant-0005' });
  });

  it('scan: finds the liff_id on the second tenant (first has no match), backfills the route row', async () => {
    const { db: master, queries: masterQueries } = makeFakeKyselyDb<MasterDB>((sqlText) => {
      if (sqlText.includes('SELECT 1 AS ok')) return [{ ok: 1 }];
      if (sqlText.includes('FROM tenant_line_account_routes')) return []; // no fast-path match
      if (sqlText.includes('FROM tenants')) {
        return [
          { id: 1, slug: 'tenant-0001', db_name: 'reya_tenant_0001' },
          { id: 2, slug: 'tenant-0002', db_name: 'reya_tenant_0002' },
        ];
      }
      return [];
    });
    const { db: tenant1Db } = makeFakeKyselyDb<TenantDB>(() => []); // no match
    const { db: tenant2Db } = makeFakeKyselyDb<TenantDB>((sqlText) =>
      sqlText.includes('FROM line_accounts') ? [{ id: 44 }] : []
    );
    const getTenantDb = jest.fn().mockImplementation(async (tenantId: number) => {
      return tenantId === 1 ? tenant1Db : tenant2Db;
    });

    const result = await resolveLineAccountByLiffId(LIFF_ID, { master, getTenantDb });

    expect(result).toEqual({ success: true, line_account_id: 44, tenant_id: 2, tenant_slug: 'tenant-0002' });
    // Backfill UPDATE was issued against master with the matched tenant/account.
    expect(masterQueries.some((q) => q.sql.includes('UPDATE tenant_line_account_routes'))).toBe(true);
  });

  it('scan: a tenant DB that throws is skipped, scan continues to the next tenant', async () => {
    const { db: master } = makeFakeKyselyDb<MasterDB>((sqlText) => {
      if (sqlText.includes('SELECT 1 AS ok')) return [{ ok: 1 }];
      if (sqlText.includes('FROM tenant_line_account_routes')) return [];
      if (sqlText.includes('FROM tenants')) {
        return [
          { id: 1, slug: 'tenant-0001', db_name: 'reya_tenant_0001' },
          { id: 2, slug: 'tenant-0002', db_name: 'reya_tenant_0002' },
        ];
      }
      return [];
    });
    const { db: tenant2Db } = makeFakeKyselyDb<TenantDB>((sqlText) =>
      sqlText.includes('FROM line_accounts') ? [{ id: 7 }] : []
    );
    const getTenantDb = jest.fn().mockImplementation(async (tenantId: number) => {
      if (tenantId === 1) throw new Error('tenant DB unreachable');
      return tenant2Db;
    });

    const result = await resolveLineAccountByLiffId(LIFF_ID, { master, getTenantDb });

    expect(result).toEqual({ success: true, line_account_id: 7, tenant_id: 2, tenant_slug: 'tenant-0002' });
  });

  it('a tenant row with an empty db_name is skipped without calling getTenantDb', async () => {
    const { db: master } = makeFakeKyselyDb<MasterDB>((sqlText) => {
      if (sqlText.includes('SELECT 1 AS ok')) return [{ ok: 1 }];
      if (sqlText.includes('FROM tenant_line_account_routes')) return [];
      if (sqlText.includes('FROM tenants')) return [{ id: 1, slug: 'tenant-0001', db_name: '' }];
      return [];
    });
    const getTenantDb = jest.fn();

    const result = await resolveLineAccountByLiffId(LIFF_ID, { master, getTenantDb });

    expect(result).toEqual({ success: false, error: 'not_found' });
    expect(getTenantDb).not.toHaveBeenCalled();
  });

  it('no tenant matches -> not_found', async () => {
    const { db: master } = makeFakeKyselyDb<MasterDB>((sqlText) => {
      if (sqlText.includes('SELECT 1 AS ok')) return [{ ok: 1 }];
      if (sqlText.includes('FROM tenant_line_account_routes')) return [];
      if (sqlText.includes('FROM tenants')) {
        return [{ id: 1, slug: 'tenant-0001', db_name: 'reya_tenant_0001' }];
      }
      return [];
    });
    const { db: tenantDb } = makeFakeKyselyDb<TenantDB>(() => []);
    const getTenantDb = jest.fn().mockResolvedValue(tenantDb);

    const result = await resolveLineAccountByLiffId(LIFF_ID, { master, getTenantDb });

    expect(result).toEqual({ success: false, error: 'not_found' });
  });

  it('the tenants-list query itself throwing degrades to not_found (NOT platform_unavailable — mirrors PHP\'s catch(\\Throwable){$tenants=[];})', async () => {
    const { db: master } = makeFakeKyselyDb<MasterDB>((sqlText) => {
      if (sqlText.includes('SELECT 1 AS ok')) return [{ ok: 1 }];
      if (sqlText.includes('FROM tenant_line_account_routes')) return [];
      if (sqlText.includes('FROM tenants')) throw new Error('tenants table unavailable');
      return [];
    });

    const result = await resolveLineAccountByLiffId(LIFF_ID, { master, getTenantDb: jest.fn() });

    expect(result).toEqual({ success: false, error: 'not_found' });
  });
});
