import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { isAllowedField, updateCustomerInfo } from './_lib/updateCustomerInfo';

/**
 * POST /api/inbox/actions/update-customer-info — literal port of
 * `api/inbox-v2.php`'s `case 'update_customer_info':` (lines 2165-2196).
 *
 * ```php
 * case 'update_customer_info':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $body = getJsonBody();
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     $field = $_POST['field'] ?? $body['field'] ?? '';
 *     $value = trim($_POST['value'] ?? $body['value'] ?? '');
 *     $allowedFields = ['display_name', 'phone', 'address', 'email', 'real_name', 'birthday', 'province', 'postal_code', 'district', 'gender', 'note', 'member_id'];
 *     if (!$userId || !in_array($field, $allowedFields)) { sendError('Invalid user ID or field'); }
 *     try {
 *         // display_name -> custom_display_name (see _lib/updateCustomerInfo.ts); every other field -> its own column.
 *         $stmt->execute([$value ?: null, $userId]);
 *         sendResponse(['success' => true, 'message' => 'Customer info updated successfully']);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to update customer info: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * Body: `{user_id, field, value}` — read from the JSON body only, matching
 * every other ported action in this family.
 *
 * `ALLOWED_FIELDS` (exported from `_lib/updateCustomerInfo.ts`) is the exact
 * 12-field whitelist, verbatim order — this route never widens it. `field`
 * failing `isAllowedField()` (or a missing/zero `userId`) both produce the
 * SAME `400 'Invalid user ID or field'` response, matching PHP's single
 * combined `!$userId || !in_array($field, $allowedFields)` condition.
 *
 * See `_lib/updateCustomerInfo.ts`'s module doc for: the `display_name` ->
 * `custom_display_name` special case, the `Record<AllowedField, keyof
 * Users>` compile-time column-name proof, and `toNullable()`'s exact PHP
 * `$value ?: null` semantics (`''`/`'0'` -> `null`, everything else passes
 * through).
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
  const field = typeof body.field === 'string' ? body.field : '';
  const value = trimOrEmpty(body.value);

  if (!userId || !isAllowedField(field)) {
    return NextResponse.json({ success: false, error: 'Invalid user ID or field' }, { status: 400 });
  }

  const { db } = auth.value;

  try {
    await updateCustomerInfo(db, userId, field, value);
    return NextResponse.json({ success: true, message: 'Customer info updated successfully' });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json(
      { success: false, error: `Failed to update customer info: ${message}` },
      { status: 400 }
    );
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
