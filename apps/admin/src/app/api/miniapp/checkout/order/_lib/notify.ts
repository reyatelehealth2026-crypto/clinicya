import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { toFloatOrZero } from './phpCompat';

/**
 * notify.ts — port of api/checkout.php's sendReceiptMessage()/buildFlexReceipt() (L1869-2024, LINE Flex
 * push sent after upload_slip) and notifyTelegramNewOrder()/notifyTelegramPayment() (L2434-2549, Telegram
 * push sent after create_order / upload_slip respectively). Read all four functions in full before
 * editing this file.
 *
 * Every exported function here mirrors its PHP original's own internal try/catch — each is a plain
 * fetch() call that ALWAYS resolves to a boolean, never throws, matching PHP's
 * `catch (Exception $e) { error_log(...); return false; }` guard on every one of these four functions.
 * Callers (createOrder.ts / uploadSlip.ts) must NEVER let a notify failure fail the already-committed
 * order/slip response — see this batch's acceptance criteria ("independently try/catch-swallowed exactly
 * like the PHP originals").
 *
 * EARLY-RETURN GUARDS preserved verbatim (silent no-op, matching PHP): sendReceiptMessage() requires the
 * order's user to have both a line_user_id AND the owning line_accounts row's channel_access_token;
 * notifyTelegramNewOrder()/notifyTelegramPayment() require an enabled `telegram_settings` row with both
 * bot_token and chat_id, AND their respective notify_new_order/notify_payment flag not explicitly 0. The
 * sandboxed e2e fixture seeds no live credentials for either channel, so both stacks take the identical
 * no-op guard path — this proves the guard logic and DB/response shape, not live delivery (documented
 * limitation, per this batch's acceptance criteria).
 *
 * BASE_URL: config/config.php defines a literal `BASE_URL` constant (`'https://clinicya.re-ya.com/'`)
 * used only inside these two Telegram messages' "ดูรายละเอียด"/"ตรวจสอบสลิป" deep links — NOT the
 * tenant's own request host (contrast uploadSlip.ts's `image_url`, which deliberately uses the incoming
 * request's own scheme+host per PHP's L1797-1806 bug-fix comment). Mirrored here via an env var override
 * with that literal as the fallback default — same established convention as
 * checkout/cart/_lib/cartProductSource.ts's `MANAGER_PRODUCT_PHOTO_BASE_URL`.
 */

function notifyBaseUrl(): string {
  const env = process.env.CHECKOUT_NOTIFY_BASE_URL;
  return env && env.trim() !== '' ? env.replace(/\/+$/, '') : 'https://clinicya.re-ya.com';
}

/** `number_format($v, 0)` — thousands-comma, no decimals. */
function phpNumberFormat0(value: unknown): string {
  return Math.round(toFloatOrZero(value)).toLocaleString('en-US', { maximumFractionDigits: 0 });
}

function parseJsonObjectOrEmpty(raw: string | null | undefined): Record<string, unknown> {
  if (!raw) return {};
  try {
    const parsed: unknown = JSON.parse(raw);
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? (parsed as Record<string, unknown>) : {};
  } catch {
    return {};
  }
}

// ---------------------------------------------------------------------------
// buildFlexReceipt() (L1933-2024)
// ---------------------------------------------------------------------------

/** The `transactions` columns buildFlexReceipt()/sendReceiptMessage() read (subset of `SELECT *`). */
export interface ReceiptOrderRow {
  id: number;
  order_number: string;
  user_id: number;
  total_amount: unknown;
  shipping_fee: unknown;
  grand_total: unknown;
  delivery_info: string | null;
}

export interface ReceiptItemRow {
  product_name: string;
  quantity: number;
  subtotal: unknown;
}

interface DeliveryInfoLike {
  name?: unknown;
  phone?: unknown;
  address?: unknown;
}

// FlexBubble typed loosely (LINE Flex Message JSON) — matches PHP's array shape 1:1; no LINE SDK types
// exist in this repo yet (packages/line doesn't exist until Phase 6 per the migration plan).
// eslint-disable-next-line @typescript-eslint/no-explicit-any
export type FlexBubble = Record<string, any>;

