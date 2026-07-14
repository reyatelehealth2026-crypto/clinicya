import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * cartProductSource.ts — private port (scoped to this route only) of api/checkout.php's shared
 * cart-product-source helpers used by every cart handler (read in full before writing this file):
 *
 *   - resolveCartProductSource() (L167-172)
 *   - resolveCartProductSourceWithShopDefault() (L178-188), which itself inlines
 *     includes/shop-data-source.php's getShopOrderDataSource()/normalizeShopOrderDataSource()
 *   - odooCartLineUnitPrice() (L190-199)
 *   - getUserIdFromLineUserId() (L818-840) — the shared user-resolution helper used by
 *     handleAddToCart/handleUpdateCart/handleRemoveFromCart/handleClearCart (handleGetCart has its own
 *     near-duplicate inline copy in PHP with extra debug-info tracking — ported directly in handlers.ts
 *     rather than forced through this same function, to keep the debug trail byte-for-byte faithful)
 *   - buildManagerProductPhotoUrl()/managerProductPhotoBaseUrl() (includes/manager-product-photo.php) —
 *     needed by handleGetCart's shop_products branch to build a cart line's image_url
 *
 * NOT shared with apps/admin/src/app/api/miniapp/shop-products/_lib/catalogSource.ts (which already
 * ports the identical getShopOrderDataSource()/normalizeShopOrderDataSource() pair for the `categories`
 * action) — duplicated here deliberately, per this repo's established precedent of per-route helper
 * duplication over cross-route sharing (member/_lib/phpCompat.ts vs health-profile/_lib/phpCompat.ts vs
 * appointments/_lib/phpCompat.ts each keep their own copy; shop-products/** is a different builder's
 * lane and out of this route's allowed-paths).
 *
 * SIMPLIFICATION (flagged, same established precedent as shop-products/_lib/query.ts's own module doc
 * and this batch's acceptance criteria): api/checkout.php guards every cart_items/shop_products access
 * behind `ensureCartProductSourceSupport()` (a runtime `ALTER TABLE cart_items ADD COLUMN
 * product_source ...` + unique-index migration dance) and `hasTableColumn('cart_items',
 * 'product_source')` / `tableExists('shop_products')` probes. packages/db's generated
 * `tenant-db.d.ts` confirms BOTH are unconditionally true on a tenant DB created from the committed
 * template: `CartItems.product_source` is `Generated<string>` (not optional/probed), and `shop_products`
 * is a real registered table in `DB`. This port always takes the "hasPs=true / shop_products exists"
 * branch — the `ensureCartProductSourceSupport()` migration call, the non-joined SQL fallback query
 * shape, and handleAddToCart's elaborate INSERT-then-catch-then-SELECT-or-INSERT fallback dance (which
 * exists purely to tolerate a cart_items table without the unique_user_product_source index) are all
 * dropped as unreachable on the committed schema. The literal 'Cart migration required (product_source)'
 * failure message in handleAddToCart is consequently unreachable here too (guarded by the same
 * hasTableColumn() check) — intentionally not wired to any live code path.
 */

export type CartProductSource = 'business_items' | 'shop_products';

/** Port of resolveCartProductSource() (L167-172). */
export function resolveCartProductSource(raw: unknown): CartProductSource {
  const s = strOrEmpty(raw).trim().toLowerCase();
  return s === 'shop_products' ? 'shop_products' : 'business_items';
}

function normalizeShopOrderDataSource(value: string | null | undefined): 'shop' | 'odoo' {
  return (value ?? '').trim().toLowerCase() === 'odoo' ? 'odoo' : 'shop';
}

/** Port of includes/shop-data-source.php's getShopOrderDataSource() (the `ensureShopOrderDataSourceColumn()` migration guard it calls first is dropped for the same SIMPLIFICATION reason as above — `shop_settings.order_data_source` is unconditionally present per tenant-db.d.ts). */
async function getShopOrderDataSource(db: Kysely<TenantDB>, lineAccountId: number): Promise<'shop' | 'odoo'> {
  try {
    if (lineAccountId) {
      const scoped = await sql<{ order_data_source: string | null }>`
        SELECT order_data_source FROM shop_settings WHERE line_account_id = ${lineAccountId} LIMIT 1
      `.execute(db);
      const value = scoped.rows[0]?.order_data_source;
      if (value !== undefined && value !== null && value !== '') {
        return normalizeShopOrderDataSource(value);
      }
    }
    const fallback = await sql<{ order_data_source: string | null }>`
      SELECT order_data_source FROM shop_settings WHERE id = 1 OR line_account_id IS NULL LIMIT 1
    `.execute(db);
    return normalizeShopOrderDataSource(fallback.rows[0]?.order_data_source);
  } catch {
    return 'shop';
  }
}

/** Port of resolveCartProductSourceWithShopDefault() (L178-188). */
export async function resolveCartProductSourceWithShopDefault(
  db: Kysely<TenantDB>,
  raw: unknown,
  lineAccountId: number
): Promise<CartProductSource> {
  const trimmed = raw !== null && raw !== undefined ? strOrEmpty(raw).trim() : '';
  if (trimmed !== '') {
    return resolveCartProductSource(trimmed);
  }
  return (await getShopOrderDataSource(db, lineAccountId)) === 'odoo' ? 'shop_products' : 'business_items';
}

/** Port of odooCartLineUnitPrice() (L190-199). */
export function odooCartLineUnitPrice(row: { o_list: unknown; o_online: unknown }): number {
  const list = toFloatOrZero(row.o_list);
  const online = toFloatOrZero(row.o_online);
  if (online > 0) return online;
  return list > 0 ? list : 0;
}

// ---------------------------------------------------------------------------
// includes/manager-product-photo.php port (used by handleGetCart's shop_products branch)
// ---------------------------------------------------------------------------

function managerProductPhotoBaseUrl(): string {
  const env = process.env.MANAGER_PRODUCT_PHOTO_BASE_URL;
  return env && env.trim() !== '' ? env.replace(/\/+$/, '') : 'https://manager.cnypharmacy.com';
}

/** Port of buildManagerProductPhotoUrl(): numeric product_code/sku left-padded to 4 digits, else used as-is. */
export function buildManagerProductPhotoUrl(productCode: unknown, sku: unknown): string {
  let key = strOrEmpty(productCode).trim();
  if (key === '') {
    key = strOrEmpty(sku).trim();
  }
  if (key === '') {
    return '';
  }
  if (/^\d+$/.test(key)) {
    key = key.padStart(4, '0');
  }
  return `${managerProductPhotoBaseUrl()}/uploads/product_photo/${encodeURIComponent(key)}.jpg`;
}

// ---------------------------------------------------------------------------
// getUserIdFromLineUserId (L818-840)
// ---------------------------------------------------------------------------

export interface ResolvedCartUser {
  userId: number;
  lineAccountId: number;
}

/**
 * Port of getUserIdFromLineUserId() (L818-840) — used by handleAddToCart/handleUpdateCart/
 * handleRemoveFromCart/handleClearCart. Auto-creates a `users` row (display_name='LIFF User') keyed to
 * the active/default `line_accounts` row when no existing user matches `lineUserId`, exactly like the
 * PHP original. Returns `null` for falsy `lineUserId` (mirrors `if (!$lineUserId) return [null, null];`).
 */
export async function getUserIdFromLineUserId(db: Kysely<TenantDB>, lineUserId: unknown): Promise<ResolvedCartUser | null> {
  if (phpFalsy(lineUserId)) {
    return null;
  }
  const lineUserIdStr = strOrEmpty(lineUserId);

  const existing = await sql<{ id: number; line_account_id: number }>`
    SELECT id, line_account_id FROM users WHERE line_user_id = ${lineUserIdStr}
  `.execute(db);
  const user = existing.rows[0];
  if (user) {
    return { userId: Number(user.id), lineAccountId: Number(user.line_account_id) };
  }

  const accountResult = await sql<{ id: number }>`
    SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1
  `.execute(db);
  const lineAccountId = accountResult.rows[0]?.id ?? 1;

  const insertResult = await sql<never>`
    INSERT INTO users (line_account_id, line_user_id, display_name) VALUES (${lineAccountId}, ${lineUserIdStr}, 'LIFF User')
  `.execute(db);

  return { userId: Number(insertResult.insertId ?? 0), lineAccountId: Number(lineAccountId) };
}

// ---------------------------------------------------------------------------
// PHP-semantics helpers (small, local — see phpCompat.ts precedent in member/health-profile/appointments)
// ---------------------------------------------------------------------------

/** PHP `(string) $v` — coerces non-string inputs, `null`/`undefined` -> `''`. */
export function strOrEmpty(value: unknown): string {
  if (typeof value === 'string') return value;
  if (value === undefined || value === null) return '';
  return String(value);
}

/** PHP `!$v` truthiness check for the scalar shapes this route ever sees (string/number/null/undefined). */
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
