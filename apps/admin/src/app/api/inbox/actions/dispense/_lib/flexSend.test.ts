/**
 * @jest-environment node
 */
import { makeFakeTenantDb, type RecordedQuery } from './testHelpers/fakeTenantDb';

const mockMedicineLabel = jest.fn();
const mockMedicineLabelsCarousel = jest.fn();
const mockToMessage = jest.fn();
const mockLineSendMessage = jest.fn();
jest.mock('@reya/line', () => ({
  medicineLabel: (...args: unknown[]) => mockMedicineLabel(...args),
  medicineLabelsCarousel: (...args: unknown[]) => mockMedicineLabelsCarousel(...args),
  toMessage: (...args: unknown[]) => mockToMessage(...args),
  sendMessage: (...args: unknown[]) => mockLineSendMessage(...args),
}));

import { sendDispenseFlexMessage, type DispenseFlexUser } from './flexSend';
import type { DispenseItem } from './types';

const FLEX_BUBBLE = { type: 'bubble' } as const;
const FLEX_CAROUSEL = { type: 'carousel', contents: [] } as const;
const FLEX_ENVELOPE = { type: 'flex', altText: 'alt', contents: FLEX_BUBBLE };

const USER: DispenseFlexUser = {
  line_user_id: 'Uabc123',
  line_account_id: 9,
  display_name: 'สมชาย',
  reply_token: 'replytok',
  reply_token_expires_str: '2026-07-14 12:00:00',
};

const SHOP_INFO = { name: 'ร้านยา', address: '', phone: '', logo: '', open_hours: '08:00-24:00 น.', pharmacist: '' };

interface DbConfig {
  channelAccessToken?: string | null;
  liffAppRow?: { liff_id: string } | null;
  lineAccountLinkRow?: { id: number; liff_id: string | null; basic_id: string | null; name: string | null } | null;
  businessItemImage?: string | null;
  hasSentByRowCount?: number;
}

function wireDb(config: DbConfig = {}): { queries: RecordedQuery[]; db: ReturnType<typeof makeFakeTenantDb>['db'] } {
  const { db, queries } = makeFakeTenantDb((sqlText) => {
    const lower = sqlText.toLowerCase();

    if (lower.includes('select channel_access_token')) {
      return config.channelAccessToken === undefined || config.channelAccessToken === null
        ? []
        : [{ channel_access_token: config.channelAccessToken }];
    }
    if (lower.includes('from liff_apps')) {
      return config.liffAppRow === undefined || config.liffAppRow === null ? [] : [config.liffAppRow];
    }
    if (lower.includes('select id, liff_id, basic_id, name')) {
      return config.lineAccountLinkRow === undefined || config.lineAccountLinkRow === null ? [] : [config.lineAccountLinkRow];
    }
    if (lower.includes('select image_url from business_items')) {
      return config.businessItemImage === undefined || config.businessItemImage === null ? [] : [{ image_url: config.businessItemImage }];
    }
    if (lower.includes("show columns from messages like 'sent_by'")) {
      const count = config.hasSentByRowCount ?? 1;
      return Array.from({ length: count }, () => ({ Field: 'sent_by' }));
    }
    if (lower.includes('insert into')) {
      return { insertId: 1, affectedRows: 1 };
    }
    if (lower.includes('update')) {
      return { insertId: 0, affectedRows: 1 };
    }
    return [];
  });
  return { queries, db };
}

const DEFAULT_CONFIG: DbConfig = {
  channelAccessToken: 'token-abc',
  liffAppRow: null,
  lineAccountLinkRow: { id: 9, liff_id: null, basic_id: null, name: 'Shop' },
  hasSentByRowCount: 1,
};

beforeEach(() => {
  jest.clearAllMocks();
  mockToMessage.mockReturnValue(FLEX_ENVELOPE);
  mockMedicineLabel.mockReturnValue(FLEX_BUBBLE);
  mockMedicineLabelsCarousel.mockReturnValue(FLEX_CAROUSEL);
  mockLineSendMessage.mockResolvedValue({ code: 200, method: 'push', body: {} });
});

function itemFixture(overrides: Partial<DispenseItem> = {}): DispenseItem {
  return { product_id: 1, qty: 2, price: 10, name: 'ยา A', ...overrides };
}

describe('sendDispenseFlexMessage — no channel_access_token', () => {
  it('is a no-op (best-effort skip) when line_accounts has no matching row', async () => {
    const { db } = wireDb({ ...DEFAULT_CONFIG, channelAccessToken: null });
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [itemFixture()],
      shopInfo: SHOP_INFO,
      paymentMethod: 'cash',
      orderNumber: 'DIS260714153045100',
      transactionId: 1,
    });
    expect(mockLineSendMessage).not.toHaveBeenCalled();
  });
});

