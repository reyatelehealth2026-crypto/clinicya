/**
 * phpCompat.ts — tiny helpers reproducing PHP semantics `case 'dispense':` (inbox-v2.php
 * lines 469-736) relies on (`intval()`, `floatval()`, `trim($v ?? '')`, `empty()`/`!empty()`,
 * `mt_rand()`). Kept intentionally minimal and local to this route folder rather than a shared
 * package — same established precedent as
 * apps/admin/src/app/api/miniapp/checkout/order/_lib/phpCompat.ts,
 * apps/admin/src/app/api/miniapp/member/_lib/phpCompat.ts, etc. (each keeps its own copy); this
 * batch's allowed-paths boundary (dispenseChain owns
 * apps/admin/src/app/api/inbox/actions/dispense/** exclusively) keeps this folder independently
 * editable without reaching into another builder's lane. Shared across every _lib/*.ts file in
 * THIS action family (dispense.ts, refillTracking.ts, checkoutUrl.ts, flexSend.ts) — sharing
 * within one action family is fine; only cross-family imports are disallowed by convention.
 */

/** PHP's `intval($v)` — loose int cast: non-numeric strings become 0, not NaN. */
export function intval(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? Math.trunc(value) : 0;
  if (typeof value === 'string') {
    const match = /^\s*[+-]?\d+/.exec(value);
    return match ? Number.parseInt(match[0], 10) : 0;
  }
  return 0;
}

/** PHP's `floatval($v)` — leading-numeric parse, non-numeric/null/undefined -> 0 (never NaN). */
export function floatval(value: unknown): number {
  if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
  if (typeof value === 'string') {
    const n = Number.parseFloat(value);
    return Number.isFinite(n) ? n : 0;
  }
  return 0;
}

/** `trim($v ?? '')` — coerces non-string inputs to string first (JSON bodies may carry non-strings). */
export function trimOrEmpty(value: unknown): string {
  if (typeof value === 'string') return value.trim();
  if (value === undefined || value === null) return '';
  return String(value).trim();
}

/** PHP's `empty($v)`: true for undefined/null/''/0/'0'/false/[] (NOT for '0.0', non-zero numbers, non-empty arrays). */
export function phpEmpty(value: unknown): boolean {
  if (value === undefined || value === null || value === false) return true;
  if (value === '' || value === 0 || value === '0') return true;
  if (Array.isArray(value) && value.length === 0) return true;
  return false;
}

/** Mirrors PHP's `!empty($x)` / bare `if ($x)` truthiness check. */
export function phpTruthy(value: unknown): boolean {
  return !phpEmpty(value);
}

/** PHP's `mt_rand($min, $max)` — uniformly random integer in `[min, max]` inclusive. */
export function mtRand(min: number, max: number): number {
  return min + Math.floor(Math.random() * (max - min + 1));
}

/** PHP's `$value ?: $fallback` (Elvis operator) — returns `value` when it's `!empty()`-truthy, else `fallback`. */
export function phpElvis<T>(value: T, fallback: T): T {
  return phpTruthy(value) ? value : fallback;
}
