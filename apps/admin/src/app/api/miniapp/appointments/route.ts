import { NextResponse, type NextRequest } from 'next/server';
import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { miniappJson, miniappPreflight } from '@/lib/miniapp/cors';
import { TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS, withMiniappTenant } from '@/lib/miniapp/tenant';
import { handleAvailableSlots, handleBook, handleCancel, handleMyAppointments, handlePharmacists, type ActionResult } from './_lib/handlers';

/**
 * /api/miniapp/appointments — port of api/appointments.php (762 lines, read in full) for the FIVE
 * actions with real line-mini-app callers (grep-verified against line-mini-app/src/lib/appointments-api.ts
 * AND src/components/miniapp/VideoClient.tsx, which calls this endpoint directly, bypassing the lib
 * wrapper): `pharmacists` (GET), `available_slots` (GET), `book` (POST), `my_appointments` (GET),
 * `cancel` (POST). `pharmacist_detail`, `today_appointments`, `detail`, `rate` are explicitly OUT of
 * scope — zero callers. See packages/contracts/src/appointments.ts's doc comment for the full contract
 * rationale (envelope shape, the `available_slots` message-override trap, appointment_id format, the
 * DYNAMIC-COLUMN VERIFICATION findings).
 *
 * IDENTITY MODEL: no session/auth gating — line_user_id + line_account_id trusted as given, matching
 * the PHP original (and, notably, api/appointments.php's own user lookups are NOT even
 * line_account_id-scoped, unlike member.php/health-profile.php — a plain `WHERE line_user_id = ?`,
 * preserved as-is). TENANCY: see lib/miniapp/tenant.ts's two-phase pin doc comment.
 *
 * ENVELOPE: `flatSuccessEnvelope()`-shaped (`{success, message, ...data}`, always implicit HTTP 200) —
 * every branch below returns `status: 200` via `_lib/handlers.ts`'s `ok()` helper, mirroring
 * api/appointments.php's `jsonResponse()` (no `http_response_code()` call anywhere in that file).
 *
 * OUTER TRY/CATCH: api/appointments.php wraps its ENTIRE action switch in `try { ... } catch
 * (Exception $e) { jsonResponse(false, $e->getMessage()); }` — any handler exception becomes a normal
 * `{success:false, message:<exception message>}` HTTP 200 response, never an unhandled 500. Ported
 * here as the same try/catch around each dispatch below (mirrors wishlist/route.ts's own
 * `dispatch()`'s try/catch, adapted to this file's `{message}`-keyed failure shape instead of
 * wishlist's `{error}`-keyed one).
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

async function dispatchGet(db: Kysely<TenantDB>, action: string, query: Record<string, unknown>): Promise<ActionResult> {
  try {
    switch (action) {
      case 'pharmacists':
        return await handlePharmacists(db);
      case 'available_slots':
        return await handleAvailableSlots(db, query);
      case 'my_appointments':
        return await handleMyAppointments(db, query);
      default:
        return { status: 200, body: { success: false, message: 'Invalid action' } };
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { status: 200, body: { success: false, message } };
  }
}

async function dispatchPost(db: Kysely<TenantDB>, action: string, data: Record<string, unknown>): Promise<ActionResult> {
  try {
    switch (action) {
      case 'book':
        return await handleBook(db, data);
      case 'cancel':
        return await handleCancel(db, data);
      default:
        return { status: 200, body: { success: false, message: 'Invalid action' } };
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { status: 200, body: { success: false, message } };
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

  const resolved = await withMiniappTenant(request, { method: 'GET', query }, ({ db }) => dispatchGet(db, action, query));

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const url = new URL(request.url);
  const query = queryRecord(url);
  const jsonBody = await parseJsonBody(request);
  const action = (query.action || (jsonBody.action as string | undefined) || '') as string;

  const resolved = await withMiniappTenant(request, { method: 'POST', query, jsonBody }, ({ db }) => dispatchPost(db, action, jsonBody));

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}
