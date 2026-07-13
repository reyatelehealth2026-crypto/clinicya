import { extractCategoryFilters } from './categories';

describe('extractCategoryFilters', () => {
  it('dedupes in first-seen order, not sorted order', () => {
    const templates = [
      { category: 'ทักทาย' },
      { category: 'โปรโมชั่น' },
      { category: 'ทักทาย' },
      { category: 'FAQ' },
    ];
    expect(extractCategoryFilters(templates)).toEqual(['ทักทาย', 'โปรโมชั่น', 'FAQ']);
  });

  it('skips null categories (PHP `if ($cat)` falsy)', () => {
    expect(extractCategoryFilters([{ category: null }, { category: 'A' }])).toEqual(['A']);
  });

  it('skips empty-string categories', () => {
    expect(extractCategoryFilters([{ category: '' }, { category: 'A' }])).toEqual(['A']);
  });

  it('skips the string "0" (PHP loose-falsy), matching `if ($cat)`', () => {
    expect(extractCategoryFilters([{ category: '0' }, { category: 'A' }])).toEqual(['A']);
  });

  it('returns [] for an empty template list', () => {
    expect(extractCategoryFilters([])).toEqual([]);
  });

  it('preserves the order of the already-sorted input rather than re-sorting', () => {
    // Simulates `ORDER BY category, name` output where category order is not
    // plain alphabetical (e.g. NULL-first collation quirks) — the function
    // must not "fix" this by sorting.
    const templates = [{ category: 'Z' }, { category: 'A' }];
    expect(extractCategoryFilters(templates)).toEqual(['Z', 'A']);
  });
});
