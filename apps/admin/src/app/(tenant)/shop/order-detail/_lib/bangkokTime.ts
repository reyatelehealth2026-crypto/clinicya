/**
 * bangkokTime.ts — the one Asia/Bangkok wall-clock helper this route needs:
 * `date('d/m/Y H:i')` for buildOrderStatusFlex()'s "📅 " date line
 * (`'📅 ' . date('d/m/Y H:i')`, shop/order-detail.php line 88). CLAUDE.md:
 * "Timezone is always Asia/Bangkok (+07:00)" — PHP's `date()` call runs under
 * that server-configured default timezone; this file reproduces the same
 * wall-clock formatting explicitly via `Intl.DateTimeFormat`'s `timeZone`
 * option rather than assuming the Node process itself runs in +07:00 — same
 * established per-route-folder convention as
 * apps/admin/src/app/api/inbox/actions/dispense/_lib/bangkokTime.ts,
 * .../miniapp/checkout/order/_lib/bangkokTime.ts, and
 * .../miniapp/appointments/_lib/bangkokTime.ts (mirrored technique, not
 * imported — those live in other builders' lanes; this batch's
 * allowed-paths boundary keeps this folder independently editable).
 */

const BANGKOK_TIME_ZONE = 'Asia/Bangkok';

/**
 * `date('d/m/Y H:i')` in Asia/Bangkok — e.g. '14/08/2026 20:15'. All
 * components zero-padded to match PHP's `d`/`m`/`H`/`i` format chars exactly.
 */
export function bangkokDdMmYyyyHm(now: Date = new Date()): string {
  // hourCycle: 'h23' (not just hour12: false) avoids a known ICU quirk where
  // hour12:false alone can render midnight as "24" instead of "00" on some
  // Node/ICU builds — PHP's date('H') is always zero-padded 00-23.
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: BANGKOK_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(now);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '00';
  return `${get('day')}/${get('month')}/${get('year')} ${get('hour')}:${get('minute')}`;
}
