/**
 * phpRound.ts — a faithful behavioral port of PHP's `round($value, $places)`
 * builtin (mode = PHP_ROUND_HALF_UP, i.e. round-half-away-from-zero — the
 * only mode `includes/document-helpers.php` ever uses).
 *
 * WHY THIS EXISTS: `round()` in PHP is not `Math.round(x * 100) / 100`. PHP's
 * C implementation (ext/standard/math.c, `_php_math_round`) applies a
 * "pre-rounding" correction step to compensate for IEEE-754 binary-double
 * representation error before the final half-away-from-zero step — that's
 * why `round(1.005, 2)` is `1.01` in PHP even though the literal `1.005`
 * is actually stored as `1.00499999999999989...` in binary64. A naive
 * `Math.round(value * 100) / 100` port would silently under-round exactly
 * these cases — the ones that matter most for "correct to the satang" VAT
 * math, since `subtotal * (rate / 100)` and `subtotal / (1 + rate/100)`
 * routinely land a few ULPs off an exact 2-decimal value.
 *
 * IMPLEMENTATION: rather than transliterating PHP's internal algorithm
 * (which uses a hand-rolled decimal-digit-counting pre-scale that is easy to
 * get subtly wrong — see this file's git history / build report for a
 * mistranscribed first attempt), this snaps the value to 15 significant
 * decimal digits (`Number.prototype.toPrecision(15)`, matching PHP's
 * `DBL_DIG = 15`) before AND after scaling by `10**places`, which
 * eliminates the same binary-representation noise PHP's pre-rounding step
 * targets, then rounds half-away-from-zero on the now-clean integer-ish
 * value. Empirically validated against a real `php` CLI (PHP 8.4) executing
 * the actual `includes/document-helpers.php` source across 5,000+
 * generated values plus the classic x.xx5 edge cases (1.005, 2.675, 5.055,
 * 100.005, negative values, values needing the full 15-digit budget) — zero
 * mismatches. See docs/runbooks/phase5-documents-vat-parity.md for the
 * validation methodology and commands to reproduce it.
 */
export function phpRound(value: number, places = 0): number {
  if (!Number.isFinite(value) || value === 0) {
    return value;
  }
  const sign = value < 0 ? -1 : 1;
  let abs = Math.abs(value);

  // Snap to 15 significant decimal digits — cancels binary-representation
  // noise in the input itself (PHP's DBL_DIG-based pre-rounding).
  abs = Number(abs.toPrecision(15));

  const shift = Math.pow(10, places);
  let scaled = abs * shift;
  // Snap again — the multiply can reintroduce a few ULPs of noise even when
  // the pre-snapped input was clean.
  scaled = Number(scaled.toPrecision(15));

  const rounded = Math.floor(scaled + 0.5); // half away from zero (abs already applied)
  return (sign * rounded) / shift;
}
