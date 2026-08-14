import { makeFakeTenantDb } from './testHelpers/fakeTenantDb';
import {
  getOrderDetail,
  getOrderItems,
  getPaymentSlips,
  getShopAccounts,
  getOrderDetailPageData,
  repairSlipUrlScheme,
  buildSlipImageSrc,
} from './queries';

describe('getOrderDetail', () => {
  it('scopes to the order id AND (line_account_id = currentBotId OR line_account_id IS NULL)', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      {
        id: 42,
        orderNumber: 'ORD-42',
        createdAt: new Date('2026-08-01T00:00:00Z'),
        status: 'pending',
        paymentStatus: 'pending',
        paymentMethod: 'transfer',
        totalAmount: '500.00',
        shippingFee: '0.00',
        discountAmount: '0.00',
        grandTotal: '500.00',
        deliveryInfo: null,
        shippingName: null,
        shippingPhone: null,
        shippingAddress: null,
        shippingTracking: null,
        note: null,
        transactionType: 'purchase',
        userId: 9,
        displayName: 'ลูกค้า A',
        pictureUrl: null,
        lineUserId: 'U1',
      },
    ]);

    const row = await getOrderDetail(db, 42, 7);
    expect(row?.orderNumber).toBe('ORD-42');

    const q = queries[0]!;
    expect(q.sql).toMatch(/from transactions o/i);
    expect(q.sql).toMatch(/join users u on o\.user_id = u\.id/i);
    expect(q.sql).toMatch(/o\.line_account_id = \? or o\.line_account_id is null/i);
    expect(q.params).toContain(42);
    expect(q.params).toContain(7);
  });

  it('returns null when no row matches', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const row = await getOrderDetail(db, 999, 7);
    expect(row).toBeNull();
  });
});

describe('getOrderItems', () => {
  it('queries transaction_items by transaction_id', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      { id: 1, productName: 'พาราเซตามอล', productPrice: '20.00', quantity: 2, subtotal: '40.00' },
    ]);
    const rows = await getOrderItems(db, 42);
    expect(rows).toHaveLength(1);
    expect(queries[0]!.sql).toMatch(/from transaction_items where transaction_id = \?/i);
    expect(queries[0]!.params).toEqual([42]);
  });
});

describe('URL rewrite helpers', () => {
  it('repairSlipUrlScheme fixes "https:/host" -> "https://host"', () => {
    expect(repairSlipUrlScheme('https:/example.com/uploads/slips/a.jpg')).toBe('https://example.com/uploads/slips/a.jpg');
    expect(repairSlipUrlScheme('http:/example.com/a.jpg')).toBe('http://example.com/a.jpg');
    // Already-correct URL is left untouched.
    expect(repairSlipUrlScheme('https://example.com/a.jpg')).toBe('https://example.com/a.jpg');
  });

  it('buildSlipImageSrc rewrites to a same-origin /uploads/slips/<basename> path', () => {
    expect(buildSlipImageSrc('https://wrong-host.example/uploads/slips/slip-123.png')).toBe('/uploads/slips/slip-123.png');
    // A literal space in the basename is rawurlencode()'d (%20), matching PHP's rawurlencode().
    expect(buildSlipImageSrc('https://example.com/uploads/slips/slip 1.jpg')).toBe('/uploads/slips/slip%201.jpg');
  });

  it('buildSlipImageSrc falls back to the original string when no basename can be extracted', () => {
    expect(buildSlipImageSrc('')).toBe('');
  });
});

