import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * refillReminders.ts — port of `classes/DrugRecommendEngineService.php`'s
 * `getRefillReminders()` (lines 399-538) and `getRefillMessage()` (lines
 * 546-558), as driven by api/inbox-v2.php's `case 'refill_reminders': case
 * 'refill-reminders': case 'get_refill_reminders':` (lines ~1410-1438).
 *
 * ```php
 * public function getRefillReminders(int $userId): array
 * {
 *     $reminders = [];
 *     $usageDurations = ['chronic' => 30, 'antibiotic' => 7, 'vitamin' => 30, 'pain' => 14, 'default' => 30];
 *     try {
 *         $sql = "
 *             SELECT
 *                 bi.id as product_id, bi.name, bi.sku, bi.price, bi.stock, bi.image_url,
 *                 MAX(t.created_at) as last_purchase_date, SUM(ti.quantity) as total_quantity,
 *                 ic.name as category_name
 *             FROM transactions t
 *             JOIN transaction_items ti ON t.id = ti.transaction_id
 *             JOIN business_items bi ON ti.product_id = bi.id
 *             LEFT JOIN item_categories ic ON bi.category_id = ic.id
 *             WHERE t.user_id = ?
 *             AND t.status NOT IN ('cancelled', 'failed')
 *             AND t.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
 *             GROUP BY bi.id, bi.name, bi.sku, bi.price, bi.stock, bi.image_url, ic.name
 *             ORDER BY last_purchase_date DESC
 *         ";
 *         $stmt = $this->db->prepare($sql);
 *         $stmt->execute([$userId]);
 *         $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);
 *
 *         foreach ($purchases as $purchase) {
 *             $category = strtolower($purchase['category_name'] ?? '');
 *             $duration = $usageDurations['default'];
 *             foreach ($usageDurations as $key => $days) {
 *                 if (stripos($category, $key) !== false) { $duration = $days; break; }
 *             }
 *
 *             $lastPurchase = new DateTime($purchase['last_purchase_date']);
 *             $refillDate = clone $lastPurchase;
 *             $refillDate->modify("+{$duration} days");
 *
 *             $now = new DateTime();
 *             $daysUntilRefill = (int)$now->diff($refillDate)->format('%r%a');
 *
 *             if ($daysUntilRefill <= 7) {
 *                 $status = 'due'; $urgency = 'normal';
 *                 if ($daysUntilRefill < 0) { $status = 'overdue'; $urgency = 'high'; }
 *                 elseif ($daysUntilRefill <= 3) { $urgency = 'medium'; }
 *
 *                 $reminders[] = [
 *                     'productId' => $purchase['product_id'], 'name' => $purchase['name'], 'sku' => $purchase['sku'],
 *                     'price' => (float)$purchase['price'], 'stock' => (int)$purchase['stock'],
 *                     'imageUrl' => $purchase['image_url'], 'category' => $purchase['category_name'],
 *                     'lastPurchaseDate' => $purchase['last_purchase_date'],
 *                     'estimatedRefillDate' => $refillDate->format('Y-m-d'),
 *                     'daysUntilRefill' => $daysUntilRefill, 'status' => $status, 'urgency' => $urgency,
 *                     'usageDuration' => $duration, 'message' => $this->getRefillMessage($daysUntilRefill, $purchase['name'])
 *                 ];
 *             }
 *         }
 *
 *         usort($reminders, function($a, $b) {
 *             if ($a['status'] === 'overdue' && $b['status'] !== 'overdue') return -1;
 *             if ($a['status'] !== 'overdue' && $b['status'] === 'overdue') return 1;
 *             return $a['daysUntilRefill'] - $b['daysUntilRefill'];
 *         });
 *     } catch (PDOException $e) {
 *         error_log("DrugRecommendEngine getRefillReminders error: " . $e->getMessage());
 *         try {
 *             $sql = "SELECT ... FROM orders o JOIN order_items oi ... "; // fallback, orders table
 *             $stmt = $this->db->prepare($sql);
 *             $stmt->execute([$userId]);
 *             // Process similar to above...
 *         } catch (PDOException $e2) { // Return empty if both fail
 *         }
 *     }
 *
 *     return ['reminders' => $reminders, 'userId' => $userId, 'totalDue' => count($reminders)];
 * }
 *
 * private function getRefillMessage(int $daysUntilRefill, string $drugName): string
 * {
 *     if ($daysUntilRefill < 0) {
 *         return "ยา {$drugName} เลยกำหนดเติมแล้ว " . abs($daysUntilRefill) . " วัน";
 *     } elseif ($daysUntilRefill === 0) {
 *         return "ยา {$drugName} ถึงกำหนดเติมวันนี้";
 *     } elseif ($daysUntilRefill === 1) {
 *         return "ยา {$drugName} จะถึงกำหนดเติมพรุ่งนี้";
 *     } else {
 *         return "ยา {$drugName} จะถึงกำหนดเติมใน {$daysUntilRefill} วัน";
 *     }
 * }
 * ```
 *
 * No schema drift here — per this batch's confirmed scoping correction,
 * every column this query touches (`bi.id`/`name`/`sku`/`price`/`stock`/
 * `image_url`/`category_id`, `t.id`/`user_id`/`status`/`created_at`,
 * `ti.transaction_id`/`product_id`/`quantity`, `ic.id`/`name`) is confirmed
 * present in `packages/db/src/generated/tenant-db.d.ts`. This is a purely
 * literal port, no fix-forward deviation.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * `$now->diff($refillDate)->format('%r%a')` day-count semantics
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's `DateTime::diff()` computes the difference of `$refillDate` minus
 * `$this` (i.e. `$now`); `%r` emits `-` when `$refillDate` is BEFORE `$now`
 * (i.e. `invert=1`), empty otherwise; `%a` is the total whole-day count.
 * So: `refillDate` in the past (overdue) -> negative; `refillDate` today or
 * in the future -> zero or positive. Ported as `daysUntilRefillCount()`
 * below: `floor(abs(refillDateMs - nowMs) / 86_400_000)`, sign taken from
 * whether `refillDate < now`. Asia/Bangkok has no DST, so this straight
 * UTC-ms day-count matches PHP's calendar-day diff for the timestamps this
 * function ever handles (production/CI pin the process TZ to Asia/Bangkok
 * per CLAUDE.md).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * The PDOException fallback (orders/order_items) is a NO-OP — preserved as
 * such, not re-implemented
 * ═══════════════════════════════════════════════════════════════════════
 * PHP's outer `catch (PDOException $e)` re-queries a fallback
 * `orders`/`order_items` table pair, but its own comment
 * (`// Process similar to above...`) is followed by NOTHING — the fallback
 * query's results are never read into `$reminders`. So the faithful,
 * literal behavior is: ANY failure of the primary `transactions`-table
 * query yields `reminders: []`, full stop — regardless of whether a
 * fallback query would have found anything. This is a deliberately
 * preserved PHP quirk, not an oversight in this port. This port does not
 * even issue the inert fallback query: it has zero observable effect on
 * the return value (its result is discarded either way), so re-executing
 * an unused, side-effect-free `SELECT` against tables this codebase's
 * generated schema (`packages/db/src/generated/tenant-db.d.ts`) doesn't
 * even define an `orders`/`order_items` pair for is not worth the added
 * complexity or the extra round-trip.
 */

