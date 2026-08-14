import { NextResponse, type NextRequest } from 'next/server';
import { resolveInboxApiContext } from './_lib/session';
import { logMetric } from './_lib/logPerformanceMetric';

/**
 * POST /api/inbox/actions/log-performance-metric — literal port of
 * `api/inbox-v2.php`'s `case 'logPerformanceMetric': case
 * 'log_performance_metric':` (lines 2845-2904):
 *
 * ```php
 * case 'logPerformanceMetric':
 * case 'log_performance_metric':
 *     if ($method !== 'POST') { sendError('Method not allowed', 405); }
 *     try {
 *         $input = json_decode(file_get_contents('php://input'), true);
 *         if (!$input) { sendError('Invalid JSON input'); }
 *         $metrics = isset($input['metrics']) ? $input['metrics'] : [$input];
 *         require_once __DIR__ . '/../classes/PerformanceMetricsService.php';
 *         $perfService = new PerformanceMetricsService($db, $lineAccountId);
 *         $successCount = 0; $failCount = 0;
 *         foreach ($metrics as $metric) {
 *             $metricType = $metric['metric_type'] ?? null;
 *             $durationMs = $metric['duration_ms'] ?? null;
 *             $userAgent = $metric['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null;
 *             $operationDetails = $metric['operation_details'] ?? null;
 *             if (!$metricType || $durationMs === null) { $failCount++; continue; }
 *             $result = $perfService->logMetric($metricType, $durationMs, $userAgent, $operationDetails);
 *             if ($result) { $successCount++; } else { $failCount++; }
 *         }
 *         sendResponse([
 *             'success' => true,
 *             'message' => "Logged {$successCount} metrics" . ($failCount > 0 ? ", {$failCount} failed" : ''),
 *             'logged' => $successCount,
 *             'failed' => $failCount
 *         ]);
 *     } catch (Exception $e) {
 *         logInboxApiException($e, 'catch');
 *         sendError('Failed to log performance metrics: ' . $e->getMessage());
 *     }
 *     break;
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * NO `loadService()` 503 GATE — `PerformanceMetricsService` is
 * `require_once`'d DIRECTLY, unguarded
 * ═══════════════════════════════════════════════════════════════════════
 * Ground-truth-verified against the PHP source above: unlike
 * `../record-analytics` (which DOES call `loadService('ConsultationAnalyzerService',
 * ...)` with a real 503 branch), this case block does
 * `require_once __DIR__ . '/../classes/PerformanceMetricsService.php';
 * $perfService = new PerformanceMetricsService($db, $lineAccountId);` — a
 * bare `require_once` + direct `new`, with no `file_exists()`/
 * `class_exists()` probe and no `if (!$service) { sendError(..., 503); }`
 * anywhere. There is genuinely no "service unavailable" code path in the
 * PHP source to port — inventing one here would be a fabrication, not a
 * port (per this batch's brief). `route.test.ts` asserts the ABSENCE of a
 * 503 branch, not its presence — same shape of assertion as
 * `../get-performance-metrics/route.test.ts` and `../analytics/route.test.ts`.
 *
 * `$input = json_decode(..., true); if (!$input) { sendError('Invalid JSON
 * input'); }` reads the RAW request body directly (NOT the family's usual
 * `getJsonBody()` + `$_POST` fallback pattern used by e.g.
 * `../record-analytics`) — ported as a direct `request.json()` parse below,
 * matching the PHP source's own direct `php://input` read.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * LOAD-BEARING PHP QUIRK: an empty JSON object `{}` is REJECTED (400), same
 * as malformed/empty input
 * ═══════════════════════════════════════════════════════════════════════
 * `!$input` is a PHP TRUTHINESS check on the WHOLE decoded payload, not a
 * null-check. `json_decode('{}', true)` returns PHP's empty array `[]` —
 * which is FALSY in PHP — so a syntactically valid, non-null, but
 * key-less JSON object body (`{}`) hits the exact same `sendError('Invalid
 * JSON input')` branch as truly malformed/empty input. `isPhpFalsy()` below
 * replicates this: `null`/`undefined` (malformed JSON, or a parse
 * exception), `{}` (empty object — PHP's empty associative array), `[]`
 * (empty JSON array), `0`, `false`, `''`, and the literal string `'0'` are
 * all "falsy"; anything else (a non-empty object/array, a nonzero number,
 * `true`, a non-empty non-`'0'` string) is "truthy" and proceeds.
 * `route.test.ts` asserts the `{}` case explicitly — do not "fix" this into
 * accepting an empty object.
 *
 * `metrics = isset(input.metrics) ? input.metrics : [input]` — the
 * single-metric-shorthand: a caller may POST either `{metrics: [...]}` (a
 * batch) or a single flat metric object directly (`{metric_type: ...,
 * duration_ms: ...}`), which gets wrapped as a 1-element array. Ported as
 * `getMetricsList()` below; if `metrics` is present-and-non-null but is
 * NOT actually an array, this port falls back to an empty list (a
 * deliberate, documented DEVIATION from PHP's own behavior, which would
 * silently `foreach` over a non-iterable value and process zero items with
 * warnings suppressed — the net observable effect, zero metrics processed,
 * is the same either way; JS has no equivalent silent-no-op path for
 * `for...of` over a non-iterable that would not itself throw).
 *
 * Per-metric fields: `metricType = metric.metric_type ?? null`;
 * `durationMs = metric.duration_ms ?? null`; `userAgent =
 * metric.user_agent ?? request.headers.get('user-agent') ?? null`
 * (PHP's `$_SERVER['HTTP_USER_AGENT']`); `operationDetails =
 * metric.operation_details ?? null`.
 *
 * Skip-and-count-as-failed: `!$metricType || $durationMs === null` — PHP's
 * `!$metricType` is a TRUTHINESS check (catches `''`, `0`, `false`, `null`,
 * an empty array), but `$durationMs === null` is a STRICT null check — a
 * `durationMs` of `0` is explicitly NOT skipped here (it still reaches
 * `logMetric()`, which has its OWN, separate `is_numeric()`/`>= 0`
 * validation — see `_lib/logPerformanceMetric.ts`'s module doc for that
 * second, later gate).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * LOAD-BEARING: RESPONSE IS UNCONDITIONALLY `success:true` AT HTTP 200 —
 * even when EVERY metric in the batch failed
 * ═══════════════════════════════════════════════════════════════════════
 * `sendResponse(['success' => true, ...])` is unconditional once the
 * `!$input` gate passes — there is no branch that flips `success` to
 * `false` based on `$failCount` (even `$failCount === count($metrics)`,
 * i.e. every single metric failed validation/logging). Only a genuinely
 * THROWN/unexpected error (not a per-metric validation failure, which is
 * caught and counted, never thrown) reaches the `catch (Exception $e)`
 * block and produces `{success:false, error:'Failed to log performance
 * metrics: ...'}` at (PHP's `sendError()` default) HTTP 400.
 * `route.test.ts` asserts the all-failed-batch case explicitly stays
 * `success:true` at 200.
 *
 * `lineAccountId` resolves as `session.currentBotId ?? 1` — the established
 * 2-tier convention across this whole `api/inbox/actions/*` family (see
 * e.g. `../poll/route.ts`, `../get-admins/route.ts`), NOT PHP's own broader
 * `$_SESSION`/`$_GET`/`$_POST` resolution chain for `$lineAccountId` at the
 * top of `api/inbox-v2.php`.
 *
 * AUTH: same gate as every other `api/inbox/actions/*` sibling — a valid
 * tenant session (any of the six TenantRole values).
 */

