/**
 * @jest-environment node
 */
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb, sqlDate, type QueryImpl } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { handleAddToCart, handleClearCart, handleGetCart, handleRemoveFromCart, handleUpdateCart } from './handlers';

function setup(queryImpl: QueryImpl) {
  return makeFakeKyselyDb<TenantDB>(queryImpl);
}

describe('handleGetCart (action=cart)', () => {
  it('existing user, one business_items line -> matches fixtures/checkout-cart/cart-ok.json', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 42, line_account_id: 1 }];
      if (sqlText.includes('FROM cart_items c')) {
        return [
          {
            id: 901,
            line_account_id: 1,
            line_user_id: 'U1111111111111111111111111111aa',
            product_id: 501,
            product_source: 'business_items',
            quantity: 2,
            unit_id: null,
            created_at: sqlDate('2026-07-10 09:00:00'),
            updated_at: sqlDate('2026-07-10 09:00:00'),
            user_id: 42,
            bi_name: 'พาราเซตามอล 500mg',
            bi_price: '20.00',
            bi_sale_price: null,
            bi_image_url: 'https://cdn.example.com/para.jpg',
            bi_is_active: 1,
            o_name: null,
            o_list: null,
            o_online: null,
            o_pc: null,
            o_sku: null,
            o_is_active: null,
          },
        ];
      }
      if (sqlText.includes('FROM shop_settings WHERE line_account_id')) return [{ shipping_fee: '40.00', free_shipping_min: '500.00' }];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const result = await handleGetCart(db, { line_user_id: 'U1111111111111111111111111111aa' });

    expect(result.status).toBe(200);
    expect(result.body).toMatchObject({
      success: true,
      message: '',
      subtotal: 40,
      shipping_fee: 40,
      free_shipping_min: 500,
      total: 80,
      item_count: 1,
    });
    const items = result.body.items as Record<string, unknown>[];
    expect(items).toHaveLength(1);
    expect(items[0]).toMatchObject({
      name: 'พาราเซตามอล 500mg',
      price: '20.00', // raw DECIMAL passthrough, NOT Number()-cast
      sale_price: null,
      subtotal: 40,
      product_source: 'business_items',
      created_at: '2026-07-10 09:00:00', // Date -> PHP PDO-shaped string, not a Z-suffixed ISO string
    });
    expect(items[0].bi_price).toBe('20.00'); // raw joined alias column still present (leaky shape)
  });

  it('neither line_user_id nor user_id -> User not found (fixtures/checkout-cart/cart-empty-user-not-found.json)', async () => {
    const { db, queries } = setup(() => []);
    const result = await handleGetCart(db, {});
    expect(result.body).toEqual({ success: false, message: 'User not found' });
    expect(queries).toHaveLength(0);
  });

  it('debug=1 (any value) attaches the debug trail, including on the User-not-found failure branch', async () => {
    const { db } = setup(() => []);
    const result = await handleGetCart(db, { debug: '1' });
    expect(result.body.success).toBe(false);
    expect(result.body.debug).toMatchObject({ input_user_id: null, input_line_user_id: null, line_user_id_length: 0 });
  });

  it('unknown line_user_id auto-creates a user, tracked in debug.user_created/new_user_id', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [];
      if (sqlText.includes('FROM line_accounts')) return [{ id: 1 }];
      if (sqlText.includes('INSERT INTO users')) return { insertId: 55, affectedRows: 1 };
      if (sqlText.includes('FROM cart_items c')) return [];
      if (sqlText.includes('FROM shop_settings')) return [{ shipping_fee: 50, free_shipping_min: 500 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleGetCart(db, { line_user_id: 'Ubrandnew', debug: '' });
    expect(result.body.debug).toMatchObject({ user_created: true, new_user_id: 55 });
    expect(result.body).toMatchObject({ success: true, items: [], item_count: 0 });
  });

  it('a cart line whose joined product row is missing (deleted product) is filtered out, tracked in debug.filtered_out', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM cart_items c')) {
        return [
          {
            id: 1,
            line_account_id: 1,
            line_user_id: 'U1',
            product_id: 4321,
            product_source: 'business_items',
            quantity: 1,
            unit_id: null,
            created_at: '2026-01-01 00:00:00',
            updated_at: '2026-01-01 00:00:00',
            user_id: 1,
            bi_name: null,
            bi_price: null,
            bi_sale_price: null,
            bi_image_url: null,
            bi_is_active: null,
            o_name: null,
            o_list: null,
            o_online: null,
            o_pc: null,
            o_sku: null,
            o_is_active: null,
          },
        ];
      }
      if (sqlText.includes('FROM shop_settings')) return [{ shipping_fee: 50, free_shipping_min: 500 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleGetCart(db, { line_user_id: 'U1', debug: '1' });
    expect(result.body.items).toEqual([]);
    expect(result.body.debug).toMatchObject({
      raw_cart_count: 1,
      filtered_cart_count: 0,
      filtered_out: [{ product_id: 4321, reason: 'product_deleted' }],
    });
  });

  it('shop_products line: price/sale_price are genuine numbers (float-cast), image_url built via manager-photo pattern', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM cart_items c')) {
        return [
          {
            id: 2,
            line_account_id: 1,
            line_user_id: 'U1',
            product_id: 88,
            product_source: 'shop_products',
            quantity: 3,
            unit_id: null,
            created_at: '2026-01-01 00:00:00',
            updated_at: '2026-01-01 00:00:00',
            user_id: 1,
            bi_name: null,
            bi_price: null,
            bi_sale_price: null,
            bi_image_url: null,
            bi_is_active: null,
            o_name: 'ยาลดไข้ (Odoo)',
            o_list: '100.00',
            o_online: '80.00',
            o_pc: '7',
            o_sku: 'SKU7',
            o_is_active: 1,
          },
        ];
      }
      if (sqlText.includes('FROM shop_settings')) return [{ shipping_fee: 0, free_shipping_min: 0 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleGetCart(db, { line_user_id: 'U1' });
    const items = result.body.items as Record<string, unknown>[];
    expect(items[0]).toMatchObject({
      name: 'ยาลดไข้ (Odoo)',
      price: 100, // genuine number, not a string — PHP explicitly (float)-casts on the shop_products branch
      sale_price: 80,
      image_url: 'https://manager.cnypharmacy.com/uploads/product_photo/0007.jpg',
      product_source: 'shop_products',
      subtotal: 240, // odooCartLineUnitPrice prefers online_price (80) * quantity (3)
    });
  });

  it('lineAccountId-scoped shop_settings empty -> falls back to the unscoped LIMIT 1 row', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 9 }];
      if (sqlText.includes('FROM cart_items c')) return [];
      if (sqlText.includes('FROM shop_settings WHERE line_account_id')) return [];
      if (sqlText.includes('FROM shop_settings LIMIT 1')) return [{ shipping_fee: 30, free_shipping_min: 1000 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleGetCart(db, { line_user_id: 'U1' });
    expect(result.body).toMatchObject({ shipping_fee: 30, free_shipping_min: 1000 });
    expect(queries.some((q) => q.sql.includes('FROM shop_settings LIMIT 1'))).toBe(true);
  });

  it('no shop_settings row at all -> defaults shipping_fee=50/free_shipping_min=500', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM cart_items c')) return [];
      if (sqlText.includes('FROM shop_settings')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleGetCart(db, { line_user_id: 'U1' });
    expect(result.body).toMatchObject({ shipping_fee: 50, free_shipping_min: 500, subtotal: 0, total: 50 });
  });

  it('subtotal >= free_shipping_min waives shipping_fee (set to 0)', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM cart_items c')) {
        return [
          {
            id: 1,
            line_account_id: 1,
            line_user_id: 'U1',
            product_id: 1,
            product_source: 'business_items',
            quantity: 100,
            unit_id: null,
            created_at: '2026-01-01 00:00:00',
            updated_at: '2026-01-01 00:00:00',
            user_id: 1,
            bi_name: 'X',
            bi_price: '10.00',
            bi_sale_price: null,
            bi_image_url: null,
            bi_is_active: 1,
            o_name: null,
            o_list: null,
            o_online: null,
            o_pc: null,
            o_sku: null,
            o_is_active: null,
          },
        ];
      }
      if (sqlText.includes('FROM shop_settings')) return [{ shipping_fee: 50, free_shipping_min: 500 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleGetCart(db, { line_user_id: 'U1' });
    expect(result.body).toMatchObject({ subtotal: 1000, shipping_fee: 0, total: 1000 });
  });
});

