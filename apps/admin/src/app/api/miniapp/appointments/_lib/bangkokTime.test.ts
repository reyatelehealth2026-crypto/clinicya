/**
 * @jest-environment node
 */
import { addDaysToDateString, dayOfWeek, formatHm, nowInBangkok, pseudoUtcFromDateAndTime, todayInBangkok } from './bangkokTime';

describe('todayInBangkok', () => {
  it('formats as YYYY-MM-DD in Asia/Bangkok, independent of the host TZ', () => {
    // 2026-07-13T18:30:00Z is 2026-07-14 01:30 in Bangkok (+07:00) — crosses the UTC day boundary.
    const now = new Date('2026-07-13T18:30:00Z');
    expect(todayInBangkok(now)).toBe('2026-07-14');
  });

  it('does not roll over for a time still within the same Bangkok day', () => {
    const now = new Date('2026-07-13T10:00:00Z'); // 17:00 in Bangkok, same calendar day
    expect(todayInBangkok(now)).toBe('2026-07-13');
  });
});

describe('nowInBangkok', () => {
  it('re-encodes the Bangkok wall-clock time as a pseudo-UTC instant', () => {
    const now = new Date('2026-07-13T18:30:15Z'); // 2026-07-14 01:30:15 in Bangkok
    const pseudo = nowInBangkok(now);
    expect(pseudo.toISOString()).toBe('2026-07-14T01:30:15.000Z');
  });
});

describe('addDaysToDateString', () => {
  it('adds days across a month boundary', () => {
    expect(addDaysToDateString('2026-07-13', 30)).toBe('2026-08-12');
  });

  it('supports negative offsets', () => {
    expect(addDaysToDateString('2026-07-13', -1)).toBe('2026-07-12');
  });
});

describe('pseudoUtcFromDateAndTime + formatHm', () => {
  it('parses an H:i time and round-trips through formatHm', () => {
    const d = pseudoUtcFromDateAndTime('2026-07-14', '09:20');
    expect(formatHm(d)).toBe('09:20');
  });

  it('parses an H:i:s time (TIME column shape) the same way', () => {
    const d = pseudoUtcFromDateAndTime('2026-07-14', '09:20:00');
    expect(formatHm(d)).toBe('09:20');
  });
});

describe('dayOfWeek', () => {
  it('matches PHP DateTime::format("w") — 0=Sunday..6=Saturday', () => {
    // 2026-07-19 is a Sunday.
    expect(dayOfWeek(pseudoUtcFromDateAndTime('2026-07-19', '00:00'))).toBe(0);
    // 2026-07-14 is a Tuesday.
    expect(dayOfWeek(pseudoUtcFromDateAndTime('2026-07-14', '00:00'))).toBe(2);
  });
});
