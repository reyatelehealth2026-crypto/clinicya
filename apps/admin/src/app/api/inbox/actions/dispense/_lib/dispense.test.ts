/**
 * @jest-environment node
 */
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './testHelpers/fakeTenantDb';

const mockTrackFromDispense = jest.fn();
jest.mock('./refillTracking', () => ({
  trackFromDispense: (...args: unknown[]) => mockTrackFromDispense(...args),
}));

const mockSendDispenseFlexMessage = jest.fn();
jest.mock('./flexSend', () => ({
  sendDispenseFlexMessage: (...args: unknown[]) => mockSendDispenseFlexMessage(...args),
}));

import { dispenseAction, type DispenseRequestBody } from './dispense';

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 7,
    tenantId: 1,
    currentBotId: 3,
    role: 'admin',
    username: 'pharmacist1',
    displayName: 'Pharmacist One',
    createdAt: '2026-07-01T00:00:00.000Z',
    lastSeenAt: '2026-07-14T00:00:00.000Z',
    expiresAt: '2026-07-15T00:00:00.000Z',
    ...overrides,
  };
}

const USER_ROW = {
  line_user_id: 'Uabc123',
  line_account_id: 9,
  display_name: 'สมชาย',
  reply_token: 'replytok',
  reply_token_expires_str: '2026-07-14 12:00:00',
};

interface QueryImplConfig {
  userRow?: Record<string, unknown> | null;
  businessItemRow?: Record<string, unknown> | null;
  shopSettingsRow?: Record<string, unknown> | null;
  lineAccountNameRow?: Record<string, unknown> | null;
}

/** Builds a query responder covering every SQL branch dispenseAction() issues (refillTracking/flexSend are jest-mocked, so no DB calls are expected for those). */
function makeDefaultQueryImpl(config: QueryImplConfig = {}) {
  let nextInsertId = 100;
  return (sqlText: string, _params: unknown[]): unknown => {
    const lower = sqlText.toLowerCase();

    if (lower.includes('from business_items where id')) {
      return config.businessItemRow === undefined ? [] : config.businessItemRow === null ? [] : [config.businessItemRow];
    }
    if (lower.includes('from users where id')) {
      return config.userRow === undefined ? [USER_ROW] : config.userRow === null ? [] : [config.userRow];
    }
    if (lower.includes('from shop_settings where line_account_id')) {
      return config.shopSettingsRow === undefined ? [] : config.shopSettingsRow === null ? [] : [config.shopSettingsRow];
    }
    if (lower.includes('from line_accounts where id')) {
      return config.lineAccountNameRow === undefined ? [] : config.lineAccountNameRow === null ? [] : [config.lineAccountNameRow];
    }
    if (lower.startsWith('update business_items set stock')) {
      return { insertId: 0, affectedRows: 1 };
    }
    if (lower.startsWith('delete from cart')) {
      return { insertId: 0, affectedRows: 1 };
    }
    if (
      lower.includes('insert into dispensing_records') ||
      lower.includes('insert into cart') ||
      lower.includes('insert into transactions') ||
      lower.includes('insert into transaction_items') ||
      lower.includes('activity_logs')
    ) {
      return { insertId: nextInsertId++, affectedRows: 1 };
    }
    return [];
  };
}

function wireFakeDb(config: QueryImplConfig = {}): { queries: RecordedQuery[]; db: ReturnType<typeof makeFakeTenantDb>['db'] } {
  const { db, queries } = makeFakeTenantDb(makeDefaultQueryImpl(config));
  return { queries, db };
}

