import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getLowStockDrugs } from './_lib/lowStockDrugs';

/**
 * GET /api/inbox/actions/low-stock-drugs — port of api/inbox-v2.php's
 * `case 'low_stock_drugs': case 'low-stock-drugs':` (lines ~1161-1182):
 *
 * ```php
 * case 'low_stock_drugs':
 * case 'low-stock-drugs':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $limit = (int) ($_GET['limit'] ?? 50);
 *     $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);
 *     if (!$integration) { sendError('Integration service not available', 503); }
 *     $result = $integration->getLowStockDrugs($limit);
 *     sendResponse(['success' => true, 'data' => $result, 'count' => count($result)]);
 *     break;
 * ```
 *
 * Port of `PharmacyIntegrationService::getLowStockDrugs()` — see
 * `_lib/lowStockDrugs.ts`'s module doc for the full literal port, and for
 * the CONFIRMED SCHEMA-DRIFT FIX this port applies: PHP's `SELECT ...
 * is_prescription ...` throws on every real call in production
 * (`business_items.is_prescription` does not exist — the real column is
 * `requires_prescription`), so `case 'low_stock_drugs':` today ALWAYS
 * returns `{success: true, data: [], count: 0}` regardless of how many
 * drugs are actually low on stock. This port queries the real
 * `requires_prescription` column (aliased to `is_prescription` in the
 * result set) instead of reproducing that always-empty behavior — a
 * deliberate, documented fix-forward deviation (same precedent as Phase 4
 * batch 3's assign-conversation route).
 *
 * Query param: `limit` (int, default 50, no lower/upper clamp — matches
 * PHP's own unbounded `(int) ($_GET['limit'] ?? 50)`).
 *
 * ALWAYS `success: true`, HTTP 200 — unlike `drug-inventory`/
 * `drug-pricing-data`, this action has no `found`/error check at all;
 * `getLowStockDrugs()` never throws (a genuine DB failure resolves to an
 * empty array internally, matching PHP's own `catch (PDOException $e) {
 * return []; }` — see `_lib/lowStockDrugs.ts`'s module doc), so this
 * route's own `try/catch` below is a defensive addition for genuinely
 * unexpected errors outside that function's own DB call and is not
 * expected to be exercised by real traffic.
 *
 * "Integration service not available" 503 — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc for why this
 * Next port never fabricates that branch (static import vs PHP's runtime
 * `file_exists()`/`class_exists()` probe).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP `(int) $v` for a query-string value — leading numeric parse, else 0 (used for `?? 50`'s fallback default, so absence is handled by the caller, not this cast). */
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
  // `(int) ($_GET['limit'] ?? 50)` — `??` is isset()-based: an absent `limit` -> 50, a present-but-empty/non-numeric `limit` -> (int) cast of it (0).
  const limit = params.has('limit') ? toIntParam(params.get('limit') ?? '') : 50;

  const { db } = auth.value;

  try {
    const data = await getLowStockDrugs(db, limit);
    return NextResponse.json({ success: true, data, count: data.length });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
