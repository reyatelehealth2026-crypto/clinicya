import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * logPerformanceMetric.ts — literal port of `classes/PerformanceMetricsService.php`'s
 * `logMetric()` (lines 39-88) and `checkPerformanceThreshold()` (lines
 * 99-122), backing `api/inbox-v2.php`'s `case 'logPerformanceMetric': case
 * 'log_performance_metric':` (lines 2845-2904).
 *
 * ```php
 * public function logMetric($metricType, $durationMs, $userAgent = null, $operationDetails = null) {
 *     try {
 *         $validTypes = ['page_load', 'conversation_switch', 'message_render', 'api_call', 'scroll_performance', 'cache_hit', 'cache_miss'];
 *         if (!in_array($metricType, $validTypes)) {
 *             error_log("PerformanceMetricsService: Invalid metric type: $metricType");
 *             return false;
 *         }
 *         if (!is_numeric($durationMs) || $durationMs < 0) {
 *             error_log("PerformanceMetricsService: Invalid duration: $durationMs");
 *             return false;
 *         }
 *         $detailsJson = null;
 *         if ($operationDetails !== null) {
 *             $detailsJson = json_encode($operationDetails);
 *             if ($detailsJson === false) { error_log(...); $detailsJson = null; }
 *         }
 *         $stmt = $this->db->prepare("
 *             INSERT INTO performance_metrics
 *             (line_account_id, metric_type, duration_ms, user_agent, operation_details, created_at)
 *             VALUES (?, ?, ?, ?, ?, NOW())
 *         ");
 *         $result = $stmt->execute([$this->lineAccountId, $metricType, intval($durationMs), $userAgent, $detailsJson]);
 *         $this->checkPerformanceThreshold($metricType, $durationMs, $operationDetails);
 *         return $result;
 *     } catch (PDOException $e) {
 *         error_log("PerformanceMetricsService: Database error - " . $e->getMessage());
 *         return false;
 *     }
 * }
 *
 * private function checkPerformanceThreshold($metricType, $durationMs, $operationDetails) {
 *     $thresholds = [
 *         'page_load' => 2000, 'conversation_switch' => 1000, 'message_render' => 200,
 *         'api_call' => 500, 'scroll_performance' => 17
 *     ];
 *     if (isset($thresholds[$metricType]) && $durationMs > $thresholds[$metricType]) {
 *         $context = '';
 *         if ($operationDetails) { $context = ' - Details: ' . json_encode($operationDetails); }
 *         error_log(sprintf("PERFORMANCE WARNING: %s exceeded threshold (%dms > %dms)%s", $metricType, $durationMs, $thresholds[$metricType], $context));
 *     }
 * }
 * ```
 *
 * `performance_metrics` (columns `line_account_id`, `metric_type`,
 * `duration_ms`, `user_agent`, `operation_details`, `created_at`) is
 * confirmed present in `packages/db/src/generated/tenant-db.d.ts`
 * (`PerformanceMetrics`) with every column this INSERT touches correctly
 * typed (`metric_type` is even a narrow 7-value union there, matching the
 * `$validTypes` whitelist exactly) — a fully literal, unmodified port, no
 * schema-drift fix needed.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * TWO DIFFERENT THRESHOLD MAPS — do not confuse them
 * ═══════════════════════════════════════════════════════════════════════
 * `checkPerformanceThreshold()`'s own map here (`LOG_THRESHOLDS_MS` below)
 * has **5 entries**, INCLUDING `scroll_performance: 17` — used only to emit
 * a `console.warn` (PHP: `error_log(...)`) when a single logged metric
 * exceeds its threshold. This is a COMPLETELY DIFFERENT map from
 * `../../get-performance-metrics/_lib/getPerformanceMetrics.ts`'s own
 * `ERROR_RATE_THRESHOLDS_MS` (4 entries, deliberately EXCLUDING
 * `scroll_performance`), which drives that OTHER action's `error_rate`
 * percentage calculation via `getErrorRate()`. Both maps happen to share
 * the same numeric values for the 4 types they have in common
 * (`page_load: 2000`, `conversation_switch: 1000`, `message_render: 200`,
 * `api_call: 500`) — that overlap is a PHP coincidence (both maps were
 * hand-written with the same numbers), not a shared constant; PHP itself
 * declares two textually separate `$thresholds` arrays in two separate
 * methods, and this port keeps them as two separate, independently-copied
 * `const` maps for the same reason.
 *
 * `is_numeric($durationMs) && $durationMs >= 0` — validated INSIDE
 * `logMetric()` itself (a SEPARATE, LATER check than `../route.ts`'s own
 * earlier `durationMs === null` skip-and-count-as-failed gate). A metric
 * that survives the route's `!metricType || durationMs === null` filter
 * but fails THIS check (e.g. a non-numeric string, or a negative number)
 * still counts as a per-metric failure here, just one layer deeper —
 * `logMetric()` itself returns `false`, incrementing `failCount` the same
 * way. `isPhpNumeric()` below replicates PHP's `is_numeric()` for the
 * shapes `duration_ms` can realistically arrive as from a JSON body
 * (number, or a numeric string) — PHP's `is_numeric()` also accepts
 * leading/trailing whitespace variants and hex/scientific notation for
 * strings; this port covers the realistic JSON-body shapes, not PHP's full
 * grammar (matches this batch's brief, which does not call out any
 * `is_numeric()` edge case beyond "duration_ms of 0 is NOT skipped").
 *
 * `json_encode($operationDetails)` here has NO `JSON_UNESCAPED_UNICODE`
 * flag (unlike `../../record-analytics/_lib/recordAnalytics.ts`'s own
 * `json_encode(..., JSON_UNESCAPED_UNICODE)` calls) — PHP's DEFAULT
 * `json_encode()` DOES escape non-ASCII characters to `\uXXXX` sequences.
 * `JSON.stringify()` never does this. This is a genuine, byte-level
 * encoding difference for any Thai text inside `operation_details` — NOT
 * ported byte-for-byte (this port's stored JSON text will contain literal
 * UTF-8 Thai characters instead of `\uXXXX` escapes), because the escaped
 * vs. unescaped forms are semantically IDENTICAL once JSON-decoded again
 * (both round-trip to the same string) and no consumer of this column in
 * this codebase parses the raw bytes without JSON-decoding first — flagged
 * here for completeness, not treated as a load-bearing quirk to preserve.
 *
 * `$result = $stmt->execute(...)` — PDO's `execute()` returns `true` on a
 * successful `INSERT` (always, regardless of rows-affected count) — ported
 * as an unconditional `true` after a successful `sql`-tagged INSERT,
 * `false` from the enclosing `catch`, same as
 * `../../record-analytics/_lib/recordAnalytics.ts`'s own `recordAnalytics()`.
 *
 * `checkPerformanceThreshold()`'s own PHP `error_log(...)` warning becomes
 * a `console.warn(...)` call here (fired AFTER a successful INSERT, exactly
 * where PHP calls it — after `$stmt->execute()`, before the `return
 * $result`) — mirroring the established house precedent for porting PHP's
 * `error_log()` calls into `console.*` (see e.g.
 * `../../classify-customer/_lib/classifyCustomer.ts`'s `console.error`
 * ports).
 */

const VALID_METRIC_TYPES = [
  'page_load',
  'conversation_switch',
  'message_render',
  'api_call',
  'scroll_performance',
  'cache_hit',
  'cache_miss',
] as const;

export type MetricType = (typeof VALID_METRIC_TYPES)[number];

/** `checkPerformanceThreshold()`'s own 5-entry map — INCLUDES scroll_performance. Distinct from get-performance-metrics' 4-entry error-rate map — see module doc. */
const LOG_THRESHOLDS_MS: Readonly<Partial<Record<MetricType, number>>> = {
  page_load: 2000,
  conversation_switch: 1000,
  message_render: 200,
  api_call: 500,
  scroll_performance: 17,
};

function isValidMetricType(value: unknown): value is MetricType {
  return typeof value === 'string' && (VALID_METRIC_TYPES as readonly string[]).includes(value);
}

/**
 * PHP `is_numeric($v)` for the realistic shapes a JSON-decoded `duration_ms`
 * arrives as (number, or a numeric string) — see module doc.
 */
function isPhpNumeric(value: unknown): boolean {
  if (typeof value === 'number') return Number.isFinite(value);
  if (typeof value === 'string') {
    if (value.trim() === '') return false;
    return Number.isFinite(Number(value));
  }
  return false;
}

/** PHP `intval($v)` — truncating numeric cast, non-numeric -> 0. */
function phpIntval(value: unknown): number {
  const n = typeof value === 'number' ? value : Number.parseFloat(String(value));
  return Number.isFinite(n) ? Math.trunc(n) : 0;
}

/** `checkPerformanceThreshold()` — fires a console.warn when a metric exceeds ITS OWN 5-entry threshold map. Never throws, never affects the caller's return value. */
function checkPerformanceThreshold(metricType: MetricType, durationMs: number, operationDetails: unknown): void {
  const threshold = LOG_THRESHOLDS_MS[metricType];
  if (threshold !== undefined && durationMs > threshold) {
    const context = operationDetails ? ` - Details: ${JSON.stringify(operationDetails)}` : '';
    console.warn(`PERFORMANCE WARNING: ${metricType} exceeded threshold (${durationMs}ms > ${threshold}ms)${context}`);
  }
}

export interface LogMetricParams {
  metricType: unknown;
  durationMs: unknown;
  userAgent: string | null;
  operationDetails: unknown;
}

export async function logMetric(db: Kysely<TenantDB>, lineAccountId: number, params: LogMetricParams): Promise<boolean> {
  try {
    if (!isValidMetricType(params.metricType)) {
      console.error(`PerformanceMetricsService: Invalid metric type: ${String(params.metricType)}`);
      return false;
    }

    if (!isPhpNumeric(params.durationMs) || Number(params.durationMs) < 0) {
      console.error(`PerformanceMetricsService: Invalid duration: ${String(params.durationMs)}`);
      return false;
    }

    const detailsJson = params.operationDetails !== null && params.operationDetails !== undefined ? JSON.stringify(params.operationDetails) : null;

    await sql`
      INSERT INTO performance_metrics
      (line_account_id, metric_type, duration_ms, user_agent, operation_details, created_at)
      VALUES (${lineAccountId}, ${params.metricType}, ${phpIntval(params.durationMs)}, ${params.userAgent}, ${detailsJson}, NOW())
    `.execute(db);

    checkPerformanceThreshold(params.metricType, Number(params.durationMs), params.operationDetails);

    return true;
  } catch (error) {
    console.error('PerformanceMetricsService: Database error -', error instanceof Error ? error.message : error);
    return false;
  }
}
