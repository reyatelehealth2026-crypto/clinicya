import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { checkUserAllergy } from './_lib/checkAllergy';

/**
 * GET /api/inbox/actions/check-allergy — port of api/inbox-v2.php's
 * `case 'check_allergy': case 'check-allergy':` (lines ~1088-1120):
 *
 * ```php
 * case 'check_allergy':
 * case 'check-allergy':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     $drugName = $_GET['drug_name'] ?? $_GET['drug'] ?? '';
 *     if (!$userId) { sendError('User ID is required'); }
 *     if (empty($drugName)) { sendError('Drug name is required'); }
 *     $integration = loadService('PharmacyIntegrationService', $db, $lineAccountId);
 *     if (!$integration) { sendError('Integration service not available', 503); }
 *     $result = $integration->checkUserAllergy($userId, $drugName);
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * Port of `PharmacyIntegrationService::checkUserAllergy()` — see
 * `_lib/checkAllergy.ts`'s module doc for the full literal port (it calls
 * the cross-imported `getUserMedicalHistory`, so this action transitively
 * benefits from schema-drift fixes (A)/(B) documented on
 * `../medical-history/_lib/medicalHistory.ts`).
 *
 * Query params: `user_id` (int, required, 400 `'Invalid user ID'` if
 * `<= 0`); `drug_name` OR `drug` (string — `??` is isset()-based: the first
 * PRESENT key wins, even if its value is `''`; required, else 400
 * `'Drug name is required'`).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `empty($drugName)` — PHP's `empty()` treats the STRING `'0'` as empty too
 * ═══════════════════════════════════════════════════════════════════════
 * `empty('0')` is `true` in PHP (alongside `''`, `null`, `0`) — so a literal
 * `drug_name=0` (or `drug=0`) query string is rejected as "Drug name is
 * required", not passed through as a one-character drug name. Ported
 * exactly via `drugName === '' || drugName === '0'`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE SECOND, TEXTUALLY-UNREACHABLE `!$userId` CHECK IS NOT PORTED
 * ═══════════════════════════════════════════════════════════════════════
 * Same reasoning as `../medical-history/route.ts`'s doc — only the
 * reachable `'Invalid user ID'` 400 is ported.
 *
 * `success: true` is UNCONDITIONAL here (unlike `medical-history`/
 * `patient-profile`, which derive `success` from `result.found`) —
 * `checkUserAllergy()` never throws (it delegates to `getUserMedicalHistory()`,
 * which swallows its own DB errors — see `../medical-history/_lib/medicalHistory.ts`'s
 * module doc), so the `try/catch` below is a defensive addition for
 * genuinely unexpected errors. `case 'check_allergy':` has no case-level
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

/** PHP `(int) $v` for a query-string value — leading numeric parse, else 0. */
function toIntParam(value: string): number {
  const n = Number.parseInt(value, 10);
  return Number.isFinite(n) ? n : 0;
}

/**
 * PHP `$_GET['drug_name'] ?? $_GET['drug'] ?? ''` — `??` is an `isset()`
 * check, not a truthiness check: the first key that is PRESENT in the query
 * string wins, even if its value is `''`.
 */
function firstSetStringParam(params: URLSearchParams, keys: readonly string[]): string {
  for (const key of keys) {
    if (params.has(key)) {
      return params.get(key) ?? '';
    }
  }
  return '';
}

/** PHP `empty($v)` for a string value already known to be a `string` — true for `''` and the exact string `'0'`. */
function isPhpEmptyString(value: string): boolean {
  return value === '' || value === '0';
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

  const drugName = firstSetStringParam(params, ['drug_name', 'drug']);
  if (isPhpEmptyString(drugName)) {
    return NextResponse.json({ success: false, error: 'Drug name is required' }, { status: 400 });
  }

  const { db } = auth.value;

  try {
    const result = await checkUserAllergy(db, userId, drugName);
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
