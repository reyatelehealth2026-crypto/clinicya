import {
  isMedicineItem,
  medicineIcon,
  frequencyText,
  mealTimingText,
  timeOfDayIcons,
  paymentMethodText,
  itemSubtotal,
  parseDispenseItems,
} from './dispenseItem';

describe('isMedicineItem / medicineIcon', () => {
  it('is false (📦) when isMedicine is absent/false', () => {
    expect(isMedicineItem({ name: 'x' })).toBe(false);
    expect(medicineIcon({ name: 'x' })).toBe('📦');
    expect(medicineIcon({ name: 'x', isMedicine: false })).toBe('📦');
  });

  it('is 💊 for an internal medicine (default usageType)', () => {
    expect(medicineIcon({ name: 'x', isMedicine: true })).toBe('💊');
    expect(medicineIcon({ name: 'x', isMedicine: true, usageType: 'internal' })).toBe('💊');
  });

  it('is 🧴 for an external medicine', () => {
    expect(medicineIcon({ name: 'x', isMedicine: true, usageType: 'external' })).toBe('🧴');
  });
});

describe('frequencyText', () => {
  it('defaults frequency to "3" -> "3 ครั้ง/วัน"', () => {
    expect(frequencyText({ name: 'x' })).toBe('3 ครั้ง/วัน');
  });
  it('renders "เมื่อมีอาการ" for frequency "prn"', () => {
    expect(frequencyText({ name: 'x', frequency: 'prn' })).toBe('เมื่อมีอาการ');
  });
  it('renders "<n> ครั้ง/วัน" for any other frequency value', () => {
    expect(frequencyText({ name: 'x', frequency: '2' })).toBe('2 ครั้ง/วัน');
  });
});

describe('mealTimingText', () => {
  it('defaults to "หลังอาหาร" (after) when mealTiming is absent', () => {
    expect(mealTimingText({ name: 'x' })).toBe('หลังอาหาร');
  });
  it('maps before/after/with', () => {
    expect(mealTimingText({ name: 'x', mealTiming: 'before' })).toBe('ก่อนอาหาร');
    expect(mealTimingText({ name: 'x', mealTiming: 'with' })).toBe('พร้อมอาหาร');
  });
  it('falls back to "หลังอาหาร" for an unknown mealTiming value', () => {
    expect(mealTimingText({ name: 'x', mealTiming: 'midnight' })).toBe('หลังอาหาร');
  });
});

describe('timeOfDayIcons', () => {
  it('is empty when timeOfDay is absent or empty', () => {
    expect(timeOfDayIcons({ name: 'x' })).toBe('');
    expect(timeOfDayIcons({ name: 'x', timeOfDay: [] })).toBe('');
  });
  it('joins known icons with a space, unknown entries as empty string', () => {
    expect(timeOfDayIcons({ name: 'x', timeOfDay: ['morning', 'bedtime'] })).toBe('🌅 🌙');
    expect(timeOfDayIcons({ name: 'x', timeOfDay: ['morning', 'unknown'] })).toBe('🌅 ');
  });
});

describe('paymentMethodText', () => {
  it('maps known payment methods', () => {
    expect(paymentMethodText('cash')).toBe('💵 เงินสด');
    expect(paymentMethodText('transfer')).toBe('📱 โอนเงิน');
    expect(paymentMethodText('credit')).toBe('💳 บัตรเครดิต');
    expect(paymentMethodText('later')).toBe('⏰ จ่ายทีหลัง');
  });
  it('falls back to the raw value for an unknown method', () => {
    expect(paymentMethodText('crypto')).toBe('crypto');
  });
  it('is empty for null', () => {
    expect(paymentMethodText(null)).toBe('');
  });
});

describe('itemSubtotal', () => {
  it('multiplies price * qty, defaulting price to 0 and qty to 1', () => {
    expect(itemSubtotal({ name: 'x', price: 50, qty: 3 })).toBe(150);
    expect(itemSubtotal({ name: 'x', qty: 3 })).toBe(0); // no price -> 0
    expect(itemSubtotal({ name: 'x', price: 50 })).toBe(50); // no qty -> defaults to 1
  });
});

describe('parseDispenseItems', () => {
  it('parses a JSON array of items', () => {
    const items = parseDispenseItems(JSON.stringify([{ name: 'a' }, { name: 'b' }]));
    expect(items).toHaveLength(2);
  });
  it('returns [] for null/empty/malformed JSON', () => {
    expect(parseDispenseItems(null)).toEqual([]);
    expect(parseDispenseItems('')).toEqual([]);
    expect(parseDispenseItems('not-json')).toEqual([]);
  });
  it('returns [] for a non-array JSON value (defensive normalization, not a literal PHP-quirk port)', () => {
    expect(parseDispenseItems(JSON.stringify({ a: 1 }))).toEqual([]);
  });
});
