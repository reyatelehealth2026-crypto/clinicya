import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import {
  medicineLabel,
  medicineLabelsCarousel,
  sendMessage as lineApiSendMessage,
  toMessage,
  type LineMessage,
  type ShopInfo,
} from '@reya/line';
import type { DispenseItem } from './types';
import { phpEmpty, phpTruthy } from './phpCompat';
import { reyaIsRealLiffId, reyaLiffUrlOrOa } from './checkoutUrl';

/**
 * flexSend.ts — port of inbox-v2.php's "Send LINE Flex Message (medicine label) to customer"
 * block (`case 'dispense':`, lines 647-721). The ENTIRE block in PHP is wrapped in one
 * `try { ... } catch (Exception $e) { error_log(...) }` — this file's exported function
 * reproduces that block's contents faithfully (including its own finer-grained inner
 * try/catches, see below), but does NOT itself catch every possible error at its outer edge;
 * dispense.ts's caller wraps the whole call in try/catch-and-continue, mirroring PHP's outer
 * try exactly (belt and suspenders, not "simplified" to a single layer).
 *
 * Only called when `!empty($user['line_user_id'])` (PHP line 648) — the caller is responsible
 * for that gate.
 */

export interface DispenseFlexUser {
  line_user_id: string;
  line_account_id: number | null;
  display_name: string | null;
  reply_token: string | null;
  /** DATE_FORMAT'd 'YYYY-MM-DD HH:MM:SS' string — see send-message/_lib/sendMessage.ts's own doc for why, same convention reused here. */
  reply_token_expires_str: string | null;
}

export interface SendDispenseFlexMessageParams {
  db: Kysely<TenantDB>;
  userId: number;
  user: DispenseFlexUser;
  /** The (already business_items-hydrated) working item array — mutated further in-place by this function's own image hydration pass, matching PHP's `foreach ($itemsArr as &$item)` at line 676. */
  itemsArr: DispenseItem[];
  shopInfo: ShopInfo;
  paymentMethod: string;
  orderNumber: string;
  /** `$transactionId` from the transaction-creation step — may be null if that step failed. */
  transactionId: number | null;
}

interface LineAccountTokenRow {
  channel_access_token: string;
}

interface LiffAppRow {
  liff_id: string;
}

interface BusinessItemImageRow {
  image_url: string | null;
}

