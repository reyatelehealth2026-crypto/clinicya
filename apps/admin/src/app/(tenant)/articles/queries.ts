import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * queries.ts — literal Kysely ports of classes/HealthArticleService.php's
 * read methods, plus the default-line-account-id resolution and
 * shop_settings lookup both articles.php and article.php inline at the top
 * of their own file (identical block in both — lines 13-35 of articles.php,
 * 13-37 of article.php). Read both PHP files and the full service class in
 * full before editing this file.
 *
 * Written as raw `sql` fragments (not Kysely's typed `.selectFrom()`
 * builder) — this codebase's established house style for ported queries,
 * see templates/queries.ts's own doc comment (no CamelCasePlugin on the
 * shared Kysely<TenantDB> instance).
 *
 * COLUMN TRIMMING (not byte-parity, deliberately): every one of
 * HealthArticleService's four read methods selects `a.*` (every
 * health_articles column). Each function below instead selects only the
 * columns the corresponding PHP template actually renders — the same
 * established convention as loyalty-members/queries.ts's `SELECT id,
 * display_name, ...` (not `SELECT *`) and templates/queries.ts. Filter/
 * order/limit/param logic is preserved exactly; unread columns are not
 * fetched. One consequence worth flagging: `getPublishedArticles`/`getBySlug`
 * additionally select `c.slug AS category_slug` in the PHP source — grepped
 * both templates for `category_slug` and found zero uses in the rendered
 * HTML, so it is not ported here either.
 *
 * LINE-ACCOUNT SCOPING: HealthArticleService's constructor takes a nullable
 * `?int $lineAccountId` and every query method skips the `(a.line_account_id
 * = ? OR a.line_account_id IS NULL)` filter when it's null. Both PHP pages
 * always construct the service with a concrete resolved int (`$lineAccountId
 * = $lineAccount['id'] ?? 1`, never null) — the null branch is dead code on
 * the only two call sites that exist. `getPublishedArticles`/`getArticleBySlug`
 * below take `lineAccountId: number` (not nullable) and always apply the
 * filter, matching the only path actually exercised.
 *
 * VERBATIM QUIRK, PRESERVED ON PURPOSE: `HealthArticleService::search()` and
 * `::getRelatedArticles()` do NOT apply the line_account_id filter at all
 * (re-read both methods — no `$this->lineAccountId` reference anywhere in
 * either). A search hit or a "related articles" pick can therefore surface
 * another LINE account's article. This looks like a bug but is the real,
 * current PHP behavior — `searchArticles`/`getRelatedArticles` below
 * deliberately do not take a `lineAccountId` parameter, so a future editor
 * can't "fix" it by accident.
 */

export interface ArticleCategoryRow {
  id: number;
  name: string;
}

export interface ArticleListRow {
  id: number;
  slug: string;
  title: string;
  excerpt: string | null;
  featured_image: string | null;
  is_featured: number | null;
  author_name: string | null;
  published_at: Date | null;
  category_name: string | null;
}

export interface RelatedArticleRow {
  id: number;
  slug: string;
  title: string;
  featured_image: string | null;
}

export interface ArticleDetailRow {
  id: number;
  slug: string;
  title: string;
  excerpt: string | null;
  content: string;
  featured_image: string | null;
  author_name: string | null;
  author_title: string | null;
  author_image: string | null;
  category_id: number | null;
  category_name: string | null;
  published_at: Date | null;
  updated_at: Date | null;
  view_count: number;
  tags: string | null;
  meta_title: string | null;
  meta_description: string | null;
  meta_keywords: string | null;
}

export interface ShopSettingsInfo {
  shopName: string;
  shopLogo: string;
}

const DEFAULT_SHOP_NAME = 'LINE Telepharmacy';

/**
 * Ported from articles.php lines 14-24 / article.php lines 14-24 (identical
 * in both):
 *
 *   try {
 *       $stmt = $db->query("SELECT * FROM line_accounts WHERE is_default = 1 LIMIT 1");
 *       $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
 *       if (!$lineAccount) {
 *           $stmt = $db->query("SELECT * FROM line_accounts ORDER BY id ASC LIMIT 1");
 *           $lineAccount = $stmt->fetch(PDO::FETCH_ASSOC);
 *       }
 *   } catch (Exception $e) {}
 *   $lineAccountId = $lineAccount['id'] ?? 1;
 *
 * Both queries share ONE try/catch in PHP — if the first query throws, the
 * second is never attempted either (not "try each independently"), which is
 * why both live inside this function's single try block below. Only `id` is
 * selected (not `SELECT *`) — `article.php` also reads `liff_id` off this
 * row (line 25) into `$liffId`/`$liffUrl`, but neither is referenced
 * anywhere else in the file (grepped) — dead PHP locals, not ported.
 */
export async function resolveDefaultLineAccountId(db: Kysely<TenantDB>): Promise<number> {
  try {
    let result = await sql<{ id: number }>`SELECT id FROM line_accounts WHERE is_default = 1 LIMIT 1`.execute(db);
    let row = result.rows[0];
    if (!row) {
      result = await sql<{ id: number }>`SELECT id FROM line_accounts ORDER BY id ASC LIMIT 1`.execute(db);
      row = result.rows[0];
    }
    return row?.id ?? 1;
  } catch {
    return 1;
  }
}

/**
 * Ported from articles.php lines 26-35 / article.php lines 28-37 (identical
 * in both):
 *
 *   try {
 *       $stmt = $db->prepare("SELECT * FROM shop_settings WHERE line_account_id = ? LIMIT 1");
 *       $stmt->execute([$lineAccountId]);
 *       $shopSettings = $stmt->fetch(PDO::FETCH_ASSOC);
 *   } catch (Exception $e) {}
 *   $shopName = $shopSettings['shop_name'] ?? 'LINE Telepharmacy';
 *   $shopLogo = $shopSettings['shop_logo'] ?? '';
 */
export async function getShopSettings(db: Kysely<TenantDB>, lineAccountId: number): Promise<ShopSettingsInfo> {
  try {
    const result = await sql<{ shop_name: string | null; shop_logo: string | null }>`
      SELECT shop_name, shop_logo FROM shop_settings WHERE line_account_id = ${lineAccountId} LIMIT 1
    `.execute(db);
    const row = result.rows[0];
    return { shopName: row?.shop_name ?? DEFAULT_SHOP_NAME, shopLogo: row?.shop_logo ?? '' };
  } catch {
    return { shopName: DEFAULT_SHOP_NAME, shopLogo: '' };
  }
}

/** Port of `HealthArticleService::getCategories()` (lines 151-159): `SELECT * FROM health_article_categories WHERE is_active = 1 ORDER BY sort_order ASC` — only `id`/`name` are read by either template's category-chip loop. */
export async function getArticleCategories(db: Kysely<TenantDB>): Promise<ArticleCategoryRow[]> {
  try {
    const result = await sql<ArticleCategoryRow>`
      SELECT id, name FROM health_article_categories WHERE is_active = 1 ORDER BY sort_order ASC
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

