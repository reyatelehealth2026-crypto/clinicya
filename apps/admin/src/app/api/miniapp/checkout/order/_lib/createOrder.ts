import { sql, type Kysely, type Transaction } from 'kysely';
import type { TenantDB } from '@reya/db';
import { coalesce, phpFalsy, strOrEmpty, toFloatOrZero, toIntOrZero } from './phpCompat';
import { checkoutOrderUnitPrice, loadCheckoutCartLinesFromDb, resolveCartProductSource, type CheckoutCartLine } from './cartLines';
import { bangkokYmd } from './bangkokTime';
import { notifyTelegramNewOrder } from './notify';

/**
 * createOrder.ts — port of api/checkout.php's handleCreateOrder() (action=create_order, L1288-1656). Read
 * the full function (and its helpers — resolveCartProductSource L167-172, checkoutOrderUnitPrice
 * L201-214, loadCheckoutCartLinesFromDb L216-312, all duplicated locally in ./cartLines.ts; tableExists
 * L314-347; jsonResponse L97-100) before editing this file.
 *
 * NON-NEGOTIABLE, byte-for-byte (this batch's acceptance criteria): the guarded UPDATE stays exactly
 * `UPDATE business_items SET stock = stock - ? WHERE id = ? AND stock >= ?` and PHP NEVER checks either
 * guarded UPDATE's (business_items.stock / shop_products.saleable_qty) affected-row-count afterward — the
 * order is created/committed even when a guard silently no-ops on insufficient stock. Do NOT add a
 * rowCount/affected-rows check here as an "improvement"; that changes behavior beyond what was authorized
 * (see fixtures/checkout-order/create-order-insufficient-stock-guard-noop.json and createOrder.test.ts's
 * matching case).
 *
 * SIMPLIFICATION (flagged, schema-verification-confirmed — see this batch's acceptance criteria): PHP
 * wraps the `transactions` INSERT in a 3-level fallback cascade (Level 1: full insert incl.
 * payment_status/line_user_id; Level 2: without line_user_id; Level 3: without payment_status), preceded
 * by a defensive `ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_status ...`.
 * packages/db/src/generated/tenant-db.d.ts confirms Transactions has BOTH `payment_status` and
 * `line_user_id` unconditionally present on the committed schema — Level 1 is the only branch ever
 * reached. This port implements ONLY Level 1; the ALTER TABLE DDL-on-request is dropped entirely (plan
 * §4.1 bans auto-create-schema in new code; CLAUDE.md's "Auto-create tables" convention disallows this
 * pattern for new features too), and Levels 2/3 are unreachable dead code, not ported.
 *
 * DEFERRED, NOT silently dropped (orchestrator scoping decision, this batch's brief): AR ledger creation
 * (AccountReceivableService::createFromTransaction(), L1594-1608, for credit/cod/term/invoice payment
 * methods), NotificationService::notifyNewOrder() (LINE/email fanout beyond the Telegram push below), and
 * ActivityLogger::logOrder() (audit trail) are OUT OF SCOPE this round — see the TODO comment at this
 * function's end. `ar_id` is always `null` in this port's response.
 *
 * notifyTelegramNewOrder() IS in scope and is called after commit, independently try/catch-swallowed
 * (see ./notify.ts's own module doc) — a notify failure must never fail the already-committed order
 * response, matching PHP's ordering exactly (commit -> AR hook -> Telegram -> NotificationService ->
 * ActivityLogger -> jsonResponse).
 */

export interface CreateOrderActionResult {
  status: number;
  body: Record<string, unknown>;
}

/** Port of api/checkout.php's local `jsonResponse($success, $message, $data = [])` — always HTTP 200. */
function ok(success: boolean, message: string, data: Record<string, unknown> = {}): CreateOrderActionResult {
  return { status: 200, body: { success, message, ...data } };
}

/** `SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1` (`?? 1` fallback) — reused at L1315-1317/L1330-1332. */
async function resolveDefaultLineAccountId(db: Kysely<TenantDB>): Promise<number> {
  const result = await sql<{ id: number }>`SELECT id FROM line_accounts WHERE is_active = 1 ORDER BY is_default DESC LIMIT 1`.execute(db);
  return Number(result.rows[0]?.id ?? 1);
}

/** `mt_rand(1, 9999)`. */
function mtRand1to9999(): number {
  return 1 + Math.floor(Math.random() * 9999);
}

