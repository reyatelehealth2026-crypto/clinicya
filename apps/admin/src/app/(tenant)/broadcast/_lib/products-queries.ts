import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * products-queries.ts — read-side port of the "products" tab of includes/broadcast/products.php
 * (`renderBroadcastProducts($db, $currentBotId)`, invoked unconditionally at the bottom of that
 * file). Read the full 522-line source before touching this file. Scope boundary vs.
 * `./products-actions.ts`: this file is reads only — the three POST actions
 * (create_broadcast/send_broadcast/delete_campaign) are ported there.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════
 * Product catalog read: reused, not re-derived — with one documented gap
 * ═══════════════════════════════════════════════════════════════════════════════════════
 * products.php:12-15 constructs `new UnifiedShop($db, null, $currentBotId)` and calls
 * `$shop->getItems(['in_stock' => true], 100)` (classes/UnifiedShop.php:244-286) +
 * `$shop->getCategories(50)` (lines 204-223) + `$shop->getItem($id)` (lines 295-319) — all
 * three resolve to `business_items`/`business_categories` on `getItemsTable()`/
 * `getCategoriesTable()`'s auto-detection (always `business_items`/`product_categories` on the
 * committed tenant schema — packages/db's generated tenant-db.d.ts confirms both tables exist
 * unconditionally, same "schema-drift shim, no equivalent need here" precedent as
 * apps/admin/src/app/api/miniapp/shop-products/_lib/query.ts's own module doc). Per this
 * batch's brief, `getInStockProducts()`/`getCatalogProductById()` below deliberately reuse
 * THAT query's FROM/JOIN/SELECT shape (`business_items p LEFT JOIN business_categories c`,
 * `line_account_id = ? OR line_account_id IS NULL` scoping, `COALESCE(NULLIF(image_url,''),
 * NULLIF(photo_path,''))` image resolution) rather than re-deriving UnifiedShop's own SQL from
 * scratch — SAME underlying table, SAME committed-schema simplification precedent.
 *
 * CONFIRMED GAP (why this file does NOT literally call `getProductsAction()`/re-export from
 * shop-products/_lib/query.ts instead of writing its own two functions here): that function's
 * `ProductsActionParams` has NO `in_stock`/`stock > 0` filter at all — it filters
 * `is_active = 1` plus optional category/search/brand, nothing stock-related — because
 * checkout.php's own `handleGetProducts()` (what it ports) never applies one either.
 * `UnifiedShop::getItems()`, by contrast, DOES apply `stock > 0` whenever the caller passes
 * `['in_stock' => true]` (line 272-274), which products.php's campaign-composer form always
 * does. Reusing `getProductsAction()` unmodified would silently let out-of-stock products
 * into the broadcast campaign composer — a real behavior regression, not a "close enough"
 * simplification. `getCategoriesAction()`'s own `categories` action, separately, reads a
 * DIFFERENT table (`shop_products`, an Odoo-catalog-source query) — this tab doesn't use it;
 * `getCategoriesTable()` mirrors the `is_active=1 ORDER BY sort_order ASC` shape instead
 * (`business_categories`, matching `query.ts`'s own categories sub-query in
 * `getProductsAction()`). Given the divergent filter requirement, this file writes its own
 * lean queries against the identical table/column shape rather than either re-exporting or
 * forking `query.ts` in place.
 *
 * `getCatalogCategories()` below is a DEAD FETCH, ported faithfully: products.php:54
 * (`$categories = $shop->getCategories(50);`) assigns the result to a local that is NEVER
 * referenced anywhere else in the file (grepped) — no category filter/list renders in the
 * create-campaign form's HTML. `ProductsTab.tsx` calls this function for parity (so a future
 * reader diffing against PHP sees the same query run) but does not render its result, matching
 * PHP's own dead-fetch behavior exactly.
 */

// ---------------------------------------------------------------------------
// Product catalog — products.php:53 ($shop->getItems(['in_stock' => true], 100))
// ---------------------------------------------------------------------------

export interface BroadcastCatalogProduct {
  id: number;
  name: string;
  imageUrl: string | null;
  price: number | null;
  salePrice: number | null;
}

interface RawCatalogProductRow {
  id: number;
  name: string;
  price: string | number | null;
  sale_price: string | number | null;
  image_url: string | null;
}

function normalizeCatalogProduct(row: RawCatalogProductRow): BroadcastCatalogProduct {
  return {
    id: Number(row.id),
    name: row.name,
    imageUrl: row.image_url && row.image_url !== '' ? row.image_url : null,
    price: row.price !== null && row.price !== '' ? Number(row.price) : null,
    salePrice: row.sale_price !== null && row.sale_price !== '' ? Number(row.sale_price) : null,
  };
}

/**
 * Port of `UnifiedShop::getItems(['in_stock' => true], 100)` restricted to `business_items`
 * (see module doc): `is_active = 1 AND stock > 0`, scoped `(line_account_id = ? OR
 * line_account_id IS NULL)` when `currentBotId` is set, `ORDER BY id DESC` (UnifiedShop's own
 * default `$filters['order'] ?? 'id DESC'`), `LIMIT 100`.
 */
