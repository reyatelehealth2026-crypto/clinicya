import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getAssignment } from './_lib/getAssignment';

/**
 * GET /api/inbox/actions/get-assignment?user_id=N — literal port of
 * api/inbox-v2.php's `case 'get_assignment':` (lines ~2565-2588).
 *
 * ```php
 * case 'get_assignment':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = intval($_GET['user_id'] ?? 0);
 *     if (!$userId) { sendError('User ID is required'); }
 *     try {
 *         $assignment = $inboxService->getAssignment($userId);
 *         sendResponse(['success' => true, 'data' => $assignment]);
 *     } catch (Exception $e) {
 *         sendError('Failed to get assignment: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * See `_lib/getAssignment.ts`'s module doc for the `admin_users`
 * schema-drift finding this endpoint's happy path depends on, and for why
 * a missing-table throw is left to propagate here (not swallowed) —
 * reproducing PHP's own try/catch -> `sendError('Failed to get assignment:
 * ' . $e->getMessage())` (a clean 400 JSON error, matching `sendError`'s
 * default status code).
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

export async function GET(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const url = new URL(request.url);
  const userId = phpIntCast(url.searchParams.get('user_id'));
  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }

  const { db } = auth.value;

  try {
    const data = await getAssignment(db, userId);
    return NextResponse.json({ success: true, data });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to get assignment: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
