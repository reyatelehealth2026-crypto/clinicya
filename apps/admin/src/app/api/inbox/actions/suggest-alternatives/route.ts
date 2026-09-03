import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { suggestAlternatives } from './_lib/suggestAlternatives';

/**
 * POST /api/inbox/actions/suggest-alternatives — port of api/inbox-v2.php's
 * `case 'suggest_alternatives': case 'suggest-alternatives':` (lines ~807-833):
 *
 * ```php
 * case 'suggest_alternatives':
 * case 'suggest-alternatives':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $body = getJsonBody();
 *     $drugId = (int) ($_POST['drug_id'] ?? $body['drug_id'] ?? 0);
 *     $requestedDiscount = (float) ($_POST['discount'] ?? $body['discount'] ?? 0);
 *     if (!$drugId) { sendError('Drug ID is required'); }
 *     if ($requestedDiscount <= 0) { sendError('Discount amount must be greater than 0'); }
 *     $pricingEngine = loadService('DrugPricingEngineService', $db, $lineAccountId);
 *     if (!$pricingEngine) { sendError('Pricing engine service not available', 503); }
 *     $result = $pricingEngine->suggestAlternatives($drugId, $requestedDiscount);
 *     sendResponse(['success' => !isset($result['error']), 'data' => $result]);
 *     break;
 * ```
 *
 * Port of `DrugPricingEngineService::suggestAlternatives()` — see
 * `_lib/suggestAlternatives.ts`'s module doc for the full literal port
 * (including the cross-imported `getMaxDiscount()`).
 *
 * Body: `{ drug_id: number, discount: number }`. 400 `'Drug ID is
 * required'` when `drug_id` is falsy; 400 `'Discount amount must be
 * greater than 0'` when `discount <= 0`. A Route Handler has no `$_POST`
 * equivalent, so only the JSON body is read.
 *
 * STATUS IS ALWAYS 200 — even for the error-shaped payload (`getMaxDiscount()`
 * returned an `error`, e.g. drug not found) — PHP's `sendResponse()` call
 * here passes no explicit status code, so `success: false` still comes back
 * as HTTP 200. Same literal-parity quirk already documented on
 * `../max-discount/route.ts`; not "fixed" into a 404/400 here either.
 *
 * "Pricing engine service not available" 503 — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a) for why this Next port never fabricates that branch.
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

/** PHP's `(float) $v` — loose float cast, non-numeric -> 0. */
function floatval(value: unknown): number {
  if (typeof value === 'number') return value;
  if (typeof value === 'string') {
    const parsed = Number.parseFloat(value);
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

  const drugId = intval(body.drug_id ?? 0);
  const requestedDiscount = floatval(body.discount ?? 0);

  if (!drugId) {
    return NextResponse.json({ success: false, error: 'Drug ID is required' }, { status: 400 });
  }
  if (requestedDiscount <= 0) {
    return NextResponse.json({ success: false, error: 'Discount amount must be greater than 0' }, { status: 400 });
  }

  const { db } = auth.value;

  try {
    const result = await suggestAlternatives(db, drugId, requestedDiscount);
    return NextResponse.json({ success: !('error' in result), data: result });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
