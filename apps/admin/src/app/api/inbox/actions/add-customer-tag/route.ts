import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { addCustomerTagAction, resolveAssignedBy } from './_lib/addCustomerTag';

/**
 * POST /api/inbox/actions/add-customer-tag — literal port of
 * `api/inbox-v2.php`'s `case 'add_customer_tag':` (lines 2057-2090).
 *
 * ```php
 * case 'add_customer_tag':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_POST['user_id'] ?? 0);
 *     $tagName = trim($_POST['tag_name'] ?? '');
 *     if (!$userId || empty($tagName)) { sendError('User ID and tag name are required'); }
 *     try {
 *         // find-or-create by $tagName, then INSERT IGNORE the assignment — see _lib/addCustomerTag.ts
 *         sendResponse(['success' => true, 'message' => 'Tag added successfully', 'tag_id' => $tagId]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to add tag: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * NOT the same route as the already-merged `actions/assign-tag/route.ts`
 * (ports the byte-adjacent `case 'assign_tag':`, a DIFFERENT case label with
 * NO find-or-create-by-name preamble — it takes an existing `tag_id`
 * directly from the request body). See `_lib/addCustomerTag.ts`'s module
 * doc for the full SQL-text cross-reference confirming exactly where the
 * two case blocks diverge (the find-or-create preamble) and where they are
 * byte-for-byte identical (the final `INSERT IGNORE INTO
 * user_tag_assignments`). This route does not import from, or otherwise
 * touch, `actions/assign-tag/**`.
 *
 * Body: `{user_id, tag_name}` — read from the JSON body only, matching every
 * other ported action in this family.
 *
 * `tag_name` is `trim()`'d before the required-fields check AND before the
 * find-or-create query (matches PHP: `$tagName = trim($_POST['tag_name'] ??
 * '');` happens once, upstream of both).
 *
 * `lineAccountId` (used for the `user_tags` find-or-create scope) resolves
 * as `session.currentBotId ?? 1` — the established convention across this
 * whole `api/inbox/actions/*` family.
 *
 * `assigned_by` resolves via `resolveAssignedBy(session.adminUserId)` — see
 * `_lib/addCustomerTag.ts`'s module doc for why the PHP `?? 'Admin'`
 * fallback is structurally unreachable here but kept for literal parity.
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
  const tagName = trimOrEmpty(body.tag_name);
  if (!userId || !tagName) {
    return NextResponse.json({ success: false, error: 'User ID and tag name are required' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const { tagId } = await addCustomerTagAction(
      db,
      userId,
      tagName,
      lineAccountId,
      resolveAssignedBy(session.adminUserId)
    );
    return NextResponse.json({ success: true, message: 'Tag added successfully', tag_id: tagId });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to add tag: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
