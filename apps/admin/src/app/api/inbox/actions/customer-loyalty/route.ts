import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getCustomerLoyalty } from './_lib/customerLoyalty';

/**
 * GET /api/inbox/actions/customer-loyalty — literal port of
 * `api/inbox-v2.php`'s `case 'customer_loyalty': case 'customer-loyalty':`
 * (lines 843-868), backed by `DrugPricingEngineService::getCustomerLoyalty()`.
 *
 * ```php
 * case 'customer_loyalty':
 * case 'customer-loyalty':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     if (!$userId) { sendError('User ID is required'); }
 *     $pricingEngine = loadService('DrugPricingEngineService', $db, $lineAccountId);
 *     if (!$pricingEngine) { sendError('Pricing engine service not available', 503); }
 *     $result = $pricingEngine->getCustomerLoyalty($userId);
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THIS BATCH'S ONE ALIAS PAIR — two case labels, one route
 * ═══════════════════════════════════════════════════════════════════════
 * Unlike every other action in this batch (each with exactly one PHP case
 * label), `customer_loyalty` has TWO: `case 'customer_loyalty':` AND
 * `case 'customer-loyalty':` (both fall through to the same body — no
 * `break` between them) — both route to this single `route.ts`.
 *
 * Query param: `user_id` (int). Only the REACHABLE `$userId <= 0` check is
 * ported as `400 'Invalid user ID'` — the second, textually-unreachable
 * `if (!$userId) { sendError('User ID is required'); }` immediately after
 * it can never execute (every value making `!$userId` true is already `<=
 * 0` and would have exited via the first check), same precedent already
 * established by `../patient-profile/route.ts` and
 * `../medical-history/route.ts` (Phase 4 batch 4b) for this exact
 * two-check PHP idiom.
 *
 * `success: true` is UNCONDITIONAL — unlike `patient-profile`/
 * `medical-history` (whose `success` derives from a `found` flag on the
 * result), `getCustomerLoyalty()`'s result has NO `found` key at all; PHP's
 * `sendResponse(['success' => true, 'data' => $result])` never varies this.
 * Do not copy the `found`-derived-`success` pattern here.
 *
 * "Pricing engine service not available" 503 — same reasoning as
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a): PHP's `loadService()` runtime `file_exists()`/`class_exists()` probe
 * has no Next analogue (a static TypeScript import either compiles or the
 * build fails outright), so this port never fabricates that branch.
 *
 * `case 'customer_loyalty':` has NO case-level try/catch in
 * `api/inbox-v2.php`'s switch — a genuinely unexpected error here falls
 * through to the outer `catch (Throwable $e)`. Following the same house
 * precedent Phase 4 batch 4a's `low-stock-drugs`/`drug-inventory` and batch
 * 4b's `patient-profile`/`medical-history` already established for this
 * identical no-case-catch situation, this route uses the uniform
 * `'Database error: {message}'` shape at HTTP 500 for that defensive
 * branch.
 *
 * `lineAccountId` (used by `getUserTierInfo()`'s `member_tiers`/
 * `points_tiers` queries) resolves as `session.currentBotId ?? 1` — the
 * established convention across this whole `api/inbox/actions/*` family.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six `TenantRole` values).
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

  const userId = toIntParam(request.nextUrl.searchParams.get('user_id') ?? '');
  if (userId <= 0) {
    return NextResponse.json({ success: false, error: 'Invalid user ID' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const result = await getCustomerLoyalty(db, lineAccountId, userId);
    return NextResponse.json({ success: true, data: result });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
