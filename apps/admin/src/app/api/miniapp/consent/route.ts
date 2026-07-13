import { NextResponse, type NextRequest } from 'next/server';
import { miniappJson, miniappPreflight } from '@/lib/miniapp/cors';
import { TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS, withMiniappTenant } from '@/lib/miniapp/tenant';
import { handleSaveConsent, type ActionResult } from './_lib/handlers';

/**
 * /api/miniapp/consent — port of `api/consent.php`'s `action=save` ONLY (`handleSaveConsent()`; read
 * `api/consent.php` in full, 327 lines, before touching this file). `check`/`withdraw`/`history` are
 * explicitly OUT of scope — `consent-api.ts` (the only line-mini-app caller) calls `action=save`
 * exclusively; grepped, zero callers anywhere for the other three actions.
 *
 * ============================================================================
 * DEVIATION (flag exactly as prominently as addresses.ts's "no PHP source" finding — see
 * `packages/contracts/src/consent.ts`'s doc comment for the full writeup): TENANT-RESOLUTION BEHAVIOR IS
 * DELIBERATELY NOT PRESERVED.
 * ============================================================================
 * `api/consent.php` is CONSPICUOUSLY MISSING `require_once bootstrap/route_by_account.php` — every
 * sibling file in this batch and batch 1 requires it. Real production PHP therefore falls through to the
 * legacy/default DB when this endpoint is called from the root domain — a genuine PRE-EXISTING BUG.
 * DECISION (mig-orchestrator sign-off, not re-litigated here): this Route Handler uses the STANDARD
 * two-phase `resolveMiniappTenantContext()`/`withMiniappTenant()` helper anyway, exactly like every other
 * `/api/miniapp/**` route — a deliberate, byte-level deviation from the PHP original's tenant-resolution
 * behavior (the Next port is strictly MORE correct here, never falling back to a legacy DB). PARITY
 * HARNESS IMPACT: its PHP-side call for `consent:save` needs a `Host` header pinned to the seeded tenant
 * subdomain, to exercise PHP's real subdomain-resolution code path rather than the broken fallback it
 * takes on the root domain.
 *
 * DECISION: `ActivityLogger::logConsent()` (PHP's per-consent-type audit write, INSIDE the same
 * transaction as the `user_consents` upserts) is DELIBERATELY NOT PORTED — no Next-side `ActivityLogger`
 * writer exists yet (only a read-only `getLogs()`/`countLogs()` port, at
 * `apps/admin/src/app/(tenant)/activity-logs/queries.ts`). See `_lib/handlers.ts` and
 * `packages/contracts/src/consent.ts` for the full decision writeup.
 *
 * IDENTITY MODEL: no session/auth gating — line_user_id trusted as given, same trust-on-input model
 * every other `/api/miniapp/**` route uses.
 * ENVELOPE: `flatSuccessEnvelope()`-shaped (`{success, message, ...data}`, always HTTP 200) — same as
 * member.php/rewards.php.
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
  const action = (query.action ?? jsonBody.action ?? '') as string;

  const resolved = await withMiniappTenant(request, { method: 'POST', query, jsonBody }, async ({ db }) => {
    if (action !== 'save') {
      return { status: 200, body: { success: false, message: 'Invalid action' } } satisfies ActionResult;
    }

    const lineUserId = typeof jsonBody.line_user_id === 'string' ? jsonBody.line_user_id : '';
    const consents =
      jsonBody.consents && typeof jsonBody.consents === 'object' ? (jsonBody.consents as Record<string, unknown>) : {};
    const ipAddress = request.headers.get('x-forwarded-for') ?? null;
    const userAgent = request.headers.get('user-agent');

    try {
      return await handleSaveConsent(db, lineUserId, consents, ipAddress, userAgent);
    } catch (error) {
      // Mirrors api/consent.php's top-level `catch (Exception $e) { jsonResponse(false, $e->getMessage()); }`.
      const message = error instanceof Error ? error.message : String(error);
      return { status: 200, body: { success: false, message } } satisfies ActionResult;
    }
  });

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}
