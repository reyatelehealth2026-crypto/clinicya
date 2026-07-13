import { NextResponse, type NextRequest } from 'next/server';
import { miniappJson, miniappPreflight } from '@/lib/miniapp/cors';
import { TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS, withMiniappTenant } from '@/lib/miniapp/tenant';
import { handleExportData, handleRequestDeletion, handleWithdrawConsent, type ActionResult, type DataRightsContext } from './_lib/handlers';
import { resolveUser } from './_lib/service';

/**
 * /api/miniapp/data-rights — port of `api/data-rights.php` (178 lines, delegating to
 * `modules/PDPA/Services/DataRightsService.php`, 365 lines — both read in full). ALL THREE actions have
 * live callers: `withdraw_consent`, `request_deletion`, `export_data` (verified via `data-rights-api.ts`
 * + `data-rights-request.ts`).
 *
 * ============================================================================
 * DEVIATION (same finding as `consent/route.ts` — read that file's doc comment for the full writeup):
 * TENANT-RESOLUTION BEHAVIOR IS DELIBERATELY NOT PRESERVED.
 * ============================================================================
 * `api/data-rights.php` is, like `api/consent.php`, CONSPICUOUSLY MISSING `require_once
 * bootstrap/route_by_account.php`. This Route Handler uses the STANDARD two-phase
 * `resolveMiniappTenantContext()`/`withMiniappTenant()` helper anyway (mig-orchestrator sign-off, not
 * re-litigated here) — a deliberate, byte-level deviation from the PHP original. PARITY HARNESS IMPACT:
 * its PHP-side call for `data-rights:*` needs a `Host` header pinned to the seeded tenant subdomain.
 *
 * MIGRATION DEPENDENCY: `data_deletion_requests` / `users.deletion_status` /
 * `users.deletion_requested_at` live only in `database/migration_2026-07-04_pdpa_data_rights.sql` — the
 * parity harness's fixture-seeding step must apply it before exercising `request_deletion`/`export_data`.
 * See `_lib/service.ts` and `packages/contracts/src/data-rights.ts` for the full writeup.
 *
 * SECURITY-CRITICAL: every action resolves `users.id` server-side from `(line_user_id, line_account_id)`
 * ONLY — a client-supplied `user_id` (if one were ever sent) is never read/trusted.
 *
 * ORDERING (replicated exactly from `api/data-rights.php`): identity is validated — `line_user_id`
 * present, then `resolveUser()` succeeds — BEFORE the action switch is reached. An unrecognized `action`
 * with a missing/unresolvable identity returns the IDENTITY error, never `'Invalid action'`. See
 * `_lib/handlers.ts`'s doc comment.
 *
 * IDENTITY MODEL: no session/auth gating — line_user_id trusted as given, same trust-on-input model
 * every other `/api/miniapp/**` route uses (the `resolveUser()` step above is the actual security
 * boundary, not a session).
 * ENVELOPE: `flatSuccessEnvelope()`-shaped (`{success, message, ...data}`, always HTTP 200) — same as
 * `consent.php`'s `drJsonResponse()`.
 */

function queryRecord(url: URL): Record<string, string> {
  return Object.fromEntries(url.searchParams.entries());
}

async function parseJsonBody(request: NextRequest): Promise<Record<string, unknown>> {
  try {
    const raw = await request.text();
    if (!raw) return {};
    const parsed: unknown = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : {};
  } catch {
    return {};
  }
}

/** `(int) $rawAccount > 0 ? (int) $rawAccount : null` — PHP only accepts a POSITIVE integer, else null. */
function resolveLineAccountId(source: Record<string, unknown>): number | null {
  const raw = source.line_account_id;
  if (raw === undefined || raw === null || raw === '') return null;
  const parsed = Number(raw);
  return Number.isFinite(parsed) && parsed > 0 ? Math.trunc(parsed) : null;
}

function respond(result: ActionResult): NextResponse {
  return miniappJson(result.body, { status: result.status });
}

export async function OPTIONS(): Promise<NextResponse> {
  return miniappPreflight();
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const url = new URL(request.url);
  const query = queryRecord(url);
  const jsonBody = await parseJsonBody(request);
  const action = (jsonBody.action ?? query.action ?? '') as string;

  const resolved = await withMiniappTenant(request, { method: 'POST', query, jsonBody }, async ({ db }) => {
    // ── Validate identity BEFORE doing anything (mirrors api/data-rights.php's own comment/ordering) ──
    const lineUserIdRaw = jsonBody.line_user_id ?? query.line_user_id;
    const lineUserId = typeof lineUserIdRaw === 'string' ? lineUserIdRaw.trim() : '';
    if (lineUserId === '') {
      return { status: 200, body: { success: false, message: 'LINE User ID required' } } satisfies ActionResult;
    }

    const lineAccountId = resolveLineAccountId(jsonBody);
    const ctx: DataRightsContext = {
      db,
      lineUserId,
      lineAccountId,
      ip: request.headers.get('x-forwarded-for'),
      ua: request.headers.get('user-agent'),
    };

    try {
      const user = await resolveUser(db, lineUserId, lineAccountId);
      if (user === null) {
        return { status: 200, body: { success: false, message: 'User not found' } } satisfies ActionResult;
      }

      switch (action) {
        case 'withdraw_consent':
          return await handleWithdrawConsent(ctx, user, jsonBody.consent_type);
        case 'request_deletion':
          return await handleRequestDeletion(ctx, user, jsonBody.reason);
        case 'export_data':
          return await handleExportData(ctx, user);
        default:
          return { status: 200, body: { success: false, message: 'Invalid action' } } satisfies ActionResult;
      }
    } catch {
      // Mirrors api/data-rights.php's top-level `catch (\Throwable $e) { error_log(...);
      // drJsonResponse(false, 'เกิดข้อผิดพลาดในการดำเนินการ'); }` — NOT a raw message leak (unlike
      // consent.php/medication-reminders.php, this file's own catch branch uses a fixed generic string).
      return { status: 200, body: { success: false, message: 'เกิดข้อผิดพลาดในการดำเนินการ' } } satisfies ActionResult;
    }
  });

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}
