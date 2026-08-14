import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { sendPdfAction } from './_lib/sendPdf';

/**
 * POST /api/inbox/actions/send-pdf — port of inbox-v2.php's `case 'send_pdf':` (lines 876-977),
 * the chat composer's "send PDF" upload button. Reads `multipart/form-data` (`user_id` + a `pdf`
 * file field) via `request.formData()` — this is a new endpoint, not bound to PHP's
 * `$_FILES`/`$_POST` superglobal shape.
 *
 * AUTH: same gate as every other `api/inbox/actions/**` Route Handler — a valid tenant session
 * (any of the six TenantRole values). inbox-v2.php's page shell requires a logged-in admin
 * (`includes/header.php`) before its same-page AJAX switch is ever reachable; this Route Handler
 * is reachable directly (not wrapped by `(tenant)/layout.tsx`), so it must perform that check
 * itself.
 *
 * ERROR SHAPE: mirrors inbox-v2.php's outer `catch (Exception $e) { http_response_code(400);
 * echo json_encode(['success' => false, 'error' => $e->getMessage()]); }` (lines 982-985) — every
 * validation branch inside `sendPdfAction()` returns that literal shape directly; the try/catch
 * below is a defensive wrapper for anything genuinely unexpected (fs/DB errors), not a port of a
 * specific PHP branch.
 */
export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  let form: FormData;
  try {
    form = await request.formData();
  } catch {
    form = new FormData();
  }

  const url = new URL(request.url);
  const origin = `${url.protocol}//${url.host}`;

  const { db, session } = auth.value;

  try {
    const result = await sendPdfAction(db, session, form, origin);
    return NextResponse.json(result.body, { status: result.status });
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to send PDF: ${errorMessage}` }, { status: 400 });
  }
}