export async function sendDispenseFlexMessage(params: SendDispenseFlexMessageParams): Promise<void> {
  const { db, userId, user, itemsArr, shopInfo, paymentMethod, orderNumber, transactionId } = params;

  // classes/LineAccountManager.php::getLineAPI($user['line_account_id']) — PHP falls back to a
  // config-constant-backed `new LineAPI()` when no `line_accounts` row matches; Next has no such
  // legacy config fallback (same decision as send-message/_lib/sendMessage.ts). Here that
  // decision means the flex send is simply skipped (best-effort — the whole block is
  // try/catch-wrapped by the caller anyway), not a hard error.
  const lineAccountRows = await sql<LineAccountTokenRow>`
    SELECT channel_access_token FROM line_accounts WHERE id = ${user.line_account_id}
  `.execute(db);
  const lineAccount = lineAccountRows.rows[0];
  if (!lineAccount) {
    return;
  }

  // Build checkout URL with LIFF-or-OA fallback (PHP lines 653-673).
  // Priority: dedicated liff_apps wrapper -> line_accounts.liff_id (via reyaLiffUrlOrOa) -> OA
  // chat -> '' (no checkout button rendered). Computed UNCONDITIONALLY regardless of payment
  // method, exactly like PHP — only the VALUE PASSED to the Flex builders below is gated on
  // `needCheckout`.
  const deepLink = transactionId ? `/order?id=${transactionId}` : '/orders';
  let checkoutUrl = '';
  // 1. Dedicated LIFF wrapper for the Mini App, if configured.
  try {
    const liffAppResult = await sql<LiffAppRow>`
      SELECT liff_id FROM liff_apps
      WHERE line_account_id = ${user.line_account_id} AND is_active = 1
        AND (name IN ('miniapp', 'order', 'checkout') OR endpoint_url LIKE '%/miniapp%')
      ORDER BY FIELD(name, 'order', 'checkout', 'miniapp') LIMIT 1
    `.execute(db);
    const liffApp = liffAppResult.rows[0];
    if (liffApp && reyaIsRealLiffId(liffApp.liff_id)) {
      const sep = deepLink.includes('?') ? '&' : '?';
      checkoutUrl = `https://liff.line.me/${liffApp.liff_id}${deepLink}${sep}la=${Number(user.line_account_id ?? 0)}`;
    }
  } catch {
    // ignore — PHP: catch (Exception $e) {}
  }
  // 2. Fall back to line_accounts.liff_id, else OA chat, else ''.
  if (checkoutUrl === '') {
    checkoutUrl = await reyaLiffUrlOrOa(db, user.line_account_id, deepLink);
  }

  // Hydrate item images from business_items (second, image-only hydration pass, PHP lines
  // 676-688 — distinct from dispense.ts's earlier description/usage/generic_name pass).
  for (const item of itemsArr) {
    if (phpTruthy(item.product_id) && phpEmpty(item.image)) {
      try {
        const productResult = await sql<BusinessItemImageRow>`
          SELECT image_url FROM business_items WHERE id = ${item.product_id}
        `.execute(db);
        const product = productResult.rows[0];
        if (product && phpTruthy(product.image_url)) {
          item.image = product.image_url;
        }
      } catch {
        // ignore — PHP: catch (Exception $e) {}
      }
    }
  }

  const needCheckout = paymentMethod === 'later' || paymentMethod === 'transfer';
  const patientName = user.display_name ?? '';

  const flexContents =
    itemsArr.length > 1
      ? medicineLabelsCarousel(itemsArr, shopInfo, patientName, needCheckout ? checkoutUrl : null)
      : (() => {
          // itemsArr is guaranteed non-empty by the time dispense.ts calls this function (it
          // already threw "No items to dispense" upstream when empty) — the explicit guard
          // below exists only to satisfy noUncheckedIndexedAccess, not because this path is
          // expected to be hit.
          const soleItem = itemsArr[0];
          if (!soleItem) {
            return null;
          }
          return medicineLabel(soleItem, shopInfo, patientName, needCheckout ? checkoutUrl : null);
        })();
  if (flexContents === null) {
    return;
  }
  const flexMessage = toMessage(flexContents, `💊 รายการจ่ายยา #${orderNumber}`);

  // Reply-token-first sendMessage() with push fallback (classes/LineAPI.php::sendMessage(), now
  // @reya/line's sendMessage()) — PHP: `$line->sendMessage($user['line_user_id'], [$flexMessage],
  // $user['reply_token'] ?? null, $user['reply_token_expires'] ?? null, $db, $userId)`.
  await lineApiSendMessage(
    {
      userId: user.line_user_id,
      // LineFlexMessage is a precise structural type (no index signature); LineMessage (the
      // generic Messaging API envelope @reya/line's sendMessage() accepts) intentionally has
      // one so it stays independently buildable from flex.ts (see packages/line/src/api.ts's
      // own module doc) — the cast just bridges those two, the wire shape is unchanged.
      messages: [flexMessage as unknown as LineMessage],
      replyToken: user.reply_token,
      tokenExpires: user.reply_token_expires_str,
      internalUserId: userId,
      onReplyTokenUsed: async () => {
        // classes/LineAPI.php::sendMessage() clears the single-use reply token itself via
        // clearReplyToken($db, $userId) internally — @reya/line has zero @reya/db dependency, so
        // that side effect is this injected callback (same pattern as send-message/_lib/sendMessage.ts).
        await db.updateTable('users').set({ reply_token: null, reply_token_expires: null }).where('id', '=', userId).execute();
      },
    },
    { channelAccessToken: lineAccount.channel_access_token }
  );
  // NOTE: PHP does not branch on the LINE API call's result here — the outgoing `messages` row
  // below is written unconditionally, win or lose. Preserved as-is (not "improved" with a result check).

  // Persist outgoing flex message in chat history (PHP lines 704-717).
  //
  // DELIBERATE DEVIATION from send-message/_lib/sendMessage.ts's own precedent: that file treats
  // this same `SHOW COLUMNS FROM messages LIKE 'sent_by'` runtime schema probe as unreachable
  // dead code against the current committed schema (`sent_by` always exists) and drops it,
  // inserting only the sent_by-inclusive shape. Here BOTH insert-shape branches are kept
  // verbatim, per this batch's explicit brief — do not "simplify" this away.
  let hasSentBy = false;
  try {
    const checkCol = await sql`SHOW COLUMNS FROM messages LIKE 'sent_by'`.execute(db);
    hasSentBy = checkCol.rows.length > 0;
  } catch {
    // ignore — hasSentBy stays false, PHP: catch (Exception $e) {}
  }
  const msgContent = JSON.stringify(flexMessage);
  if (hasSentBy) {
    await db
      .insertInto('messages')
      .values({
        line_account_id: user.line_account_id,
        user_id: userId,
        direction: 'outgoing',
        message_type: 'flex',
        content: msgContent,
        sent_by: 'system:dispense',
        created_at: sql<Date>`NOW()`,
        is_read: 1,
      })
      .execute();
  } else {
    await db
      .insertInto('messages')
      .values({
        line_account_id: user.line_account_id,
        user_id: userId,
        direction: 'outgoing',
        message_type: 'flex',
        content: msgContent,
        created_at: sql<Date>`NOW()`,
        is_read: 1,
      })
      .execute();
  }
}
