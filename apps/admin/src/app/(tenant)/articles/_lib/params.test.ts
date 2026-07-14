import { firstParam, isSearchQueryPresent, parseCategoryIdParam } from './params';

describe('firstParam', () => {
  it('returns a plain string value unchanged', () => {
    expect(firstParam('abc')).toBe('abc');
  });
  it('returns the first element of an array value', () => {
    expect(firstParam(['a', 'b'])).toBe('a');
  });
  it('returns "" for undefined', () => {
    expect(firstParam(undefined)).toBe('');
  });
});

describe('parseCategoryIdParam', () => {
  it('parses a numeric string into an id', () => {
    expect(parseCategoryIdParam('3')).toBe(3);
  });
  it('returns null for "0" (PHP falsy int)', () => {
    expect(parseCategoryIdParam('0')).toBeNull();
  });
  it('returns null when absent', () => {
    expect(parseCategoryIdParam(undefined)).toBeNull();
  });
  it('returns null for a non-numeric string ((int) cast -> 0)', () => {
    expect(parseCategoryIdParam('abc')).toBeNull();
  });
  it('preserves a negative id as a real (non-null) filter value', () => {
    expect(parseCategoryIdParam('-5')).toBe(-5);
  });
  it('takes the first value when given an array', () => {
    expect(parseCategoryIdParam(['7', '9'])).toBe(7);
  });
});

describe('isSearchQueryPresent', () => {
  it('is false for an empty string', () => {
    expect(isSearchQueryPresent('')).toBe(false);
  });
  it('is false for the literal string "0" (PHP string falsiness)', () => {
    expect(isSearchQueryPresent('0')).toBe(false);
  });
  it('is true for a whitespace-only string (no trim() in the PHP source)', () => {
    expect(isSearchQueryPresent('   ')).toBe(true);
  });
  it('is true for an ordinary search term', () => {
    expect(isSearchQueryPresent('วิตามิน')).toBe(true);
  });
});
