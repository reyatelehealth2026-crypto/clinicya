import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { classifyCustomer } from './_lib/classifyCustomer';

/**
 * GET /api/inbox/actions/classify-customer — port of api/inbox-v2.php's
 * `case 'classify_customer': case 'classify-customer':` (lines ~363-391):
 *
 * ```php
 * case 'classify_customer':
 * case 'classify-customer':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     $minMessages = (int) ($_GET['min_messages'] ?? 5);
 *     if (!$userId) { sendError('User ID is required'); }
 *     $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);
 *     if (!$healthEngine) { sendError('Health engine service not available', 503); }
 *     $classification = $healthEngine->classifyCustomer($userId, $minMessages);
 *     sendResponse(['success' => true, 'data' => $classification]);
 *     break;
 * ```
 *
 * Query params: `user_id` (int, required, must be > 0) and `min_messages`
 * (int, defaulting to `5` — this is the ROUTE's OWN literal default; see
 * `_lib/classifyCustomer.ts`'s module doc for why this is deliberately NOT
 * the `CustomerHealthEngineService::MIN_MESSAGES_FOR_CLASSIFICATION`
 * constant's own value of `1` — that constant is only the PHP *method
 * signature's* default, used when the method is called with no second
 * argument at all, which this route never does).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE SECOND, TEXTUALLY-UNREACHABLE `!$userId` CHECK IS NOT PORTED
 * ═══════════════════════════════════════════════════════════════════════
 * Same "double user-id guard" pattern as `../customer-health/route.ts` and
 * `../medical-history/route.ts`: every value of `$userId` that makes
 * `!$userId` true (only `0`) is already `<= 0` and would have exited via the
 * FIRST check — the second `sendError('User ID is required')` can never
 * execute. Only the reachable `'Invalid user ID'` 400 is ported.
 *
 * "Health engine service not available" 503 — PHP's `loadService()` guard
 * has no Next analogue (a static TypeScript import either compiles and is
 * present in the bundle, or the build fails outright) — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc. Not fabricated
 * here.
 *
 * STATUS IS ALWAYS 200 — PHP's `sendResponse()` call passes no explicit
 * status code. `classifyCustomer()`'s own DB reads/writes each have their
 * own `catch` (see `_lib/classifyCustomer.ts`'s module doc) — it never
 * throws. No case-level try/catch of its own in PHP either.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP `(int) $v` for a query-string value — leading numeric parse, else 0. */
function toIntParam(value: string): number {
  const n = Number.parseInt(value, 10);
  return Number.isFinite(n) ? n : 0;
}

export async function GET(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const params = request.nextUrl.searchParams;
  const userId = toIntParam(params.get('user_id') ?? '');
  if (userId <= 0) {
    return NextResponse.json({ success: false, error: 'Invalid user ID' }, { status: 400 });
  }

  const minMessages = toIntParam(params.get('min_messages') ?? '5');

  const { db } = auth.value;
  const classification = await classifyCustomer(db, userId, minMessages);

  return NextResponse.json({ success: true, data: classification });
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
