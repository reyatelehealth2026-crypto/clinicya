import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import {
  phpNumberFormat,
  sendMessage as lineApiSendMessage,
  type FlexBubble,
  type FlexComponent,
  type LineFlexMessage,
  type LineMessage,
} from '@reya/line';
import { bangkokDdMmYyyyHm } from './bangkokTime';

/**
 * orderStatusFlex.ts — port of shop/order-detail.php's `buildOrderStatusFlex()`
 * (PHP lines 42-159) + `sendOrderStatusFlex()` (PHP lines 165-187), plus the
 * separate rejection-Flex bubble the `reject_payment` action builds inline
 * (PHP lines 461-496) — kept in this same file per the porting brief.
 *
 * Built on @reya/line's FlexBubble/LineFlexMessage types and its
 * sendMessage()/pushMessage() dispatcher (packages/line/src/api.ts), the same
 * way apps/admin/src/app/api/inbox/actions/dispense/_lib/flexSend.ts and
 * .../send-message/_lib/sendMessage.ts already call into that package.
 */

export type OrderStatusKey = 'pending' | 'confirmed' | 'paid' | 'shipping' | 'delivered' | 'cancelled';

interface StatusConfigEntry {
  icon: string;
  label: string;
  color: string;
  msg: string;
}

/** Port of PHP's `$statusConfig` (lines 43-50). */
const STATUS_CONFIG: Record<OrderStatusKey, StatusConfigEntry> = {
  pending: { icon: '⏳', label: 'รอยืนยัน', color: '#F59E0B', msg: 'รอการยืนยันจากร้านค้า' },
  confirmed: { icon: '✅', label: 'ยืนยันแล้ว', color: '#3B82F6', msg: 'ออเดอร์ได้รับการยืนยันแล้ว' },
  paid: { icon: '💰', label: 'ชำระเงินแล้ว', color: '#10B981', msg: 'ยืนยันการชำระเงินเรียบร้อย' },
  shipping: { icon: '🚚', label: 'กำลังจัดส่ง', color: '#8B5CF6', msg: 'สินค้าถูกจัดส่งแล้ว' },
  delivered: { icon: '📦', label: 'จัดส่งแล้ว', color: '#059669', msg: 'สินค้าถึงปลายทางแล้ว' },
  cancelled: { icon: '❌', label: 'ยกเลิก', color: '#EF4444', msg: 'ออเดอร์ถูกยกเลิก' },
};

/** Minimal order shape buildOrderStatusFlex() reads — a subset of `transactions`. */
export interface OrderFlexInput {
  order_number: string;
  delivery_info: string | null;
  total_amount: number | string;
  shipping_fee: number | string | null;
  grand_total: number | string;
}

export interface OrderFlexItem {
  product_name: string;
  quantity: number;
  subtotal: number | string;
}

/** Mirrors PHP's `json_decode($x ?? '{}', true)` returning `[]`/`{}` on any parse failure. */
function parseDeliveryInfo(raw: string | null): Record<string, unknown> {
  if (!raw) return {};
  try {
    const parsed: unknown = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : {};
  } catch {
    return {};
  }
}

