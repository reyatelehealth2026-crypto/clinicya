/**
 * format.ts — standalone local copy of the date/number formatting helpers
 * shop/order-detail.php uses, matching the exact PHP calls (`date('d/m/Y
 * H:i', ...)`, `number_format($n, 2)`). Same duplication rationale as
 * `(tenant)/users/_lib/format.ts`'s own doc comment: order-detail.php does
 * plain Gregorian `date()` everywhere (grepped for '543'/'buddhist' — zero
 * hits), so there is no Buddhist-era conversion to reproduce here either.
 * Kept local rather than imported from `../../users/_lib/format` or
 * `../../orders/_lib/*` — this batch's allowed-paths boundary keeps
 * order-detail and every other page directory fully disjoint.
 */

function pad2(n: number): string {
  return String(n).padStart(2, '0');
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
  return `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}/${d.getFullYear()} ${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
}

/** Mirrors PHP's `number_format($n, 2)` (comma-grouped, 2 decimals) — used for baht amounts. */
export function formatMoney(value: number | string | null | undefined): string {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00';
}

/** Mirrors PHP's `number_format($n)` (comma-grouped, no decimals). */
export function formatMoney0(value: number | string | null | undefined): string {
  const n = Number(value ?? 0);
  return Number.isFinite(n) ? Math.round(n).toLocaleString('en-US') : '0';
}
