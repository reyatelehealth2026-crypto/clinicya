import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { TenantSession } from '@reya/auth';
import type { DispenseItem } from './types';
import { floatval, intval, mtRand, phpElvis, phpEmpty, phpTruthy, trimOrEmpty } from './phpCompat';
import { bangkokYmdHisLong, bangkokYmdHisShort } from './bangkokTime';
import { trackFromDispense } from './refillTracking';
import { sendDispenseFlexMessage } from './flexSend';

/**
 * dispense.ts — literal port of inbox-v2.php's `case 'dispense':` (lines 469-736), the
 * same-page AJAX action (ระบบจ่ายยา / dispense system) fired from the dispense modal. Read the
 * full PHP block before editing this file; the section comments below cite exact PHP line
 * ranges.
 *
 * FAULT-TOLERANCE CONTRACT (mirrors PHP's own per-block try/catch structure — NOT an
 * all-or-nothing rewrite): only the initial `user_id`/`items` validation (PHP lines 473-491,
 * "User ID is required" / "No items to dispense") and the `users` lookup (PHP lines 533-539,
 * "User not found") can abort the whole action. Every step after that is independently
 * fault-tolerant, exactly matching PHP's scoping:
 *   - business_items hydration: try/catch PER ITEM, failures leave that item raw (PHP 496-531).
 *   - dispensing_records insert: UNGUARDED (PHP 580-582 has no try/catch around it) — a failure
 *     here throws all the way up to route.ts's outer catch, same as PHP's outer switch-level
 *     catch would surface it as a flat 400.
 *   - transaction + transaction_items + cart insert: one try/catch around the whole block
 *     (PHP 591-622) — a failure anywhere inside leaves `transactionId` at whatever it was before
 *     the failure (often still `null`) and the dispense continues regardless.
 *   - cash-only stock decrement: UNGUARDED (PHP 625-632, no try/catch) — like the
 *     dispensing_records insert, an error here propagates to route.ts's outer catch.
 *   - refill tracking: try/catch-and-continue (PHP 635-645).
 *   - LINE Flex send: try/catch-and-continue (PHP 649-720), only when the user has a
 *     `line_user_id`.
 *   - activity log: try/catch-and-continue (PHP 723-731).
 */

export interface DispenseRequestBody {
  user_id?: unknown;
  items?: unknown;
  total_amount?: unknown;
  payment_method?: unknown;
  notes?: unknown;
  shop_name?: unknown;
  pharmacist_name?: unknown;
}

export interface DispenseActionResult {
  status: number;
  body: Record<string, unknown>;
}

interface DispenseUserRow {
  line_user_id: string | null;
  line_account_id: number | null;
  display_name: string | null;
  reply_token: string | null;
  /** DATE_FORMAT'd 'YYYY-MM-DD HH:MM:SS' string — same convention as send-message/_lib/sendMessage.ts. */
  reply_token_expires_str: string | null;
}

interface BusinessItemHydrationRow {
  description: string | null;
  usage_instructions: string | null;
  default_usage_text: string | null;
  image_url: string | null;
  photo_path: string | null;
  generic_name: string | null;
  strength: string | null;
  manufacturer: string | null;
}

interface ShopSettingsRow {
  shop_name: string | null;
  address: string | null;
  shop_address: string | null;
  contact_phone: string | null;
  shop_logo: string | null;
  pharmacist_name: string | null;
}

interface LineAccountNameRow {
  name: string | null;
}

export interface DispenseShopInfo {
  name: string;
  address: string;
  phone: string;
  logo: string;
  open_hours: string;
  pharmacist: string;
}

/**
 * Parses the `items` field from the JSON request body into (1) the RAW payload as-received —
 * stored untouched into `dispensing_records.items`, mirroring PHP's `$items = $_POST['items']`
 * (a bare string, never decoded before that INSERT) — and (2) a fresh, independently-mutable
 * working array (`itemsArr`) — mirroring PHP's `$itemsArr = json_decode($items, true)`, which
 * produces a new array wholly separate from the `$items` string. Accepts either a JSON string
 * (PHP's own `$_POST['items']` shape, form-encoded) or a native JSON array (this being a new
 * JSON API, not bound to PHP's `$_POST` shape) — either way, `rawItemsJson` is what actually gets
 * persisted, and `itemsArr` is a deep-cloned copy the hydration loop is free to mutate without
 * ever touching the stored raw payload.
 */
