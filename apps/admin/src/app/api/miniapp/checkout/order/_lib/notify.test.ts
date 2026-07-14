/**
 * @jest-environment node
 */
import type { TenantDB } from '@reya/db';
import { makeFakeKyselyDb, type QueryImpl } from '@/lib/miniapp/testHelpers/fakeKyselyDb';
import { buildFlexReceipt, notifyTelegramNewOrder, notifyTelegramPayment, sendReceiptMessage, type ReceiptOrderRow } from './notify';

function setup(queryImpl: QueryImpl) {
  return makeFakeKyselyDb<TenantDB>(queryImpl);
}

const baseOrder: ReceiptOrderRow = {
  id: 900,
  order_number: 'TXN202607140001',
  user_id: 42,
  total_amount: '100.00',
  shipping_fee: '40.00',
  grand_total: '140.00',
  delivery_info: JSON.stringify({ name: 'สมชาย', phone: '0812345678', address: '123 ถนนสุขุมวิท' }),
};

beforeEach(() => {
  (global as unknown as { fetch: jest.Mock }).fetch = jest.fn().mockResolvedValue({ status: 200 });
});

describe('buildFlexReceipt', () => {
  it('builds the bubble with item rows, address, and totals; omits hero without a slipUrl', () => {
    const bubble = buildFlexReceipt(
      baseOrder,
      [{ product_name: 'พาราเซตามอล', quantity: 2, subtotal: '40.00' }],
      { name: 'สมชาย', phone: '0812345678', address: '123 ถนนสุขุมวิท' },
      null
    );
    expect(bubble.hero).toBeUndefined();
    expect(bubble.body.contents[1].text).toBe('Order #TXN202607140001');
    const itemBox = bubble.body.contents[7];
    expect(itemBox.contents[0].contents[0].text).toBe('พาราเซตามอล');
    expect(itemBox.contents[0].contents[1].text).toBe('x2');
    expect(itemBox.contents[0].contents[2].text).toBe('฿40');
  });

  it('includes a hero image block when slipUrl is given', () => {
    const bubble = buildFlexReceipt(baseOrder, [], {}, 'https://example.com/slip.jpg');
    expect(bubble.hero).toMatchObject({ type: 'image', url: 'https://example.com/slip.jpg' });
  });

  it('shipping_fee=0 renders "ฟรี!" in green, not a ฿0 amount', () => {
    const bubble = buildFlexReceipt({ ...baseOrder, shipping_fee: 0 }, [], {}, null);
    const shippingRow = bubble.body.contents[10];
    expect(shippingRow.contents[1].text).toBe('ฟรี!');
    expect(shippingRow.contents[1].color).toBe('#1DB446');
  });

  it('falls back to "ไม่ระบุที่อยู่" when deliveryInfo has no name/phone/address', () => {
    const bubble = buildFlexReceipt(baseOrder, [], {}, null);
    const addrText = bubble.body.contents[4];
    expect(addrText.text).toBe('ไม่ระบุที่อยู่');
  });
});

describe('sendReceiptMessage', () => {
  it('silent no-op (false) when the user has no line_user_id/channel_access_token', async () => {
    const { db } = setup(() => []);
    const result = await sendReceiptMessage(db, baseOrder, 'https://example.com/slip.jpg');
    expect(result).toBe(false);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('pushes a LINE flex message and returns true on HTTP 200', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('FROM users u')) {
        return [{ line_user_id: 'U123', display_name: 'สมชาย', channel_access_token: 'token-abc' }];
      }
      if (sqlText.includes('FROM transaction_items')) {
        return [{ product_name: 'พาราเซตามอล', quantity: 2, subtotal: '40.00' }];
      }
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await sendReceiptMessage(db, baseOrder, 'https://example.com/slip.jpg');
    expect(result).toBe(true);
    expect(global.fetch).toHaveBeenCalledWith(
      'https://api.line.me/v2/bot/message/push',
      expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ Authorization: 'Bearer token-abc' }) })
    );
  });

  it('returns false (swallowed) when fetch throws', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('FROM users u')) return [{ line_user_id: 'U123', display_name: null, channel_access_token: 'token-abc' }];
      if (sqlText.includes('FROM transaction_items')) return [];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    (global as unknown as { fetch: jest.Mock }).fetch = jest.fn().mockRejectedValue(new Error('network down'));
    const result = await sendReceiptMessage(db, baseOrder, null);
    expect(result).toBe(false);
  });
});

