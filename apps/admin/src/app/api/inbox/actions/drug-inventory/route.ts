import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getDrugInventory } from './_lib/drugInventory';

/**
 * GET /api/inbox/actions/drug-inventory — port of api/inbox-v2.php's
 * `case 'drug_inventory': case 'drug-inventory': case 'get_drug_inventory':`
 * (lines ~986-1011):
 *
 * ```php
 * case 'drug_inventory':
 * case 'drug-inventory':
 * case 'get_drug_inventory':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $productId = (int) ($_GET['product_id'] ?? $_GET['drug_id'] ?? $_GET['id'] ?? 0);
 *     if (!$productId) { sendError('Product ID is required'); }
 *     $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);
 *     if (!$integration) { sendError('Integration service not available', 503); }
 *     $result = $integration->getDrugInventory($productId);
 *     sendResponse(['success' => $result['found'] ?? false, 'data' => $result]);
 *     break;
 * ```
 *
 * Port of `PharmacyIntegrationService::getDrugInventory()` — see
 * `_lib/drugInventory.ts`'s module doc for the full literal port, and for
 * the CONFIRMED SCHEMA-DRIFT FIX this port applies: PHP's `SELECT ...
 * is_prescription ...` throws on every real call in production
 * (`business_items.is_prescription` does not exist — the real column is
 * `requires_prescription`), so `case 'drug_inventory':` today ALWAYS
 * returns `{success: false, data: {found: false, productId, error}}`
 * regardless of whether the product exists. This port queries the real
 * `requires_prescription` column (aliased to `is_prescription` in the
 * result set) instead of reproducing that always-broken behavior — a
 * deliberate, documented fix-forward deviation (same precedent as Phase 4
 * batch 3's assign-conversation route).
 *
 * Query params: `product_id`, `drug_id`, or `id` (int, required — 400
 * `'Product ID is required'` when all three are absent/zero).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * STATUS IS ALWAYS 200 — even for `found: false`
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `sendResponse([...])` call here passes NO explicit status-code
 * argument. A not-found product AND a genuine DB error both respond HTTP
 * 200 with `success: false` (`getDrugInventory()` never throws — it
 * swallows its own DB errors into the return value, matching
 * `PharmacyIntegrationService::getDrugInventory()`'s own internal
 * `catch (PDOException $e)`; see `_lib/drugInventory.ts`'s module doc).
 * The `try/catch` below is a defensive addition for genuinely unexpected
 * errors outside that function's own DB call — PHP has no equivalent code
 * path here, so this branch is not expected to be exercised by real
 * traffic.
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
    const result = await getDrugInventory(db, productId);
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
