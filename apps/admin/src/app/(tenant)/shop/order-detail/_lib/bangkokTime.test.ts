/**
 * @jest-environment node
 */
import { bangkokDdMmYyyyHm } from './bangkokTime';

describe('bangkokDdMmYyyyHm', () => {
  it('formats as dd/mm/yyyy HH:mm in Asia/Bangkok, independent of the host TZ', () => {
    // 2026-08-14T13:15:00Z is 2026-08-14 20:15 in Bangkok (+07:00).
    const now = new Date('2026-08-14T13:15:00Z');
    expect(bangkokDdMmYyyyHm(now)).toBe('14/08/2026 20:15');
  });

  it('rolls over the calendar day across the UTC/Bangkok boundary', () => {
    // 2026-08-14T18:30:00Z is 2026-08-15 01:30 in Bangkok — a different calendar day than UTC.
    const now = new Date('2026-08-14T18:30:00Z');
    expect(bangkokDdMmYyyyHm(now)).toBe('15/08/2026 01:30');
  });

  it('zero-pads midnight to 00:00, not 24:00 (hourCycle: h23 quirk guard)', () => {
    // 2026-08-13T17:00:00Z is 2026-08-14 00:00 in Bangkok.
    const now = new Date('2026-08-13T17:00:00Z');
    expect(bangkokDdMmYyyyHm(now)).toBe('14/08/2026 00:00');
  });
});
