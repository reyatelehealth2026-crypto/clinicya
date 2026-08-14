import { sql, type Expression, type Kysely, type SqlBool } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { ShopCategoryItem, ShopProduct, ShopProductBadge } from '@reya/contracts';

/**
 * catalog-queries.ts — read-side port of includes/broadcast/catalog.php's
 * (662 LOC) top-of-file data fetches (lines 9-38), used by
 * ../_components/CatalogTab.tsx. Read the full source before touching this
 * file — every mutation the tab's JS performs (draft save/load/delete,
 * `action=send_flex`) is a client-side `fetch()` straight to the
 * PRE-EXISTING `api/broadcast_drafts.php` / `api/broadcast.php` PHP
 * endpoints (out of this batch's scope entirely, ported verbatim in
 * ../_components/CatalogBuilderClient.tsx) — nothing here writes anything.
 *
 * PRODUCTS/CATEGORIES: catalog.php uses `new UnifiedShop($db, null,
 * $currentBotId)` then `$shop->getItems(['in_stock' => true], 200)` /
 * `$shop->getCategories(50)` (classes/UnifiedShop.php). Per this batch's
 * brief, this reuses the shape of the already-ported shop-products precedent
 * (apps/admin/src/app/api/miniapp/shop-products/_lib/{query.ts,
 * catalogSource.ts} — `ShopProduct/ShopCategoryItem` from `@reya/contracts`)
 * rather than re-deriving a bespoke row shape, so the two product pickers in
 * this app (this tab's and the shop-products-backed one) never diverge.
 *
 * IMPORTANT SCOPE NOTE: `getProductsAction`/`getCategoriesAction` in that
 * precedent file are NOT reused by direct call here — they query different
 * tables than `UnifiedShop` does for THIS PHP page:
 *   - `UnifiedShop::getItemsTable()` only ever returns `business_items`
 *     (same table `getProductsAction` uses) — SAME table, but
 *     `getProductsAction` has no `in_stock` (`stock > 0`) filter option at
 *     all (checkout.php's own product browsing never filters by stock), so
 *     it cannot express `getItems(['in_stock' => true], 200)` without
 *     reimplementing the WHERE clause here regardless.
 *   - `UnifiedShop::getCategoriesTable()` prioritizes `product_categories`
 *     over `item_categories` (classes/UnifiedShop.php lines 96-102) and,
 *     critically, NEVER joins `business_categories` — a genuinely different
 *     table from the one `getProductsAction`'s embedded `categories` field
 *     (or `getCategoriesAction`'s Odoo branch) uses. Both `product_categories`
 *     and `item_categories` exist unconditionally on the committed tenant
 *     template (packages/db's generated `tenant-db.d.ts` confirms both), so
 *     — same "skip the runtime tableExists()/hasColumn() probing, resolve
 *     statically" simplification already established by catalogSource.ts's
 *     own module doc — this always queries `product_categories` (the table
 *     `getCategoriesTable()`'s priority order would pick).
 * The SELECT below is therefore hand-written against `business_items`
 * directly (not a call into query.ts), but maps its rows into the exact same
 * `ShopProduct` shape query.ts's own `normalizeProduct()` produces, reusing
 * the identical field-by-field normalization logic (image-gallery build,
 * discount/badge computation) so nothing about the RESULT shape drifts.
 */

const CATALOG_PRODUCTS_LIMIT = 200;
const CATALOG_CATEGORIES_LIMIT = 50;

// ---------------------------------------------------------------------------
// Shared helpers (mirrors query.ts's decodeJsonArray/buildImageGallery)
// ---------------------------------------------------------------------------

function decodeJsonArray(value: string | null): unknown[] {
  if (!value) return [];
  try {
    const decoded: unknown = JSON.parse(value);
    return Array.isArray(decoded) ? decoded : [];
  } catch {
    return [];
  }
}

