import { formatArticleDate, parseArticleTags, toArticleIsoDateTime } from './format';

describe('formatArticleDate', () => {
  it("mirrors PHP's date('d M Y', ...) — zero-padded day, English short month, 4-digit year", () => {
    expect(formatArticleDate(new Date(2026, 6, 5, 10, 30))).toBe('05 Jul 2026');
  });
  it('returns "" for null/undefined', () => {
    expect(formatArticleDate(null)).toBe('');
    expect(formatArticleDate(undefined)).toBe('');
  });
  it('accepts a date string', () => {
    expect(formatArticleDate('2026-01-14T00:00:00')).toBe('14 Jan 2026');
  });
  it('returns "" for an invalid date', () => {
    expect(formatArticleDate('not-a-date')).toBe('');
  });
});

describe('toArticleIsoDateTime', () => {
  it('formats with a T separator, zero-padded components, and the +07:00 offset', () => {
    expect(toArticleIsoDateTime(new Date(2026, 6, 5, 9, 5, 3))).toBe('2026-07-05T09:05:03+07:00');
  });
  it('returns "" for null/undefined', () => {
    expect(toArticleIsoDateTime(null)).toBe('');
    expect(toArticleIsoDateTime(undefined)).toBe('');
  });
});

describe('parseArticleTags', () => {
  it('parses a JSON array of tag strings', () => {
    expect(parseArticleTags('["วิตามิน","สุขภาพ"]')).toEqual(['วิตามิน', 'สุขภาพ']);
  });
  it('returns [] for null/undefined/empty', () => {
    expect(parseArticleTags(null)).toEqual([]);
    expect(parseArticleTags(undefined)).toEqual([]);
    expect(parseArticleTags('')).toEqual([]);
  });
  it('returns [] for invalid JSON', () => {
    expect(parseArticleTags('{not json')).toEqual([]);
  });
  it('returns [] for valid JSON that is not an array', () => {
    expect(parseArticleTags('{"a":1}')).toEqual([]);
  });
});
