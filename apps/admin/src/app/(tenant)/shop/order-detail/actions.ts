'use server';

import { sql } from 'kysely';
import { redirect } from 'next/navigation';
import { requireTenantPageContext } from './_lib/session';
import { SlipVerifier, type VerifyResult } from './_lib/slipVerifier';
import { sendOrderStatusFlex, sendOrderRejectionFlex } from './_lib/orderStatusFlex';
import { awardOrderLoyaltyPoints } from './_lib/loyaltyAward';
import { getShopAccounts } from './queries';

/**
 * actions.ts — Server Actions for shop/order-detail.php's ENTIRE POST
 * handler (PHP lines 189-535), one function per `case`/`if ($action ===
 * ...)` branch: verifySlipAction, updateStatusAction, approvePaymentAction,
 * updateShippingAction, rejectPaymentAction, addTrackingAction.
 *
 * TENANT-GUARD FIDELITY (do not "fix"/unify — this is the literal PHP
 * behavior, confirmed against the source):
 *   - updateStatusAction's `status`/`shipping_tracking` UPDATEs, and
 *     approvePaymentAction's `payment_status`/`status` UPDATE, and
 *     verifySlipAction's `payment_status`/`status` UPDATE on a verified
 *     slip, ALL carry `AND (line_account_id = ? OR line_account_id IS
 *     NULL)`.
 *   - updateShippingAction's UPDATE and addTrackingAction's UPDATE carry NO
 *     such guard whatsoever — PHP's own source comment on the add_tracking
 *     UPDATE literally says "Update without line_account_id filter to
 *     ensure it works" (shop/order-detail.php line 513). Preserved exactly.
 * See `queries.ts`'s sibling `guardEnumeration.test.ts`-style coverage in
 * `actions.test.ts` for a dedicated test enumerating this.
 *
 * approvePaymentAction's loyalty-points award is genuinely a DIFFERENT code
 * path from `(tenant)/user-detail/actions.ts`'s `addPointsAction` — see
 * `./_lib/loyaltyAward.ts`'s own module doc for the full divergence table.
 * Do not consolidate them.
 *
 * Every action redirects back to `/shop/order-detail?id=N&...` on
 * completion, mirroring PHP's `header("Location: order-detail.php?id=
 * {$orderId}&...")`. `rejectPaymentAction` is DELIBERATELY not wrapped in a
 * top-level try/catch — the PHP `reject_payment` branch (lines 450-507) has
 * none either; every other action mirrors PHP's own try/catch (or lack of
 * one) exactly.
 *
 * OUT OF SCOPE (flagged, not silently dropped): `updateStatusAction`'s and
 * `approvePaymentAction`'s "V2.5 Auto-fulfill digital items" block (PHP:
 * `if (file_exists(__DIR__ . '/../classes/BusinessBot.php')) { ...
 * $businessBot->autoFulfillDigitalItems($orderId); }`). `classes/
 * BusinessBot.php` has not been ported to Next in this batch (no
 * `@reya/business-bot` package / equivalent exists), and porting it is
 * outside this page's allowed-paths boundary. PHP itself treats this as a
 * best-effort optional integration (`file_exists()`-guarded, try/catch
 * wrapped, errors only logged) — the Next port's omission is the same
 * "silently skip if unavailable" behavior a tenant with no BusinessBot
 * class would already see in PHP, just unconditional here. Flagged in the
 * build report for a future BusinessBot-porting batch.
 */

function firstString(formData: FormData, key: string): string {
  const v = formData.get(key);
  return typeof v === 'string' ? v : '';
}

/** Mirrors PHP's `!empty($x)` / bare `if ($x)` truthiness for a plain $_POST string: false only for '' and '0'. */
function phpTruthyStr(value: string): boolean {
  return value !== '' && value !== '0';
}

/** Mirrors PHP's `empty($x)` for a nullable DB string column: true for null/undefined/''/'0'. */
function phpEmptyStr(value: string | null | undefined): boolean {
  return value === null || value === undefined || value === '' || value === '0';
}

// ---------------------------------------------------------------------------
// verify_slip — PHP lines 195-291.
// ---------------------------------------------------------------------------

interface StoredSlipRow {
  id: number;
  transaction_id: number | null;
  qr_payload: string | null;
  verify_data: string | null;
}

interface OrderAmountRow {
  grand_total: string | number | null;
  total_amount: string | number | null;
}

