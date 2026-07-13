/**
 * bangkokTime.ts — Asia/Bangkok wall-clock date/time helpers for api/appointments.php's port.
 * CLAUDE.md: "Timezone is always Asia/Bangkok (+07:00)" — PHP's `new DateTime(...)`/`date(...)` calls
 * in api/appointments.php run under that server-configured default timezone; this file reproduces the
 * same wall-clock arithmetic explicitly via `Intl.DateTimeFormat`'s `timeZone` option, rather than
 * assuming the Node process itself runs in +07:00 (same convention as dashboard's
 * `_lib/dateFilter.ts::todayInBangkok()` and member's `_lib/columns.ts::generateMemberId()` — mirrored
 * here, not imported, per this batch's self-contained-per-route-folder convention).
 *
 * DESIGN: every date/time value this endpoint touches (`appointment_date`, `appointment_time`,
 * pharmacist schedule `start_time`/`end_time`) is a plain wall-clock value with no timezone
 * information of its own — PHP's `DateTime` arithmetic on them never crosses a real UTC offset
 * conversion, it's pure calendar/clock math. To reproduce that deterministically regardless of the
 * Node process's OS timezone, every "instant" in this file is represented as a Date object built with
 * an explicit trailing `Z` (UTC) from wall-clock components — a PSEUDO-UTC instant, read back out only
 * via the UTC getters (`getUTCHours()` etc, never `getHours()`). The only place a REAL timezone
 * conversion happens is `nowInBangkok()`, which asks `Intl.DateTimeFormat` for the current wall-clock
 * time in Asia/Bangkok and re-encodes those components as a pseudo-UTC Date — so "now" and every
 * date/time value parsed via `pseudoUtcFromDateAndTime()` live in the same pseudo-UTC space and can be
 * compared/subtracted directly with plain Date comparison operators, exactly matching what PHP's
 * `DateTime` comparison operators do when both sides are constructed under the same default timezone.
 */

const BANGKOK_TIME_ZONE = 'Asia/Bangkok';

/** 'YYYY-MM-DD' for "today" in Asia/Bangkok — mirrors PHP's `date('Y-m-d')` under the Asia/Bangkok default timezone. */
export function todayInBangkok(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: BANGKOK_TIME_ZONE, year: 'numeric', month: '2-digit', day: '2-digit' }).format(
    now
  );
}

/** Pseudo-UTC instant for "right now" in Asia/Bangkok — see this file's own doc comment for what "pseudo-UTC" means. */
export function nowInBangkok(now: Date = new Date()): Date {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: BANGKOK_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(now);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '00';
  return new Date(`${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}:${get('second')}Z`);
}

/** Adds `days` (may be negative) to a 'YYYY-MM-DD' string, returning another 'YYYY-MM-DD' string. Pure calendar math, no timezone involved. */
export function addDaysToDateString(dateStr: string, days: number): string {
  const d = new Date(`${dateStr}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}

/** `new DateTime($date . ' ' . $time)` — `time` may be `H:i` or `H:i:s`; both parse fine (missing seconds default to `:00`). */
export function pseudoUtcFromDateAndTime(dateStr: string, timeStr: string): Date {
  const withSeconds = timeStr.length === 5 ? `${timeStr}:00` : timeStr;
  return new Date(`${dateStr}T${withSeconds}Z`);
}

/** `$dt->format('H:i')` on a pseudo-UTC instant produced by this file. */
export function formatHm(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`;
}

/** `(int) $dt->format('w')` — 0=Sunday .. 6=Saturday, matching PHP's DateTime::format('w'). */
export function dayOfWeek(d: Date): number {
  return d.getUTCDay();
}
