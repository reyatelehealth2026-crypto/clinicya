import {
  TRANSACTION_TYPES,
  ORDER_STATUSES,
  ORDER_STATUS_KEYS,
  STATUS_FILTER_ACTIVE_CLASS,
  STATUS_BADGE_CLASS,
  isKnownOrderStatus,
} from './constants';

describe('TRANSACTION_TYPES', () => {
  it('has exactly the four keys/icons/labels from shop/orders.php lines 429-434', () => {
    expect(TRANSACTION_TYPES).toEqual({
      purchase: { icon: '🛒', label: 'ซื้อสินค้า' },
      booking: { icon: '📅', label: 'จองคิว' },
      subscription: { icon: '🔄', label: 'สมัครสมาชิก' },
      redemption: { icon: '🎁', label: 'แลกของรางวัล' },
    });
  });
});

describe('ORDER_STATUSES', () => {
  it('has exactly the six status keys/labels from shop/orders.php lines 471-478, in PHP declaration order', () => {
    expect(ORDER_STATUS_KEYS).toEqual(['pending', 'confirmed', 'paid', 'shipping', 'delivered', 'cancelled']);
    expect(ORDER_STATUSES).toEqual({
      pending: { label: 'รอยืนยัน' },
      confirmed: { label: 'ยืนยันแล้ว' },
      paid: { label: 'ชำระแล้ว' },
      shipping: { label: 'กำลังส่ง' },
      delivered: { label: 'ส่งแล้ว' },
      cancelled: { label: 'ยกเลิก' },
    });
  });
});

describe('STATUS_FILTER_ACTIVE_CLASS / STATUS_BADGE_CLASS', () => {
  it('defines a class string for every status key, no more, no less', () => {
    for (const key of ORDER_STATUS_KEYS) {
      expect(typeof STATUS_FILTER_ACTIVE_CLASS[key]).toBe('string');
      expect(typeof STATUS_BADGE_CLASS[key]).toBe('string');
    }
    expect(Object.keys(STATUS_FILTER_ACTIVE_CLASS).sort()).toEqual([...ORDER_STATUS_KEYS].sort());
    expect(Object.keys(STATUS_BADGE_CLASS).sort()).toEqual([...ORDER_STATUS_KEYS].sort());
  });
});

describe('isKnownOrderStatus', () => {
  it('is true for every declared status key', () => {
    for (const key of ORDER_STATUS_KEYS) {
      expect(isKnownOrderStatus(key)).toBe(true);
    }
  });

  it('is false for null/undefined/unknown values', () => {
    expect(isKnownOrderStatus(null)).toBe(false);
    expect(isKnownOrderStatus(undefined)).toBe(false);
    expect(isKnownOrderStatus('refunded')).toBe(false);
    expect(isKnownOrderStatus('')).toBe(false);
  });
});
