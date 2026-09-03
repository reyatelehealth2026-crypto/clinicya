import { render, screen } from '@testing-library/react';

const mockRequirePublicTenantContext = jest.fn();
jest.mock('@/lib/tenant/publicTenantPageContext', () => ({
  requirePublicTenantContext: () => mockRequirePublicTenantContext(),
}));

const mockRedirect = jest.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
jest.mock('next/navigation', () => ({
  redirect: (url: string) => mockRedirect(url),
}));

jest.mock('next/headers', () => ({
  headers: jest.fn(),
}));

import { headers } from 'next/headers';
import { makeFakeTenantDb } from '@/app/(tenant)/users/testHelpers/fakeTenantDb';
import ArticleDetailPage, { generateMetadata } from './page';

const mockHeaders = headers as jest.MockedFunction<typeof headers>;

const ARTICLE_ROW = {
  id: 5,
  slug: 'how-to-vitamin-c',
  title: 'วิธีทานวิตามินซี',
  excerpt: 'สรุปสั้นๆ',
  content: '<p>เนื้อหา</p>',
  featured_image: null,
  author_name: 'ภญ. สมศรี',
  author_title: 'เภสัชกร',
  author_image: null,
  category_id: 2,
  category_name: 'วิตามิน',
  published_at: new Date(2026, 6, 5, 9, 0, 0),
  updated_at: new Date(2026, 6, 6, 9, 0, 0),
  view_count: 41,
  tags: '["วิตามิน","สุขภาพ"]',
  meta_title: null,
  meta_description: null,
  meta_keywords: null,
};

function defaultQueryImpl(sqlText: string) {
  if (sqlText.includes('FROM line_accounts')) return [{ id: 3 }];
  if (sqlText.includes('FROM shop_settings')) return [{ shop_name: 'ร้านยาทดสอบ', shop_logo: '' }];
  if (sqlText.includes('UPDATE health_articles')) return { affectedRows: 1 };
  if (sqlText.includes('a.slug = ?')) return [ARTICLE_ROW];
  if (sqlText.includes('FROM health_articles')) return []; // related-articles query
  return [];
}

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown = defaultQueryImpl) {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequirePublicTenantContext.mockResolvedValue({ db, session: { tenantId: 1 } });
  return { queries };
}

