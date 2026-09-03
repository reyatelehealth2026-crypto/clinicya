import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * consultationAnalytics.ts — literal port of `api/inbox-v2.php`'s `case
 * 'analytics': case 'get_analytics': case 'consultation_analytics':` two
 * inline SQL queries + arithmetic (lines 1681-1786). **There is no PHP
 * service class here** — unlike every other `ConsultationAnalyzerService`-
 * backed action in this family (`detect-urgency`, `consultation-stage`,
 * `quick-actions`, `context-widgets`, and this batch's own
 * `record-analytics`), this case block runs its SQL directly against `$db`
 * inline in the switch statement, never calling `loadService(...)`. See
 * "NO 503 GATE" below.
 *
 * ```php
 * $pharmacistId = (int) ($_GET['pharmacist_id'] ?? $adminId ?? 0);
 * $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
 * $endDate = $_GET['end_date'] ?? date('Y-m-d');
 *
 * try {
 *     $sql = "
 *         SELECT
 *             COUNT(*) as total_consultations,
 *             SUM(resulted_in_purchase) as successful_consultations,
 *             AVG(response_time_avg) as avg_response_time,
 *             SUM(ai_suggestions_shown) as total_ai_suggestions,
 *             SUM(ai_suggestions_accepted) as accepted_ai_suggestions,
 *             SUM(purchase_amount) as total_revenue,
 *             AVG(message_count) as avg_messages_per_consultation
 *         FROM consultation_analytics
 *         WHERE created_at BETWEEN ? AND ?
 *     ";
 *     $params = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
 *     if ($pharmacistId) { $sql .= " AND pharmacist_id = ?"; $params[] = $pharmacistId; }
 *     $stmt = $db->prepare($sql); $stmt->execute($params);
 *     $summary = $stmt->fetch(PDO::FETCH_ASSOC);
 *
 *     $sql2 = "
 *         SELECT communication_type, COUNT(*) as count,
 *                SUM(resulted_in_purchase) as purchases,
 *                AVG(response_time_avg) as avg_response_time
 *         FROM consultation_analytics
 *         WHERE created_at BETWEEN ? AND ?
 *     ";
 *     $params2 = [$startDate . ' 00:00:00', $endDate . ' 23:59:59'];
 *     if ($pharmacistId) { $sql2 .= " AND pharmacist_id = ?"; $params2[] = $pharmacistId; }
 *     $sql2 .= " GROUP BY communication_type";
 *     $stmt2 = $db->prepare($sql2); $stmt2->execute($params2);
 *     $byType = $stmt2->fetchAll(PDO::FETCH_ASSOC);
 *
 *     $totalConsultations = (int) ($summary['total_consultations'] ?? 0);
 *     $successfulConsultations = (int) ($summary['successful_consultations'] ?? 0);
 *     $successRate = $totalConsultations > 0 ? round(($successfulConsultations / $totalConsultations) * 100, 2) : 0;
 *
 *     $totalAiSuggestions = (int) ($summary['total_ai_suggestions'] ?? 0);
 *     $acceptedAiSuggestions = (int) ($summary['accepted_ai_suggestions'] ?? 0);
 *     $aiAcceptanceRate = $totalAiSuggestions > 0 ? round(($acceptedAiSuggestions / $totalAiSuggestions) * 100, 2) : 0;
 *
 *     sendResponse(['success' => true, 'data' => [
 *         'period' => ['startDate' => $startDate, 'endDate' => $endDate],
 *         'summary' => [
 *             'totalConsultations' => $totalConsultations,
 *             'successfulConsultations' => $successfulConsultations,
 *             'successRate' => $successRate,
 *             'avgResponseTime' => round((float) ($summary['avg_response_time'] ?? 0), 2),
 *             'avgMessagesPerConsultation' => round((float) ($summary['avg_messages_per_consultation'] ?? 0), 1),
 *             'totalRevenue' => (float) ($summary['total_revenue'] ?? 0),
 *             'aiAcceptanceRate' => $aiAcceptanceRate
 *         ],
 *         'byType' => $byType
 *     ]]);
 * } catch (PDOException $e) {
 *     logInboxApiException($e, 'catch');
 *     error_log("Analytics query error: " . $e->getMessage());
 *     sendResponse(['success' => true, 'data' => [
 *         'period' => ['startDate' => $startDate, 'endDate' => $endDate],
 *         'summary' => [], 'byType' => [], 'message' => 'No analytics data available yet'
 *     ]]);
 * }
 * ```
 *
 * ═══════════════════════════════════════════════════════════════════════
 * NO 503 GATE — this case block never calls `loadService(...)` at all
 * ═══════════════════════════════════════════════════════════════════════
 * Ground-truth-verified against `api/inbox-v2.php` lines 1681-1786 (not
 * assumed from the brief's own summary, which read ambiguously on this
 * point in one place — resolved here by re-reading the actual PHP source,
 * per this agent's standing instruction to read source in full rather than
 * guess). `case 'analytics':` runs its two `SELECT`s directly against `$db`
 * — there is no `ConsultationAnalyzerService` instance anywhere in this
 * case body, so no `loadService()` guard, no 503 branch, nothing to port
 * for "service unavailable". Do not confuse this with `record-analytics`
 * (this batch's OTHER `ConsultationAnalyzerService`-backed sibling, which
 * DOES call `loadService('ConsultationAnalyzerService', ...)` with a real
 * 503 gate — see `../../record-analytics/_lib/recordAnalytics.ts`'s module
 * doc). `route.test.ts` asserts the ABSENCE of a 503 code path here, the
 * same shape of assertion used for `../../log-performance-metric` and
 * `../../get-performance-metrics` (see this batch's runbook §3).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * BROAD CATCH, NOT A NARROW PDOException-ONLY CATCH
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `catch (PDOException $e)` only catches driver-level SQL failures —
 * a genuinely different (and impossible here) failure like a TypeError
 * would NOT be caught by that clause and would fall through to
 * `api/inbox-v2.php`'s outer `catch (Throwable $e)` (a different response
 * shape: `Internal server error: ...` at 500). This port's `../route.ts`
 * wraps the ENTIRE `getConsultationAnalytics()` call (both queries + all
 * arithmetic) in one plain `catch`, with no attempt to distinguish "a
 * mysql2 driver error" from "a genuinely unexpected JS exception" — a
 * broad catch, not a narrow one. This is a DELIBERATE, DOCUMENTED
 * simplification (JS has no exception-type hierarchy analogous to PDO's
 * `PDOException` vs. everything else), following this family's own
 * precedent for "broad-catch-with-same-degrade-behavior" being an
 * acceptable simplification (see e.g. `../../poll/route.ts`'s own module
 * doc for the general pattern of documenting such choices explicitly
 * rather than doing them silently) — flagged prominently here per this
 * batch's brief, not silently done. In practice this only matters for the
 * theoretical case of a non-SQL exception during the arithmetic below (none
 * of which can actually throw — see `toIntOrZero`/`toFloatOrZero`, both
 * total functions), so the distinction is not observable in this route's
 * real behavior either way.
 *
 * `byType` rows are passed through EXACTLY as fetched — PHP's
 * `fetchAll(PDO::FETCH_ASSOC)` result is embedded in the response
 * unmodified, snake_case column names and all (`communication_type`,
 * `count`, `purchases`, `avg_response_time`) — UNLIKE `summary`, which is
 * rebuilt into a fresh camelCase object. This module reproduces that
 * asymmetry: `byType` below is the raw driver row array, `summary` is
 * hand-assembled.
 */

interface SummaryRow {
  total_consultations: number | string | null;
  successful_consultations: number | string | null;
  avg_response_time: number | string | null;
  total_ai_suggestions: number | string | null;
  accepted_ai_suggestions: number | string | null;
  total_revenue: number | string | null;
  avg_messages_per_consultation: number | string | null;
}

export interface ByTypeRow {
  communication_type: string | null;
  count: number | string;
  purchases: number | string | null;
  avg_response_time: number | string | null;
}

export interface ConsultationAnalyticsSummary {
  totalConsultations: number;
  successfulConsultations: number;
  successRate: number;
  avgResponseTime: number;
  avgMessagesPerConsultation: number;
  totalRevenue: number;
  aiAcceptanceRate: number;
}

export interface ConsultationAnalyticsResult {
  summary: ConsultationAnalyticsSummary;
  byType: ByTypeRow[];
}

/** PHP `(int) ($v ?? 0)` on a fetched column value (mysql2 may hand back a numeric string, a number, or null). */
function toIntOrZero(value: unknown): number {
  if (value === null || value === undefined) return 0;
  const n = typeof value === 'number' ? value : Number.parseFloat(String(value));
  return Number.isFinite(n) ? Math.trunc(n) : 0;
}

/** PHP `(float) ($v ?? 0)` on a fetched column value. */
function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** PHP `round($x, 2)` — for the non-negative magnitudes this module ever produces, `Math.round` half-up is equivalent. */
function round2(value: number): number {
  return Math.round(value * 100) / 100;
}

/** PHP `round($x, 1)`. */
function round1(value: number): number {
  return Math.round(value * 10) / 10;
}

export async function getConsultationAnalytics(
  db: Kysely<TenantDB>,
  startDate: string,
  endDate: string,
  pharmacistId: number
): Promise<ConsultationAnalyticsResult> {
  const startDateTime = `${startDate} 00:00:00`;
  const endDateTime = `${endDate} 23:59:59`;
  // PHP `if ($pharmacistId) { ... AND pharmacist_id = ? }` — int truthiness (0 is the only falsy int, negatives included).
  const pharmacistClause = pharmacistId ? sql` AND pharmacist_id = ${pharmacistId}` : sql``;

  const summaryResult = await sql<SummaryRow>`
    SELECT
      COUNT(*) as total_consultations,
      SUM(resulted_in_purchase) as successful_consultations,
      AVG(response_time_avg) as avg_response_time,
      SUM(ai_suggestions_shown) as total_ai_suggestions,
      SUM(ai_suggestions_accepted) as accepted_ai_suggestions,
      SUM(purchase_amount) as total_revenue,
      AVG(message_count) as avg_messages_per_consultation
    FROM consultation_analytics
    WHERE created_at BETWEEN ${startDateTime} AND ${endDateTime}${pharmacistClause}
  `.execute(db);
  const summary = summaryResult.rows[0];

  const byTypeResult = await sql<ByTypeRow>`
    SELECT
      communication_type,
      COUNT(*) as count,
      SUM(resulted_in_purchase) as purchases,
      AVG(response_time_avg) as avg_response_time
    FROM consultation_analytics
    WHERE created_at BETWEEN ${startDateTime} AND ${endDateTime}${pharmacistClause}
    GROUP BY communication_type
  `.execute(db);

  const totalConsultations = toIntOrZero(summary?.total_consultations);
  const successfulConsultations = toIntOrZero(summary?.successful_consultations);
  const successRate = totalConsultations > 0 ? round2((successfulConsultations / totalConsultations) * 100) : 0;

  const totalAiSuggestions = toIntOrZero(summary?.total_ai_suggestions);
  const acceptedAiSuggestions = toIntOrZero(summary?.accepted_ai_suggestions);
  const aiAcceptanceRate = totalAiSuggestions > 0 ? round2((acceptedAiSuggestions / totalAiSuggestions) * 100) : 0;

  return {
    summary: {
      totalConsultations,
      successfulConsultations,
      successRate,
      avgResponseTime: round2(toFloatOrZero(summary?.avg_response_time)),
      avgMessagesPerConsultation: round1(toFloatOrZero(summary?.avg_messages_per_consultation)),
      totalRevenue: toFloatOrZero(summary?.total_revenue),
      aiAcceptanceRate,
    },
    byType: byTypeResult.rows,
  };
}