describe('notifyTelegramNewOrder', () => {
  const input = {
    orderId: 900,
    orderNumber: 'TXN202607140001',
    total: 140,
    user: { display_name: 'สมชาย' },
    deliveryInfo: { phone: '0812345678', address: '123 ถนนสุขุมวิท' },
  };

  it('silent no-op (false) when telegram_settings is missing', async () => {
    const { db } = setup(() => []);
    const result = await notifyTelegramNewOrder(db, input);
    expect(result).toBe(false);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('silent no-op when is_enabled/bot_token/chat_id are missing', async () => {
    const { db } = setup((sqlText) =>
      sqlText.includes('telegram_settings') ? [{ bot_token: null, chat_id: '123', is_enabled: 1, notify_new_order: 1 }] : []
    );
    expect(await notifyTelegramNewOrder(db, input)).toBe(false);
  });

  it('silent no-op when notify_new_order is explicitly 0', async () => {
    const { db } = setup((sqlText) =>
      sqlText.includes('telegram_settings') ? [{ bot_token: 'bt', chat_id: 'cid', is_enabled: 1, notify_new_order: 0 }] : []
    );
    expect(await notifyTelegramNewOrder(db, input)).toBe(false);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('posts a urlencoded sendMessage and returns true when enabled', async () => {
    const { db } = setup((sqlText) => {
      if (sqlText.includes('telegram_settings')) return [{ bot_token: 'bt', chat_id: 'cid', is_enabled: 1, notify_new_order: 1 }];
      if (sqlText.includes('transaction_items')) return [{ product_name: 'พาราเซตามอล', quantity: 2, subtotal: '40.00' }];
      throw new Error(`unexpected query: ${sqlText}`);
    });
    const result = await notifyTelegramNewOrder(db, input);
    expect(result).toBe(true);
    const [url, opts] = (global.fetch as jest.Mock).mock.calls[0];
    expect(url).toBe('https://api.telegram.org/botbt/sendMessage');
    expect(opts.body).toBeInstanceOf(URLSearchParams);
    expect((opts.body as URLSearchParams).get('chat_id')).toBe('cid');
    expect((opts.body as URLSearchParams).get('disable_web_page_preview')).toBe('1');
  });
});

describe('notifyTelegramPayment', () => {
  it('silent no-op (false) when notify_payment is explicitly 0', async () => {
    const { db } = setup((sqlText) =>
      sqlText.includes('telegram_settings') ? [{ bot_token: 'bt', chat_id: 'cid', is_enabled: 1, notify_payment: 0 }] : []
    );
    const result = await notifyTelegramPayment(db, 900, 'TXN202607140001', 'https://example.com/slip.jpg', { display_name: 'สมชาย' });
    expect(result).toBe(false);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('posts a multipart sendPhoto (FormData body) and returns true when enabled', async () => {
    const { db } = setup((sqlText) =>
      sqlText.includes('telegram_settings') ? [{ bot_token: 'bt', chat_id: 'cid', is_enabled: 1, notify_payment: 1 }] : []
    );
    const result = await notifyTelegramPayment(db, 900, 'TXN202607140001', 'https://example.com/slip.jpg', { display_name: 'สมชาย' });
    expect(result).toBe(true);
    const [url, opts] = (global.fetch as jest.Mock).mock.calls[0];
    expect(url).toBe('https://api.telegram.org/botbt/sendPhoto');
    expect(opts.body).toBeInstanceOf(FormData);
    expect((opts.body as FormData).get('photo')).toBe('https://example.com/slip.jpg');
  });
});
