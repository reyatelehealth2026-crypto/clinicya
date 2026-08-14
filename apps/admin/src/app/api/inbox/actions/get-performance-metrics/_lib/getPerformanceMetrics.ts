import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * getPerformanceMetrics.ts — literal port of `classes/PerformanceMetricsService.php`'s
 * `getMetricStats()` (lines 192-250), `calculatePercentiles()` (lines
 * 260-312), `getErrorRate()` (lines 325-367), and `getAllMetricStats()`
 * (lines 378-387), plus `api/inbox-v2.php`'s own error-rate-augmentation
 * loop (lines 2926-2943) backing `case 'getPerformanceMetrics': case
 * 'get_performance_metrics':` (lines 2910-2954).
 *
 * ```php
 * public function getMetricStats($metricType, $startDate = null, $endDate = null) {
 *     try {
 *         $query = "SELECT COUNT(*) as count, AVG(duration_ms) as average, MIN(duration_ms) as min, MAX(duration_ms) as max
 *                    FROM performance_metrics WHERE metric_type = ?";
 *         $params = [$metricType];
 *         if ($this->lineAccountId !== null) { $query .= " AND line_account_id = ?"; $params[] = $this->lineAccountId; }
 *         if ($startDate !== null) { $query .= " AND DATE(created_at) >= ?"; $params[] = $startDate; }
 *         if ($endDate !== null) { $query .= " AND DATE(created_at) <= ?"; $params[] = $endDate; }
 *         $stmt = $this->db->prepare($query); $stmt->execute($params);
 *         $stats = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if ($stats['count'] > 0) {
 *             $percentiles = $this->calculatePercentiles($metricType, $startDate, $endDate);
 *             $stats = array_merge($stats, $percentiles);
 *         } else {
 *             $stats['p50'] = 0; $stats['p95'] = 0; $stats['p99'] = 0;
 *         }
 *         return $stats;
 *     } catch (PDOException $e) {
 *         error_log("PerformanceMetricsService: Error calculating stats - " . $e->getMessage());
 *         return ['count' => 0, 'average' => 0, 'min' => 0, 'max' => 0, 'p50' => 0, 'p95' => 0, 'p99' => 0];
 *     }
 * }
 *
 * private function calculatePercentiles($metricType, $startDate = null, $endDate = null) {
 *     try {
 *         $query = "SELECT duration_ms FROM performance_metrics WHERE metric_type = ?"; $params = [$metricType];
 *         if ($this->lineAccountId !== null) { ...AND line_account_id = ?... }
 *         if ($startDate !== null) { ...AND DATE(created_at) >= ?... }
 *         if ($endDate !== null) { ...AND DATE(created_at) <= ?... }
 *         $query .= " ORDER BY duration_ms ASC";
 *         $stmt = $this->db->prepare($query); $stmt->execute($params);
 *         $durations = $stmt->fetchAll(PDO::FETCH_COLUMN);
 *         $count = count($durations);
 *         if ($count === 0) { return ['p50' => 0, 'p95' => 0, 'p99' => 0]; }
 *         $p50Index = (int) ceil($count * 0.50) - 1;
 *         $p95Index = (int) ceil($count * 0.95) - 1;
 *         $p99Index = (int) ceil($count * 0.99) - 1;
 *         return ['p50' => $durations[$p50Index], 'p95' => $durations[$p95Index], 'p99' => $durations[$p99Index]];
 *     } catch (PDOException $e) {
 *         error_log(...); return ['p50' => 0, 'p95' => 0, 'p99' => 0];
 *     }
 * }
 *
 * public function getErrorRate($metricType, $errorThreshold, $startDate = null, $endDate = null) {
 *     try {
 *         $query = "SELECT COUNT(*) as total, SUM(CASE WHEN duration_ms > ? THEN 1 ELSE 0 END) as errors
 *                    FROM performance_metrics WHERE metric_type = ?"; $params = [$errorThreshold, $metricType];
 *         if ($this->lineAccountId !== null) { ... } if ($startDate !== null) { ... } if ($endDate !== null) { ... }
 *         $stmt = $this->db->prepare($query); $stmt->execute($params);
 *         $result = $stmt->fetch(PDO::FETCH_ASSOC);
 *         if ($result['total'] == 0) { return 0.0; }
 *         return ($result['errors'] / $result['total']) * 100;
 *     } catch (PDOException $e) { error_log(...); return 0.0; }
 * }
 *
 * public function getAllMetricStats($startDate = null, $endDate = null) {
 *     $metricTypes = ['page_load', 'conversation_switch', 'message_render', 'api_call', 'scroll_performance'];
 *     $results = [];
 *     foreach ($metricTypes as $type) { $results[$type] = $this->getMetricStats($type, $startDate, $endDate); }
 *     return $results;
 * }
 * ```
 *
 * `api/inbox-v2.php`'s `case 'getPerformanceMetrics':` case body itself:
 * ```php
 * $stats = $perfService->getAllMetricStats($startDate, $endDate);
 * $thresholds = ['page_load' => 2000, 'conversation_switch' => 1000, 'message_render' => 200, 'api_call' => 500];
 * foreach ($stats as $type => $data) {
 *     if (isset($thresholds[$type])) {
 *         $stats[$type]['error_rate'] = $perfService->getErrorRate($type, $thresholds[$type], $startDate, $endDate);
 *     }
 * }
 * sendResponse(['success' => true, 'data' => $stats]);
 * ```
 *
 * `performance_metrics` is confirmed present in
 * `packages/db/src/generated/tenant-db.d.ts` (`PerformanceMetrics`) with
 * every column these `SELECT`s touch correctly typed — a fully literal,
 * unmodified port, no schema-drift fix needed.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * NO `loadService()` 503 GATE — same reason as `../../log-performance-metric`
 * ═══════════════════════════════════════════════════════════════════════
 * `PerformanceMetricsService` is `require_once`'d and `new`'d directly in
 * `case 'getPerformanceMetrics':`, exactly like its `log_performance_metric`
 * sibling — no `file_exists()`/`class_exists()` probe, no 503 branch
 * anywhere in the PHP source. Not fabricated here either.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * TWO DIFFERENT METRIC-TYPE LISTS — do not confuse them
 * ═══════════════════════════════════════════════════════════════════════
 * `getAllMetricStats()`'s own list (`STATS_METRIC_TYPES` below) has
 * **5 entries** and deliberately EXCLUDES `cache_hit`/`cache_miss` (those
 * two ARE valid for `logMetric()`'s 7-value whitelist in
 * `../../log-performance-metric/_lib/logPerformanceMetric.ts`, but
 * `getAllMetricStats()` simply never queries stats for them — a metric
 * logged as `cache_hit` is stored and retrievable via `getMetricStats()`
 * directly, just never surfaced through THIS aggregate endpoint).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * TWO DIFFERENT THRESHOLD MAPS — do not confuse this one with logMetric()'s
 * ═══════════════════════════════════════════════════════════════════════
 * The case body's own `$thresholds` map (`ERROR_RATE_THRESHOLDS_MS` below)
 * has only **4 entries** — `page_load`, `conversation_switch`,
 * `message_render`, `api_call` — DELIBERATELY EXCLUDING
 * `scroll_performance`. Only these 4 types get an `error_rate` key added to
 * their stats object; `scroll_performance`'s stats object has NO
 * `error_rate` key at all (not even `null`) — `isset($thresholds[$type])`
 * is `false` for it, so the augmentation loop's body never runs for that
 * type. This is a SEPARATE, textually distinct PHP array from
 * `../../log-performance-metric/_lib/logPerformanceMetric.ts`'s own
 * `LOG_THRESHOLDS_MS` (5 entries, DOES include `scroll_performance: 17`) —
 * see that file's own module doc for the same warning from the other
 * direction. The two maps happen to share identical numeric values for the
 * 4 types they have in common; that is a PHP-source coincidence, not a
 * shared constant — each is ported as its own independent `const` here.
 *
 * `getMetricStats()`'s degrade-on-failure shape
 * (`{count:0,average:0,min:0,max:0,p50:0,p95:0,p99:0}`) and
 * `calculatePercentiles()`'s own separate degrade shape
 * (`{p50:0,p95:0,p99:0}`, merged into the stats the SAME way a real
 * `array_merge()` would) and `getErrorRate()`'s own degrade value (`0.0`)
 * are all ported as literal fallbacks on any query failure — each wrapped
 * in its own `try/catch`, matching PHP's per-method `try/catch` structure
 * exactly (three independent catches, not one catch around everything).
 *
 * `(int) ceil($count * 0.50) - 1` etc. — `Math.ceil()` + `Math.trunc()`
 * (PHP's `(int)` cast on an already-integral `ceil()` result is a no-op
 * truncation) ported as `Math.trunc(Math.ceil(...)) - 1`, equivalently
 * `Math.ceil(...) - 1` since `ceil()`'s result is already an integer.
 *
 * `$result['errors'] / $result['total']) * 100` — PHP's `==` loose
 * comparison (`$result['total'] == 0`) matches both int `0` and numeric
 * string `'0'` from a driver that stringifies COUNT(*) — ported via a
 * numeric coercion (`Number(total) === 0`), covering both shapes.
 *
 * `if ($this->lineAccountId !== null) { ... AND line_account_id = ? }` —
 * this port's `line_account_id = ${lineAccountId}` clause below is
 * UNCONDITIONAL, not gated by a null check. `../route.ts` resolves
 * `lineAccountId` as `session.currentBotId ?? 1` (this family's
 * established 2-tier convention — see e.g. `../../poll/route.ts`), which
 * is always a real `number`, never `null` — the exact same real-world
 * situation PHP itself is in (`$lineAccountId` at the top of
 * `api/inbox-v2.php` also always resolves to a real int, defaulting to
 * `1`, never actually `null` in the case-block context). `$this->lineAccountId
 * !== null` is therefore always `true` in practice on both sides; this
 * port simplifies it to an unconditional clause rather than reproducing a
 * branch that can never actually take its `false` path — a deliberate,
 * documented simplification, not a behavior change.
 */

