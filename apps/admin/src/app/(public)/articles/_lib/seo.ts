import { headers } from 'next/headers';

/**
 * seo.ts — base-URL resolution + the JSON-LD payload builder shared by
 * `/articles/[slug]`'s generateMetadata() and page body.
 *
 * BASE URL — DELIBERATE DEVIATION from article.php's literal `BASE_URL`
 * (config/config.php: `'https://clinicya.re-ya.com/'`, a single hardcoded
 * host) used for `og:url` (line 85) and every share link (lines 606/610/614).
 * This app is multi-tenant with subdomain-per-tenant routing (ADR-001,
 * CLAUDE.md) — a hardcoded single-tenant origin would produce a WRONG
 * canonical/share URL for every tenant except the one PHP's constant points
 * at. Uses the incoming request's own scheme+host instead — the SAME fix
 * already applied to `image_url` in
 * apps/admin/src/app/api/miniapp/checkout/order/_lib/uploadSlip.ts (see its
 * module doc: "the hardcoded BASE_URL points at a single tenant subdomain
 * whose docroot does not contain this shared upload dir"). An
 * `ARTICLES_BASE_URL` env override is offered for environments where
 * `x-forwarded-*`/`host` headers aren't trustworthy, mirroring
 * checkout/order/_lib/notify.ts's `CHECKOUT_NOTIFY_BASE_URL` convention —
 * expected unset in normal per-tenant-subdomain operation.
 */
export async function getRequestBaseUrl(): Promise<string> {
  const envOverride = process.env.ARTICLES_BASE_URL;
  if (envOverride && envOverride.trim() !== '') {
    return envOverride.replace(/\/+$/, '');
  }
  const h = await headers();
  const proto = h.get('x-forwarded-proto') ?? 'https';
  const host = h.get('x-forwarded-host') ?? h.get('host') ?? 'localhost';
  return `${proto}://${host}`;
}

/**
 * Canonical article URL for the given origin. DELIBERATE URL-SHAPE
 * DECISION: article.php is a separate top-level legacy file
 * (`article.php?slug=...`); this batch's path boundary is `articles/**`
 * only, so the port lives at a NESTED `/articles/[slug]` segment rather
 * than a second top-level `/article` route. Every outbound link this batch
 * generates (og:url, JSON-LD is silent on url/mainEntityOfPage — see
 * buildArticleJsonLd's doc, share links, "related articles"/"all articles"
 * navigation) uses this shape consistently.
 */
export function buildArticleUrl(baseUrl: string, slug: string): string {
  return `${baseUrl}/articles/${encodeURIComponent(slug)}`;
}

export interface ArticleJsonLdInput {
  title: string;
  description: string;
  featuredImage: string | null;
  publishedAtIso: string;
  updatedAtIso: string;
  authorName: string | null;
  authorTitle: string | null;
  shopName: string;
  shopLogo: string;
}

/**
 * Structural port of article.php's inline `<script type="application/ld+json">`
 * block (lines 487-518) — same field set (`headline`/`description`/`image`/
 * `datePublished`/`dateModified`/`author`/`publisher`; no top-level `url` or
 * `mainEntityOfPage` — the PHP source never emits either), same
 * optional-field omission rules (`image`/`author`/`author.jobTitle`/
 * `publisher.logo` only present when the source value is non-empty,
 * mirroring each `<?php if (...): ?>` guard). `datePublished`/`dateModified`
 * are ISO-8601-normalized (see format.ts's toArticleIsoDateTime doc) rather
 * than PHP's raw un-formatted DB string — a schema.org-correctness fix, not
 * a value change.
 */
export function buildArticleJsonLd(input: ArticleJsonLdInput): Record<string, unknown> {
  const jsonLd: Record<string, unknown> = {
    '@context': 'https://schema.org',
    '@type': 'Article',
    headline: input.title,
    description: input.description,
  };

  if (input.featuredImage) {
    jsonLd.image = input.featuredImage;
  }

  jsonLd.datePublished = input.publishedAtIso;
  jsonLd.dateModified = input.updatedAtIso;

  if (input.authorName) {
    const author: Record<string, unknown> = { '@type': 'Person', name: input.authorName };
    if (input.authorTitle) {
      author.jobTitle = input.authorTitle;
    }
    jsonLd.author = author;
  }

  const publisher: Record<string, unknown> = { '@type': 'Organization', name: input.shopName };
  if (input.shopLogo) {
    publisher.logo = { '@type': 'ImageObject', url: input.shopLogo };
  }
  jsonLd.publisher = publisher;

  return jsonLd;
}
