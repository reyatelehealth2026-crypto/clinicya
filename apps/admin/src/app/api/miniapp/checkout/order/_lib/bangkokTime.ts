/**
 * bangkokTime.ts — the one Asia/Bangkok wall-clock helper this route needs: `date('Ymd')` for
 * createOrder.ts's order_number generation (`'TXN' . date('Ymd') . str_pad(mt_rand(1,9999), 4, '0',
 * STR_PAD_LEFT)`, api/checkout.php L1431). CLAUDE.md: "Timezone is always Asia/Bangkok (+07:00)" — PHP's
 * `date()` call runs under that server-configured default timezone.
 *
 * packages/core (the migration plan's intended home for a shared Bangkok-time helper, per
 * docs/plans/2026-07-12-nextjs-full-migration-plan.md §1.1) does not exist yet on this branch — this file
 * is a local, self-contained copy, same established per-route-folder convention as
 * appointments/_lib/bangkokTime.ts::todayInBangkok() (mirrored technique, not imported — that file lives
 * in a different builder's lane).
 */

const BANGKOK_TIME_ZONE = 'Asia/Bangkok';

/** `date('Ymd')` in Asia/Bangkok — e.g. '20260714'. */
export function bangkokYmd(now: Date = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: BANGKOK_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(now);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '00';
  return `${get('year')}${get('month')}${get('day')}`;
}