const STATS_METRIC_TYPES = ['page_load', 'conversation_switch', 'message_render', 'api_call', 'scroll_performance'] as const;

export type StatsMetricType = (typeof STATS_METRIC_TYPES)[number];

/** The case body's own 4-entry map — DELIBERATELY EXCLUDES scroll_performance. Distinct from log-performance-metric's 5-entry warning-threshold map — see module doc. */
const ERROR_RATE_THRESHOLDS_MS: Readonly<Partial<Record<StatsMetricType, number>>> = {
  page_load: 2000,
  conversation_switch: 1000,
  message_render: 200,
  api_call: 500,
};

export interface MetricStats {
  count: number;
  /**
   * `null` when the query SUCCEEDED but matched zero rows (MySQL's
   * `AVG()`/`MIN()`/`MAX()` over zero rows is SQL `NULL`, and PHP's `else`
   * branch in `getMetricStats()` never touches these three keys — only
   * `p50`/`p95`/`p99` get forced to `0` there); a literal `0` ONLY on the
   * `catch (PDOException $e)` degrade path (`DEGRADED_STATS` below). These
   * are two genuinely different "empty" shapes — see module doc.
   */
  average: number | null;
  min: number | null;
  max: number | null;
  p50: number;
  p95: number;
  p99: number;
  /** Present ONLY for the 4 types in ERROR_RATE_THRESHOLDS_MS — see module doc. Absent (not null) for scroll_performance. */
  error_rate?: number;
}

