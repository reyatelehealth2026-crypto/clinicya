/**
 * @jest-environment node
 */
jest.mock('./notify', () => ({
  notifyTelegramNewOrder: jest.fn().mockResolvedValue(true),
}));

import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb, type QueryImpl } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { handleCreateOrder } from './createOrder';
import { notifyTelegramNewOrder } from './notify';

function setup(queryImpl: QueryImpl) {
  return makeFakeKyselyDb<TenantDB>(queryImpl);
}

const mockNotify = notifyTelegramNewOrder as jest.Mock;

beforeEach(() => {
  mockNotify.mockClear();
  mockNotify.mockResolvedValue(true);
});

/** BEGIN/COMMIT/ROLLBACK bracket the transaction — pass them through harmlessly, same convention as
 *  pointsClaim.test.ts's happy-path test (falls through to its own catch-all `return []`). */
function passthrough(sqlText: string): boolean {
  return /^(BEGIN|COMMIT|ROLLBACK)/i.test(sqlText.trim());
}

describe('handleCreateOrder — validation', () => {
  it('User not found when there is no line_user_id and no user_id', async () => {
    const { db } = setup(() => []);
    const result = await handleCreateOrder(db, {});
    expect(result.status).toBe(200);
    expect(result.body).toEqual({ success: false, message: 'User not found (line_user_id: null)' });
    expect(mockNotify).not.toHaveBeenCalled();
  });

  it('Cart is empty when cart_items is omitted and the DB cart is empty', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users WHERE line_user_id')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('SELECT id, line_account_id, display_name, line_user_id FROM users WHERE id')) {
        return [{ id: 1, line_account_id: 1, display_name: 'X', line_user_id: 'U1' }];
      }
      if (sqlText.includes('FROM cart_items c')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleCreateOrder(db, { line_user_id: 'U1' });
    expect(result.body).toEqual({ success: false, message: 'Cart is empty' });
    expect(mockNotify).not.toHaveBeenCalled();
  });

  it('empty cart_items array falls through to the DB cart (also empty) -> Cart is empty', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users WHERE line_user_id')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('SELECT id, line_account_id, display_name, line_user_id FROM users WHERE id')) {
        return [{ id: 1, line_account_id: 1, display_name: 'X', line_user_id: 'U1' }];
      }
      if (sqlText.includes('FROM cart_items c')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await handleCreateOrder(db, { line_user_id: 'U1', cart_items: [] });
    expect(result.body).toEqual({ success: false, message: 'Cart is empty' });
  });
});