interface DeliveryInfo {
  type: 'shipping';
  name: string;
  phone: string;
  address: string;
  subdistrict: string;
  district: string;
  province: string;
  postcode: string;
  full_address: string;
}

/**
 * Port of L1408-1425's delivery_info build — DISTINCT from the dispense flow's `{type:'pickup',
 * dispense_id, payment_method}` shape (messages.php/inbox-v2.php, Phase 5, not this round) — both shapes
 * share the same `transactions.delivery_info` column, so this INSERT's column list must never preclude
 * Phase 5's future dispense writer from continuing to use its own shape.
 */
function buildDeliveryInfo(address: Record<string, unknown>): DeliveryInfo {
  const name = strOrEmpty(coalesce(address.name, ''));
  const phone = strOrEmpty(coalesce(address.phone, ''));
  const addressLine = strOrEmpty(coalesce(address.address, ''));
  const subdistrict = strOrEmpty(coalesce(address.subdistrict, ''));
  const district = strOrEmpty(coalesce(address.district, ''));
  const province = strOrEmpty(coalesce(address.province, ''));
  const postcode = strOrEmpty(coalesce(address.postcode, ''));
  // `trim(implode(' ', array_filter([...])))` — array_filter's default callback drops PHP-falsy entries
  // ('' and the literal string '0'); every input here is already a string via strOrEmpty() above.
  const full_address = [addressLine, subdistrict, district, province, postcode]
    .filter((v) => v !== '' && v !== '0')
    .join(' ')
    .trim();
  return {
    type: 'shipping',
    name,
    phone,
    address: addressLine,
    subdistrict,
    district,
    province,
    postcode,
    full_address,
  };
}

interface ShopSettingsFeeRow {
  shipping_fee: unknown;
  free_shipping_min: unknown;
}

