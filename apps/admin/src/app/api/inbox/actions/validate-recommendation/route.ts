import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { validateDrugRecommendation } from './_lib/validateRecommendation';

/**
 * POST /api/inbox/actions/validate-recommendation — port of
 * api/inbox-v2.php's `case 'validate_recommendation': case
 * 'validate-recommendation':` (lines ~1052-1081):
 *
 * ```php
 * case 'validate_recommendation':
 * case 'validate-recommendation':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $body = getJsonBody();
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     $productId = (int) ($_POST['product_id'] ?? $body['product_id'] ?? $body['drug_id'] ?? 0);
 *     if (!$userId) { sendError('User ID is required'); }
 *     if (!$productId) { sendError('Product ID is required'); }
 *     $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);
 *     if (!$integration) { sendError('Integration service not available', 503); }
 *     $result = $integration->validateDrugRecommendation($userId, $productId);
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * Port of `PharmacyIntegrationService::validateDrugRecommendation()` — see
 * `_lib/validateRecommendation.ts`'s module doc: this route is almost
 * entirely composition of already-ported, read-only-imported siblings
 * (`getDrugInventory`, `getUserMedicalHistory`, `checkDrugInteractions`).
 *
 * Body: `{ user_id: number, product_id?: number, drug_id?: number }` —
 * `product_id` falls back to `drug_id` when absent (PHP's own
 * three-way `??` chain: `body.product_id ?? body.drug_id ?? 0`; a Route
 * Handler has no `$_POST` equivalent, so only the JSON body is read).
 * 400 `'User ID is required'` when `user_id` is falsy; 400 `'Product ID is
 * required'` when the resolved `product_id` is falsy.
 *
 * "Integration service not available" 503 — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a) for why this Next port never fabricates that branch.
 *
 * `success: true` is UNCONDITIONAL — `validateDrugRecommendation()` never
 * throws (every DB call it makes, via the three imported helpers, swallows
 * its own errors), so the `try/catch` below is a defensive addition using
 * the house `'Database error: {message}'` 500 shape.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP's `(int) $v` — loose int cast, non-numeric -> 0. */
function intval(value: unknown): number {
  if (typeof value === 'number') return Math.trunc(value);
  if (typeof value === 'string') {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}

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
  const body = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

  const userId = intval(body.user_id ?? 0);
  const productId = intval(body.product_id ?? body.drug_id ?? 0);

  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }
  if (!productId) {
    return NextResponse.json({ success: false, error: 'Product ID is required' }, { status: 400 });
  }

  const { db } = auth.value;

  try {
    const result = await validateDrugRecommendation(db, userId, productId);
    return NextResponse.json({ success: true, data: result });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
