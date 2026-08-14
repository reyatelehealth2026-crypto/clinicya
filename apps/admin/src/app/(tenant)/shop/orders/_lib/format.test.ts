import { formatMoney2, formatOrderDateTime } from './format';

describe('formatMoney2', () => {
  it('formats a Decimal-column string with 2 decimals and comma grouping', () => {
    expect(formatMoney2('1234.5')).toBe('1,234.50');
    expect(formatMoney2('1234567.891')).toBe('1,234,567.89');
  });

  it('formats a plain number the same way', () => {
    expect(formatMoney2(0)).toBe('0.00');
    expect(formatMoney2(99)).toBe('99.00');
  });

  it('treats a non-numeric value as 0, matching number_format() on a non-numeric PHP value', () => {
    expect(formatMoney2('not-a-number')).toBe('0.00');
  });
});

describe('formatOrderDateTime', () => {
  it('formats a bare "YYYY-MM-DD HH:MM:SS" DB string as d/m/Y H:i in Asia/Bangkok', () => {
    expect(formatOrderDateTime('2026-03-05 09:07:00')).toBe('05/03/2026 09:07');
  });

  it('formats a JS Date the same way, converting to Asia/Bangkok wall-clock', () => {
    // 2026-03-05T02:07:00Z === 2026-03-05 09:07:00 Asia/Bangkok (+07:00)
    expect(formatOrderDateTime(new Date('2026-03-05T02:07:00Z'))).toBe('05/03/2026 09:07');
  });
});
