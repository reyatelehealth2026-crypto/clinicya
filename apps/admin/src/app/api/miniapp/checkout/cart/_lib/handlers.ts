import type { Kysely } from 'kysely';
import { sql } from 'kysely';
import type { TenantDB } from '@reya/db';
import {
  buildManagerProductPhotoUrl,
  getUserIdFromLineUserId,
  odooCartLineUnitPrice,
  phpFalsy,
  resolveCartProductSource,
  resolveCartProductSourceWithShopDefault,
  strOrEmpty,
  toFloatOrZero,
  toIntOrZero,
} from './cartProductSource';

/**
 * handlers.ts — the 5 cart action handlers ported from api/checkout.php (read in full, 2794 lines):
 *   - handleGetCart()        action=cart          (L1105-1283)
 *   - handleAddToCart()      action=add_to_cart   (L845-971)
 *   - handleUpdateCart()     action=update_cart   (L976-1035)
 *   - handleRemoveFromCart() action=remove_from_cart (L1040-1076)
 *   - handleClearCart()      action=clear_cart    (L1081-1100)
 *
 * Every handler always takes the "hasPs=true / shop_products table exists" branch — see
 * cartProductSource.ts's module doc for the SIMPLIFICATION rationale (schema-drift compatibility shims
 * with no equivalent need on a tenant DB created from the committed template).
 *
 * TWO VERIFIED, PRESERVED PRODUCTION GAPS (do not "fix" while porting — contractNote):
 *   (a) line-mini-app/src/lib/shop-api.ts's addToCart/updateCartItem/removeCartLine send `unit_id`, but
 *       api/checkout.php never reads it anywhere (zero occurrences, grep-verified) — multi-unit cart
 *       selection is silently dropped server-side today. This port does not read `unit_id` from the
 *       request body either, on any of the 4 write handlers. `cart_items.unit_id` DOES still exist as a
 *       column (see tenant-db.d.ts) and IS still passed through raw in handleGetCart's `c.*`-shaped
 *       response (gap preserved: a value written by nothing server-side, but still echoed back).
 *   (b) action=payment_info (shop-api.ts's fetchPaymentInfo()) has zero callers in line-mini-app
 *       (grep-verified) and is not a valid PHP action in api/checkout.php (falls through to the generic
 *       'Invalid action' response) — confirmed dead client code, intentionally not ported here.
 */

export interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

/** Port of api/checkout.php's local `jsonResponse($success, $message, $data)` — always HTTP 200. */
function ok(success: boolean, message: string, data: Record<string, unknown> = {}): ActionResult {
  return { status: 200, body: { success, message, ...data } };
}

/**
 * packages/db's mysql2 pool has no `dateStrings: true`, so DATETIME columns MAY hydrate as JS `Date`
 * objects depending on the driver's column-type inference; PHP PDO (ATTR_EMULATE_PREPARES=false, native
 * prepares) always returns DATETIME columns as raw `YYYY-MM-DD HH:MM:SS` strings. Same fix already
 * applied in rewards/member's own loyaltyPoints.ts/handlers.ts (`asDateTimeString`) — duplicated here
 * rather than imported, per this batch's allowed-paths boundary.
 */
function asDateTimeString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

/** PHP `empty($v)`: true for undefined/null/''/0/'0'/false — used for `!empty($item['name'])`. */
function phpEmpty(value: unknown): boolean {
  if (value === undefined || value === null || value === false) return true;
  if (value === '' || value === 0 || value === '0') return true;
  return false;
}

// ---------------------------------------------------------------------------
// action=cart (handleGetCart, L1105-1283)
// ---------------------------------------------------------------------------

interface RawCartRow {
  id: number;
  line_account_id: number;
  line_user_id: string;
  product_id: number;
  product_source: string | null;
  quantity: number | null;
  unit_id: number | null;
  created_at: string | Date;
  updated_at: string | Date;
  user_id: number;
  bi_name: string | null;
  bi_price: string | null;
  bi_sale_price: string | null;
  bi_image_url: string | null;
  bi_is_active: number | null;
  o_name: string | null;
  o_list: string | null;
  o_online: string | null;
  o_pc: string | null;
  o_sku: string | null;
  o_is_active: number | null;
}

/**
 * Port of the foreach body in handleGetCart() (L1196-1238) — per-row shop_products/business_items
 * branch, then the `!empty($item['name'])` filter. Field-for-field faithful, including the "leaky"
 * response shape: the real PHP endpoint really does emit the raw joined `bi_*`/`o_*` alias columns
 * alongside the derived `name`/`price`/`sale_price`/`image_url`/`is_active`/`subtotal` overrides (PHP
 * never unsets them) — reproduced here rather than curated away, since mig-verify's field-level parity
 * check would otherwise flag missing fields on real traffic.
 */