/** Port of buildFlexReceipt() (L1933-2024) — pure, no I/O. */
export function buildFlexReceipt(
  order: ReceiptOrderRow,
  items: ReceiptItemRow[],
  deliveryInfo: DeliveryInfoLike,
  slipUrl: string | null
): FlexBubble {
  const itemList = items.map((item) => ({
    type: 'box',
    layout: 'horizontal',
    contents: [
      { type: 'text', text: item.product_name, size: 'sm', color: '#555555', flex: 4, wrap: true },
      { type: 'text', text: `x${item.quantity}`, size: 'sm', color: '#111111', align: 'end', flex: 1 },
      { type: 'text', text: `฿${phpNumberFormat0(item.subtotal)}`, size: 'sm', color: '#111111', align: 'end', flex: 2 },
    ],
  }));

  const diName = typeof deliveryInfo.name === 'string' ? deliveryInfo.name : '';
  const diPhone = typeof deliveryInfo.phone === 'string' ? deliveryInfo.phone : '';
  const diAddress = typeof deliveryInfo.address === 'string' ? deliveryInfo.address : '';
  const addrParts = [diName, diPhone, diAddress].filter((v) => v !== '');
  const addr = addrParts.length > 0 ? addrParts.join('\n') : 'ไม่ระบุที่อยู่';

  const shippingFee = toFloatOrZero(order.shipping_fee);

  const bubble: FlexBubble = {
    type: 'bubble',
    body: {
      type: 'box',
      layout: 'vertical',
      contents: [
        { type: 'text', text: '✅ แจ้งชำระเงินเรียบร้อย', weight: 'bold', size: 'lg', color: '#1DB446' },
        { type: 'text', text: `Order #${order.order_number}`, size: 'xs', color: '#aaaaaa', margin: 'xs' },
        { type: 'separator', margin: 'lg' },
        { type: 'text', text: '📦 ที่อยู่จัดส่ง', weight: 'bold', size: 'sm', margin: 'lg' },
        { type: 'text', text: addr, size: 'xs', color: '#666666', wrap: true, margin: 'sm' },
        { type: 'separator', margin: 'lg' },
        { type: 'text', text: '🛒 รายการสินค้า', weight: 'bold', size: 'sm', margin: 'lg' },
        { type: 'box', layout: 'vertical', margin: 'md', spacing: 'sm', contents: itemList },
        { type: 'separator', margin: 'lg' },
        {
          type: 'box',
          layout: 'horizontal',
          margin: 'md',
          contents: [
            { type: 'text', text: 'ยอดสินค้า', size: 'sm', color: '#555555' },
            { type: 'text', text: `฿${phpNumberFormat0(order.total_amount)}`, size: 'sm', color: '#111111', align: 'end' },
          ],
        },
        {
          type: 'box',
          layout: 'horizontal',
          margin: 'sm',
          contents: [
            { type: 'text', text: 'ค่าจัดส่ง', size: 'sm', color: '#555555' },
            {
              type: 'text',
              text: shippingFee > 0 ? `฿${phpNumberFormat0(shippingFee)}` : 'ฟรี!',
              size: 'sm',
              color: shippingFee > 0 ? '#111111' : '#1DB446',
              align: 'end',
            },
          ],
        },
        { type: 'separator', margin: 'md' },
        {
          type: 'box',
          layout: 'horizontal',
          margin: 'md',
          contents: [
            { type: 'text', text: 'ยอดสุทธิ', weight: 'bold', size: 'md' },
            { type: 'text', text: `฿${phpNumberFormat0(order.grand_total)}`, weight: 'bold', size: 'xl', align: 'end', color: '#1DB446' },
          ],
        },
      ],
    },
    footer: {
      type: 'box',
      layout: 'vertical',
      contents: [
        { type: 'text', text: '🙏 ขอบคุณที่อุดหนุนครับ', align: 'center', color: '#aaaaaa', size: 'xs' },
        { type: 'text', text: '🚚 รอจัดส่ง 1-3 วันทำการ', align: 'center', color: '#888888', size: 'xs', margin: 'sm' },
      ],
    },
  };

  if (slipUrl) {
    bubble.hero = {
      type: 'image',
      url: slipUrl,
      size: 'full',
      aspectRatio: '20:13',
      aspectMode: 'cover',
      action: { type: 'uri', uri: slipUrl },
    };
  }

  return bubble;
}

