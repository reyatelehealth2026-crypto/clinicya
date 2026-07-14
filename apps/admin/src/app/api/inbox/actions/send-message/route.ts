import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { phpIntCast, sendMessageAction } from './_lib/sendMessage';

/**
 * POST /api/inbox/actions/send-message — port of inbox-v2.php's
 * `case 'send_message':` (lines 236-336), LINE-platform branch only (see
 * `_lib/sendMessage.ts`'s module doc for the Facebook/TikTok scope
 * boundary). Reads a JSON body (`{ user_id, message, reply_to_id? }`) via
 * `request.json()` — this is a new endpoint, not bound to PHP's `$_POST`
 * shape.
 *
 * AUTH: same gate as `api/inbox/messages/route.ts` and
 * `api/inbox/conversations/route.ts` — a valid tenant session (any of the
 * six TenantRole values). inbox-v2.php's page shell requires a logged-in
 * admin (`includes/header.php`) before its same-page AJAX switch is ever
 * reachable; this Route Handler is reachable directly (not wrapped by
 * `(tenant)/layout.tsx`), so it must perform that check itself.
 *
 * This is a pure API/data-layer deliverable — wiring an actual
 * composer/send-button UI into the chat thread pane is a separate future
 * batch (mig-ui territory), not part of this one.
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

  // inbox-v2.php lines 237-240:
  //   $userId = intval($_POST['user_id'] ?? 0);
  //   $message = trim($_POST['message'] ?? '');
  //   if (!$userId || !$message) throw new Exception("Invalid data");
  const userId = phpIntCast(body.user_id);
  const message = typeof body.message === 'string' ? body.message.trim() : '';
  if (!userId || !message) {
    return NextResponse.json({ success: false, error: 'Invalid data' }, { status: 400 });
  }

  const replyToId = body.reply_to_id !== undefined && body.reply_to_id !== null ? phpIntCast(body.reply_to_id) : null;

  const { db, session } = auth.value;

  try {
    const result = await sendMessageAction(db, session, userId, message, replyToId);
    return NextResponse.json(result.body, { status: result.status });
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to send message: ${errorMessage}` }, { status: 400 });
  }
}
