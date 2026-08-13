import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getDrugPricingInline } from './_lib/drugPricing';

/**
 * GET /api/inbox/actions/drug-pricing — port of api/inbox-v2.php's
 * `case 'drug_pricing': case 'drug-pricing': case 'calculate_margin':`
 * (lines ~705-770). See `_lib/drugPricing.ts`'s module doc for the full
 * literal PHP source and the `SHOW COLUMNS` simplification note.
 *
 * THIS IS THE INLINE-SQL ACTION — distinct from `drug-pricing-data`
 * (`PharmacyIntegrationService::getDrugPricing()`, a different query shape
 * and response envelope) and from `max-discount` (which calls
 * `DrugPricingEngineService::calculateMargin()`, not this action's own
 * inline margin math).
 *
 * Query params: `drug_id` or `id` (int, required — 400 `'Drug ID is
 * required'` when both are absent/zero). 404 `'Drug not found'` when no
 * matching row. 500 `'Database error: {message}'` on any other DB failure.
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
    const data = await getDrugPricingInline(db, drugId);
    if (!data) {
      return NextResponse.json({ success: false, error: 'Drug not found' }, { status: 404 });
    }
    return NextResponse.json({ success: true, data });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
