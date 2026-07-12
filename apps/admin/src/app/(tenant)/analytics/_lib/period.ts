/**
 * period.ts — analytics.php's top-of-file date-range parsing (lines 42-44),
 * used by the 'overview' tab only:
 *
 *   $period = $_GET['period'] ?? '30';
 *   $startDate = $_GET['start'] ?? date('Y-m-d', strtotime("-{$period} days"));
 *   $endDate = $_GET['end'] ?? date('Y-m-d');
 *
 * The 'advanced' tab has its OWN, differently-shaped period parameter
 * ('24h'|'7d'|'30d'|'90d'|'year', defaulting to '7d' — see
 * AnalyticsController::dashboard()/AnalyticsModel::getDateRange()) — ported
 * separately in advancedQueries.ts, not here. Both tabs read the same `period`
 * query-string key but are never rendered simultaneously (only the active
 * tab's include runs), so there is no real collision, only two independent
 * readers of the same key — replicated as two independent parsers here too.
 */

const BANGKOK_TIME_ZONE = 'Asia/Bangkok';

/** 'YYYY-MM-DD' for "today" in Asia/Bangkok — mirrors PHP's date('Y-m-d') under this codebase's forced +07:00 timezone. */
export function todayInBangkok(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: BANGKOK_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now);
}

/** 'YYYY-MM-DD' for (today - days) in Asia/Bangkok — mirrors PHP's date('Y-m-d', strtotime("-{$period} days")). */
export function daysAgoInBangkok(days: number, now: Date = new Date()): string {
  const todayStr = todayInBangkok(now);
  const d = new Date(`${todayStr}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() - days);
  return d.toISOString().slice(0, 10);
}

export interface OverviewPeriodFilter {
  /** Raw `period` value, verbatim from searchParams (or '30' when absent) — mirrors PHP's `$_GET['period'] ?? '30'`. */
  period: string;
  startDate: string;
  endDate: string;
}

export function parseOverviewPeriod(
  searchParams: Record<string, string | string[] | undefined>,
  now: Date = new Date()
): OverviewPeriodFilter {
  const periodRaw = searchParams.period;
  const period = (Array.isArray(periodRaw) ? periodRaw[0] : periodRaw) ?? '30';

  const startRaw = searchParams.start;
  const start = Array.isArray(startRaw) ? startRaw[0] : startRaw;
  const daysNum = Number.parseInt(period, 10);
  const startDate = start ?? daysAgoInBangkok(Number.isFinite(daysNum) ? daysNum : 30, now);

  const endRaw = searchParams.end;
  const end = Array.isArray(endRaw) ? endRaw[0] : endRaw;
  const endDate = end ?? todayInBangkok(now);

  return { period, startDate, endDate };
}