/** PHP truthiness (`!$v`) on an already-decoded JSON value — see module doc for the exact falsy set. */
function isPhpFalsy(value: unknown): boolean {
  if (value === null || value === undefined || value === false) return true;
  if (value === 0 || value === '' || value === '0') return true;
  if (Array.isArray(value)) return value.length === 0;
  if (typeof value === 'object') return Object.keys(value as Record<string, unknown>).length === 0;
  return false;
}

/** PHP `isset($v)` — false for both a missing key (`undefined`) and an explicit JSON `null`. */
function isSetValue(value: unknown): boolean {
  return value !== undefined && value !== null;
}

/** `isset($input['metrics']) ? $input['metrics'] : [$input]` — see module doc for the non-array-`metrics` fallback deviation. */
function getMetricsList(input: unknown): unknown[] {
  if (input !== null && typeof input === 'object' && !Array.isArray(input)) {
    const metricsField = (input as Record<string, unknown>).metrics;
    if (isSetValue(metricsField)) {
      return Array.isArray(metricsField) ? metricsField : [];
    }
  }
  return [input];
}

/** `$metric['key'] ?? null` — array/object-key read with PHP's isset-based `??` default; non-object `$metric` (or a missing key) always yields `null`, matching PHP's warning-suppressed `null` on invalid array access. */
function getMetricField(metric: unknown, key: string): unknown {
  if (metric !== null && typeof metric === 'object' && !Array.isArray(metric)) {
    const value = (metric as Record<string, unknown>)[key];
    return isSetValue(value) ? value : null;
  }
  return null;
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveInboxApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }

  let input: unknown;
  try {
    input = await request.json();
  } catch {
    input = null; // malformed/empty body -> PHP's json_decode(...) === null
  }

  if (isPhpFalsy(input)) {
    return NextResponse.json({ success: false, error: 'Invalid JSON input' }, { status: 400 });
  }

  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;
  const headerUserAgent = request.headers.get('user-agent');

  const metrics = getMetricsList(input);

  let successCount = 0;
  let failCount = 0;

  try {
    for (const metric of metrics) {
      const metricType = getMetricField(metric, 'metric_type');
      const durationMs = getMetricField(metric, 'duration_ms');
      const metricUserAgentRaw = getMetricField(metric, 'user_agent');
      const userAgent = (isSetValue(metricUserAgentRaw) ? (metricUserAgentRaw as string) : (headerUserAgent ?? null)) as string | null;
      const operationDetails = getMetricField(metric, 'operation_details');

      if (isPhpFalsy(metricType) || durationMs === null) {
        failCount++;
        continue;
      }

      const result = await logMetric(db, lineAccountId, { metricType, durationMs, userAgent, operationDetails });
      if (result) {
        successCount++;
      } else {
        failCount++;
      }
    }

    return NextResponse.json({
      success: true,
      message: `Logged ${successCount} metrics${failCount > 0 ? `, ${failCount} failed` : ''}`,
      logged: successCount,
      failed: failCount,
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to log performance metrics: ${message}` }, { status: 400 });
  }
}

/** Method-not-allowed, matching PHP's `sendError('Method not allowed', 405)` shape for this action. */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json({ success: false, error: 'Method not allowed' }, { status: 405 });
}
