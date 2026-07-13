import { z } from 'zod';

/**
 * resolve-line-account.ts — zod contracts for api/resolve-line-account.php
 * (GET only). Read in full before writing this file (186 lines).
 *
 * WHY THIS ENDPOINT EXISTS: line-mini-app is ONE static export shared by
 * every tenant; when a LIFF session only knows its LIFF id (no `?la=` deep
 * link), it asks this platform-level, tenant-AGNOSTIC endpoint to map that
 * LIFF id back to the owning `line_account_id` (+ tenant). It deliberately
 * does NOT depend on subdomain/session resolution — mirrors
 * `REYA_SKIP_SUBDOMAIN_RESOLUTION` (see the phase-3-batch-1 brief's tenant
 * two-phase-pin note: this is the ONE exception that skips
 * `resolveMiniappTenantContext()` entirely).
 *
 * RESPONSE ENVELOPE — bespoke, NOT `flatSuccessEnvelope()`: no `message` key
 * on any branch, `error` (not `message`) on failure, and REAL HTTP status
 * codes (400/404/503) — see rla_respond()'s `http_response_code($status)`
 * call.
 *
 * Cache-Control — PORTS PHP'S REAL OBSERVED BEHAVIOR, NOT ITS SOURCE-LEVEL
 * INTENT (mig-verify Phase 3 batch 1 finding). The PHP file's OWN `header()`
 * call near its top literally says `Cache-Control: public, max-age=300`, but
 * that value never reaches a real client: `require_once
 * __DIR__.'/../config/config.php'` runs right after, and config.php
 * unconditionally calls `session_start()` when no session is active. PHP's
 * session module (default `session.cache_limiter=nocache`, unmodified by
 * this repo's php.ini) emits its OWN `Cache-Control`/`Pragma`/`Expires`
 * headers via `header()`, which — same header name, default `replace=true`
 * — SILENTLY OVERWRITES the earlier `public, max-age=300` value. Confirmed
 * against a real running PHP container (curl -I), not just the source
 * file's stated header() call. The value every real client actually
 * receives is `no-store, no-cache, must-revalidate` — preserved here as
 * `RESOLVE_LINE_ACCOUNT_CACHE_CONTROL` for the route handler to set
 * literally, not re-derived.
 */

// ---------------------------------------------------------------------------
// GET request
// ---------------------------------------------------------------------------

/**
 * Mirrors `rla_valid_liff_id()`: non-empty, <=64 chars, `^[A-Za-z0-9-]+$`.
 * A `liff_id` failing this check is a 400 `invalid_liff_id`, not a zod parse
 * failure on the request itself — the route handler validates this at
 * runtime the same way PHP does (regex check), not via `.regex()` here, so
 * the 400 response shape/body matches the PHP branch exactly. The query
 * schema below only requires the key be a string; the format check is a
 * separate, explicitly-named export so the route handler's control flow
 * mirrors `rla_valid_liff_id()` 1:1.
 */
export const LIFF_ID_PATTERN = /^[A-Za-z0-9-]+$/;
export const LIFF_ID_MAX_LENGTH = 64;

export function isValidLiffId(liffId: string): boolean {
  return liffId !== '' && liffId.length <= LIFF_ID_MAX_LENGTH && LIFF_ID_PATTERN.test(liffId);
}

export const ResolveLineAccountQuerySchema = z.object({
  liff_id: z.string().optional(),
});
export type ResolveLineAccountQuery = z.infer<typeof ResolveLineAccountQuerySchema>;

// ---------------------------------------------------------------------------
// Response — union of the success shape and every named failure `error` code
// ---------------------------------------------------------------------------

export const ResolveLineAccountOkSchema = z.object({
  success: z.literal(true),
  line_account_id: z.number(),
  tenant_id: z.number(),
  tenant_slug: z.string(),
});
export type ResolveLineAccountOk = z.infer<typeof ResolveLineAccountOkSchema>;

/** The four named failure codes rla_respond() ever emits, each with its own fixed HTTP status (see RESOLVE_LINE_ACCOUNT_STATUS). */
export const ResolveLineAccountErrorCodeSchema = z.enum([
  'invalid_liff_id',
  'platform_unavailable',
  'not_found',
]);
export type ResolveLineAccountErrorCode = z.infer<typeof ResolveLineAccountErrorCodeSchema>;

export const ResolveLineAccountFailSchema = z.object({
  success: z.literal(false),
  error: ResolveLineAccountErrorCodeSchema,
});
export type ResolveLineAccountFail = z.infer<typeof ResolveLineAccountFailSchema>;

export const ResolveLineAccountResponseSchema = z.union([
  ResolveLineAccountOkSchema,
  ResolveLineAccountFailSchema,
]);
export type ResolveLineAccountResponse = z.infer<typeof ResolveLineAccountResponseSchema>;

/** HTTP status PHP's rla_respond() uses for each branch — the route handler must match these exactly. */
export const RESOLVE_LINE_ACCOUNT_STATUS = {
  ok: 200,
  invalid_liff_id: 400,
  platform_unavailable: 503,
  not_found: 404,
} as const satisfies Record<'ok' | ResolveLineAccountErrorCode, number>;

/**
 * Set on EVERY response (success or failure) — matches what a real client
 * actually receives (PHP's session-module `nocache` limiter clobbers the
 * source file's own `public, max-age=300` header() call — see module doc
 * above), not the PHP source's literal header() argument.
 */
export const RESOLVE_LINE_ACCOUNT_CACHE_CONTROL = 'no-store, no-cache, must-revalidate' as const;