export async function verifySlipAction(orderId: number, formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const currentBotId = session.currentBotId ?? 1;

  const slipId = Number.parseInt(firstString(formData, 'slip_id'), 10) || 0;
  const postedQr = firstString(formData, 'qr_data').trim();
  let reason = 'no_qr';

  try {
    const slipRows = await sql<StoredSlipRow>`
      SELECT id, transaction_id, qr_payload, verify_data FROM payment_slips WHERE id = ${slipId} AND transaction_id = ${orderId} LIMIT 1
    `.execute(db);
    const slip = slipRows.rows[0] ?? null;

    // Prefer a QR the admin's browser just decoded from the image; fall
    // back to the one stored at upload. Persist a freshly decoded one.
    const qr = postedQr !== '' ? postedQr : (slip?.qr_payload ?? '');
    if (slip && postedQr !== '' && phpEmptyStr(slip.qr_payload)) {
      try {
        await sql`UPDATE payment_slips SET qr_payload = ${postedQr} WHERE id = ${slipId}`.execute(db);
      } catch {
        // qr_payload column may be missing on a not-yet-migrated tenant DB — PHP: catch (\Throwable $e) {}
      }
    }

    if (slip && qr !== '') {
      // Expected amount = order grand_total.
      const orderRows = await sql<OrderAmountRow>`SELECT grand_total, total_amount FROM transactions WHERE id = ${orderId} LIMIT 1`.execute(db);
      const ord = orderRows.rows[0];
      const expectedAmount = Number(ord?.grand_total ?? ord?.total_amount ?? 0);

      // Shop destination accounts (PromptPay + bank accounts).
      const shopAccounts = await getShopAccounts(db, currentBotId);

      // GhostX rejects re-scans of the same QR with HTTP 409. If we already
      // captured the GhostX response at upload, re-evaluate it instead of
      // re-scanning; only call GhostX fresh if we have none.
      const verifier = new SlipVerifier();
      let prior: { slipVerification?: { transfer?: unknown } } | null = null;
      if (slip.verify_data) {
        try {
          prior = JSON.parse(slip.verify_data) as { slipVerification?: { transfer?: unknown } };
        } catch {
          prior = null;
        }
      }

      let vr: VerifyResult;
      if (prior && prior.slipVerification?.transfer) {
        vr = verifier.verifyStored(prior as unknown as Record<string, unknown>, expectedAmount, shopAccounts, false);
      } else {
        vr = await verifier.verify(qr, expectedAmount, shopAccounts, false);
      }
      reason = vr.reason;
      const vd = JSON.stringify(vr.data);

      // Guard against the same slip ref being reused on another order.
      let dup = false;
      if (vr.ref) {
        const dupRows = await sql<{ id: number }>`SELECT id FROM payment_slips WHERE verify_ref = ${vr.ref} AND id <> ${slipId} LIMIT 1`.execute(db);
        dup = dupRows.rows.length > 0;
      }

      if (vr.verified && !dup) {
        await sql`
          UPDATE payment_slips SET status='approved', verify_ref=${vr.ref}, verify_amount=${vr.amount}, verify_data=${vd}, verified_at=NOW() WHERE id=${slipId}
        `.execute(db);
        await sql`
          UPDATE transactions SET payment_status='paid', status='paid' WHERE id=${orderId} AND (line_account_id=${currentBotId} OR line_account_id IS NULL)
        `.execute(db);
        try {
          await sendOrderStatusFlex(db, currentBotId, orderId, 'paid');
        } catch (e) {
          console.error('verify_slip flex error:', e);
        }
        reason = 'ok';
      } else {
        if (dup) {
          reason = 'duplicate_ref';
        }
        // Never clobber a real stored response with an empty one (a
        // re-scan that hit GhostX 409 returns no data) so the saved
        // upload-time response stays available for re-evaluation.
        if (vr.data && Object.keys(vr.data).length > 0) {
          await sql`UPDATE payment_slips SET verify_amount=${vr.amount}, verify_data=${vd} WHERE id=${slipId}`.execute(db);
        }
      }
    }
  } catch (e) {
    console.error('verify_slip error:', e);
    reason = 'error';
  }

  redirect(`/shop/order-detail?id=${orderId}&verify=${encodeURIComponent(reason)}`);
}

// ---------------------------------------------------------------------------
// update_status — PHP lines 293-344.
// ---------------------------------------------------------------------------

export async function updateStatusAction(orderId: number, formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const currentBotId = session.currentBotId ?? 1;

  try {
    const newStatus = firstString(formData, 'status');
    await sql`UPDATE transactions SET status = ${newStatus} WHERE id = ${orderId} AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)`.execute(db);

    // WMS Integration: Set wms_status to pending_pick when order is confirmed or paid.
    if (newStatus === 'confirmed' || newStatus === 'paid') {
      try {
        await sql`UPDATE transactions SET wms_status = 'pending_pick' WHERE id = ${orderId} AND (wms_status IS NULL OR wms_status = '')`.execute(db);
      } catch {
        // wms_status column may not exist, ignore — PHP: catch (Exception $e) {}
      }
    }

    // Update tracking if provided. PHP: `if (!empty($_POST['tracking']))`.
    let tracking: string | null = null;
    const trackingRaw = firstString(formData, 'tracking');
    if (phpTruthyStr(trackingRaw)) {
      tracking = trackingRaw;
      await sql`UPDATE transactions SET shipping_tracking = ${tracking} WHERE id = ${orderId} AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)`.execute(db);
    }

    // Send Flex notification to customer (with error handling).
    try {
      await sendOrderStatusFlex(db, currentBotId, orderId, newStatus, tracking);
    } catch (e) {
      console.error('sendOrderStatusFlex error:', e);
    }

    // V2.5 auto-fulfill digital items: OUT OF SCOPE — see module doc above.
  } catch (e) {
    console.error('update_status error:', e);
  }

  redirect(`/shop/order-detail?id=${orderId}&updated=1`);
}

