/**
 * phpCompat.ts — tiny helpers reproducing PHP semantics api/health-profile.php's write handlers rely
 * on (`?? ''`, `isset()`, `empty()`, `intval()`/`floatval()`). Kept intentionally minimal/local rather
 * than a shared package — mirrors member's/appointments'/wishlist's own local phpCompat.ts, not
 * imported cross-route, per this batch's allowed-paths boundary. This file is NEW (parallel to the
 * existing, untouched `_lib/query.ts`, which never needed loose-cast helpers for the read-only `get`
 * action).
 */

/** PHP's `isset($v)`: false for both a missing key AND an explicit null value (NOT false for '', 0, false). */
export function isset(value: unknown): boolean {
  return value !== undefined && value !== null;
}

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

/** `floatval($v)` — PHP's loose float cast: non-numeric strings become 0.0, not NaN. */
export function floatval(value: unknown): number {
  if (typeof value === 'number') return value;
  if (typeof value === 'string') {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}
