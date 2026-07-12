/**
 * dateFilter.ts — pure tab/date resolution for dashboard.php's tab router
 * and includes/dashboard/executive.php's date filter. No I/O; unit tested
 * directly (no DB, no React) per the Phase 2 batch 1 brief.
 *
 * Port notes:
 *  - dashboard.php: `$activeTab = $_GET['tab'] ?? 'executive'; if (!in_array($activeTab,
 *    $validTabs)) { $activeTab = 'executive'; }` — resolveActiveTab() below is a byte-for-byte
 *    port of that guard (missing OR unrecognised -> 'executive').
 *  - includes/dashboard/executive.php: `$dateFilter = $_GET['date'] ?? date('Y-m-d');` — PHP's
 *    `??` only fires on a genuinely MISSING key, not on an empty/malformed string, and PHP does
 *    NO further validation before splicing $dateFilter into the SQL BETWEEN bounds. We
 *    deliberately mirror that laissez-faire behaviour (default only when the searchParam is
 *    absent; otherwise pass the raw string straight through) rather than adding stricter
 *    validation PHP never had — the value only ever reaches the DB as a bound `sql` parameter
 *    (never string-concatenated), so a malformed date can't do worse than what PHP already risks
 *    (an empty/odd result set for that day), and inventing new validation here would be a
 *    behaviour change, not a markup one.
 */

export const DASHBOARD_TABS = ['executive', 'crm'] as const;
export type DashboardTab = (typeof DASHBOARD_TABS)[number];

/** Port of dashboard.php's `$activeTab = $_GET['tab'] ?? 'executive'; if (!in_array(...)) …`. */
export function resolveActiveTab(tabParam: string | undefined): DashboardTab {
  return (DASHBOARD_TABS as readonly string[]).includes(tabParam ?? '') ? (tabParam as DashboardTab) : 'executive';
}

const BANGKOK_TIME_ZONE = 'Asia/Bangkok';

/**
 * 'YYYY-MM-DD' for "today" in Asia/Bangkok, independent of server-local timezone — mirrors
 * PHP's `date('Y-m-d')` under this codebase's Asia/Bangkok default timezone (CLAUDE.md: "Timezone
 * is always Asia/Bangkok"), computed via an explicit Intl timeZone rather than assuming the
 * Node process itself runs in +07:00.
 */
export function todayInBangkok(now: Date = new Date()): string {
  // en-CA locale formats as YYYY-MM-DD, which is exactly PHP's date('Y-m-d') shape.
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: BANGKOK_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now);
}

export interface ExecutiveDateFilter {
  /** Raw filter value, verbatim from searchParams (or today-in-Bangkok when absent). */
  dateFilter: string;
  /** `${dateFilter} 00:00:00` — Asia/Bangkok wall-clock start bound, matches PHP exactly. */
  dateStart: string;
  /** `${dateFilter} 23:59:59` — Asia/Bangkok wall-clock end bound, matches PHP exactly. */
  dateEnd: string;
}

/** Port of executive.php's `$dateFilter = $_GET['date'] ?? date('Y-m-d');` + the two BETWEEN bounds built from it. */
export function resolveExecutiveDateFilter(dateParam: string | undefined, now: Date = new Date()): ExecutiveDateFilter {
  const dateFilter = dateParam ?? todayInBangkok(now);
  return {
    dateFilter,
    dateStart: `${dateFilter} 00:00:00`,
    dateEnd: `${dateFilter} 23:59:59`,
  };
}

/**
 * Bilingual-safe display formatter for the command-strip date, e.g. "Sunday, 12 Jul 2026" —
 * mirrors PHP's `date('l, j M Y', strtotime($dateFilter))`. PHP's date() emits English day/month
 * names unconditionally (it isn't locale-aware), so this deliberately uses 'en-US', not 'th-TH'.
 * `dateFilter` is parsed as an Asia/Bangkok midnight instant so the weekday can't roll over from a
 * server running in a different timezone. Falls back to the raw string for a malformed
 * `dateFilter` (PHP's `strtotime(false)` → epoch-0 quirk isn't worth reproducing — out of the
 * acceptance-tested scope, see brief).
 */
export function formatDateFilterDisplay(dateFilter: string): string {
  const parsed = new Date(`${dateFilter}T00:00:00+07:00`);
  if (Number.isNaN(parsed.getTime())) {
    return dateFilter;
  }
  // Built from individual Intl parts (rather than one combined formatter) so the output
  // order matches PHP's `l, j M Y` exactly ("Weekday, Day Month Year") — Intl's built-in
  // combined weekday+day+month+year formatting follows locale convention instead
  // ("Sunday, Jul 12, 2026"), which isn't the shape PHP produces.
  const weekday = new Intl.DateTimeFormat('en-US', { timeZone: BANGKOK_TIME_ZONE, weekday: 'long' }).format(parsed);
  const day = new Intl.DateTimeFormat('en-US', { timeZone: BANGKOK_TIME_ZONE, day: 'numeric' }).format(parsed);
  const month = new Intl.DateTimeFormat('en-US', { timeZone: BANGKOK_TIME_ZONE, month: 'short' }).format(parsed);
  const year = new Intl.DateTimeFormat('en-US', { timeZone: BANGKOK_TIME_ZONE, year: 'numeric' }).format(parsed);
  return `${weekday}, ${day} ${month} ${year}`;
}