function buildCartItem(row: RawCartRow): { item: Record<string, unknown> | null; filteredOutReason: { product_id: number; reason: string } | null } {
  const src = resolveCartProductSource(row.product_source);
  const item: Record<string, unknown> = {
    ...row,
    created_at: asDateTimeString(row.created_at),
    updated_at: asDateTimeString(row.updated_at),
  };

  const quantity = toIntOrZero(row.quantity);

  if (src === 'shop_products') {
    const unit = odooCartLineUnitPrice({ o_list: row.o_list, o_online: row.o_online });
    let price = toFloatOrZero(row.o_list); // step1: (float) ($item['o_list'] ?? 0)
    const online = toFloatOrZero(row.o_online); // step2
    let salePrice: number | null = online > 0 && online < price ? online : online > 0 && price <= 0 ? online : null; // step3
    if (price <= 0 && online > 0) {
      // step4 override
      price = online;
      salePrice = null;
    }
    item.name = row.o_name ?? null;
    item.price = price;
    item.sale_price = salePrice;
    item.image_url = buildManagerProductPhotoUrl(row.o_pc ?? '', row.o_sku ?? '');
    item.is_active = row.o_is_active !== null && row.o_is_active !== undefined ? Number(row.o_is_active) : 1;
    item.product_source = 'shop_products';
    item.subtotal = unit * quantity;
  } else {
    const bp = row.bi_price;
    const bs = row.bi_sale_price;
    const lineUnit = bs !== null && bs !== undefined && bs !== '' && toFloatOrZero(bs) > 0 ? toFloatOrZero(bs) : toFloatOrZero(bp);
    item.name = row.bi_name ?? null;
    item.price = row.bi_price ?? null; // CONTRACT-DRIFT FIX: raw DECIMAL passthrough, no Number() cast (see shop-products/_lib/query.ts precedent)
    item.sale_price = row.bi_sale_price ?? null;
    item.image_url = row.bi_image_url ?? null;
    item.is_active = row.bi_is_active ?? null;
    item.product_source = 'business_items';
    item.subtotal = lineUnit * quantity;
  }

  if (!phpEmpty(item.name)) {
    return { item, filteredOutReason: null };
  }
  return { item: null, filteredOutReason: { product_id: Number(row.product_id), reason: 'product_deleted' } };
}

async function fetchShippingSettings(
  db: Kysely<TenantDB>,
  lineAccountId: number | null
): Promise<{ shipping_fee: unknown; free_shipping_min: unknown } | undefined> {
  if (lineAccountId) {
    const scoped = await sql<{ shipping_fee: unknown; free_shipping_min: unknown }>`
      SELECT shipping_fee, free_shipping_min FROM shop_settings WHERE line_account_id = ${lineAccountId} LIMIT 1
    `.execute(db);
    if (scoped.rows[0]) return scoped.rows[0];
  }
  const fallback = await sql<{ shipping_fee: unknown; free_shipping_min: unknown }>`
    SELECT shipping_fee, free_shipping_min FROM shop_settings LIMIT 1
  `.execute(db);
  return fallback.rows[0];
}

