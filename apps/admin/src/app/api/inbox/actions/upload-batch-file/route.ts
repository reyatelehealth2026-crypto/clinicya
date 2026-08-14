import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { uploadBatchFileAction } from './_lib/uploadBatchFile';

/**
 * POST /api/inbox/actions/upload-batch-file — port of `api/inbox-v2.php`'s
 * `case 'upload_batch_file':` (lines 3489-3543), the chat composer's batch
 * message attachment stager. Reads `multipart/form-data` (a `file` field)
 * via `request.formData()`. See `_lib/uploadBatchFile.ts`'s module doc for
 * the full PHP source, validation order (size BEFORE type — the opposite
 * order from the sibling mediaSend batch's `send-image`/`send-pdf`), and
 * the confirmed no-'jpg'-fallback extension difference from that batch.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` Route Handler — a
 * valid tenant session (any of the six TenantRole values). Enforced here
 * even though `uploadBatchFileAction()` itself needs neither `db` nor
 * `session` (the PHP case body never touches `$db` or LINE) — the auth
 * boundary is about who may reach this Route Handler at all, not what the
 * downstream action happens to use (same reasoning as
 * `upload-for-analysis/route.ts`'s own doc comment).
 *
 * ERROR SHAPE: `api/inbox-v2.php`'s `case 'upload_batch_file':` has NO
 * case-level `catch` of its own — every `sendError()` call inside it is a
 * literal, immediate exit (see `_lib/uploadBatchFile.ts`'s validation-order
 * doc), and a genuinely unexpected PHP exception here would fall through to
 * the file's outer page-level `catch (Throwable $e)`. The try/catch below
 * is this route's own defensive wrapper for anything genuinely unexpected
 * (filesystem errors outside the one `sendError('Failed to save file')`
 * branch `uploadBatchFileAction()` already handles itself) — not a literal
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
    const result = await uploadBatchFileAction(form, origin);
    return NextResponse.json(result.body, { status: result.status });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to upload file: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
