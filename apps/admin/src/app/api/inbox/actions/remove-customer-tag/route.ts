import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { removeCustomerTag } from './_lib/removeCustomerTag';

/**
 * POST /api/inbox/actions/remove-customer-tag — literal port of
 * `api/inbox-v2.php`'s `case 'remove_customer_tag':` (lines 2105-2130).
 *
 * ```php
 * case 'remove_customer_tag':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $body = getJsonBody();
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     $tagId = (int) ($_POST['tag_id'] ?? $body['tag_id'] ?? 0);
 *     if (!$userId || !$tagId) { sendError('User ID and tag ID are required'); }
 *     try {
 *         $stmt = $db->prepare("DELETE FROM user_tag_assignments WHERE user_id = ? AND tag_id = ?");
 *         $stmt->execute([$userId, $tagId]);
 *         sendResponse(['success' => true, 'message' => 'Tag removed successfully']);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to remove tag: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * Body: `{user_id, tag_id}` — read from the JSON body only. PHP already
 * calls `getJsonBody()` itself for this action, falling back to `$_POST`
 * first — a Route Handler has no `$_POST` analogue for a JSON-bodied
 * request, so only the JSON body is read here, matching every other ported
 * action in this batch (e.g. the already-merged `actions/assign-tag/route.ts`).
 *
 * Success is sent UNCONDITIONALLY — PHP never inspects
 * `$stmt->rowCount()`, so a `DELETE` matching zero rows (e.g. the tag was
 * already removed) still returns `{success: true, message: 'Tag removed
 * successfully'}`. See `_lib/removeCustomerTag.ts`'s module doc.
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

export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const raw: unknown = await request.json().catch(() => ({}));
  const body = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

  const userId = intval(body.user_id);
  const tagId = intval(body.tag_id);
  if (!userId || !tagId) {
    return NextResponse.json({ success: false, error: 'User ID and tag ID are required' }, { status: 400 });
  }

  const { db } = auth.value;

  try {
    await removeCustomerTag(db, userId, tagId);
    return NextResponse.json({ success: true, message: 'Tag removed successfully' });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to remove tag: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
