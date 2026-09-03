import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getConsultationAnalytics } from './_lib/consultationAnalytics';
import { daysAgoInBangkok, todayInBangkok } from './_lib/bangkokTime';

/**
 * GET /api/inbox/actions/analytics — literal port of `api/inbox-v2.php`'s
 * `case 'analytics': case 'get_analytics': case 'consultation_analytics':`
 * (lines 1681-1786):
 *
 * ```php
 * case 'analytics':
 * case 'get_analytics':
 * case 'consultation_analytics':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *
 *     $pharmacistId = (int) ($_GET['pharmacist_id'] ?? $adminId ?? 0);
 *     $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
 *     $endDate = $_GET['end_date'] ?? date('Y-m-d');
 *
 *     try {
 *         // ... two SELECTs against consultation_analytics, see _lib/consultationAnalytics.ts ...
 *         sendResponse(['success' => true, 'data' => [
 *             'period' => ['startDate' => $startDate, 'endDate' => $endDate],
 *             'summary' => [...], 'byType' => $byType
 *         ]]);
 *     } catch (PDOException $e) {
 *         logInboxApiException($e, 'catch');
 *         error_log("Analytics query error: " . $e->getMessage());
 *         sendResponse(['success' => true, 'data' => [
 *             'period' => ['startDate' => $startDate, 'endDate' => $endDate],
 *             'summary' => [], 'byType' => [], 'message' => 'No analytics data available yet'
 *         ]]);
 *     }
 *     break;
 * ```
 *
 * Direct SQL against `consultation_analytics` — see `_lib/
 * consultationAnalytics.ts`'s module doc for the full literal port of both
 * queries and the arithmetic, INCLUDING why this action has **no
 * `loadService()` 503 gate at all** (ground-truth-verified against the PHP
 * source — this case body never instantiates a service class, unlike this
 * batch's `record-analytics` sibling).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * LOAD-BEARING: A DB QUERY FAILURE HERE IS STILL `success:true` AT HTTP 200
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `catch (PDOException $e)` does NOT turn into an error response — it
 * SOFT-DEGRADES to `{success:true, data:{period, summary:{}, byType:[],
 * message:'No analytics data available yet'}}`, still `sendResponse()`'d
 * with its default 200 status code (no explicit status argument). This is
 * preserved EXACTLY below: the `catch` block returns `success:true` at
 * (implicit) HTTP 200, never 400/500. `route.test.ts` asserts this directly
 * — do not "fix" this into an error response.
 *
 * This route's `catch` is BROAD (catches anything `getConsultationAnalytics()`
 * throws), not narrowly typed to a PDO-style exception — JS has no
 * exception-type hierarchy to narrow against. Documented explicitly per
 * this batch's brief as a deliberate, acceptable simplification (see
 * `_lib/consultationAnalytics.ts`'s own module doc for the full
 * "broad-catch-with-same-degrade-behavior" rationale) — not a silent
 * choice.
 *
 * Query params:
 *   - `pharmacist_id` (int, optional) — PHP's `(int) ($_GET['pharmacist_id']
 *     ?? $adminId ?? 0)`: when the key is PRESENT in the query string
 *     (even `?pharmacist_id=0`), that value wins; only when the key is
 *     ENTIRELY ABSENT does it fall back to `$adminId` (this codebase's
 *     established `session.adminUserId` convention — always a real number,
 *     never `null`, so the trailing `?? 0` PHP fallback is structurally
 *     unreachable here, same precedent as `../add-customer-note/route.ts`).
 *   - `start_date` / `end_date` (string, `Y-m-d`, optional) — PHP's `??` is
 *     isset()-based: an explicitly EMPTY query value (`?start_date=`) is
 *     still "present" and is NOT replaced by the default (mirrored
 *     correctly by `URLSearchParams.get()`, which returns `''` for a
 *     present-but-empty key and `null` only when the key is absent, exactly
 *     matching PHP's `isset()` semantics). Defaults: `start_date` ->
 *     30-days-ago in Asia/Bangkok, `end_date` -> today in Asia/Bangkok (see
 *     `_lib/bangkokTime.ts` — CLAUDE.md: never rely on server-local
 *     timezone).
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

  const { db, session } = auth.value;
  const params = request.nextUrl.searchParams;

  // PHP `(int) ($_GET['pharmacist_id'] ?? $adminId ?? 0)` — isset()-based:
  // present (even '0') wins; absent falls back to session.adminUserId.
  const pharmacistId = params.has('pharmacist_id') ? toIntParam(params.get('pharmacist_id') ?? '') : session.adminUserId;

  const startDate = params.get('start_date') ?? daysAgoInBangkok(30);
  const endDate = params.get('end_date') ?? todayInBangkok();

  try {
    const { summary, byType } = await getConsultationAnalytics(db, startDate, endDate, pharmacistId);
    return NextResponse.json({
      success: true,
      data: { period: { startDate, endDate }, summary, byType },
    });
  } catch (error) {
    // Broad catch, soft-degrade to success:true at 200 — see module doc.
    console.error('Analytics query error:', error instanceof Error ? error.message : error);
    return NextResponse.json({
      success: true,
      data: {
        period: { startDate, endDate },
        summary: {},
        byType: [],
        message: 'No analytics data available yet',
      },
    });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
