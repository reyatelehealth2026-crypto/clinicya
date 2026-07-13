import { z } from 'zod';

/**
 * envelope.ts — shared zod helpers for the ported /api/miniapp/** endpoints
 * (Phase 3 batch 1, docs/plans/2026-07-12-nextjs-full-migration-plan.md).
 *
 * There is NOT one universal PHP response envelope across this batch's six
 * endpoints — each source PHP file has its own actual shape, verified by
 * reading it in full (see the brief's "RESPONSE ENVELOPE" contractNote):
 *
 *   - member.php / rewards.php: flat `{success, message, ...rest}` at the TOP
 *     level (PHP's local `jsonResponse($success, $message, $data = [])` does
 *     `array_merge(['success'=>.., 'message'=>..], $data)`), always HTTP 200
 *     (jsonResponse() never calls http_response_code()). THIS is the one
 *     shape modeled here, via `flatSuccessEnvelope()` — used by member.ts/
 *     rewards.ts (mig-api-writes' lane).
 *   - health-profile.php: `{success, ...}` per handler, WITH real HTTP status
 *     codes (400 validation, 500 DB error) — see health-profile.ts, which
 *     does NOT use flatSuccessEnvelope (no `message` key on most branches).
 *   - wishlist.php: ad hoc inline shape (`{success, items?, count?,
 *     is_favorite?, message?, error?}`), always implicit HTTP 200 — see
 *     wishlist.ts (mig-api-writes' lane), also not built on this helper.
 *   - points-history.php: ad hoc inline (`{success, user?, history?,
 *     error?}`), HTTP 200 on the normal error path — see points-history.ts.
 *   - resolve-line-account.php: `{success, line_account_id, tenant_id,
 *     tenant_slug}` / `{success:false, error}`, WITH real status codes
 *     (400/404/503) and a `Cache-Control` response header — see
 *     resolve-line-account.ts.
 *
 * So this file intentionally holds ONLY the one genuinely-shared helper
 * (`flatSuccessEnvelope`) — every bespoke per-endpoint shape lives in that
 * endpoint's own contract file, never forced through this one type.
 */

/**
 * Models member.php / rewards.php's `jsonResponse($success, $message, $data)`
 * shape: `{success: boolean, message: string, ...shape}` at the top level,
 * always HTTP 200. `shape` typically overrides `success` with a `z.literal()`
 * to discriminate the ok/fail branches of a `z.union(...)`.
 */
export function flatSuccessEnvelope<Shape extends z.ZodRawShape>(shape: Shape) {
  return z.object({
    success: z.boolean(),
    message: z.string(),
    ...shape,
  });
}
