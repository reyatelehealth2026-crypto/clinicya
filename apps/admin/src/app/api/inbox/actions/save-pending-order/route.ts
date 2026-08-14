import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { savePendingOrder } from './_lib/savePendingOrder';

/**
 * POST /api/inbox/actions/save-pending-order — literal port of
 * api/inbox-v2.php's `case 'save_pending_order':` (lines ~1832-1878).
 *
 * ```php
 * case 'save_pending_order':
 *     if ($method !== 'POST') {
 *         sendError('Method not allowed', 405);
 *     }
 *
 *     $body = getJsonBody();
 *     $userId = (int) ($body['user_id'] ?? 0);
 *     $items = $body['items'] ?? [];
 *     $subtotal = (float) ($body['subtotal'] ?? 0);
 *     $discount = (float) ($body['discount'] ?? 0);
 *     $total = (float) ($body['total'] ?? 0);
 *
 *     if (!$userId) {
 *         sendError('User ID is required');
 *     }
 *
 *     if (empty($items)) {
 *         sendError('Items are required');
 *     }
 *
 *     $pendingOrderData = [
 *         'items' => $items,
 *         'subtotal' => $subtotal,
 *         'discount' => $discount,
 *         'total' => $total,
 *         'created_at' => date('Y-m-d H:i:s'),
 *         'line_account_id' => $lineAccountId
 *     ];
 *
 *     $expiresAt = date('Y-m-d H:i:s', strtotime('+30 minutes'));
 *
 *     try {
 *         // ... INSERT ... ON DUPLICATE KEY UPDATE into user_states (see
 *         // _lib/savePendingOrder.ts for the SHOW-KEYS-probe simplification) ...
 *         sendResponse(['success' => true, 'message' => 'Pending order saved', 'expires_at' => $expiresAt]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to save pending order: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * `sendError()`'s DEFAULT status code is 400 (`function sendError(string
 * $message, int $statusCode = 400)`) — this case's own `catch` block calls
 * `sendError('Failed to save pending order: ' . $e->getMessage())` with NO
 * explicit status argument, so a DB failure here is HTTP 400, not 500
 * (unlike the defensive `'Database error: ...'` 500 shape used by routes
 * whose PHP case has no case-level try/catch at all — this case DOES have
 * one, and its catch produces this exact 400 message/shape).
 *
 * This JSON body accepts only `{ user_id, items, subtotal, discount, total
 * }` — a Route Handler has no `$_POST` equivalent, so only the JSON body is
 * read here (PHP itself only reads `getJsonBody()` for this action too —
 * unlike some sibling actions, there is no `$_POST['x'] ?? $body['x']`
 * dual-read to reproduce for `save_pending_order`).
 *
 * `lineAccountId` (folded into `pendingOrderData.line_account_id`) resolves
 * as `session.currentBotId ?? 1` — the established `../get-admins/route.ts`
 * precedent, NOT PHP's full 4-tier session/query/body fallback chain (per
 * this batch's brief).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP's `(int) $value` — loose int cast, non-numeric -> 0. */
function toIntCast(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? Math.trunc(value) : 0;
  if (typeof value === 'string') {
    const match = /^\s*[+-]?\d+/.exec(value);
    return match ? Number.parseInt(match[0], 10) : 0;
  }
  return 0;
}

/** PHP's `(float) $value` — loose float cast, non-numeric -> 0. */
function toFloatCast(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
  if (typeof value === 'string') {
    const match = /^\s*[+-]?\d+(\.\d+)?/.exec(value);
    return match ? Number.parseFloat(match[0]) : 0;
  }
  return 0;
}

/** PHP's `empty($v)` for a JSON-decoded `items` value (array or object): missing/null/false/0/'0'/''/[]/{} are all "empty". */
function phpEmpty(value: unknown): boolean {
  if (value === null || value === undefined || value === false) return true;
  if (value === 0 || value === '0' || value === '') return true;
  if (Array.isArray(value)) return value.length === 0;
  if (typeof value === 'object') return Object.keys(value as object).length === 0;
  return false;
}

/** `YYYY-MM-DD HH:MM:SS` in local wall-clock — same convention as `../patient-profile/_lib/patientProfile.ts` / `../prescription-history/_lib/prescriptionHistory.ts`, duplicated here per this batch's brief (not imported). */
function toMysqlDateTimeString(value: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
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

  const userId = toIntCast(body.user_id ?? 0);
  const items = body.items ?? [];
  const subtotal = toFloatCast(body.subtotal ?? 0);
  const discount = toFloatCast(body.discount ?? 0);
  const total = toFloatCast(body.total ?? 0);

  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }
  if (phpEmpty(items)) {
    return NextResponse.json({ success: false, error: 'Items are required' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  const now = new Date();
  const pendingOrderData = {
    items,
    subtotal,
    discount,
    total,
    created_at: toMysqlDateTimeString(now),
    line_account_id: lineAccountId,
  };
  const expiresAt = toMysqlDateTimeString(new Date(now.getTime() + 30 * 60 * 1000));

  try {
    await savePendingOrder(db, {
      userId,
      stateData: JSON.stringify(pendingOrderData),
      expiresAt,
    });
    return NextResponse.json({ success: true, message: 'Pending order saved', expires_at: expiresAt });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to save pending order: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
