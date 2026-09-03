/**
 * params.ts — literal ports of articles.php's two `$_GET` reads (lines
 * 44-45):
 *
 *   $categoryId = isset($_GET['category']) ? (int)$_GET['category'] : null;
 *   $searchQuery = $_GET['q'] ?? '';
 *   ...
 *   if ($searchQuery) { $articles = $articleService->search($searchQuery, 20); }
 *
 * Both preserve PHP-specific truthiness/cast quirks on purpose — see each
 * function's own doc comment.
 */

/** Collapses Next's `string | string[] | undefined` searchParams shape to the single-value string $_GET always gives PHP (repeated keys pick the first, matching PHP's own "last one wins" — actually PHP keeps only the LAST repeated key for a non-array param; a single value either way is what real links on this page ever produce, so first-vs-last is not observable). */
export function firstParam(value: string | string[] | undefined): string {
  if (Array.isArray(value)) return value[0] ?? '';
  return value ?? '';
}

/** PHP's (int) cast: leading whitespace ignored, an optional sign + leading digit run parsed, 0 for anything else (empty, non-numeric, "abc123"). */
function phpIntCast(value: string): number {
  const match = value.trim().match(/^[+-]?\d+/);
  if (!match) return 0;
  const n = Number.parseInt(match[0], 10);
  return Number.isNaN(n) ? 0 : n;
}

/**
 * Mirrors `(int)$_GET['category']` + the `if ($categoryId)` truthy checks
 * gating the WHERE clause (HealthArticleService::getPublishedArticles) and
 * the active-chip highlight (articles.php line 437). 0 (absent/non-numeric)
 * means "no filter" -> null. A negative id (`?category=-5`) IS truthy in
 * PHP and is preserved as a real (non-matching) filter value here too, not
 * special-cased to null — faithful to the literal cast+truthy-check, not a
 * "helpful" reinterpretation of it.
 */
export function parseCategoryIdParam(raw: string | string[] | undefined): number | null {
  const n = phpIntCast(firstParam(raw));
  return n !== 0 ? n : null;
}

/**
 * Mirrors PHP's `if ($searchQuery)` on `$_GET['q'] ?? ''` — no `trim()`
 * anywhere in articles.php, so a whitespace-only query IS treated as a real
 * search term (falls into HealthArticleService::search(), not
 * getPublishedArticles()). PHP string falsiness applies: only `''` and the
 * literal string `'0'` are falsy — a search for the single character "0"
 * would (per the real PHP source) silently fall through to the unfiltered
 * article list instead of running as a search. Replicated verbatim, not
 * "fixed".
 */
export function isSearchQueryPresent(value: string): boolean {
  return value !== '' && value !== '0';
}
