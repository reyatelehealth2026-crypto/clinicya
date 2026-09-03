/**
 * @jest-environment node
 */
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import {
  buildManagerProductPhotoUrl,
  getUserIdFromLineUserId,
  odooCartLineUnitPrice,
  phpFalsy,
  resolveCartProductSource,
  resolveCartProductSourceWithShopDefault,
  strOrEmpty,
  toFloatOrZero,
  toIntOrZero,
} from './cartProductSource';

describe('resolveCartProductSource', () => {
  it('only "shop_products" (case/whitespace-insensitive) maps to shop_products', () => {
    expect(resolveCartProductSource('shop_products')).toBe('shop_products');
    expect(resolveCartProductSource('  SHOP_PRODUCTS  ')).toBe('shop_products');
  });

  it('anything else, including null/undefined/empty, defaults to business_items', () => {
    expect(resolveCartProductSource(null)).toBe('business_items');
    expect(resolveCartProductSource(undefined)).toBe('business_items');
    expect(resolveCartProductSource('')).toBe('business_items');
    expect(resolveCartProductSource('business_items')).toBe('business_items');
    expect(resolveCartProductSource('odoo')).toBe('business_items');
  });
});

describe('resolveCartProductSourceWithShopDefault', () => {
  it('a non-empty raw value short-circuits without querying shop_settings', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>();
    expect(await resolveCartProductSourceWithShopDefault(db, 'shop_products', 1)).toBe('shop_products');
    expect(queries).toHaveLength(0);
  });

  it('empty/omitted raw value falls back to shop_settings.order_data_source', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('WHERE line_account_id =')) return [{ order_data_source: 'odoo' }];
      return [];
    });
    expect(await resolveCartProductSourceWithShopDefault(db, null, 1)).toBe('shop_products');
  });

  it('order_data_source anything other than odoo -> business_items', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>(() => [{ order_data_source: 'shop' }]);
    expect(await resolveCartProductSourceWithShopDefault(db, '', 1)).toBe('business_items');
  });
});

describe('odooCartLineUnitPrice', () => {
  it('prefers online_price when positive', () => {
    expect(odooCartLineUnitPrice({ o_list: '100.00', o_online: '80.00' })).toBe(80);
  });
  it('falls back to list_price when online is 0/absent', () => {
    expect(odooCartLineUnitPrice({ o_list: '100.00', o_online: null })).toBe(100);
    expect(odooCartLineUnitPrice({ o_list: '100.00', o_online: 0 })).toBe(100);
  });
  it('0 when both are absent/non-positive', () => {
    expect(odooCartLineUnitPrice({ o_list: null, o_online: null })).toBe(0);
    expect(odooCartLineUnitPrice({ o_list: '0', o_online: '0' })).toBe(0);
  });
});

describe('buildManagerProductPhotoUrl', () => {
  const OLD_ENV = process.env.MANAGER_PRODUCT_PHOTO_BASE_URL;
  afterEach(() => {
    // `process.env.X = undefined` stringifies to the literal "undefined" (Node coerces all env values
    // to strings) — must `delete` instead when there was no prior value, or every later test in this
    // file would see a truthy (bogus) override.
    if (OLD_ENV === undefined) {
      delete process.env.MANAGER_PRODUCT_PHOTO_BASE_URL;
    } else {
      process.env.MANAGER_PRODUCT_PHOTO_BASE_URL = OLD_ENV;
    }
  });

  it('numeric product_code is left-padded to 4 digits', () => {
    expect(buildManagerProductPhotoUrl('7', null)).toBe('https://manager.cnypharmacy.com/uploads/product_photo/0007.jpg');
  });
  it('non-numeric product_code is used as-is (URL-encoded)', () => {
    expect(buildManagerProductPhotoUrl('SKU-01', null)).toBe('https://manager.cnypharmacy.com/uploads/product_photo/SKU-01.jpg');
  });
  it('falls back to sku when product_code is empty', () => {
    expect(buildManagerProductPhotoUrl('', '42')).toBe('https://manager.cnypharmacy.com/uploads/product_photo/0042.jpg');
  });
  it('both empty -> empty string', () => {
    expect(buildManagerProductPhotoUrl('', '')).toBe('');
    expect(buildManagerProductPhotoUrl(null, null)).toBe('');
  });
  it('respects MANAGER_PRODUCT_PHOTO_BASE_URL override, trimming a trailing slash', () => {
    process.env.MANAGER_PRODUCT_PHOTO_BASE_URL = 'https://cdn.example.com/photos/';
    expect(buildManagerProductPhotoUrl('9', null)).toBe('https://cdn.example.com/photos/uploads/product_photo/0009.jpg');
  });
});

