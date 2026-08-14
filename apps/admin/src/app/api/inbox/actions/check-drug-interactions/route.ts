import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { checkInteractions } from './_lib/checkDrugInteractions';

/**
 * POST /api/inbox/actions/check-drug-interactions — port of
 * api/inbox-v2.php's `case 'check_drug_interactions': case
 * 'check-drug-interactions':` (lines ~1363-1401):
 *
 * ```php
 * case 'check_drug_interactions':
 * case 'check-drug-interactions':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     $body = getJsonBody();
 *     $userId = (int) ($_POST['user_id'] ?? $body['user_id'] ?? 0);
 *     $drugIds = $_POST['drug_ids'] ?? $body['drug_ids'] ?? [];
 *     if (!$userId) { sendError('User ID is required'); }
 *     if (empty($drugIds)) { sendError('Drug IDs array is required'); }
 *     if (is_string($drugIds)) {
 *         $drugIds = json_decode($drugIds, true) ?? array_map('intval', explode(',', $drugIds));
 *     }
 *     $recommendEngine = loadService('DrugRecommendEngineService', $db, $lineAccountId);
 *     if (!$recommendEngine) { sendError('Recommendation engine service not available', 503); }
 *     $healthEngine = loadService('CustomerHealthEngineService', $db, $lineAccountId);
 *     if ($healthEngine) { $recommendEngine->setHealthEngine($healthEngine); }
 *     $result = $recommendEngine->checkInteractions($drugIds, $userId);
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * Port of `DrugRecommendEngineService::checkInteractions()` — see
 * `_lib/checkDrugInteractions.ts`'s module doc for the full literal port
 * (its OWN, independent `findInteraction()` algorithm — do not conflate
 * with `../check-interactions`'s `PharmacyIntegrationService` variant) and
 * why `setHealthEngine()` is always treated as applied (routes straight
 * through `./_lib/customerHealthEngine.ts`).
 *
 * GUARD ORDER DIFFERS FROM `check-interactions`: `user_id` is validated
 * FIRST here (400 `'User ID is required'` when falsy), THEN `drug_ids`
 * emptiness (400 `'Drug IDs array is required'`) — verified against the
 * literal PHP order above (`check-interactions` never gates on `user_id`
 * at all, only on `drugs`).
 *
 * Body: `{ user_id: number, drug_ids: number[] | string }` — `drug_ids` as
 * a string is JSON-decoded, falling back to `array_map('intval',
 * explode(',', ...))` on decode failure.
 *
 * "Recommendation engine service not available" 503 — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a) for why this Next port never fabricates that branch.
 *
 * `success: true` is UNCONDITIONAL — `checkInteractions()` never throws
 * (`findInteraction()`/`getDrugNames()` swallow their own DB errors), so
 * the `try/catch` below is a defensive addition using the house
 * `'Database error: {message}'` 500 shape.
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

/** PHP `empty($v)` on a raw JSON-body value — see `../check-interactions/_lib/checkInteractions.ts`'s `isPhpEmpty()` for the full semantics this mirrors. */
function isPhpEmpty(value: unknown): boolean {
  if (Array.isArray(value)) return value.length === 0;
  if (typeof value === 'string') return value === '' || value === '0';
  if (typeof value === 'number') return value === 0;
  if (typeof value === 'boolean') return value === false;
  return value === null || value === undefined;
}

/**
 * `is_string($drugIds) ? (json_decode($drugIds, true) ?? array_map('intval', explode(',', $drugIds))) : $drugIds`.
 * The array branch (the typical JSON-body shape, `"drug_ids": [1,2,3]`) is
 * NOT `intval()`-mapped by PHP either — only the CSV-string fallback is.
 */
function parseDrugIdsValue(value: unknown): number[] {
  if (Array.isArray(value)) {
    return value.map((v) => (typeof v === 'number' ? v : Number(v)));
  }
  if (typeof value !== 'string') {
    return [];
  }
  try {
    const decoded: unknown = JSON.parse(value);
    if (decoded !== null) {
      if (Array.isArray(decoded)) return decoded.map((v) => (typeof v === 'number' ? v : Number(v)));
      return [Number(decoded)];
    }
  } catch {
    // json_decode() returning null on invalid JSON — fall through to explode(',', ...).
  }
  return value.split(',').map((v) => intval(v));
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
  const rawDrugIds = body.drug_ids ?? [];

  if (!userId) {
    return NextResponse.json({ success: false, error: 'User ID is required' }, { status: 400 });
  }
  if (isPhpEmpty(rawDrugIds)) {
    return NextResponse.json({ success: false, error: 'Drug IDs array is required' }, { status: 400 });
  }

  const drugIds = parseDrugIdsValue(rawDrugIds);

  const { db } = auth.value;

  try {
    const result = await checkInteractions(db, drugIds, userId);
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
