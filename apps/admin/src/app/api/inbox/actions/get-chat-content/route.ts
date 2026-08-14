import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getChatContent, phpIntCast, phpIntCastOrDefault } from './_lib/getChatContent';

/**
 * GET /api/inbox/actions/get-chat-content?user_id=N — literal port of
 * `api/inbox-v2.php`'s `case 'get_chat_content':` (lines 3057-3163). See
 * `_lib/getChatContent.ts`'s module doc for the full PHP source and the
 * schema-drift decision behind the unconditional `conversation_multi_assignees`
 * read.
 *
 * QUERY PARAMS (`?user_id=` and `?user=` both resolve the same user):
 * `$userId = (int) ($_GET['user_id'] ?? $_GET['user'] ?? 0);` — PHP's `??`
 * short-circuits on `isset()`, so `user_id` wins whenever the query string
 * carries that key at all (even `user_id=` empty), and `user` is only
 * consulted when `user_id` is entirely absent from the query string.
 * `$limit = min((int) ($_GET['limit'] ?? 50), 100);` — default 50, hard
 * capped at 100 (no lower bound). `$offset = (int) ($_GET['offset'] ?? 0);`.
 *
 * `!$userId` (missing/falsy `user_id`) is a 400 'User ID is required' —
 * this check runs BEFORE PHP's `try` block (line 3067, ahead of line 3068's
 * `try {`), so it is a literal, immediate 400, never routed through the
 * case-level catch below.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values). See `_lib/session.ts`'s
 * module doc for why this isn't a literal port of a PHP auth check
 * (`api/inbox-v2.php` has none ahead of its action switch).
 *
 * ERROR SHAPE: `case 'get_chat_content':` HAS its own case-level
 * `catch (Exception $e) { sendError('Failed to get chat content: ' .
 * $e->getMessage()); }` (lines 3160-3163) — unlike `poll`'s case (which has
 * NO case-level catch and falls through to the outer page-level handler),
 * this route's catch below reproduces THAT literal text/status (HTTP 400
 * default `sendError` status), not the generic `'Database error: ...'` 500
 * shape `poll/route.ts` uses for its own no-case-catch situation.
 *
 * `lineAccountId` resolves as `session.currentBotId ?? 1` — the established
 * 2-tier simplification of `api/inbox-v2.php` line 71's full 4-tier
 * `$_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ??
 * $_GET['line_account_id'] ?? $_POST['line_account_id'] ?? 1` chain, per
 * `poll/route.ts`'s own documented precedent (reused verbatim, not
 * reinvented).
 */
export async function GET(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  const searchParams = request.nextUrl.searchParams;
  // `$_GET['user_id'] ?? $_GET['user'] ?? 0` — `user_id` wins whenever the key is present at all
  // (isset() semantics), regardless of its value; `user` is only consulted when `user_id` is
  // entirely absent from the query string.
  const userIdRaw = searchParams.has('user_id') ? searchParams.get('user_id') : searchParams.get('user');
  const userId = phpIntCast(userIdRaw);
  const limit = Math.min(phpIntCastOrDefault(searchParams.get('limit'), 50), 100);
  const offset = phpIntCastOrDefault(searchParams.get('offset'), 0);

  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }

  try {
    const result = await getChatContent(db, lineAccountId, userId, limit, offset);
    return NextResponse.json(result.body, { status: result.status });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to get chat content: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