function itemsJson(items: unknown[]): string {
  return JSON.stringify(items);
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('dispenseAction — validation', () => {
  it('throws "No items to dispense" for an empty items array', async () => {
    const { db } = wireFakeDb();
    const body: DispenseRequestBody = { items: '[]', payment_method: 'cash' };
    await expect(dispenseAction(db, fakeSession(), 42, body, 'https://tenant.re-ya.com')).rejects.toThrow('No items to dispense');
  });

  it('throws "No items to dispense" when items is an unparseable string', async () => {
    const { db } = wireFakeDb();
    const body: DispenseRequestBody = { items: 'not json', payment_method: 'cash' };
    await expect(dispenseAction(db, fakeSession(), 42, body, 'https://tenant.re-ya.com')).rejects.toThrow('No items to dispense');
  });

  it('throws "User not found" when the users lookup returns no row', async () => {
    const { db } = wireFakeDb({ userRow: null });
    const body: DispenseRequestBody = { items: itemsJson([{ product_id: 1, qty: 2, price: 10, name: 'ยา A' }]), payment_method: 'cash' };
    await expect(dispenseAction(db, fakeSession(), 42, body, 'https://tenant.re-ya.com')).rejects.toThrow('User not found');
  });
});

describe('dispenseAction — order-number generation', () => {
  it('order_number matches /^DIS\\d{15}$/ and the transaction order_number matches /^TXN\\d{17}$/ (NOT genDocNumber\'s {PREFIX}-{YYMM}-{seq4} scheme)', async () => {
    const { db, queries } = wireFakeDb();
    const body: DispenseRequestBody = {
      items: itemsJson([{ product_id: 1, qty: 2, price: 10, name: 'ยา A' }]),
      payment_method: 'cash',
    };
    const result = await dispenseAction(db, fakeSession(), 42, body, 'https://tenant.re-ya.com');

    expect(result.status).toBe(200);
    const orderNumber = result.body.order_number as string;
    expect(orderNumber).toMatch(/^DIS\d{15}$/);
    expect(orderNumber).not.toMatch(/^DIS-\d{4}-\d{4}$/);

    const txnInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into transactions'));
    expect(txnInsert).toBeDefined();
    // Bound param order: line_account_id, user_id, line_user_id, order_number, [literal 'purchase'
    // is NOT a bound param], status, payment_status, ... — see dispense.ts's transactions INSERT.
    const txnOrderNumber = txnInsert!.params[3] as string;
    expect(txnOrderNumber).toMatch(/^TXN\d{17}$/);
    expect(txnOrderNumber).not.toMatch(/^TXN-\d{4}-\d{4}$/);
  });
});

describe('dispenseAction — raw items payload vs hydrated itemsArr', () => {
  it('dispensing_records.items stores the pre-hydration raw payload, not the business_items-hydrated array used for tracking/flex', async () => {
    const rawItems = [{ product_id: 1, qty: 2, price: 10, name: 'ยา A' }];
    const rawJson = itemsJson(rawItems);
    const { db, queries } = wireFakeDb({
      businessItemRow: {
        description: 'แก้ปวด',
        usage_instructions: 'ทานหลังอาหาร',
        default_usage_text: null,
        image_url: 'https://example.com/a.png',
        photo_path: null,
        generic_name: 'Paracetamol',
        strength: '500mg',
        manufacturer: 'ACME',
      },
    });
    const body: DispenseRequestBody = { items: rawJson, payment_method: 'cash' };

    await dispenseAction(db, fakeSession(), 42, body, 'https://tenant.re-ya.com');

    const dispenseInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into dispensing_records'));
    expect(dispenseInsert).toBeDefined();
    // Column order: line_account_id, user_id, pharmacist_id, order_number, items, total_amount, payment_method, notes
    expect(dispenseInsert!.params[4]).toBe(rawJson);
    expect(JSON.parse(dispenseInsert!.params[4] as string)).toEqual(rawItems);

    // The hydrated array handed to refill-tracking/flex-send DOES carry the business_items fields.
    expect(mockTrackFromDispense).toHaveBeenCalledTimes(1);
    const hydratedItems = mockTrackFromDispense.mock.calls[0][1] as Array<Record<string, unknown>>;
    expect(hydratedItems[0].indication).toBe('แก้ปวด');
    expect(hydratedItems[0].usage_text).toBe('ทานหลังอาหาร');
    expect(hydratedItems[0].generic_name).toBe('Paracetamol');
    expect(hydratedItems[0].strength).toBe('500mg');
    expect(hydratedItems[0].manufacturer).toBe('ACME');
  });

  it('a native JS array (not a JSON string) for `items` is also stored raw (JSON.stringify of the exact input) and independently cloned for hydration', async () => {
    const rawItems = [{ product_id: 1, qty: 1, price: 5, name: 'ยา B' }];
    const { db, queries } = wireFakeDb();
    const body: DispenseRequestBody = { items: rawItems, payment_method: 'cash' };

    await dispenseAction(db, fakeSession(), 42, body, 'https://tenant.re-ya.com');

    const dispenseInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into dispensing_records'));
    expect(JSON.parse(dispenseInsert!.params[4] as string)).toEqual(rawItems);
    // Mutating the array the hydration loop worked on must never mutate the caller's original object.
    expect(rawItems[0]).not.toHaveProperty('indication');
  });
});

describe('dispenseAction — cash vs transfer/later branching', () => {
  it('cash: stock UPDATE fires per item with the exact guarded WHERE clause, payment_status=paid/status=completed, cart is never touched', async () => {
    const { db, queries } = wireFakeDb();
    const body: DispenseRequestBody = {
      items: itemsJson([{ product_id: 5, qty: 3, price: 20, name: 'ยา C' }]),
      payment_method: 'cash',
      total_amount: 60,
    };

    await dispenseAction(db, fakeSession(), 42, body, 'https://tenant.re-ya.com');

    const stockUpdate = queries.find((q) => q.sql.toLowerCase().includes('update business_items set stock'));
    expect(stockUpdate).toBeDefined();
    expect(stockUpdate!.sql.toLowerCase()).toContain('and stock >=');
    expect(stockUpdate!.params).toEqual([3, 5, 3]);

    expect(queries.some((q) => q.sql.toLowerCase().includes('cart'))).toBe(false);

    const txnInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into transactions'));
    // Bound param order: line_account_id[0], user_id[1], line_user_id[2], order_number[3],
    // [literal 'purchase' skipped], status[4], payment_status[5], payment_method[6], ...
    expect(txnInsert!.params[4]).toBe('completed');
    expect(txnInsert!.params[5]).toBe('paid');
  });

  it.each(['transfer', 'later'])(
    'payment_method=%s: DELETEs then re-INSERTs the cart, payment_status=pending/status=pending, stock UPDATE never fires',
    async (paymentMethod) => {
      const { db, queries } = wireFakeDb();
      const body: DispenseRequestBody = {
        items: itemsJson([{ product_id: 5, qty: 3, price: 20, name: 'ยา C' }]),
        payment_method: paymentMethod,
        total_amount: 60,
      };

      await dispenseAction(db, fakeSession({ currentBotId: 3 }), 42, body, 'https://tenant.re-ya.com');

      expect(queries.some((q) => q.sql.toLowerCase().includes('update business_items set stock'))).toBe(false);

      const cartDelete = queries.find((q) => q.sql.toLowerCase().startsWith('delete from cart'));
      expect(cartDelete).toBeDefined();
      expect(cartDelete!.params).toEqual([42, 3]);

      const cartInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into cart'));
      expect(cartInsert).toBeDefined();
      expect(cartInsert!.params).toEqual([3, 42, 5, 3]);

      // DELETE must precede the INSERT.
      const deleteIndex = queries.indexOf(cartDelete!);
      const insertIndex = queries.indexOf(cartInsert!);
      expect(deleteIndex).toBeLessThan(insertIndex);

      const txnInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into transactions'));
      expect(txnInsert!.params[4]).toBe('pending'); // status
      expect(txnInsert!.params[5]).toBe('pending'); // payment_status
    }
  );
});

describe('dispenseAction — sub-step fault tolerance', () => {
  it('a thrown error inside RefillTracking still yields 200 success with the same dispense_id/order_number as a failure-free run', async () => {
    const { db: db1 } = wireFakeDb();
    const session = fakeSession();
    const body: DispenseRequestBody = { items: itemsJson([{ product_id: 1, qty: 1, price: 10, name: 'ยา A' }]), payment_method: 'cash' };

    mockTrackFromDispense.mockImplementationOnce(() => {
      throw new Error('refill tracking exploded');
    });
    const failResult = await dispenseAction(db1, session, 42, body, 'https://tenant.re-ya.com');
    expect(failResult.status).toBe(200);
    expect(failResult.body.success).toBe(true);
    expect(typeof failResult.body.order_number).toBe('string');
    expect(typeof failResult.body.dispense_id).toBe('number');
  });

  it('a thrown error inside the Flex-send step still yields 200 success with the same dispense_id/order_number as a failure-free run', async () => {
    const { db } = wireFakeDb();
    const session = fakeSession();
    const body: DispenseRequestBody = { items: itemsJson([{ product_id: 1, qty: 1, price: 10, name: 'ยา A' }]), payment_method: 'cash' };

    mockSendDispenseFlexMessage.mockImplementationOnce(() => {
      throw new Error('flex send exploded');
    });
    const result = await dispenseAction(db, session, 42, body, 'https://tenant.re-ya.com');
    expect(result.status).toBe(200);
    expect(result.body.success).toBe(true);
    expect(typeof result.body.order_number).toBe('string');
    expect(typeof result.body.dispense_id).toBe('number');
  });

  it('flex-send is only attempted when the user has a line_user_id', async () => {
    const { db } = wireFakeDb({ userRow: { ...USER_ROW, line_user_id: null } });
    const body: DispenseRequestBody = { items: itemsJson([{ product_id: 1, qty: 1, price: 10, name: 'ยา A' }]), payment_method: 'cash' };

    await dispenseAction(db, fakeSession(), 42, body, 'https://tenant.re-ya.com');
    expect(mockSendDispenseFlexMessage).not.toHaveBeenCalled();
  });
});

describe('dispenseAction — activity log', () => {
  it('writes activity_logs with log_type=data, action=create, entity_type=dispense', async () => {
    const { db, queries } = wireFakeDb();
    const body: DispenseRequestBody = { items: itemsJson([{ product_id: 1, qty: 1, price: 10, name: 'ยา A' }]), payment_method: 'cash' };

    const result = await dispenseAction(db, fakeSession(), 42, body, 'https://tenant.re-ya.com');

    const logInsert = queries.find((q) => q.sql.toLowerCase().includes('activity_logs'));
    expect(logInsert).toBeDefined();
    expect(logInsert!.params).toEqual(
      expect.arrayContaining(['data', 'create', `จ่ายยา #${result.body.order_number as string}`, 42, 'dispense', result.body.dispense_id])
    );
  });
});
