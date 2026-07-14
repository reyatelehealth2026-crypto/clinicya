import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { requireTenantPageContext } from '../../users/_lib/session';
import { formatNumber } from '../../users/_lib/format';
import { getArticleBySlug, getRelatedArticles, getShopSettings, resolveDefaultLineAccountId } from '../queries';
import { incrementViewCountAction } from '../actions';
import { formatArticleDate, parseArticleTags, toArticleIsoDateTime } from '../_lib/format';
import { buildArticleJsonLd, buildArticleUrl, getRequestBaseUrl } from '../_lib/seo';
import { RelatedArticles } from '../_components/RelatedArticles';
import { ShareButtons } from '../_components/ShareButtons';

/**
 * (tenant)/articles/[slug]/page.tsx — Server Component port of article.php
 * (669 LOC, confirmed by reading the full file): the public, SEO-friendly
 * single-article detail view — meta/OG/JSON-LD tags, related articles, tag
 * chips, share buttons, view-count increment.
 *
 * URL-SHAPE DECISION (deliberate, not a guess): article.php is a SEPARATE
 * top-level legacy file (`article.php?slug=xyz`), but this batch's allowed
 * paths are `articles/**` only — no top-level `/article` route can be
 * created here. Ported as a NESTED dynamic segment, `/articles/[slug]`,
 * instead. Every internal link this batch generates (ArticleCard,
 * RelatedArticles, ShareButtons' `url` prop, JSON-LD, og:url) is already
 * consistent with this shape — see `_lib/seo.ts`'s `buildArticleUrl`.
 *
 * ACCESS-MODEL DEVIATION (flagged for mig-orchestrator sign-off before any
 * real flip — NOT a merge blocker this round): article.php has NO auth gate
 * at all (no `includes/header.php`, no `$_SESSION` check, no
 * `isSuperAdmin()`/`isAdmin()`/`isStaff()` anywhere — grepped the full
 * file). This port sits under `(tenant)/**`, whose `layout.tsx`
 * unconditionally redirects unauthenticated requests to `/auth/login` (via
 * `requireTenantPageContext()` below) — so a public reader with a shared
 * article link will now hit a login wall instead of the article, a real
 * behavior change from the legacy public page. This is a consequence of the
 * route boundary this batch was given (`articles/**` nested under the
 * existing `(tenant)` realm, the only realm with an established
 * `requireTenantPageContext()`/Kysely convention to reuse) and is called
 * out here for the orchestrator to weigh before this route is ever put
 * behind the real public `/articles` path at flip time.
 *
 * MISSING/UNPUBLISHED SLUG: article.php sends BOTH `header('HTTP/1.0 404
 * Not Found')` AND `header('Location: articles.php')` (lines 45-46) — a
 * self-contradictory combo (a real 404 has no meaningful redirect target).
 * Per this batch's brief, the OBSERVED behavior to replicate is "redirect
 * to /articles", not a bare 404 page — implemented below with
 * `redirect('/articles')`.
 *
 * HEADER/FOOTER "หน้าแรก" LINKS: article.php's header logo and footer both
 * link to `index.php` (the public landing page) and the footer additionally
 * links to `privacy-policy.php`. Neither route exists in this realm/batch
 * (the public landing page is Phase 12 scope; this port lives under the
 * tenant admin app, which has no `privacy-policy` route at all) — linking
 * to either would 404. Both "หน้าแรก" links point at `/dashboard` (this
 * realm's actual landing page) instead, and the privacy-policy footer link
 * is omitted rather than pointing at a route that doesn't exist. A markup
 * adaptation, not a data/behavior one — no acceptance-criteria data point
 * covers footer links.
 */
export interface ArticleDetailPageProps {
  params: Promise<{ slug: string }>;
}

const RELATED_ARTICLES_LIMIT = 3;

export async function generateMetadata({ params }: ArticleDetailPageProps): Promise<Metadata> {
  const { slug } = await params;
  const { db } = await requireTenantPageContext();
  const lineAccountId = await resolveDefaultLineAccountId(db);
  const [{ shopName }, article] = await Promise.all([getShopSettings(db, lineAccountId), getArticleBySlug(db, slug, lineAccountId)]);

  if (!article) {
    // The page component (not generateMetadata) owns the actual redirect-on-missing-slug behavior —
    // this is just a harmless fallback title for the brief instant Next may resolve metadata before
    // the redirect takes effect.
    return { title: `บทความสุขภาพ | ${shopName}` };
  }

  const metaTitle = article.meta_title ?? article.title;
  const metaDescription = article.meta_description ?? article.excerpt ?? '';
  const baseUrl = await getRequestBaseUrl();
  const url = buildArticleUrl(baseUrl, slug);

  return {
    title: `${metaTitle} | ${shopName}`,
    description: metaDescription,
    keywords: article.meta_keywords ?? undefined,
    openGraph: {
      title: metaTitle,
      description: metaDescription,
      type: 'article',
      url,
      images: article.featured_image ? [{ url: article.featured_image }] : undefined,
      publishedTime: article.published_at ? toArticleIsoDateTime(article.published_at) : undefined,
      authors: article.author_name ? [article.author_name] : undefined,
    },
  };
}

