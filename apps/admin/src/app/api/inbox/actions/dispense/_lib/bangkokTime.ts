/**
 * bangkokTime.ts — Asia/Bangkok wall-clock helpers for `case 'dispense':`'s order-number
 * generation (`'DIS' . date('ymdHis') . rand(100, 999)` / `'TXN' . date('YmdHis') .
 * rand(100, 999)`, inbox-v2.php lines 485, 609) and RefillTrackingHelper's day-supply
 * arithmetic (`date('Y-m-d', strtotime("+N days"))`, classes/RefillTrackingHelper.php).
 * CLAUDE.md: "Timezone is always Asia/Bangkok (+07:00)" — PHP's `date()`/`strtotime()` calls
 * run under that server-configured default timezone; this file reproduces the same wall-clock
 * arithmetic explicitly via `Intl.DateTimeFormat`'s `timeZone` option rather than assuming the
 * Node process itself runs in +07:00 — same established convention as
 * apps/admin/src/app/api/miniapp/checkout/order/_lib/bangkokTime.ts and
 * apps/admin/src/app/api/miniapp/appointments/_lib/bangkokTime.ts (mirrored technique, not
 * imported — those live in a different builder's lane; this batch's allowed-paths boundary
 * keeps this folder independently editable).
 */

const BANGKOK_TIME_ZONE = 'Asia/Bangkok';

interface BangkokParts {
  yyyy: string;
  yy: string;
  mm: string;
  dd: string;
  hh: string;
  mi: string;
  ss: string;
}

function bangkokParts(now: Date): BangkokParts {
  // hourCycle: 'h23' (not just hour12: false) avoids a known ICU quirk where hour12:false alone
  // can render midnight as "24" instead of "00" on some Node/ICU builds — PHP's date('H') is
  // always zero-padded 00-23.
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
  const yyyy = get('year');
  return { yyyy, yy: yyyy.slice(-2), mm: get('month'), dd: get('day'), hh: get('hour'), mi: get('minute'), ss: get('second') };
}

/** `date('ymdHis')` in Asia/Bangkok — e.g. '260714153045' (12 digits: 2-digit year..second). */
export function bangkokYmdHisShort(now: Date = new Date()): string {
  const p = bangkokParts(now);
  return `${p.yy}${p.mm}${p.dd}${p.hh}${p.mi}${p.ss}`;
}

/** `date('YmdHis')` in Asia/Bangkok — e.g. '20260714153045' (14 digits: 4-digit year..second). */
export function bangkokYmdHisLong(now: Date = new Date()): string {
  const p = bangkokParts(now);
  return `${p.yyyy}${p.mm}${p.dd}${p.hh}${p.mi}${p.ss}`;
}

/** `date('Y-m-d')` ("today") in Asia/Bangkok — e.g. '2026-07-14'. */
export function bangkokTodayYmd(now: Date = new Date()): string {
  const p = bangkokParts(now);
  return `${p.yyyy}-${p.mm}-${p.dd}`;
}

/**
 * Adds `days` (may be negative) to a 'YYYY-MM-DD' string, returning another 'YYYY-MM-DD' string.
 * Pure calendar math via a pseudo-UTC instant, no timezone involved beyond the initial string —
 * mirrors PHP's `date('Y-m-d', strtotime("$dateStr +N days"))`.
 */
export function addDaysToYmd(dateStr: string, days: number): string {
  const d = new Date(`${dateStr}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + days);
  return d.toISOString().slice(0, 10);
}