const USAGE_DURATIONS: readonly (readonly [string, number])[] = [
  ['chronic', 30],
  ['antibiotic', 7],
  ['vitamin', 30],
  ['pain', 14],
  ['default', 30],
];

/** PHP's `foreach ($usageDurations as $key => $days) { if (stripos($category, $key) !== false) ... }` loop, in insertion order, first match wins. */
function usageDurationForCategory(categoryName: string | null): number {
  const category = (categoryName ?? '').toLowerCase();
  let duration = 30; // $usageDurations['default']
  for (const [key, days] of USAGE_DURATIONS) {
    if (category.includes(key)) {
      duration = days;
      break;
    }
  }
  return duration;
}

/**
 * `$now->diff($refillDate)->format('%r%a')` — see module doc. `refillDate`
 * strictly before `now` -> negative; at or after `now` -> non-negative.
 */
function daysUntilRefillCount(refillDate: Date, now: Date): number {
  const diffMs = Math.abs(refillDate.getTime() - now.getTime());
  const days = Math.floor(diffMs / 86_400_000);
  return refillDate.getTime() < now.getTime() ? -days : days;
}

/** `YYYY-MM-DD HH:MM:SS` in local wall-clock — see `../../messages/_lib/query.ts`. */
function toMysqlDateTimeString(value: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

/** `YYYY-MM-DD` in local wall-clock — PHP's `$refillDate->format('Y-m-d')`. */
function toDateOnlyString(value: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
}

/** PHP `(float) $v` — non-numeric/null/undefined -> 0, never NaN. */
function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** PHP `(int) $v` — non-numeric/null/undefined -> 0. */
function toIntOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? Math.trunc(n) : 0;
}

