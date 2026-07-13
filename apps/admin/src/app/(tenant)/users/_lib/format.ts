/**
 * format.ts — tiny date/number formatting helpers mirroring the exact PHP
 * calls users.php/user-detail.php use. Both pages format every timestamp
 * with plain Gregorian `date('d/m/Y', ...)` / `date('d/m/Y H:i', ...)` —
 * neither page does Buddhist-era (+543) conversion anywhere (grepped for
 * '543'/'buddhist' in both files — zero hits), so this deliberately does
 * NOT reach for a Buddhist-calendar helper; there is nothing to convert.
 * `packages/core/dates` (plan §4.4) doesn't exist yet either way, and is
 * outside this batch's allowed paths.
 */

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

/** Mirrors PHP's `date('d/m/Y', strtotime($ts))`. */
export function formatDateDMY(value: Date | string | null | undefined): string {
  if (!value) {
    return '-';
  }
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) {
    return '-';
  }
  return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}/${d.getFullYear()}`;
}

/** yyyy-mm-dd — the shape an HTML `<input type="date">` needs for its `value`/`defaultValue`. */
export function formatDateISO(value: Date | string | null | undefined): string {
  if (!value) {
    return '';
  }
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) {
    return '';
  }
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
}

/** Mirrors PHP's `date('d/m/Y H:i', strtotime($ts))`. */
export function formatDateTimeDMY(value: Date | string | null | undefined): string {
  if (!value) {
    return '-';
  }
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) {
    return '-';
  }
  return `${formatDateDMY(d)} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

/** Mirrors PHP's `number_format($n)` (comma-grouped, no decimals). */
export function formatNumber(value: number | string | null | undefined): string {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? Math.trunc(n).toLocaleString('en-US') : '0';
}

/** Mirrors PHP's `number_format($n, 2)` (comma-grouped, 2 decimals) — used for baht amounts. */
export function formatMoney(value: number | string | null | undefined): string {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00';
}
