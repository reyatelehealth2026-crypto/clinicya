import { cookies } from 'next/headers';
import type { NextRequest } from 'next/server';
import { NextResponse } from 'next/server';
import { getSession, requireRole, TENANT_SESSION_COOKIE, type TenantSession } from '@reya/auth';
import { getTenantDb } from '@reya/db';
import { getMessagesCursor, phpIntCast } from './_lib/query';

/**
 * GET /api/inbox/messages — port of `api/inbox-v2.php`'s
 * `action=getMessages`/`get_messages` (lines 2789-2837), backed by
 * `classes/InboxService.php::getMessagesCursor()`. Response envelope is a
 * literal port of `sendResponse()`/`sendError()` (api/inbox-v2.php lines
 * 107-127): `{success:true, data:{messages, next_cursor, has_more, count}}`
 * or `{success:false, error}`.
 *
 * NOT PORTED (see brief boundaries): `get_chat_content` (superseded by
 * Next's native navigation to `/inbox/[userId]`) and its mark-as-read side
 * effect; the ETag/If-None-Match 304 short-circuit (a caching optimization,
 * not user-visible behavior — can be added later without changing the
 * response contract this route promises).
 *
 * AUTH — a deliberate ADDITION over the literal PHP source: api/inbox-v2.php
 * itself performs NO login check (it reads `$_SESSION['current_bot_id']`
 * etc. with a bare `?? 1` fallback and never calls anything from
 * `includes/auth_check.php`) — the page shell `inbox-v2.php` is what's
 * gated, not this AJAX endpoint. This Next Route Handler is reachable
 * directly (it is not wrapped by `(tenant)/layout.tsx`, which only gates
 * page renders, not sibling `api/**` Route Handlers), so leaving it
 * unauthenticated would let anyone hit it directly and read a tenant's
 * conversation history — "preserve behavior, not markup" does not extend to
 * reproducing a pre-existing auth gap in new code. Requires a valid tenant
 * session (any of the six TenantRole values — messages.php/inbox-v2.php
 * have no page-specific role gate beyond "any authenticated admin", mirrors
 * `(tenant)/users/_lib/session.ts`'s own rationale) and resolves the tenant
 * DB from the session, ignoring any client-supplied tenant/account hint.
 */

const TENANT_ROLES: readonly TenantSession['role'][] = [
  'super_admin',
  'admin',
  'pharmacist',
  'marketing',
  'tech',
  'staff',
];

export async function GET(request: NextRequest): Promise<Response> {
  const cookieStore = await cookies();
  const sid = cookieStore.get(TENANT_SESSION_COOKIE)?.value;
  const rawSession = await getSession(sid, 'tenant');
  const tenantSession = rawSession && rawSession.realm === 'tenant' ? rawSession : null;

  const authResult = requireRole<TenantSession>(tenantSession, TENANT_ROLES);
  if (!authResult.ok || authResult.value.tenantId === null) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: 401 });
  }

  const query = Object.fromEntries(request.nextUrl.searchParams.entries());

  // `$userId = (int) ($_GET['user_id'] ?? 0); if ($userId <= 0) { sendError('Invalid user ID'); }`
  // (api/inbox-v2.php lines 2795-2798). The follow-up `if (!$userId)` check
  // (line 2802-2804) is unreachable dead code — any $userId <= 0 already
  // returned above — so it is not replicated here.
  const userId = query.user_id !== undefined ? phpIntCast(query.user_id) : 0;
  if (userId <= 0) {
    return NextResponse.json({ success: false, error: 'Invalid user ID' }, { status: 400 });
  }

  // `$cursor = $_GET['cursor'] ?? null;` — PHP's `??` here is isset()-based:
  // an absent `cursor` param -> null, but a present-but-empty `cursor=` ->
  // the empty string (a distinct, legal cursor value). `Object.fromEntries`
  // over `URLSearchParams` preserves that same distinction (`undefined` vs `''`).
  const cursor = query.cursor ?? null;

  // `$limit = (int) ($_GET['limit'] ?? 50); if ($limit < 1 || $limit > 100) $limit = 50;`
  // (api/inbox-v2.php lines 2800, 2806-2809) — a literal RESET to 50 for any
  // out-of-range value, NOT a clamp-to-boundary (e.g. limit=9999 -> 50, not
  // 100). getMessagesCursor() then ALSO clamps internally
  // (max(1,min(100,limit))), matching InboxService.php's own defensive cap —
  // redundant here since this branch already guarantees a valid value, but
  // preserved so the two layers match the real system exactly.
  let limit = query.limit !== undefined ? phpIntCast(query.limit) : 50;
  if (limit < 1 || limit > 100) {
    limit = 50;
  }

  try {
    const db = await getTenantDb(authResult.value.tenantId);
    const data = await getMessagesCursor(db, userId, cursor, limit);
    return NextResponse.json({ success: true, data });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to get messages: ${message}` }, { status: 400 });
  }
}
