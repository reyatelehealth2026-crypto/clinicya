/**
 * numeric.ts — tiny shared coercion helper. mysql2 returns DECIMAL/AVG/SUM
 * aggregate columns as strings (to avoid float precision loss) and COUNT()
 * as JS numbers; PHP's PDO does the analogous implicit numeric coercion
 * every time `$row['x'] ?? 0` gets used in arithmetic/number_format(). This
 * normalises either shape (and SQL NULL, e.g. AVG() over zero rows) to a
 * plain JS number, defaulting to 0 exactly like the `?? 0` fallbacks
 * throughout executive.php/crm.php.
 */
export function toNumber(value: unknown): number {
  if (value === null || value === undefined) {
    return 0;
  }
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}
