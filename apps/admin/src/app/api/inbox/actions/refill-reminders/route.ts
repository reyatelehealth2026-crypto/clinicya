import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getRefillReminders } from './_lib/refillReminders';

/**
 * GET /api/inbox/actions/refill-reminders — port of api/inbox-v2.php's
 * `case 'refill_reminders': case 'refill-reminders': case 'get_refill_reminders':`
 * (lines ~1410-1438):
 *
 * ```php
 * case 'refill_reminders':
 * case 'refill-reminders':
 * case 'get_refill_reminders':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     $userId = (int) ($_GET['user_id'] ?? 0);
 *     if ($userId <= 0) { sendError('Invalid user ID'); }
 *     if (!$userId) { sendError('User ID is required'); }
 *     $recommendEngine = loadService('DrugRecommendEngineService', $db, $lineAccountId);
 *     if (!$recommendEngine) { sendError('Recommendation engine service not available', 503); }
 *     $result = $recommendEngine->getRefillReminders($userId);
 *     sendResponse(['success' => true, 'data' => $result]);
 *     break;
 * ```
 *
 * Port of `DrugRecommendEngineService::getRefillReminders()` — see
 * `_lib/refillReminders.ts`'s module doc for the full literal port,
 * including the day-count semantics and the deliberately-preserved no-op
 * PDOException fallback. No schema-drift fixes apply to this action (per
 * this batch's confirmed scoping correction, every column its query
 * touches already exists — this is NOT backed by
 * `PharmacyIntegrationService`, unlike the other four actions in this
 * batch).
 *
 * Query param: `user_id` (int, required).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * THE SECOND, TEXTUALLY-UNREACHABLE `!$userId` CHECK IS NOT PORTED
 * ═══════════════════════════════════════════════════════════════════════
 * Same reasoning as `../medical-history/route.ts`'s doc — only the
 * reachable `'Invalid user ID'` 400 is ported.
 *
 * `success: true` is UNCONDITIONAL — `getRefillReminders()` never throws
 * (its own `catch (PDOException $e)` swallows DB errors into an empty
 * `reminders` array — see `_lib/refillReminders.ts`'s module doc), so the
 * `try/catch` below is a defensive addition for genuinely unexpected
 * errors. `case 'refill_reminders':` has no case-level try/catch of its
 * own; following the house precedent set by Phase 4 batch 4a's
 * `low-stock-drugs`/`drug-inventory`, this defensive branch uses the
 * uniform `'Database error: {message}'` shape for consistency across the
 * `api/inbox/actions/*` family, though it is not expected to be exercised
 * by real traffic.
 *
 * RESPONSE SHAPE: `{success, data}` ONLY — unlike `prescription-history`/
 * `../low-stock-drugs` (Phase 4 batch 4a), this action's PHP `sendResponse()`
 * call has NO `'count' => count($result)` key; `$result` itself is already
 * the `{reminders, userId, totalDue}` object (not a bare array), so a
 * top-level `count` would not even make sense here. Verified directly
 * against the PHP case block above.
 *
 * "Recommendation engine service not available" 503 — NOT "Integration
 * service not available": `DrugRecommendEngineService` (not
 * `PharmacyIntegrationService`) backs this one action in the batch, with
 * its own distinct `loadService()` guard and error message (see this
 * batch's brief's scoping correction). Same "static import, no runtime
 * file_exists() probe" reasoning as
 * `../max-discount/_lib/drugPricingEngine.ts`'s module doc (Phase 4 batch
 * 4a) for why this Next port never fabricates that branch.
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
    const result = await getRefillReminders(db, userId);
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
