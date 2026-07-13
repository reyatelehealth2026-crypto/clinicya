import { getMasterDb, getTenantDb } from '@reya/db';
import { RESOLVE_LINE_ACCOUNT_CACHE_CONTROL, RESOLVE_LINE_ACCOUNT_STATUS } from '@reya/contracts';
import { handleMiniappOptions, miniappJson } from '@/lib/miniapp/cors';
import { resolveLineAccountByLiffId } from './_lib/lookup';

/**
 * GET /api/miniapp/resolve-line-account — port of api/resolve-line-account.php.
 *
 * DELIBERATE EXCEPTION to the shared tenant two-phase pin
 * (apps/admin/src/lib/miniapp/tenant.ts): this endpoint is platform-level
 * and must NOT depend on subdomain/session resolution at all — mirrors the
 * PHP file's `define('REYA_SKIP_SUBDOMAIN_RESOLUTION', true)`. It therefore
 * never imports `resolveMiniappTenantContext()`; it talks to `getMasterDb()`
 * directly, plus a per-candidate-tenant `getTenantDb(tenant.id)` scan (see
 * `_lib/lookup.ts`).
 *
 * WHY THIS ENDPOINT EXISTS: line-mini-app is ONE static export shared by
 * every tenant. When a LIFF session only knows its LIFF id (no `?la=` deep
 * link in the URL), it asks this endpoint to map that LIFF id back to the
 * owning `line_account_id` (+ tenant), so the mini-app can resolve which
 * tenant it's serving at runtime.
 *
 * `Cache-Control` (see `RESOLVE_LINE_ACCOUNT_CACHE_CONTROL`'s own doc comment
 * in @reya/contracts for why its value is PHP's REAL observed header, not
 * the PHP source file's own `header()` literal) is set on EVERY response
 * (success or failure) — mirrors PHP setting it unconditionally before any
 * branching; not something to drop on error responses.
 */

/**
 * Custom (not the shared `handleMiniappOptions` export directly) so the
 * Cache-Control header lands here too — PHP sets its header unconditionally
 * BEFORE its own OPTIONS short-circuit, so the preflight response carries it
 * too.
 */
export function OPTIONS(): Response {
  const response = handleMiniappOptions();
  response.headers.set('Cache-Control', RESOLVE_LINE_ACCOUNT_CACHE_CONTROL);
  return response;
}

export async function GET(request: Request): Promise<Response> {
  const url = new URL(request.url);
  const liffId = (url.searchParams.get('liff_id') ?? '').trim();

  const master = getMasterDb();
  const result = await resolveLineAccountByLiffId(liffId, { master, getTenantDb });

  const status = result.success ? RESOLVE_LINE_ACCOUNT_STATUS.ok : RESOLVE_LINE_ACCOUNT_STATUS[result.error];
  const response = miniappJson(result, { status });
  response.headers.set('Cache-Control', RESOLVE_LINE_ACCOUNT_CACHE_CONTROL);
  return response;
}