export async function getInStockProducts(
  db: Kysely<TenantDB>,
  currentBotId: number | null
): Promise<BroadcastCatalogProduct[]> {
  const scopeClause =
    currentBotId !== null
      ? sql`AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)`
      : sql``;
  const result = await sql<RawCatalogProductRow>`
    SELECT id, name, price, sale_price,
      COALESCE(NULLIF(image_url, ''), NULLIF(photo_path, '')) AS image_url
    FROM business_items
    WHERE is_active = 1 AND stock > 0 ${scopeClause}
    ORDER BY id DESC
    LIMIT 100
  `.execute(db);
  return result.rows.map(normalizeCatalogProduct);
}

/**
 * Port of `UnifiedShop::getItem($id)` (classes/UnifiedShop.php:295-319), the per-product
 * lookup `createBroadcastAction()` calls once per selected product id to snapshot
 * `name`/`image_url`/`price`/`sale_price` into `broadcast_items` at campaign-creation time.
 * Same `is_active = 1` + optional line_account_id scoping as `getInStockProducts()` — NOT
 * additionally filtered by `stock > 0` (PHP's `getItem()` has no such filter; only
 * `getItems()`'s caller-supplied `in_stock` filter does).
 */
export async function getCatalogProductById(
  db: Kysely<TenantDB>,
  currentBotId: number | null,
  productId: number
): Promise<BroadcastCatalogProduct | null> {
  const scopeClause =
    currentBotId !== null
      ? sql`AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)`
      : sql``;
  const result = await sql<RawCatalogProductRow>`
    SELECT id, name, price, sale_price,
      COALESCE(NULLIF(image_url, ''), NULLIF(photo_path, '')) AS image_url
    FROM business_items
    WHERE id = ${productId} AND is_active = 1 ${scopeClause}
    LIMIT 1
  `.execute(db);
  const row = result.rows[0];
  return row ? normalizeCatalogProduct(row) : null;
}

/** Dead fetch, ported for parity only — see module doc. Not rendered by ProductsTab. */
export interface BroadcastCatalogCategory {
  id: number;
  name: string;
}

export async function getCatalogCategories(
  db: Kysely<TenantDB>,
  currentBotId: number | null
): Promise<BroadcastCatalogCategory[]> {
  const scopeClause =
    currentBotId !== null ? sql`AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)` : sql``;
  const result = await sql<{ id: number; name: string }>`
    SELECT id, name FROM business_categories WHERE is_active = 1 ${scopeClause} ORDER BY sort_order ASC LIMIT 50
  `.execute(db);
  return result.rows.map((r) => ({ id: Number(r.id), name: r.name }));
}

// ---------------------------------------------------------------------------
// Tags — products.php:58-63
// ---------------------------------------------------------------------------

export interface BroadcastProductTag {
  id: number;
  name: string;
  color: string | null;
}

/** products.php:58-63: `SELECT * FROM user_tags WHERE line_account_id = ? OR line_account_id
 * IS NULL ORDER BY name` — narrowed to the columns the send-target modal actually renders
 * (id/name/color, see products.php:441-445). */
export async function getProductBroadcastTags(
  db: Kysely<TenantDB>,
  currentBotId: number | null
): Promise<BroadcastProductTag[]> {
  const result = await sql<{ id: number; name: string; color: string | null }>`
    SELECT id, name, color FROM user_tags
    WHERE line_account_id = ${currentBotId} OR line_account_id IS NULL
    ORDER BY name
  `.execute(db);
  return result.rows.map((r) => ({ id: Number(r.id), name: r.name, color: r.color }));
}

// ---------------------------------------------------------------------------
// Campaigns list — products.php:65-70, plus per-campaign items (products.php:341-343)
// ---------------------------------------------------------------------------

export interface BroadcastCampaign {
  id: number;
  name: string;
  status: string | null;
  autoTagEnabled: boolean;
  createdAt: Date;
}

/** products.php:65-70: `SELECT * FROM broadcast_campaigns WHERE (line_account_id = ? OR
 * line_account_id IS NULL) ORDER BY created_at DESC LIMIT 20`. */
export async function getBroadcastCampaigns(
  db: Kysely<TenantDB>,
  currentBotId: number | null
): Promise<BroadcastCampaign[]> {
  const result = await sql<{
    id: number;
    name: string;
    status: string | null;
    auto_tag_enabled: number | null;
    created_at: Date;
  }>`
    SELECT id, name, status, auto_tag_enabled, created_at FROM broadcast_campaigns
    WHERE (line_account_id = ${currentBotId} OR line_account_id IS NULL)
    ORDER BY created_at DESC LIMIT 20
  `.execute(db);
  return result.rows.map((r) => ({
    id: Number(r.id),
    name: r.name,
    status: r.status,
    autoTagEnabled: Boolean(r.auto_tag_enabled),
    createdAt: r.created_at,
  }));
}

export interface BroadcastCampaignItem {
  id: number;
  itemName: string;
  itemImage: string | null;
}

/** products.php:341-343: `SELECT * FROM broadcast_items WHERE broadcast_id = ? ORDER BY
 * sort_order` — narrowed to what the campaign card's thumbnail strip renders. */
export async function getBroadcastCampaignItems(
  db: Kysely<TenantDB>,
  campaignId: number
): Promise<BroadcastCampaignItem[]> {
  const result = await sql<{ id: number; item_name: string; item_image: string | null }>`
    SELECT id, item_name, item_image FROM broadcast_items WHERE broadcast_id = ${campaignId} ORDER BY sort_order
  `.execute(db);
  return result.rows.map((r) => ({ id: Number(r.id), itemName: r.item_name, itemImage: r.item_image }));
}
