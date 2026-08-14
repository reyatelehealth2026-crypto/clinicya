import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { DispenseItem } from './types';
import { addDaysToYmd, bangkokTodayYmd } from './bangkokTime';
import { intval, phpTruthy } from './phpCompat';

/**
 * refillTracking.ts — port of classes/RefillTrackingHelper.php's `parsePackSize()` and
 * `trackFromDispense()` ONLY, against the already-existing `medication_refill_tracking` table
 * (packages/db/src/generated/tenant-db.d.ts's `MedicationRefillTracking` interface confirms it's
 * already on the committed generated schema). `ensureTable()`'s
 * `CREATE TABLE IF NOT EXISTS medication_refill_tracking (...)` is deliberately NOT ported —
 * CLAUDE.md's "Auto-create tables" convention prefers a versioned migration over page-load
 * auto-create for new features, and the table already exists on the committed schema, so there
 * is nothing to auto-create here.
 *
 * Called from dispense.ts wrapped in try/catch-and-continue (mirrors inbox-v2.php lines
 * 635-645, which wrap the whole `RefillTrackingHelper::trackFromDispense(...)` call in its own
 * try/catch on top of this function's own per-item try/catch below — belt and suspenders,
 * replicated faithfully rather than "simplified" to a single layer).
 */

/**
 * แยก pack_size จาก unit string เช่น "1 กล่อง[50เม็ด]" → 50. รองรับรูปแบบ [N], [Nเม็ด], [Nซีซี],
 * [Nแคปซูล] ฯลฯ. คืน 1 ถ้าไม่พบตัวเลข (เช่น unit ว่าง หรือ "ชิ้น"). Port of
 * `RefillTrackingHelper::parsePackSize()`.
 */
export function parsePackSize(unit: string): number {
  if (unit === '') {
    return 1;
  }
  const match = /\[(\d+)/.exec(unit);
  if (match) {
    // match[1] is the capture group — guaranteed present whenever the overall pattern matches
    // (the regex has no optional branch around it), the `?? '1'` fallback exists only to satisfy
    // noUncheckedIndexedAccess.
    return Math.max(1, Number.parseInt(match[1] ?? '1', 10));
  }
  return 1;
}

export interface RefillTrackingContext {
  user_id: number;
  line_user_id?: string | null;
  line_account_id?: number | null;
  dispense_id?: number | null;
}

interface ExistingTrackingRow {
  id: number;
  estimated_end_date_str: string;
}

/**
 * รับรายการ dispense items แล้วบันทึก refill tracking สำหรับเฉพาะรายการที่เป็นยา
 * (isMedicine=true). Port of `RefillTrackingHelper::trackFromDispense()`. Each item's DB work is
 * independently try/catch-and-continue (PHP: `catch (Exception $e) { error_log(...) }` per
 * item, inside the `foreach`) — one item's failure never stops the rest of the batch.
 */
export async function trackFromDispense(db: Kysely<TenantDB>, items: DispenseItem[], ctx: RefillTrackingContext): Promise<void> {
  const userId = intval(ctx.user_id ?? 0);
  if (userId <= 0) {
    return;
  }
  const lineUserId = ctx.line_user_id ?? null;
  const lineAccountId = ctx.line_account_id ?? null;
  const dispenseId = ctx.dispense_id ?? null;

  for (const item of items) {
    const isMedicine = phpTruthy(item.isMedicine) && (item.isMedicine as unknown) !== false;
    if (!isMedicine) {
      continue;
    }

    const productId = intval(item.product_id ?? 0);
    const qtyUnits = intval(item.qty ?? 0);
    if (productId <= 0 || qtyUnits <= 0) {
      continue;
    }

    // แปลง qty (จำนวนกล่อง/หลอด) → จำนวนเม็ด/โดสจริง.
    const packSize = parsePackSize(typeof item.unit === 'string' ? item.unit : '');
    const totalDoses = qtyUnits * packSize;

    const dosagePerTime = Math.max(1, intval(item.dosage ?? 1));
    const timeOfDay = item.timeOfDay;
    const timesPerDay = Array.isArray(timeOfDay) && timeOfDay.length > 0 ? timeOfDay.length : 1;
    const dailyDosage = dosagePerTime * timesPerDay;

    const daysSupply = Math.max(1, Math.ceil(totalDoses / dailyDosage));
    const estimatedEndDate = addDaysToYmd(bangkokTodayYmd(), daysSupply);
    const productName = typeof item.name === 'string' ? item.name : '';
    const qty = totalDoses; // เก็บเป็นจำนวนโดสจริงเพื่อให้ display คำนวณ remaining ถูก

    try {
      // Dedupe: ถ้ามี tracking ที่ยัง active สำหรับ (user, product) → ต่ออายุแทน insert ใหม่.
      const existingResult = await sql<ExistingTrackingRow>`
        SELECT id, DATE_FORMAT(estimated_end_date, '%Y-%m-%d') AS estimated_end_date_str
        FROM medication_refill_tracking
        WHERE user_id = ${userId} AND product_id = ${productId} AND estimated_end_date >= CURDATE()
        ORDER BY id DESC LIMIT 1
      `.execute(db);
      const existing = existingResult.rows[0];

      if (existing) {
        const newEndDate = addDaysToYmd(existing.estimated_end_date_str, daysSupply);
        await sql`
          UPDATE medication_refill_tracking SET
            quantity_purchased = quantity_purchased + ${qty},
            daily_dosage       = ${dailyDosage},
            estimated_end_date = ${newEndDate},
            reminder_sent_at   = NULL
          WHERE id = ${existing.id}
        `.execute(db);
      } else {
        await sql`
          INSERT INTO medication_refill_tracking
            (user_id, line_user_id, line_account_id, product_id, product_name,
             quantity_purchased, daily_dosage, purchase_date, estimated_end_date,
             order_id, source, source_ref_id)
          VALUES (${userId}, ${lineUserId}, ${lineAccountId}, ${productId}, ${productName},
                  ${qty}, ${dailyDosage}, CURDATE(), ${estimatedEndDate},
                  ${dispenseId}, 'dispense', ${dispenseId})
        `.execute(db);
      }
    } catch {
      // ignore — matches PHP's per-item catch(Exception $e){error_log(...)}, continue with the
      // next item in the batch.
    }
  }
}