/** Ports `getRefillMessage()` exactly (Thai strings verbatim). */
export function getRefillMessage(daysUntilRefill: number, drugName: string): string {
  if (daysUntilRefill < 0) {
    const overdueDays = Math.abs(daysUntilRefill);
    return `ยา ${drugName} เลยกำหนดเติมแล้ว ${overdueDays} วัน`;
  } else if (daysUntilRefill === 0) {
    return `ยา ${drugName} ถึงกำหนดเติมวันนี้`;
  } else if (daysUntilRefill === 1) {
    return `ยา ${drugName} จะถึงกำหนดเติมพรุ่งนี้`;
  } else {
    return `ยา ${drugName} จะถึงกำหนดเติมใน ${daysUntilRefill} วัน`;
  }
}

interface RefillPurchaseRow {
  product_id: number;
  name: string;
  sku: string | null;
  price: unknown;
  stock: unknown;
  image_url: string | null;
  last_purchase_date: Date;
  total_quantity: unknown;
  category_name: string | null;
}

export interface RefillReminder {
  productId: number;
  name: string;
  sku: string | null;
  price: number;
  stock: number;
  imageUrl: string | null;
  category: string | null;
  lastPurchaseDate: string;
  estimatedRefillDate: string;
  daysUntilRefill: number;
  status: 'due' | 'overdue';
  urgency: 'normal' | 'medium' | 'high';
  usageDuration: number;
  message: string;
}

export interface RefillRemindersResult {
  reminders: RefillReminder[];
  userId: number;
  totalDue: number;
}

export async function getRefillReminders(db: Kysely<TenantDB>, userId: number): Promise<RefillRemindersResult> {
  const reminders: RefillReminder[] = [];

  try {
    const result = await sql<RefillPurchaseRow>`
      SELECT
        bi.id as product_id,
        bi.name,
        bi.sku,
        bi.price,
        bi.stock,
        bi.image_url,
        MAX(t.created_at) as last_purchase_date,
        SUM(ti.quantity) as total_quantity,
        ic.name as category_name
      FROM transactions t
      JOIN transaction_items ti ON t.id = ti.transaction_id
      JOIN business_items bi ON ti.product_id = bi.id
      LEFT JOIN item_categories ic ON bi.category_id = ic.id
      WHERE t.user_id = ${userId}
      AND t.status NOT IN ('cancelled', 'failed')
      AND t.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
      GROUP BY bi.id, bi.name, bi.sku, bi.price, bi.stock, bi.image_url, ic.name
      ORDER BY last_purchase_date DESC
    `.execute(db);

    const now = new Date();

    for (const purchase of result.rows) {
      const duration = usageDurationForCategory(purchase.category_name);
      const lastPurchase = purchase.last_purchase_date;
      const refillDate = new Date(lastPurchase.getTime() + duration * 86_400_000);
      const daysUntilRefill = daysUntilRefillCount(refillDate, now);

      if (daysUntilRefill <= 7) {
        let status: 'due' | 'overdue' = 'due';
        let urgency: 'normal' | 'medium' | 'high' = 'normal';

        if (daysUntilRefill < 0) {
          status = 'overdue';
          urgency = 'high';
        } else if (daysUntilRefill <= 3) {
          urgency = 'medium';
        }

        reminders.push({
          productId: purchase.product_id,
          name: purchase.name,
          sku: purchase.sku,
          price: toFloatOrZero(purchase.price),
          stock: toIntOrZero(purchase.stock),
          imageUrl: purchase.image_url,
          category: purchase.category_name,
          lastPurchaseDate: toMysqlDateTimeString(lastPurchase),
          estimatedRefillDate: toDateOnlyString(refillDate),
          daysUntilRefill,
          status,
          urgency,
          usageDuration: duration,
          message: getRefillMessage(daysUntilRefill, purchase.name),
        });
      }
    }

    reminders.sort((a, b) => {
      if (a.status === 'overdue' && b.status !== 'overdue') return -1;
      if (a.status !== 'overdue' && b.status === 'overdue') return 1;
      return a.daysUntilRefill - b.daysUntilRefill;
    });
  } catch {
    // PHP's own catch(PDOException) + inert fallback-to-orders-table query — see module doc: null-effect, reminders stays [].
  }

  return { reminders, userId, totalDue: reminders.length };
}
