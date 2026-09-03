import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { removeAssignee, unassignConversation } from './_lib/unassignConversation';

/**
 * POST /api/inbox/actions/unassign-conversation — literal port of
 * api/inbox-v2.php's `case 'unassign_conversation':` (lines ~2529-2559).
 *
 * ```php
 * case 'unassign_conversation':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $body = getJsonBody();
 *     $userId = intval($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     $adminId = intval($_POST['admin_id'] ?? $body['admin_id'] ?? 0);
 *     if (!$userId) { sendError('User ID is required'); }
 *     try {
 *         $inboxService = new InboxService($db, $lineAccountId);
 *         if ($adminId > 0) {
 *             $success = $inboxService->removeAssignee($userId, $adminId);
 *             $message = 'ยกเลิกการมอบหมายสำเร็จ';
 *         } else {
 *             $success = $inboxService->unassignConversation($userId);
 *             $message = 'ยกเลิกการมอบหมายทั้งหมดสำเร็จ';
 *         }
 *         sendResponse(['success' => $success, 'message' => $message]);
 *     } catch (Exception $e) {
 *         sendError('Failed to unassign conversation: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * Reads a JSON body `{ user_id, admin_id? }`. `admin_id > 0` selects the
 * single-admin `removeAssignee()` branch (Thai message
 * 'ยกเลิกการมอบหมายสำเร็จ'); an omitted/falsy/zero `admin_id` selects the
 * remove-all `unassignConversation()` branch (Thai message
 * 'ยกเลิกการมอบหมายทั้งหมดสำเร็จ'). See `_lib/unassignConversation.ts`'s
 * module doc for why the two branches touch a different set of tables
 * (`removeAssignee()` is legacy-table-blind; `unassignConversation()`
 * clears both).
 *
 * `$success` in PHP is always `true` in practice — this repo's PDO
 * connection is configured with `PDO::ERRMODE_EXCEPTION`
 * (modules/Core/Database.php), so a real DELETE failure throws rather than
 * making `$stmt->execute()` return false — matching Kysely's own
 * throw-on-failure semantics; this Route Handler's happy path likewise
 * always responds `success: true`, with a genuine DB error caught by the
 * outer try/catch below instead.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP's `intval($v ?? 0)` — loose int cast, non-numeric -> 0. */
function phpIntCast(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? Math.trunc(value) : 0;
  if (typeof value === 'string') {
    const match = /^\s*[+-]?\d+/.exec(value);
    return match ? Number.parseInt(match[0], 10) : 0;
  }
  return 0;
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

  const userId = phpIntCast(body.user_id ?? 0);
  const adminId = phpIntCast(body.admin_id ?? 0);

  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }

  const { db } = auth.value;

  try {
    let message: string;
    if (adminId > 0) {
      await removeAssignee(db, userId, adminId);
      message = 'ยกเลิกการมอบหมายสำเร็จ';
    } else {
      await unassignConversation(db, userId);
      message = 'ยกเลิกการมอบหมายทั้งหมดสำเร็จ';
    }

    return NextResponse.json({ success: true, message });
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to unassign conversation: ${errorMessage}` }, { status: 400 });
  }
}
