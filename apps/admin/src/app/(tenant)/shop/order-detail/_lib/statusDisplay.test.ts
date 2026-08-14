import { computeStatusBadge, computeTransactionTypeInfo } from './statusDisplay';

describe('computeStatusBadge', () => {
  it('returns the plain label/colors for a normal (non-COD) status', () => {
    expect(computeStatusBadge('paid', 'transfer')).toEqual({ label: 'ชำระแล้ว', bg: 'var(--color-emerald-100)', color: 'var(--color-emerald-700)' });
  });

  it('shows "รอจัดส่ง (COD)" for a COD order sitting at confirmed', () => {
    expect(computeStatusBadge('confirmed', 'cod').label).toBe('รอจัดส่ง (COD)');
  });

  it('does NOT special-case COD for any status other than confirmed', () => {
    expect(computeStatusBadge('paid', 'cod').label).toBe('ชำระแล้ว');
    expect(computeStatusBadge('pending', 'cod').label).toBe('รอยืนยัน');
  });

  it('defaults to pending label/colors when status is null', () => {
    expect(computeStatusBadge(null, null)).toEqual({ label: 'รอยืนยัน', bg: 'var(--color-amber-100)', color: 'var(--color-amber-700)' });
  });

  it('falls back to "รอดำเนินการ"/slate-100/dark-700 for an unrecognized status', () => {
    expect(computeStatusBadge('weird_status', null)).toEqual({ label: 'รอดำเนินการ', bg: 'var(--color-slate-100)', color: 'var(--color-dark-700)' });
  });
});

describe('computeTransactionTypeInfo', () => {
  it('resolves each known type', () => {
    expect(computeTransactionTypeInfo('booking').info.label).toBe('จองคิว');
    expect(computeTransactionTypeInfo('subscription').info.label).toBe('สมัครสมาชิก');
    expect(computeTransactionTypeInfo('redemption').info.label).toBe('แลกของรางวัล');
  });

  it('defaults to purchase when null or unrecognized', () => {
    expect(computeTransactionTypeInfo(null).type).toBe('purchase');
    expect(computeTransactionTypeInfo('unknown').info.label).toBe('ซื้อสินค้า');
  });
});
