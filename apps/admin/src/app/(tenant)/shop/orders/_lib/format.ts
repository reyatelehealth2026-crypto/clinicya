/**
 * format.ts — display formatters ported from shop/orders.php's own inline
 * `number_format()`/`date()` calls (lines 183-185, 662-663, 668, 770, 777).
 *
 * NOTE on Buddhist dates: shop/orders.php renders every timestamp with
 * plain Gregorian `date('d/m/Y H:i', strtotime(...))` — it never converts to
 * a Buddhist-era year anywhere in the file (contrast with pages that DO,
 * e.g. includes/document-helpers.php's formatThaiDate(), ported to
 * packages/core/dates' thaiDate.ts). There is nothing to port from
 * packages/core/dates for THIS page; formatOrderDateTime() below
 * deliberately stays Gregorian to match the PHP source exactly.
 */

/** Mirrors PHP's `number_format($value, 2)` — comma-grouped, always 2 decimals. Accepts a Decimal column's string form or a plain number. */
export function formatMoney2(value: string | number): string {
  const n = typeof value === 'string' ? Number(value) : value;
  return (Number.isFinite(n) ? n : 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Mirrors PHP's `date('d/m/Y H:i', strtotime($value))` under the server's
 * forced Asia/Bangkok timezone (CLAUDE.md: "Timezone is always
 * Asia/Bangkok"). `value` is a DB DATETIME string ("YYYY-MM-DD HH:MM:SS",
 * Asia/Bangkok local, no offset) or a Date already hydrated by Kysely —
 * both are normalized to Asia/Bangkok wall-clock output either way.
 */
export function formatOrderDateTime(value: string | Date): string {
  const date = value instanceof Date ? value : parseBangkokLocalDateTime(value);
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Bangkok',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(date);
  const get = (type: Intl.DateTimeFormatPartTypes) => parts.find((p) => p.type === type)?.value ?? '';
  return `${get('day')}/${get('month')}/${get('year')} ${get('hour')}:${get('minute')}`;
}

/** A bare "YYYY-MM-DD HH:MM:SS" (or ISO-ish) string with no explicit zone is Asia/Bangkok local — same convention @reya/line's parseTokenExpiryMs() documents. */
function parseBangkokLocalDateTime(value: string): Date {
  const trimmed = value.trim();
  if (/(?:[zZ]|[+-]\d{2}:?\d{2})$/.test(trimmed)) {
    return new Date(trimmed);
  }
  const isoLike = trimmed.includes('T') ? trimmed : trimmed.replace(' ', 'T');
  return new Date(`${isoLike}+07:00`);
}
