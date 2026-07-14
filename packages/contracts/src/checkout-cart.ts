import { z } from 'zod';
import { flatSuccessEnvelope } from './envelope';

/**
 * checkout-cart.ts — zod contracts for the ported api/checkout.php cart-CRUD + pricing actions
 * (cartAndPricing builder's lane, docs/plans/2026-07-12-nextjs-full-migration-plan.md Phase 3):
 * `cart` (GET), `add_to_cart`/`update_cart`/`remove_from_cart`/`clear_cart` (POST) — implemented at
 * apps/admin/src/app/api/miniapp/checkout/cart/route.ts — and `validate_promo` (POST) — implemented at
 * apps/admin/src/app/api/miniapp/checkout/pricing/route.ts.
 *
 * All six actions use api/checkout.php's local `jsonResponse($success, $message, $data = [])`
 * (`array_merge(['success'=>.., 'message'=>..], $data)`), always HTTP 200 (no `http_response_code()`
 * call anywhere in checkout.php) — modeled via `flatSuccessEnvelope()`, same as member.ts/rewards.ts.
 *
 * Field lists read directly off api/checkout.php (handleGetCart L1105-1283, handleAddToCart L845-971,
 * handleUpdateCart L976-1035, handleRemoveFromCart L1040-1076, handleClearCart L1081-1100,
 * handleValidatePromo L2202-2312 + validateHardcodedPromo L2317-2348) — read in full before writing this
 * file — cross-checked against line-mini-app/src/lib/shop-api.ts's CartLine/CartResponse/
 * ValidatePromoResponse client types.
 *
 * CART ITEM SHAPE IS DELIBERATELY "LEAKY": handleGetCart()'s SQL is `SELECT c.*, p.name AS bi_name, ...,
 * o.name AS o_name, ...` (a LEFT JOIN against both business_items and shop_products), and PHP never
 * unsets the raw joined alias columns after deriving name/price/sale_price/image_url/is_active/
 * product_source/subtotal onto the same array — the real production response carries BOTH the raw
 * `bi_*`/`o_*` alias fields AND the derived overrides. CartItemSchema below reproduces that faithfully
 * (not curated down to just the "clean" fields) so mig-verify's field-level parity check has something
 * to match against real traffic.
 *
 * DECIMAL PASSTHROUGH: `price`/`sale_price` are raw MySQL DECIMAL passthrough (PHP PDO with
 * ATTR_EMULATE_PREPARES=false still returns DECIMAL columns as strings, e.g. `"20.00"`) ONLY on the
 * business_items branch — `$item['price'] = $item['bi_price'] ?? null;` never casts. The shop_products
 * branch DOES explicitly `(float)`-cast price/sale_price in PHP, so those come back as genuine JSON
 * numbers. Both are modeled as `z.union([z.string(), z.number()]).nullable()` to allow either shape
 * (same technique as shop-products.ts's ShopProduct.price/sale_price).
 */

// ---------------------------------------------------------------------------
// Shared sub-shapes
// ---------------------------------------------------------------------------

const DecimalLike = z.union([z.string(), z.number()]).nullable();

