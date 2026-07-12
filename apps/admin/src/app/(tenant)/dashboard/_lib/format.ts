/**
 * format.ts — display formatters matching PHP's `number_format()` (comma
 * thousands separator, 0 decimals by default, rounds — not truncates).
 */
export function formatNumber(value: number): string {
  return Math.round(value).toLocaleString('en-US');
}

/** Port of `'฿' . number_format($orderStats['revenue'] ?? 0)`. */
export function formatBaht(value: number): string {
  return `฿${formatNumber(value)}`;
}
