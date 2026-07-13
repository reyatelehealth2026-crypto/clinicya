import { z } from 'zod';

/**
 * wishlist.ts — zod contracts for the ported api/wishlist.php actions owned by this batch:
 * `list` (default action when `action` is omitted — PHP's switch defaults to 'list'), `toggle`,
 * `remove`. `add` and `check` are explicitly OUT of scope (zero line-mini-app callers, confirmed via
 * grep of src/lib/wishlist-api.ts).
 *
 * DELIBERATELY does not use envelope.ts's `flatSuccessEnvelope()` — api/wishlist.php's response shape
 * is its own ad hoc inline `json_encode([...])` at each call site (no shared `jsonResponse()` helper
 * unlike member.php/rewards.php), so schemas here are hand-built to match it exactly:
 *   - never a `message` key on the plain list/count success paths;
 *   - `error` (not `message`) on every failure path, via the file-level `catch (Exception $e)` and the
 *     per-branch `Missing user or product` checks;
 *   - always implicit HTTP 200 (no header()/http_response_code() call anywhere in the file).
 * Read api/wishlist.php in full (170 lines) before writing this file.
 */

// ---------------------------------------------------------------------------
// Shared sub-shape
// ---------------------------------------------------------------------------

/**
 * `SELECT w.*, p.name, p.sku, p.price, p.sale_price, p.image_url, p.stock, is_on_sale, discount_percent
 *  FROM user_wishlist w JOIN business_items p ...` — the two CASE-computed columns are ported verbatim
 * in the route handler's SQL, not recomputed in JS, so their nullability/rounding matches MySQL's.
 */
export const WishlistItemSchema = z.object({
  id: z.number(),
  user_id: z.number(),
  line_user_id: z.string().nullable(),
  product_id: z.number(),
  line_account_id: z.number().nullable(),
  price_when_added: z.number(),
  notify_on_sale: z.union([z.number(), z.boolean()]).nullable(),
  notify_on_restock: z.union([z.number(), z.boolean()]).nullable(),
  notified_at: z.string().nullable(),
  created_at: z.string(),
  name: z.string(),
  sku: z.string().nullable(),
  price: z.number().nullable(),
  sale_price: z.number().nullable(),
  image_url: z.string().nullable(),
  stock: z.number().nullable(),
  /** `CASE WHEN p.sale_price IS NOT NULL AND p.sale_price < w.price_when_added THEN 1 ELSE 0 END`. */
  is_on_sale: z.union([z.literal(0), z.literal(1)]),
  /** `ROUND((1 - p.sale_price / w.price_when_added) * 100)`, else 0. */
  discount_percent: z.number(),
});
export type WishlistItem = z.infer<typeof WishlistItemSchema>;

// ---------------------------------------------------------------------------
// GET action=list (also the no-action-param default)
// ---------------------------------------------------------------------------

export const WishlistListQuerySchema = z.object({
  action: z.literal('list').optional(),
  line_user_id: z.string().optional(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
});
export type WishlistListQuery = z.infer<typeof WishlistListQuerySchema>;

/** `!$userId && !$lineUserId` early-exit branch — note: no `count` key here, unlike the normal branch. */
const WishlistListEmpty = z.object({ success: z.literal(true), items: z.array(WishlistItemSchema).length(0) });
const WishlistListOk = z.object({
  success: z.literal(true),
  items: z.array(WishlistItemSchema),
  count: z.number(),
});
const WishlistListFail = z.object({ success: z.literal(false), error: z.string() });
export const WishlistListResponseSchema = z.union([WishlistListOk, WishlistListEmpty, WishlistListFail]);
export type WishlistListResponse = z.infer<typeof WishlistListResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=toggle
// ---------------------------------------------------------------------------

export const WishlistToggleRequestSchema = z.object({
  action: z.literal('toggle'),
  line_user_id: z.string(),
  product_id: z.union([z.string(), z.number()]),
  line_account_id: z.union([z.string(), z.number()]).optional(),
});
export type WishlistToggleRequest = z.infer<typeof WishlistToggleRequestSchema>;

const WishlistToggleOk = z.object({
  success: z.literal(true),
  is_favorite: z.boolean(),
  message: z.string(),
});
const WishlistMissingParams = z.object({ success: z.literal(false), error: z.literal('Missing user or product') });
export const WishlistToggleResponseSchema = z.union([WishlistToggleOk, WishlistMissingParams]);
export type WishlistToggleResponse = z.infer<typeof WishlistToggleResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=remove
// ---------------------------------------------------------------------------

export const WishlistRemoveRequestSchema = z.object({
  action: z.literal('remove'),
  line_user_id: z.string(),
  product_id: z.union([z.string(), z.number()]),
  line_account_id: z.union([z.string(), z.number()]).optional(),
});
export type WishlistRemoveRequest = z.infer<typeof WishlistRemoveRequestSchema>;

const WishlistRemoveOk = z.object({ success: z.literal(true), message: z.literal('ลบออกจากรายการโปรดแล้ว') });
export const WishlistRemoveResponseSchema = z.union([WishlistRemoveOk, WishlistMissingParams]);
export type WishlistRemoveResponse = z.infer<typeof WishlistRemoveResponseSchema>;