describe('handleCreateOrder — happy paths', () => {
  it('transfer payment: status pending, subtotal/shipping computed from client-provided cart_items -> matches fixtures/checkout-order/create-order-transfer-ok.json', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users WHERE line_user_id')) return [{ id: 42, line_account_id: 1 }];
      if (sqlText.includes('SELECT id, line_account_id, display_name, line_user_id FROM users WHERE id')) {
        return [{ id: 42, line_account_id: 1, display_name: 'สมชาย', line_user_id: 'U1111111111111111111111111111aa' }];
      }
      if (sqlText.includes('FROM shop_settings WHERE line_account_id')) return [{ shipping_fee: '40.00', free_shipping_min: '500.00' }];
      if (sqlText.includes('INSERT INTO transactions')) return { insertId: 9001, affectedRows: 1 };
      if (sqlText.includes('INSERT INTO transaction_items')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('UPDATE business_items SET stock')) return { affectedRows: 1 };
      if (sqlText.includes('SELECT stock FROM business_items')) return [{ stock: 48 }];
      if (sqlText.includes('INSERT INTO stock_movements')) return { insertId: 1, affectedRows: 1 };
      if (sqlText.includes('DELETE FROM cart_items')) return { affectedRows: 1 };
      if (passthrough(sqlText)) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const result = await handleCreateOrder(db, {
      line_user_id: 'U1111111111111111111111111111aa',
      line_account_id: 1,
      payment_method: 'transfer',
      address: {
        name: 'สมชาย',
        phone: '0812345678',
        address: '123 ถนนสุขุมวิท',
        subdistrict: 'คลองตัน',
        district: 'วัฒนา',
        province: 'กรุงเทพ',
        postcode: '10110',
      },
      cart_items: [{ product_id: 501, name: 'พาราเซตามอล 500mg', price: 20, quantity: 2, product_source: 'business_items' }],
    });

    expect(result.status).toBe(200);
    expect(result.body).toMatchObject({
      success: true,
      message: 'Order created',
      order_id: 9001,
      total: 80, // subtotal 40 + shipping 40 (below the 500 free-shipping minimum)
      payment_method: 'transfer',
      ar_id: null,
    });
    expect(result.body.order_number).toMatch(/^TXN\d{12}$/);

    const txInsert = queries.find((q) => q.sql.includes('INSERT INTO transactions'));
    expect(txInsert?.params).toContain('pending'); // status=pending (non-cod) AND payment_status=pending
    expect(mockNotify).toHaveBeenCalledTimes(1);
    expect(mockNotify.mock.calls[0][1]).toMatchObject({ orderId: 9001, orderNumber: result.body.order_number, total: 80 });
  });

  it('cod payment: status confirmed, wms_status set to pending_pick, payment_status still pending (never paid at creation)', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users WHERE line_user_id')) return [{ id: 42, line_account_id: 1 }];
      if (sqlText.includes('SELECT id, line_account_id, display_name, line_user_id FROM users WHERE id')) {
        return [{ id: 42, line_account_id: 1, display_name: 'สมชาย', line_user_id: 'U2' }];
      }
      if (sqlText.includes('FROM shop_settings WHERE line_account_id')) return [{ shipping_fee: '0.00', free_shipping_min: '0.00' }];
      if (sqlText.includes('INSERT INTO transactions')) return { insertId: 9002, affectedRows: 1 };
      if (sqlText.includes('INSERT INTO transaction_items')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('UPDATE business_items SET stock')) return { affectedRows: 1 };
      if (sqlText.includes('SELECT stock FROM business_items')) return [{ stock: 10 }];
      if (sqlText.includes('INSERT INTO stock_movements')) return { insertId: 1, affectedRows: 1 };
      if (sqlText.includes('DELETE FROM cart_items')) return { affectedRows: 1 };
      if (sqlText.includes("wms_status = 'pending_pick'")) return { affectedRows: 1 };
      if (passthrough(sqlText)) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const result = await handleCreateOrder(db, {
      line_user_id: 'U2',
      line_account_id: 1,
      payment_method: 'cod',
      address: { name: 'A', phone: '08', address: 'addr' },
      cart_items: [{ product_id: 501, name: 'X', price: 10, quantity: 1 }],
    });

    expect(result.body).toMatchObject({ success: true, message: 'Order created', payment_method: 'cod' });
    const txInsert = queries.find((q) => q.sql.includes('INSERT INTO transactions'));
    expect(txInsert?.params).toContain('confirmed'); // status
    expect(queries.some((q) => q.sql.includes("wms_status = 'pending_pick'"))).toBe(true);
  });

  it('insufficient stock: guarded UPDATE no-ops silently (0 affected rows), no rowcount check -> order still created/committed unchanged', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users WHERE line_user_id')) return [{ id: 42, line_account_id: 1 }];
      if (sqlText.includes('SELECT id, line_account_id, display_name, line_user_id FROM users WHERE id')) {
        return [{ id: 42, line_account_id: 1, display_name: 'สมชาย', line_user_id: 'U3' }];
      }
      if (sqlText.includes('FROM shop_settings WHERE line_account_id')) return [{ shipping_fee: '40.00', free_shipping_min: '500.00' }];
      if (sqlText.includes('INSERT INTO transactions')) return { insertId: 9003, affectedRows: 1 };
      if (sqlText.includes('INSERT INTO transaction_items')) return { insertId: 0, affectedRows: 1 };
      // Guarded UPDATE no-ops: seeded stock=0, requested quantity=5 -> `AND stock >= 5` never matches.
      if (sqlText.includes('UPDATE business_items SET stock')) return { affectedRows: 0 };
      // Stock column is unchanged (still 0) because the guard above never fired.
      if (sqlText.includes('SELECT stock FROM business_items')) return [{ stock: 0 }];
      if (sqlText.includes('INSERT INTO stock_movements')) return { insertId: 1, affectedRows: 1 };
      if (sqlText.includes('DELETE FROM cart_items')) return { affectedRows: 1 };
      if (passthrough(sqlText)) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const result = await handleCreateOrder(db, {
      line_user_id: 'U3',
      line_account_id: 1,
      payment_method: 'transfer',
      address: { name: 'A' },
      cart_items: [{ product_id: 777, name: 'X', price: 10, quantity: 5 }],
    });

    // NON-NEGOTIABLE (this batch's acceptance criteria): the order is still created/committed even though
    // the stock guard silently no-op'd — PHP never checks the UPDATE's affected-row-count.
    expect(result.body).toMatchObject({ success: true, message: 'Order created' });
    expect(queries.some((q) => q.sql.includes('INSERT INTO transactions'))).toBe(true);
    expect(queries.some((q) => q.sql.includes('INSERT INTO transaction_items'))).toBe(true);
    expect(queries.some((q) => q.sql.trim().startsWith('UPDATE business_items SET stock') && q.sql.includes('AND stock >= ?'))).toBe(true);
  });

  it('shop_products line: saleable_qty UPDATE guard, also never rowcount-checked', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users WHERE line_user_id')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('SELECT id, line_account_id, display_name, line_user_id FROM users WHERE id')) {
        return [{ id: 1, line_account_id: 1, display_name: 'X', line_user_id: 'U5' }];
      }
      if (sqlText.includes('FROM shop_settings WHERE line_account_id')) return [{ shipping_fee: 0, free_shipping_min: 0 }];
      if (sqlText.includes('INSERT INTO transactions')) return { insertId: 1, affectedRows: 1 };
      if (sqlText.includes('INSERT INTO transaction_items')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('UPDATE shop_products SET saleable_qty')) return { affectedRows: 0 };
      if (sqlText.includes('DELETE FROM cart_items')) return { affectedRows: 1 };
      if (passthrough(sqlText)) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    const result = await handleCreateOrder(db, {
      line_user_id: 'U5',
      line_account_id: 1,
      payment_method: 'transfer',
      address: {},
      cart_items: [{ product_id: 88, name: 'Odoo item', price: 50, quantity: 2, product_source: 'shop_products' }],
    });

    expect(result.body).toMatchObject({ success: true, message: 'Order created' });
    // business_items/stock_movements code paths must NOT run for a shop_products line.
    expect(queries.some((q) => q.sql.includes('UPDATE business_items SET stock'))).toBe(false);
    expect(queries.some((q) => q.sql.includes('INSERT INTO stock_movements'))).toBe(false);
  });
});

