import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getSafeAlternatives } from './_lib/safeAlternatives';

/**
 * GET /api/inbox/actions/safe-alternatives — port of api/inbox-v2.php's
 * `case 'safe_alternatives': case 'safe-alternatives': case
 * 'get_safe_alternatives':` (lines ~1478-1508):
 *
 * ```php
 * case 'safe_alternatives':
 * case 'safe-alternatives':
 * case 'get_safe_alternatives':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $drugId = (int) ($_GET['drug_id'] ?? $_GET['id'] ?? 0);
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     if (!$drugId) { sendError('Drug ID is required'); }
 *     if (!$userId) { sendError('User ID is required'); }
 *     $recommendEngine = loadService('DrugRecommendEngineService', $db, $lineAccountId);
 *     if (!$recommendEngine) { sendError('Recommendation engine service not available', 503); }
 *     $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);
 *     if ($healthEngine) { $recommendEngine->setHealthEngine($healthEngine); }
 *     $result = $recommendEngine->getSafeAlternatives($drugId, $userId);
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * Port of `DrugRecommendEngineService::getSafeAlternatives()` — see
 * `_lib/safeAlternatives.ts`'s module doc for the full literal port
 * (including the `similar_text()` algorithm and the `bi.*` -> explicit
 * column-list simplification).
 *
 * GUARD ORDER: `user_id <= 0` is checked FIRST (400 `'Invalid user ID'`),
 * THEN `drug_id` falsy (400 `'Drug ID is required'`) — verified against
 * the literal PHP order above. The trailing `!$userId` check is
 * textually unreachable (same reasoning as `../medical-history/route.ts`'s
 * doc) and is not ported.
 *
 * Query params: `drug_id` or `id` (int, required); `user_id` (int,
 * required, 400 `'Invalid user ID'` if `<= 0`).
 *
 * "Recommendation engine service not available" 503 — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a) for why this Next port never fabricates that branch.
 *
 * `success: true` is UNCONDITIONAL — `getSafeAlternatives()` never throws
 * (`getDrugDetails()`/`getSimilarDrugs()` swallow their own DB errors), so
 * the `try/catch` below is a defensive addition using the house
 * `'Database error: {message}'` 500 shape.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP `(int) $v` for a query-string value — leading numeric parse, else 0. */
function toIntParam(value: string): number {
  const n = Number.parseInt(value, 10);
  return Number.isFinite(n) ? n : 0;
}

/**
 * PHP `$_GET['drug_id'] ?? $_GET['id'] ?? 0` — `??` is an `isset()` check,
 * not a truthiness check: the first key that is PRESENT in the query
 * string wins, even if its value casts to a falsy `0`.
 */
function firstSetIntParam(params: URLSearchParams, keys: readonly string[]): number {
  for (const key of keys) {
    if (params.has(key)) {
      return toIntParam(params.get(key) ?? '');
    }
  }
  return 0;
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

  const drugId = firstSetIntParam(params, ['drug_id', 'id']);
  if (!drugId) {
    return NextResponse.json({ success: false, error: 'Drug ID is required' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const result = await getSafeAlternatives(db, drugId, userId, lineAccountId);
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
