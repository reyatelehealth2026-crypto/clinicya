import { NextResponse, type NextRequest } from 'next/server';
import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { miniappJson, miniappPreflight } from '@/lib/miniapp/cors';
import { TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS, withMiniappTenant } from '@/lib/miniapp/tenant';
import { handleAdd, handleDelete, handleList, handleMarkTaken, resolveUserId, type ActionResult } from './_lib/handlers';

/**
 * /api/miniapp/medication-reminders — port of `api/medication-reminders.php` (338 lines, read in full)
 * for the actions `reminders-api.ts` actually calls: `list` (GET, also the default action when `action`
 * is omitted), `add` (POST), `delete` (POST), `mark_taken` (POST). `update`/`history`/`adherence`/
 * `from_order` are explicitly OUT of scope — zero line-mini-app callers, confirmed via grep.
 *
 * ROUTING: unlike `consent.php`/`data-rights.php`, `api/medication-reminders.php` DOES `require_once
 * bootstrap/route_by_account.php` — standard two-phase tenant resolution, NO deviation here.
 *
 * DISPATCH: PHP itself never branches on `$_SERVER['REQUEST_METHOD']` — `$method` is read but unused;
 * every action is reachable regardless of HTTP verb, driven purely by the `action` param
 * (`$input['action'] ?? $_GET['action'] ?? 'list'`). Mirrored here exactly like
 * `apps/admin/src/app/api/miniapp/wishlist/route.ts` does for the same reason: one shared `dispatch()`
 * called from both `GET` and `POST`, rather than a per-method allowlist that PHP doesn't actually have.
 *
 * ENVELOPE: AD HOC, like `wishlist.php` — see `packages/contracts/src/medication-reminders.ts`'s doc
 * comment for the full shape breakdown (no shared `jsonResponse()` helper, `error` not `message` on
 * every failure branch, always implicit HTTP 200).
 *
 * IDENTITY MODEL: no session/auth gating — line_user_id + line_account_id trusted as given, same
 * trust-on-input model every other `/api/miniapp/**` route uses.
 */

function queryRecord(url: URL): Record<string, string> {
  return Object.fromEntries(url.searchParams.entries());
}

async function parseJsonBody(request: NextRequest): Promise<Record<string, unknown> | null> {
  try {
    const raw = await request.text();
    if (!raw) return null;
    const parsed: unknown = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : null;
  } catch {
    return null;
  }
}

/**
 * Port of `$input[$key] ?? $_GET[$key] ?? $default` — for GET requests `$input` IS `$_REQUEST`
 * (`json_decode(php://input) ?: $_REQUEST`, since `php://input` is empty on GET), so `jsonBody` is null
 * there and this collapses to just reading `query`. Same helper `wishlist/route.ts` already uses.
 */
function field(jsonBody: Record<string, unknown> | null, query: Record<string, string>, key: string): unknown {
  if (jsonBody && jsonBody[key] !== undefined && jsonBody[key] !== null) return jsonBody[key];
  return query[key];
}

async function dispatch(
  db: Kysely<TenantDB>,
  jsonBody: Record<string, unknown> | null,
  query: Record<string, string>
): Promise<ActionResult> {
  const action = String(field(jsonBody, query, 'action') ?? 'list');
  const lineUserId = String(field(jsonBody, query, 'line_user_id') ?? '');
  const lineAccountIdRaw = field(jsonBody, query, 'line_account_id');
  const lineAccountId = lineAccountIdRaw === undefined || lineAccountIdRaw === null || lineAccountIdRaw === '' ? null : Number(lineAccountIdRaw);

  try {
    const userId = await resolveUserId(db, lineUserId);

    switch (action) {
      case 'add':
        return await handleAdd(db, userId, lineUserId, lineAccountId, {
          medication_name: field(jsonBody, query, 'medication_name'),
          dosage: field(jsonBody, query, 'dosage'),
          frequency: field(jsonBody, query, 'frequency'),
          reminder_times: field(jsonBody, query, 'reminder_times'),
          start_date: field(jsonBody, query, 'start_date'),
          end_date: field(jsonBody, query, 'end_date'),
          notes: field(jsonBody, query, 'notes'),
          product_id: field(jsonBody, query, 'product_id'),
          order_id: field(jsonBody, query, 'order_id'),
        });
      case 'delete':
        return await handleDelete(db, userId, field(jsonBody, query, 'reminder_id'));
      case 'mark_taken':
        return await handleMarkTaken(
          db,
          userId,
          field(jsonBody, query, 'reminder_id'),
          field(jsonBody, query, 'scheduled_time'),
          field(jsonBody, query, 'status'),
          field(jsonBody, query, 'notes')
        );
      case 'list':
      default:
        return await handleList(db, userId, lineUserId);
    }
  } catch (error) {
    // Mirrors api/medication-reminders.php's top-level `catch (Exception $e) { echo json_encode([
    // 'success'=>false, 'error'=>$e->getMessage()]); }` — a raw exception-message leak, replicated
    // byte-for-byte (not sanitized into a generic message; that would be a response-shape change).
    const message = error instanceof Error ? error.message : String(error);
    return { status: 200, body: { success: false, error: message } };
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

  const resolved = await withMiniappTenant(request, { method: 'GET', query }, ({ db }) => dispatch(db, null, query));

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const url = new URL(request.url);
  const query = queryRecord(url);
  const jsonBody = await parseJsonBody(request);

  const resolved = await withMiniappTenant(request, { method: 'POST', query, jsonBody }, ({ db }) => dispatch(db, jsonBody, query));

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}
