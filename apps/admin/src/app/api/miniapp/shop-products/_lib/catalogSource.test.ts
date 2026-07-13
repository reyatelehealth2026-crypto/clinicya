/**
 * @jest-environment node
 */
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { useShopProductCatalog } from './catalogSource';

describe('useShopProductCatalog', () => {
  it('lineAccountId <= 0 -> false, no query issued', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>();
    expect(await useShopProductCatalog(db, 0)).toBe(false);
    expect(queries).toHaveLength(0);
  });

  it('scoped shop_settings row has order_data_source=odoo -> true', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('line_account_id = ')) return [{ order_data_source: 'odoo' }];
      return [];
    });
    expect(await useShopProductCatalog(db, 1)).toBe(true);
  });

  it('scoped row empty -> falls back to the unscoped default row', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('WHERE line_account_id =')) return [];
      if (sqlText.includes('id = 1 OR line_account_id IS NULL')) return [{ order_data_source: 'odoo' }];
      return [];
    });
    expect(await useShopProductCatalog(db, 1)).toBe(true);
  });

  it('order_data_source is anything other than "odoo" -> false (default shop)', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => [{ order_data_source: 'shop' }]);
    expect(await useShopProductCatalog(db, 1)).toBe(false);
  });

  it('a thrown query error -> false (matches PHP catch -> "shop")', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => {
      throw new Error('shop_settings missing');
    });
    expect(await useShopProductCatalog(db, 1)).toBe(false);
  });

  it('is case-insensitive / trims whitespace, matching normalizeShopOrderDataSource()', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => [{ order_data_source: '  ODOO  ' }]);
    expect(await useShopProductCatalog(db, 1)).toBe(true);
  });
});
