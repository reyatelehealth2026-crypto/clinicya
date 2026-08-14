import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { uploadForAnalysisAction } from './_lib/uploadForAnalysis';

/**
 * POST /api/inbox/actions/upload-for-analysis — port of inbox-v2.php's
 * `case 'upload_for_analysis':` (lines 836-874), the chat composer's "analyze this image" upload
 * button. Reads `multipart/form-data` (an `image` file field — `user_id`, if sent, is ignored,
 * see `_lib/uploadForAnalysis.ts`'s module doc) via `request.formData()`.
 *
 * AUTH: same gate as every other `api/inbox/actions/**` Route Handler — a valid tenant session
 * (any of the six TenantRole values). This is enforced here even though
 * `uploadForAnalysisAction()` itself needs neither `db` nor `session` (the PHP case body never
 * touches `$db` or LINE) — the auth boundary is about who may reach this Route Handler at all,
 * not what the downstream action happens to use. inbox-v2.php's page shell requires a logged-in
 * admin (`includes/header.php`) before its same-page AJAX switch is ever reachable; this Route
 * Handler is reachable directly (not wrapped by `(tenant)/layout.tsx`), so it must perform that
 * check itself.
 *
 * ERROR SHAPE: mirrors inbox-v2.php's outer `catch (Exception $e) { http_response_code(400);
 * echo json_encode(['success' => false, 'error' => $e->getMessage()]); }` (lines 982-985) — every
 * validation branch inside `uploadForAnalysisAction()` returns that literal shape directly; the
 * try/catch below is a defensive wrapper for anything genuinely unexpected (fs errors), not a
 * port of a specific PHP branch.
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

  try {
    const result = await uploadForAnalysisAction(form, origin);
    return NextResponse.json(result.body, { status: result.status });
  } catch (error) {
    const errorMessage = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to upload image: ${errorMessage}` }, { status: 400 });
  }
}
