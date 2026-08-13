import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getDrugInfoById, getDrugInfoByName } from './_lib/drugInfo';
import { calculateMargin } from '../max-discount/_lib/drugPricingEngine';

/**
 * GET /api/inbox/actions/drug-info — port of api/inbox-v2.php's
 * `case 'drug_info': case 'drug-info': case 'get_drug_info':`
 * (lines ~518-612). See `_lib/drugInfo.ts`'s module doc for the full
 * literal PHP source, the row-shaping logic, and the `isPrescription`
 * schema-drift fix (`requires_prescription`, not the nonexistent
 * `is_prescription` PHP silently always reads as `false` via `bi.*`).
 *
 * Query params: `drug_id` or `id` (int), `name` (string) — at least one
 * required, else 400 `'Drug ID or name is required'`. When `drugId` is
 * given (`??`/isset()-based: `drug_id` wins over `id` when both are
 * present, even if `drug_id`'s value casts to `0`), looks up by id;
 * otherwise looks up by `name` (`LIKE %name%` against `name` OR `sku`,
 * scoped to `line_account_id`). 404 `'Drug not found'` if no row.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `pricing` — a separate `calculateMargin()` call, its OWN try/catch
 * ═══════════════════════════════════════════════════════════════════════
 * PHP (lines 561-573):
 * ```php
 * $pricingEngine = loadService('DrugPricingEngineService', $db, $lineAccountId);
 * $pricing = null;
 * if ($pricingEngine) {
 *     try {
 *         $pricing = $pricingEngine->calculateMargin((int) $drug['id']);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         $pricing = null; // Pricing calculation failed, continue without it
 *     }
 * }
 * ```
 * `calculateMargin` is imported directly from
 * `../max-discount/_lib/drugPricingEngine` — a deliberate, documented
 * single-owner cross-route import (both `drug-info/**` and
 * `max-discount/**` belong to this same builder stream this round; see
 * that module's own doc). On failure, `pricing` stays `null` and the
 * OUTER request still succeeds — this mirrors PHP's swallow-and-continue
 * exactly: a pricing-engine failure never turns the whole `drug_info`
 * response into an error.
 *
 * "DrugPricingEngineService not available" — PHP's `if ($pricingEngine)`
 * guard (no `sendError()` call, just skips setting `$pricing`) has no
 * equivalent gap in this port: `calculateMargin` is a static import, always
 * present. Same "structurally unreachable in Next" reasoning as
 * `max-discount`'s own 503 note, just without an error status attached
 * here since PHP itself never errors on this branch either — it silently
 * leaves `pricing: null`, which can only happen here via the try/catch.
 *
 * 500 `'Database error: {message}'` on any other DB failure — matching
 * `case 'drug_info':`'s own case-level `catch (PDOException $e)`.
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
  const drugName = params.get('name') ?? '';

  if (!drugId && !drugName) {
    return NextResponse.json({ success: false, error: 'Drug ID or name is required' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    const drug = drugId ? await getDrugInfoById(db, drugId) : await getDrugInfoByName(db, drugName, lineAccountId);

    if (!drug) {
      return NextResponse.json({ success: false, error: 'Drug not found' }, { status: 404 });
    }

    let pricing: Awaited<ReturnType<typeof calculateMargin>> | null = null;
    try {
      pricing = await calculateMargin(db, drug.id);
    } catch {
      pricing = null;
    }

    return NextResponse.json({ success: true, data: { ...drug, pricing } });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
