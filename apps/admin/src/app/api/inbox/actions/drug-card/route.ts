import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { generateDrugCard } from './_lib/drugCard';

/**
 * GET /api/inbox/actions/drug-card — port of api/inbox-v2.php's
 * `case 'drug_card': case 'drug-card': case 'generate_drug_card':`
 * (lines ~1447-1475):
 *
 * ```php
 * case 'drug_card':
 * case 'drug-card':
 * case 'generate_drug_card':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $drugId = (int) ($_GET['drug_id'] ?? $_GET['id'] ?? 0);
 *     if (!$drugId) { sendError('Drug ID is required'); }
 *     $recommendEngine = loadService('DrugRecommendEngineService', $db, $lineAccountId);
 *     if (!$recommendEngine) { sendError('Recommendation engine service not available', 503); }
 *     $result = $recommendEngine->generateDrugCard($drugId);
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * Port of `DrugRecommendEngineService::generateDrugCard()` — see
 * `_lib/drugCard.ts`'s module doc for the full literal port (pure LINE
 * Flex-JSON assembly, no DB writes; every icon/emoji/Thai label/hex
 * color/button action string must match byte-for-byte).
 *
 * Query param: `drug_id` or `id` (int, required — 400 `'Drug ID is
 * required'` when both are absent/zero).
 *
 * "Recommendation engine service not available" 503 — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a) for why this Next port never fabricates that branch.
 *
 * `success: true` is UNCONDITIONAL — `generateDrugCard()` never throws
 * (`getDrugDetails()` swallows its own DB errors, returning `null`, which
 * produces the "not found" bubble rather than an exception), so the
 * `try/catch` below is a defensive addition using the house `'Database
 * error: {message}'` 500 shape.
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
  const drugId = firstSetIntParam(params, ['drug_id', 'id']);
  if (!drugId) {
    return NextResponse.json({ success: false, error: 'Drug ID is required' }, { status: 400 });
  }

  const { db } = auth.value;

  try {
    const result = await generateDrugCard(db, drugId);
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
