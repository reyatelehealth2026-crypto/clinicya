/**
 * @jest-environment node
 */
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb, type QueryImpl } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { checkoutOrderUnitPrice, loadCheckoutCartLinesFromDb, odooCartLineUnitPrice, resolveCartProductSource } from './cartLines';

function setup(queryImpl: QueryImpl) {
  return makeFakeKyselyDb<TenantDB>(queryImpl);
}

describe('resolveCartProductSource', () => {
  it('is business_items for null/unknown/empty', () => {
    expect(resolveCartProductSource(null)).toBe('business_items');
    expect(resolveCartProductSource(undefined)).toBe('business_items');
    expect(resolveCartProductSource('')).toBe('business_items');
    expect(resolveCartProductSource('nonsense')).toBe('business_items');
  });
  it('is shop_products only for an exact (case/whitespace-insensitive) match', () => {
    expect(resolveCartProductSource('shop_products')).toBe('shop_products');
    expect(resolveCartProductSource(' Shop_Products ')).toBe('shop_products');
  });
});

describe('odooCartLineUnitPrice', () => {
  it('prefers online_price when positive', () => {
    expect(odooCartLineUnitPrice({ o_list: '100.00', o_online: '80.00' })).toBe(80);
  });
  it('falls back to list_price when online_price is 0/absent', () => {
    expect(odooCartLineUnitPrice({ o_list: '100.00', o_online: 0 })).toBe(100);
    expect(odooCartLineUnitPrice({ o_list: '100.00', o_online: null })).toBe(100);
  });
  it('is 0 when both are 0/absent', () => {
    expect(odooCartLineUnitPrice({ o_list: null, o_online: null })).toBe(0);
  });
});

describe('checkoutOrderUnitPrice', () => {
  it('prefers sale_price when it is positive and below price', () => {
    expect(checkoutOrderUnitPrice({ price: 100, sale_price: 80 })).toBe(80);
  });
  it('ignores sale_price when it is not below price', () => {
    expect(checkoutOrderUnitPrice({ price: 100, sale_price: 120 })).toBe(100);
  });
  it('uses sale_price when price is <= 0', () => {
    expect(checkoutOrderUnitPrice({ price: 0, sale_price: 50 })).toBe(50);
  });
  it('is 0 when both are absent', () => {
    expect(checkoutOrderUnitPrice({})).toBe(0);
  });
});

describe('loadCheckoutCartLinesFromDb', () => {
  it('business_items line: unit price prefers sale_price, filters out a line whose joined product is missing (deleted product)', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('FROM cart_items c')) {
        return [
          {
            product_id: 501,
            quantity: 2,
            product_source: 'business_items',
            bi_name: 'พาราเซตามอล 500mg',
            bi_price: '20.00',
            bi_sale_price: '15.00',
            o_name: null,
            o_list: null,
            o_online: null,
          },
          {
            product_id: 999,
            quantity: 1,
            product_source: 'business_items',
            bi_name: null,
            bi_price: null,
            bi_sale_price: null,
            o_name: null,
            o_list: null,
            o_online: null,
          },
        ];
      }
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const result = await loadCheckoutCartLinesFromDb(db, 42, 1);
    expect(result).toEqual([
      {
        product_id: 501,
        name: 'พาราเซตามอล 500mg',
        price: 20,
        sale_price: 15,
        quantity: 2,
        product_source: 'business_items',
        _unit: 15,
      },
    ]);
    expect(queries[0]?.sql).toContain('LEFT JOIN shop_products o');
  });

  it('shop_products line: prefers online_price for _unit, list_price for display price', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('FROM cart_items c')) {
        return [
          {
            product_id: 88,
            quantity: 3,
            product_source: 'shop_products',
            bi_name: null,
            bi_price: null,
            bi_sale_price: null,
            o_name: 'ยาลดไข้ (Odoo)',
            o_list: '100.00',
            o_online: '80.00',
          },
        ];
      }
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const result = await loadCheckoutCartLinesFromDb(db, 1, 1);
    expect(result).toEqual([
      {
        product_id: 88,
        name: 'ยาลดไข้ (Odoo)',
        price: 100,
        sale_price: 80,
        quantity: 3,
        product_source: 'shop_products',
        _unit: 80,
      },
    ]);
  });

  it('shop_products line with no list_price: price and _unit both fall back to online_price, sale_price null', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('FROM cart_items c')) {
        return [
          {
            product_id: 88,
            quantity: 1,
            product_source: 'shop_products',
            bi_name: null,
            bi_price: null,
            bi_sale_price: null,
            o_name: 'X',
            o_list: 0,
            o_online: '50.00',
          },
        ];
      }
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const result = await loadCheckoutCartLinesFromDb(db, 1, 1);
    expect(result[0]).toMatchObject({ price: 50, sale_price: null, _unit: 50 });
  });

  it('empty cart -> empty array', async () => {
    const { db } = setup(() => []);
    const result = await loadCheckoutCartLinesFromDb(db, 1, null);
    expect(result).toEqual([]);
  });
});