// ---------------------------------------------------------------------------
// sendReceiptMessage() (L1869-1928)
// ---------------------------------------------------------------------------

/**
 * Port of sendReceiptMessage() (L1869-1928) — early-return guard: order's user missing/no line_user_id/no
 * channel_access_token -> silent no-op (`false`), matching PHP. Always resolves, never throws.
 */
export async function sendReceiptMessage(db: Kysely<TenantDB>, order: ReceiptOrderRow, slipUrl: string | null): Promise<boolean> {
  try {
    const userResult = await sql<{ line_user_id: string | null; display_name: string | null; channel_access_token: string | null }>`
      SELECT u.line_user_id, u.display_name, la.channel_access_token
      FROM users u
      JOIN line_accounts la ON u.line_account_id = la.id
      WHERE u.id = ${order.user_id}
    `.execute(db);
    const userData = userResult.rows[0];
    if (!userData || !userData.line_user_id || !userData.channel_access_token) {
      return false;
    }

    const itemsResult = await sql<ReceiptItemRow>`SELECT * FROM transaction_items WHERE transaction_id = ${order.id}`.execute(db);
    const deliveryInfo = parseJsonObjectOrEmpty(order.delivery_info);
    const flex = buildFlexReceipt(order, itemsResult.rows, deliveryInfo, slipUrl);

    const messages = [
      {
        type: 'flex',
        altText: `✅ แจ้งชำระเงินเรียบร้อย #${order.order_number}`,
        contents: flex,
      },
    ];

    const response = await fetch('https://api.line.me/v2/bot/message/push', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${userData.channel_access_token}`,
      },
      body: JSON.stringify({ to: userData.line_user_id, messages }),
    });

    return response.status === 200;
  } catch {
    // swallow — matches PHP's catch (Exception $e) { error_log('sendReceiptMessage error: ' . ...); return false; }
    return false;
  }
}

// ---------------------------------------------------------------------------
// Telegram settings (shared by both notifiers, L2439/L2510: `SELECT * FROM telegram_settings WHERE id=1`)
// ---------------------------------------------------------------------------

interface TelegramSettingsRow {
  bot_token: string | null;
  chat_id: string | null;
  is_enabled: number | null;
  notify_new_order: number | null;
  notify_payment: number | null;
}

async function loadTelegramSettings(db: Kysely<TenantDB>): Promise<TelegramSettingsRow | undefined> {
  const result = await sql<TelegramSettingsRow>`SELECT * FROM telegram_settings WHERE id = 1`.execute(db);
  return result.rows[0];
}

// ---------------------------------------------------------------------------
// notifyTelegramNewOrder() (L2434-2500)
// ---------------------------------------------------------------------------

export interface NotifyNewOrderInput {
  orderId: number;
  orderNumber: string;
  total: number;
  user: { display_name?: string | null };
  deliveryInfo: DeliveryInfoLike;
}

/**
 * Port of notifyTelegramNewOrder() (L2434-2500) — early-return guard: telegram_settings missing/disabled
 * or missing bot_token/chat_id, or notify_new_order explicitly 0 -> silent no-op (`false`), matching PHP.
 * Sends `application/x-www-form-urlencoded` (PHP: `http_build_query($data)` on CURLOPT_POSTFIELDS).
 */
export async function notifyTelegramNewOrder(db: Kysely<TenantDB>, input: NotifyNewOrderInput): Promise<boolean> {
  try {
    const settings = await loadTelegramSettings(db);
    if (!settings || !settings.is_enabled || !settings.bot_token || !settings.chat_id) {
      return false;
    }
    if (!(settings.notify_new_order ?? 1)) {
      return false;
    }

    const itemsResult = await sql<{ product_name: string; quantity: number; subtotal: unknown }>`
      SELECT product_name, quantity, subtotal FROM transaction_items WHERE transaction_id = ${input.orderId}
    `.execute(db);

    let itemList = '';
    for (const item of itemsResult.rows) {
      itemList += `  • ${item.product_name} x${item.quantity} = ฿${phpNumberFormat0(item.subtotal)}\n`;
    }

    const customerName = input.user.display_name ?? 'ลูกค้า';
    const phone = typeof input.deliveryInfo.phone === 'string' && input.deliveryInfo.phone !== '' ? input.deliveryInfo.phone : '-';
    const address = typeof input.deliveryInfo.address === 'string' && input.deliveryInfo.address !== '' ? input.deliveryInfo.address : '-';

    let message = '🛒 <b>ออเดอร์ใหม่!</b>\n\n';
    message += `📋 Order: <code>${input.orderNumber}</code>\n`;
    message += `👤 ลูกค้า: ${customerName}\n`;
    message += `📱 โทร: ${phone}\n`;
    message += `📍 ที่อยู่: ${address}\n\n`;
    message += `📦 <b>รายการสินค้า:</b>\n${itemList}\n`;
    message += `💰 <b>ยอดรวม: ฿${phpNumberFormat0(input.total)}</b>\n\n`;
    message += `🔗 <a href="${notifyBaseUrl()}/shop/orders.php">ดูรายละเอียด</a>`;

    const body = new URLSearchParams({
      chat_id: settings.chat_id,
      text: message,
      parse_mode: 'HTML',
      // PHP: http_build_query(['disable_web_page_preview' => true]) -> `disable_web_page_preview=1`
      // (http_build_query casts bool true -> '1'), NOT the string 'true'.
      disable_web_page_preview: '1',
    });

    await fetch(`https://api.telegram.org/bot${settings.bot_token}/sendMessage`, {
      method: 'POST',
      body,
    });

    return true;
  } catch {
    return false;
  }
}