describe('getUserIdFromLineUserId', () => {
  it('falsy lineUserId -> null, no query issued', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>();
    expect(await getUserIdFromLineUserId(db, null)).toBeNull();
    expect(await getUserIdFromLineUserId(db, '')).toBeNull();
    expect(queries).toHaveLength(0);
  });

  it('existing user -> returns stored id/line_account_id, no INSERT', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 42, line_account_id: 3 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    expect(await getUserIdFromLineUserId(db, 'U1')).toEqual({ userId: 42, lineAccountId: 3 });
    expect(queries.some((q) => q.sql.includes('INSERT INTO users'))).toBe(false);
  });

  it('unknown user -> auto-creates against the default active line_accounts row', async () => {
    const { db, queries } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [];
      if (sqlText.includes('FROM line_accounts')) return [{ id: 5 }];
      if (sqlText.includes('INSERT INTO users')) return { insertId: 99, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });
    expect(await getUserIdFromLineUserId(db, 'Unew')).toEqual({ userId: 99, lineAccountId: 5 });
    expect(queries.some((q) => q.sql.includes("display_name) VALUES") && q.sql.includes('INSERT INTO users'))).toBe(true);
  });

  it('no active line_accounts row -> defaults to line_account_id 1', async () => {
    const { db } = makeFakeKyselyDb<TenantDB>((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [];
      if (sqlText.includes('FROM line_accounts')) return [];
      if (sqlText.includes('INSERT INTO users')) return { insertId: 7, affectedRows: 1 };
      throw new Error(`unexpected query: ${sqlText}`);
    });
    expect(await getUserIdFromLineUserId(db, 'Uanother')).toEqual({ userId: 7, lineAccountId: 1 });
  });
});

describe('phpCompat helpers', () => {
  it('strOrEmpty', () => {
    expect(strOrEmpty('abc')).toBe('abc');
    expect(strOrEmpty(null)).toBe('');
    expect(strOrEmpty(undefined)).toBe('');
    expect(strOrEmpty(42)).toBe('42');
  });

  it('phpFalsy', () => {
    expect(phpFalsy(null)).toBe(true);
    expect(phpFalsy(undefined)).toBe(true);
    expect(phpFalsy('')).toBe(true);
    expect(phpFalsy('0')).toBe(true);
    expect(phpFalsy(0)).toBe(true);
    expect(phpFalsy(false)).toBe(true);
    expect(phpFalsy('U1')).toBe(false);
    expect(phpFalsy(1)).toBe(false);
  });

  it('toFloatOrZero', () => {
    expect(toFloatOrZero('20.50')).toBe(20.5);
    expect(toFloatOrZero(null)).toBe(0);
    expect(toFloatOrZero('')).toBe(0);
    expect(toFloatOrZero('abc')).toBe(0);
  });

  it('toIntOrZero', () => {
    expect(toIntOrZero(3)).toBe(3);
    expect(toIntOrZero('4')).toBe(4);
    expect(toIntOrZero('abc')).toBe(0);
    expect(toIntOrZero(null)).toBe(0);
    expect(toIntOrZero(2.9)).toBe(2);
  });
});