function buildImageGallery(imageGallery: string | null, imageUrl: string | null, photoPath: string | null): string[] {
  const gallery: string[] = [];
  for (const entry of decodeJsonArray(imageGallery)) {
    if (typeof entry === 'string' && entry.trim() !== '') gallery.push(entry.trim());
  }
  for (const candidate of [imageUrl, photoPath]) {
    if (candidate && candidate.trim() !== '') gallery.push(candidate.trim());
  }
  return Array.from(new Set(gallery));
}

/**
 * PHP's `?:` ("truthy-or-fallback") operator for the value shapes PDO/Kysely
 * actually hand back here (string | number | null). `''`/`'0'`/`0`/null are
 * the only falsy cases that matter — mirrors
 * (tenant)/crm-dashboard-advanced/_lib/format.ts's `isPhpEmpty()` precedent,
 * narrowed to this file's scalar shapes (that helper also treats `false` as
 * empty, which never occurs here).
 */
function phpTruthy(value: string | number | null | undefined): boolean {
  if (value === null || value === undefined) return false;
  if (typeof value === 'number') return value !== 0;
  return value !== '' && value !== '0';
}

// ---------------------------------------------------------------------------
// getCatalogProducts — UnifiedShop::getItems(['in_stock' => true], 200)
// ---------------------------------------------------------------------------

interface RawCatalogProductRow {
  id: number;
  name: string;
  description: string | null;
  price: string | number | null;
  sale_price: string | number | null;
  stock: number | null;
  sku: string | null;
  barcode: string | null;
  manufacturer: string | null;
  generic_name: string | null;
  usage_instructions: string | null;
  properties_other: string | null;
  unit: string | null;
  category_id: number | null;
  image_gallery: string | null;
  photo_path: string | null;
  is_flash_sale: number | null;
  image_url: string | null;
}

function normalizeCatalogProduct(row: RawCatalogProductRow): ShopProduct {
  const price = row.price !== null && row.price !== '' ? Number(row.price) : null;
  const sale = row.sale_price !== null && row.sale_price !== '' ? Number(row.sale_price) : null;

  let discountPercent: number | null = null;
  let promotionLabel: string | null = null;
  let badges: ShopProductBadge[] = [];
  if (sale !== null && price !== null && sale < price && price > 0) {
    discountPercent = Math.round((1 - sale / price) * 100);
    promotionLabel = 'โปรโมชัน';
    badges = [{ text: `-${discountPercent}%`, color: 'red' }];
  }

  return {
    id: Number(row.id),
    name: row.name,
    description: row.description,
    price: row.price,
    sale_price: row.sale_price,
    stock: row.stock === null ? null : Number(row.stock),
    sku: row.sku,
    barcode: row.barcode,
    manufacturer: row.manufacturer,
    generic_name: row.generic_name,
    usage_instructions: row.usage_instructions,
    properties_other: row.properties_other,
    unit: row.unit,
    category_id: row.category_id === null ? null : Number(row.category_id),
    // UnifiedShop::getItems() is a plain `SELECT * FROM business_items` with
    // NO join to any categories table (unlike checkout.php's business_items/
    // business_categories join) — category_name is therefore never resolved
    // by the real PHP page and is always null here. Not a gap to "fill in".
    category_name: null,
    image_gallery: buildImageGallery(row.image_gallery, row.image_url, row.photo_path),
    photo_path: row.photo_path,
    image_url: row.image_url,
    // No wishlist/user context in a broadcast-composition screen (unlike
    // checkout.php's LEFT JOIN user_wishlist) — always false, same reasoning
    // catalogSource.ts documents for its own Odoo/non-Odoo split.
    is_favorite: false,
    is_flash_sale: row.is_flash_sale ?? 0,
    promotion_label: promotionLabel,
    discount_percent: discountPercent,
    badges,
    brand: row.manufacturer,
  };
}

