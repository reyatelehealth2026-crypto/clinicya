import { phpRound } from './phpRound';

/**
 * vat.ts — TypeScript port of `includes/document-helpers.php`'s
 * `calcVAT()` (lines 123-140), `computeLineTotal()` (lines 219-227), and
 * `formatMoney()` (lines 209-212).
 */

export interface VATResult {
  base: number;
  vat: number;
  total: number;
}

/**
 * Port of `calcVAT(float $subtotal, float $vatRate = 7.00, bool $vatInclusive = false): array`.
 *
 * ```php
 * function calcVAT(float $subtotal, float $vatRate = 7.00, bool $vatInclusive = false): array
 * {
 *     $rate = max(0.0, $vatRate) / 100.0;
 *     if ($vatInclusive) {
 *         $base = $subtotal / (1 + $rate);
 *         $vat  = $subtotal - $base;
 *         $total = $subtotal;
 *     } else {
 *         $base = $subtotal;
 *         $vat  = $subtotal * $rate;
 *         $total = $subtotal + $vat;
 *     }
 *     return ['base' => round($base, 2), 'vat' => round($vat, 2), 'total' => round($total, 2)];
 * }
 * ```
 *
 * @param subtotal Pre-VAT base (or VAT-inclusive total when `vatInclusive=true`)
 * @param vatRate Percent, e.g. 7.00. Negative rates are clamped to 0 (`max(0.0, $vatRate)`).
 * @param vatInclusive When true, `subtotal` already includes VAT — back-calc it out.
 */
export function calcVAT(subtotal: number, vatRate = 7.0, vatInclusive = false): VATResult {
  const rate = Math.max(0.0, vatRate) / 100.0;
  let base: number;
  let vat: number;
  let total: number;
  if (vatInclusive) {
    base = subtotal / (1 + rate);
    vat = subtotal - base;
    total = subtotal;
  } else {
    base = subtotal;
    vat = subtotal * rate;
    total = subtotal + vat;
  }
  return {
    base: phpRound(base, 2),
    vat: phpRound(vat, 2),
    total: phpRound(total, 2),
  };
}

/**
 * Port of `computeLineTotal(float $qty, float $unitPrice, float $discountPercent = 0.0, float $discountAmount = 0.0): float`.
 *
 * ```php
 * function computeLineTotal(float $qty, float $unitPrice, float $discountPercent = 0.0, float $discountAmount = 0.0): float
 * {
 *     $gross = $qty * $unitPrice;
 *     if ($discountPercent > 0) {
 *         $gross -= $gross * ($discountPercent / 100.0);
 *     }
 *     $gross -= $discountAmount;
 *     return round(max(0.0, $gross), 2);
 * }
 * ```
 *
 * `line_total = (qty * unit_price) * (1 - discount_percent/100) - discount_amount`,
 * floored at 0, rounded to 2dp.
 */
export function computeLineTotal(qty: number, unitPrice: number, discountPercent = 0.0, discountAmount = 0.0): number {
  let gross = qty * unitPrice;
  if (discountPercent > 0) {
    gross -= gross * (discountPercent / 100.0);
  }
  gross -= discountAmount;
  return phpRound(Math.max(0.0, gross), 2);
}

/**
 * Port of `formatMoney(float $amount): string` — `number_format($amount, 2, '.', ',')`.
 * Thai-locale-style thousands separator, 2 decimal places, `.` as the
 * decimal point. NOT locale-sensitive (PHP's `number_format` isn't either).
 */
export function formatMoney(amount: number): string {
  const rounded = phpRound(amount, 2);
  const sign = rounded < 0 ? '-' : '';
  const abs = Math.abs(rounded);
  // Fix to 2dp as a string first (avoids toLocaleString's own env-dependent
  // rounding/grouping behavior), then group the integer part with commas.
  const fixed = abs.toFixed(2);
  const [intPart, fracPart] = fixed.split('.');
  const grouped = (intPart ?? '0').replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return `${sign}${grouped}.${fracPart}`;
}
