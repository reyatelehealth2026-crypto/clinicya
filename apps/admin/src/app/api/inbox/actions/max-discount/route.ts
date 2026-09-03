import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getMaxDiscount } from './_lib/drugPricingEngine';

/**
 * GET /api/inbox/actions/max-discount — port of api/inbox-v2.php's
 * `case 'max_discount': case 'max-discount':` (lines ~776-801):
 *
 * ```php
 * case 'max_discount':
 * case 'max-discount':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $drugId = (int) ($_GET['drug_id'] ?? $_GET['id'] ?? 0);
 *     $minMargin = isset($_GET['min_margin']) ? (float) $_GET['min_margin'] : 10.0;
 *     if (!$drugId) { sendError('Drug ID is required'); }
 *     $pricingEngine = loadService('DrugPricingEngineService', $db, $lineAccountId);
 *     if (!$pricingEngine) { sendError('Pricing engine service not available', 503); }
 *     $result = $pricingEngine->getMaxDiscount($drugId, $minMargin);
 *     sendResponse(['success' => !isset($result['error']), 'data' => $result]);
 *     break;
 * ```
 *
 * Query params: `drug_id` or `id` (int, required — 400 `'Drug ID is
 * required'` when both are absent/zero), `min_margin` (float, default 10.0).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * STATUS IS ALWAYS 200 — even for the error-shaped payloads
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `sendResponse([...])` call here passes NO explicit status-code
 * argument, so `sendResponse()`'s own default (200) applies even when
 * `getMaxDiscount()` returned an `error` key (drug not found, or an invalid
 * `min_margin` >= 100). This is a literal-parity PHP quirk, not a bug in
 * this port: `{success: false, data: {..., error: '...'}}` still comes back
 * as HTTP 200. Do not "fix" this into a 404/400 — `route.test.ts` asserts
 * the 200 directly.
 *
 * "Pricing engine service not available" 503 — see
 * `_lib/drugPricingEngine.ts`'s module doc: structurally unreachable in
 * Next (static import, not a runtime `file_exists()`/`class_exists()`
 * probe), so no runtime 503 branch is fabricated here.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP `(int) $v` for a query-string value that is already known to be present (`isset()` true). */
function toIntParam(value: string): number {
  const n = Number.parseInt(value, 10);
  return Number.isFinite(n) ? n : 0;
}

/** PHP `(float) $v` for a query-string value that is already known to be present (`isset()` true). */
function toFloatParam(value: string): number {
  const n = Number.parseFloat(value);
  return Number.isFinite(n) ? n : 0;
}

/**
 * PHP `$_GET['drug_id'] ?? $_GET['id'] ?? 0` — `??` is an `isset()` check,
 * not a truthiness check: the first key that is PRESENT in the query
 * string wins, even if its value casts to a falsy `0` (e.g. `?drug_id=0
 * &id=7` resolves to `0`, matching PHP, not `7`). Falls through to the next
 * key only when the prior key is entirely absent from the query string.
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
  const drugId = firstSetIntParam(params, ['drug_id', 'id']);
  if (!drugId) {
    return NextResponse.json({ success: false, error: 'Drug ID is required' }, { status: 400 });
  }
  const minMargin = params.has('min_margin') ? toFloatParam(params.get('min_margin') ?? '') : 10.0;

  const { db } = auth.value;

  try {
    const result = await getMaxDiscount(db, drugId, minMargin);
    return NextResponse.json({ success: !('error' in result), data: result });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
