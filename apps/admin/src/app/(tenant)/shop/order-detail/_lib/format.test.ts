import { formatDateTimeDMY, formatMoney, formatMoney0 } from './format';

describe('formatDateTimeDMY', () => {
  it('formats a Date as dd/mm/yyyy HH:MM', () => {
    expect(formatDateTimeDMY(new Date(2026, 7, 14, 9, 5))).toBe('14/08/2026 09:05');
  });

  it('returns "-" for null/undefined/invalid', () => {
    expect(formatDateTimeDMY(null)).toBe('-');
    expect(formatDateTimeDMY(undefined)).toBe('-');
    expect(formatDateTimeDMY('not-a-date')).toBe('-');
  });
});

describe('formatMoney', () => {
  it('formats with 2 decimals and thousands separators', () => {
    expect(formatMoney(1234.5)).toBe('1,234.50');
    expect(formatMoney('500')).toBe('500.00');
    expect(formatMoney(null)).toBe('0.00');
  });
});

describe('formatMoney0', () => {
  it('formats with no decimals and thousands separators', () => {
    expect(formatMoney0(1234.5)).toBe('1,235');
    expect(formatMoney0('500')).toBe('500');
    expect(formatMoney0(undefined)).toBe('0');
  });
});