export default async function ArticleDetailPage({ params }: ArticleDetailPageProps) {
  const { slug } = await params;
  const { db } = await requireTenantPageContext();
  const lineAccountId = await resolveDefaultLineAccountId(db);
  const [{ shopName, shopLogo }, article] = await Promise.all([getShopSettings(db, lineAccountId), getArticleBySlug(db, slug, lineAccountId)]);

  if (!article) {
    redirect('/articles');
  }

  // Fired exactly once per page render, unconditionally on every successful lookup — matches
  // HealthArticleService::getBySlug()'s side effect. See actions.ts's own doc for why this is a
  // direct call, not a <form action>.
  await incrementViewCountAction(article.id);

  const relatedArticles = await getRelatedArticles(db, article.id, article.category_id, RELATED_ARTICLES_LIMIT);
  const tags = parseArticleTags(article.tags);
  const metaDescription = article.meta_description ?? article.excerpt ?? '';

  const baseUrl = await getRequestBaseUrl();
  const articleUrl = buildArticleUrl(baseUrl, slug);

  const jsonLd = buildArticleJsonLd({
    title: article.title,
    description: metaDescription,
    featuredImage: article.featured_image,
    publishedAtIso: toArticleIsoDateTime(article.published_at),
    updatedAtIso: toArticleIsoDateTime(article.updated_at),
    authorName: article.author_name,
    authorTitle: article.author_title,
    shopName,
    shopLogo,
  });

  return (
    <div className="mx-auto max-w-3xl px-4 py-6">
      {/* eslint-disable-next-line react/no-danger */}
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }} />

      <div className="mb-4 flex items-center justify-between">
        <a href="/articles" className="flex items-center gap-2 text-sm font-medium text-gray-500 hover:text-emerald-700">
          <span aria-hidden="true">←</span>
          <span>บทความทั้งหมด</span>
        </a>
        <a href="/dashboard" className="flex items-center gap-2 text-sm font-semibold text-gray-800">
          {shopLogo ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={shopLogo} alt={shopName} className="h-9 w-9 rounded-lg object-cover" />
          ) : null}
          <span>{shopName}</span>
        </a>
      </div>

      <article className="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-black/5">
        <div className="aspect-video bg-gray-100">
          {article.featured_image ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={article.featured_image} alt={article.title} className="h-full w-full object-cover" />
          ) : (
            <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-emerald-50 to-gray-200 text-6xl text-emerald-600/50" aria-hidden="true">
              📰
            </div>
          )}
        </div>

        <div className="p-6 md:p-10">
          {article.category_name ? (
            <span className="mb-4 inline-block rounded-full bg-emerald-50 px-3 py-1 text-[13px] font-medium text-emerald-700">{article.category_name}</span>
          ) : null}

          <h1 className="mb-4 text-2xl font-bold leading-tight text-gray-800 md:text-4xl">{article.title}</h1>

          <div className="mb-6 flex flex-wrap gap-4 border-b border-gray-200 pb-6 text-sm text-gray-500">
            {article.author_name ? (
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">
                  {article.author_image ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img src={article.author_image} alt="" className="h-full w-full rounded-full object-cover" />
                  ) : (
                    <span aria-hidden="true">👨‍⚕️</span>
                  )}
                </div>
                <div className="leading-tight">
                  <div className="font-semibold text-gray-800">{article.author_name}</div>
                  {article.author_title ? <div className="text-[13px] text-gray-500">{article.author_title}</div> : null}
                </div>
              </div>
            ) : null}

            {article.published_at ? (
              <div className="flex items-center gap-2">
                <span aria-hidden="true">🗓️</span>
                {formatArticleDate(article.published_at)}
              </div>
            ) : null}

            <div className="flex items-center gap-2">
              <span aria-hidden="true">👁️</span>
              {formatNumber(article.view_count)} views
            </div>
          </div>

          {/* eslint-disable-next-line react/no-danger */}
          <div className="prose max-w-none text-[1.05rem] leading-8 text-gray-700" dangerouslySetInnerHTML={{ __html: article.content }} />

          {tags.length > 0 ? (
            <div className="mt-8 flex flex-wrap gap-2 border-t border-gray-200 pt-6">
              {tags.map((tag) => (
                <a
                  key={tag}
                  href={`/articles?tag=${encodeURIComponent(tag)}`}
                  className="rounded-full bg-gray-100 px-3.5 py-1.5 text-[13px] text-gray-600 hover:bg-emerald-50 hover:text-emerald-700"
                >
                  #{tag}
                </a>
              ))}
            </div>
          ) : null}

          <ShareButtons url={articleUrl} title={article.title} />
        </div>
      </article>

      <RelatedArticles articles={relatedArticles} />

      <footer className="mt-4 rounded-2xl bg-gray-800 px-6 py-8 text-center text-white">
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