/** The `catch (PDOException $e)` degrade shape — literal `0`s for EVERY field, including average/min/max (unlike the "0 matching rows" success path — see `MetricStats.average`'s own doc). */
const DEGRADED_STATS: Readonly<Omit<MetricStats, 'error_rate'>> = { count: 0, average: 0, min: 0, max: 0, p50: 0, p95: 0, p99: 0 };

function toNum(value: unknown): number {
  if (value === null || value === undefined) return 0;
  const n = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** Preserves SQL `NULL` (a genuinely absent AVG/MIN/MAX over zero rows) as `null`, rather than coercing it to `0` like `toNum()` does. */
function toNumOrNull(value: unknown): number | null {
  if (value === null || value === undefined) return null;
  const n = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(n) ? n : null;
}

/** `if ($startDate !== null) { ... AND DATE(created_at) >= ? }` / same for endDate — both conditional clauses, independently applied. */
function dateRangeClause(startDate: string | null, endDate: string | null) {
  const startClause = startDate !== null ? sql` AND DATE(created_at) >= ${startDate}` : sql``;
  const endClause = endDate !== null ? sql` AND DATE(created_at) <= ${endDate}` : sql``;
  return sql`${startClause}${endClause}`;
}

/** `calculatePercentiles()` — its own independent try/catch, degrades to {p50:0,p95:0,p99:0} on failure. */
async function calculatePercentiles(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  metricType: string,
  startDate: string | null,
  endDate: string | null
): Promise<{ p50: number; p95: number; p99: number }> {
  try {
    const dateClause = dateRangeClause(startDate, endDate);
    const result = await sql<{ duration_ms: number | string }>`
      SELECT duration_ms
      FROM performance_metrics
      WHERE metric_type = ${metricType} AND line_account_id = ${lineAccountId}${dateClause}
      ORDER BY duration_ms ASC
    `.execute(db);
    const durations = result.rows.map((r) => toNum(r.duration_ms));
    const count = durations.length;
    if (count === 0) {
      return { p50: 0, p95: 0, p99: 0 };
    }
    const p50Index = Math.ceil(count * 0.5) - 1;
    const p95Index = Math.ceil(count * 0.95) - 1;
    const p99Index = Math.ceil(count * 0.99) - 1;
    return {
      p50: durations[p50Index] ?? 0,
      p95: durations[p95Index] ?? 0,
      p99: durations[p99Index] ?? 0,
    };
  } catch {
    return { p50: 0, p95: 0, p99: 0 };
  }
}

/** `getMetricStats()` — its own independent try/catch, degrades to the full DEGRADED_STATS shape on failure. */
export async function getMetricStats(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  metricType: string,
  startDate: string | null,
  endDate: string | null
): Promise<Omit<MetricStats, 'error_rate'>> {
  try {
    const dateClause = dateRangeClause(startDate, endDate);
    const result = await sql<{ count: number | string; average: number | string | null; min: number | string | null; max: number | string | null }>`
      SELECT COUNT(*) as count, AVG(duration_ms) as average, MIN(duration_ms) as min, MAX(duration_ms) as max
      FROM performance_metrics
      WHERE metric_type = ${metricType} AND line_account_id = ${lineAccountId}${dateClause}
    `.execute(db);
    const row = result.rows[0];
    const count = toNum(row?.count);

    if (count > 0) {
      const percentiles = await calculatePercentiles(db, lineAccountId, metricType, startDate, endDate);
      return {
        count,
        average: toNumOrNull(row?.average),
        min: toNumOrNull(row?.min),
        max: toNumOrNull(row?.max),
        ...percentiles,
      };
    }

    // count === 0 (zero matching rows, query itself succeeded): PHP's `else` branch
    // only forces p50/p95/p99 to 0 — average/min/max stay whatever AVG()/MIN()/MAX()
    // returned, which is SQL NULL over zero rows. NOT the same shape as DEGRADED_STATS.
    return {
      count,
      average: toNumOrNull(row?.average),
      min: toNumOrNull(row?.min),
      max: toNumOrNull(row?.max),
      p50: 0,
      p95: 0,
      p99: 0,
    };
  } catch {
    return { ...DEGRADED_STATS };
  }
}

/** `getErrorRate()` — its own independent try/catch, degrades to 0.0 on failure. */
export async function getErrorRate(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  metricType: string,
  errorThreshold: number,
  startDate: string | null,
  endDate: string | null
): Promise<number> {
  try {
    const dateClause = dateRangeClause(startDate, endDate);
    const result = await sql<{ total: number | string; errors: number | string | null }>`
      SELECT COUNT(*) as total, SUM(CASE WHEN duration_ms > ${errorThreshold} THEN 1 ELSE 0 END) as errors
      FROM performance_metrics
      WHERE metric_type = ${metricType} AND line_account_id = ${lineAccountId}${dateClause}
    `.execute(db);
    const row = result.rows[0];
    const total = toNum(row?.total);
    if (total === 0) {
      return 0.0;
    }
    return (toNum(row?.errors) / total) * 100;
  } catch {
    return 0.0;
  }
}

export type AllMetricStats = Record<StatsMetricType, MetricStats>;

/**
 * `getAllMetricStats()` + the case body's own error-rate augmentation loop
 * (lines 2926-2943) — combined into one function since both always run
 * together for this one action (see module doc for why `error_rate` is
 * present only for 4 of the 5 types).
 */
export async function getAllMetricStats(db: Kysely<TenantDB>, lineAccountId: number, startDate: string | null, endDate: string | null): Promise<AllMetricStats> {
  const results = {} as AllMetricStats;

  for (const type of STATS_METRIC_TYPES) {
    results[type] = await getMetricStats(db, lineAccountId, type, startDate, endDate);
  }

  for (const type of STATS_METRIC_TYPES) {
    const threshold = ERROR_RATE_THRESHOLDS_MS[type];
    if (threshold !== undefined) {
      results[type].error_rate = await getErrorRate(db, lineAccountId, type, threshold, startDate, endDate);
    }
  }

  return results;
}