/** Port of `buildOrderStatusFlex($order, $items, $newStatus, $tracking)` (PHP lines 42-159). */
export function buildOrderStatusFlex(
  order: OrderFlexInput,
  items: OrderFlexItem[],
  newStatus: string,
  tracking: string | null = null
): LineFlexMessage {
  const status = STATUS_CONFIG[newStatus as OrderStatusKey] ?? STATUS_CONFIG.pending;

  // Build item list.
  const itemList: FlexComponent[] = items.map((item) => ({
    type: 'box',
    layout: 'horizontal',
    contents: [
      { type: 'text', text: item.product_name, size: 'sm', color: '#555555', flex: 4, wrap: true },
      { type: 'text', text: 'x' + item.quantity, size: 'sm', color: '#111111', align: 'end', flex: 1 },
      { type: 'text', text: '฿' + phpNumberFormat(Number(item.subtotal), 0), size: 'sm', color: '#111111', align: 'end', flex: 2 },
    ],
  }));

  // Get delivery info.
  const deliveryInfo = parseDeliveryInfo(order.delivery_info);
  const addrParts: string[] = [];
  if (deliveryInfo.name) addrParts.push(String(deliveryInfo.name));
  if (deliveryInfo.phone) addrParts.push(String(deliveryInfo.phone));
  if (deliveryInfo.address) addrParts.push(String(deliveryInfo.address));
  const addr = addrParts.length > 0 ? addrParts.join('\n') : 'ไม่ระบุที่อยู่';

  const dateStr = bangkokDdMmYyyyHm();

  // Body contents.
  const bodyContents: FlexComponent[] = [
    {
      type: 'box',
      layout: 'horizontal',
      contents: [{ type: 'text', text: status.icon + ' ' + status.label, weight: 'bold', size: 'xl', color: status.color }],
    },
    { type: 'text', text: status.msg, size: 'sm', color: '#888888', margin: 'sm' },
    { type: 'separator', margin: 'lg' },
    { type: 'text', text: '📋 Order #' + order.order_number, weight: 'bold', size: 'sm', margin: 'lg' },
    { type: 'text', text: '📅 ' + dateStr, size: 'xs', color: '#aaaaaa', margin: 'sm' },
  ];

  // Add tracking number if shipping.
  if (newStatus === 'shipping' && tracking) {
    bodyContents.push({
      type: 'box',
      layout: 'vertical',
      margin: 'lg',
      paddingAll: 'md',
      backgroundColor: '#F3E8FF',
      cornerRadius: 'md',
      contents: [
        { type: 'text', text: '🚚 เลขพัสดุ', weight: 'bold', size: 'sm', color: '#7C3AED' },
        { type: 'text', text: tracking, size: 'lg', weight: 'bold', color: '#5B21B6', margin: 'sm' },
      ],
    });
  }

  // Add items section.
  bodyContents.push({ type: 'separator', margin: 'lg' });
  bodyContents.push({ type: 'text', text: '🛒 รายการสินค้า', weight: 'bold', size: 'sm', margin: 'lg' });
  bodyContents.push({ type: 'box', layout: 'vertical', margin: 'md', spacing: 'sm', contents: itemList });

  // Add totals.
  const shippingFee = Number(order.shipping_fee ?? 0);
  bodyContents.push({ type: 'separator', margin: 'lg' });
  bodyContents.push({
    type: 'box',
    layout: 'horizontal',
    margin: 'md',
    contents: [
      { type: 'text', text: 'ยอดสินค้า', size: 'sm', color: '#555555' },
      { type: 'text', text: '฿' + phpNumberFormat(Number(order.total_amount), 0), size: 'sm', color: '#111111', align: 'end' },
    ],
  });
  bodyContents.push({
    type: 'box',
    layout: 'horizontal',
    margin: 'sm',
    contents: [
      { type: 'text', text: 'ค่าจัดส่ง', size: 'sm', color: '#555555' },
      {
        type: 'text',
        text: shippingFee > 0 ? '฿' + phpNumberFormat(shippingFee, 0) : 'ฟรี!',
        size: 'sm',
        color: shippingFee > 0 ? '#111111' : '#10B981',
        align: 'end',
      },
    ],
  });
  bodyContents.push({ type: 'separator', margin: 'md' });
  bodyContents.push({
    type: 'box',
    layout: 'horizontal',
    margin: 'md',
    contents: [
      { type: 'text', text: 'ยอดสุทธิ', weight: 'bold', size: 'md' },
      { type: 'text', text: '฿' + phpNumberFormat(Number(order.grand_total), 0), weight: 'bold', size: 'xl', align: 'end', color: status.color },
    ],
  });

  // Add address section.
  bodyContents.push({ type: 'separator', margin: 'lg' });
  bodyContents.push({ type: 'text', text: '📦 ที่อยู่จัดส่ง', weight: 'bold', size: 'sm', margin: 'lg' });
  bodyContents.push({ type: 'text', text: addr, size: 'xs', color: '#666666', wrap: true, margin: 'sm' });

  const bubble: FlexBubble = {
    type: 'bubble',
    body: { type: 'box', layout: 'vertical', contents: bodyContents },
    footer: {
      type: 'box',
      layout: 'vertical',
      contents: [{ type: 'text', text: '🙏 ขอบคุณที่ใช้บริการ', align: 'center', color: '#aaaaaa', size: 'xs' }],
    },
  };

  return {
    type: 'flex',
    altText: status.icon + ' อัพเดทสถานะ #' + order.order_number + ' - ' + status.label,
    contents: bubble,
  };
}