/**
 * `UnifiedShop::getItems(['in_stock' => true], 200)` — `SELECT * FROM
 * business_items WHERE is_active = 1 AND [(line_account_id = ? OR
 * line_account_id IS NULL)] AND stock > 0 ORDER BY id DESC LIMIT 200`
 * (classes/UnifiedShop.php lines 244-290; the `line_account_id` clause is
 * conditional on `$this->lineAccountId` being truthy, exactly like every
 * other UnifiedShop method — `lineAccountId <= 0` here means "unscoped",
 * matching `catalogSource.ts`'s own `lineAccountId <= 0` convention).
 */
export async function getCatalogProducts(db: Kysely<TenantDB>, lineAccountId: number): Promise<ShopProduct[]> {
  const conditions: Expression<SqlBool>[] = [sql<SqlBool>`is_active = 1`, sql<SqlBool>`stock > 0`];
  if (lineAccountId > 0) {
    conditions.push(sql<SqlBool>`(line_account_id = ${lineAccountId} OR line_account_id IS NULL)`);
  }
  const whereExpr = sql<SqlBool>`(${sql.join(conditions, sql` AND `)})`;

  const result = await sql<RawCatalogProductRow>`
    SELECT id, name, description, price, sale_price, stock, sku, barcode,
           manufacturer, generic_name, usage_instructions, properties_other, unit,
           category_id, image_gallery, photo_path, is_flash_sale,
           COALESCE(NULLIF(image_url, ''), NULLIF(photo_path, '')) AS image_url
      FROM business_items
     WHERE ${whereExpr}
     ORDER BY id DESC
     LIMIT ${CATALOG_PRODUCTS_LIMIT}
  `.execute(db);

  return result.rows.map(normalizeCatalogProduct);
}

// ---------------------------------------------------------------------------
// getCatalogCategories — UnifiedShop::getCategories(50)
// ---------------------------------------------------------------------------

/**
 * `UnifiedShop::getCategories(50)` — `SELECT * FROM product_categories
 * WHERE is_active = 1 [AND (line_account_id = ? OR line_account_id IS
 * NULL)] ORDER BY sort_order ASC LIMIT 50` (classes/UnifiedShop.php lines
 * 204-223 + 96-102's table-priority pick — see module doc for why
 * `product_categories`, not `business_categories`).
 */
export async function getCatalogCategories(db: Kysely<TenantDB>, lineAccountId: number): Promise<ShopCategoryItem[]> {
  const conditions: Expression<SqlBool>[] = [sql<SqlBool>`is_active = 1`];
  if (lineAccountId > 0) {
    conditions.push(sql<SqlBool>`(line_account_id = ${lineAccountId} OR line_account_id IS NULL)`);
  }
  const whereExpr = sql<SqlBool>`(${sql.join(conditions, sql` AND `)})`;

  const result = await sql<{ id: number; name: string | null }>`
    SELECT id, name FROM product_categories
     WHERE ${whereExpr}
     ORDER BY sort_order ASC
     LIMIT ${CATALOG_CATEGORIES_LIMIT}
  `.execute(db);

  return result.rows.map((r) => ({ id: Number(r.id), name: r.name ?? '' }));
}

// ---------------------------------------------------------------------------
// Dead-but-fetched parity reads — catalog.php lines 25-38
// ---------------------------------------------------------------------------

export interface CatalogSegmentParity {
  id: number;
  name: string;
  description: string | null;
  userCount: number;
}

/**
 * catalog.php lines 26-30: `try { SELECT id, name, description, user_count
 * FROM customer_segments ORDER BY name } catch (Exception $e) {}` into
 * `$segments`. CONFIRMED FINDING (read the full 662-line source): `$segments`
 * is NEVER referenced again anywhere else in the file — no `<?= $segments
 * ?>`, no `data-segment-*`, no JS array. It is fetched and immediately
 * discarded by the real PHP page. Ported here for byte-for-byte read parity
 * (not silently dropped) but deliberately unused by
 * ../_components/CatalogTab.tsx — inventing a segment picker PHP itself
 * never built would be new behavior, not a port. Note this query carries NO
 * `line_account_id` filter at all (unlike ../_lib/send-queries.ts's
 * `getSegments()`, a DIFFERENT PHP page's DIFFERENT segments query that DOES
 * filter) — reproduced exactly as written, not "fixed" to match that other
 * page's scoping.
 */
