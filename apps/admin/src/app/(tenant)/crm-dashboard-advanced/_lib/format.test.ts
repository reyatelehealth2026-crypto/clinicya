import {
  stageLabel,
  stageBadgeClass,
  ticketStatusBadgeClass,
  ticketPriorityBadgeClass,
  formatMoney,
  formatCount,
  formatDealDate,
  formatCustomerDate,
  formatSla,
  isPhpEmpty,
  isPhpIsset,
} from './format';

describe('stageLabel / badge classes', () => {
  it('labels all 6 stages', () => {
    expect(stageLabel('lead')).toBe('New Leads');
    expect(stageLabel('closed_won')).toBe('Closed Won');
    expect(stageLabel('closed_lost')).toBe('Closed Lost');
  });

  it('falls back to the raw stage string for an unknown stage', () => {
    expect(stageLabel('unknown_stage')).toBe('unknown_stage');
  });

  it('gives each stage a distinct badge class', () => {
    const classes = new Set(['lead', 'qualified', 'proposal', 'negotiation', 'closed_won', 'closed_lost'].map(stageBadgeClass));
    expect(classes.size).toBe(6);
  });

  it('maps ticket status/priority to badge classes without throwing on unknown values', () => {
    expect(ticketStatusBadgeClass('open')).toContain('blue');
    expect(ticketStatusBadgeClass('bogus')).toContain('gray');
    expect(ticketPriorityBadgeClass('urgent')).toContain('red');
    expect(ticketPriorityBadgeClass('high')).toContain('red');
    expect(ticketPriorityBadgeClass('low')).toContain('gray');
  });
});

describe('formatMoney / formatCount', () => {
  it('formats with comma grouping and treats null/undefined as 0', () => {
    expect(formatMoney(1234567.5)).toBe('1,234,567.5');
    expect(formatMoney(null)).toBe('0');
    expect(formatMoney(undefined)).toBe('0');
    expect(formatCount('42')).toBe('42');
    expect(formatCount(1234)).toBe('1,234');
  });
});

describe('formatDealDate / formatCustomerDate (th-TH Buddhist calendar)', () => {
  it('formats a deal date as day + short Thai month', () => {
    const result = formatDealDate('2026-07-13');
    expect(result).toMatch(/13/);
  });

  it('formats a customer date with a 2-digit Buddhist year', () => {
    // 2026 CE = 2569 BE -> 2-digit year '69'
    const result = formatCustomerDate('2026-07-13T00:00:00Z');
    expect(result).toContain('69');
  });

  it('returns empty string for null/invalid input', () => {
    expect(formatDealDate(null)).toBe('');
    expect(formatDealDate('not-a-date')).toBe('');
  });
});

describe('formatSla', () => {
  const now = new Date('2026-07-13T12:00:00Z');

  it('returns "-" when there is no deadline', () => {
    expect(formatSla(null, now)).toEqual({ text: '-', breached: false });
  });

  it('returns BREACHED when the deadline is in the past', () => {
    expect(formatSla('2026-07-13T10:00:00Z', now)).toEqual({ text: 'BREACHED', breached: true });
  });

  it('returns "{hours}h left" when the deadline is in the future, floored', () => {
    // 5.5 hours away -> floor to 5
    expect(formatSla('2026-07-13T17:30:00Z', now)).toEqual({ text: '5h left', breached: false });
  });

  it('always reports BREACHED for the epoch sla_deadline bug value (1970-01-01 07:00:00)', () => {
    expect(formatSla('1970-01-01T00:00:00Z', now).breached).toBe(true);
  });
});

describe('isPhpEmpty (PHP empty() semantics)', () => {
  it.each([null, undefined, false, 0, '0', ''])('treats %p as empty', (v) => {
    expect(isPhpEmpty(v)).toBe(true);
  });

  it.each([1, '1', 'a', true, -1, '00'])('treats %p as NOT empty', (v) => {
    expect(isPhpEmpty(v)).toBe(false);
  });
});

describe('isPhpIsset (PHP isset() semantics)', () => {
  it.each([null, undefined])('treats %p as NOT set', (v) => {
    expect(isPhpIsset(v)).toBe(false);
  });

  it.each([0, '', false, 'a', 1])('treats %p as set (isset is more permissive than empty)', (v) => {
    expect(isPhpIsset(v)).toBe(true);
  });
});
