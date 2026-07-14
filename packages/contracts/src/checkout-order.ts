import { z } from 'zod';
import { flatSuccessEnvelope } from './envelope';

/**
 * checkout-order.ts — zod contracts for the ported api/checkout.php order-creation + payment-slip-upload
 * flow (orderCreation builder's lane, docs/plans/2026-07-12-nextjs-full-migration-plan.md Phase 3):
 * `create_order` (POST, JSON body) and `upload_slip` (POST, multipart/form-data) — implemented at
 * apps/admin/src/app/api/miniapp/checkout/order/route.ts.
 *
 * Both actions use api/checkout.php's local `jsonResponse($success, $message, $data = [])`
 * (`array_merge(['success'=>.., 'message'=>..], $data)`), always HTTP 200 (checkout.php's jsonResponse()
 * never calls http_response_code() — confirmed, no call anywhere in the whole 2794-line file) — modeled
 * via `flatSuccessEnvelope()`, same as checkout-cart.ts/member.ts/rewards.ts.
 *
 * Field lists are read directly off api/checkout.php's handleCreateOrder() (L1288-1656) and
 * handleUploadSlip() (L1733-1863) — read in full before editing this file — cross-checked against
 * line-mini-app/src/lib/shop-api.ts's CreateShopOrderInput/CreateShopOrderResult/UploadSlipResult client
 * types.
 *
 * upload_slip has NO request-body schema here on purpose (per this batch's brief): the real request is a
 * `multipart/form-data` FormData, not JSON — there is nothing a zod object schema can validate against a
 * raw multipart body without a parser this package doesn't own. Only its RESPONSE shape is modeled.
 *
 * `ar_id` is always `z.null()` on the success shape this round — AccountReceivableService's AR-ledger hook
 * (api/checkout.php L1594-1608) is deliberately deferred (see createOrder.ts's own TODO comment); a future
 * phase that ports it will need to loosen this to `z.number().nullable()`.
 */

// ---------------------------------------------------------------------------
// POST action=create_order (handleCreateOrder, L1288-1656)
// ---------------------------------------------------------------------------

/** `address` sub-object — every field is optional/defaults to `''` server-side (PHP: `$address['x'] ?? ''`). */
export const CreateOrderAddressSchema = z.object({
  name: z.string().optional(),
  phone: z.string().optional(),
  address: z.string().optional(),
  subdistrict: z.string().optional(),
  district: z.string().optional(),
  province: z.string().optional(),
  postcode: z.string().optional(),
});
export type CreateOrderAddress = z.infer<typeof CreateOrderAddressSchema>;

/** A client-supplied cart line (L1350-1362) — used only when `cart_items` is a non-empty array; otherwise
 *  the server loads the user's `cart_items` DB rows itself (loadCheckoutCartLinesFromDb()). */
export const CreateOrderCartItemSchema = z.object({
  product_id: z.union([z.string(), z.number()]).optional(),
  name: z.string().optional(),
  price: z.union([z.string(), z.number()]).optional(),
  quantity: z.union([z.string(), z.number()]).optional(),
  product_source: z.enum(['business_items', 'shop_products']).optional(),
});

export const CreateOrderRequestSchema = z.object({
  action: z.literal('create_order'),
  line_user_id: z.string().optional(),
  /** Raw numeric user_id override — only meaningful when line_user_id is omitted (real client traffic
   *  always sends line_user_id; PHP still supports this path). */
  user_id: z.union([z.string(), z.number()]).optional(),
  line_account_id: z.union([z.string(), z.number()]).optional(),
  address: CreateOrderAddressSchema.optional(),
  /** Defaults to 'transfer' server-side when omitted. */
  payment_method: z.string().optional(),
  display_name: z.string().optional(),
  /** When a non-empty array, used verbatim instead of the server-side cart_items DB load. */
  cart_items: z.array(CreateOrderCartItemSchema).optional(),
  /** When present, overrides the server-computed subtotal/shipping/total (line-mini-app sends `subtotal`
   *  after a promo discount so the server can recompute shipping from the discounted amount). */
  subtotal: z.union([z.string(), z.number()]).optional(),
  shipping: z.union([z.string(), z.number()]).optional(),
  total: z.union([z.string(), z.number()]).optional(),
});
export type CreateOrderRequest = z.infer<typeof CreateOrderRequestSchema>;

const CreateOrderOk = flatSuccessEnvelope({
  success: z.literal(true),
  order_id: z.number(),
  order_number: z.string(),
  total: z.number(),
  payment_method: z.string(),
  /** Always `null` this round — AR ledger creation deferred, see this file's module doc. */
  ar_id: z.null(),
});
/** Failure `message` is one of: 'User not found (line_user_id: ...)' | 'Cart is empty'. */
const CreateOrderFail = flatSuccessEnvelope({ success: z.literal(false) });
export const CreateOrderResponseSchema = z.union([CreateOrderOk, CreateOrderFail]);
export type CreateOrderResponse = z.infer<typeof CreateOrderResponseSchema>;

// ---------------------------------------------------------------------------
// POST action=upload_slip (handleUploadSlip, L1733-1863) — RESPONSE ONLY, see module doc.
// ---------------------------------------------------------------------------

const UploadSlipOk = flatSuccessEnvelope({
  success: z.literal(true),
  image_url: z.string(),
});
/** Failure `message` is one of: 'Order ID required' | 'No file uploaded' | 'Invalid file type' |
 *  'File too large (max 5MB)' | 'Order not found' | 'Failed to save file'. */
const UploadSlipFail = flatSuccessEnvelope({ success: z.literal(false) });
export const UploadSlipResponseSchema = z.union([UploadSlipOk, UploadSlipFail]);
export type UploadSlipResponse = z.infer<typeof UploadSlipResponseSchema>;
