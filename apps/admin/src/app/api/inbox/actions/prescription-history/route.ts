import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getUserPrescriptionHistory } from './_lib/prescriptionHistory';

/**
 * GET /api/inbox/actions/prescription-history — port of api/inbox-v2.php's
 * `case 'prescription_history': case 'prescription-history':`
 * (lines ~1126-1155):
 *
 * ```php
 * case 'prescription_history':
 * case 'prescription-history':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     $limit = (int) ($_GET['limit'] ?? 20);
 *     if (!$userId) { sendError('User ID is required'); }
 *     $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);
 *     if (!$integration) { sendError('Integration service not available', 503); }
 *     $result = $integration->getUserPrescriptionHistory($userId, $limit);
 *     sendResponse(['success' => true, 'data' => $result, 'count' => count($result)]);
 *     break;
 * ```
 *
 * Port of `PharmacyIntegrationService::getUserPrescriptionHistory()` — see
 * `_lib/prescriptionHistory.ts`'s module doc for the full literal port and
 * the CONFIRMED SCHEMA-DRIFT FIX (D) it applies (`is_prescription` ->
 * `requires_prescription`, in BOTH the SELECT list and the WHERE clause).
 * Before this fix, this action ALWAYS returned `{success: true, data: [],
 * count: 0}` in production.
 *
 * Query params: `user_id` (int, required), `limit` (int, default 20, no
 * lower/upper clamp — matches PHP's own unbounded `(int) ($_GET['limit'] ?? 20)`).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE SECOND, TEXTUALLY-UNREACHABLE `!$userId` CHECK IS NOT PORTED
 * ═══════════════════════════════════════════════════════════════════════
 * Same reasoning as `../medical-history/route.ts`'s doc: `if ($userId <= 0)
 * { sendError('Invalid user ID'); }` already exits for every value that
 * would make the later `if (!$userId) { sendError('User ID is required'); }`
 * true — only the reachable `'Invalid user ID'` 400 is ported. (PHP reads
 * `limit` from `$_GET` AFTER the `user_id <= 0` check but BEFORE the
 * unreachable `!$userId` check — this ordering has no observable effect
 * since `limit` is read unconditionally either way, so this port simply
 * reads both query params up front.)
 *
 * `success: true` is UNCONDITIONAL here (unlike `medical-history`/
 * `patient-profile`, which derive `success` from `result.found`) —
 * `getUserPrescriptionHistory()` never throws (its own `catch (PDOException
 * $e)` swallows DB errors into an empty array — see
 * `_lib/prescriptionHistory.ts`'s module doc), so the `try/catch` below is
 * a defensive addition for genuinely unexpected errors outside that
 * function's own DB call. `case 'prescription_history':` has no case-level
 * try/catch of its own; following the house precedent set by Phase 4 batch
 * 4a's `low-stock-drugs`/`drug-inventory`, this defensive branch uses the
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

/** PHP `(int) $v` for a query-string value — leading numeric parse, else 0 (used for `?? 20`'s fallback default, so absence is handled by the caller, not this cast). */
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
  // `(int) ($_GET['limit'] ?? 20)` — `??` is isset()-based: an absent `limit` -> 20, a present-but-empty/non-numeric `limit` -> (int) cast of it (0).
  const limit = params.has('limit') ? toIntParam(params.get('limit') ?? '') : 20;

  const { db } = auth.value;

  try {
    const data = await getUserPrescriptionHistory(db, userId, limit);
    return NextResponse.json({ success: true, data, count: data.length });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Database error: ${message}` }, { status: 500 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