describe('handleCreateOrder — delivery_info shape (L1408-1425)', () => {
  it('full_address is a space-joined, empty-filtered concatenation of address/subdistrict/district/province/postcode', async () => {
    const { db, queries } = setup((sqlText) => {
      if (sqlText.includes('SELECT id, line_account_id FROM users WHERE line_user_id')) return [{ id: 1, line_account_id: 1 }];
      if (sqlText.includes('SELECT id, line_account_id, display_name, line_user_id FROM users WHERE id')) {
        return [{ id: 1, line_account_id: 1, display_name: 'X', line_user_id: 'U4' }];
      }
      if (sqlText.includes('FROM shop_settings WHERE line_account_id')) return [{ shipping_fee: 0, free_shipping_min: 0 }];
      if (sqlText.includes('INSERT INTO transactions')) return { insertId: 1, affectedRows: 1 };
      if (sqlText.includes('INSERT INTO transaction_items')) return { insertId: 0, affectedRows: 1 };
      if (sqlText.includes('UPDATE business_items SET stock')) return { affectedRows: 1 };
      if (sqlText.includes('SELECT stock FROM business_items')) return [{ stock: 5 }];
      if (sqlText.includes('INSERT INTO stock_movements')) return { insertId: 1, affectedRows: 1 };
      if (sqlText.includes('DELETE FROM cart_items')) return { affectedRows: 1 };
      if (passthrough(sqlText)) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });

    await handleCreateOrder(db, {
      line_user_id: 'U4',
      line_account_id: 1,
      payment_method: 'transfer',
      address: { address: '123 ถนนสุขุมวิท', subdistrict: '', district: 'วัฒนา', province: 'กรุงเทพ', postcode: '' },
      cart_items: [{ product_id: 1, name: 'X', price: 10, quantity: 1 }],
    });

    const txInsert = queries.find((q) => q.sql.includes('INSERT INTO transactions'));
    const deliveryInfoJson = txInsert?.params.find((p) => typeof p === 'string' && p.includes('full_address')) as string;
    expect(JSON.parse(deliveryInfoJson)).toEqual({
      type: 'shipping',
      name: '',
      phone: '',
      address: '123 ถนนสุขุมวิท',
      subdistrict: '',
      district: 'วัฒนา',
      province: 'กรุงเทพ',
      postcode: '',
      full_address: '123 ถนนสุขุมวิท วัฒนา กรุงเทพ',
    });
  });
});