export async function handleGetCart(db: Kysely<TenantDB>, query: Record<string, string | undefined>): Promise<ActionResult> {
  const debug = Object.prototype.hasOwnProperty.call(query, 'debug');
  const inputUserId = query.user_id ?? null;
  const inputLineUserId = query.line_user_id ?? null;

  let userId: string | number | null = inputUserId;
  let lineAccountId: number | null = null;
  const debugInfo: Record<string, unknown> = {
    input_user_id: inputUserId,
    input_line_user_id: inputLineUserId,
    line_user_id_length: strOrEmpty(inputLineUserId).length,
  };

  if (!phpFalsy(inputLineUserId)) {
    const lineUserIdStr = strOrEmpty(inputLineUserId);
    const existing = await sql<{ id: number; line_account_id: number }>`
      SELECT id, line_account_id FROM users WHERE line_user_id = ${lineUserIdStr}
    `.execute(db);
    const user = existing.rows[0];
    if (user) {
      userId = Number(user.id);
      lineAccountId = Number(user.line_account_id);
      debugInfo.user_found = true;
      debugInfo.db_user_id = userId;
    } else {
      debugInfo.user_found = false;
      const accountResult = await sql<{ id: number }>`
        SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1
      `.execute(db);
      lineAccountId = accountResult.rows[0]?.id ?? 1;
      const insertResult = await sql<never>`
        INSERT INTO users (line_account_id, line_user_id, display_name) VALUES (${lineAccountId}, ${lineUserIdStr}, 'LIFF User')
      `.execute(db);
      userId = Number(insertResult.insertId ?? 0);
      debugInfo.user_created = true;
      debugInfo.new_user_id = userId;
    }
  }

  if (phpFalsy(userId)) {
    return debug ? ok(false, 'User not found', { debug: debugInfo }) : ok(false, 'User not found');
  }

  const rows = await sql<RawCartRow>`
    SELECT c.id, c.line_account_id, c.line_user_id, c.product_id, c.product_source, c.quantity, c.unit_id,
           c.created_at, c.updated_at, c.user_id,
           p.name AS bi_name, p.price AS bi_price, p.sale_price AS bi_sale_price,
           p.image_url AS bi_image_url, p.is_active AS bi_is_active,
           o.name AS o_name, o.list_price AS o_list, o.online_price AS o_online,
           o.product_code AS o_pc, o.sku AS o_sku, o.is_active AS o_is_active
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

  debugInfo.raw_cart_count = rows.rows.length;

  const items: Record<string, unknown>[] = [];
  const filteredOut: { product_id: number; reason: string }[] = [];
  for (const row of rows.rows) {
    const { item, filteredOutReason } = buildCartItem(row);
    if (item) items.push(item);
    else if (filteredOutReason) filteredOut.push(filteredOutReason);
  }
  debugInfo.filtered_cart_count = items.length;
  debugInfo.filtered_out = filteredOut;

  let subtotal = 0;
  for (const item of items) {
    const v = toFloatOrZero(item.subtotal);
    item.subtotal = v;
    subtotal += v;
  }

  const settings = await fetchShippingSettings(db, lineAccountId);
  let shippingFee = settings && settings.shipping_fee !== null && settings.shipping_fee !== undefined ? toFloatOrZero(settings.shipping_fee) : 50;
  const freeShippingMin =
    settings && settings.free_shipping_min !== null && settings.free_shipping_min !== undefined ? toFloatOrZero(settings.free_shipping_min) : 500;

  if (subtotal >= freeShippingMin) {
    shippingFee = 0;
  }
  const total = subtotal + shippingFee;

  const body: Record<string, unknown> = {
    items,
    subtotal,
    shipping_fee: shippingFee,
    free_shipping_min: freeShippingMin,
    total,
    item_count: items.length,
  };
  if (debug) body.debug = debugInfo;

  return ok(true, '', body);
}

// ---------------------------------------------------------------------------
// action=add_to_cart (handleAddToCart, L845-971)
// ---------------------------------------------------------------------------

export async function handleAddToCart(db: Kysely<TenantDB>, data: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = data.line_user_id;
  const productId = toIntOrZero(data.product_id);
  const quantity = Math.max(1, toIntOrZero(data.quantity ?? 1));

  if (phpFalsy(lineUserId) || productId <= 0) {
    return ok(false, 'Missing required fields');
  }

  const resolved = await getUserIdFromLineUserId(db, lineUserId);
  if (!resolved || phpFalsy(resolved.userId)) {
    return ok(false, 'User not found');
  }
  const { userId, lineAccountId } = resolved;

  const productSource = await resolveCartProductSourceWithShopDefault(db, data.product_source ?? null, lineAccountId);

  let productName: string | null;

  if (productSource === 'shop_products') {
    const rows = await sql<{ id: number; name: string; list_price: unknown; online_price: unknown; saleable_qty: unknown }>`
      SELECT id, name, list_price, online_price, saleable_qty
      FROM shop_products
      WHERE id = ${productId} AND line_account_id = ${lineAccountId}
        AND storefront_enabled = 1 AND is_active = 1
    `.execute(db);
    const product = rows.rows[0];
    if (!product) return ok(false, 'Product not found');
    const stock = toFloatOrZero(product.saleable_qty);
    if (stock < quantity) return ok(false, 'Not enough stock');
    productName = product.name;
  } else {
    const rows = await sql<{ id: number; name: string; price: unknown; sale_price: unknown; stock: number | null }>`
      SELECT id, name, price, sale_price, stock FROM business_items WHERE id = ${productId} AND is_active = 1
    `.execute(db);
    const product = rows.rows[0];
    if (!product) return ok(false, 'Product not found');
    if (product.stock !== null && product.stock !== undefined && Number(product.stock) < quantity) {
      return ok(false, 'Not enough stock');
    }
    productName = product.name;
  }

  const lineUserIdStr = strOrEmpty(lineUserId);
  await sql`
    INSERT INTO cart_items (user_id, line_user_id, product_id, product_source, quantity)
    VALUES (${userId}, ${lineUserIdStr}, ${productId}, ${productSource}, ${quantity})
    ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
  `.execute(db);

  const cartCountResult = await sql<{ total: unknown }>`SELECT SUM(quantity) as total FROM cart_items WHERE user_id = ${userId}`.execute(db);
  const cartCount = toIntOrZero(cartCountResult.rows[0]?.total ?? 0);

  return ok(true, 'Added to cart', { cart_count: cartCount, product_name: productName });
}

// ---------------------------------------------------------------------------
// action=update_cart (handleUpdateCart, L976-1035)
// ---------------------------------------------------------------------------

export async function handleUpdateCart(db: Kysely<TenantDB>, data: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = data.line_user_id;
  const productId = toIntOrZero(data.product_id);
  const quantity = toIntOrZero(data.quantity ?? 0);

  if (phpFalsy(lineUserId) || productId <= 0) {
    return ok(false, 'Missing required fields');
  }

  const resolved = await getUserIdFromLineUserId(db, lineUserId);
  if (!resolved || phpFalsy(resolved.userId)) {
    return ok(false, 'User not found');
  }
  const { userId, lineAccountId } = resolved;

  const productSource = await resolveCartProductSourceWithShopDefault(db, data.product_source ?? null, lineAccountId);

  if (quantity <= 0) {
    await sql`
      DELETE FROM cart_items
      WHERE user_id = ${userId} AND product_id = ${productId}
        AND IFNULL(product_source, 'business_items') = ${productSource}
    `.execute(db);
  } else {
    if (productSource === 'shop_products') {
      const rows = await sql<{ saleable_qty: unknown }>`
        SELECT saleable_qty FROM shop_products
        WHERE id = ${productId} AND line_account_id = ${lineAccountId} AND storefront_enabled = 1 AND is_active = 1
      `.execute(db);
      const stockRaw = rows.rows[0]?.saleable_qty;
      if (stockRaw !== null && stockRaw !== undefined && toFloatOrZero(stockRaw) < quantity) {
        return ok(false, 'Not enough stock');
      }
    } else {
      const rows = await sql<{ stock: number | null }>`SELECT stock FROM business_items WHERE id = ${productId}`.execute(db);
      const stockRaw = rows.rows[0]?.stock;
      if (stockRaw !== null && stockRaw !== undefined && Number(stockRaw) < quantity) {
        return ok(false, 'Not enough stock');
      }
    }

    await sql`
      UPDATE cart_items SET quantity = ${quantity}, updated_at = NOW()
      WHERE user_id = ${userId} AND product_id = ${productId}
        AND IFNULL(product_source, 'business_items') = ${productSource}
    `.execute(db);
  }

  const cartCountResult = await sql<{ total: unknown }>`SELECT SUM(quantity) as total FROM cart_items WHERE user_id = ${userId}`.execute(db);
  const cartCount = toIntOrZero(cartCountResult.rows[0]?.total ?? 0);

  return ok(true, 'Cart updated', { cart_count: cartCount });
}

// ---------------------------------------------------------------------------
// action=remove_from_cart (handleRemoveFromCart, L1040-1076)
// ---------------------------------------------------------------------------

export async function handleRemoveFromCart(db: Kysely<TenantDB>, data: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = data.line_user_id;
  const productId = toIntOrZero(data.product_id);

  if (phpFalsy(lineUserId) || productId <= 0) {
    return ok(false, 'Missing required fields');
  }

  const resolved = await getUserIdFromLineUserId(db, lineUserId);
  if (!resolved || phpFalsy(resolved.userId)) {
    return ok(false, 'User not found');
  }
  const { userId, lineAccountId } = resolved;

  const productSource = await resolveCartProductSourceWithShopDefault(db, data.product_source ?? null, lineAccountId);

  await sql`
    DELETE FROM cart_items
    WHERE user_id = ${userId} AND product_id = ${productId}
      AND IFNULL(product_source, 'business_items') = ${productSource}
  `.execute(db);

  const cartCountResult = await sql<{ total: unknown }>`SELECT SUM(quantity) as total FROM cart_items WHERE user_id = ${userId}`.execute(db);
  const cartCount = toIntOrZero(cartCountResult.rows[0]?.total ?? 0);

  return ok(true, 'Item removed', { cart_count: cartCount });
}

// ---------------------------------------------------------------------------
// action=clear_cart (handleClearCart, L1081-1100)
// ---------------------------------------------------------------------------

export async function handleClearCart(db: Kysely<TenantDB>, data: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = data.line_user_id;

  if (phpFalsy(lineUserId)) {
    return ok(false, 'Missing line_user_id');
  }

  const resolved = await getUserIdFromLineUserId(db, lineUserId);
  if (!resolved || phpFalsy(resolved.userId)) {
    return ok(false, 'User not found');
  }

  await sql`DELETE FROM cart_items WHERE user_id = ${resolved.userId}`.execute(db);

  return ok(true, 'Cart cleared', { cart_count: 0 });
}