describe('handleAddToCart (action=add_to_cart)', () => {
  it('ok -> matches fixtures/checkout-cart/add-to-cart-ok.json', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM shop_settings')) return [{ order_data_source: 'shop' }];
      if (sqlText.includes('FROM business_items WHERE id')) return [{ id: 501, name: 'พาราเซตามอล 500mg', price: '20.00', sale_price: null, stock: 50 }];
      if (sqlText.includes('INSERT INTO cart_items')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('SUM(quantity)')) return [{ total: 2 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleAddToCart(db, { action: 'add_to_cart', line_user_id: 'U2222222222222222222222222222bb', product_id: 501, quantity: 2 });
    expect(result.body).toEqual({ success: true, message: 'Added to cart', cart_count: 2, product_name: 'พาราเซตามอล 500mg' });
    expect(queries.some((q) => q.sql.includes('ON DUPLICATE KEY UPDATE'))).toBe(true);
  });

  it('not enough stock -> matches fixtures/checkout-cart/add-to-cart-not-enough-stock.json', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM shop_settings')) return [{ order_data_source: 'shop' }];
      if (sqlText.includes('FROM business_items WHERE id')) return [{ id: 777, name: 'X', price: '1', sale_price: null, stock: 3 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleAddToCart(db, { action: 'add_to_cart', line_user_id: 'U3333333333333333333333333333cc', product_id: 777, quantity: 10 });
    expect(result.body).toEqual({ success: false, message: 'Not enough stock' });
  });

  it('product not found -> matches fixtures/checkout-cart/add-to-cart-product-not-found.json', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM shop_settings')) return [{ order_data_source: 'shop' }];
      if (sqlText.includes('FROM business_items WHERE id')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleAddToCart(db, { action: 'add_to_cart', line_user_id: 'U4444444444444444444444444444dd', product_id: 999999, quantity: 1 });
    expect(result.body).toEqual({ success: false, message: 'Product not found' });
  });

  it('missing line_user_id/product_id -> Missing required fields, no queries', async () => {
    const { db, queries } = setup(() => []);
    const result = await handleAddToCart(db, { action: 'add_to_cart', product_id: 1 });
    expect(result.body).toEqual({ success: false, message: 'Missing required fields' });
    expect(queries).toHaveLength(0);
  });

  it('quantity is clamped to a minimum of 1 (PHP max(1, intval(...)))', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM shop_settings')) return [{ order_data_source: 'shop' }];
      if (sqlText.includes('FROM business_items WHERE id')) return [{ id: 1, name: 'X', price: '1', sale_price: null, stock: 5 }];
      if (sqlText.includes('INSERT INTO cart_items')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('SUM(quantity)')) return [{ total: 1 }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    await handleAddToCart(db, { action: 'add_to_cart', line_user_id: 'U1', product_id: 1, quantity: 0 });
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO cart_items'));
    expect(insertQuery?.params).toContain(1);
  });

  it('shop_products branch checks saleable_qty against quantity', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM shop_products')) return [{ id: 9, name: 'Odoo item', list_price: '10', online_price: '8', saleable_qty: '1' }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleAddToCart(db, { action: 'add_to_cart', line_user_id: 'U1', product_id: 9, quantity: 5, product_source: 'shop_products' });
    expect(result.body).toEqual({ success: false, message: 'Not enough stock' });
  });
});

describe('handleUpdateCart (action=update_cart)', () => {
  it('quantity<=0 deletes the line -> matches fixtures/checkout-cart/update-cart-remove-on-zero-qty.json', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('SUM(quantity)')) return [{ total: null }];
      return [];
    });
    const result = await handleUpdateCart(db, { action: 'update_cart', line_user_id: 'U5555555555555555555555555555ee', product_id: 501, quantity: 0 });
    expect(result.body).toEqual({ success: true, message: 'Cart updated', cart_count: 0 });
    expect(queries.some((q) => q.sql.trim().startsWith('DELETE FROM cart_items'))).toBe(true);
    expect(queries.some((q) => q.sql.trim().startsWith('UPDATE cart_items'))).toBe(false);
  });

  it('positive quantity within stock -> UPDATE, not DELETE', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM business_items WHERE id')) return [{ stock: 20 }];
      if (sqlText.includes('SUM(quantity)')) return [{ total: 5 }];
      return [];
    });
    const result = await handleUpdateCart(db, { action: 'update_cart', line_user_id: 'U1', product_id: 501, quantity: 5 });
    expect(result.body).toEqual({ success: true, message: 'Cart updated', cart_count: 5 });
    expect(queries.some((q) => q.sql.trim().startsWith('UPDATE cart_items'))).toBe(true);
  });

  it('stock found and insufficient -> Not enough stock, no UPDATE', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM business_items WHERE id')) return [{ stock: 2 }];
      return [];
    });
    const result = await handleUpdateCart(db, { action: 'update_cart', line_user_id: 'U1', product_id: 501, quantity: 5 });
    expect(result.body).toEqual({ success: false, message: 'Not enough stock' });
    expect(queries.some((q) => q.sql.trim().startsWith('UPDATE cart_items'))).toBe(false);
  });

  it('no stock row found (product missing) -> stock check is silently skipped, UPDATE still runs (preserved PHP quirk)', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('FROM business_items WHERE id')) return [];
      if (sqlText.includes('SUM(quantity)')) return [{ total: 3 }];
      return [];
    });
    const result = await handleUpdateCart(db, { action: 'update_cart', line_user_id: 'U1', product_id: 404, quantity: 3 });
    expect(result.body).toEqual({ success: true, message: 'Cart updated', cart_count: 3 });
  });
});

