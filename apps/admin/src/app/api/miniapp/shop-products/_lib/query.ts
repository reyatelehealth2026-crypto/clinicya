import { sql, type Expression, type Kysely, type SqlBool } from 'kysely';
import type { TenantDB } from '@reya/db';
import type {
  ShopCategoriesResponse,
  ShopCategoryItem,
  ShopProduct,
  ShopProductDetailResponse,
  ShopProductsResponse,
} from '@reya/contracts';

/**
 * query.ts — TypeScript port of api/checkout.php's handleGetProducts()/
 * handleGetProductDetail() (`products`/`product_detail` actions) PLUS
 * api/shop-products.php's own standalone `categories` action. Read both PHP
 * functions/branches in full before writing this file — see route.ts's
 * module doc for the scope-correction rationale (checkout.php, NOT
 * shop-products.php, is what the mini-app's product/category browsing
 * actually calls).
 *
 * SIMPLIFICATION (flagged, same established precedent as
 * apps/admin/src/app/(tenant)/users/queries.ts's own module doc): PHP guards
 * several `business_items`/`business_categories` columns with runtime
 * `hasTableColumn()`/`SHOW COLUMNS` probes (schema-drift compatibility shims
 * with no equivalent need on a tenant DB created from the committed
 * template — see packages/db's generated `tenant-db.d.ts`, which already
 * reflects the real committed column set: `business_items.badges` and
 * `business_items.promotion_label` genuinely do NOT exist as columns, and
 * NEITHER does `business_categories.icon_url` (that table only has
 * `image_url` — see `BusinessCategories` in tenant-db.d.ts); every other
 * guarded column DOES exist). This port always selects the full "happy
 * path" column set and treats `badges`/`promotion_label`/
 * `business_categories.icon_url` as always-absent (`[]` / `null`, or
 * `NULL AS icon_url` in the categories query below, mirroring checkout.php's
 * own `hasTableColumn('business_categories','icon_url') ? 'icon_url' :
 * 'NULL AS icon_url'` guard) rather than re-probing the schema per request.
 * Selecting the bare `icon_url` column unguarded is a hard SQL error
 * (Unknown column) on every real tenant DB — this was a live parity-miss
 * (mig-verify Phase 3 batch 1 finding) until fixed.
 *
 * Raw `sql` escape hatch throughout (not the typed query builder), same
 * rationale as users/queries.ts: the shared `Kysely<TenantDB>` has no
 * `CamelCasePlugin`, and several fragments (dynamic WHERE/ORDER BY) are
 * easiest to express as literal SQL text.
 */

// ---------------------------------------------------------------------------
// Shared row shape + normalization (buildBusinessItemSelectFields() +
// normalizeShopProductRow() + enrichProductRow() + buildShopImageGallery())
// ---------------------------------------------------------------------------

interface RawProductRow {
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
  category_name: string | null;
  image_gallery: string | null;
  photo_path: string | null;
  is_flash_sale: number | null;
  is_favorite: number;
}

/** `decodeJsonArrayValue()` — tolerant JSON-array decode, `[]` on anything else. */
function decodeJsonArray(value: string | null): unknown[] {
  if (!value) return [];
  try {
    const decoded: unknown = JSON.parse(value);
    return Array.isArray(decoded) ? decoded : [];
  } catch {
    return [];
  }
}

/** `buildShopImageGallery()` — decoded image_gallery entries + image_url/photo_path, deduped, falsy filtered. */
function buildImageGallery(row: RawProductRow, imageUrl: string | null): string[] {
  const gallery: string[] = [];
  for (const entry of decodeJsonArray(row.image_gallery)) {
    if (typeof entry === 'string' && entry.trim() !== '') gallery.push(entry.trim());
  }
  for (const candidate of [imageUrl, row.photo_path]) {
    if (candidate && typeof candidate === 'string' && candidate.trim() !== '') gallery.push(candidate.trim());
  }
  return Array.from(new Set(gallery));
}

