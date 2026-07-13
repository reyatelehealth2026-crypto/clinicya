import { NextResponse, type NextRequest } from 'next/server';
import { miniappJson, miniappPreflight } from '@/lib/miniapp/cors';
import { TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS, withMiniappTenant } from '@/lib/miniapp/tenant';
import { handleCheck, handleGetCard, handleRegister, handleUpdateProfile, type ActionResult } from './_lib/handlers';

/**
 * /api/miniapp/member — port of api/member.php (read in full, 812 lines) for the WRITE-lane batch
 * (mig-api-writes, Phase 3 batch 1): `check` (GET, real write side effects — see brief), `get_card`
 * (GET, pure read, kept in this same file per contractNote point 9), `register` (POST),
 * `update_profile` (POST). `get_tiers` is intentionally NOT ported (zero line-mini-app callers).
 *
 * IDENTITY MODEL: no session/auth gating — line_user_id + line_account_id are trusted as given,
 * exactly like the PHP original (no server-side LIFF token verification anywhere in this surface).
 * TENANCY: resolveMiniappTenantContext() two-phase pin (x-tenant-id header, else routeByLineAccount()
 * via line_account_id/la/account) — see lib/miniapp/tenant.ts's doc comment.
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

/** `$_GET['line_account_id'] ?? 1` / body equivalent — business-data row scoping, NOT tenant routing. */
function resolveLineAccountId(source: Record<string, unknown>): number {
  const raw = source.line_account_id;
  if (raw === undefined || raw === null || raw === '') return 1;
  const parsed = Number(raw);
  return Number.isFinite(parsed) ? parsed : 1;
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
    switch (action) {
      case 'check':
        return handleCheck(db, lineAccountId, query);
      case 'get_card':
        return handleGetCard(db, lineAccountId, query);
      default:
        return { status: 200, body: { success: false, message: 'Invalid action' } } satisfies ActionResult;
    }
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
    switch (action) {
      case 'register':
        return handleRegister(db, lineAccountId, jsonBody);
      case 'update_profile':
        return handleUpdateProfile(db, jsonBody);
      default:
        return { status: 200, body: { success: false, message: 'Invalid action' } } satisfies ActionResult;
    }
  });

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}
