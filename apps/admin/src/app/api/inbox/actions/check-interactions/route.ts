import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { checkDrugInteractions, parseCheckInteractionsBody } from './_lib/checkInteractions';

/**
 * POST /api/inbox/actions/check-interactions — port of api/inbox-v2.php's
 * `case 'check_interactions': case 'check-interactions':` (lines ~881-906).
 * See `_lib/checkInteractions.ts`'s module doc for the full literal PHP
 * source and the JSON-body parsing rules.
 *
 * This route is a thin params-parsing wrapper only — the actual
 * `PharmacyIntegrationService::checkDrugInteractions()` port lives at
 * `../patient-profile/_lib/patientProfile.ts` (already merged, Phase 4
 * batch 4b) and is imported directly, per this batch's brief.
 *
 * Body: `{ drugs: string[] | string, user_id?: number }`. 400
 * `'Drug names array is required'` when `drugs` is PHP-`empty()` (an
 * absent/empty array, or the string `''`/`'0'`).
 *
 * `success: true` is UNCONDITIONAL — `checkDrugInteractions()` never
 * throws (its own `findInteraction()` swallows DB errors — see that
 * module's doc), so the `try/catch` below is a defensive addition for
 * genuinely unexpected errors, following the uniform house
 * `'Database error: {message}'` 500 shape used across this Route Handler
 * family.
 *
 * "Integration service not available" 503 — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a) for why this Next port never fabricates that branch (static import
 * vs PHP's runtime `file_exists()`/`class_exists()` probe).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

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

  const parsed = parseCheckInteractionsBody(body);
  if (!parsed.ok) {
    return NextResponse.json({ success: false, error: parsed.error }, { status: 400 });
  }

  const { db } = auth.value;

  try {
    const result = await checkDrugInteractions(db, parsed.value.drugNames, parsed.value.userId);
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
