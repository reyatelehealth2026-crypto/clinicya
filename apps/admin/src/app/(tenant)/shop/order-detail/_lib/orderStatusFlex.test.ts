jest.mock('@reya/line', () => {
  const actual = jest.requireActual('@reya/line');
  return {
    ...actual,
    sendMessage: jest.fn(),
  };
});

import { sendMessage as mockedSendMessage } from '@reya/line';
import { makeFakeTenantDb } from '../testHelpers/fakeTenantDb';
import {
  buildOrderStatusFlex,
  buildOrderRejectionFlex,
  sendOrderStatusFlex,
  sendOrderRejectionFlex,
  type OrderFlexInput,
  type OrderFlexItem,
} from './orderStatusFlex';

const mockSendMessage = mockedSendMessage as jest.MockedFunction<typeof mockedSendMessage>;

const BASE_ORDER: OrderFlexInput = {
  order_number: 'ORD-1001',
  delivery_info: JSON.stringify({ name: 'สมชาย ใจดี', phone: '0812345678', address: '123 ถนนสุขุมวิท' }),
  total_amount: 500,
  shipping_fee: 0,
  grand_total: 500,
};

const ITEMS: OrderFlexItem[] = [{ product_name: 'พาราเซตามอล', quantity: 2, subtotal: 40 }];

function bubbleBody(msg: ReturnType<typeof buildOrderStatusFlex>) {
  const bubble = msg.contents as { body: { contents: unknown[] } };
  return bubble.body.contents;
}

