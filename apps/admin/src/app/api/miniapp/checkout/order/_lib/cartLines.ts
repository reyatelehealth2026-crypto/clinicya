import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { strOrEmpty, toFloatOrZero } from './phpCompat';

/**
 * cartLines.ts — PRIVATE, route-scoped port of api/checkout.php's shared cart-line-loading helpers needed
 * by handleCreateOrder() (read api/checkout.php in full before editing this file):
 *
 *   - resolveCartProductSource() (L167-172)
 *   - odooCartLineUnitPrice() (L190-199)
 *   - checkoutOrderUnitPrice() (L201-214)
 *   - loadCheckoutCartLinesFromDb() (L216-312)
 *
 * NOT shared with apps/admin/src/app/api/miniapp/checkout/cart/_lib/cartProductSource.ts (cartAndPricing
 * builder's own near-identical copy of resolveCartProductSource()/odooCartLineUnitPrice()) — duplicated
 * here deliberately, per this batch's brief ("intentional duplication vs cartAndPricing's own copy — do
 * not import across lanes") and this repo's established per-route-folder-duplication convention
 * (member/_lib/phpCompat.ts vs health-profile/_lib/phpCompat.ts vs appointments/_lib/phpCompat.ts each
 * keep their own copy). checkout/cart/** and checkout/pricing/** are a different builder's lane and out
 * of this route's allowed-paths.
 *
 * resolveCartProductSourceWithShopDefault() (L178-188) is deliberately NOT ported here —
 * handleCreateOrder() never calls it (grep-verified: only resolveCartProductSource() and
 * loadCheckoutCartLinesFromDb() are reached from L1288-1656), so
 * includes/shop-data-source.php's getShopOrderDataSource()-driven default-inference logic is out of scope
 * for this file.
 *
 * SIMPLIFICATION (flagged, same established precedent as cartProductSource.ts's own module doc and
 * shop-products/_lib/query.ts's): loadCheckoutCartLinesFromDb() guards its shop_products-aware SQL shape
 * behind `ensureCartProductSourceSupport($db)` (a runtime `ALTER TABLE cart_items ADD COLUMN
 * product_source ...` + unique-index migration dance) and `hasTableColumn('cart_items', 'product_source')
 * && tableExists('shop_products')`. packages/db's generated tenant-db.d.ts confirms BOTH are
 * unconditionally true on a tenant DB created from the committed template (`CartItems.product_source` is
 * `Generated<string>`, not optional/probed; `shop_products` is a real registered table in `DB`) — this
 * port always takes the joined-query branch; the migration call and the non-joined SQL fallback shape are
 * dropped as unreachable on the committed schema.
 */

export type CheckoutCartProductSource = 'business_items' | 'shop_products';

/** The subset of handleCreateOrder()'s per-line array shape this route actually consumes (product_id,
 *  name, price, sale_price, quantity, product_source, `_unit` — the pre-resolved unit price used in
 *  preference to re-deriving via checkoutOrderUnitPrice(), matching PHP's `isset($item['_unit'])` check). */
export interface CheckoutCartLine {
  product_id: number;
  name: string;
  price: number;
  sale_price: number | null;
  quantity: number;
  product_source: CheckoutCartProductSource;
  _unit: number;
}

/** Port of resolveCartProductSource() (L167-172). */
export function resolveCartProductSource(raw: unknown): CheckoutCartProductSource {
  const s = strOrEmpty(raw).trim().toLowerCase();
  return s === 'shop_products' ? 'shop_products' : 'business_items';
}

/** Port of odooCartLineUnitPrice() (L190-199). */
export function odooCartLineUnitPrice(row: { o_list: unknown; o_online: unknown }): number {
  const list = toFloatOrZero(row.o_list);
  const online = toFloatOrZero(row.o_online);
  if (online > 0) {
    return online;
  }
  return list > 0 ? list : 0;
}

/** Port of checkoutOrderUnitPrice() (L201-214) — unit price for an order line (business or odoo-shaped row). */
export function checkoutOrderUnitPrice(item: { price?: unknown; sale_price?: unknown }): number {
  const p = toFloatOrZero(item.price);
  const s = item.sale_price !== undefined && item.sale_price !== null && item.sale_price !== '' ? toFloatOrZero(item.sale_price) : 0;
  if (s > 0 && (p <= 0 || s < p)) {
    return s;
  }
  return p > 0 ? p : s;
}

interface CartJoinRow {
  product_id: number;
  quantity: number | null;
  product_source: string | null;
  bi_name: string | null;
  bi_price: unknown;
  bi_sale_price: unknown;
  o_name: string | null;
  o_list: unknown;
  o_online: unknown;
}

/**
 * Port of loadCheckoutCartLinesFromDb() (L216-312) — load cart lines from DB for checkout (same rules as
 * GET cart). SIMPLIFICATION per this file's module doc: always takes the shop_products-joined branch.
 */
export async function loadCheckoutCartLinesFromDb(
  db: Kysely<TenantDB>,
  userId: number,
  lineAccountId: number | null
): Promise<CheckoutCartLine[]> {
  const result = await sql<CartJoinRow>`
    SELECT c.*,
           p.name AS bi_name,
           p.price AS bi_price,
           p.sale_price AS bi_sale_price,
           p.image_url AS bi_image_url,
           p.is_active AS bi_is_active,
           o.name AS o_name,
           o.list_price AS o_list,
           o.online_price AS o_online,
           o.product_code AS o_pc,
           o.sku AS o_sku,
           o.is_active AS o_is_active
    FROM cart_items c
    LEFT JOIN business_items p
      ON c.product_id = p.id
     AND IFNULL(NULLIF(c.product_source, ''), 'business_items') = 'business_items'
    LEFT JOIN shop_products o
      ON c.product_id = o.id
     AND c.product_source = 'shop_products'
     AND o.line_account_id = ${lineAccountId ?? 0}
    WHERE c.user_id = ${userId}
  `.execute(db);

  const out: CheckoutCartLine[] = [];
  for (const item of result.rows) {
    const src = resolveCartProductSource(item.product_source);
    if (src === 'shop_products') {
      const name = item.o_name ?? '';
      if (name === '') {
        continue;
      }
      const unit = odooCartLineUnitPrice({ o_list: item.o_list, o_online: item.o_online });
      const list = toFloatOrZero(item.o_list);
      const on = toFloatOrZero(item.o_online);
      let price = list > 0 ? list : on > 0 ? on : 0;
      let sale: number | null = on > 0 && list > 0 && on < list ? on : null;
      if (list <= 0 && on > 0) {
        price = on;
        sale = null;
      }
      out.push({
        product_id: Number(item.product_id),
        name,
        price,
        sale_price: sale,
        quantity: Number(item.quantity ?? 0),
        product_source: 'shop_products',
        _unit: unit,
      });
    } else {
      const name = item.bi_name ?? '';
      if (name === '') {
        continue;
      }
      const bp = item.bi_price ?? 0;
      const bs = item.bi_sale_price;
      const hasSale = bs !== null && bs !== undefined && bs !== '' && toFloatOrZero(bs) > 0;
      const unit = hasSale ? toFloatOrZero(bs) : toFloatOrZero(bp);
      out.push({
        product_id: Number(item.product_id),
        name,
        price: toFloatOrZero(bp),
        sale_price: bs !== null && bs !== undefined && bs !== '' ? toFloatOrZero(bs) : null,
        quantity: Number(item.quantity ?? 0),
        product_source: 'business_items',
        _unit: unit,
      });
    }
  }
  return out;
}