/** `enrichProductRow()` + `normalizeShopProductRow()` composed. */
function normalizeProduct(row: RawProductRow): ShopProduct {
  const price = row.price !== null && row.price !== '' ? Number(row.price) : null;
  const sale = row.sale_price !== null && row.sale_price !== '' ? Number(row.sale_price) : null;

  let discountPercent: number | null = null;
  let promotionLabel: string | null = null;
  let badges: ShopProduct['badges'] = [];
  if (sale !== null && price !== null && sale < price && price > 0) {
    discountPercent = Math.round((1 - sale / price) * 100);
    promotionLabel = 'โปรโมชัน';
    badges = [{ text: `-${discountPercent}%`, color: 'red' }];
  }

  const imageUrl = (row as unknown as { image_url: string | null }).image_url;
  return {
    id: Number(row.id),
    name: row.name,
    description: row.description,
    // CONTRACT-DRIFT FIX: pass the raw DECIMAL value straight through.
    // enrichProductRow() only floatval()s price/sale_price into LOCAL
    // variables for the discount computation above — it never reassigns
    // $p['price']/$p['sale_price'] back onto the row, so PHP's actual
    // response carries the raw PDO string (e.g. "25.00"), not a cast float.
    // Number()-casting here silently turned those into JS numbers, a real
    // field-level parity mismatch (mig-verify Phase 3 batch 1 finding).
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
    category_name: row.category_name,
    image_gallery: buildImageGallery(row, imageUrl),
    photo_path: row.photo_path,
    image_url: imageUrl,
    is_favorite: Boolean(row.is_favorite),
    is_flash_sale: row.is_flash_sale ?? 0,
    promotion_label: promotionLabel,
    discount_percent: discountPercent,
    badges,
    brand: row.manufacturer,
  };
}

const PRODUCT_SELECT_FRAGMENT = sql.raw(`
  p.id, p.name, p.description, p.price, p.sale_price, p.stock, p.sku, p.barcode,
  p.manufacturer, p.generic_name, p.usage_instructions, p.properties_other, p.unit,
  p.category_id, p.image_gallery, p.photo_path, p.is_flash_sale,
  COALESCE(NULLIF(p.image_url, ''), NULLIF(p.photo_path, '')) AS image_url,
  c.name AS category_name
`);

/** `getExistingUserIdFromLineUserId()`. */
async function findExistingUserId(db: Kysely<TenantDB>, lineUserId: string | null): Promise<number | null> {
  if (!lineUserId) return null;
  const result = await sql<{ id: number }>`SELECT id FROM users WHERE line_user_id = ${lineUserId} LIMIT 1`.execute(
    db
  );
  return result.rows[0] ? Number(result.rows[0].id) : null;
}

/** `buildProductSortClause()`. */
function sortClause(sort: string | undefined): ReturnType<typeof sql.raw> {
  switch (sort) {
    case 'price_asc':
      return sql.raw(`COALESCE(NULLIF(p.sale_price,''), NULLIF(p.price,''), 0) ASC, p.id DESC`);
    case 'price_desc':
      return sql.raw(`COALESCE(NULLIF(p.sale_price,''), NULLIF(p.price,''), 0) DESC, p.id DESC`);
    case 'discount':
      return sql.raw(
        `CASE WHEN p.price IS NOT NULL AND p.sale_price IS NOT NULL AND p.sale_price < p.price AND p.price > 0 THEN (1 - p.sale_price / p.price) ELSE 0 END DESC, p.id DESC`
      );
    case 'name_asc':
      return sql.raw(`p.name ASC`);
    default:
      return sql.raw(`p.id DESC`);
  }
}

// ---------------------------------------------------------------------------
// action=products (handleGetProducts())
// ---------------------------------------------------------------------------

export interface ProductsActionParams {
  lineAccountId: string | null;
  categoryId: string | null;
  search: string;
  sort: string | undefined;
  brand: string;
  lineUserId: string | null;
  limit: number;
  offset: number;
}

