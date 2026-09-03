/**
 * format.ts — display formatting for article rows, ported from articles.php
 * / article.php's inline `date()`/`number_format()`/`json_decode()` calls.
 * No Buddhist-era conversion anywhere (grepped both PHP files + classes/
 * HealthArticleService.php for '543'/'buddhist'/'setlocale' — zero hits);
 * `packages/core/dates` (plan §1.1/§5) doesn't exist yet on this branch
 * either way and is outside this batch's allowed paths — same reasoning
 * users/_lib/format.ts's own doc comment gives for not reaching for one.
 *
 * mysql2 (no `dateStrings`/`timezone` option set — see
 * packages/db/src/tenantPoolRegistry.ts) round-trips a DATETIME column's
 * Bangkok wall-clock digits (the pool's `SET time_zone='+07:00'` init makes
 * every stored DATETIME literal already-Bangkok-local) onto a JS Date
 * WITHOUT shifting them; reading them back with the Date object's LOCAL
 * getters is the same convention users/_lib/format.ts's formatDateDMY()
 * already established for this codebase — do not switch to
 * `Intl.DateTimeFormat(..., {timeZone:'Asia/Bangkok'})` here, that would
 * double-apply an offset that was never actually removed.
 */

const MONTH_ABBR = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/** Mirrors PHP's `date('d M Y', strtotime($ts))` — e.g. "14 Jul 2026" (articles.php:483, article.php:581). */
export function formatArticleDate(value: Date | string | null | undefined): string {
  if (!value) return '';
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  const day = String(d.getDate()).padStart(2, '0');
  const month = MONTH_ABBR[d.getMonth()];
  return `${day} ${month} ${d.getFullYear()}`;
}

/**
 * ISO-8601-with-offset for the JSON-LD `datePublished`/`dateModified`
 * fields (article.php lines 496-497) and Open Graph's `article:published_
 * time` (line 89). DELIBERATE NORMALIZATION, not byte-parity: PHP
 * interpolates the raw DB DATETIME string unescaped/unformatted (e.g.
 * "2026-07-14 09:00:00"), which is not valid ISO 8601 (no `T` separator, no
 * offset) — schema.org/Open-Graph consumers expect ISO 8601. This emits the
 * SAME Bangkok wall-clock digits (see module doc) with a `T` separator and
 * the explicit `+07:00` offset CLAUDE.md documents as the app's fixed
 * timezone, which is a strictly more correct rendering of the identical
 * instant, not a different one.
 */
export function toArticleIsoDateTime(value: Date | string | null | undefined): string {
  if (!value) return '';
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}+07:00`;
}

/**
 * Mirrors article.php line 56: `json_decode($article['tags'], true) ?? []`
 * (guarded by `if (!empty($article['tags']))`, line 55). Any decode failure
 * or non-array JSON value (health_articles.tags is only ever written by
 * HealthArticleService::create()/update() as `json_encode(string[])`, so a
 * non-array shape is not a real-world case, only a defensive one) falls
 * back to `[]`, matching PHP's `?? []`.
 */
export function parseArticleTags(raw: string | null | undefined): string[] {
  if (!raw) return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? (parsed as string[]) : [];
  } catch {
    return [];
  }
}
