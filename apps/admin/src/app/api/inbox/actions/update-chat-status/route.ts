import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { updateChatStatus } from './_lib/updateChatStatus';

/**
 * POST /api/inbox/actions/update-chat-status — literal port of
 * api/inbox-v2.php's `case 'update_chat_status':` (lines ~2362-2406).
 *
 * ```php
 * case 'update_chat_status':
 *     if ($method !== 'POST') {
 *         sendError('Method not allowed', 405);
 *     }
 *
 *     $userId = (int) ($_POST['user_id'] ?? 0);
 *     $status = trim($_POST['status'] ?? '');
 *
 *     $allowedStatuses = ['', 'pending', 'completed', 'shipping', 'tracking', 'billing'];
 *
 *     if (!$userId) {
 *         sendError('User ID is required');
 *     }
 *
 *     if (!in_array($status, $allowedStatuses)) {
 *         sendError('Invalid status');
 *     }
 *
 *     try {
 *         // ... SELECT old chat_status, UPDATE users, best-effort INSERT
 *         // chat_status_history (own inner try/catch) — see
 *         // _lib/updateChatStatus.ts ...
 *         sendResponse(['success' => true, 'message' => 'Chat status updated successfully']);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to update chat status: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * `''` (EMPTY STRING) IS A VALID `status` VALUE — it's the first entry in
 * PHP's own `$allowedStatuses` whitelist, and clearing the chat status is a
 * legitimate, intentional action (the UI's "clear status" control), not an
 * error. It is NOT rejected by the whitelist check here either.
 *
 * `$status ?: null` (PHP's ternary-shorthand `?:`, "empty-string-or-falsy
 * -> null") is what actually reaches both the `UPDATE users SET chat_status
 * = ?` and the `chat_status_history.new_status` column — an empty-string
 * `status` therefore stores/logs as SQL `NULL`, not the literal string
 * `''`. Resolved here (not in `_lib/updateChatStatus.ts`) as `newStatus =
 * status || null` — `status` can only be `''` or one of the 4 non-empty
 * whitelisted values at this point (never `'0'`, which PHP's `?:` would
 * also fold to null but which isn't a reachable whitelist member, so that
 * edge of PHP's general falsy-string semantics doesn't matter in practice
 * here).
 *
 * `sendError()`'s DEFAULT status code is 400 — this case's own `catch`
 * block (wrapping the SELECT/UPDATE pair) calls `sendError('Failed to
 * update chat status: ' . $e->getMessage())` with no explicit status
 * argument, so a SELECT/UPDATE failure here is HTTP 400. The
 * `chat_status_history` INSERT's OWN inner try/catch is separate and
 * strictly swallows — see `_lib/updateChatStatus.ts`'s module doc — so a
 * history-insert failure never reaches this outer catch and never flips the
 * response to an error.
 *
 * `changed_by` is `session.adminUserId` — PHP re-reads `$_SESSION['admin_user']['id']`
 * INSIDE this case body (line ~2394), a DIFFERENT session key than the
 * file-level `$adminId = $_SESSION['admin_id'] ?? ...` used by sibling
 * actions like `assign_tag` — both resolve to the same `admin_users.id` in
 * practice, so `TenantSession.adminUserId` (always a number; see
 * `../assign-tag/_lib/assignTag.ts`'s `resolveAssignedBy()` doc for the
 * general precedent) is used directly here, with no `?? 'Admin'`-style
 * string fallback needed (this column is a nullable admin id, not a
 * varchar label).
 *
 * This JSON body accepts only `{ user_id, status }` — a Route Handler has
 * no `$_POST` equivalent, so only the JSON body is read here, matching
 * every other action in this batch.
 *
 * `lineAccountId` (bound into `chat_status_history.line_account_id`)
 * resolves as `session.currentBotId ?? 1` — the established
 * `../get-admins/route.ts` precedent, NOT PHP's full 4-tier fallback chain
 * (per this batch's brief).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

const ALLOWED_STATUSES = ['', 'pending', 'completed', 'shipping', 'tracking', 'billing'] as const;

/** PHP `(int) $value` — loose int cast, non-numeric -> 0. */
function toIntCast(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? Math.trunc(value) : 0;
  if (typeof value === 'string') {
    const match = /^\s*[+-]?\d+/.exec(value);
    return match ? Number.parseInt(match[0], 10) : 0;
  }
  return 0;
}

/** PHP `trim($_POST['status'] ?? '')` — non-string/missing input coerces to `''` before trimming. */
function toTrimmedString(value: unknown): string {
  if (typeof value !== 'string') {
    return '';
  }
  return value.trim();
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  let raw: unknown;
  try {
    raw = await request.json();
  } catch {
    raw = {};
  }
  const body = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

  const userId = toIntCast(body.user_id ?? 0);
  const status = toTrimmedString(body.status ?? '');

  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }
  if (!(ALLOWED_STATUSES as readonly string[]).includes(status)) {
    return NextResponse.json({ success: false, error: 'Invalid status' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;
  const newStatus = status || null; // PHP `$status ?: null`.

  try {
    await updateChatStatus(db, {
      userId,
      lineAccountId,
      newStatus,
      changedBy: session.adminUserId,
    });
    return NextResponse.json({ success: true, message: 'Chat status updated successfully' });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to update chat status: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