export async function handleCreateOrder(db: Kysely<TenantDB>, data: Record<string, unknown>): Promise<CreateOrderActionResult> {
  let userId: number | null = data.user_id !== undefined && data.user_id !== null ? toFloatOrZero(data.user_id) : null;
  const lineUserId: string | null = data.line_user_id !== undefined && data.line_user_id !== null ? strOrEmpty(data.line_user_id) : null;
  const requestLineAccountId = data.line_account_id;
  const address =
    data.address && typeof data.address === 'object' && !Array.isArray(data.address) ? (data.address as Record<string, unknown>) : {};
  const paymentMethod = strOrEmpty(coalesce(data.payment_method, 'transfer'));
  const displayName = strOrEmpty(coalesce(data.display_name, coalesce(address.name, 'LIFF User')));
  const cartItemsRaw = coalesce<unknown>(data.cart_items, null);
  const requestSubtotal = coalesce<unknown>(data.subtotal, null);
  const requestShipping = coalesce<unknown>(data.shipping, null);
  const requestTotal = coalesce<unknown>(data.total, null);

  // ---- Resolve user_id / line_account_id (L1302-1338) --------------------
  let lineAccountId: number | null = null;

  if (!phpFalsy(lineUserId)) {
    const existing = await sql<{ id: number; line_account_id: number }>`SELECT id, line_account_id FROM users WHERE line_user_id = ${lineUserId}`.execute(
      db
    );
    const user = existing.rows[0];
    if (user) {
      userId = Number(user.id);
      lineAccountId = Number(user.line_account_id);
    } else {
      lineAccountId = phpFalsy(requestLineAccountId) ? null : toFloatOrZero(requestLineAccountId);
      if (phpFalsy(lineAccountId)) {
        lineAccountId = await resolveDefaultLineAccountId(db);
      }
      const insertResult = await sql`
        INSERT INTO users (line_account_id, line_user_id, display_name) VALUES (${lineAccountId}, ${lineUserId}, ${displayName})
      `.execute(db);
      userId = Number(insertResult.insertId ?? 0);
    }
  }

  if (phpFalsy(lineAccountId)) {
    lineAccountId = phpFalsy(requestLineAccountId) ? null : toFloatOrZero(requestLineAccountId);
    if (phpFalsy(lineAccountId)) {
      lineAccountId = await resolveDefaultLineAccountId(db);
    }
  }

  if (phpFalsy(userId)) {
    return ok(false, `User not found (line_user_id: ${lineUserId === null ? 'null' : lineUserId})`);
  }

  const finalUserId = Number(userId);
  const finalLineAccountId = phpFalsy(lineAccountId) ? null : Number(lineAccountId);

  // Full user row for notifications (also set when the user was just INSERTed) — L1340-1346.
  const fullUserResult = await sql<{ id: number; line_account_id: number; display_name: string | null; line_user_id: string | null }>`
    SELECT id, line_account_id, display_name, line_user_id FROM users WHERE id = ${finalUserId} LIMIT 1
  `.execute(db);
  const user = fullUserResult.rows[0] ?? { display_name: displayName, line_user_id: lineUserId };

  // ---- Cart items: request-provided or loaded from DB (L1348-1371) -------
  let items: CheckoutCartLine[];
  if (Array.isArray(cartItemsRaw) && cartItemsRaw.length > 0) {
    items = cartItemsRaw.map((raw) => {
      const item = raw && typeof raw === 'object' && !Array.isArray(raw) ? (raw as Record<string, unknown>) : {};
      const unit = toFloatOrZero(coalesce<unknown>(item.price, 0));
      return {
        product_id: toIntOrZero(coalesce<unknown>(item.product_id, 0)),
        name: strOrEmpty(coalesce<unknown>(item.name, '')),
        price: unit,
        sale_price: unit,
        quantity: toIntOrZero(coalesce<unknown>(item.quantity, 0)),
        product_source: resolveCartProductSource(coalesce<unknown>(item.product_source, null)),
        _unit: unit,
      };
    });
  } else {
    items = await loadCheckoutCartLinesFromDb(db, finalUserId, finalLineAccountId);
  }

  if (items.length === 0) {
    return ok(false, 'Cart is empty');
  }

  // ---- Subtotal / shipping / total (L1373-1406) ---------------------------
  let subtotal = 0;
  for (const item of items) {
    const unit = item._unit !== undefined ? Number(item._unit) : checkoutOrderUnitPrice(item);
    subtotal += unit * item.quantity;
  }
  if (requestSubtotal !== null) {
    subtotal = toFloatOrZero(requestSubtotal);
  }

  let shippingFee: number;
  if (requestShipping !== null) {
    shippingFee = toFloatOrZero(requestShipping);
  } else {
    let settings: ShopSettingsFeeRow | undefined;
    if (!phpFalsy(finalLineAccountId)) {
      const scoped = await sql<ShopSettingsFeeRow>`
        SELECT shipping_fee, free_shipping_min FROM shop_settings WHERE line_account_id = ${finalLineAccountId} LIMIT 1
      `.execute(db);
      settings = scoped.rows[0];
    }
    if (!settings) {
      const fallback = await sql<ShopSettingsFeeRow>`SELECT shipping_fee, free_shipping_min FROM shop_settings LIMIT 1`.execute(db);
      settings = fallback.rows[0];
    }
    shippingFee = toFloatOrZero(coalesce<unknown>(settings?.shipping_fee, 50));
    const freeShippingMin = toFloatOrZero(coalesce<unknown>(settings?.free_shipping_min, 500));
    if (subtotal >= freeShippingMin) {
      shippingFee = 0;
    }
  }

  const total = requestTotal !== null ? toFloatOrZero(requestTotal) : subtotal + shippingFee;
  const deliveryInfo = buildDeliveryInfo(address);

  // ---- Create order (L1427-1592, inside one DB transaction) ---------------
  const orderNumber = `TXN${bangkokYmd()}${String(mtRand1to9999()).padStart(4, '0')}`;
  const orderStatus: 'confirmed' | 'pending' = paymentMethod === 'cod' ? 'confirmed' : 'pending';
  const paymentStatus = 'pending';

  const deliveryInfoJson = JSON.stringify(deliveryInfo); // json_encode($deliveryInfo, JSON_UNESCAPED_UNICODE) — JSON.stringify already leaves UTF-8 unescaped.

  const orderId = await db.transaction().execute(async (trx: Transaction<TenantDB>) => {
    const insertResult = await sql`
      INSERT INTO transactions
      (line_account_id, transaction_type, order_number, user_id, line_user_id, total_amount, shipping_fee, grand_total, delivery_info, payment_method, status, payment_status)
      VALUES (${finalLineAccountId}, 'purchase', ${orderNumber}, ${finalUserId}, ${lineUserId}, ${subtotal}, ${shippingFee}, ${total}, ${deliveryInfoJson}, ${paymentMethod}, ${orderStatus}, ${paymentStatus})
    `.execute(trx);
    const newOrderId = Number(insertResult.insertId ?? 0);

    for (const item of items) {
      const unit = item._unit !== undefined ? Number(item._unit) : checkoutOrderUnitPrice(item);
      const itemSubtotal = unit * item.quantity;
      const src = item.product_source ?? 'business_items';

      await sql`
        INSERT INTO transaction_items (transaction_id, product_id, product_name, product_price, quantity, subtotal)
        VALUES (${newOrderId}, ${item.product_id}, ${item.name}, ${unit}, ${item.quantity}, ${itemSubtotal})
      `.execute(trx);

      if (src === 'shop_products') {
        // SIMPLIFICATION: tableExists('shop_products') is unconditionally true on the committed schema
        // (see cartLines.ts's module doc) — the PHP guard is dropped.
        // NON-NEGOTIABLE: PHP never checks this UPDATE's affected-row-count — see this file's module doc.
        await sql`
          UPDATE shop_products SET saleable_qty = saleable_qty - ${item.quantity}, updated_at = NOW()
          WHERE id = ${item.product_id} AND line_account_id = ${finalLineAccountId} AND saleable_qty >= ${item.quantity}
        `.execute(trx);
      } else {
        // NON-NEGOTIABLE, byte-for-byte: keep this exact WHERE guard — never weaken it — and never check
        // its affected-row-count. See this file's module doc.
        await sql`UPDATE business_items SET stock = stock - ${item.quantity} WHERE id = ${item.product_id} AND stock >= ${item.quantity}`.execute(
          trx
        );

        try {
          // SIMPLIFICATION: the `SHOW TABLES LIKE 'stock_movements'` table-existence probe is dropped —
          // packages/db's tenant-db.d.ts confirms the table is unconditionally present on the committed
          // schema. The insert itself stays try/catch-guarded to preserve PHP's non-fatal-on-failure
          // semantics (e.g. a NOT NULL violation on stock_before/stock_after when the product row is
          // missing/its stock column is NULL must not abort the whole order transaction).
          const stockRow = await sql<{ stock: number | null }>`SELECT stock FROM business_items WHERE id = ${item.product_id}`.execute(trx);
          const currentStock = stockRow.rows[0] ? stockRow.rows[0].stock : null;
          await sql`
            INSERT INTO stock_movements
            (line_account_id, product_id, movement_type, quantity, stock_before, stock_after, reference_type, reference_id, reference_number, notes, created_by)
            VALUES (${finalLineAccountId}, ${item.product_id}, 'sale', ${-item.quantity}, ${(currentStock ?? 0) + item.quantity}, ${currentStock}, 'order', ${newOrderId}, ${orderNumber}, ${'ขายสินค้า: ' + item.name}, NULL)
          `.execute(trx);
        } catch {
          // swallow — matches PHP's catch (Exception $e) { error_log('Stock movement error: ' . ...); }
        }
      }
    }

    // Clear cart — L1578-1580.
    await sql`DELETE FROM cart_items WHERE user_id = ${finalUserId}`.execute(trx);

    // WMS: pending_pick for already-confirmed (COD) orders — L1582-1590.
    if (orderStatus === 'confirmed') {
      try {
        await sql`UPDATE transactions SET wms_status = 'pending_pick' WHERE id = ${newOrderId}`.execute(trx);
      } catch {
        // wms_status column may not exist on an older schema — swallow, matches PHP.
      }
    }

    return newOrderId;
  });

  // ---- Post-commit (L1594-1648) -------------------------------------------
  // TODO(phase9-accounting / cross-cutting-notifications): port
  // AccountReceivableService::createFromTransaction() (L1594-1608 — AR ledger row for
  // credit/cod/term/invoice payment methods), NotificationService::notifyNewOrder() (L1613-1625 —
  // LINE/email fanout), and ActivityLogger::logOrder() (L1627-1640 — audit trail). Deliberately deferred
  // this round per checkout/order's mig-api brief (orchestrator scoping decision, not a silent drop).
  const arId: number | null = null;

  // Telegram push IS in scope — best-effort, independently try/catch-swallowed exactly like the PHP
  // original (notifyTelegramNewOrder() already never throws; .catch() here is defense-in-depth only).
  await notifyTelegramNewOrder(db, { orderId, orderNumber, total, user, deliveryInfo }).catch(() => false);

  return ok(true, 'Order created', {
    order_id: orderId,
    order_number: orderNumber,
    total,
    payment_method: paymentMethod,
    ar_id: arId,
  });
}
