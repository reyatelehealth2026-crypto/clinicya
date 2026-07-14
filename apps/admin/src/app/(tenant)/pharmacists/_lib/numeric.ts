/**
 * numeric.ts — tiny local coercion helper. mysql2 returns DECIMAL columns
 * (rating, consultation_fee) and correlated-subquery COUNT(*) columns as
 * either JS numbers or numeric strings depending on driver config; PHP's PDO
 * does the analogous implicit numeric coercion every time
 * `$p['rating'] ?? 0` / `$p['consultation_fee'] ?? 0` gets used in
 * number_format()/arithmetic in includes/pharmacy/pharmacists.php.
 *
 * NOT imported from (tenant)/dashboard/_lib/numeric.ts, which has the exact
 * same shape — that file sits outside this batch's allowed paths (owns only
 * apps/admin/src/app/(tenant)/pharmacists/**), so a same-shaped helper is
 * duplicated here rather than reached for across a route-tree boundary that
 * isn't in the read-only reference list either.
 */
export function toNumber(value: unknown): number {
  if (value === null || value === undefined) {
    return 0;
  }
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}
