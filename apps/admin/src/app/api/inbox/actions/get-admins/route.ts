import { NextResponse } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getAdmins } from './_lib/getAdmins';

/**
 * GET /api/inbox/actions/get-admins — port of api/inbox-v2.php's
 * `case 'get_admins':` (lines ~2434-2456), the same-page GET AJAX action
 * used to populate the assignment dropdown in inbox-v2.php's dispense/
 * assign modal. No query params.
 *
 * `lineAccountId` resolves as `session.currentBotId ?? 1` — the established
 * `api/inbox/conversations/route.ts` precedent (`$_SESSION['current_bot_id']
 * ?? 1`, api/inbox-v2.php line 71's first fallback link), NOT the full
 * 4-tier `$_SESSION['current_bot_id'] ?? $_SESSION['line_account_id'] ??
 * $_GET['line_account_id'] ?? $_POST['line_account_id'] ?? 1` chain — this
 * endpoint takes no query params at all, so tiers 2-4 of PHP's chain are
 * unreachable from this route's own call shape regardless (per this
 * batch's brief).
 *
 * See `_lib/getAdmins.ts`'s module doc for the `admin_users` schema-drift
 * finding this endpoint's happy path depends on, and for why a missing-table
 * throw is left to propagate here (not swallowed) — reproducing PHP's own
 * `case 'get_admins':` try/catch -> `sendError('Failed to get admin list: '
 * . $e->getMessage())` (a clean 400 JSON error, matching `sendError`'s
 * default status code).
 */
export async function GET(): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }
  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const admins = await getAdmins(db, lineAccountId);
    return NextResponse.json({ success: true, data: admins });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to get admin list: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
