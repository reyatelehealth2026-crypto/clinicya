/**
 * format.ts — display constants/formatters matching
 * includes/pharmacy/pharmacists.php verbatim.
 */

/**
 * `$dayNames` (pharmacists.php line 133, duplicated client-side at line 366)
 * — index IS `pharmacist_schedules.day_of_week` (0=Sunday ... 6=Saturday,
 * per the column's own DB comment). Do not reorder/localize differently;
 * both the schedule-input labels and the schedule-preview badges key off
 * this exact index.
 */
export const DAY_NAMES_TH = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'] as const;

/** Mirrors PHP's `number_format($p['rating'] ?? 0, 1)` (line 171). */
export function formatRating(value: number): string {
  return value.toFixed(1);
}

/** Mirrors PHP's `number_format($p['consultation_fee'])` (line 179) — 0-decimal, comma-grouped baht. */
export function formatBaht(value: number): string {
  return Math.round(value).toLocaleString('en-US');
}

/**
 * Mirrors PHP's `$s.start_time.substring(0, 5)` (editPharmacist(), line 415)
 * — strips a DB TIME value's `:SS` seconds suffix so it fits an
 * `<input type="time">`'s `HH:MM` value shape.
 */
export function toHHMM(value: string): string {
  return value.slice(0, 5);
}

/**
 * Mirrors PHP's `date('Y-m-d')` (line 351, the holiday-date input's `min`
 * attribute) — PHP's MySQL/app timezone is forced to `Asia/Bangkok`
 * (+07:00, see CLAUDE.md), so "today" is computed in that timezone here
 * too rather than the browser's local timezone (this is a Client Component;
 * `new Date()` alone would use the visitor's local clock).
 */
export function todayBangkokISO(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Bangkok', year: 'numeric', month: '2-digit', day: '2-digit' }).format(
    new Date()
  );
}

/**
 * Mirrors PHP's client-side `date.toLocaleDateString('th-TH', {day:
 * 'numeric', month: 'short', year: 'numeric'})` (openHolidayModal(), line
 * 440) used to render each holiday chip's date. `th-TH`'s default calendar
 * is Buddhist, so this already renders the Buddhist-era year (+543) exactly
 * like the PHP page's own client-side JS does — no separate conversion
 * needed.
 */
export function formatHolidayDateTh(value: string): string {
  return new Date(value).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
}
