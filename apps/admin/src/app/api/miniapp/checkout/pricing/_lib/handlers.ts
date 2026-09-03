import type { Kysely } from 'kysely';
import { sql } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * handlers.ts — port of api/checkout.php's handleValidatePromo() (L2202-2312) + validateHardcodedPromo()
 * (L2317-2348), action=validate_promo. Read both functions in full before writing this file.
 *
 * `promotions` (and `promotion_usage`) do NOT appear anywhere in packages/db's generated
 * `tenant-db.d.ts` — unlike cartProductSource.ts's `ensureCartProductSourceSupport()`/`tableExists
 * ('shop_products')` simplifications, this is NOT a schema-drift shim to skip: the table genuinely does
 * not exist on the committed tenant template, so the `SHOW TABLES LIKE 'promotions'` runtime probe in
 * handleValidatePromo is a REAL, LIVE branch (not dead code) and is preserved faithfully below
 * (`promotionsTableExists()`), not hardcoded to `false`. On the committed template that probe always
 * comes back empty and every real request falls through to `validateHardcodedPromo()`'s 4 fixed codes
 * (WELCOME10/SAVE50/FREESHIP/NEWUSER) — see fixtures/checkout-cart/validate-promo-*.json. The
 * `promotions`-table-aware branch (`findPromotion()` + its date/usage/per-user-limit checks) is still
 * implemented in full below (raw `sql`, since there's no generated Kysely type for a table that isn't in
 * the committed schema) so a tenant DB that genuinely has a `promotions` table — e.g. a manually
 * migrated one — behaves identically to the PHP original; exercised by handlers.test.ts, not by the
 * fixtures (which only cover what's reachable on the committed template).
 */

export interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

/** Port of api/checkout.php's local `jsonResponse($success, $message, $data)` — always HTTP 200. */
function ok(success: boolean, message: string, data: Record<string, unknown> = {}): ActionResult {
  return { status: 200, body: { success, message, ...data } };
}

function strOrEmpty(value: unknown): string {
  if (typeof value === 'string') return value;
  if (value === undefined || value === null) return '';
  return String(value);
}

function toFloatOrZero(value: unknown): number {
  if (value === null || value === undefined || value === '') return 0;
  const n = Number(value);
  return Number.isFinite(n) ? n : 0;
}

/** `number_format($v, 0)` — thousands-comma, no decimals. Used only by the promotions-table branch's min-order message. */
function phpNumberFormat0(value: number): string {
  return Math.round(value).toLocaleString('en-US', { maximumFractionDigits: 0 });
}

/** `date('Y-m-d H:i:s')` under the server's Asia/Bangkok default timezone (CLAUDE.md). */
function nowBangkokDateTimeString(now: Date = new Date()): string {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Bangkok',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(now);
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '00';
  return `${get('year')}-${get('month')}-${get('day')} ${get('hour')}:${get('minute')}:${get('second')}`;
}

/** DATE/DATETIME columns on a hand-added `promotions` table could hydrate as a JS Date (no `dateStrings: true` anywhere in packages/db) — normalize to PHP PDO's raw string shape before doing string comparison, same as elsewhere in this batch. */
function asDateTimeString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

// ---------------------------------------------------------------------------
// validateHardcodedPromo() (L2317-2348)
// ---------------------------------------------------------------------------

interface HardcodedPromo {
  type: 'percentage' | 'fixed';
  value: number;
  min: number;
  max?: number;
}

const HARDCODED_PROMO_CODES: Record<string, HardcodedPromo> = {
  WELCOME10: { type: 'percentage', value: 10, min: 100 },
  SAVE50: { type: 'fixed', value: 50, min: 300 },
  FREESHIP: { type: 'fixed', value: 50, min: 0 },
  NEWUSER: { type: 'percentage', value: 15, min: 200, max: 100 },
};

/** Port of validateHardcodedPromo() — returns the discount amount, or 0 for an unknown code / subtotal below its minimum. */
export function validateHardcodedPromo(code: string, subtotal: number): number {
  const promo = HARDCODED_PROMO_CODES[code];
  if (!promo) return 0;
  if (subtotal < promo.min) return 0;

  let discount: number;
  if (promo.type === 'percentage') {
    discount = subtotal * (promo.value / 100);
    if (promo.max !== undefined && discount > promo.max) {
      discount = promo.max;
    }
  } else {
    discount = promo.value;
  }

  return Math.min(discount, subtotal);
}

// ---------------------------------------------------------------------------
// promotions-table-aware branch (handleValidatePromo()'s main body, L2233-2306)
// ---------------------------------------------------------------------------

interface PromoRow {
  id: number;
  name: string | null;
  code: string;
  is_active: number;
  line_account_id: number | null;
  start_date: string | Date | null;
  end_date: string | Date | null;
  min_order_amount: unknown;
  usage_limit: number | null;
  usage_count: number | null;
  per_user_limit: number | null;
  discount_type: string | null;
  discount_value: unknown;
  max_discount: unknown;
}

/** `SHOW TABLES LIKE 'promotions'` — the genuine runtime probe (see module doc); NOT simplified to a hardcoded `false`. */
async function promotionsTableExists(db: Kysely<TenantDB>): Promise<boolean> {
  const result = await sql<{ Tables_in_db?: string }>`SHOW TABLES LIKE 'promotions'`.execute(db);
  return result.rows.length > 0;
}

async function findPromotion(db: Kysely<TenantDB>, code: string, lineAccountId: number | null): Promise<PromoRow | undefined> {
  if (lineAccountId) {
    const result = await sql<PromoRow>`
      SELECT * FROM promotions
      WHERE code = ${code} AND is_active = 1 AND (line_account_id = ${lineAccountId} OR line_account_id IS NULL)
    `.execute(db);
    return result.rows[0];
  }
  const result = await sql<PromoRow>`SELECT * FROM promotions WHERE code = ${code} AND is_active = 1`.execute(db);
  return result.rows[0];
}

async function countPromotionUsageForUser(db: Kysely<TenantDB>, promotionId: number, lineUserId: string): Promise<number> {
  const result = await sql<{ 'COUNT(*)': unknown }>`
    SELECT COUNT(*) FROM promotion_usage WHERE promotion_id = ${promotionId} AND line_user_id = ${lineUserId}
  `.execute(db);
  const row = result.rows[0] as Record<string, unknown> | undefined;
  const raw = row ? Object.values(row)[0] : 0;
  return toFloatOrZero(raw);
}

/** Port of handleValidatePromo()'s promotions-table-driven branch (L2233-2306). */
async function validateFromPromotionsTable(
  db: Kysely<TenantDB>,
  code: string,
  lineUserId: string | null,
  lineAccountId: number | null,
  subtotal: number
): Promise<ActionResult> {
  const promo = await findPromotion(db, code, lineAccountId);
  if (!promo) {
    return ok(false, 'โค้ดไม่ถูกต้อง', { valid: false });
  }

  const now = nowBangkokDateTimeString();
  const startDate = asDateTimeString(promo.start_date);
  const endDate = asDateTimeString(promo.end_date);
  if (startDate && now < startDate) {
    return ok(false, 'โค้ดยังไม่เริ่มใช้งาน', { valid: false });
  }
  if (endDate && now > endDate) {
    return ok(false, 'โค้ดหมดอายุแล้ว', { valid: false });
  }

  const minOrder = toFloatOrZero(promo.min_order_amount);
  if (minOrder > 0 && subtotal < minOrder) {
    return ok(false, `ยอดสั่งซื้อขั้นต่ำ ฿${phpNumberFormat0(minOrder)}`, { valid: false });
  }

  if (promo.usage_limit && (promo.usage_count ?? 0) >= promo.usage_limit) {
    return ok(false, 'โค้ดถูกใช้ครบจำนวนแล้ว', { valid: false });
  }

  if (lineUserId && promo.per_user_limit) {
    const userUsage = await countPromotionUsageForUser(db, promo.id, lineUserId);
    if (userUsage >= promo.per_user_limit) {
      return ok(false, 'คุณใช้โค้ดนี้ครบจำนวนแล้ว', { valid: false });
    }
  }

  const discountType = promo.discount_type ?? 'fixed';
  let discount: number;
  if (discountType === 'percentage') {
    discount = subtotal * (toFloatOrZero(promo.discount_value) / 100);
    const maxDiscount = toFloatOrZero(promo.max_discount);
    if (promo.max_discount && discount > maxDiscount) {
      discount = maxDiscount;
    }
  } else {
    discount = toFloatOrZero(promo.discount_value);
  }
  discount = Math.min(discount, subtotal);

  return ok(true, 'โค้ดถูกต้อง', {
    valid: true,
    discount,
    discount_type: discountType,
    discount_value: toFloatOrZero(promo.discount_value),
    code,
    promo_id: promo.id,
    promo_name: promo.name ?? code,
  });
}

// ---------------------------------------------------------------------------
// action=validate_promo (handleValidatePromo(), L2202-2312)
// ---------------------------------------------------------------------------

export async function handleValidatePromo(db: Kysely<TenantDB>, data: Record<string, unknown>): Promise<ActionResult> {
  const code = strOrEmpty(data.code).trim().toUpperCase();
  const lineUserId = data.line_user_id !== undefined && data.line_user_id !== null ? strOrEmpty(data.line_user_id) : null;
  const lineAccountId =
    data.line_account_id !== undefined && data.line_account_id !== null && data.line_account_id !== ''
      ? toFloatOrZero(data.line_account_id)
      : null;
  const subtotal = toFloatOrZero(data.subtotal);

  if (!code) {
    return ok(false, 'กรุณากรอกโค้ดส่วนลด', { valid: false });
  }

  try {
    const tableExists = await promotionsTableExists(db);
    if (!tableExists) {
      const discount = validateHardcodedPromo(code, subtotal);
      if (discount > 0) {
        return ok(true, 'โค้ดถูกต้อง', { valid: true, discount, discount_type: 'fixed', code });
      }
      return ok(false, 'โค้ดไม่ถูกต้องหรือหมดอายุ', { valid: false });
    }

    return await validateFromPromotionsTable(db, code, lineUserId, lineAccountId, subtotal);
  } catch {
    return ok(false, 'ไม่สามารถตรวจสอบโค้ดได้', { valid: false });
  }
}