// ---------------------------------------------------------------------------
// approve_payment — PHP lines 346-436.
// ---------------------------------------------------------------------------

export async function approvePaymentAction(orderId: number, _formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const currentBotId = session.currentBotId ?? 1;

  try {
    await sql`UPDATE transactions SET payment_status = 'paid', status = 'paid' WHERE id = ${orderId} AND (line_account_id = ${currentBotId} OR line_account_id IS NULL)`.execute(db);

    // WMS Integration: Set wms_status to pending_pick when payment approved.
    try {
      await sql`UPDATE transactions SET wms_status = 'pending_pick' WHERE id = ${orderId} AND (wms_status IS NULL OR wms_status = '')`.execute(db);
    } catch {
      // wms_status column may not exist, ignore — PHP: catch (Exception $e) {}
    }

    await sql`UPDATE payment_slips SET status = 'approved' WHERE transaction_id = ${orderId} AND status = 'pending'`.execute(db);

    // Send Flex notification (with error handling).
    try {
      await sendOrderStatusFlex(db, currentBotId, orderId, 'paid');
    } catch (e) {
      console.error('sendOrderStatusFlex error:', e);
    }

    // V2.5 auto-fulfill digital items: OUT OF SCOPE — see module doc above.

    // Award loyalty points (order-detail.php's OWN inline block — see ./_lib/loyaltyAward.ts's module doc).
    try {
      await awardOrderLoyaltyPoints(db, orderId, currentBotId);
    } catch (e) {
      console.error('Award points error:', e);
    }
  } catch (e) {
    console.error('approve_payment error:', e);
  }

  redirect(`/shop/order-detail?id=${orderId}&updated=1`);
}

// ---------------------------------------------------------------------------
// update_shipping — PHP lines 438-448. NO line_account_id guard (literal).
// ---------------------------------------------------------------------------

export async function updateShippingAction(orderId: number, formData: FormData): Promise<void> {
  const { db } = await requireTenantPageContext();

  try {
    const shippingName = firstString(formData, 'shipping_name');
    const shippingPhone = firstString(formData, 'shipping_phone');
    const shippingAddress = firstString(formData, 'shipping_address');
    // NO `AND (line_account_id = ? OR line_account_id IS NULL)` guard — matches
    // shop/order-detail.php lines 440-441 exactly (no such clause in the PHP source).
    await sql`UPDATE transactions SET shipping_name=${shippingName}, shipping_phone=${shippingPhone}, shipping_address=${shippingAddress} WHERE id=${orderId}`.execute(
      db
    );
  } catch (e) {
    console.error('update_shipping error:', e);
  }

  redirect(`/shop/order-detail?id=${orderId}&updated=1`);
}

// ---------------------------------------------------------------------------
// reject_payment — PHP lines 450-507. NO top-level try/catch (literal).
// ---------------------------------------------------------------------------

export async function rejectPaymentAction(orderId: number, _formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const currentBotId = session.currentBotId ?? 1;

  await sql`UPDATE payment_slips SET status = 'rejected' WHERE transaction_id = ${orderId} AND status = 'pending'`.execute(db);

  // Send rejection Flex message (its own separate bubble — see ./_lib/orderStatusFlex.ts).
  await sendOrderRejectionFlex(db, currentBotId, orderId);

  redirect(`/shop/order-detail?id=${orderId}&rejected=1`);
}

// ---------------------------------------------------------------------------
// add_tracking — PHP lines 509-534. NO line_account_id guard (literal —
// PHP's own comment: "Update without line_account_id filter to ensure it works").
// ---------------------------------------------------------------------------

export async function addTrackingAction(orderId: number, formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const currentBotId = session.currentBotId ?? 1;

  try {
    const tracking = firstString(formData, 'tracking').trim();
    if (phpTruthyStr(tracking)) {
      // Update without line_account_id filter to ensure it works (PHP line 513 comment, preserved verbatim).
      await sql`UPDATE transactions SET shipping_tracking = ${tracking}, status = 'shipping' WHERE id = ${orderId}`.execute(db);

      // Send Flex notification with tracking.
      try {
        await sendOrderStatusFlex(db, currentBotId, orderId, 'shipping', tracking);
      } catch (e) {
        console.error('sendOrderStatusFlex error:', e);
      }
    }
  } catch (e) {
    console.error('add_tracking error:', e);
  }

  redirect(`/shop/order-detail?id=${orderId}&tracking_added=1`);
}
