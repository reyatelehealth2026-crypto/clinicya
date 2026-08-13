import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getDrugPricingData } from './_lib/drugPricingData';

/**
 * GET /api/inbox/actions/drug-pricing-data — port of api/inbox-v2.php's
 * `case 'drug_pricing_data': case 'drug-pricing-data':` (lines ~1022-1046):
 *
 * ```php
 * case 'drug_pricing_data':
 * case 'drug-pricing-data':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $productId = (int) ($_GET['product_id'] ?? $_GET['drug_id'] ?? $_GET['id'] ?? 0);
 *     if (!$productId) { sendError('Product ID is required'); }
 *     $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);
 *     if (!$integration) { sendError('Integration service not available', 503); }
 *     $result = $integration->getDrugPricing($productId);
 *     sendResponse(['success' => $result['found'] ?? false, 'data' => $result]);
 *     break;
 * ```
 *
 * Port of `PharmacyIntegrationService::getDrugPricing()` — see
 * `_lib/drugPricingData.ts`'s module doc for the full literal port
 * (including the PHP-truthiness `sale_price ? ... : null` / `cost_price ?
 * ... : null` semantics carried over exactly).
 *
 * Query params: `product_id`, `drug_id`, or `id` (int, required — 400
 * `'Product ID is required'` when all three are absent/zero).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * STATUS IS ALWAYS 200 — even for `found: false`
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `sendResponse([...])` call here passes NO explicit status-code
 * argument, so a not-found product still responds HTTP 200 with
 * `{success: false, data: {found: false, productId}}`. This is a
 * literal-parity PHP quirk, not a bug — do not add a 404 for this case.
 * `route.test.ts` asserts the 200 directly. A genuine DB failure is ALSO
 * a 200 (`{success: false, data: {found: false, productId, error}}`) —
 * see `_lib/drugPricingData.ts`'s module doc: `getDrugPricingData()` never
 * throws, it swallows its own DB errors into the return value, matching
 * `PharmacyIntegrationService::getDrugPricing()`'s own internal
 * `catch (PDOException $e)`. The `try/catch` below is therefore a
 * defensive addition for genuinely unexpected errors outside that
 * function's own DB call (e.g. a malformed session/db handle) — PHP has
 * no equivalent code path here, so this branch is not expected to be
 * exercised by real traffic.
 *
 * "Integration service not available" 503 — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc for why this
 * Next port never fabricates that branch (static import vs PHP's runtime
 * `file_exists()`/`class_exists()` probe).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP `(int) $v` for a query-string value that is already known to be present (`isset()` true). */
function toIntParam(value: string): number {
  const n = Number.parseInt(value, 10);
  return Number.isFinite(n) ? n : 0;
}

/**
 * PHP `$_GET['product_id'] ?? $_GET['drug_id'] ?? $_GET['id'] ?? 0` — `??`
 * is an `isset()` check, not a truthiness check: the first key that is
 * PRESENT in the query string wins, even if its value casts to a falsy `0`.
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
  const productId = firstSetIntParam(params, ['product_id', 'drug_id', 'id']);
  if (!productId) {
    return NextResponse.json({ success: false, error: 'Product ID is required' }, { status: 400 });
  }

  const { db } = auth.value;

  try {
    const result = await getDrugPricingData(db, productId);
    return NextResponse.json({ success: result.found ?? false, data: result });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
