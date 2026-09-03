import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { dispenseAction, type DispenseRequestBody } from './_lib/dispense';
import { intval } from './_lib/phpCompat';

/**
 * POST /api/inbox/actions/dispense — port of inbox-v2.php's `case 'dispense':` (lines
 * 469-736), the same-page AJAX action fired from the dispense modal (ระบบจ่ายยา). Reads a JSON
 * body (`{ user_id, items, total_amount, payment_method, notes, shop_name, pharmacist_name }`)
 * via `request.json()` — this is a new endpoint, not bound to PHP's `$_POST` shape.
 *
 * AUTH: same gate as every other `api/inbox/actions/**` Route Handler — a valid tenant session
 * (any of the six TenantRole values). inbox-v2.php's page shell requires a logged-in admin
 * (`includes/header.php`) before its same-page AJAX switch is ever reachable; this Route
 * Handler is reachable directly (not wrapped by `(tenant)/layout.tsx`), so it must perform that
 * check itself.
 *
 * ERROR SHAPE: mirrors inbox-v2.php's outer `catch (Exception $e) { http_response_code(400);
 * echo json_encode(['success' => false, 'error' => $e->getMessage()]); }` (lines 980-985) — any
 * error thrown out of `dispenseAction()` (including its own "User ID is required" / "No items
 * to dispense" / "User not found" validation throws) becomes a flat 400 with that literal
 * message. Per-sub-step fault tolerance (RefillTracking, LINE Flex send, cart writes, activity
 * log, etc.) is handled INSIDE `dispenseAction()` itself — see `_lib/dispense.ts`'s module doc.
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
  const body: DispenseRequestBody = raw !== null && typeof raw === 'object' ? (raw as DispenseRequestBody) : {};

  // inbox-v2.php lines 473-476:
  //   $userId = intval($_POST['user_id'] ?? 0);
  //   if (!$userId) throw new Exception('User ID is required');
  const userId = intval(body.user_id);
  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }

  const url = new URL(request.url);
  const origin = `${url.protocol}//${url.host}`;

  const { db, session } = auth.value;

  try {
    const result = await dispenseAction(db, session, userId, body, origin);
    return NextResponse.json(result.body, { status: result.status });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: message }, { status: 400 });
  }
}
