import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { assignConversation, parseAssignRequest } from './_lib/assignConversation';

/**
 * POST /api/inbox/actions/assign-conversation — port of api/inbox-v2.php's
 * `case 'assign_conversation':` (lines ~2461-2519), backed by
 * `classes/InboxService.php::assignConversation()` (see
 * `_lib/assignConversation.ts`'s module doc for the full literal port +
 * the dual-write and admin_users schema-drift findings).
 *
 * Reads a JSON body `{ user_id, assign_to: number | number[] | string }` —
 * `assign_to` accepts a bare admin id, an array of ids, or a JSON-encoded
 * string of either (see `_lib/assignConversation.ts`'s `normalizeAssignTo()`
 * for the literal parse port of inbox-v2.php lines ~2478-2497).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * INTENTIONAL, FLAGGED DEVIATION — PHP-BUG DECISION
 * ═══════════════════════════════════════════════════════════════════════
 * inbox-v2.php's own case does:
 *
 * ```php
 * $success = $inboxService->assignConversation($userId, $adminIds, $assignedBy);
 * if ($success) {
 *     sendResponse(['success' => true, 'message' => ..., 'assigned_count' => count($adminIds)]);
 * } else {
 *     sendError('Failed to assign conversation');
 * }
 * ```
 *
 * `InboxService::assignConversation()` ALWAYS returns a non-empty PHP
 * array — even the failure paths return `['success' => false, 'error' =>
 * ..., 'code' => 'USER_NOT_FOUND']` etc. — and a non-empty PHP array is
 * always truthy. The `if ($success)` check above is therefore ALWAYS true
 * in practice, the `else` branch is dead code, and PHP silently responds
 * HTTP 200 `{"success": true, "message": "...", ...}` even when the
 * underlying assignment failed with USER_NOT_FOUND / ADMIN_NOT_FOUND /
 * ASSIGN_FAILED (`$adminIds` is even unrelated to the failed result at that
 * point — `count($adminIds)` still reports the *requested* count, not what
 * was actually assigned).
 *
 * This is NOT replicated. Per this batch's brief, this Route Handler reads
 * the domain result's own `.success` field explicitly (same pattern as
 * `_lib/sendMessage.ts`'s `UNSUPPORTED_PLATFORM_MESSAGE` note — an
 * intentional, documented behavioral improvement over the PHP source, not
 * a silent contract change) and maps a `success: false` result to a real
 * non-2xx status, echoing PHP's own `error`/`code` text:
 *   USER_NOT_FOUND  -> 404
 *   ADMIN_NOT_FOUND -> 404
 *   ASSIGN_FAILED   -> 500
 * `route.test.ts` asserts this fixed behavior directly (a USER_NOT_FOUND
 * case correctly responds non-2xx / success:false), not PHP's
 * silently-successful 200.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

function statusForCode(code: 'USER_NOT_FOUND' | 'ADMIN_NOT_FOUND' | 'ASSIGN_FAILED'): number {
  return code === 'ASSIGN_FAILED' ? 500 : 404;
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

  const parsed = parseAssignRequest(body);
  if (!parsed.ok) {
    return NextResponse.json({ success: false, error: parsed.error }, { status: 400 });
  }
  const { userId, adminIds } = parsed.value;

  const { db, session } = auth.value;
  // Mirrors `$_SESSION['current_bot_id'] ?? 1` — see api/inbox/conversations/route.ts's
  // established `session.currentBotId ?? 1` precedent for this batch.
  const lineAccountId = session.currentBotId ?? 1;
  // inbox-v2.php: `$assignedBy = $_SESSION['admin_id'] ?? null;`
  const assignedBy = session.adminUserId ?? null;

  try {
    const result = await assignConversation(db, lineAccountId, userId, adminIds, assignedBy);

    if (!result.success) {
      // See module doc above — this is the fixed, non-buggy branch PHP's
      // dead `else` never reaches.
      return NextResponse.json({ success: false, error: result.error, code: result.code }, { status: statusForCode(result.code) });
    }

    return NextResponse.json({
      success: true,
      message: adminIds.length > 1 ? `มอบหมายงานให้ ${adminIds.length} คนสำเร็จ` : 'มอบหมายงานสำเร็จ',
      assigned_count: adminIds.length,
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to assign conversation: ${message}` }, { status: 400 });
  }
}