describe('getPaymentSlips', () => {
  it('orders by created_at DESC and applies both URL transforms', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      {
        id: 1,
        status: 'pending',
        adminNote: null,
        createdAt: new Date('2026-08-01T00:00:00Z'),
        amount: '500.00',
        imageUrl: 'https:/example.com/uploads/slips/slip1.jpg', // malformed scheme (single slash)
        verifyRef: null,
        verifyAmount: null,
        verifyData: null,
        verifiedAt: null,
        qrPayload: null,
      },
    ]);

    const rows = await getPaymentSlips(db, 42);
    expect(rows).toHaveLength(1);
    expect(rows[0]!.imageUrl).toBe('https://example.com/uploads/slips/slip1.jpg');
    expect(rows[0]!.imageSrc).toBe('/uploads/slips/slip1.jpg');

    expect(queries[0]!.sql).toMatch(/from payment_slips where transaction_id = \? order by created_at desc/i);
    expect(queries[0]!.sql).toMatch(/verify_ref as verifyRef/i);
    expect(queries[0]!.sql).toMatch(/qr_payload as qrPayload/i);
  });

  it('returns an empty array when there are no slips', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const rows = await getPaymentSlips(db, 42);
    expect(rows).toEqual([]);
  });
});

describe('getShopAccounts', () => {
  it('collects the PromptPay number plus every bank account number', async () => {
    const { db } = makeFakeTenantDb(() => [
      {
        promptpayNumber: '0812345678',
        bankAccounts: JSON.stringify([{ account_number: '111-1-11111-1' }, { account_number: '222-2-22222-2' }]),
      },
    ]);
    const accounts = await getShopAccounts(db, 7);
    expect(accounts).toEqual(['0812345678', '111-1-11111-1', '222-2-22222-2']);
  });

  it('returns an empty array when shop_settings has no row for this line_account_id', async () => {
    const { db } = makeFakeTenantDb(() => []);
    expect(await getShopAccounts(db, 7)).toEqual([]);
  });

  it('skips a malformed bank_accounts JSON payload without throwing', async () => {
    const { db } = makeFakeTenantDb(() => [{ promptpayNumber: '0812345678', bankAccounts: 'not json' }]);
    expect(await getShopAccounts(db, 7)).toEqual(['0812345678']);
  });

  it('handles an empty promptpay_number + empty bank_accounts', async () => {
    const { db } = makeFakeTenantDb(() => [{ promptpayNumber: null, bankAccounts: null }]);
    expect(await getShopAccounts(db, 7)).toEqual([]);
  });
});

describe('getOrderDetailPageData', () => {
  it('returns null when the order is not found (mirrors redirect to orders list)', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const data = await getOrderDetailPageData(db, 999, 7);
    expect(data).toBeNull();
  });

  it('assembles order + items + slips + shopAccounts together', async () => {
    const { db, setQueryImpl } = makeFakeTenantDb();
    setQueryImpl((sqlTextRaw) => {
      const sqlText = sqlTextRaw.toLowerCase();
      if (sqlText.includes('from transactions o')) {
        return [
          {
            id: 42,
            orderNumber: 'ORD-42',
            createdAt: new Date(),
            status: 'pending',
            paymentStatus: 'pending',
            paymentMethod: 'transfer',
            totalAmount: '500.00',
            shippingFee: '0.00',
            discountAmount: '0.00',
            grandTotal: '500.00',
            deliveryInfo: null,
            shippingName: null,
            shippingPhone: null,
            shippingAddress: null,
            shippingTracking: null,
            note: null,
            transactionType: 'purchase',
            userId: 9,
            displayName: 'ลูกค้า A',
            pictureUrl: null,
            lineUserId: 'U1',
          },
        ];
      }
      if (sqlText.includes('from transaction_items')) {
        return [{ id: 1, productName: 'สินค้า', productPrice: '10.00', quantity: 1, subtotal: '10.00' }];
      }
      if (sqlText.includes('from payment_slips')) {
        return [];
      }
      if (sqlText.includes('from shop_settings')) {
        return [{ promptpayNumber: '0812345678', bankAccounts: null }];
      }
      return [];
    });

    const data = await getOrderDetailPageData(db, 42, 7);
    expect(data?.order.orderNumber).toBe('ORD-42');
    expect(data?.items).toHaveLength(1);
    expect(data?.slips).toEqual([]);
    expect(data?.shopAccounts).toEqual(['0812345678']);
  });
});