/** `SELECT c.*, p.* AS bi_*, o.* AS o_*` row from handleGetCart(), post per-row derivation (L1196-1238). */
export const CartItemSchema = z.object({
  // raw cart_items columns (`c.*`)
  id: z.number(),
  line_account_id: z.number(),
  line_user_id: z.string(),
  product_id: z.number(),
  quantity: z.number().nullable(),
  /** Written by nothing server-side (see module doc's preserved gap (a)) but still selected/echoed raw. */
  unit_id: z.number().nullable(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
  user_id: z.number(),
  // raw joined business_items columns
  bi_name: z.string().nullable(),
  bi_price: DecimalLike,
  bi_sale_price: DecimalLike,
  bi_image_url: z.string().nullable(),
  bi_is_active: z.union([z.number(), z.boolean()]).nullable(),
  // raw joined shop_products columns
  o_name: z.string().nullable(),
  o_list: DecimalLike,
  o_online: DecimalLike,
  o_pc: z.string().nullable(),
  o_sku: z.string().nullable(),
  o_is_active: z.union([z.number(), z.boolean()]).nullable(),
  // derived / overwritten fields (overwrite the raw `product_source` column above — only one key survives)
  name: z.string().nullable(),
  price: DecimalLike,
  sale_price: DecimalLike,
  image_url: z.string().nullable(),
  is_active: z.union([z.number(), z.boolean()]).nullable(),
  product_source: z.enum(['business_items', 'shop_products']),
  subtotal: z.number(),
});
export type CartItem = z.infer<typeof CartItemSchema>;

const CartDebugSchema = z
  .object({
    input_user_id: z.union([z.string(), z.number()]).nullable(),
    input_line_user_id: z.string().nullable(),
    line_user_id_length: z.number(),
    user_found: z.boolean().optional(),
    db_user_id: z.union([z.string(), z.number()]).optional(),
    user_created: z.boolean().optional(),
    new_user_id: z.union([z.string(), z.number()]).optional(),
    raw_cart_count: z.number().optional(),
    filtered_cart_count: z.number().optional(),
    filtered_out: z.array(z.object({ product_id: z.number(), reason: z.string() })).optional(),
  })
  .optional();

// ---------------------------------------------------------------------------
// GET action=cart (handleGetCart, L1105-1283)
// ---------------------------------------------------------------------------

export const CartQuerySchema = z.object({
  action: z.literal('cart'),
  user_id: z.union([z.string(), z.number()]).optional(),
  line_user_id: z.string().optional(),
  /** Presence-only flag (`isset($_GET['debug'])`) — any value (including `''`) turns debug mode on. */
  debug: z.string().optional(),
});
export type CartQuery = z.infer<typeof CartQuerySchema>;

const CartOk = flatSuccessEnvelope({
  success: z.literal(true),
  items: z.array(CartItemSchema),
  subtotal: z.number(),
  shipping_fee: z.number(),
  free_shipping_min: z.number(),
  total: z.number(),
  item_count: z.number(),
  debug: CartDebugSchema,
});
const CartFail = flatSuccessEnvelope({ success: z.literal(false), debug: CartDebugSchema });
export const CartResponseSchema = z.union([CartOk, CartFail]);
export type CartResponse = z.infer<typeof CartResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=add_to_cart (handleAddToCart, L845-971)
// ---------------------------------------------------------------------------

export const AddToCartRequestSchema = z.object({
  action: z.literal('add_to_cart'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  product_id: z.union([z.string(), z.number()]),
  quantity: z.union([z.string(), z.number()]).optional(),
  product_source: z.enum(['business_items', 'shop_products']).optional(),
  /** Sent by line-mini-app's addToCart() but NEVER read by api/checkout.php (grep-verified, module doc
   *  gap (a)) — accepted here for shape-compat with the real request body, always ignored server-side. */
  unit_id: z.union([z.string(), z.number()]).optional(),
});
export type AddToCartRequest = z.infer<typeof AddToCartRequestSchema>;

const AddToCartOk = flatSuccessEnvelope({
  success: z.literal(true),
  cart_count: z.number(),
  product_name: z.string().nullable(),
});
/**
 * Failure `message` is one of: 'Missing required fields' | 'User not found' | 'Product not found' |
 * 'Not enough stock' | 'Cart migration required (product_source)' (this last one is unreachable in this
 * port — see cartProductSource.ts's module doc SIMPLIFICATION).
 */
const AddToCartFail = flatSuccessEnvelope({ success: z.literal(false) });
export const AddToCartResponseSchema = z.union([AddToCartOk, AddToCartFail]);
export type AddToCartResponse = z.infer<typeof AddToCartResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=update_cart (handleUpdateCart, L976-1035)
// ---------------------------------------------------------------------------

export const UpdateCartRequestSchema = z.object({
  action: z.literal('update_cart'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  product_id: z.union([z.string(), z.number()]),
  /** `quantity <= 0` deletes the line instead of updating it. */
  quantity: z.union([z.string(), z.number()]),
  product_source: z.enum(['business_items', 'shop_products']).optional(),
  /** See AddToCartRequestSchema's `unit_id` doc — same preserved gap. */
  unit_id: z.union([z.string(), z.number()]).optional(),
});
export type UpdateCartRequest = z.infer<typeof UpdateCartRequestSchema>;

const UpdateCartOk = flatSuccessEnvelope({ success: z.literal(true), cart_count: z.number() });
/** Failure `message`: 'Missing required fields' | 'User not found' | 'Not enough stock'. */
const UpdateCartFail = flatSuccessEnvelope({ success: z.literal(false) });
export const UpdateCartResponseSchema = z.union([UpdateCartOk, UpdateCartFail]);
export type UpdateCartResponse = z.infer<typeof UpdateCartResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=remove_from_cart (handleRemoveFromCart, L1040-1076)
// ---------------------------------------------------------------------------

export const RemoveFromCartRequestSchema = z.object({
  action: z.literal('remove_from_cart'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  product_id: z.union([z.string(), z.number()]),
  product_source: z.enum(['business_items', 'shop_products']).optional(),
  /** See AddToCartRequestSchema's `unit_id` doc — same preserved gap. */
  unit_id: z.union([z.string(), z.number()]).optional(),
});
export type RemoveFromCartRequest = z.infer<typeof RemoveFromCartRequestSchema>;

const RemoveFromCartOk = flatSuccessEnvelope({ success: z.literal(true), cart_count: z.number() });
/** Failure `message`: 'Missing required fields' | 'User not found'. */
const RemoveFromCartFail = flatSuccessEnvelope({ success: z.literal(false) });
export const RemoveFromCartResponseSchema = z.union([RemoveFromCartOk, RemoveFromCartFail]);
export type RemoveFromCartResponse = z.infer<typeof RemoveFromCartResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=clear_cart (handleClearCart, L1081-1100)
// ---------------------------------------------------------------------------

export const ClearCartRequestSchema = z.object({
  action: z.literal('clear_cart'),
  line_user_id: z.string(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
});
export type ClearCartRequest = z.infer<typeof ClearCartRequestSchema>;

const ClearCartOk = flatSuccessEnvelope({ success: z.literal(true), cart_count: z.literal(0) });
/** Failure `message`: 'Missing line_user_id' | 'User not found'. */
const ClearCartFail = flatSuccessEnvelope({ success: z.literal(false) });
export const ClearCartResponseSchema = z.union([ClearCartOk, ClearCartFail]);
export type ClearCartResponse = z.infer<typeof ClearCartResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=validate_promo (handleValidatePromo + validateHardcodedPromo)
// ---------------------------------------------------------------------------

export const ValidatePromoRequestSchema = z.object({
  action: z.literal('validate_promo'),
  code: z.string(),
  line_user_id: z.string().optional(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  subtotal: z.union([z.string(), z.number()]).optional(),
});
export type ValidatePromoRequest = z.infer<typeof ValidatePromoRequestSchema>;

const ValidatePromoOk = flatSuccessEnvelope({
  success: z.literal(true),
  valid: z.literal(true),
  discount: z.number(),
  discount_type: z.enum(['fixed', 'percentage']),
  code: z.string(),
  /** Present only on the `promotions`-table-driven branch (unreachable on the committed template — see module doc). */
  discount_value: z.number().optional(),
  promo_id: z.number().optional(),
  promo_name: z.string().optional(),
});
const ValidatePromoFail = flatSuccessEnvelope({ success: z.literal(false), valid: z.literal(false) });
export const ValidatePromoResponseSchema = z.union([ValidatePromoOk, ValidatePromoFail]);
export type ValidatePromoResponse = z.infer<typeof ValidatePromoResponseSchema>;
