import { z } from 'zod';
import { flatSuccessEnvelope } from './envelope';

/**
 * shop-products.ts — zod contracts for THREE read-only actions ported under
 * one Route Handler (`/api/miniapp/shop-products`), per the phase-3-batch-1
 * brief's scope correction (verified by reading the real call graph, not the
 * literal file list handed down):
 *
 *   - `products`        — api/checkout.php's `action=products` (handleGetProducts()).
 *                          This — NOT shop-products.php — is what the mini-app's
 *                          ShopClient.tsx actually calls via shop-api.ts's
 *                          fetchProducts(). checkout.php itself is otherwise
 *                          untouched this batch (its cart/order/slip actions
 *                          stay on PHP).
 *   - `product_detail`   — api/checkout.php's `action=product_detail`
 *                          (handleGetProductDetail()), same reasoning.
 *   - `categories`       — api/shop-products.php's own standalone `action=categories`
 *                          branch. Genuinely public/read-only; ported for
 *                          completeness even though today's client gets
 *                          categories EMBEDDED inside `products` rather than
 *                          calling this standalone.
 *
 * api/shop-products.php's own `products`/`product_detail` actions (the
 * shop_products/business_items/cny_products branching at the top of that
 * file) are explicitly OUT of scope — dead from the mini-app's perspective.
 *
 * `products`/`product_detail` share ONE product row shape (both read from
 * `business_items` via the SAME `buildBusinessItemSelectFields()` +
 * `normalizeShopProductRow()` pair in checkout.php — see ShopProductSchema).
 * checkout.php's local `jsonResponse($success, $message, $data)` is
 * STRUCTURALLY IDENTICAL to member.php/rewards.php's (`array_merge(['success'=>..,
 * 'message'=>..], $data)`, always HTTP 200), so those two actions reuse
 * `flatSuccessEnvelope()`. `categories` does NOT — shop-products.php builds
 * its own ad hoc `json_encode()` with no `message` key and `error` (not
 * `message`) on failure; modeled as its own bespoke schema.
 *
 * Read api/checkout.php's handleGetProducts()/handleGetProductDetail() (and
 * their buildBusinessItemSelectFields/normalizeShopProductRow/
 * buildProductSearchWhere/buildProductSortClause helpers) and
 * api/shop-products.php's `categories` branch in full before writing this file.
 */

// ---------------------------------------------------------------------------
// Shared product row — checkout.php's business_items projection
// ---------------------------------------------------------------------------

/** `enrichProductRow()`'s decoded badge shape (`json_decode($p['badges'] ?? [], true)`, defaults `[]`). */
export const ShopProductBadgeSchema = z.object({
  text: z.string(),
  color: z.string().optional(),
});
export type ShopProductBadge = z.infer<typeof ShopProductBadgeSchema>;

/**
 * `buildBusinessItemSelectFields()` + `normalizeShopProductRow()`'s final
 * row shape. Two columns (`badges`, `promotion_label`) do not exist on the
 * committed tenant template (packages/db's generated schema confirms this —
 * same "hasTableColumn() drift-guard has no equivalent need on a
 * template-created tenant DB" simplification already established in
 * apps/admin/src/app/(tenant)/users/queries.ts) — they are ALWAYS `NULL AS
 * ...` on a real tenant DB today, so `badges` is always `[]` and
 * `promotion_label` is always `null` UNLESS a discount is computed
 * client-side by `enrichProductRow()` (sale_price < price -> synthesized
 * `-N%` badge + `'โปรโมชัน'` label). Modeled as nullable/array-with-default,
 * not `.literal(null)`, so a future schema migration adding real columns
 * does not break this contract.
 */
export const ShopProductSchema = z.object({
  id: z.number(),
  name: z.string(),
  description: z.string().nullable(),
  price: z.union([z.number(), z.string()]).nullable(),
  sale_price: z.union([z.number(), z.string()]).nullable(),
  stock: z.number().nullable(),
  sku: z.string().nullable(),
  barcode: z.string().nullable(),
  manufacturer: z.string().nullable(),
  generic_name: z.string().nullable(),
  usage_instructions: z.string().nullable(),
  properties_other: z.string().nullable(),
  unit: z.string().nullable(),
  category_id: z.number().nullable(),
  category_name: z.string().nullable(),
  /** `buildShopImageGallery()` — deduped [image_gallery JSON..., image_url, photo_path], never the raw JSON string. */
  image_gallery: z.array(z.string()),
  photo_path: z.string().nullable(),
  /** COALESCE(image_url, photo_path) as image_url. */
  image_url: z.string().nullable(),
  is_favorite: z.boolean(),
  /** `!empty($p['is_flash_sale'])`-shaped column; MySQL TINYINT via PDO can surface as 0/1 or boolean depending on driver config. */
  is_flash_sale: z.union([z.number(), z.boolean()]),
  promotion_label: z.string().nullable(),
  discount_percent: z.number().nullable(),
  badges: z.array(ShopProductBadgeSchema),
  /** Alias of `manufacturer`, set by normalizeShopProductRow(). */
  brand: z.string().nullable(),
});
export type ShopProduct = z.infer<typeof ShopProductSchema>;

// ---------------------------------------------------------------------------
// GET action=products (checkout.php handleGetProducts())
// ---------------------------------------------------------------------------

/**
 * `sort` accepts any string — `buildProductSortClause()`'s `switch` falls
 * through to the `id DESC` default for anything not in this list (including
 * `undefined`/omitted), so an unrecognized value is NOT a validation error.
 */
