/**
 * bangkokTime.ts — Asia/Bangkok wall-clock helpers for `case 'analytics':`'s
 * default date range (`api/inbox-v2.php` lines 1689-1690):
 *
 * ```php
 * $startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
 * $endDate = $_GET['end_date'] ?? date('Y-m-d');
 * ```
 *
 * CLAUDE.md: "Timezone is always Asia/Bangkok (+07:00)" — PHP's `date()`/
 * `strtotime()` calls run under that server-configured default timezone;
 * this file reproduces the same wall-clock arithmetic explicitly via
 * `Intl.DateTimeFormat`'s `timeZone` option rather than assuming the Node
 * process itself runs in +07:00 — same established technique as
 * `apps/admin/src/app/api/inbox/actions/dispense/_lib/bangkokTime.ts` and
 * `apps/admin/src/app/(tenant)/analytics/_lib/period.ts` (mirrored, not
 * imported — those live in different builders' lanes; this batch's
 * allowed-paths boundary keeps this folder independently editable).
 */

const BANGKOK_TIME_ZONE = 'Asia/Bangkok';

/** 'YYYY-MM-DD' for "today" in Asia/Bangkok — mirrors PHP's `date('Y-m-d')` under this codebase's forced +07:00 timezone. */
export function todayInBangkok(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: BANGKOK_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now);
}

/** 'YYYY-MM-DD' for (today - days) in Asia/Bangkok — mirrors PHP's `date('Y-m-d', strtotime("-{$days} days"))`. */
export function daysAgoInBangkok(days: number, now: Date = new Date()): string {
  const todayStr = todayInBangkok(now);
  const d = new Date(`${todayStr}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() - days);
  return d.toISOString().slice(0, 10);
}
