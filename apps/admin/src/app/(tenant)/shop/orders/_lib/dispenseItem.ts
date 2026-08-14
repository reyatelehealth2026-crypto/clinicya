/**
 * _lib/dispenseItem.ts — pure formatting helpers for shop/orders.php's
 * '?view=dispense' read-only tab (lines 749-844), ported per-line-item.
 * These are read-only display helpers — this batch's brief is explicit that
 * NO write action for dispensing originates on this page (that flow lives
 * in messages.php/inbox-v2.php, untouched, Phase 5 territory).
 */

export interface DispenseLineItem {
  name: string;
  /** Printed RAW in the "จำนวน: X unit" line (PHP line 792 — no `??` default there at all), unlike the subtotal calc below, which DOES default a missing qty to 1. */
  qty?: number | string;
  unit?: string;
  isMedicine?: boolean;
  usageType?: string;
  indication?: string;
  dosage?: number | string;
  dosageUnit?: string;
  frequency?: string;
  mealTiming?: string;
  timeOfDay?: string[];
  notes?: string;
  price?: number | string;
}

const MEAL_TEXT: Record<string, string> = { before: 'ก่อนอาหาร', after: 'หลังอาหาร', with: 'พร้อมอาหาร' };
const TIME_ICONS: Record<string, string> = { morning: '🌅', noon: '☀️', evening: '🌆', bedtime: '🌙' };
const PAYMENT_TEXT: Record<string, string> = { cash: '💵 เงินสด', transfer: '📱 โอนเงิน', credit: '💳 บัตรเครดิต', later: '⏰ จ่ายทีหลัง' };

/**
 * `!empty($item['isMedicine']) && $item['isMedicine'] !== false` (line 786)
 * — PHP's own `!empty()` already excludes `false` (along with 0/''/null/
 * []), so the trailing `&& !== false` is dead/redundant in PHP itself; this
 * collapses to a plain truthy check.
 */
export function isMedicineItem(item: DispenseLineItem): boolean {
  return !!item.isMedicine;
}

/** Mirrors line 787: `($item['usageType'] ?? 'internal') === 'external' ? '🧴' : '💊'`, else '📦' for a non-medicine item. */
export function medicineIcon(item: DispenseLineItem): string {
  if (!isMedicineItem(item)) return '📦';
  return (item.usageType ?? 'internal') === 'external' ? '🧴' : '💊';
}

/** Mirrors lines 800-804: `$freq = $item['frequency'] ?? '3'; echo $freq === 'prn' ? 'เมื่อมีอาการ' : $freq . ' ครั้ง/วัน';`. */
export function frequencyText(item: DispenseLineItem): string {
  const freq = item.frequency ?? '3';
  return freq === 'prn' ? 'เมื่อมีอาการ' : `${freq} ครั้ง/วัน`;
}

/** Mirrors line 810: `$mealText[$item['mealTiming'] ?? 'after'] ?? 'หลังอาหาร'`. */
export function mealTimingText(item: DispenseLineItem): string {
  const timing = item.mealTiming ?? 'after';
  return MEAL_TEXT[timing] ?? 'หลังอาหาร';
}

/** Mirrors line 813: `implode(' ', array_map(fn($t) => $timeIcons[$t] ?? '', $item['timeOfDay']))`, only rendered when timeOfDay is non-empty (line 812's `!empty()` guard). */
export function timeOfDayIcons(item: DispenseLineItem): string {
  if (!item.timeOfDay || item.timeOfDay.length === 0) return '';
  return item.timeOfDay.map((t) => TIME_ICONS[t] ?? '').join(' ');
}

/** Mirrors line 836: `$paymentText[$record['payment_method']] ?? htmlspecialchars($record['payment_method'])`. */
export function paymentMethodText(paymentMethod: string | null): string {
  if (!paymentMethod) return '';
  return PAYMENT_TEXT[paymentMethod] ?? paymentMethod;
}

/** Mirrors line 824: `($item['price'] ?? 0) * ($item['qty'] ?? 1)`. Non-numeric price/qty coerce to 0 here — PHP8 would throw a TypeError multiplying a genuinely non-numeric string; real dispense-written data is always numeric (messages.php/inbox-v2.php's own writer), so this is a defensive simplification for malformed data, not a literal PHP-quirk preservation. */
export function itemSubtotal(item: DispenseLineItem): number {
  const price = Number(item.price ?? 0);
  const qty = Number(item.qty ?? 1);
  return (Number.isFinite(price) ? price : 0) * (Number.isFinite(qty) ? qty : 0);
}

/**
 * Mirrors line 758: `json_decode($record['items'], true) ?: []` — PHP's
 * Elvis operator treats a falsy decode result (null on malformed JSON, or
 * any falsy value including an empty array/object) as `[]`. A non-array
 * (e.g. a bare JSON object with keys, which PHP's `true`-flag json_decode
 * turns into a non-empty associative array — truthy, NOT replaced by
 * PHP's own `?:`) is a real PHP foreach-crash edge case for malformed data
 * that never occurs from this codebase's own dispense-writer (messages.php/
 * inbox-v2.php always encodes a JSON array here); this port defensively
 * normalizes any non-array decode result to `[]` too, rather than
 * reproducing that crash risk.
 */
export function parseDispenseItems(raw: string | null): DispenseLineItem[] {
  if (!raw) return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? (parsed as DispenseLineItem[]) : [];
  } catch {
    return [];
  }
}
