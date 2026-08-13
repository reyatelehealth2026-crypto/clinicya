import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { assignTagAction, resolveAssignedBy } from './_lib/assignTag';

/**
 * POST /api/inbox/actions/assign-tag — literal port of api/inbox-v2.php's
 * `case 'assign_tag':` (lines 2130-2153).
 *
 * ```php
 * case 'assign_tag':
 *     if ($method !== 'POST') {
 *         sendError('Method not allowed', 405);
 *     }
 *
 *     $body = getJsonBody();
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     $tagId = (int) ($_POST['tag_id'] ?? $body['tag_id'] ?? 0);
 *
 *     if (!$userId || !$tagId) {
 *         sendError('User ID and tag ID are required');
 *     }
 *
 *     try {
 *         $stmt = $db->prepare("INSERT IGNORE INTO user_tag_assignments (user_id, tag_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())");
 *         $stmt->execute([$userId, $tagId, $adminId ?? 'Admin']);
 *
 *         sendResponse([
 *             'success' => true,
 *             'message' => 'Tag assigned successfully'
 *         ]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to assign tag: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * A BRAND-NEW, SEPARATE route from the same-page-AJAX `update_tags` action
 * (a different PHP file's own switch, already ported at the sibling,
 * already-merged `actions/tags` Route Handler — add/remove-toggle
 * semantics, `assigned_by` hardcoded to `'manual'`, echoes the caller's
 * full current tag list back). This action is insert-only: no
 * remove/toggle branch and no tag-list echo in the response. See
 * `_lib/assignTag.ts`'s module doc for the full distinction and the
 * schema-drift finding this INSERT IGNORE shares with that other action's
 * own `add` branch on the same table. Nothing in this directory imports
 * from, or otherwise touches, that other action family's files.
 *
 * This JSON body accepts only `{ user_id, tag_id }` — this is a new
 * endpoint, not bound to PHP's `$_POST`-or-JSON-body dual read (PHP reads
 * `$_POST['user_id'] ?? $body['user_id']`; a Route Handler has no `$_POST`
 * equivalent, so only the JSON body is read here, matching every other
 * ported action in this batch).
 *
 * AUTH: same gate as the sibling `mark-all-read`/`mark-as-read-on-line`
 * actions in this batch and every other `api/inbox/actions/*` Route
 * Handler — a valid tenant session (any of the six TenantRole values). See
 * `_lib/session.ts`'s module doc for why this isn't a literal port of a PHP
 * auth check (api/inbox-v2.php has none ahead of its action switch).
 */

/** PHP's `(int) $value` — loose int cast, non-numeric -> 0. */
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
  const tagId = intval(body.tag_id);
  if (!userId || !tagId) {
    return NextResponse.json({ success: false, error: 'User ID and tag ID are required' }, { status: 400 });
  }

  const { db, session } = auth.value;

  try {
    await assignTagAction(db, userId, tagId, resolveAssignedBy(session.adminUserId));
    return NextResponse.json({ success: true, message: 'Tag assigned successfully' });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to assign tag: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
