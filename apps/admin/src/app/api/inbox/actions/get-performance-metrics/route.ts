import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { getAllMetricStats } from './_lib/getPerformanceMetrics';

/**
 * GET /api/inbox/actions/get-performance-metrics — literal port of
 * `api/inbox-v2.php`'s `case 'getPerformanceMetrics': case
 * 'get_performance_metrics':` (lines 2910-2954):
 *
 * ```php
 * case 'getPerformanceMetrics':
 * case 'get_performance_metrics':
 *     if ($method !== 'GET') { sendError('Method not allowed', 405); }
 *     try {
 *         $startDate = $_GET['start_date'] ?? null;
 *         $endDate = $_GET['end_date'] ?? null;
 *
 *         require_once __DIR__ . '/../classes/PerformanceMetricsService.php';
 *         $perfService = new PerformanceMetricsService($db, $lineAccountId);
 *
 *         $stats = $perfService->getAllMetricStats($startDate, $endDate);
 *
 *         $thresholds = ['page_load' => 2000, 'conversation_switch' => 1000, 'message_render' => 200, 'api_call' => 500];
 *         foreach ($stats as $type => $data) {
 *             if (isset($thresholds[$type])) {
 *                 $stats[$type]['error_rate'] = $perfService->getErrorRate($type, $thresholds[$type], $startDate, $endDate);
 *             }
 *         }
 *
 *         sendResponse(['success' => true, 'data' => $stats]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to get performance metrics: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * NO `loadService()` 503 GATE — same reason as `../log-performance-metric`
 * ═══════════════════════════════════════════════════════════════════════
 * `PerformanceMetricsService` is `require_once`'d and `new`'d directly, no
 * `file_exists()`/`class_exists()` probe, no 503 branch anywhere in the PHP
 * source. Not fabricated here — `route.test.ts` asserts the ABSENCE of a
 * 503 branch, same shape of assertion as
 * `../log-performance-metric/route.test.ts` and `../analytics/route.test.ts`.
 *
 * `startDate`/`endDate` — PHP's `$_GET['start_date'] ?? null` /
 * `$_GET['end_date'] ?? null`: BOTH optional/nullable, NO DEFAULTING at
 * all (unlike `../analytics/route.ts`, which defaults to a 30-day
 * Asia/Bangkok window) — when absent, `startDate`/`endDate` stay `null`,
 * and `_lib/getPerformanceMetrics.ts`'s date-range `WHERE` clauses are
 * skipped entirely for the absent side (see that file's own
 * `dateRangeClause()`). `URLSearchParams.get()` returning `null` for an
 * absent key already matches PHP's `??`/`isset()` semantics exactly — no
 * `?? someDefault(...)` is ever applied here.
 *
 * `getAllMetricStats()` + the `$thresholds` error-rate-augmentation loop
 * are combined into ONE `_lib/getPerformanceMetrics.ts` function
 * (`getAllMetricStats()` there) since both always run together for this
 * one action — see that module's own doc for the full literal port,
 * including the TWO DIFFERENT metric-type lists and TWO DIFFERENT
 * threshold maps involved (do not confuse `getAllMetricStats()`'s own
 * 5-type list, which excludes `cache_hit`/`cache_miss`, with
 * `logMetric()`'s 7-value whitelist; do not confuse the case body's own
 * 4-entry `$thresholds` map, which excludes `scroll_performance`, with
 * `checkPerformanceThreshold()`'s own 5-entry warning-threshold map in
 * `../log-performance-metric/_lib/logPerformanceMetric.ts`).
 *
 * This case block's PHP `try/catch` wraps the ENTIRE body (both the stats
 * fetch and the error-rate loop) — but every query inside
 * `_lib/getPerformanceMetrics.ts` already has its OWN independent
 * `try/catch` that degrades to a fallback shape rather than throwing (see
 * that module's own doc). This outer `try/catch` below is therefore, in
 * practice, only reachable via a genuinely unexpected non-DB error — ported
 * the same way PHP's own outer `catch (Exception $e)` is: `{success:false,
 * error:'Failed to get performance metrics: ...'}` at (PHP's `sendError()`
 * default) HTTP 400.
 *
 * `lineAccountId` resolves as `session.currentBotId ?? 1` — the established
 * 2-tier convention across this whole `api/inbox/actions/*` family.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

export async function GET(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  try {
    // PHP reads $_GET['start_date']/$_GET['end_date'] INSIDE its own try block
    // (line 2917-2918) — mirrored here rather than reading searchParams earlier.
    const params = request.nextUrl.searchParams;
    const startDate = params.get('start_date'); // null when absent — no defaulting (unlike ../analytics).
    const endDate = params.get('end_date');

    const stats = await getAllMetricStats(db, lineAccountId, startDate, endDate);
    return NextResponse.json({ success: true, data: stats });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to get performance metrics: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function POST(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
