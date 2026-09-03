jest.mock('next/headers', () => ({
  headers: jest.fn(),
}));

import { headers } from 'next/headers';
import { buildArticleJsonLd, buildArticleUrl, getRequestBaseUrl } from './seo';

const mockHeaders = headers as jest.MockedFunction<typeof headers>;

function fakeHeaders(entries: Record<string, string>) {
  return { get: (key: string) => entries[key] ?? null } as unknown as Awaited<ReturnType<typeof headers>>;
}

describe('getRequestBaseUrl', () => {
  const originalEnv = process.env.ARTICLES_BASE_URL;

  afterEach(() => {
    process.env.ARTICLES_BASE_URL = originalEnv;
  });

  it('derives scheme+host from x-forwarded-proto/x-forwarded-host', async () => {
    delete process.env.ARTICLES_BASE_URL;
    mockHeaders.mockResolvedValue(fakeHeaders({ 'x-forwarded-proto': 'https', 'x-forwarded-host': 'tenant-abcd.re-ya.com' }));
    expect(await getRequestBaseUrl()).toBe('https://tenant-abcd.re-ya.com');
  });

  it('falls back to the host header and https when x-forwarded-* are absent', async () => {
    delete process.env.ARTICLES_BASE_URL;
    mockHeaders.mockResolvedValue(fakeHeaders({ host: 'tenant-abcd.re-ya.com' }));
    expect(await getRequestBaseUrl()).toBe('https://tenant-abcd.re-ya.com');
  });

  it('prefers an ARTICLES_BASE_URL env override and strips trailing slashes', async () => {
    process.env.ARTICLES_BASE_URL = 'https://override.example.com/';
    expect(await getRequestBaseUrl()).toBe('https://override.example.com');
  });
});

describe('buildArticleUrl', () => {
  it('builds the nested /articles/[slug] shape, URL-encoding the slug', () => {
    expect(buildArticleUrl('https://tenant-abcd.re-ya.com', 'วิธี ดูแลผิว')).toBe(
      'https://tenant-abcd.re-ya.com/articles/' + encodeURIComponent('วิธี ดูแลผิว')
    );
  });
});

describe('buildArticleJsonLd', () => {
  const base = {
    title: 'บทความทดสอบ',
    description: 'คำอธิบาย',
    featuredImage: null,
    publishedAtIso: '2026-07-05T09:00:00+07:00',
    updatedAtIso: '2026-07-06T09:00:00+07:00',
    authorName: null,
    authorTitle: null,
    shopName: 'ร้านยาทดสอบ',
    shopLogo: '',
  };

  it('emits the required fields with no optional fields when image/author/logo are absent', () => {
    const jsonLd = buildArticleJsonLd(base);
    expect(jsonLd).toEqual({
      '@context': 'https://schema.org',
      '@type': 'Article',
      headline: 'บทความทดสอบ',
      description: 'คำอธิบาย',
      datePublished: '2026-07-05T09:00:00+07:00',
      dateModified: '2026-07-06T09:00:00+07:00',
      publisher: { '@type': 'Organization', name: 'ร้านยาทดสอบ' },
    });
  });

  it('includes image/author/publisher.logo only when present', () => {
    const jsonLd = buildArticleJsonLd({
      ...base,
      featuredImage: 'https://x/img.png',
      authorName: 'ภญ. สมศรี',
      authorTitle: 'เภสัชกร',
      shopLogo: 'https://x/logo.png',
    });
    expect(jsonLd.image).toBe('https://x/img.png');
    expect(jsonLd.author).toEqual({ '@type': 'Person', name: 'ภญ. สมศรี', jobTitle: 'เภสัชกร' });
    expect((jsonLd.publisher as Record<string, unknown>).logo).toEqual({ '@type': 'ImageObject', url: 'https://x/logo.png' });
  });

  it('omits author.jobTitle when authorTitle is absent', () => {
    const jsonLd = buildArticleJsonLd({ ...base, authorName: 'ภญ. สมศรี' });
    expect(jsonLd.author).toEqual({ '@type': 'Person', name: 'ภญ. สมศรี' });
  });
});
