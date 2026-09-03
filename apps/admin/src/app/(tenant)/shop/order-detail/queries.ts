import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * queries.ts — data assembly for `/shop/order-detail?id=N`, ported from
 * shop/order-detail.php lines 538-599 (order + items + payment slips +
 * shop-accounts GET-time reads). Every real-table query is a raw `sql`
 * fragment with camelCase column aliases (not Kysely's typed builder) —
 * same convention as `(tenant)/user-detail/queries.ts`.
 */

// ---------------------------------------------------------------------------
// getOrderDetail — PHP lines 538-548.
// ---------------------------------------------------------------------------

export interface OrderDetailRow {
  id: number;
  orderNumber: string;
  createdAt: Date;
  status: string | null;
  paymentStatus: string | null;
  paymentMethod: string | null;
  totalAmount: string;
  shippingFee: string | null;
  discountAmount: string | null;
  grandTotal: string;
  deliveryInfo: string | null;
  shippingName: string | null;
  shippingPhone: string | null;
  shippingAddress: string | null;
  shippingTracking: string | null;
  note: string | null;
  transactionType: string | null;
  userId: number;
  displayName: string | null;
  pictureUrl: string | null;
  lineUserId: string;
}

/**
 * Port of:
 *   SELECT o.*, u.display_name, u.picture_url, u.line_user_id
 *   FROM transactions o JOIN users u ON o.user_id = u.id
 *   WHERE o.id = ? AND (o.line_account_id = ? OR o.line_account_id IS NULL)
 * (PHP lines 538-543). `o.*` is narrowed to the columns the page actually
 * renders/uses. Returns null when no row matches — mirrors PHP's `if
 * (!$order) { header('Location: orders.php'); exit; }` (page.tsx redirects
 * on a null return, same pattern as user-detail's own queries.ts).
 */
export async function getOrderDetail(db: Kysely<TenantDB>, orderId: number, currentBotId: number | null): Promise<OrderDetailRow | null> {
  const result = await sql<OrderDetailRow>`
    SELECT
      o.id, o.order_number AS orderNumber, o.created_at AS createdAt, o.status,
      o.payment_status AS paymentStatus, o.payment_method AS paymentMethod,
      o.total_amount AS totalAmount, o.shipping_fee AS shippingFee, o.discount_amount AS discountAmount,
      o.grand_total AS grandTotal, o.delivery_info AS deliveryInfo,
      o.shipping_name AS shippingName, o.shipping_phone AS shippingPhone, o.shipping_address AS shippingAddress,
      o.shipping_tracking AS shippingTracking, o.note, o.transaction_type AS transactionType, o.user_id AS userId,
      u.display_name AS displayName, u.picture_url AS pictureUrl, u.line_user_id AS lineUserId
    FROM transactions o
    JOIN users u ON o.user_id = u.id
    WHERE o.id = ${orderId} AND (o.line_account_id = ${currentBotId} OR o.line_account_id IS NULL)
  `.execute(db);
  return result.rows[0] ?? null;
}

// ---------------------------------------------------------------------------
// getOrderItems — PHP lines 550-553.
// ---------------------------------------------------------------------------

export interface OrderItemRow {
  id: number;
  productName: string;
  productPrice: string;
  quantity: number;
  subtotal: string;
}

/** Port of `SELECT * FROM transaction_items WHERE transaction_id = ?` (PHP lines 551-553), narrowed to the columns rendered. */
export async function getOrderItems(db: Kysely<TenantDB>, orderId: number): Promise<OrderItemRow[]> {
  const result = await sql<OrderItemRow>`
    SELECT id, product_name AS productName, product_price AS productPrice, quantity, subtotal
    FROM transaction_items WHERE transaction_id = ${orderId}
  `.execute(db);
  return result.rows;
}

// ---------------------------------------------------------------------------
// getPaymentSlips — PHP lines 555-566 (fetch + scheme repair) and 1066-1071
// (same-origin `/uploads/slips/<basename>` rewrite, computed per-slip at
// render time in PHP but computed here at query time instead — same output).
// ---------------------------------------------------------------------------

export interface PaymentSlipRow {
  id: number;
  status: 'approved' | 'pending' | 'rejected' | null;
  adminNote: string | null;
  createdAt: Date;
  amount: string | null;
  imageUrl: string;
  /**
   * `verify_ref`/`verify_amount`/`verify_data`/`verified_at`/`qr_payload` —
   * see packages/db/src/generated/tenant-db.d.ts's PaymentSlips interface
   * doc comment (this batch's schema-governance fix-forward) for why these
   * are real production columns despite being absent from the committed
   * tenant template until packages/db/migrations/tenant/migration_2026-08-14_
   * payment_slips_verification.sql.
   */
  verifyRef: string | null;
  verifyAmount: string | null;
  verifyData: string | null;
  verifiedAt: Date | null;
  qrPayload: string | null;
}

export interface PaymentSlipView extends PaymentSlipRow {
  /** Same-origin `/uploads/slips/<basename>` URL, safe to render regardless of how the stored `imageUrl` was built. */
  imageSrc: string;
}

/**
 * Mirrors PHP's `basename()`: the last path segment, with trailing slashes
 * stripped first.
 */
function phpBasename(path: string): string {
  const trimmed = path.replace(/\/+$/, '');
  const idx = trimmed.lastIndexOf('/');
  return idx === -1 ? trimmed : trimmed.slice(idx + 1);
}

/**
 * Mirrors PHP's `parse_url($url, PHP_URL_PATH)`: pure string splitting, no
 * normalization/encoding of any kind. Deliberately NOT built on the WHATWG
 * `URL` API — `URL` would percent-encode a raw space (or re-encode an
 * already-`%`-escaped segment) while parsing, which `parse_url()` never
 * does; that divergence would silently double-encode a slip filename by the
 * time `phpRawUrlEncode()` below runs. Returns null when no path component
 * can be extracted (mirrors PHP_URL_PATH being unset/false), matching the
 * `?: $slip['image_url']` fallback in the caller.
 */
function phpUrlPath(url: string): string | null {
  const m = /^([a-zA-Z][a-zA-Z0-9+.-]*:)?(\/\/[^/?#]*)?([^?#]*)/.exec(url);
  const path = m?.[3] ?? '';
  return path !== '' ? path : null;
}

/** Mirrors PHP's `rawurlencode()` (RFC 3986) — unlike `encodeURIComponent`, it also encodes `! ' ( ) *`. */
function phpRawUrlEncode(value: string): string {
  return encodeURIComponent(value).replace(/[!'()*]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase());
}

/**
 * Repairs a malformed slip URL scheme ("https:/host" -> "https://host") from
 * a typo'd BASE_URL so existing slips still render. Port of PHP lines
 * 561-564 (the `preg_replace('#^(https?):/([^/])#i', '$1://$2', ...)` pass).
 */
export function repairSlipUrlScheme(imageUrl: string): string {
  return imageUrl.replace(/^(https?):\/([^/])/i, '$1://$2');
}

/**
 * Renders via a same-origin relative path so the image always loads on the
 * current host over https, regardless of how the stored `image_url` was
 * built (wrong host / http mixed-content / malformed scheme). Files live in
 * the shared `/uploads/slips/`. Port of PHP lines 1066-1071.
 */
export function buildSlipImageSrc(repairedImageUrl: string): string {
  const path = phpUrlPath(repairedImageUrl) || repairedImageUrl;
  const slipFile = phpBasename(path);
  return slipFile !== '' ? '/uploads/slips/' + phpRawUrlEncode(slipFile) : repairedImageUrl;
}

/** Port of `SELECT * FROM payment_slips WHERE transaction_id = ? ORDER BY created_at DESC` (PHP lines 556-558), plus both URL transforms above applied per row. */
export async function getPaymentSlips(db: Kysely<TenantDB>, orderId: number): Promise<PaymentSlipView[]> {
  const result = await sql<PaymentSlipRow>`
    SELECT
      id, status, admin_note AS adminNote, created_at AS createdAt, amount, image_url AS imageUrl,
      verify_ref AS verifyRef, verify_amount AS verifyAmount, verify_data AS verifyData,
      verified_at AS verifiedAt, qr_payload AS qrPayload
    FROM payment_slips WHERE transaction_id = ${orderId} ORDER BY created_at DESC
  `.execute(db);

  return result.rows.map((row) => {
    const imageUrl = row.imageUrl ? repairSlipUrlScheme(row.imageUrl) : row.imageUrl;
    return { ...row, imageUrl, imageSrc: buildSlipImageSrc(imageUrl) };
  });
}

// ---------------------------------------------------------------------------
// getShopAccounts — PHP lines 220-240 (verify_slip action) / 570-588 (GET
// render) — the SAME block, duplicated verbatim twice in the PHP source.
// Ported here once and reused by both queries.ts's page-render path and
// actions.ts's verifySlipAction.
// ---------------------------------------------------------------------------

interface ShopSettingsAccountsRow {
  promptpayNumber: string | null;
  bankAccounts: string | null;
}

interface BankAccountEntry {
  account_number?: string;
}

/**
 * Port of the `$shopAccounts`/`$shopAccts` assembly block: shop's PromptPay
 * number plus every configured bank account number, as plain strings
 * (formatting ignored downstream by `SlipVerifier.accountMatches()`).
 */
export async function getShopAccounts(db: Kysely<TenantDB>, currentBotId: number | null): Promise<string[]> {
  const result = await sql<ShopSettingsAccountsRow>`
    SELECT promptpay_number AS promptpayNumber, bank_accounts AS bankAccounts
    FROM shop_settings WHERE line_account_id = ${currentBotId} LIMIT 1
  `.execute(db);
  const cfg = result.rows[0];
  if (!cfg) {
    return [];
  }

  const accounts: string[] = [];
  if (cfg.promptpayNumber) {
    accounts.push(String(cfg.promptpayNumber));
  }
  if (cfg.bankAccounts) {
    try {
      const parsed: unknown = JSON.parse(cfg.bankAccounts);
      if (Array.isArray(parsed)) {
        for (const b of parsed as BankAccountEntry[]) {
          if (b && b.account_number) {
            accounts.push(String(b.account_number));
          }
        }
      }
    } catch {
      // PHP: json_decode() failure -> $dec is null -> is_array($dec) is false -> loop skipped.
    }
  }
  return accounts;
}

// ---------------------------------------------------------------------------
// Composed page data — PHP's top-to-bottom GET-time query sequence.
// ---------------------------------------------------------------------------

export interface OrderDetailPageData {
  order: OrderDetailRow;
  items: OrderItemRow[];
  slips: PaymentSlipView[];
  shopAccounts: string[];
}

/**
 * Assembles the whole `/shop/order-detail?id=N` page's data in one call.
 * Returns null when the order id doesn't resolve to a row scoped to
 * `currentBotId` (mirrors PHP's `if (!$order) { header('Location:
 * orders.php'); exit; }` at lines 545-548) — page.tsx redirects on a null
 * return.
 */
export async function getOrderDetailPageData(
  db: Kysely<TenantDB>,
  orderId: number,
  currentBotId: number | null
): Promise<OrderDetailPageData | null> {
  const order = await getOrderDetail(db, orderId, currentBotId);
  if (!order) {
    return null;
  }

  const [items, slips, shopAccounts] = await Promise.all([
    getOrderItems(db, orderId),
    getPaymentSlips(db, orderId),
    getShopAccounts(db, currentBotId),
  ]);

  return { order, items, slips, shopAccounts };
}
