import { parseOverviewPeriod, todayInBangkok, daysAgoInBangkok } from './period';

const FIXED_NOW = new Date('2026-07-15T10:00:00Z'); // 17:00 Bangkok

describe('todayInBangkok / daysAgoInBangkok', () => {
  it('returns the Bangkok-local date', () => {
    expect(todayInBangkok(FIXED_NOW)).toBe('2026-07-15');
  });
  it('subtracts whole days', () => {
    expect(daysAgoInBangkok(7, FIXED_NOW)).toBe('2026-07-08');
    expect(daysAgoInBangkok(30, FIXED_NOW)).toBe('2026-06-15');
  });
  it('rolls over a UTC day boundary correctly (Bangkok is UTC+7)', () => {
    const lateUtc = new Date('2026-07-15T20:00:00Z'); // 2026-07-16 03:00 Bangkok
    expect(todayInBangkok(lateUtc)).toBe('2026-07-16');
  });
});

describe('parseOverviewPeriod', () => {
  it('defaults period to "30" and derives start/end from it, matching analytics.php lines 42-44', () => {
    const result = parseOverviewPeriod({}, FIXED_NOW);
    expect(result.period).toBe('30');
    expect(result.startDate).toBe('2026-06-15');
    expect(result.endDate).toBe('2026-07-15');
  });

  it('uses the given period to compute the default start date when start is absent', () => {
    const result = parseOverviewPeriod({ period: '7' }, FIXED_NOW);
    expect(result.period).toBe('7');
    expect(result.startDate).toBe('2026-07-08');
  });

  it('prefers explicit start/end over the period-derived defaults', () => {
    const result = parseOverviewPeriod({ period: '7', start: '2025-01-01', end: '2025-01-31' }, FIXED_NOW);
    expect(result.startDate).toBe('2025-01-01');
    expect(result.endDate).toBe('2025-01-31');
  });

  it('takes the first value when a param is an array', () => {
    const result = parseOverviewPeriod({ period: ['90', '7'] }, FIXED_NOW);
    expect(result.period).toBe('90');
  });
});