describe('buildOrderStatusFlex', () => {
  it('renders the pending status header (icon/label/color/message)', () => {
    const msg = buildOrderStatusFlex(BASE_ORDER, ITEMS, 'pending');
    expect(msg.type).toBe('flex');
    expect(msg.altText).toContain('รอยืนยัน');
    const body = bubbleBody(msg) as Array<{ contents?: Array<{ text?: string; color?: string }> }>;
    const headerRow = body[0]!.contents![0]!;
    expect(headerRow.text).toBe('⏳ รอยืนยัน');
    expect(headerRow.color).toBe('#F59E0B');
  });

  it('renders the confirmed status', () => {
    const msg = buildOrderStatusFlex(BASE_ORDER, ITEMS, 'confirmed');
    expect(msg.altText).toContain('ยืนยันแล้ว');
    const body = bubbleBody(msg) as Array<{ contents?: Array<{ text?: string; color?: string }> }>;
    expect(body[0]!.contents![0]!.text).toBe('✅ ยืนยันแล้ว');
    expect(body[0]!.contents![0]!.color).toBe('#3B82F6');
  });

  it('renders the paid status, colored green on the grand-total row', () => {
    const msg = buildOrderStatusFlex(BASE_ORDER, ITEMS, 'paid');
    expect(msg.altText).toContain('ชำระเงินแล้ว');
    const body = bubbleBody(msg) as Array<{ contents?: Array<{ color?: string }> }>;
    expect(body[0]!.contents![0]!.color).toBe('#10B981');
  });

  it('renders the shipping status WITH a tracking box when a tracking number is given', () => {
    const msg = buildOrderStatusFlex(BASE_ORDER, ITEMS, 'shipping', 'TH999888777');
    expect(msg.altText).toContain('กำลังจัดส่ง');
    const body = bubbleBody(msg) as Array<Record<string, unknown>>;
    // Tracking box is inserted right after the date line (index 4), before the item separator.
    const trackingBox = body[5] as { backgroundColor?: string; contents?: Array<{ text?: string }> };
    expect(trackingBox.backgroundColor).toBe('#F3E8FF');
    expect(trackingBox.contents?.[1]?.text).toBe('TH999888777');
  });

  it('renders the shipping status WITHOUT a tracking box when no tracking number is given', () => {
    const msg = buildOrderStatusFlex(BASE_ORDER, ITEMS, 'shipping', null);
    const body = bubbleBody(msg) as Array<{ backgroundColor?: string }>;
    expect(body.some((c) => c.backgroundColor === '#F3E8FF')).toBe(false);
  });

  it('renders the delivered status', () => {
    const msg = buildOrderStatusFlex(BASE_ORDER, ITEMS, 'delivered');
    expect(msg.altText).toContain('จัดส่งแล้ว');
    const body = bubbleBody(msg) as Array<{ contents?: Array<{ color?: string }> }>;
    expect(body[0]!.contents![0]!.color).toBe('#059669');
  });

  it('renders the cancelled status', () => {
    const msg = buildOrderStatusFlex(BASE_ORDER, ITEMS, 'cancelled');
    expect(msg.altText).toContain('ยกเลิก');
    const body = bubbleBody(msg) as Array<{ contents?: Array<{ color?: string }> }>;
    expect(body[0]!.contents![0]!.color).toBe('#EF4444');
  });

  it('falls back to the pending config for an unrecognized status', () => {
    const msg = buildOrderStatusFlex(BASE_ORDER, ITEMS, 'unknown_status');
    const body = bubbleBody(msg) as Array<{ contents?: Array<{ text?: string }> }>;
    expect(body[0]!.contents![0]!.text).toBe('⏳ รอยืนยัน');
  });

  it('shows "ฟรี!" for a zero shipping fee and a formatted amount otherwise', () => {
    const free = buildOrderStatusFlex(BASE_ORDER, ITEMS, 'pending');
    const freeBody = bubbleBody(free) as Array<{ contents?: Array<{ text?: string }> }>;
    const shippingRowFree = freeBody.find((c) => c.contents?.[0]?.text === 'ค่าจัดส่ง')!;
    expect(shippingRowFree.contents![1]!.text).toBe('ฟรี!');

    const paidShip = buildOrderStatusFlex({ ...BASE_ORDER, shipping_fee: 50 }, ITEMS, 'pending');
    const paidBody = bubbleBody(paidShip) as Array<{ contents?: Array<{ text?: string }> }>;
    const shippingRowPaid = paidBody.find((c) => c.contents?.[0]?.text === 'ค่าจัดส่ง')!;
    expect(shippingRowPaid.contents![1]!.text).toBe('฿50');
  });

  it('falls back to "ไม่ระบุที่อยู่" when delivery_info has no name/phone/address', () => {
    const msg = buildOrderStatusFlex({ ...BASE_ORDER, delivery_info: null }, ITEMS, 'pending');
    const body = bubbleBody(msg) as Array<{ text?: string }>;
    expect(body[body.length - 1]!.text).toBe('ไม่ระบุที่อยู่');
  });

  it('renders the "📅 " date line as dd/mm/yyyy HH:mm (PHP: date(\'d/m/Y H:i\')), matching Asia/Bangkok wall-clock time', () => {
    const msg = buildOrderStatusFlex(BASE_ORDER, ITEMS, 'pending');
    const body = bubbleBody(msg) as Array<{ text?: string }>;
    const dateLine = body.find((c) => typeof c.text === 'string' && c.text.startsWith('📅 '))!;
    expect(dateLine).toBeDefined();
    // dd/mm/yyyy HH:mm — zero-padded date AND time-of-day, computed in Asia/Bangkok
    // regardless of the test runner's own local timezone.
    expect(dateLine.text).toMatch(/^📅 \d{2}\/\d{2}\/\d{4} \d{2}:\d{2}$/);
  });
});

describe('buildOrderRejectionFlex — a separate bubble from buildOrderStatusFlex', () => {
  it('is a distinct "slip rejected" bubble, not a status-flow bubble', () => {
    const statusMsg = buildOrderStatusFlex(BASE_ORDER, ITEMS, 'pending');
    const rejectMsg = buildOrderRejectionFlex({ order_number: BASE_ORDER.order_number, grand_total: BASE_ORDER.grand_total });

    expect(rejectMsg.altText).toContain('หลักฐานการชำระเงินไม่ถูกต้อง');
    expect(rejectMsg.altText).not.toEqual(statusMsg.altText);

    const rejectBody = (rejectMsg.contents as { body: { contents: Array<{ text?: string; color?: string }> } }).body.contents;
    expect(rejectBody[0]!.text).toBe('❌ สลิปไม่ถูกต้อง');
    expect(rejectBody[0]!.color).toBe('#EF4444');

    // The status-flow bubble never renders this exact rejection headline.
    const statusBody = bubbleBody(statusMsg) as Array<{ text?: string }>;
    expect(statusBody.some((c) => c.text === '❌ สลิปไม่ถูกต้อง')).toBe(false);
  });
});