// ---------------------------------------------------------------------------
// notifyTelegramPayment() (L2505-2549)
// ---------------------------------------------------------------------------

/**
 * Port of notifyTelegramPayment() (L2505-2549) — same early-return guard shape as
 * notifyTelegramNewOrder(), gated on notify_payment instead of notify_new_order. Sends
 * `multipart/form-data` (PHP passes an associative array straight to CURLOPT_POSTFIELDS, which makes curl
 * build a multipart body — replicated via FormData, NOT URLSearchParams/urlencoded).
 */
// eslint-disable-next-line @typescript-eslint/no-unused-vars -- orderId kept for call-site symmetry with
// notifyTelegramNewOrder() / faithfulness to the PHP signature (PHP's own notifyTelegramPayment($orderId,
// ...) never uses $orderId in its Telegram payload either).
export async function notifyTelegramPayment(
  db: Kysely<TenantDB>,
  orderId: number,
  orderNumber: string,
  slipUrl: string,
  user: { display_name?: string | null }
): Promise<boolean> {
  try {
    const settings = await loadTelegramSettings(db);
    if (!settings || !settings.is_enabled || !settings.bot_token || !settings.chat_id) {
      return false;
    }
    if (!(settings.notify_payment ?? 1)) {
      return false;
    }

    const customerName = user.display_name ?? 'ลูกค้า';
    const caption =
      `💳 <b>แจ้งชำระเงิน!</b>\n\n📋 Order: <code>${orderNumber}</code>\n👤 ลูกค้า: ${customerName}\n\n` +
      `🔗 <a href="${notifyBaseUrl()}/shop/orders.php?pending_slip=1">ตรวจสอบสลิป</a>`;

    const form = new FormData();
    form.append('chat_id', settings.chat_id);
    form.append('photo', slipUrl);
    form.append('caption', caption);
    form.append('parse_mode', 'HTML');

    await fetch(`https://api.telegram.org/bot${settings.bot_token}/sendPhoto`, {
      method: 'POST',
      body: form,
    });

    return true;
  } catch {
    return false;
  }
}
