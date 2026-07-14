import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import ArticlesPage, { generateMetadata } from './page';

const ARTICLE_ROW = {
  id: 1,
  slug: 'how-to-vitamin-c',
  title: 'วิธีทานวิตามินซี',
  excerpt: 'สรุปสั้นๆ',
  featured_image: null,
  is_featured: 1,
  author_name: 'ภญ. สมศรี',
  published_at: new Date(2026, 6, 5),
  category_name: 'วิตามิน',
};

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown) {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: { tenantId: 1 } });
  return { queries };
}

function defaultQueryImpl(sqlText: string) {
  if (sqlText.includes('FROM line_accounts')) return [{ id: 3 }];
  if (sqlText.includes('FROM shop_settings')) return [{ shop_name: 'ร้านยาทดสอบ', shop_logo: '' }];
  if (sqlText.includes('FROM health_article_categories')) return [{ id: 1, name: 'วิตามิน' }];
  if (sqlText.includes('FROM health_articles')) return [ARTICLE_ROW];
  return [];
}

describe('ArticlesPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders the hero, search box, category chips, and every article card', async () => {
    wireDb(defaultQueryImpl);
    const element = await ArticlesPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.getByRole('heading', { name: /บทความสุขภาพ/ })).toBeInTheDocument();
    expect(screen.getByRole('textbox', { name: 'ค้นหาบทความ' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'ทั้งหมด' })).toHaveAttribute('href', '/articles');
    expect(screen.getByRole('link', { name: 'วิตามิน' })).toHaveAttribute('href', '/articles?category=1');
    expect(screen.getByText('วิธีทานวิตามินซี')).toBeInTheDocument();
    expect(screen.getByText('แนะนำ')).toBeInTheDocument();
  });

  it('shows the empty state when there are no articles', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('FROM health_articles')) return [];
      return defaultQueryImpl(sqlText);
    });
    const element = await ArticlesPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.getByText('ไม่พบบทความ')).toBeInTheDocument();
  });

  it('dispatches to search (not getPublishedArticles) when q is present and non-"0"', async () => {
    const { queries } = wireDb(defaultQueryImpl);
    await ArticlesPage({ searchParams: Promise.resolve({ q: 'วิตามิน' }) });
    const articlesQuery = queries.find((q) => q.sql.includes('FROM health_articles'));
    expect(articlesQuery?.sql).toContain('LIKE');
    expect(articlesQuery?.sql).not.toContain('line_account_id');
  });

  it('falls back to getPublishedArticles (category-filterable) when q is absent', async () => {
    const { queries } = wireDb(defaultQueryImpl);
    await ArticlesPage({ searchParams: Promise.resolve({ category: '1' }) });
    const articlesQuery = queries.find((q) => q.sql.includes('FROM health_articles'));
    expect(articlesQuery?.sql).toContain('a.category_id = ?');
    expect(articlesQuery?.sql).toContain('line_account_id');
  });

  it('does not render the category bar when there are no categories', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('FROM health_article_categories')) return [];
      return defaultQueryImpl(sqlText);
    });
    const element = await ArticlesPage({ searchParams: Promise.resolve({}) });
    render(element);
    expect(screen.queryByRole('link', { name: 'ทั้งหมด' })).not.toBeInTheDocument();
  });
});

describe('generateMetadata', () => {
  it("titles the page with the shop name, mirroring PHP's dynamic <title>", async () => {
    wireDb(defaultQueryImpl);
    const metadata = await generateMetadata();
    expect(metadata.title).toBe('บทความสุขภาพ | ร้านยาทดสอบ');
    expect(metadata.description).toContain('ร้านยาทดสอบ');
  });
});