// ---------------------------------------------------------------------------
// sendOrderStatusFlex() — port of PHP lines 165-187.
// ---------------------------------------------------------------------------

interface OrderStatusSendRow {
  order_number: string;
  delivery_info: string | null;
  total_amount: string | number;
  shipping_fee: string | number | null;
  grand_total: string | number;
  line_user_id: string | null;
  reply_token: string | null;
  reply_token_expires_str: string | null;
}

interface OrderItemRow {
  product_name: string;
  quantity: number;
  subtotal: string | number;
}

interface LineAccountTokenRow {
  channel_access_token: string;
}

/**
 * Resolves the `line_accounts.channel_access_token` for `currentBotId`
 * (`$_SESSION['current_bot_id']` — the admin's currently-selected LINE OA,
 * NOT the order's own `line_account_id`). PHP's
 * `LineAccountManager::getLineAPI()` falls back to a config-constant-backed
 * `new LineAPI()` when no row matches; Next has no such legacy config
 * fallback (same decision as send-message/_lib/sendMessage.ts and
 * dispense/_lib/flexSend.ts) — a missing row means the send is skipped.
 */
async function resolveChannelAccessToken(db: Kysely<TenantDB>, currentBotId: number): Promise<string | null> {
  const rows = await sql<LineAccountTokenRow>`SELECT channel_access_token FROM line_accounts WHERE id = ${currentBotId}`.execute(db);
  return rows.rows[0]?.channel_access_token ?? null;
}

/**
 * Send Flex Order Status to customer. Port of `sendOrderStatusFlex($line,
 * $db, $orderId, $newStatus, $tracking)` (PHP lines 165-187), with `$line`
 * replaced by resolving `currentBotId`'s channel token internally (see
 * `resolveChannelAccessToken` above) so call sites don't need to construct a
 * LineAPI-equivalent object themselves.
 *
 * PHP's `method_exists($line, 'sendMessage') ? sendMessage(...) :
 * pushMessage(...)` branch always takes the `sendMessage()` arm in practice
 * (`LineAPI::sendMessage()` always exists) — @reya/line's `sendMessage()`
 * always exists too, so that branch collapses to an unconditional
 * `sendMessage()` call here, matching the same simplification already made
 * in dispense/_lib/flexSend.ts and send-message/_lib/sendMessage.ts.
 *
 * Returns `false` when there is nothing to send to (no matching order, no
 * `line_user_id`, or no resolvable channel token) — mirrors PHP's `if
 * (!$order || !$order['line_user_id']) return false;` plus this port's own
 * "skip when no line_accounts row" extension above.
 */
export async function sendOrderStatusFlex(
  db: Kysely<TenantDB>,
  currentBotId: number,
  orderId: number,
  newStatus: string,
  tracking: string | null = null
): Promise<boolean> {
  const orderRows = await sql<OrderStatusSendRow>`
    SELECT o.order_number, o.delivery_info, o.total_amount, o.shipping_fee, o.grand_total,
      u.line_user_id, u.reply_token, DATE_FORMAT(u.reply_token_expires, '%Y-%m-%d %H:%i:%s') AS reply_token_expires_str
    FROM transactions o JOIN users u ON o.user_id = u.id WHERE o.id = ${orderId}
  `.execute(db);
  const order = orderRows.rows[0];
  if (!order || !order.line_user_id) {
    return false;
  }

  const itemRows = await sql<OrderItemRow>`SELECT product_name, quantity, subtotal FROM transaction_items WHERE transaction_id = ${orderId}`.execute(db);

  const flexMessage = buildOrderStatusFlex(order, itemRows.rows, newStatus, tracking);

  const channelAccessToken = await resolveChannelAccessToken(db, currentBotId);
  if (!channelAccessToken) {
    return false;
  }

  await lineApiSendMessage(
    {
      userId: order.line_user_id,
      messages: [flexMessage as unknown as LineMessage],
      replyToken: order.reply_token,
      tokenExpires: order.reply_token_expires_str,
    },
    { channelAccessToken }
  );
  return true;
}

