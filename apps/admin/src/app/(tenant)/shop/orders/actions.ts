'use server';

import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { revalidatePath } from 'next/cache';
import { requireTenantPageContext } from './_lib/session';
import { logOrderActivity } from './_lib/activityLog';
import { notifyOrderByLine } from './_lib/lineNotify';

/**
 * actions.ts — Server Action ports of shop/orders.php's two POST branches
 * (lines 255-335):
 *
 *   - updateOrderStatusAction -> action==='update_status' (lines 259-299)
 *   - approvePaymentAction    -> action==='approve_payment' (lines 300-330)
 *
 * revalidatePath('/shop/orders') replaces PHP's own
 * `echo "<script>window.location.href = 'orders.php';</script>"; exit;`
 * (line 333, used "since headers may already be sent" per PHP's own
 * comment) — Server Actions have no page navigation to trigger; the calling
 * client component re-fetches the Server Component tree at this path
 * instead. This is a substitution of REDIRECT MECHANISM only — every state
 * change PHP made before that redirect (the UPDATEs, the wms_status
 * best-effort update, the activity log row, the LINE notify) happens
 * identically here, in the identical order, before revalidatePath() runs.
 *
 * `currentBotId = session.currentBotId ?? 1` mirrors shop/orders.php's own
 * top-of-file `$currentBotId = $_SESSION['current_bot_id'] ?? 1;` (line 21)
 * — NOT the raw nullable `session.currentBotId`. This is the value used for
 * the tenant-guarded UPDATE and the `line_accounts` lookup below. Contrast
 * with _lib/activityLog.ts, which independently reads
 * `session.currentBotId` RAW (no `?? 1`) for its own `line_account_id`
 * column — see that file's own doc comment for why (ActivityLogger::log()'s
 * fallback reads `$_SESSION['current_bot_id']` directly, a separate PHP
 * expression from this file's local `$currentBotId`).
 */

export interface OrderActionResult {
  success: boolean;
}

function assertValidOrderId(orderId: number): void {
  // shop/orders.php's own guard is `if ($action === 'update_status' &&
  // $orderId)` / `elseif (... && $orderId)` — when $orderId is falsy, PHP
  // does nothing at all (no error; it just falls through to the redirect).
  // Throwing here instead is a deliberate Server-Action-hygiene choice
  // (matches users/actions.ts's assertValidIds), not a literal PHP port.
  if (!Number.isInteger(orderId) || orderId <= 0) {
    throw new Error('Missing required fields');
  }
}

/** `!empty($_POST['tracking'])` — PHP's empty() also treats the string "0" as empty. */
function phpNotEmpty(value: string | undefined): value is string {
  return !!value && value !== '0';
}

interface OrderNotifyRow {
  order_number: string;
  line_user_id: string | null;
  reply_token: string | null;
  /** DATE_FORMAT'd 'YYYY-MM-DD HH:MM:SS' — see api/inbox/actions/send-message/_lib/sendMessage.ts's own doc for why this codebase always reads reply_token_expires this way (no CamelCasePlugin + mysql2 driver timezone ambiguity), same convention reused here. */
  reply_token_expires_str: string | null;
}

/**
 * Fetches exactly the fields BOTH POST branches' notify blocks need. PHP
 * runs two SLIGHTLY different SELECTs here (update_status's own SELECT also
 * fetches `u.display_name`; approve_payment's does not) — but neither
 * branch's message text ever actually references display_name (re-checked
 * against shop/orders.php lines 287, 324: only order_number and the status
 * text are interpolated), so it is dead-select in both PHP branches alike.
 * One shared query covers both faithfully.
 */
async function fetchOrderNotifyContext(db: Kysely<TenantDB>, orderId: number): Promise<OrderNotifyRow | undefined> {
  const result = await sql<OrderNotifyRow>`
    SELECT o.order_number AS order_number, u.line_user_id AS line_user_id, u.reply_token AS reply_token,
      DATE_FORMAT(u.reply_token_expires, '%Y-%m-%d %H:%i:%s') AS reply_token_expires_str
    FROM transactions o
    JOIN users u ON o.user_id = u.id
    WHERE o.id = ${orderId}
  `.execute(db);
  return result.rows[0];
}

interface LineAccountTokenRow {
  channel_access_token: string;
}

