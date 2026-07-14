import type { Metadata } from 'next';
import { requireTenantPageContext } from '../users/_lib/session';
import { firstParam, isSearchQueryPresent, parseCategoryIdParam } from './_lib/params';
import { getArticleCategories, getPublishedArticles, getShopSettings, resolveDefaultLineAccountId, searchArticles } from './queries';
import { ArticleCard } from './_components/ArticleCard';
import { CategoryFilter } from './_components/CategoryFilter';
import { SearchBox } from './_components/SearchBox';
import { EmptyState } from '@/components/EmptyState';

/**
 * (tenant)/articles/page.tsx — Server Component port of articles.php (513
 * LOC, confirmed by reading the full file): the public browse/list view for
 * health articles, with a category-chip filter bar and a search box. Serves
 * at the same clean URL shape PHP does — `/articles`, `?category=<id>`,
 * `?q=<term>` all preserved.
 *
 * ACCESS-MODEL DEVIATION (flagged for mig-orchestrator sign-off before any
 * real flip — NOT a merge blocker this round, per this batch's brief):
 * articles.php/article.php are FULLY PUBLIC, unauthenticated pages in PHP —
 * confirmed by reading both files in full: neither includes
 * `includes/header.php`, checks `$_SESSION`, or calls any of
 * `isSuperAdmin()`/`isAdmin()`/`isStaff()`. There are zero create/edit/
 * delete forms in either file either — HealthArticleService's
 * create()/update()/delete()/togglePublish()/toggleFeatured() are exercised
 * only by `includes/landing/admin-articles.php` (the real article CMS,
 * explicitly Phase 12 scope — NOT touched by this batch). The route
 * boundary this batch was given places the port inside `(tenant)/**`,
 * whose `layout.tsx` unconditionally redirects any unauthenticated request
 * to `/auth/login` (`requireTenantPageContext()` below, same as every
 * other Phase-2 admin page) — so this port is reachable ONLY to a logged-in
 * tenant admin session, a strictly narrower audience than the PHP original
 * (any member of the public with the URL). This access-model change is a
 * deliberate, documented consequence of the given path boundary
 * (`articles/**` nested under the existing `(tenant)` realm), not an
 * oversight — see `[slug]/page.tsx`'s own doc comment for the matching
 * note on that route.
 */
export interface ArticlesPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

const ARTICLES_LIMIT = 20;

export async function generateMetadata(): Promise<Metadata> {
  const { db } = await requireTenantPageContext();
  const lineAccountId = await resolveDefaultLineAccountId(db);
  const { shopName } = await getShopSettings(db, lineAccountId);
  return {
    title: `บทความสุขภาพ | ${shopName}`,
    description: `บทความสุขภาพ ความรู้เรื่องยา วิตามิน และการดูแลสุขภาพจาก ${shopName}`,
  };
}

export default async function ArticlesPage({ searchParams }: ArticlesPageProps) {
  const params = await searchParams;
  const { db } = await requireTenantPageContext();

  const categoryId = parseCategoryIdParam(params.category);
  // NOT trimmed — see _lib/params.ts's isSearchQueryPresent doc for why (no trim() in articles.php
  // either). NOTE: `?tag=` (the querystring article.php's tag chips link to, e.g.
  // `articles.php?tag=วิตามิน`) is intentionally never read here — grepped articles.php in full and it
  // never touches `$_GET['tag']` anywhere; a tag chip click lands back on the unfiltered/category-only
  // list in the real PHP page too. Not a gap in this port.
  const searchQuery = firstParam(params.q);

  const lineAccountId = await resolveDefaultLineAccountId(db);
  const [{ shopName }, categories, articles] = await Promise.all([
    getShopSettings(db, lineAccountId),
    getArticleCategories(db),
    isSearchQueryPresent(searchQuery)
      ? searchArticles(db, searchQuery, ARTICLES_LIMIT)
      : getPublishedArticles(db, lineAccountId, ARTICLES_LIMIT, categoryId),
  ]);

  return (
    <div className="mx-auto max-w-6xl px-4 py-6">
      <header className="mb-8 rounded-2xl bg-gradient-to-br from-emerald-600 to-emerald-800 px-6 py-10 text-center text-white">
        <h1 className="text-2xl font-bold">📚 บทความสุขภาพ</h1>
        <p className="mt-1 text-white/90">ความรู้ดีๆ เพื่อสุขภาพของคุณ — {shopName}</p>
      </header>

      <div className="mb-6">
        <SearchBox defaultValue={searchQuery} />
      </div>

      {categories.length > 0 ? (
        <div className="mb-6">
          <CategoryFilter categories={categories} activeCategoryId={categoryId} />
        </div>
      ) : null}

      {articles.length > 0 ? (
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {articles.map((article) => (
            <ArticleCard key={article.id} article={article} />
          ))}
        </div>
      ) : (
        <EmptyState icon={<span aria-hidden="true">📰</span>} heading="ไม่พบบทความ" />
      )}

      {/* Footer — port of articles.php's .page-footer (lines 499-510). "หน้าแรก" points at /dashboard
          (this realm's actual landing page, not the unported public index.php) and the
          privacy-policy.php link is omitted (no equivalent route in this batch/realm) — see
          [slug]/page.tsx's doc comment for the identical footer-link adaptation and rationale. */}
      <footer className="mt-12 rounded-2xl bg-gray-800 px-6 py-8 text-center text-white">
        <div className="mb-4 flex justify-center gap-6 text-sm text-gray-300">
          <a href="/dashboard" className="hover:text-white">
            หน้าแรก
          </a>
          <a href="/articles" className="hover:text-white">
            บทความ
          </a>
        </div>
        <div className="text-[13px] text-gray-400">
          &copy; {new Date().getFullYear()} {shopName}
        </div>
      </footer>
    </div>
  );
}
