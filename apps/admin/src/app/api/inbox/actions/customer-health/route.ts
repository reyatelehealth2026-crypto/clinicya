import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getHealthProfile } from './_lib/customerHealth';

/**
 * GET /api/inbox/actions/customer-health — port of api/inbox-v2.php's
 * `case 'customer_health': case 'customer-health': case
 * 'get_customer_health':` (lines ~329-357):
 *
 * ```php
 * case 'customer_health':
 * case 'customer-health':
 * case 'get_customer_health':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     if (!$userId) { sendError('User ID is required'); }
 *     $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);
 *     if (!$healthEngine) { sendError('Health engine service not available', 503); }
 *     $profile = $healthEngine->getHealthProfile($userId);
 *     sendResponse(['success' => true, 'data' => $profile]);
 *     break;
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE SECOND, TEXTUALLY-UNREACHABLE `!$userId` CHECK IS NOT PORTED
 * ═══════════════════════════════════════════════════════════════════════
 * Same "double user-id guard" pattern already documented on
 * `../medical-history/route.ts` (Phase 4 batch 4b's runbook): every value of
 * `$userId` that makes `!$userId` true (only `0`, since `$userId` is always
 * an `(int)` cast) is already `<= 0` and would have exited via the FIRST
 * check — the second `sendError('User ID is required')` can never execute.
 * Only the reachable `'Invalid user ID'` 400 is ported.
 *
 * "Health engine service not available" 503 — PHP's `loadService()` guard
 * does a runtime `file_exists()`/`class_exists()` probe with no Next
 * analogue (a static TypeScript import either compiles and is present in the
 * bundle, or the build fails outright) — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc for the full
 * reasoning. Not fabricated here.
 *
 * STATUS IS ALWAYS 200 — PHP's `sendResponse()` call passes no explicit
 * status code, and `getHealthProfile()`'s own transitively-called DB reads
 * each have their own `catch (PDOException $e)` (see `_lib/customerHealth.ts`'s
 * module doc) — it never throws. No case-level try/catch of its own in PHP
 * either.
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

  const { db } = auth.value;
  const profile = await getHealthProfile(db, userId);

  return NextResponse.json({ success: true, data: profile });
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
