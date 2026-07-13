/**
 * phpCompat.ts — tiny helpers reproducing PHP semantics that api/member.php relies on for its
 * dynamic field handling (`?? ''`, `trim()`, `!empty()`). Kept intentionally minimal/local rather than
 * a shared package, per this batch's allowed-paths boundary.
 */

/** PHP's `empty($v)`: true for undefined/null/''/0/'0'/false (NOT for '0.0', non-zero numbers, etc). */
export function phpEmpty(value: unknown): boolean {
  if (value === undefined || value === null || value === false) return true;
  if (value === '' || value === 0 || value === '0') return true;
  return false;
}

/** `trim($data[$field] ?? '')` — coerces non-string inputs to string first (JSON bodies may carry numbers). */
export function strOrEmpty(value: unknown): string {
  if (typeof value === 'string') return value.trim();
  if (value === undefined || value === null) return '';
  return String(value).trim();
}

/** `!empty($data['x']) ? floatval($data['x']) : null` — note PHP's empty() treats "0"/0 as empty too. */
export function floatOrNull(value: unknown): number | null {
  return phpEmpty(value) ? null : Number(value);
}