export const ShopProductSortSchema = z.enum(['price_asc', 'price_desc', 'discount', 'name_asc']);
export type ShopProductSort = z.infer<typeof ShopProductSortSchema>;

export const ShopProductsQuerySchema = z.object({
  action: z.literal('products'),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  category_id: z.union([z.string(), z.number()]).optional(),
  search: z.string().optional(),
  sort: z.string().optional(), // see ShopProductSortSchema doc — PHP does not reject unknown values
  brand: z.string().optional(),
  line_user_id: z.string().optional(),
  limit: z.union([z.string(), z.number()]).optional(), // clamped server-side to [1,24], default 12
  offset: z.union([z.string(), z.number()]).optional(), // clamped server-side to >=0, default 0
  // The four params below are sent by shop-api.ts's fetchProducts() but are
  // DEAD on the server side today — handleGetProducts() never reads
  // `$_GET['include_zero_price']` / `include_inactive` / `catalog_mode` /
  // `catalog_bucket` at all (confirmed by reading the full function). Kept
  // here ONLY so a real request carrying them still validates; the route
  // handler must NOT branch on them (that would be new behavior PHP never had).
  include_zero_price: z.union([z.string(), z.number()]).optional(),
  include_inactive: z.union([z.string(), z.number()]).optional(),
  catalog_mode: z.string().optional(),
  catalog_bucket: z.string().optional(),
});
export type ShopProductsQuery = z.infer<typeof ShopProductsQuerySchema>;

export const ShopProductsCategorySchema = z.object({
  id: z.number(),
  name: z.string(),
  icon_url: z.string().nullable(),
});
export type ShopProductsCategory = z.infer<typeof ShopProductsCategorySchema>;

const ShopProductsOk = flatSuccessEnvelope({
  success: z.literal(true),
  products: z.array(ShopProductSchema),
  categories: z.array(ShopProductsCategorySchema),
  brands: z.array(z.string()),
  offset: z.number(),
  limit: z.number(),
  total: z.number(),
  has_more: z.boolean(),
});
const ShopProductsFail = flatSuccessEnvelope({ success: z.literal(false) });
export const ShopProductsResponseSchema = z.union([ShopProductsOk, ShopProductsFail]);
export type ShopProductsResponse = z.infer<typeof ShopProductsResponseSchema>;

// ---------------------------------------------------------------------------
// GET action=product_detail (checkout.php handleGetProductDetail())
// ---------------------------------------------------------------------------

export const ShopProductDetailQuerySchema = z.object({
  action: z.literal('product_detail'),
  product_id: z.union([z.string(), z.number()]),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  line_user_id: z.string().optional(),
});
export type ShopProductDetailQuery = z.infer<typeof ShopProductDetailQuerySchema>;

const ShopProductDetailOk = flatSuccessEnvelope({
  success: z.literal(true),
  product: ShopProductSchema,
});
const ShopProductDetailFail = flatSuccessEnvelope({ success: z.literal(false) });
export const ShopProductDetailResponseSchema = z.union([ShopProductDetailOk, ShopProductDetailFail]);
export type ShopProductDetailResponse = z.infer<typeof ShopProductDetailResponseSchema>;

// ---------------------------------------------------------------------------
// GET action=categories (shop-products.php's OWN standalone branch — NOT
// checkout.php, NOT flatSuccessEnvelope-shaped)
// ---------------------------------------------------------------------------

export const ShopCategoriesQuerySchema = z.object({
  action: z.literal('categories'),
  account: z.union([z.string(), z.number()]).optional(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
});
export type ShopCategoriesQuery = z.infer<typeof ShopCategoriesQuerySchema>;

/**
 * Shape genuinely differs by source branch: the Odoo/`shop_products` branch
 * emits `{id: category-name-string, name, code: category-name-string}`; the
 * `item_categories`/`product_categories` fallback (the common case on a
 * non-Odoo tenant) emits `{id: number, name}` with NO `code` key at all —
 * `code` is therefore optional here rather than split into two literal
 * unions, since both branches are otherwise structurally compatible.
 * `$useCnyProducts` is hardcoded `false` in shop-products.php (line 27) —
 * the `cny_products` branch is dead code, not modeled.
 */
export const ShopCategoryItemSchema = z.object({
  id: z.union([z.string(), z.number()]),
  name: z.string(),
  code: z.union([z.string(), z.number()]).optional(),
});
export type ShopCategoryItem = z.infer<typeof ShopCategoryItemSchema>;

export const ShopCategoriesOkSchema = z.object({
  success: z.literal(true),
  categories: z.array(ShopCategoryItemSchema),
  /** true iff the Odoo/`shop_products` storefront catalog is active for this line_account_id (useShopProductCatalog()). */
  category_id_is_string: z.boolean(),
});
export type ShopCategoriesOk = z.infer<typeof ShopCategoriesOkSchema>;

export const ShopCategoriesFailSchema = z.object({
  success: z.literal(false),
  error: z.string(),
});
export type ShopCategoriesFail = z.infer<typeof ShopCategoriesFailSchema>;

export const ShopCategoriesResponseSchema = z.union([ShopCategoriesOkSchema, ShopCategoriesFailSchema]);
export type ShopCategoriesResponse = z.infer<typeof ShopCategoriesResponseSchema>;

/** All three actions respond HTTP 200 unconditionally in the PHP source (no http_response_code() call on any branch of any of the three). */
export const SHOP_PRODUCTS_STATUS = 200 as const;
