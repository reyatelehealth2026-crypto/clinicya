import { makeFakeTenantDb } from '@/app/(tenant)/users/testHelpers/fakeTenantDb';
import {
  getArticleBySlug,
  getArticleCategories,
  getPublishedArticles,
  getRelatedArticles,
  getShopSettings,
  resolveDefaultLineAccountId,
  searchArticles,
} from './queries';

describe('resolveDefaultLineAccountId', () => {
  it('returns the is_default=1 row id without querying the fallback', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => (sqlText.includes('is_default = 1') ? [{ id: 9 }] : [{ id: 1 }]));
    const id = await resolveDefaultLineAccountId(db);
    expect(id).toBe(9);
    expect(queries).toHaveLength(1);
  });

  it('falls back to ORDER BY id ASC when there is no is_default row', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => (sqlText.includes('is_default = 1') ? [] : [{ id: 4 }]));
    const id = await resolveDefaultLineAccountId(db);
    expect(id).toBe(4);
    expect(queries).toHaveLength(2);
  });

  it('defaults to 1 when neither query finds a row', async () => {
    const { db } = makeFakeTenantDb(() => []);
    expect(await resolveDefaultLineAccountId(db)).toBe(1);
  });

  it('defaults to 1 when the query throws', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    expect(await resolveDefaultLineAccountId(db)).toBe(1);
  });
});

describe('getShopSettings', () => {
  it('returns shop_name/shop_logo from the row', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ shop_name: 'ร้านยาทดสอบ', shop_logo: 'https://x/logo.png' }]);
    const result = await getShopSettings(db, 7);
    expect(result).toEqual({ shopName: 'ร้านยาทดสอบ', shopLogo: 'https://x/logo.png' });
    expect(queries[0]?.params).toEqual([7]);
  });

  it('falls back to defaults when no row is found', async () => {
    const { db } = makeFakeTenantDb(() => []);
    expect(await getShopSettings(db, 7)).toEqual({ shopName: 'LINE Telepharmacy', shopLogo: '' });
  });

  it('falls back to defaults on a query error', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    expect(await getShopSettings(db, 7)).toEqual({ shopName: 'LINE Telepharmacy', shopLogo: '' });
  });
});

describe('getArticleCategories', () => {
  it('selects active categories ordered by sort_order', async () => {
    const { db, queries } = makeFakeTenantDb(() => [
      { id: 1, name: 'วิตามิน' },
      { id: 2, name: 'โรคผิวหนัง' },
    ]);
    const result = await getArticleCategories(db);
    expect(result).toHaveLength(2);
    expect(queries[0]?.sql).toContain('is_active = 1');
    expect(queries[0]?.sql).toContain('ORDER BY sort_order ASC');
  });

  it('returns [] on a query error', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    expect(await getArticleCategories(db)).toEqual([]);
  });
});

describe('getPublishedArticles', () => {
  it('filters is_published=1 + (line_account_id = ? OR IS NULL), orders by is_featured DESC, published_at DESC, with no category filter when categoryId is null', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ id: 1, slug: 'a', title: 'A', excerpt: null, featured_image: null, is_featured: 0, author_name: null, published_at: new Date(), category_name: null }]);
    await getPublishedArticles(db, 7, 20, null);
    const q = queries[0];
    expect(q?.sql).toContain('a.is_published = 1');
    expect(q?.sql).toContain('a.line_account_id = ? OR a.line_account_id IS NULL');
    expect(q?.sql).toContain('ORDER BY a.is_featured DESC, a.published_at DESC');
    expect(q?.sql).not.toContain('AND a.category_id = ?');
    expect(q?.params).toEqual([7, 20]);
  });

  it('adds AND a.category_id = ? when categoryId is provided', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getPublishedArticles(db, 7, 20, 3);
    const q = queries[0];
    expect(q?.sql).toContain('AND a.category_id = ?');
    expect(q?.params).toEqual([7, 3, 20]);
  });

  it('returns [] on a query error', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    expect(await getPublishedArticles(db, 7, 20, null)).toEqual([]);
  });
});

describe('searchArticles', () => {
  it('LIKE-matches title/excerpt/content with NO line_account_id filter', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await searchArticles(db, 'วิตามิน', 20);
    const q = queries[0];
    expect(q?.sql).toContain('a.title LIKE ? OR a.excerpt LIKE ? OR a.content LIKE ?');
    expect(q?.sql).not.toContain('line_account_id');
    expect(q?.sql).toContain('ORDER BY a.published_at DESC');
    expect(q?.params).toEqual(['%วิตามิน%', '%วิตามิน%', '%วิตามิน%', 20]);
  });

  it('returns [] on a query error', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    expect(await searchArticles(db, 'x', 20)).toEqual([]);
  });
});

describe('getArticleBySlug', () => {
  it('filters by slug + is_published=1 + line_account_id scoping and returns the row', async () => {
    const row = { id: 5, slug: 'a', title: 'A', excerpt: null, content: '<p>hi</p>', featured_image: null, author_name: null, author_title: null, author_image: null, category_id: null, category_name: null, published_at: new Date(), updated_at: new Date(), view_count: 10, tags: null, meta_title: null, meta_description: null, meta_keywords: null };
    const { db, queries } = makeFakeTenantDb(() => [row]);
    const result = await getArticleBySlug(db, 'a', 7);
    expect(result).toEqual(row);
    const q = queries[0];
    expect(q?.sql).toContain('a.slug = ?');
    expect(q?.sql).toContain('a.is_published = 1');
    expect(q?.sql).toContain('a.line_account_id = ? OR a.line_account_id IS NULL');
    expect(q?.params).toEqual(['a', 7]);
  });

  it('returns null when not found', async () => {
    const { db } = makeFakeTenantDb(() => []);
    expect(await getArticleBySlug(db, 'missing', 7)).toBeNull();
  });

  it('returns null on a query error', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    expect(await getArticleBySlug(db, 'a', 7)).toBeNull();
  });
});

describe('getRelatedArticles', () => {
  it('excludes the current article, filters is_published=1, no line_account_id filter, orders by published_at DESC', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getRelatedArticles(db, 5, null, 3);
    const q = queries[0];
    expect(q?.sql).toContain('a.is_published = 1 AND a.id != ?');
    expect(q?.sql).not.toContain('line_account_id');
    expect(q?.sql).toContain('ORDER BY a.published_at DESC');
    expect(q?.params).toEqual([5, 3]);
  });

  it('adds AND a.category_id = ? when categoryId is provided', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getRelatedArticles(db, 5, 2, 3);
    const q = queries[0];
    expect(q?.sql).toContain('AND a.category_id = ?');
    expect(q?.params).toEqual([5, 2, 3]);
  });

  it('returns [] on a query error', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    expect(await getRelatedArticles(db, 5, null, 3)).toEqual([]);
  });
});