export async function getProductsAction(
  db: Kysely<TenantDB>,
  params: ProductsActionParams
): Promise<ShopProductsResponse> {
  const wishlistUserId = await findExistingUserId(db, params.lineUserId);
  const canJoinWishlist = wishlistUserId !== null;

  const conditions: Expression<SqlBool>[] = [sql<SqlBool>`p.is_active = 1`];
  if (params.lineAccountId) {
    conditions.push(sql<SqlBool>`(p.line_account_id = ${params.lineAccountId} OR p.line_account_id IS NULL)`);
  }
  if (params.categoryId) {
    conditions.push(sql<SqlBool>`p.category_id = ${params.categoryId}`);
  }
  if (params.search) {
    const like = `%${params.search}%`;
    conditions.push(
      sql<SqlBool>`(p.name LIKE ${like} OR p.description LIKE ${like} OR p.sku LIKE ${like} OR p.manufacturer LIKE ${like} OR p.generic_name LIKE ${like})`
    );
  }
  if (params.brand !== '') {
    conditions.push(sql<SqlBool>`p.manufacturer = ${params.brand}`);
  }
  const whereExpr = sql<SqlBool>`(${sql.join(conditions, sql` AND `)})`;

  const favoriteSelect = canJoinWishlist
    ? sql.raw('CASE WHEN uw.id IS NULL THEN 0 ELSE 1 END AS is_favorite')
    : sql.raw('0 AS is_favorite');
  const wishlistJoin = canJoinWishlist
    ? sql`LEFT JOIN user_wishlist uw ON uw.product_id = p.id AND uw.user_id = ${wishlistUserId}`
    : sql``;

  const rowsResult = await sql<RawProductRow>`
    SELECT ${PRODUCT_SELECT_FRAGMENT}, ${favoriteSelect}
      FROM business_items p
      LEFT JOIN business_categories c ON c.id = p.category_id AND c.is_active = 1
      ${wishlistJoin}
     WHERE ${whereExpr}
     ORDER BY ${sortClause(params.sort)}
     LIMIT ${params.limit} OFFSET ${params.offset}
  `.execute(db);

  const countResult = await sql<{ total: number }>`
    SELECT COUNT(*) AS total FROM business_items p WHERE ${whereExpr}
  `.execute(db);
  const total = Number(countResult.rows[0]?.total ?? 0);

  const categoriesConditions: Expression<SqlBool>[] = [sql<SqlBool>`is_active = 1`];
  if (params.lineAccountId) {
    categoriesConditions.push(sql<SqlBool>`(line_account_id = ${params.lineAccountId} OR line_account_id IS NULL)`);
  }
  // `business_categories` has no `icon_url` column on the committed tenant
  // template (see module doc) — always `NULL AS icon_url`, matching
  // checkout.php's `hasTableColumn('business_categories','icon_url')` guard
  // evaluating false on every real tenant DB. Selecting the bare column
  // 500s (Unknown column 'icon_url').
  const categoriesResult = await sql<{ id: number; name: string; icon_url: string | null }>`
    SELECT id, name, NULL AS icon_url FROM business_categories
     WHERE ${sql.join(categoriesConditions, sql` AND `)}
     ORDER BY sort_order, name
  `.execute(db);

  const brandsConditions: Expression<SqlBool>[] = [
    sql<SqlBool>`is_active = 1`,
    sql<SqlBool>`manufacturer IS NOT NULL`,
    sql<SqlBool>`manufacturer != ''`,
  ];
  if (params.lineAccountId) {
    brandsConditions.push(sql<SqlBool>`(line_account_id = ${params.lineAccountId} OR line_account_id IS NULL)`);
  }
  if (params.categoryId) {
    brandsConditions.push(sql<SqlBool>`category_id = ${params.categoryId}`);
  }
  const brandsResult = await sql<{ manufacturer: string }>`
    SELECT DISTINCT manufacturer FROM business_items
     WHERE ${sql.join(brandsConditions, sql` AND `)}
     ORDER BY manufacturer ASC LIMIT 16
  `.execute(db);

  return {
    success: true,
    message: '',
    products: rowsResult.rows.map(normalizeProduct),
    categories: categoriesResult.rows.map((r) => ({ id: Number(r.id), name: r.name, icon_url: r.icon_url })),
    brands: brandsResult.rows.map((r) => r.manufacturer).filter((m): m is string => Boolean(m)),
    offset: params.offset,
    limit: params.limit,
    total,
    has_more: params.offset + params.limit < total,
  };
}

