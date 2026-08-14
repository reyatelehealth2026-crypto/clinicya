import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getUserMedicalHistory } from './_lib/medicalHistory';

/**
 * GET /api/inbox/actions/medical-history — port of api/inbox-v2.php's
 * `case 'medical_history': case 'medical-history': case 'get_medical_history':`
 * (lines ~918-946):
 *
 * ```php
 * case 'medical_history':
 * case 'medical-history':
 * case 'get_medical_history':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     if (!$userId) { sendError('User ID is required'); }
 *     $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);
 *     if (!$integration) { sendError('Integration service not available', 503); }
 *     $result = $integration->getUserMedicalHistory($userId);
 *     sendResponse(['success' => $result['found'] ?? false, 'data' => $result]);
 *     break;
 * ```
 *
 * Port of `PharmacyIntegrationService::getUserMedicalHistory()` — see
 * `_lib/medicalHistory.ts`'s module doc for the full literal port and the
 * TWO CONFIRMED SCHEMA-DRIFT FIXES it applies: (A) `birth_date` -> the real
 * `birthday` column, (B) `chronic_diseases` dropped from the SELECT (does
 * not exist on tenant DBs). Before these fixes, this action ALWAYS returned
 * `{success: false, data: {found: false, error: "..."}}}` in production.
 *
 * Query param: `user_id` (int, required).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE SECOND, TEXTUALLY-UNREACHABLE `!$userId` CHECK IS NOT PORTED
 * ═══════════════════════════════════════════════════════════════════════
 * PHP has `if ($userId <= 0) { sendError('Invalid user ID'); }` immediately
 * followed by `if (!$userId) { sendError('User ID is required'); }`. Every
 * value of `$userId` that would make `!$userId` true (0, since `$userId` is
 * always an `(int)` cast) is ALREADY `<= 0` and would have exited via the
 * first check — the second `sendError('User ID is required')` can never
 * execute. Only the reachable `'Invalid user ID'` 400 is ported.
 *
 * STATUS IS ALWAYS 200 — even for `found: false` — PHP's `sendResponse()`
 * call here passes NO explicit status-code argument. `getUserMedicalHistory()`
 * never throws (its own `catch (PDOException $e)` swallows DB errors into
 * the return value — see `_lib/medicalHistory.ts`'s module doc), so the
 * `try/catch` below is a defensive addition for genuinely unexpected errors
 * outside that function's own DB call. `case 'medical_history':` has no
 * case-level try/catch of its own (unlike `drug_info`'s) — an escaping
 * error would otherwise reach api/inbox-v2.php's generic outer
 * `catch (Throwable $e)` and a DIFFERENT 500 shape
 * (`'Internal server error: ...'`) than this route ever produces. Following
 * the house precedent already set by Phase 4 batch 4a's `low-stock-drugs`/
 * `drug-inventory` (both in the identical no-case-catch situation), this
 * defensive branch still uses the uniform `'Database error: {message}'`
 * shape for consistency across the whole `api/inbox/actions/*` family, even
 * though it is not expected to be exercised by real traffic.
 *
 * "Integration service not available" 503 — PHP's `loadService()` guard
 * does a runtime `file_exists()`/`class_exists()` probe with no Next
 * analogue (a static TypeScript import either compiles and is present in
 * the bundle, or the build fails outright) — see
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a) for the full reasoning. This port does NOT fabricate a runtime 503
 * branch for "service unavailable" — that PHP state has no Next analogue,
 * so it is documented here, not implemented.
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
    const result = await getUserMedicalHistory(db, userId);
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
