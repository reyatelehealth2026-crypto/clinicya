import { NextResponse, type NextRequest } from 'next/server';
import { miniappJson, miniappPreflight } from '@/lib/miniapp/cors';
import { TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS, withMiniappTenant } from '@/lib/miniapp/tenant';
import { handleGetRewards, handleMyRedemptions, handleRedeem, type ActionResult } from './_lib/handlers';

/**
 * /api/miniapp/rewards — port of api/rewards.php (read in full, 189 lines) for the WRITE-lane batch
 * (mig-api-writes, Phase 3 batch 1): `list`/`rewards` (GET), `redeem` (POST), `my_redemptions` (GET).
 *
 * IDENTITY MODEL: no session/auth gating — line_user_id + line_account_id trusted as given, matching
 * the PHP original. TENANCY: see lib/miniapp/tenant.ts's two-phase pin doc comment.
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

function resolveLineAccountId(source: Record<string, unknown>): number {
  const raw = source.line_account_id;
  if (raw === undefined || raw === null || raw === '') return 1;
  const parsed = Number(raw);
  return Number.isFinite(parsed) ? parsed : 1;
}

/**
 * Port of api/rewards.php's outer `try { switch(...) } catch (Exception $e) { jsonResponse(false, ...,
 * ['error_details' => [...]]) }` — wraps the whole action dispatch so an unexpected exception (a real
 * DB error, not a validation branch — those all return typed ActionResults already) still surfaces as
 * a flat, HTTP-200 `{success:false, message, error_details}` body instead of an unhandled 500. `file`/
 * `line` have no faithful Node equivalent to PHP's `getFile()`/`getLine()`; best-effort placeholders.
 */
async function runAction(action: () => Promise<ActionResult>): Promise<ActionResult> {
  try {
    return await action();
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return {
      status: 200,
      body: {
        success: false,
        message,
        error_details: { message, file: 'apps/admin/src/app/api/miniapp/rewards/route.ts', line: 0 },
      },
    };
  }
}

function respond(result: ActionResult): NextResponse {
  return miniappJson(result.body, { status: result.status });
}

export async function OPTIONS(): Promise<NextResponse> {
  return miniappPreflight();
}

export async function GET(request: NextRequest): Promise<NextResponse> {
  const url = new URL(request.url);
  const query = queryRecord(url);
  const action = query.action ?? '';

  const resolved = await withMiniappTenant(request, { method: 'GET', query }, async ({ db }) => {
    const lineAccountId = resolveLineAccountId(query);
    return runAction(async () => {
      switch (action) {
        case 'list':
        case 'rewards':
          return handleGetRewards(db, lineAccountId);
        case 'my_redemptions':
          return handleMyRedemptions(db, query);
        default:
          return { status: 200, body: { success: false, message: 'Invalid action' } };
      }
    });
  });

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const url = new URL(request.url);
  const query = queryRecord(url);
  const jsonBody = await parseJsonBody(request);
  const action = (query.action || jsonBody.action || '') as string;

  const resolved = await withMiniappTenant(request, { method: 'POST', query, jsonBody }, async ({ db }) => {
    const lineAccountId = resolveLineAccountId(jsonBody);
    return runAction(async () => {
      switch (action) {
        case 'redeem':
          return handleRedeem(db, lineAccountId, jsonBody);
        default:
          return { status: 200, body: { success: false, message: 'Invalid action' } };
      }
    });
  });

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}