describe('ArticleDetailPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockHeaders.mockResolvedValue({ get: () => null } as unknown as Awaited<ReturnType<typeof headers>>);
  });

  it('renders title/excerpt/author/category badge/tags/related-articles count', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('a.slug = ?')) return [ARTICLE_ROW];
      if (sqlText.includes('a.is_published = 1 AND a.id != ?')) {
        return [
          { id: 6, slug: 'related-1', title: 'บทความที่เกี่ยวข้อง 1', featured_image: null },
          { id: 7, slug: 'related-2', title: 'บทความที่เกี่ยวข้อง 2', featured_image: null },
        ];
      }
      return defaultQueryImpl(sqlText);
    });

    const element = await ArticleDetailPage({ params: Promise.resolve({ slug: 'how-to-vitamin-c' }) });
    render(element);

    expect(screen.getByRole('heading', { name: 'วิธีทานวิตามินซี' })).toBeInTheDocument();
    // NOTE: article.php never renders `excerpt` in the visible body either — it's used only as the
    // meta_description fallback (<head>), not part of the article-body template. Not asserted here.
    expect(screen.getByText('ภญ. สมศรี')).toBeInTheDocument();
    expect(screen.getByText('วิตามิน')).toBeInTheDocument(); // category badge
    expect(screen.getByRole('link', { name: '#วิตามิน' })).toHaveAttribute('href', `/articles?tag=${encodeURIComponent('วิตามิน')}`);
    expect(screen.getByRole('link', { name: '#สุขภาพ' })).toBeInTheDocument();
    expect(screen.getByText('บทความที่เกี่ยวข้อง 1')).toBeInTheDocument();
    expect(screen.getByText('บทความที่เกี่ยวข้อง 2')).toBeInTheDocument();
    expect(screen.getByText(/41/)).toBeInTheDocument(); // view_count, pre-increment snapshot
  });

  it('redirects to /articles when the slug is not found (missing/unpublished)', async () => {
    wireDb((sqlText) => (sqlText.includes('a.slug = ?') ? [] : defaultQueryImpl(sqlText)));
    await expect(ArticleDetailPage({ params: Promise.resolve({ slug: 'missing' }) })).rejects.toThrow('REDIRECT:/articles');
  });

  it('increments view_count exactly once per render', async () => {
    const { queries } = wireDb();
    await ArticleDetailPage({ params: Promise.resolve({ slug: 'how-to-vitamin-c' }) });
    const updates = queries.filter((q) => q.sql.includes('UPDATE health_articles') && q.sql.includes('view_count = view_count + 1'));
    expect(updates).toHaveLength(1);
    expect(updates[0]?.params).toEqual([5]);
  });

  it('increments view_count by exactly 2 across two separate page renders (acceptance criteria: fetch twice -> +2)', async () => {
    const { queries } = wireDb();
    await ArticleDetailPage({ params: Promise.resolve({ slug: 'how-to-vitamin-c' }) });
    await ArticleDetailPage({ params: Promise.resolve({ slug: 'how-to-vitamin-c' }) });
    const updates = queries.filter((q) => q.sql.includes('UPDATE health_articles') && q.sql.includes('view_count = view_count + 1'));
    expect(updates).toHaveLength(2);
  });

  it('renders the JSON-LD script tag with the expected fields', async () => {
    wireDb();
    mockHeaders.mockResolvedValue({ get: (key: string) => (key === 'host' ? 'tenant-abcd.re-ya.com' : null) } as unknown as Awaited<
      ReturnType<typeof headers>
    >);
    const element = await ArticleDetailPage({ params: Promise.resolve({ slug: 'how-to-vitamin-c' }) });
    render(element);

    const script = document.querySelector('script[type="application/ld+json"]');
    expect(script).not.toBeNull();
    const jsonLd = JSON.parse(script?.textContent ?? '{}');
    expect(jsonLd['@type']).toBe('Article');
    expect(jsonLd.headline).toBe('วิธีทานวิตามินซี');
    expect(jsonLd.author).toEqual({ '@type': 'Person', name: 'ภญ. สมศรี', jobTitle: 'เภสัชกร' });
    expect(jsonLd.publisher).toEqual({ '@type': 'Organization', name: 'ร้านยาทดสอบ' });
  });
});

describe('generateMetadata', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockHeaders.mockResolvedValue({ get: () => null } as unknown as Awaited<ReturnType<typeof headers>>);
  });

  it('builds title/description/OG fields from the article + shop name', async () => {
    wireDb();
    const metadata = await generateMetadata({ params: Promise.resolve({ slug: 'how-to-vitamin-c' }) });
    expect(metadata.title).toBe('วิธีทานวิตามินซี | ร้านยาทดสอบ');
    expect(metadata.description).toBe('สรุปสั้นๆ');
    expect(metadata.openGraph).toMatchObject({ title: 'วิธีทานวิตามินซี', type: 'article' });
  });

  it('does NOT increment view_count (generateMetadata is a pure read)', async () => {
    const { queries } = wireDb();
    await generateMetadata({ params: Promise.resolve({ slug: 'how-to-vitamin-c' }) });
    const updates = queries.filter((q) => q.sql.includes('UPDATE health_articles'));
    expect(updates).toHaveLength(0);
  });

  it('falls back to a generic title without redirecting when the slug is missing', async () => {
    wireDb((sqlText) => (sqlText.includes('a.slug = ?') ? [] : defaultQueryImpl(sqlText)));
    const metadata = await generateMetadata({ params: Promise.resolve({ slug: 'missing' }) });
    expect(metadata.title).toContain('บทความสุขภาพ');
    expect(mockRedirect).not.toHaveBeenCalled();
  });
});