function parseItemsInput(itemsInput: unknown): { rawItemsJson: string; itemsArr: DispenseItem[] } {
  const itemsValue = itemsInput !== undefined && itemsInput !== null ? itemsInput : '[]';

  if (typeof itemsValue === 'string') {
    let parsed: unknown = null;
    try {
      parsed = JSON.parse(itemsValue);
    } catch {
      parsed = null;
    }
    return { rawItemsJson: itemsValue, itemsArr: Array.isArray(parsed) ? (parsed as DispenseItem[]) : [] };
  }

  const rawItemsJson = JSON.stringify(itemsValue);
  let clone: unknown = [];
  try {
    // Deep clone via serialize/deserialize — itemsArr must never share object references with
    // whatever the caller passed in, so the hydration loop below can mutate it freely.
    clone = JSON.parse(rawItemsJson);
  } catch {
    clone = [];
  }
  return { rawItemsJson, itemsArr: Array.isArray(clone) ? (clone as DispenseItem[]) : [] };
}

export async function dispenseAction(
  db: Kysely<TenantDB>,
  session: TenantSession,
  userId: number,
  body: DispenseRequestBody,
  origin: string
): Promise<DispenseActionResult> {
  // PHP line 81: $currentBotId = $_SESSION['current_bot_id'] ?? 1; — used for every "business
  // table" write below (dispensing_records/cart/transactions/refill-tracking context), whereas
  // the LINE/shop-lookup queries further down use $user['line_account_id'] instead. This dual
  // scoping is a real PHP quirk, preserved verbatim.
  const currentBotId = session.currentBotId ?? 1;

  const totalAmount = floatval(body.total_amount);
  const paymentMethod = typeof body.payment_method === 'string' ? body.payment_method : 'cash';
  const notes = trimOrEmpty(body.notes);
  const shopNameInput = trimOrEmpty(body.shop_name);
  const pharmacistName = trimOrEmpty(body.pharmacist_name);
  const pharmacistId = session.adminUserId;
  const orderNumber = 'DIS' + bangkokYmdHisShort() + mtRand(100, 999);

  // PHP lines 487-491: json_decode + is_array/count validation.
  const { rawItemsJson, itemsArr } = parseItemsInput(body.items);
  if (itemsArr.length === 0) {
    throw new Error('No items to dispense');
  }

  // PHP lines 493-531: business_items hydration — description/usage/image/generic_name/
  // strength/manufacturer, try/catch PER ITEM, failures leave that item raw.
  for (const itm of itemsArr) {
    const pid = intval(itm.product_id);
    if (pid <= 0) continue;
    try {
      const result = await sql<BusinessItemHydrationRow>`
        SELECT description, usage_instructions, default_usage_text, image_url, photo_path,
               generic_name, strength, manufacturer
        FROM business_items WHERE id = ${pid} LIMIT 1
      `.execute(db);
      const bi = result.rows[0];
      if (!bi) continue;

      if (phpEmpty(itm.indication) && phpTruthy(bi.description)) {
        itm.indication = bi.description;
      }
      if (phpEmpty(itm.usage_text)) {
        itm.usage_text = phpElvis(bi.usage_instructions, bi.default_usage_text ?? '');
      }
      if (phpEmpty(itm.image)) {
        itm.image = phpElvis(bi.image_url, bi.photo_path ?? '');
      }
      if (phpEmpty(itm.generic_name) && phpTruthy(bi.generic_name)) {
        itm.generic_name = bi.generic_name;
      }
      if (phpEmpty(itm.strength) && phpTruthy(bi.strength)) {
        itm.strength = bi.strength;
      }
      if (phpEmpty(itm.manufacturer) && phpTruthy(bi.manufacturer)) {
        itm.manufacturer = bi.manufacturer;
      }
    } catch {
      // ignore — keep raw item, PHP: catch (\Throwable $e) {}
    }
  }

  // PHP lines 533-539: user lookup with reply token.
  const userRows = await sql<DispenseUserRow>`
    SELECT line_user_id, line_account_id, display_name, reply_token,
      DATE_FORMAT(reply_token_expires, '%Y-%m-%d %H:%i:%s') AS reply_token_expires_str
    FROM users WHERE id = ${userId}
  `.execute(db);
  const user = userRows.rows[0];
  if (!user) {
    throw new Error('User not found');
  }

  // PHP lines 541-577: shop info — pharmacist input wins, fall back to shop_settings +
  // line_accounts, keyed on $user['line_account_id'] (NOT currentBotId).
  const shopInfo: DispenseShopInfo = {
    name: '',
    address: '',
    phone: '',
    logo: '',
    open_hours: '08:00-24:00 น.',
    pharmacist: pharmacistName,
  };
  try {
    const shopSettingsResult = await sql<ShopSettingsRow>`
      SELECT shop_name, address, shop_address, contact_phone, shop_logo, pharmacist_name
      FROM shop_settings WHERE line_account_id = ${user.line_account_id}
    `.execute(db);
    const shopSettings = shopSettingsResult.rows[0];
    if (shopSettings) {
      shopInfo.name = trimOrEmpty(shopSettings.shop_name ?? '');
      shopInfo.address = trimOrEmpty(shopSettings.shop_address ?? shopSettings.address ?? '');
      shopInfo.phone = trimOrEmpty(shopSettings.contact_phone ?? '');
      if (shopInfo.pharmacist === '' && phpTruthy(shopSettings.pharmacist_name)) {
        shopInfo.pharmacist = String(shopSettings.pharmacist_name);
      }
      const rawLogo = shopSettings.shop_logo ?? '';
      if (rawLogo !== '') {
        if (/^https?:\/\//i.test(rawLogo)) {
          shopInfo.logo = rawLogo;
        } else {
          const safeOrigin = origin || 'https://re-ya.com';
          shopInfo.logo = safeOrigin + '/' + rawLogo.replace(/^\/+/, '');
        }
      }
    }
    // Final fallback: line_accounts.name.
    if (shopInfo.name === '') {
      const lineAccountResult = await sql<LineAccountNameRow>`SELECT name FROM line_accounts WHERE id = ${user.line_account_id}`.execute(
        db
      );
      const la = lineAccountResult.rows[0];
      shopInfo.name = phpElvis(trimOrEmpty(la?.name ?? ''), 'ร้านยา');
    }
  } catch {
    // ignore — PHP: catch (Exception $e) {}
  }
  if (phpTruthy(shopNameInput)) {
    shopInfo.name = shopNameInput;
  }

  // PHP lines 579-582: dispensing_records insert — UNGUARDED (no try/catch), stores the RAW
  // pre-hydration items payload, not the hydrated itemsArr.
  const dispenseInsertResult = await sql`
    INSERT INTO dispensing_records (line_account_id, user_id, pharmacist_id, order_number, items, total_amount, payment_method, notes)
    VALUES (${currentBotId}, ${userId}, ${pharmacistId}, ${orderNumber}, ${rawItemsJson}, ${totalAmount}, ${paymentMethod}, ${notes})
  `.execute(db);
  const dispenseId = Number(dispenseInsertResult.insertId ?? 0);

  // PHP lines 584-622: transaction + transaction_items + (non-cash) cart delete/insert — one
  // try/catch around the whole block.
  const isPaid = paymentMethod === 'cash';
  const paymentStatus: 'paid' | 'pending' = isPaid ? 'paid' : 'pending';
  const txnStatus: 'completed' | 'pending' = isPaid ? 'completed' : 'pending';
  let transactionId: number | null = null;

  try {
    if (!isPaid) {
      try {
        await sql`DELETE FROM cart WHERE user_id = ${userId} AND line_account_id = ${currentBotId}`.execute(db);
      } catch {
        // ignore — PHP: catch (Exception $e) {}
      }

      for (const item of itemsArr) {
        try {
          await sql`
            INSERT INTO cart (line_account_id, user_id, product_id, quantity, created_at)
            VALUES (${currentBotId}, ${userId}, ${item.product_id ?? null}, ${item.qty ?? 1}, NOW())
          `.execute(db);
        } catch {
          // ignore — PHP: catch (Exception $e) { error_log(...) }, continue with next item.
        }
      }
    }

    const txnOrderNumber = 'TXN' + bangkokYmdHisLong() + mtRand(100, 999);
    const deliveryInfo = JSON.stringify({ type: 'pickup', dispense_id: dispenseId, payment_method: paymentMethod });
    const txnInsertResult = await sql`
      INSERT INTO transactions
        (line_account_id, user_id, line_user_id, order_number, transaction_type, status, payment_status, payment_method, total_amount, grand_total, delivery_info, note, created_at)
      VALUES
        (${currentBotId}, ${userId}, ${user.line_user_id ?? null}, ${txnOrderNumber}, 'purchase', ${txnStatus}, ${paymentStatus}, ${paymentMethod}, ${totalAmount}, ${totalAmount}, ${deliveryInfo}, ${'จ่ายยา: ' + orderNumber}, NOW())
    `.execute(db);
    transactionId = Number(txnInsertResult.insertId ?? 0);

    for (const item of itemsArr) {
      const subtotal = (item.price ?? 0) * (item.qty ?? 1);
      await sql`
        INSERT INTO transaction_items (transaction_id, product_id, product_name, product_price, quantity, subtotal)
        VALUES (${transactionId}, ${item.product_id ?? null}, ${item.name ?? ''}, ${item.price ?? 0}, ${item.qty ?? 1}, ${subtotal})
      `.execute(db);
    }
  } catch {
    // ignore — PHP: catch (Exception $e) { error_log(...) }. transactionId may still be null.
  }

  // PHP lines 624-632: cash payment — ตัดสต๊อกทันที. UNGUARDED (no try/catch — a failure here
  // propagates to route.ts's outer catch, same as PHP's outer switch-level catch would). The
  // WHERE guard (`AND stock >= ?`) is the race-safety mechanism and must never be weakened, and
  // — matching PHP exactly — its affected-row-count is never checked (a silent no-op on
  // insufficient stock does not fail the dispense).
  if (isPaid) {
    for (const item of itemsArr) {
      if (phpTruthy(item.product_id) && phpTruthy(item.qty)) {
        await sql`UPDATE business_items SET stock = stock - ${item.qty} WHERE id = ${item.product_id} AND stock >= ${item.qty}`.execute(
          db
        );
      }
    }
  }

  // PHP lines 634-645: refill tracking — try/catch-and-continue.
  try {
    await trackFromDispense(db, itemsArr, {
      user_id: userId,
      line_user_id: user.line_user_id ?? null,
      line_account_id: currentBotId,
      dispense_id: dispenseId,
    });
  } catch {
    // ignore — PHP: catch (Exception $e) { error_log(...) }
  }

  // PHP lines 647-721: LINE Flex medicine-label send — try/catch-and-continue, only when the
  // user has a line_user_id.
  if (phpTruthy(user.line_user_id)) {
    try {
      await sendDispenseFlexMessage({
        db,
        userId,
        user: {
          line_user_id: user.line_user_id as string,
          line_account_id: user.line_account_id,
          display_name: user.display_name,
          reply_token: user.reply_token,
          reply_token_expires_str: user.reply_token_expires_str,
        },
        itemsArr,
        shopInfo,
        paymentMethod,
        orderNumber,
        transactionId,
      });
    } catch {
      // ignore — PHP: catch (Exception $e) { error_log(...) }
    }
  }

  // PHP lines 723-731: best-effort activity log
  // (ActivityLogger::logData(ACTION_CREATE, ...) -> log(TYPE_DATA='data', ACTION_CREATE='create', ...)).
  // Options omit admin_id/admin_name/line_account_id, so ActivityLogger::log() falls back to
  // $_SESSION['admin_id'] / $_SESSION['admin_user']['username'] / $_SESSION['current_bot_id'] —
  // same mapping as api/inbox/actions/medical/route.ts (session.adminUserId / session.username /
  // session.currentBotId ?? null).
  try {
    await db
      .insertInto('activity_logs')
      .values({
        log_type: 'data',
        action: 'create',
        description: 'จ่ายยา #' + orderNumber,
        user_id: userId,
        entity_type: 'dispense',
        entity_id: dispenseId,
        new_value: JSON.stringify({ order_number: orderNumber, total: totalAmount, items: itemsArr.length }),
        admin_id: session.adminUserId,
        admin_name: session.username,
        line_account_id: session.currentBotId ?? null,
      })
      .execute();
  } catch {
    // ignore
  }

  // PHP line 734: echo json_encode(['success' => true, 'order_number' => $orderNumber, 'dispense_id' => $dispenseId]);
  return { status: 200, body: { success: true, order_number: orderNumber, dispense_id: dispenseId } };
}
