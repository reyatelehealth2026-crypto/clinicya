import { NextResponse, type NextRequest } from 'next/server';
import { miniappJson, miniappPreflight } from '@/lib/miniapp/cors';
import { TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS, withMiniappTenant } from '@/lib/miniapp/tenant';
import { handleDelete, handleList, handleUpsert, type ActionResult } from './_lib/handlers';

/**
 * /api/miniapp/addresses
 *
 * ============================================================================
 * NO PHP ORIGINAL EXISTS FOR THIS ENDPOINT.
 * ============================================================================
 * Verified exhaustively (Phase 3 batch 2 brief): `ls api/*address*.php` returns nothing,
 * `grep -rl 'user-addresses\|user_addresses' --include=*.php .` returns nothing, and
 * `git log --all -- api/user-addresses.php` / a repo-wide deleted-file search both come up empty — the
 * endpoint was NEVER written on the PHP side. `line-mini-app/src/lib/addresses-api.ts` and the LIVE
 * `AddressesSheet.tsx` component both call `/api/user-addresses.php` today, so this feature 404s in
 * production right now — this is a PRE-EXISTING production gap this migration happens to be closing, not
 * a byte-for-byte PHP port.
 *
 * This Route Handler is therefore a FIRST-CLASS Next implementation, derived from (a) the client
 * contract already shipped in `addresses-api.ts` and (b) the `user_addresses` table already committed in
 * `database/migration_2026-05-25_tenant_template.sql`. See `packages/contracts/src/addresses.ts`'s doc
 * comment for the full writeup, including the parity-harness implication (a Next-only self-consistency
 * check, since there is no PHP side to diff against).
 *
 * `user_addresses` has NO `user_id` column — rows are keyed directly by `(line_user_id, line_account_id,
 * label)`, so there is no `users` row to resolve/auto-create for this endpoint (unlike every sibling
 * route in this batch).
 *
 * ACTIONS (client-verified via `addresses-api.ts`, 78 lines, read in full): `list` (GET), `upsert` (POST,
 * upsert-by-label via the table's own unique key), `delete` (POST, by label).
 *
 * IDENTITY MODEL: no session/auth gating — line_user_id + line_account_id trusted as given, same
 * trust-on-input model every other `/api/miniapp/**` route in this codebase uses.
 * TENANCY: standard two-phase `resolveMiniappTenantContext()`/`withMiniappTenant()` pin — no deviation.
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

/**
 * Row-scoping only (which address slot this request touches), NOT tenant DB routing — same distinction
 * `member/route.ts`'s own `resolveLineAccountId()` doc comment draws. Defaults to `0`, matching
 * `user_addresses.line_account_id`'s own `DEFAULT 0` column default (there is no PHP `?? 1` precedent to
 * follow here, so the DB's own default is the most defensible choice).
 */
function resolveLineAccountId(source: Record<string, unknown>): number {
  const raw = source.line_account_id;
  if (raw === undefined || raw === null || raw === '') return 0;
  const parsed = Number(raw);
  return Number.isFinite(parsed) ? parsed : 0;
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

  const resolved = await withMiniappTenant(request, { method: 'GET', query }, async ({ db }) => {
    if (query.action !== undefined && query.action !== 'list') {
      return { status: 200, body: { success: false, message: 'Invalid action', addresses: [] } } satisfies ActionResult;
    }
    const lineUserId = typeof query.line_user_id === 'string' ? query.line_user_id : '';
    const lineAccountId = resolveLineAccountId(query);
    return handleList(db, lineUserId, lineAccountId);
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
  const action = (jsonBody.action ?? query.action ?? '') as string;

  const resolved = await withMiniappTenant(request, { method: 'POST', query, jsonBody }, async ({ db }) => {
    const lineUserId = typeof jsonBody.line_user_id === 'string' ? jsonBody.line_user_id : '';
    const lineAccountId = resolveLineAccountId(jsonBody);

    switch (action) {
      case 'upsert':
        return handleUpsert(db, lineUserId, lineAccountId, jsonBody.label, jsonBody);
      case 'delete':
        return handleDelete(db, lineUserId, lineAccountId, jsonBody.label);
      default:
        return { status: 200, body: { success: false, message: 'Invalid action' } } satisfies ActionResult;
    }
  });

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}
