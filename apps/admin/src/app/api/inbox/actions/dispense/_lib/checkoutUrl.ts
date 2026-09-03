import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * checkoutUrl.ts — port of the subset of includes/liff-helper.php that `case 'dispense':`
 * (inbox-v2.php lines 469-736) actually calls: `reya_is_real_liff_id()`, `reya_oa_chat_url()`,
 * `reya_append_liff_context_params()`, and `reya_liff_url_or_oa()`. Deliberately does NOT port
 * `getUnifiedLiffId()`, `getShopSettings()`, or `getLineAccountIdFromUser()` — unrelated helpers
 * in the same PHP file that the dispense flow never calls.
 *
 * `reya_get_line_account_link_row()` (the private lookup `reya_liff_url_or_oa()` depends on) IS
 * ported, as a non-exported local helper — it's not "unrelated", it's `reya_liff_url_or_oa()`'s
 * own query. The PHP version wraps it in a per-request static-array cache (comment: "avoids a
 * repeat query when the same account is resolved twice in one webhook/dispense call"); that
 * cache is a pure performance optimization with zero observable effect on the return value, and
 * a Next.js Route Handler invocation is itself already a single "one account resolved once"
 * request scope, so the cache layer is dropped here (nothing else in the dispense flow calls
 * this helper more than once per request).
 */

/**
 * Decide whether a stored liff_id is a REAL, usable LIFF id.
 *
 * A tenant counts as "LIFF-connected" only when its liff_id holds a genuine value. Two states
 * mean "not connected yet": empty/null (never set), or a `PENDING…` provisioning placeholder
 * (case-insensitive prefix match) seeded for tenants that have an OA but haven't finished LIFF
 * setup. Port of `reya_is_real_liff_id()` (includes/liff-helper.php).
 */
export function reyaIsRealLiffId(liffId: string | null | undefined): boolean {
  if (liffId === null || liffId === undefined) {
    return false;
  }
  const trimmed = liffId.trim();
  if (trimmed === '') {
    return false;
  }
  if (trimmed.toUpperCase().startsWith('PENDING')) {
    return false;
  }
  return true;
}

export interface LineAccountLinkRow {
  id: number;
  liff_id: string | null;
  basic_id: string | null;
  name: string | null;
}

/**
 * Build the OA chat fallback URL for a line account. Uses the LINE Basic ID
 * (line_accounts.basic_id, e.g. "@abc1234"). Returns '' when no basic_id is on file — caller
 * then renders NO button. Port of `reya_oa_chat_url()`.
 */
export function reyaOaChatUrl(account: Pick<LineAccountLinkRow, 'basic_id'>): string {
  const basicId = (account.basic_id ?? '').trim();
  if (basicId === '') {
    return '';
  }
  return 'https://line.me/R/ti/p/' + encodeURIComponent(basicId);
}

/**
 * Append Mini-App routing context (`la=`, `liff_id=`) while preserving any URL fragment. Port of
 * `reya_append_liff_context_params()`.
 */
export function reyaAppendLiffContextParams(url: string, lineAccountId: number, liffId: string): string {
  let fragment = '';
  let base = url;
  const hashPos = url.indexOf('#');
  if (hashPos !== -1) {
    fragment = url.slice(hashPos);
    base = url.slice(0, hashPos);
  }

  const sep = base.includes('?') ? '&' : '?';
  return base + sep + 'la=' + encodeURIComponent(String(lineAccountId)) + '&liff_id=' + encodeURIComponent(liffId) + fragment;
}

/**
 * Fetch the minimal line_accounts row needed for link decisions. Port of
 * `reya_get_line_account_link_row()` (caching layer dropped — see this file's module doc).
 */
async function getLineAccountLinkRow(db: Kysely<TenantDB>, lineAccountId: number | null): Promise<LineAccountLinkRow | null> {
  try {
    if (lineAccountId !== null && lineAccountId > 0) {
      const result = await sql<LineAccountLinkRow>`
        SELECT id, liff_id, basic_id, name FROM line_accounts WHERE id = ${lineAccountId} LIMIT 1
      `.execute(db);
      return result.rows[0] ?? null;
    }
    const result = await sql<LineAccountLinkRow>`
      SELECT id, liff_id, basic_id, name FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1
    `.execute(db);
    return result.rows[0] ?? null;
  } catch {
    return null;
  }
}

/**
 * SINGLE entry point: return the URL a Mini-App button should open for a tenant.
 *   1. line_accounts.liff_id is REAL -> LIFF deep link
 *        "https://liff.line.me/{liffId}{deepLinkPath}?la={lineAccountId}&liff_id={liffId}"
 *   2. otherwise -> OA chat URL (reyaOaChatUrl), or ''
 * Port of `reya_liff_url_or_oa()`.
 */
export async function reyaLiffUrlOrOa(db: Kysely<TenantDB>, lineAccountId: number | null, deepLinkPath = ''): Promise<string> {
  const account = await getLineAccountLinkRow(db, lineAccountId);
  if (account === null) {
    return '';
  }

  if (reyaIsRealLiffId(account.liff_id)) {
    const base = 'https://liff.line.me/' + account.liff_id;
    return reyaAppendLiffContextParams(base + deepLinkPath, account.id, account.liff_id as string);
  }

  return reyaOaChatUrl(account);
}
