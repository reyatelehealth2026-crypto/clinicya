import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getComprehensivePatientProfile } from './_lib/patientProfile';

/**
 * GET /api/inbox/actions/patient-profile — port of api/inbox-v2.php's
 * `case 'patient_profile': case 'patient-profile': case 'get_patient_profile':`
 * (lines ~952-980):
 *
 * ```php
 * case 'patient_profile':
 * case 'patient-profile':
 * case 'get_patient_profile':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     if (!$userId) { sendError('User ID is required'); }
 *     $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);
 *     if (!$integration) { sendError('Integration service not available', 503); }
 *     $result = $integration->getComprehensivePatientProfile($userId);
 *     sendResponse(['success' => $result['found'] ?? false, 'data' => $result]);
 *     break;
 * ```
 *
 * Port of `PharmacyIntegrationService::getComprehensivePatientProfile()` —
 * see `_lib/patientProfile.ts`'s module doc for the full literal port,
 * including its private dependencies (`getUserTagsAndNotes()`,
 * `checkDrugInteractions()`/`findInteraction()`/severity helpers,
 * `generatePatientWarnings()`) and the CONFIRMED SCHEMA-DRIFT FIX (C) it
 * applies (`customer_notes.note_type` dropped — does not exist anywhere in
 * this codebase's committed schema). This action transitively also
 * benefits from fixes (A)/(B) (via the cross-imported
 * `getUserMedicalHistory`) and (D) (via the cross-imported
 * `getUserPrescriptionHistory`).
 *
 * Query param: `user_id` (int, required).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE SECOND, TEXTUALLY-UNREACHABLE `!$userId` CHECK IS NOT PORTED
 * ═══════════════════════════════════════════════════════════════════════
 * Same reasoning as `../medical-history/route.ts`'s doc — only the
 * reachable `'Invalid user ID'` 400 is ported.
 *
 * STATUS IS ALWAYS 200 — even for `found: false` — PHP's `sendResponse()`
 * call here passes NO explicit status-code argument, and none of
 * `getComprehensivePatientProfile()`'s internal calls throw (each swallows
 * its own DB errors, per `_lib/patientProfile.ts`'s module doc), so the
 * `try/catch` below is a defensive addition for genuinely unexpected
 * errors. `case 'patient_profile':` has no case-level try/catch of its own;
 * following the house precedent set by Phase 4 batch 4a's
 * `low-stock-drugs`/`drug-inventory`, this defensive branch uses the
 * uniform `'Database error: {message}'` shape for consistency across the
 * `api/inbox/actions/*` family, though it is not expected to be exercised
 * by real traffic.
 *
 * "Integration service not available" 503 — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a) for why this Next port never fabricates that branch (static import
 * vs PHP's runtime `file_exists()`/`class_exists()` probe).
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP `(int) $v` for a query-string value — leading numeric parse, else 0. */
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
  const userId = toIntParam(params.get('user_id') ?? '');
  if (userId <= 0) {
    return NextResponse.json({ success: false, error: 'Invalid user ID' }, { status: 400 });
  }

  const { db } = auth.value;

  try {
    const result = await getComprehensivePatientProfile(db, userId);
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
