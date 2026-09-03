import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { addCustomerNote } from './_lib/addCustomerNote';

/**
 * POST /api/inbox/actions/add-customer-note — literal port of
 * `api/inbox-v2.php`'s `case 'add_customer_note':` (lines 2026-2049).
 *
 * ```php
 * case 'add_customer_note':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_POST['user_id'] ?? 0);
 *     $content = trim($_POST['content'] ?? '');
 *     if (!$userId || empty($content)) { sendError('User ID and content are required'); }
 *     try {
 *         $stmt = $db->prepare("INSERT INTO user_notes (user_id, note, created_by, created_at) VALUES (?, ?, ?, NOW())");
 *         $stmt->execute([$userId, $content, $adminId ?? null]);
 *         sendResponse(['success' => true, 'message' => 'Note added successfully', 'note_id' => $db->lastInsertId()]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to add note: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * NOT the same route as the already-merged `actions/notes/route.ts` (ports
 * ROOT `inbox-v2.php`'s own `save_note` same-page-AJAX case — a different
 * PHP file's own switch, a 3-column INSERT with no `created_by`, and a
 * `{success, id}` response shape) — see `_lib/addCustomerNote.ts`'s module
 * doc for the full SQL-text diff cross-reference. This route does not
 * import from, or otherwise touch, `actions/notes/**`.
 *
 * Body: `{user_id, content}` — read from the JSON body only, matching every
 * other ported action in this family (PHP's `$_POST` has no Route Handler
 * analogue for a JSON-bodied request).
 *
 * `content` is `trim()`'d before the required-fields check AND before the
 * INSERT (matches PHP: `$content = trim($_POST['content'] ?? '');` happens
 * once, upstream of both the validation and the query).
 *
 * `created_by` binds `session.adminUserId` unconditionally — PHP's
 * `$adminId ?? null` can genuinely be `null` (no session/request admin id),
 * but `TenantSession.adminUserId` (`@reya/auth`) is always a number, so this
 * Route Handler always has a real value to bind. See `_lib/addCustomerNote.ts`'s
 * module doc for the nullable parameter kept for literal parity anyway.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six `TenantRole` values).
 */

/** PHP's `(int) $v` — loose int cast, non-numeric -> 0. */
function intval(value: unknown): number {
  if (typeof value === 'number') return Math.trunc(value);
  if (typeof value === 'string') {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}

/** `trim($v ?? '')` — coerces non-string inputs to string first (JSON bodies may carry non-strings). */
function trimOrEmpty(value: unknown): string {
  if (typeof value === 'string') return value.trim();
  if (value === undefined || value === null) return '';
  return String(value).trim();
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const raw: unknown = await request.json().catch(() => ({}));
  const body = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

  const userId = intval(body.user_id);
  const content = trimOrEmpty(body.content);
  if (!userId || !content) {
    return NextResponse.json({ success: false, error: 'User ID and content are required' }, { status: 400 });
  }

  const { db, session } = auth.value;

  try {
    const { noteId } = await addCustomerNote(db, userId, content, session.adminUserId);
    return NextResponse.json({ success: true, message: 'Note added successfully', note_id: noteId });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to add note: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
