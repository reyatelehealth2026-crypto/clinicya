/**
 * phpCompat.ts — tiny helpers reproducing PHP semantics api/appointments.php relies on
 * (`?? ''`, `empty()`, `intval()`). Kept intentionally minimal/local rather than a shared package, per
 * this batch's allowed-paths boundary (mirrors member's/wishlist's own local phpCompat.ts, not
 * imported cross-route).
 */

/** PHP's `empty($v)`: true for undefined/null/''/0/'0'/false (NOT for '0.0', non-zero numbers, etc). */
export function phpEmpty(value: unknown): boolean {
  if (value === undefined || value === null || value === false) return true;
  if (value === '' || value === 0 || value === '0') return true;
  return false;
}

/** `$data['x'] ?? ''` — coerces non-string inputs to string first (JSON bodies may carry numbers). */
export function strOrEmpty(value: unknown): string {
  if (typeof value === 'string') return value;
  if (value === undefined || value === null) return '';
  return String(value);
}

/** `intval($v)` — PHP's loose int cast: non-numeric strings become 0, not NaN. */
export function intval(value: unknown): number {
  if (typeof value === 'number') return Math.trunc(value);
  if (typeof value === 'string') {
    const parsed = Number.parseInt(value, 10);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}