describe('sendOrderStatusFlex', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('returns false and never calls sendMessage when the order has no line_user_id', async () => {
    const { db } = makeFakeTenantDb(() => [{ order_number: 'ORD-1', delivery_info: null, total_amount: 100, shipping_fee: 0, grand_total: 100, line_user_id: null, reply_token: null, reply_token_expires_str: null }]);
    const ok = await sendOrderStatusFlex(db, 1, 42, 'paid');
    expect(ok).toBe(false);
    expect(mockSendMessage).not.toHaveBeenCalled();
  });

  it('returns false and never calls sendMessage when no line_accounts row matches currentBotId', async () => {
    const { db, setQueryImpl } = makeFakeTenantDb();
    let call = 0;
    setQueryImpl((sqlText) => {
      call++;
      if (sqlText.includes('FROM transactions o JOIN users u')) {
        return [{ order_number: 'ORD-1', delivery_info: null, total_amount: 100, shipping_fee: 0, grand_total: 100, line_user_id: 'U123', reply_token: null, reply_token_expires_str: null }];
      }
      if (sqlText.includes('FROM transaction_items')) {
        return [];
      }
      if (sqlText.includes('FROM line_accounts')) {
        return []; // no matching account
      }
      return [];
    });
    const ok = await sendOrderStatusFlex(db, 1, 42, 'paid');
    expect(ok).toBe(false);
    expect(mockSendMessage).not.toHaveBeenCalled();
    expect(call).toBeGreaterThan(0);
  });

  it('sends via @reya/line sendMessage() when a line_user_id + channel token both resolve', async () => {
    mockSendMessage.mockResolvedValue({ code: 200, body: {}, method: 'push' });
    const { db, setQueryImpl } = makeFakeTenantDb();
    setQueryImpl((sqlText) => {
      if (sqlText.includes('FROM transactions o JOIN users u')) {
        return [
          {
            order_number: 'ORD-1',
            delivery_info: null,
            total_amount: 100,
            shipping_fee: 0,
            grand_total: 100,
            line_user_id: 'U123',
            reply_token: null,
            reply_token_expires_str: null,
          },
        ];
      }
      if (sqlText.includes('FROM transaction_items')) {
        return [{ product_name: 'สินค้า A', quantity: 1, subtotal: 100 }];
      }
      if (sqlText.includes('FROM line_accounts')) {
        return [{ channel_access_token: 'TOKEN123' }];
      }
      return [];
    });

    const ok = await sendOrderStatusFlex(db, 7, 42, 'paid');
    expect(ok).toBe(true);
    expect(mockSendMessage).toHaveBeenCalledTimes(1);
    const [params, options] = mockSendMessage.mock.calls[0]!;
    expect(params.userId).toBe('U123');
    expect(options.channelAccessToken).toBe('TOKEN123');
  });
});

describe('sendOrderRejectionFlex', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('sends the rejection bubble (not buildOrderStatusFlex output) via sendMessage()', async () => {
    mockSendMessage.mockResolvedValue({ code: 200, body: {}, method: 'reply' });
    const { db, setQueryImpl } = makeFakeTenantDb();
    setQueryImpl((sqlText) => {
      if (sqlText.includes('FROM transactions o JOIN users u')) {
        return [{ order_number: 'ORD-9', grand_total: 250, line_user_id: 'U999', reply_token: 'rtok', reply_token_expires_str: null }];
      }
      if (sqlText.includes('FROM line_accounts')) {
        return [{ channel_access_token: 'TOKEN999' }];
      }
      return [];
    });

    const ok = await sendOrderRejectionFlex(db, 3, 99);
    expect(ok).toBe(true);
    expect(mockSendMessage).toHaveBeenCalledTimes(1);
    const [params] = mockSendMessage.mock.calls[0]!;
    const sentMessage = (params.messages as unknown[])[0] as { altText: string };
    expect(sentMessage.altText).toContain('หลักฐานการชำระเงินไม่ถูกต้อง');
  });
});