/**
 * Mirrors `classes/LineAccountManager.php::getLineAPI($currentBotId)` ->
 * `getAccountById($currentBotId)` -> `SELECT * FROM line_accounts WHERE id =
 * ?` (narrowed to the one column this file needs). When no row matches, PHP
 * falls back to `new LineAPI()` (a config-constant-backed default channel)
 * and still attempts to send. Next has no such legacy config fallback (same
 * decision already established by api/inbox/actions/send-message/_lib/
 * sendMessage.ts and the dispense flex-send port) — a missing/invalid
 * `line_accounts` row for `currentBotId` means the notify is skipped
 * entirely (best-effort; the UPDATE/activity-log writes above already
 * happened and are not rolled back).
 */
async function fetchLineAccountToken(db: Kysely<TenantDB>, currentBotId: number): Promise<LineAccountTokenRow | undefined> {
  const result = await sql<LineAccountTokenRow>`
    SELECT channel_access_token FROM line_accounts WHERE id = ${currentBotId}
  `.execute(db);
  return result.rows[0];
}

/** Ported verbatim from shop/orders.php line 286 — the LINE-message status labels. Distinct from _lib/constants.ts's ORDER_STATUSES (the UI badge labels) — a SEPARATE map with different keys/wording, not to be merged. */
const LINE_NOTIFY_STATUS_TEXT: Record<string, string> = {
  confirmed: '✅ ยืนยันแล้ว',
  paid: '💰 ชำระเงินแล้ว',
  shipping: '🚚 กำลังจัดส่ง',
  delivered: '📦 จัดส่งแล้ว',
  cancelled: '❌ ยกเลิก',
};

export interface UpdateOrderStatusInput {
  orderId: number;
  status: string;
  /** `$_POST['tracking'] ?? ''` (line 288) — only persisted/appended to the message when status === 'shipping' AND the order resolves to a LINE user (see doc below for the exact PHP-preserved quirk). */
  tracking?: string;
}

/**
 * Port of shop/orders.php's `action === 'update_status'` branch (lines
 * 259-299):
 *   1. UPDATE transactions SET status = ? WHERE id = ? AND
 *      (line_account_id = ? OR line_account_id IS NULL) — TENANT-GUARDED.
 *   2. If newStatus is 'confirmed' or 'paid': best-effort UPDATE
 *      wms_status = 'pending_pick' (only when it was NULL/''), swallowing
 *      any error (e.g. the column not existing).
 *   3. Log one activity_logs row (ACTION_UPDATE).
 *   4. Re-fetch the order+user. If (and ONLY if) the order has a
 *      `line_user_id`: build the LINE message, and — a genuine PHP quirk,
 *      preserved exactly — the `shipping_tracking` UPDATE only runs INSIDE
 *      this `if ($order && $order['line_user_id'])` guard (PHP lines
 *      288-292). An order with no line_user_id (e.g. a non-LINE customer)
 *      NEVER gets its submitted tracking number saved, even though the
 *      admin filled it in. Not "fixed" here — replicated.
 */
export async function updateOrderStatusAction(input: UpdateOrderStatusInput): Promise<OrderActionResult> {
  assertValidOrderId(input.orderId);
  const { db, session } = await requireTenantPageContext();
  const currentBotId = session.currentBotId ?? 1;

  await sql`
    UPDATE transactions SET status = ${input.status}
    WHERE id = ${input.orderId} AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)
  `.execute(db);

  if (input.status === 'confirmed' || input.status === 'paid') {
    try {
      await sql`
        UPDATE transactions SET wms_status = 'pending_pick'
        WHERE id = ${input.orderId} AND (wms_status IS NULL OR wms_status = '')
      `.execute(db);
    } catch {
      // wms_status column may not exist — best-effort, matches PHP's own catch-and-ignore (line 269-271).
    }
  }

  await logOrderActivity(db, session, {
    action: 'update',
    description: 'อัพเดทสถานะคำสั่งซื้อ',
    entityId: input.orderId,
    newValue: { status: input.status },
  });

  const order = await fetchOrderNotifyContext(db, input.orderId);

  if (order && order.line_user_id) {
    let msg = `📋 อัพเดทรายการ #${order.order_number}\n\nสถานะ: ${LINE_NOTIFY_STATUS_TEXT[input.status] ?? input.status}`;

    if (input.status === 'shipping' && phpNotEmpty(input.tracking)) {
      await sql`UPDATE transactions SET shipping_tracking = ${input.tracking} WHERE id = ${input.orderId}`.execute(db);
      msg += `\n🚚 เลขพัสดุ: ${input.tracking}`;
    }

    const lineAccount = await fetchLineAccountToken(db, currentBotId);
    if (lineAccount) {
      await notifyOrderByLine(
        {
          userId: order.line_user_id,
          message: msg,
          replyToken: order.reply_token,
          tokenExpires: order.reply_token_expires_str,
        },
        { channelAccessToken: lineAccount.channel_access_token }
      );
    }
  }

  revalidatePath('/shop/orders');
  return { success: true };
}

