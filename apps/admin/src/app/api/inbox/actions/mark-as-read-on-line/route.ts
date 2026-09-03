import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { markAsReadOnLineAction } from './_lib/markAsReadOnLine';

/**
 * POST /api/inbox/actions/mark-as-read-on-line — literal port of
 * api/inbox-v2.php's `case 'mark_as_read_on_line':` (lines 2601-2679). See
 * `_lib/markAsReadOnLine.ts`'s module doc for the full PHP source and the
 * branch-by-branch port, and `_lib/lineMarkAsRead.ts` for the underlying
 * LINE Messaging API call (a local, injectable-fetch port of
 * classes/LineAPI.php::markAsRead() — NOT added to `@reya/line`, see that
 * file's doc for why).
 *
 * ```php
 * case 'mark_as_read_on_line':
 *     if ($method !== 'POST') {
 *         sendError('Method not allowed', 405);
 *     }
 *
 *     $userId = intval($_POST['user_id'] ?? 0);
 *
 *     if (!$userId) {
 *         sendError('User ID is required');
 *     }
 *
 *     try {
 *         // ...see _lib/markAsReadOnLine.ts for the rest...
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to mark as read: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * `lineAccountId` resolves as `session.currentBotId ?? 1` — same
 * simplification of api/inbox-v2.php line 71's 4-tier session/query-param
 * chain already applied by `api/inbox/actions/get-admins/route.ts`,
 * `api/inbox/actions/mark-all-read/route.ts`, and
 * `api/inbox/conversations/route.ts` for the identical PHP expression: this
 * action reads no `line_account_id` request param of its own.
 *
 * The JSON body accepts `{ user_id }` — this is a new endpoint, not bound
 * to PHP's `$_POST` shape (matches every other ported action in this
 * batch).
 *
 * AUTH: same gate as the sibling `mark-all-read`/`assign-tag` actions in
 * this batch and every other `api/inbox/actions/*` Route Handler — a valid
 * tenant session (any of the six TenantRole values). See `_lib/session.ts`'s
 * module doc for why this isn't a literal port of a PHP auth check
 * (api/inbox-v2.php has none ahead of its action switch).
 */

/** PHP's `intval($v ?? 0)` — loose int cast, non-numeric -> 0. */
function intval(value: unknown): number {
  if (typeof value === 'number') return Math.trunc(value);
  if (typeof value === 'string') {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
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

  const userId = intval(body.user_id);
  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const result = await markAsReadOnLineAction(db, lineAccountId, userId);
    return NextResponse.json(result.body, { status: result.status });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to mark as read: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