export async function getCatalogSegmentsForParity(db: Kysely<TenantDB>): Promise<CatalogSegmentParity[]> {
  try {
    const result = await sql<{ id: number; name: string; description: string | null; user_count: number | null }>`
      SELECT id, name, description, user_count FROM customer_segments ORDER BY name
    `.execute(db);
    return result.rows.map((r) => ({
      id: Number(r.id),
      name: r.name,
      description: r.description,
      userCount: Number(r.user_count ?? 0),
    }));
  } catch {
    return [];
  }
}

export interface CatalogUserTagParity {
  id: number;
  name: string;
  color: string | null;
}

/**
 * catalog.php lines 33-38: `try { SELECT id, name, color FROM user_tags
 * WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name } catch
 * (Exception $e) {}` into `$userTags`. Same CONFIRMED FINDING as
 * `getCatalogSegmentsForParity()` above — `$userTags` is fetched and never
 * referenced again anywhere in catalog.php. Ported for parity, deliberately
 * unused.
 */
export async function getCatalogUserTagsForParity(
  db: Kysely<TenantDB>,
  lineAccountId: number
): Promise<CatalogUserTagParity[]> {
  try {
    const result = await sql<{ id: number; name: string; color: string | null }>`
      SELECT id, name, color FROM user_tags
       WHERE line_account_id = ${lineAccountId} OR line_account_id IS NULL
       ORDER BY name
    `.execute(db);
    return result.rows.map((r) => ({ id: Number(r.id), name: r.name, color: r.color }));
  } catch {
    return [];
  }
}

// ---------------------------------------------------------------------------
// Trim down to the shape ../_components/CatalogBuilderClient.tsx (the
// 'use client' bubble-builder island) actually consumes — mirrors
// catalog.php lines 17-23's own `$productsJson` trim exactly.
// ---------------------------------------------------------------------------

export interface CatalogBuilderProduct {
  id: number;
  name: string;
  price: number;
  image: string;
  categoryId: number | null;
}

export interface CatalogBuilderCategory {
  id: string;
  name: string;
}

const PLACEHOLDER_PRODUCT_IMAGE = 'https://via.placeholder.com/100';

/**
 * catalog.php lines 17-23:
 *   $productsJson = json_encode(array_map(fn($p) => [
 *     'id' => $p['id'], 'name' => $p['name'],
 *     'price' => $p['sale_price'] ?: $p['price'],
 *     'image' => $p['image_url'] ?: 'https://via.placeholder.com/100',
 *     'cat' => $p['category_id'] ?? null
 *   ], $products), JSON_UNESCAPED_UNICODE);
 * `?:` is PHP's truthy-or-fallback operator (see `phpTruthy()` above), NOT
 * `??` (null-coalescing) — a `sale_price`/`image_url` of `'0'`/`0`/`''` also
 * falls through to the fallback, not just `null`.
 */
export function toCatalogBuilderProducts(products: ShopProduct[]): CatalogBuilderProduct[] {
  return products.map((p) => {
    const effectivePriceRaw = phpTruthy(p.sale_price) ? p.sale_price : p.price;
    const effectiveImage = phpTruthy(p.image_url) ? (p.image_url as string) : PLACEHOLDER_PRODUCT_IMAGE;
    return {
      id: p.id,
      name: p.name,
      price: Number(effectivePriceRaw ?? 0),
      image: effectiveImage,
      categoryId: p.category_id,
    };
  });
}

/** Same field trim for categories — catalog.php's `<option value="<?= $c['id'] ?>">` (line 72). */
export function toCatalogBuilderCategories(categories: ShopCategoryItem[]): CatalogBuilderCategory[] {
  return categories.map((c) => ({ id: String(c.id), name: c.name }));
}