/**
 * Port of shop/orders.php's `action === 'approve_payment'` branch (lines
 * 300-330).
 *
 * ⚠️ FLAGGED, DELIBERATELY UNGUARDED — genuine cross-file inconsistency,
 * preserved exactly as PHP wrote it, NOT fixed by this port:
 *
 *   UPDATE transactions SET payment_status = 'paid', status = 'paid' WHERE id = ?
 *
 * This UPDATE carries NO `line_account_id` / tenant predicate whatsoever —
 * unlike updateOrderStatusAction() above (this same file's own
 * update_status action, which DOES scope with `AND (line_account_id = ? OR
 * line_account_id IS NULL)`), and unlike shop/order-detail.php's own,
 * SEPARATELY-IMPLEMENTED `approve_payment` action (a different code path,
 * not shared with this file), which DOES scope its UPDATE. Any
 * authenticated tenant admin who knows/guesses an order id belonging to a
 * DIFFERENT tenant can flip that order to paid via this action as PHP wrote
 * it. Do not "fix" this by adding a guard here without a separate, explicit
 * decision — this batch's brief is to port behavior, not patch a
 * pre-existing PHP security gap.
 *
 * Also note (also preserved, also flagged): this action sends a PLAIN-TEXT
 * LINE message and performs NO loyalty-points award. shop/order-detail.php
 * has its own, separately-implemented action that happens to share the same
 * name ('approve_payment') but does BOTH a rich Flex message AND a
 * points award — these are two independent handlers that only share a
 * string, not a shared implementation to consolidate.
 */
export async function approvePaymentAction(orderId: number): Promise<OrderActionResult> {
  assertValidOrderId(orderId);
  const { db, session } = await requireTenantPageContext();
  const currentBotId = session.currentBotId ?? 1;

  // NO tenant guard — see the module/function doc above. Preserved exactly.
  await sql`UPDATE transactions SET payment_status = 'paid', status = 'paid' WHERE id = ${orderId}`.execute(db);

  try {
    // UNCONDITIONAL here (no `newStatus in (...)` gate) — unlike
    // updateOrderStatusAction()'s wms_status update above, which only fires
    // for newStatus 'confirmed'/'paid'. approve_payment's PHP source (lines
    // 304-310) runs this every time, with no status check at all (its own
    // status is always implicitly 'paid' via the UPDATE just above).
    await sql`
      UPDATE transactions SET wms_status = 'pending_pick'
      WHERE id = ${orderId} AND (wms_status IS NULL OR wms_status = '')
    `.execute(db);
  } catch {
    // wms_status column may not exist — best-effort, matches PHP's own catch-and-ignore (line 308-310).
  }

  await logOrderActivity(db, session, {
    action: 'approve',
    description: 'อนุมัติการชำระเงิน',
    entityId: orderId,
    newValue: { payment_status: 'paid', status: 'paid' },
  });

  const order = await fetchOrderNotifyContext(db, orderId);

  if (order && order.line_user_id) {
    const msg = `✅ ยืนยันการชำระเงินแล้ว!\n\nรายการ #${order.order_number}\nกำลังเตรียมดำเนินการ`;

    const lineAccount = await fetchLineAccountToken(db, currentBotId);
    if (lineAccount) {
      await notifyOrderByLine(
        {
          userId: order.line_user_id,
          message: msg,
          replyToken: order.reply_token,
          tokenExpires: order.reply_token_expires_str,
        },
        { channelAccessToken: lineAccount.channel_access_token }
      );
    }
  }

  revalidatePath('/shop/orders');
  return { success: true };
}
