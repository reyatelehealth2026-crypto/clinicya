import { resolveActiveTab, resolveExecutiveDateFilter, todayInBangkok, formatDateFilterDisplay } from './dateFilter';

describe('resolveActiveTab', () => {
  it('defaults to executive when tab is undefined (missing ?tab=)', () => {
    expect(resolveActiveTab(undefined)).toBe('executive');
  });

  it("falls back to executive for an invalid tab value (dashboard.php's $validTabs guard)", () => {
    expect(resolveActiveTab('xyz')).toBe('executive');
    expect(resolveActiveTab('')).toBe('executive');
    expect(resolveActiveTab('Executive')).toBe('executive'); // case-sensitive, PHP in_array() is too
  });

  it('accepts crm', () => {
    expect(resolveActiveTab('crm')).toBe('crm');
  });

  it('accepts executive explicitly', () => {
    expect(resolveActiveTab('executive')).toBe('executive');
  });
});

describe('todayInBangkok', () => {
  it('formats as YYYY-MM-DD in Asia/Bangkok regardless of a UTC instant that would be a different calendar day locally', () => {
    // 2026-07-12T20:00:00Z is 2026-07-13 03:00 in Asia/Bangkok (+07:00) — a naive
    // server-local formatter (if the process ran in UTC) would say "2026-07-12".
    const utcLateEvening = new Date('2026-07-12T20:00:00Z');
    expect(todayInBangkok(utcLateEvening)).toBe('2026-07-13');
  });

  it('formats an early-UTC instant that is still the previous Bangkok day', () => {
    // 2026-07-12T00:30:00Z is 2026-07-12 07:30 Bangkok — same calendar day, sanity check.
    expect(todayInBangkok(new Date('2026-07-12T00:30:00Z'))).toBe('2026-07-12');
  });
});

describe('resolveExecutiveDateFilter', () => {
  it("defaults dateFilter to today-in-Bangkok when the date searchParam is absent (executive.php's `?? date('Y-m-d')`)", () => {
    const now = new Date('2026-07-12T10:00:00Z'); // 17:00 Bangkok, still 2026-07-12
    const result = resolveExecutiveDateFilter(undefined, now);
    expect(result.dateFilter).toBe('2026-07-12');
    expect(result.dateStart).toBe('2026-07-12 00:00:00');
    expect(result.dateEnd).toBe('2026-07-12 23:59:59');
  });

  it('uses the provided date verbatim when present, without validating its shape (matches PHP, which never validates $_GET[date])', () => {
    const result = resolveExecutiveDateFilter('2026-01-15');
    expect(result).toEqual({ dateFilter: '2026-01-15', dateStart: '2026-01-15 00:00:00', dateEnd: '2026-01-15 23:59:59' });
  });

  it('passes a malformed date param straight through instead of silently falling back to today', () => {
    const result = resolveExecutiveDateFilter('not-a-date');
    expect(result.dateFilter).toBe('not-a-date');
    expect(result.dateStart).toBe('not-a-date 00:00:00');
  });
});

describe('formatDateFilterDisplay', () => {
  it("formats a valid date in English long form, matching PHP's date('l, j M Y', strtotime(...))", () => {
    // 2026-07-12 is a Sunday.
    expect(formatDateFilterDisplay('2026-07-12')).toBe('Sunday, 12 Jul 2026');
  });

  it('falls back to the raw string for an unparseable dateFilter', () => {
    expect(formatDateFilterDisplay('not-a-date')).toBe('not-a-date');
  });
});