// ---------------------------------------------------------------------------
// Rejection Flex — the SEPARATE bubble the `reject_payment` action builds
// inline (PHP lines 461-496). NOT `buildOrderStatusFlex()`'s output — a
// distinct, purpose-built "slip rejected" card.
// ---------------------------------------------------------------------------

/** Minimal order shape buildOrderRejectionFlex() reads. */
export interface OrderRejectionFlexInput {
  order_number: string;
  grand_total: number | string;
}

/** Port of the inline `$rejectFlex` array literal (PHP lines 461-496). */
export function buildOrderRejectionFlex(order: OrderRejectionFlexInput): LineFlexMessage {
  const bubble: FlexBubble = {
    type: 'bubble',
    body: {
      type: 'box',
      layout: 'vertical',
      contents: [
        { type: 'text', text: '❌ สลิปไม่ถูกต้อง', weight: 'bold', size: 'xl', color: '#EF4444' },
        { type: 'text', text: 'หลักฐานการชำระเงินไม่ถูกต้อง', size: 'sm', color: '#888888', margin: 'sm' },
        { type: 'separator', margin: 'lg' },
        { type: 'text', text: '📋 Order #' + order.order_number, weight: 'bold', size: 'sm', margin: 'lg' },
        { type: 'text', text: '💰 ยอดที่ต้องชำระ: ฿' + phpNumberFormat(Number(order.grand_total), 0), size: 'sm', color: '#555555', margin: 'md' },
        {
          type: 'box',
          layout: 'vertical',
          margin: 'lg',
          paddingAll: 'md',
          backgroundColor: '#FEF2F2',
          cornerRadius: 'md',
          contents: [{ type: 'text', text: '⚠️ กรุณาตรวจสอบและส่งหลักฐานใหม่', size: 'sm', color: '#DC2626', wrap: true }],
        },
      ],
    },
    footer: {
      type: 'box',
      layout: 'vertical',
      contents: [{ type: 'text', text: 'หากมีข้อสงสัย กรุณาติดต่อร้านค้า', align: 'center', color: '#aaaaaa', size: 'xs' }],
    },
  };

  return {
    type: 'flex',
    altText: '❌ หลักฐานการชำระเงินไม่ถูกต้อง #' + order.order_number,
    contents: bubble,
  };
}

interface OrderRejectSendRow {
  order_number: string;
  grand_total: string | number;
  line_user_id: string | null;
  reply_token: string | null;
  reply_token_expires_str: string | null;
}

/**
 * Send the rejection Flex to the customer. Port of the `reject_payment`
 * action's send block (PHP lines 454-503) — same reply-first/push-fallback
 * dispatch and same `currentBotId`-resolved channel token as
 * `sendOrderStatusFlex()` above. NOT wrapped in a try/catch here — the PHP
 * `reject_payment` handler has none either (see actions.ts's module doc).
 */
export async function sendOrderRejectionFlex(db: Kysely<TenantDB>, currentBotId: number, orderId: number): Promise<boolean> {
  const rows = await sql<OrderRejectSendRow>`
    SELECT o.order_number, o.grand_total,
      u.line_user_id, u.reply_token, DATE_FORMAT(u.reply_token_expires, '%Y-%m-%d %H:%i:%s') AS reply_token_expires_str
    FROM transactions o JOIN users u ON o.user_id = u.id WHERE o.id = ${orderId}
  `.execute(db);
  const order = rows.rows[0];
  if (!order || !order.line_user_id) {
    return false;
  }

  const channelAccessToken = await resolveChannelAccessToken(db, currentBotId);
  if (!channelAccessToken) {
    return false;
  }

  const rejectFlex = buildOrderRejectionFlex(order);
  await lineApiSendMessage(
    {
      userId: order.line_user_id,
      messages: [rejectFlex as unknown as LineMessage],
      replyToken: order.reply_token,
      tokenExpires: order.reply_token_expires_str,
    },
    { channelAccessToken }
  );
  return true;
}