// ---------------------------------------------------------------------------
// action=product_detail (handleGetProductDetail())
// ---------------------------------------------------------------------------

export async function getProductDetailAction(
  db: Kysely<TenantDB>,
  productId: number,
  lineAccountId: string | null,
  lineUserId: string | null
): Promise<ShopProductDetailResponse> {
  if (productId <= 0) {
    return { success: false, message: 'Missing product_id' };
  }

  try {
    const wishlistUserId = await findExistingUserId(db, lineUserId);
    const canJoinWishlist = wishlistUserId !== null;

    const conditions: Expression<SqlBool>[] = [sql<SqlBool>`p.id = ${productId}`, sql<SqlBool>`p.is_active = 1`];
    if (lineAccountId) {
      conditions.push(sql<SqlBool>`(p.line_account_id = ${lineAccountId} OR p.line_account_id IS NULL)`);
    }

    const favoriteSelect = canJoinWishlist
      ? sql.raw('CASE WHEN uw.id IS NULL THEN 0 ELSE 1 END AS is_favorite')
      : sql.raw('0 AS is_favorite');
    const wishlistJoin = canJoinWishlist
      ? sql`LEFT JOIN user_wishlist uw ON uw.product_id = p.id AND uw.user_id = ${wishlistUserId}`
      : sql``;

    const result = await sql<RawProductRow>`
      SELECT ${PRODUCT_SELECT_FRAGMENT}, ${favoriteSelect}
        FROM business_items p
        LEFT JOIN business_categories c ON c.id = p.category_id AND c.is_active = 1
        ${wishlistJoin}
       WHERE ${sql.join(conditions, sql` AND `)}
    `.execute(db);

    const row = result.rows[0];
    if (!row) {
      return { success: false, message: 'Product not found' };
    }
    return { success: true, message: '', product: normalizeProduct(row) };
  } catch (err) {
    return { success: false, message: `Error loading product: ${err instanceof Error ? err.message : String(err)}` };
  }
}

// ---------------------------------------------------------------------------
// action=categories (shop-products.php's own standalone branch)
// ---------------------------------------------------------------------------

export interface CategoriesActionParams {
  lineAccountId: number;
  /** `useShopProductCatalog()` — true when the Odoo/shop_products storefront catalog is active for this line_account_id. */
  useOdoo: boolean;
}

export async function getCategoriesAction(
  db: Kysely<TenantDB>,
  params: CategoriesActionParams
): Promise<ShopCategoriesResponse> {
  try {
    let categories: ShopCategoryItem[];

    if (params.useOdoo) {
      const result = await sql<{ category: string }>`
        SELECT DISTINCT category FROM shop_products
         WHERE line_account_id = ${params.lineAccountId}
           AND storefront_enabled = 1
           AND is_active = 1
           AND category IS NOT NULL AND category <> ''
         ORDER BY category ASC
      `.execute(db);
      categories = result.rows.map((r) => ({ id: r.category, name: r.category, code: r.category }));
    } else {
      categories = await plainCategories(db);
    }

    return { success: true, categories, category_id_is_string: params.useOdoo };
  } catch (err) {
    return { success: false, error: err instanceof Error ? err.message : String(err) };
  }
}

/** `item_categories` first, `product_categories` fallback (schema-drift try/catch — kept even though `item_categories` always exists on a template-created tenant DB, since the fallback is cheap and this is a read-only, low-traffic action). */
async function plainCategories(db: Kysely<TenantDB>): Promise<ShopCategoryItem[]> {
  try {
    const result = await sql<{ id: number; name: string }>`SELECT id, name FROM item_categories ORDER BY id`.execute(
      db
    );
    return result.rows.map((r) => ({ id: Number(r.id), name: r.name }));
  } catch {
    try {
      const result = await sql<{ id: number; name: string }>`
        SELECT id, name FROM product_categories ORDER BY id
      `.execute(db);
      return result.rows.map((r) => ({ id: Number(r.id), name: r.name }));
    } catch {
      return [];
    }
  }
}