const ARTICLE_LIST_COLUMNS = sql`a.id, a.slug, a.title, a.excerpt, a.featured_image, a.is_featured, a.author_name, a.published_at, c.name AS category_name`;

/**
 * Port of `HealthArticleService::getPublishedArticles()` (lines 18-45).
 * `categoryId` truthy in PHP -> `AND a.category_id = ?`; falsy (0/null) ->
 * clause omitted entirely (two distinct query shapes, not one query with a
 * nullable bind param), matching the PHP source's `if ($categoryId) { $sql
 * .= ...; }` structure.
 */
export async function getPublishedArticles(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  limit: number,
  categoryId: number | null
): Promise<ArticleListRow[]> {
  try {
    const result = categoryId
      ? await sql<ArticleListRow>`
          SELECT ${ARTICLE_LIST_COLUMNS}
          FROM health_articles a
          LEFT JOIN health_article_categories c ON a.category_id = c.id
          WHERE a.is_published = 1
            AND (a.line_account_id = ${lineAccountId} OR a.line_account_id IS NULL)
            AND a.category_id = ${categoryId}
          ORDER BY a.is_featured DESC, a.published_at DESC
          LIMIT ${limit}
        `.execute(db)
      : await sql<ArticleListRow>`
          SELECT ${ARTICLE_LIST_COLUMNS}
          FROM health_articles a
          LEFT JOIN health_article_categories c ON a.category_id = c.id
          WHERE a.is_published = 1
            AND (a.line_account_id = ${lineAccountId} OR a.line_account_id IS NULL)
          ORDER BY a.is_featured DESC, a.published_at DESC
          LIMIT ${limit}
        `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

/**
 * Port of `HealthArticleService::search()` (lines 365-381). NO
 * line_account_id filter — see module doc's "VERBATIM QUIRK" note. Row
 * shape matches `getPublishedArticles`'s (both feed the same
 * `articles.php` grid/`ArticleCard` template) — `a.*` in the PHP source
 * really does include `is_featured`, so the "แนะนำ" badge legitimately can
 * appear on a search result too; this is not an oversight.
 */
export async function searchArticles(db: Kysely<TenantDB>, query: string, limit: number): Promise<ArticleListRow[]> {
  try {
    const like = `%${query}%`;
    const result = await sql<ArticleListRow>`
      SELECT ${ARTICLE_LIST_COLUMNS}
      FROM health_articles a
      LEFT JOIN health_article_categories c ON a.category_id = c.id
      WHERE a.is_published = 1
        AND (a.title LIKE ${like} OR a.excerpt LIKE ${like} OR a.content LIKE ${like})
      ORDER BY a.published_at DESC
      LIMIT ${limit}
    `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}

/**
 * Port of `HealthArticleService::getBySlug()` (lines 77-103) — READ HALF
 * ONLY. The PHP method also calls `$this->incrementViewCount($article['id'])`
 * as a side effect before returning (line 96) — deliberately NOT ported
 * here; see `_lib/mutations.ts`'s `incrementArticleViewCount` +
 * `actions.ts`'s `incrementViewCountAction` for that half, fired exactly
 * once per page render from `[slug]/page.tsx` instead of being baked into
 * this read (a Server Component data-loader must stay a pure read — Next
 * can and does call the same page/generateMetadata data twice in one
 * request; a side effect hidden inside a "get" would double-fire it).
 */
export async function getArticleBySlug(db: Kysely<TenantDB>, slug: string, lineAccountId: number): Promise<ArticleDetailRow | null> {
  try {
    const result = await sql<ArticleDetailRow>`
      SELECT
        a.id, a.slug, a.title, a.excerpt, a.content, a.featured_image,
        a.author_name, a.author_title, a.author_image,
        a.category_id, c.name AS category_name,
        a.published_at, a.updated_at, a.view_count, a.tags,
        a.meta_title, a.meta_description, a.meta_keywords
      FROM health_articles a
      LEFT JOIN health_article_categories c ON a.category_id = c.id
      WHERE a.slug = ${slug}
        AND a.is_published = 1
        AND (a.line_account_id = ${lineAccountId} OR a.line_account_id IS NULL)
      LIMIT 1
    `.execute(db);
    return result.rows[0] ?? null;
  } catch {
    return null;
  }
}

/**
 * Port of `HealthArticleService::getRelatedArticles()` (lines 335-360).
 * PHP re-fetches the article by id (`getById()`, no `is_published` filter)
 * purely to read its `category_id` before running the real query; the
 * caller here (`[slug]/page.tsx`) already has that same row's `category_id`
 * from its own `getArticleBySlug()` call (same article, same column, no
 * possible divergence — `getBySlug`'s `is_published = 1` filter already
 * matched), so it's passed straight in instead of re-querying. The
 * category-name JOIN in the PHP source is also dropped — the related-card
 * template (article.php lines 630-641) renders only `slug`/`featured_image`/
 * `title`, never `category_name`, and a LEFT JOIN on a 1:1 id lookup cannot
 * change which rows/how many rows come back, so omitting it changes
 * nothing observable. `categoryId` truthy -> `AND a.category_id = ?`
 * (two query shapes), matching PHP's `if ($article['category_id']) { ... }`.
 */
export async function getRelatedArticles(
  db: Kysely<TenantDB>,
  articleId: number,
  categoryId: number | null,
  limit: number
): Promise<RelatedArticleRow[]> {
  try {
    const result = categoryId
      ? await sql<RelatedArticleRow>`
          SELECT a.id, a.slug, a.title, a.featured_image
          FROM health_articles a
          WHERE a.is_published = 1 AND a.id != ${articleId} AND a.category_id = ${categoryId}
          ORDER BY a.published_at DESC
          LIMIT ${limit}
        `.execute(db)
      : await sql<RelatedArticleRow>`
          SELECT a.id, a.slug, a.title, a.featured_image
          FROM health_articles a
          WHERE a.is_published = 1 AND a.id != ${articleId}
          ORDER BY a.published_at DESC
          LIMIT ${limit}
        `.execute(db);
    return result.rows;
  } catch {
    return [];
  }
}
