import { NextResponse } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { markAllReadAction } from './_lib/markAllRead';

/**
 * POST /api/inbox/actions/mark-all-read — literal port of
 * api/inbox-v2.php's `case 'mark_all_read':` (lines 2414-2431).
 *
 * ```php
 * case 'mark_all_read':
 *     if ($method !== 'POST') {
 *         sendError('Method not allowed', 405);
 *     }
 *
 *     try {
 *         $stmt = $db->prepare("UPDATE messages SET is_read = 1 WHERE line_account_id = ? AND direction = 'incoming' AND is_read = 0");
 *         $stmt->execute([$lineAccountId]);
 *         $affected = $stmt->rowCount();
 *
 *         sendResponse([
 *             'success' => true,
 *             'message' => "Marked {$affected} messages as read"
 *         ]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to mark messages as read: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * `lineAccountId` resolves as `session.currentBotId ?? 1` — the established
 * simplification of api/inbox-v2.php line 71's
 * `$_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ?? $_GET['line_account_id'] ?? $_POST['line_account_id'] ?? 1`
 * chain already applied by `api/inbox/actions/get-admins/route.ts` and
 * `api/inbox/conversations/route.ts` for the identical PHP expression: this
 * action takes no request params of its own (no `$_GET`/`$_POST` field is
 * referenced anywhere in this case), so tiers 2-4 of PHP's chain are
 * unreachable here regardless.
 *
 * No request body is read — this action is a single bulk UPDATE scoped
 * entirely by session-derived `lineAccountId`.
 *
 * AUTH: same gate as the sibling `mark-as-read-on-line`/`assign-tag`
 * actions in this batch and every other `api/inbox/actions/*` Route
 * Handler — a valid tenant session (any of the six TenantRole values). See
 * `_lib/session.ts`'s module doc for why this isn't a literal port of a PHP
 * auth check (api/inbox-v2.php has none ahead of its action switch).
 */
export async function POST(): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }
  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const affected = await markAllReadAction(db, lineAccountId);
    return NextResponse.json({ success: true, message: `Marked ${affected} messages as read` });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to mark messages as read: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