describe('sendDispenseFlexMessage — medicineLabel vs medicineLabelsCarousel dispatch', () => {
  it('single item -> medicineLabel(), not medicineLabelsCarousel(), passed to toMessage()', async () => {
    const { db } = wireDb(DEFAULT_CONFIG);
    const item = itemFixture();
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [item],
      shopInfo: SHOP_INFO,
      paymentMethod: 'cash',
      orderNumber: 'DIS260714153045100',
      transactionId: 1,
    });

    expect(mockMedicineLabel).toHaveBeenCalledTimes(1);
    expect(mockMedicineLabelsCarousel).not.toHaveBeenCalled();
    const [calledItem, calledShopInfo, calledPatientName, calledCheckoutUrl] = mockMedicineLabel.mock.calls[0];
    expect(calledItem).toBe(item);
    expect(calledShopInfo).toEqual(SHOP_INFO);
    expect(calledPatientName).toBe('สมชาย');
    expect(calledCheckoutUrl).toBeNull();

    expect(mockToMessage).toHaveBeenCalledWith(FLEX_BUBBLE, '💊 รายการจ่ายยา #DIS260714153045100');
    expect(mockLineSendMessage).toHaveBeenCalledTimes(1);
  });

  it('multi-item (length > 1) -> medicineLabelsCarousel(), not medicineLabel(), passed to toMessage()', async () => {
    const { db } = wireDb(DEFAULT_CONFIG);
    const items = [itemFixture({ product_id: 1 }), itemFixture({ product_id: 2 })];
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: items,
      shopInfo: SHOP_INFO,
      paymentMethod: 'cash',
      orderNumber: 'DIS260714153045100',
      transactionId: 1,
    });

    expect(mockMedicineLabelsCarousel).toHaveBeenCalledTimes(1);
    expect(mockMedicineLabel).not.toHaveBeenCalled();
    const [calledItems, calledShopInfo, calledPatientName] = mockMedicineLabelsCarousel.mock.calls[0];
    expect(calledItems).toBe(items);
    expect(calledShopInfo).toEqual(SHOP_INFO);
    expect(calledPatientName).toBe('สมชาย');

    expect(mockToMessage).toHaveBeenCalledWith(FLEX_CAROUSEL, '💊 รายการจ่ายยา #DIS260714153045100');
  });
});

describe('sendDispenseFlexMessage — checkoutUrl only passed non-null for later/transfer', () => {
  it.each(['cash'])('payment_method=%s -> 4th Flex-builder arg is null even though a real checkoutUrl resolves', async (paymentMethod) => {
    const { db } = wireDb({ ...DEFAULT_CONFIG, lineAccountLinkRow: { id: 9, liff_id: '1234-real', basic_id: null, name: 'Shop' } });
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [itemFixture()],
      shopInfo: SHOP_INFO,
      paymentMethod,
      orderNumber: 'DIS260714153045100',
      transactionId: 1,
    });
    expect(mockMedicineLabel.mock.calls[0][3]).toBeNull();
  });

  it.each(['later', 'transfer'])('payment_method=%s -> 4th Flex-builder arg is the resolved checkoutUrl string', async (paymentMethod) => {
    const { db } = wireDb({ ...DEFAULT_CONFIG, lineAccountLinkRow: { id: 9, liff_id: '1234-real', basic_id: null, name: 'Shop' } });
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [itemFixture()],
      shopInfo: SHOP_INFO,
      paymentMethod,
      orderNumber: 'DIS260714153045100',
      transactionId: 1,
    });
    const checkoutUrl = mockMedicineLabel.mock.calls[0][3] as string;
    expect(typeof checkoutUrl).toBe('string');
    expect(checkoutUrl.length).toBeGreaterThan(0);
  });
});

describe('sendDispenseFlexMessage — checkout-URL fallback order', () => {
  it('(a) an active liff_apps row with a real liff_id wins over line_accounts.liff_id', async () => {
    const { db } = wireDb({
      ...DEFAULT_CONFIG,
      liffAppRow: { liff_id: 'liffapp-real' },
      lineAccountLinkRow: { id: 9, liff_id: 'linegaccount-real', basic_id: '@shop', name: 'Shop' },
    });
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [itemFixture()],
      shopInfo: SHOP_INFO,
      paymentMethod: 'later',
      orderNumber: 'DIS260714153045100',
      transactionId: 7,
    });
    const checkoutUrl = mockMedicineLabel.mock.calls[0][3] as string;
    // deepLink is '/order?id=7' (already has a '?'), so the liff_apps branch's own separator
    // logic picks '&', not '?' — matches PHP's `$sep = (strpos($deepLink, '?') !== false) ? '&' : '?';`.
    expect(checkoutUrl).toBe('https://liff.line.me/liffapp-real/order?id=7&la=9');
  });

  it('(b) no matching liff_apps row falls back to line_accounts.liff_id when real', async () => {
    const { db } = wireDb({
      ...DEFAULT_CONFIG,
      liffAppRow: null,
      lineAccountLinkRow: { id: 9, liff_id: 'linegaccount-real', basic_id: '@shop', name: 'Shop' },
    });
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [itemFixture()],
      shopInfo: SHOP_INFO,
      paymentMethod: 'later',
      orderNumber: 'DIS260714153045100',
      transactionId: 7,
    });
    const checkoutUrl = mockMedicineLabel.mock.calls[0][3] as string;
    expect(checkoutUrl).toBe('https://liff.line.me/linegaccount-real/order?id=7&la=9&liff_id=linegaccount-real');
  });

  it('(c) neither liff_apps nor line_accounts.liff_id real -> falls back to the OA chat URL from basic_id', async () => {
    const { db } = wireDb({
      ...DEFAULT_CONFIG,
      liffAppRow: null,
      lineAccountLinkRow: { id: 9, liff_id: null, basic_id: '@shop', name: 'Shop' },
    });
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [itemFixture()],
      shopInfo: SHOP_INFO,
      paymentMethod: 'later',
      orderNumber: 'DIS260714153045100',
      transactionId: 7,
    });
    const checkoutUrl = mockMedicineLabel.mock.calls[0][3] as string;
    expect(checkoutUrl).toBe('https://line.me/R/ti/p/%40shop');
  });

  it('(d) none of the three available -> empty string is passed (needCheckout still true for later/transfer)', async () => {
    const { db } = wireDb({
      ...DEFAULT_CONFIG,
      liffAppRow: null,
      lineAccountLinkRow: { id: 9, liff_id: null, basic_id: null, name: 'Shop' },
    });
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [itemFixture()],
      shopInfo: SHOP_INFO,
      paymentMethod: 'later',
      orderNumber: 'DIS260714153045100',
      transactionId: 7,
    });
    expect(mockMedicineLabel.mock.calls[0][3]).toBe('');
  });
});

