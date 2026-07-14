/**
 * phpCompat.ts — tiny helpers reproducing PHP semantics that api/checkout.php's create_order/upload_slip
 * handlers rely on (`?? null`, `!$v` truthiness, `(float)`/`(int)` casts). Kept intentionally minimal and
 * local to this route folder rather than a shared package — same established precedent as
 * member/_lib/phpCompat.ts, health-profile/_lib/phpCompat.ts, appointments/_lib/phpCompat.ts (each keeps
 * its own copy); checkout/cart/** and checkout/pricing/** are a different builder's lane and out of this
 * route's allowed-paths, so their near-identical helpers are deliberately NOT imported from here either.
 */

/** PHP `(string) $v` — coerces non-string inputs, `null`/`undefined` -> `''`. */
export function strOrEmpty(value: unknown): string {
  if (typeof value === 'string') return value;
  if (value === undefined || value === null) return '';
  return String(value);
}

/** PHP `!$v` truthiness check for the scalar shapes this route ever sees (string/number/null/undefined/false). */
export function phpFalsy(value: unknown): boolean {
  if (value === undefined || value === null || value === false) return true;
  if (value === '' || value === 0 || value === '0') return true;
  return false;
}

/** PHP `(float) $v` — non-numeric strings/null/'' -> 0, matching PHP's lenient numeric cast (never NaN). */
export function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** PHP `(int) $v`, loosely — leading numeric parse, else 0. Sufficient for JSON-body product_id/quantity (always literal numbers or numeric strings in real traffic). */
export function toIntOrZero(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? Math.trunc(value) : 0;
  if (typeof value === 'string') {
    const n = Number.parseInt(value, 10);
    return Number.isFinite(n) ? n : 0;
  }
  return 0;
}

/** PHP `$a ?? $b` — null coalescing: returns `value` unless it's null/undefined (unlike `??=`/empty checks, `''`/`0` pass through unchanged). */
export function coalesce<T>(value: T | null | undefined, fallback: T): T {
  return value !== undefined && value !== null ? value : fallback;
}