describe('handleRemoveFromCart (action=remove_from_cart)', () => {
  it('ok -> matches fixtures/checkout-cart/remove-from-cart-ok.json', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('SUM(quantity)')) return [{ total: null }];
      return [];
    });
    const result = await handleRemoveFromCart(db, { action: 'remove_from_cart', line_user_id: 'U6666666666666666666666666666ff', product_id: 501 });
    expect(result.body).toEqual({ success: true, message: 'Item removed', cart_count: 0 });
    expect(queries.some((q) => q.sql.trim().startsWith('DELETE FROM cart_items'))).toBe(true);
  });

  it('missing product_id -> Missing required fields', async () => {
    const { db, queries } = setup(() => []);
    const result = await handleRemoveFromCart(db, { action: 'remove_from_cart', line_user_id: 'U1' });
    expect(result.body).toEqual({ success: false, message: 'Missing required fields' });
    expect(queries).toHaveLength(0);
  });
});

describe('handleClearCart (action=clear_cart)', () => {
  it('ok -> matches fixtures/checkout-cart/clear-cart-ok.json', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users')) return [{ id: 1, line_account_id: 1 }];
      return [];
    });
    const result = await handleClearCart(db, { action: 'clear_cart', line_user_id: 'U7777777777777777777777777777aa' });
    expect(result.body).toEqual({ success: true, message: 'Cart cleared', cart_count: 0 });
    expect(queries.some((q) => q.sql.trim() === 'DELETE FROM cart_items WHERE user_id = ?')).toBe(true);
  });

  it('missing line_user_id -> Missing line_user_id, no queries', async () => {
    const { db, queries } = setup(() => []);
    const result = await handleClearCart(db, {});
    expect(result.body).toEqual({ success: false, message: 'Missing line_user_id' });
    expect(queries).toHaveLength(0);
  });
});