describe('sendDispenseFlexMessage — messages insert SHOW COLUMNS branching', () => {
  it("rowCount > 0 -> INSERT includes sent_by = 'system:dispense'", async () => {
    const { db, queries } = wireDb({ ...DEFAULT_CONFIG, hasSentByRowCount: 1 });
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [itemFixture()],
      shopInfo: SHOP_INFO,
      paymentMethod: 'cash',
      orderNumber: 'DIS260714153045100',
      transactionId: 1,
    });
    const messagesInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into `messages`') || q.sql.toLowerCase().includes('insert into messages'));
    expect(messagesInsert).toBeDefined();
    expect(messagesInsert!.params).toContain('system:dispense');
  });

  it('rowCount = 0 -> INSERT omits sent_by entirely', async () => {
    const { db, queries } = wireDb({ ...DEFAULT_CONFIG, hasSentByRowCount: 0 });
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [itemFixture()],
      shopInfo: SHOP_INFO,
      paymentMethod: 'cash',
      orderNumber: 'DIS260714153045100',
      transactionId: 1,
    });
    const messagesInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into `messages`') || q.sql.toLowerCase().includes('insert into messages'));
    expect(messagesInsert).toBeDefined();
    expect(messagesInsert!.params).not.toContain('system:dispense');
    expect(messagesInsert!.sql.toLowerCase()).not.toContain('sent_by');
  });
});

describe('sendDispenseFlexMessage — business_items image hydration', () => {
  it('fills item.image from business_items when the item arrives without one', async () => {
    const { db } = wireDb({ ...DEFAULT_CONFIG, businessItemImage: 'https://example.com/pic.png' });
    const item = itemFixture({ image: undefined });
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [item],
      shopInfo: SHOP_INFO,
      paymentMethod: 'cash',
      orderNumber: 'DIS260714153045100',
      transactionId: 1,
    });
    expect(item.image).toBe('https://example.com/pic.png');
  });

  it('leaves item.image untouched when the item already has one', async () => {
    const { db } = wireDb({ ...DEFAULT_CONFIG, businessItemImage: 'https://example.com/should-not-be-used.png' });
    const item = itemFixture({ image: 'https://example.com/already-set.png' });
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [item],
      shopInfo: SHOP_INFO,
      paymentMethod: 'cash',
      orderNumber: 'DIS260714153045100',
      transactionId: 1,
    });
    expect(item.image).toBe('https://example.com/already-set.png');
  });
});

describe('sendDispenseFlexMessage — sendMessage() call shape', () => {
  it('calls @reya/line sendMessage with reply-token-first params + channel token', async () => {
    const { db } = wireDb(DEFAULT_CONFIG);
    await sendDispenseFlexMessage({
      db,
      userId: 42,
      user: USER,
      itemsArr: [itemFixture()],
      shopInfo: SHOP_INFO,
      paymentMethod: 'cash',
      orderNumber: 'DIS260714153045100',
      transactionId: 1,
    });

    expect(mockLineSendMessage).toHaveBeenCalledTimes(1);
    const [params, options] = mockLineSendMessage.mock.calls[0] as [Record<string, unknown>, Record<string, unknown>];
    expect(params.userId).toBe('Uabc123');
    expect(params.messages).toEqual([FLEX_ENVELOPE]);
    expect(params.replyToken).toBe('replytok');
    expect(params.tokenExpires).toBe('2026-07-14 12:00:00');
    expect(params.internalUserId).toBe(42);
    expect(options).toEqual({ channelAccessToken: 'token-abc' });
  });
});
