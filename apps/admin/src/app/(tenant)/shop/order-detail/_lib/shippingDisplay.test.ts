import { computeShippingDisplay } from './shippingDisplay';

describe('computeShippingDisplay', () => {
  it('prefers order.shipping_* columns over delivery_info when the column is non-null', () => {
    const d = computeShippingDisplay({
      shippingName: 'ผู้รับตัวจริง',
      shippingPhone: '0899999999',
      shippingAddress: '999 ถนนจริง',
      deliveryInfo: JSON.stringify({ name: 'ผู้รับ LIFF', phone: '0811111111', full_address: '111 ถนน LIFF' }),
    });
    expect(d.shippingName).toBe('ผู้รับตัวจริง');
    expect(d.shippingPhone).toBe('0899999999');
    expect(d.shippingAddress).toBe('999 ถนนจริง');
    // The LIFF box still shows the RAW LIFF values regardless.
    expect(d.liffName).toBe('ผู้รับ LIFF');
    expect(d.liffAddress).toBe('111 ถนน LIFF');
  });

  it('falls back to delivery_info fields when the order column is null', () => {
    const d = computeShippingDisplay({
      shippingName: null,
      shippingPhone: null,
      shippingAddress: null,
      deliveryInfo: JSON.stringify({ name: 'ผู้รับ LIFF', phone: '0811111111', full_address: '111 ถนน LIFF' }),
    });
    expect(d.shippingName).toBe('ผู้รับ LIFF');
    expect(d.shippingPhone).toBe('0811111111');
    expect(d.shippingAddress).toBe('111 ถนน LIFF');
  });

  it('does NOT fall back when the order column is an empty string (only null triggers ??)', () => {
    const d = computeShippingDisplay({
      shippingName: '',
      shippingPhone: null,
      shippingAddress: null,
      deliveryInfo: JSON.stringify({ name: 'ผู้รับ LIFF' }),
    });
    expect(d.shippingName).toBe('');
  });

  it('builds liffAddress from parts when full_address is absent', () => {
    const d = computeShippingDisplay({
      shippingName: null,
      shippingPhone: null,
      shippingAddress: null,
      deliveryInfo: JSON.stringify({ address: '123 หมู่ 4', subdistrict: 'บางนา', district: 'บางนา', province: 'กรุงเทพ', postcode: '10260' }),
    });
    expect(d.liffAddress).toBe('123 หมู่ 4 บางนา บางนา กรุงเทพ 10260');
    expect(d.shippingAddress).toBe('123 หมู่ 4 บางนา บางนา กรุงเทพ 10260');
  });

  it('skips empty parts when building liffAddress from parts', () => {
    const d = computeShippingDisplay({
      shippingName: null,
      shippingPhone: null,
      shippingAddress: null,
      deliveryInfo: JSON.stringify({ address: '123 หมู่ 4', district: 'บางนา' }),
    });
    expect(d.liffAddress).toBe('123 หมู่ 4 บางนา');
  });

  it('returns empty strings for everything when delivery_info is null and columns are null', () => {
    const d = computeShippingDisplay({ shippingName: null, shippingPhone: null, shippingAddress: null, deliveryInfo: null });
    expect(d).toEqual({ liffName: '', liffPhone: '', liffAddress: '', shippingName: '', shippingPhone: '', shippingAddress: '' });
  });

  it('degrades gracefully on malformed delivery_info JSON', () => {
    const d = computeShippingDisplay({ shippingName: null, shippingPhone: null, shippingAddress: null, deliveryInfo: 'not json' });
    expect(d.shippingName).toBe('');
    expect(d.liffAddress).toBe('');
  });
});
