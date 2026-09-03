import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { phpIntCast, sendBatchMessagesAction } from './_lib/sendBatchMessages';

/**
 * POST /api/inbox/actions/send-batch-messages — literal port of
 * `api/inbox-v2.php`'s `case 'send_batch_messages':` (lines 3169-3487). See
 * `_lib/sendBatchMessages.ts`'s module doc for the full PHP source, the
 * validation order, and the two documented PHP quirks this port preserves
 * verbatim (`sent_by` as a raw admin id, `is_read` hardcoded to 1).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * SAFETY-CRITICAL — this action pushes real LINE messages via
 * `pushMessage()` (never a reply token). `route.test.ts` mocks the ENTIRE
 * `@reya/line` module boundary (`jest.mock('@reya/line', ...)`, exactly
 * matching `send-message/route.test.ts`'s established pattern) so no test
 * in this suite can ever reach `@reya/line`'s real `defaultFetch`/
 * `globalThis.fetch`. See `_lib/sendBatchMessages.ts` for the only call
 * site that imports `pushMessage` from `@reya/line` — every other function
 * in this route folder is pure validation/DB logic with zero network
 * surface.
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Reads a JSON body (`{ user_id, messages, line_user_id? }`) via
 * `request.json()` — a new endpoint, not bound to PHP's `$_POST` shape
 * (same simplification `send-message/route.ts` already made for this same
 * PHP file's same-page AJAX switch).
 *
 * VALIDATION ORDER preserved from PHP (see `_lib/sendBatchMessages.ts` for
 * the rest, once inside the try-equivalent):
 *   1. `userId` truthy -> 400 'User ID is required'
 *   2. `messages` parsed (string body -> `JSON.parse`, matching PHP's
 *      `is_string($messages) -> json_decode(...) ?? []`) then non-empty ->
 *      400 'Messages array is required'
 *   3. `messages.length > 5` -> 400 'Maximum 5 messages allowed per batch'
 *      (delegated to `sendBatchMessagesAction()` — see its own doc)
 *
 * `lineAccountId` resolves as `session.currentBotId ?? 1` — the established
 * `poll/route.ts` precedent (2-tier simplification of `api/inbox-v2.php`
 * line 71's full 4-tier `$_SESSION`/`$_GET`/`$_POST` fallback chain),
 * reused verbatim per this batch's brief.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values). See `_lib/session.ts`'s
 * module doc for why this isn't a literal port of a PHP auth check.
 *
 * ERROR SHAPE: `case 'send_batch_messages':` HAS its own case-level
 * `catch (Exception $e) { sendError('Error sending batch messages: ' .
 * $e->getMessage()); }` (lines 3484-3487) — every EXPECTED validation
 * branch inside `sendBatchMessagesAction()` returns its own literal
 * `{status, body}` directly (mirroring `sendError()`'s `exit()`-before-catch
 * semantics — see that file's module doc), and this route's own try/catch
 * below reproduces the case-level catch's literal text for anything
 * genuinely unexpected.
 */
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

  // inbox-v2.php lines 3172-3174: `$userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
  // $messages = $_POST['messages'] ?? $body['messages'] ?? []; $lineUserId = $_POST['line_user_id']
  // ?? $body['line_user_id'] ?? '';` — this is a new JSON-body-only endpoint (see module doc), so
  // only the `$body[...]` tier of each PHP fallback chain applies.
  const userId = phpIntCast(body.user_id);

  // inbox-v2.php lines 3176-3179: `if (!$userId) sendError('User ID is required');` — before any
  // messages parsing at all.
  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }

  // inbox-v2.php lines 3183-3185: `if (is_string($messages)) { $messages = json_decode($messages, true) ?? []; }`.
  let messagesValue: unknown = body.messages;
  if (typeof messagesValue === 'string') {
    try {
      const parsed: unknown = JSON.parse(messagesValue);
      messagesValue = parsed ?? [];
    } catch {
      messagesValue = [];
    }
  }
  const messages: unknown[] = Array.isArray(messagesValue) ? messagesValue : [];

  // inbox-v2.php lines 3187-3189: `if (empty($messages)) sendError('Messages array is required');`.
  if (messages.length === 0) {
    return NextResponse.json({ success: false, error: 'Messages array is required' }, { status: 400 });
  }

  const lineUserIdParam = typeof body.line_user_id === 'string' ? body.line_user_id : '';

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const result = await sendBatchMessagesAction(db, session, lineAccountId, userId, messages, lineUserIdParam);
    return NextResponse.json(result.body, { status: result.status });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Error sending batch messages: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
