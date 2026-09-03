import { z } from 'zod';
import { flatSuccessEnvelope } from './envelope';

/**
 * consent.ts — zod contract for the ported `api/consent.php` action owned by
 * this batch: `save` ONLY (`action=save`, `handleSaveConsent()`).
 * `check`/`withdraw`/`history` are explicitly OUT of scope — `consent-api.ts`
 * (the only line-mini-app caller of this endpoint) calls `action=save`
 * exclusively; grepped, zero callers anywhere for the other three actions.
 * Read `api/consent.php` in full (327 lines) before touching this file.
 *
 * ============================================================================
 * DEVIATION #1 (flag exactly as prominently as addresses.ts's "no PHP
 * source" finding): TENANT-RESOLUTION BEHAVIOR IS DELIBERATELY NOT PRESERVED.
 * ============================================================================
 * `api/consent.php` is CONSPICUOUSLY MISSING `require_once
 * bootstrap/route_by_account.php` — every sibling file in this batch and
 * batch 1 (appointments.php, health-profile.php, medication-reminders.php,
 * member.php, checkout.php) requires it; consent.php and data-rights.php do
 * not. Practical effect in real production: when the mini-app calls
 * `api/consent.php` from the root domain (no Host-based subdomain match),
 * `TenantContext` never gets pinned by `line_account_id`, so
 * `Database::getInstance()->getConnection()` falls through to the
 * legacy/default DB, NOT the tenant DB — PDPA consent writes likely land in
 * the wrong database today. This is a genuine PRE-EXISTING BUG in the PHP
 * original, not something introduced by this migration.
 *
 * DECISION (mig-orchestrator sign-off, not re-litigated here): the Next port
 * uses the STANDARD two-phase `resolveMiniappTenantContext()`/
 * `withMiniappTenant()` helper — exactly like every other `/api/miniapp/**`
 * route — ignoring that the PHP original doesn't call
 * `route_by_account.php`. This is the SAME already-accepted policy
 * `apps/admin/src/lib/miniapp/tenant.ts`'s own doc comment documents at
 * point 2c ("There is NO legacy-DB fallback in the Next stack — deliberately
 * NOT replicated"). Net effect: the Next port's tenant resolution is
 * STRICTER/more-correct than the PHP original's for this one endpoint — a
 * byte-level behavioral deviation, unlike every other quirk in this batch
 * (which are preserved verbatim).
 *
 * PARITY HARNESS IMPACT: because of this deviation, a parity run that sends
 * the request with NO `Host` header (this batch's normal convention, per
 * `docs/runbooks/phase3-batch1-miniapp-api-parity.md` §3) would exercise
 * PHP's BROKEN legacy-DB-fallback path, not its real subdomain-resolution
 * path — the two stacks would legitimately write to different databases and
 * any diff would be meaningless. The harness's PHP-side call for
 * `consent:save` (and `data-rights:*`) needs a `Host` header PINNED TO THE
 * SEEDED TENANT SUBDOMAIN so PHP takes the subdomain-resolution code path it
 * actually has (the same one real mini-app traffic on a tenant subdomain
 * would take), matching the Next side's always-correct resolution.
 *
 * ============================================================================
 * DECISION #2: ActivityLogger::logConsent() SIDE EFFECT — DELIBERATELY NOT
 * PORTED.
 * ============================================================================
 * `handleSaveConsent()` calls `ActivityLogger::logConsent()` once PER CONSENT
 * TYPE, INSIDE the same DB transaction as the `user_consents` upserts (i.e.
 * NOT best-effort — a throw here rolls back the whole save). No Next-side
 * port of `ActivityLogger`'s WRITE path exists yet in this worktree (grepped
 * `apps/`/`packages/` — only a read-only `getLogs()`/`countLogs()` port
 * exists, at `apps/admin/src/app/(tenant)/activity-logs/queries.ts`, with no
 * accompanying writer). Building a parallel writer is out of this batch's
 * deliverables. DECISION: this side effect is a deliberately-NOT-ported
 * simplification — same "flagged simplification" pattern
 * `health-profile/_lib/query.ts`'s own doc comment already uses for the
 * table-auto-create it declined to port. If/when an `ActivityLogger` writer
 * lands on the Next side, `consent.ts`'s route handler should gain an
 * equivalent `logConsent()` call inside its own transaction to close this
 * gap — flagged here so it isn't silently forgotten.
 *
 * ENVELOPE: local `jsonResponse($success, $message='', $data=[])` =
 * `array_merge(['success'=>.., 'message'=>..], $data)`, always HTTP 200 (no
 * `http_response_code()` call anywhere in `api/consent.php`) —
 * `flatSuccessEnvelope()`-shaped, same as member.ts/rewards.ts.
 */

/**
 * PHP's `foreach ($consents as $type => $accepted)` accepts an arbitrary
 * string-keyed map with no server-side allowlist (`$versionMap[$type] ?? '1.0'`
 * falls back for unknown types) — modeled loosely here to match. The only
 * live caller (`consent-api.ts`) only ever sends `{ health_data: <boolean> }`.
 */
export const ConsentMapSchema = z.record(z.string(), z.union([z.boolean(), z.literal(0), z.literal(1)]));
export type ConsentMap = z.infer<typeof ConsentMapSchema>;

export const ConsentSaveRequestSchema = z.object({
  action: z.literal('save'),
  line_user_id: z.string(),
  consents: ConsentMapSchema,
});
export type ConsentSaveRequest = z.infer<typeof ConsentSaveRequestSchema>;

/** PHP hardcodes `jsonResponse(true, 'Consent saved', ['user_id' => $userId])` on the success path. */
const ConsentSaveOk = flatSuccessEnvelope({
  success: z.literal(true),
  message: z.literal('Consent saved'),
  user_id: z.number(),
});
/**
 * Failure branches: missing `line_user_id` -> `'LINE User ID required'`; an
 * uncaught exception during the transaction (rolled back) -> `$e->getMessage()`
 * (arbitrary string, same raw-message-leak convention as
 * `medication-reminders.ts`'s top-level catch).
 */
const ConsentSaveFail = flatSuccessEnvelope({ success: z.literal(false) });
export const ConsentSaveResponseSchema = z.union([ConsentSaveOk, ConsentSaveFail]);
export type ConsentSaveResponse = z.infer<typeof ConsentSaveResponseSchema>;
