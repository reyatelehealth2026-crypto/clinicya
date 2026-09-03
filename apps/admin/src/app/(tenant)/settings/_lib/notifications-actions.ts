'use server';

import { sql } from 'kysely';
import { redirect } from 'next/navigation';
import { requireTenantPageContext } from '../../users/_lib/session';
import { ODOO_EVENT_OPTIONS, resolveNotificationAccountId } from './notifications-queries';

/**
 * notifications-actions.ts — Server Actions for settings.php's `action ===
 * 'save_notifications'` (lines 701-766) and `action ===
 * 'test_odoo_liff_notification'` (lines 767-862) handlers, both reached via
 * notifications.php's SINGLE `<form method="POST">` (lines 116-441) — read
 * the full 442-line partial + these ~160 PHP lines in full before editing.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * CORRECTION carried over from this batch's brief (recorded here per the
 * brief's own instruction, not silently applied)
 * ═══════════════════════════════════════════════════════════════════════
 * The hand-off that spawned this batch initially pointed at
 * `save_telegram_notifications` as one of this tab's two POST branches.
 * That is WRONG, confirmed by reading settings.php in full:
 * `save_telegram_notifications` (settings.php lines 526-558) writes to the
 * UNRELATED `telegram_settings` table and ends with `$activeTab =
 * 'telegram'` (line 558) — it belongs to the commented-out, non-live
 * Telegram tab (`includes/settings/telegram.php`, not this batch's
 * `notifications.php`) and is correctly OUT of scope here, not ported. The
 * two branches that actually belong to notifications.php, confirmed by both
 * (a) their own `$activeTab = 'notifications'` (lines 765/861) and (b) the
 * `test_odoo_liff_notification` submit button being rendered literally
 * inside notifications.php's own Odoo-gated section (lines 349-387), are
 * `save_notifications` and `test_odoo_liff_notification` — both ported
 * below.
 *
 *   save_notifications (settings.php lines 701-766):
 *     try {
 *         $currentBotId = $_SESSION['current_bot_id'] ?? null;
 *         $accountId = (int) ($currentBotId ?: 0);
 *         $emailAddresses = trim($_POST['email_addresses'] ?? '');
 *         $notifyAdminUsers = isset($_POST['notify_admin_users']) ? implode(',', $_POST['notify_admin_users']) : '';
 *         $odooEvents = isset($_POST['odoo_liff_notify_events']) && is_array($_POST['odoo_liff_notify_events'])
 *             ? implode(',', array_map('trim', $_POST['odoo_liff_notify_events'])) : '';
 *         $data = [$accountId, isset($_POST['line_notify_enabled'])?1:0, ...14 more isset()?1:0/value fields...];
 *         INSERT INTO notification_settings (16 columns) VALUES (16 placeholders)
 *           ON DUPLICATE KEY UPDATE <15 non-PK columns = VALUES(...)>;
 *         $success = 'บันทึกการตั้งค่าการแจ้งเตือนสำเร็จ';
 *         $activityLogger->logData(...); // NOT reproduced, see below
 *     } catch (Exception $e) { $error = 'เกิดข้อผิดพลาด: ' . $e->getMessage(); }
 *     $activeTab = 'notifications';
 *
 *   NOTE the asymmetry vs. the read side (./notifications-queries.ts):
 *   `$notifyAdminUsers` here is `implode(',', $_POST['notify_admin_users'])`
 *   with NO `intval()`/trim applied per-element — PHP only `intval()`s on
 *   the READ path (`array_map('intval', explode(',', ...))`). This port
 *   preserves that asymmetry deliberately (see `saveNotificationsAction`
 *   below) rather than "fixing" it to intval on save too.
 *
 *   test_odoo_liff_notification (settings.php lines 767-862):
 *     try {
 *         $currentBotId = $_SESSION['current_bot_id'] ?? null;
 *         $accountId = (int) ($currentBotId ?: 0);
 *         $lineUserId = trim($_POST['test_line_user_id'] ?? '');
 *         $eventCode = trim($_POST['test_odoo_event'] ?? 'order.validated');
 *         $orderRef = trim($_POST['test_order_ref'] ?? 'SO-TEST-001');
 *         $customerName = trim($_POST['test_customer_name'] ?? 'ลูกค้าทดสอบ');
 *         if ($lineUserId === '') throw new Exception('กรุณาระบุ LINE User ID ที่ต้องการทดสอบส่ง');
 *         $eventLabels = [...8 codes...];
 *         if (!isset($eventLabels[$eventCode])) throw new Exception('สถานะทดสอบไม่ถูกต้อง');
 *         $channelAccessToken = SELECT channel_access_token FROM line_accounts WHERE id = $accountId LIMIT 1;
 *         if (empty) $channelAccessToken = SELECT channel_access_token FROM line_accounts WHERE is_default = 1 LIMIT 1;
 *         if (empty) throw new Exception('ไม่พบ Channel Access Token สำหรับส่งข้อความ');
 *         require_once 'classes/OdooFlexTemplates.php';
 *         $flexBubble = OdooFlexTemplates::odooStatusUpdate($eventCode, [...], $message, false);
 *         POST https://api.line.me/v2/bot/message/push  (Bearer $channelAccessToken)
 *         if ($curlError) throw new Exception('เกิดข้อผิดพลาดเครือข่าย: ' . $curlError);
 *         if ($httpCode !== 200) throw new Exception('LINE API ตอบกลับไม่สำเร็จ (' . $httpCode . '): ' . ($response ?: 'no response'));
 *         $success = 'ส่งข้อความทดสอบ Odoo → LIFF สำเร็จแล้ว';
 *     } catch (Exception $e) { $error = 'ทดสอบส่งแจ้งเตือนไม่สำเร็จ: ' . $e->getMessage(); }
 *     $activeTab = 'notifications';
 *
 * Both redirect back to `/settings?tab=notifications` with a
 * `?message=`/`?error=` search param carrying the exact Thai text PHP would
 * have rendered inline — same convention as ./welcome-actions.ts,
 * ./email-actions.ts, ./general-actions.ts. `redirect()` is called OUTSIDE
 * any try/catch for the same reason documented in those files (it throws a
 * Next-internal control-flow error that must not be caught as a real
 * failure).
 *
 * `save_notifications`'s success string is EXACTLY
 * 'บันทึกการตั้งค่าการแจ้งเตือนสำเร็จ' — no trailing "!" (contrast
 * welcome's 'บันทึกการตั้งค่าข้อความต้อนรับสำเร็จ!', which does have one).
 * Verified by reading settings.php line 755 character-for-character, not
 * assumed from the sibling actions' pattern.
 *
 * `test_odoo_liff_notification` has NO Odoo-kill-switch gate of its own —
 * `isOdooIntegrationEnabled()` only controls whether
 * ../_components/NotificationsTab.tsx RENDERS the test-send `<form>`
 * section (notifications.php lines 244-388's `<?php if
 * (ODOO_INTEGRATION_ENABLED): ?>`), not whether the `action ===
 * 'test_odoo_liff_notification'` handler itself runs (settings.php's PHP
 * switch has no such check — confirmed by reading the full handler). Ported
 * literally: `testOdooLiffNotificationAction` below adds NO
 * `isOdooIntegrationEnabled()` gate of its own, exactly matching PHP's
 * "form is hidden, action stays reachable if POSTed directly" shape.
 *
 * FLAGGED SCOPE CUT (same convention as ./email-actions.ts's nodemailer
 * note): `classes/OdooFlexTemplates.php::odooStatusUpdate()` (an 859-LOC
 * shared class building the full roadmap-timeline Flex bubble PHP actually
 * sends) is NOT ported here — it is Phase 6/8 territory owned by
 * mig-line/mig-worker, outside this batch's allowed paths
 * (`packages/line/**`, `packages/db/**` are off-limits here). Instead,
 * `buildMinimalTestFlexBubble()` below sends a minimal, honest test Flex
 * bubble (event label + code + the two test-only fields) via a plain
 * `fetch('https://api.line.me/v2/bot/message/push', ...)` call with Bearer
 * auth — same convention as
 * apps/admin/src/app/api/miniapp/checkout/order/_lib/notify.ts's
 * `sendReceiptMessage()` (no `curl`, no shared LINE SDK — none exists yet).
 * What IS preserved byte-exact regardless of the bubble's own visual
 * content: the HTTP-200 check, the success string
 * 'ส่งข้อความทดสอบ Odoo → LIFF สำเร็จแล้ว', and the three distinct pieces
 * of error text PHP produces — the network-error inner message
 * ('เกิดข้อผิดพลาดเครือข่าย: ' + message), the non-200 inner message
 * ('LINE API ตอบกลับไม่สำเร็จ (' + status + '): ' + body), and the outer
 * catch-all wrapper every thrown error (validation/event-code/token/network/
 * non-200 alike) gets funneled through ('ทดสอบส่งแจ้งเตือนไม่สำเร็จ: ' +
 * message) — exactly PHP's single outer `try { ... } catch (Exception $e) {
 * $error = 'ทดสอบส่งแจ้งเตือนไม่สำเร็จ: ' . $e->getMessage(); }` shape.
 *
 * Intentional gap (flagged, not silently dropped): PHP's
 * `$activityLogger->logData(ActivityLogger::ACTION_UPDATE, 'ตั้งค่าการแจ้งเตือน
 * (System)', ...)` audit write on the `save_notifications` path is NOT
 * reproduced — matches every other ported Phase 2 action (see
 * (tenant)/users/actions.ts's own note). `test_odoo_liff_notification` has
 * no audit write in PHP either (confirmed by reading the full handler).
 *
 * See ./notifications-queries.ts's module doc for the CONFIRMED FINDING
 * that `notification_settings` is missing the `UNIQUE KEY unique_account
 * (line_account_id)` PHP's own runtime DDL defines, on the committed tenant
 * template — meaning `ON DUPLICATE KEY UPDATE` below can only ever collide
 * on `id`, never on `line_account_id`, so every save in practice INSERTs a
 * new row rather than updating the prior one. A real pre-existing schema
 * drift (present in real PHP too, not introduced by this port), flagged
 * there in detail — not fixed here (`database/**` is out of scope).
 */

const SAVE_SUCCESS_MESSAGE = 'บันทึกการตั้งค่าการแจ้งเตือนสำเร็จ';
const TEST_SUCCESS_MESSAGE = 'ส่งข้อความทดสอบ Odoo → LIFF สำเร็จแล้ว';
const TEST_ERROR_PREFIX = 'ทดสอบส่งแจ้งเตือนไม่สำเร็จ: ';

/** `isset($_POST[name]) ? 1 : 0` — a checkbox absent from FormData means unchecked. */
function boolField(formData: FormData, name: string): 0 | 1 {
  return formData.get(name) !== null ? 1 : 0;
}

export async function saveNotificationsAction(formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const accountId = resolveNotificationAccountId(session.currentBotId);

  const emailAddresses = String(formData.get('email_addresses') ?? '').trim();
  // PHP: implode(',', $_POST['notify_admin_users']) — NOT intval()'d on this (save) path, see module doc.
  const notifyAdminUsers = formData.getAll('notify_admin_users[]').map(String).join(',');
  // PHP: implode(',', array_map('trim', $_POST['odoo_liff_notify_events']))
  const odooEvents = formData
    .getAll('odoo_liff_notify_events[]')
    .map((v) => String(v).trim())
    .join(',');

  let errorMessage: string | null = null;
  try {
    await sql`
      INSERT INTO notification_settings (
        line_account_id, line_notify_enabled, line_notify_new_order, line_notify_payment,
        line_notify_urgent, line_notify_appointment, line_notify_low_stock,
        email_enabled, email_addresses, email_notify_urgent, email_notify_daily_report, email_notify_low_stock,
        telegram_enabled, odoo_liff_notify_enabled, odoo_liff_notify_events, notify_admin_users
      ) VALUES (
        ${accountId}, ${boolField(formData, 'line_notify_enabled')}, ${boolField(formData, 'line_notify_new_order')}, ${boolField(formData, 'line_notify_payment')},
        ${boolField(formData, 'line_notify_urgent')}, ${boolField(formData, 'line_notify_appointment')}, ${boolField(formData, 'line_notify_low_stock')},
        ${boolField(formData, 'email_enabled')}, ${emailAddresses}, ${boolField(formData, 'email_notify_urgent')}, ${boolField(formData, 'email_notify_daily_report')}, ${boolField(formData, 'email_notify_low_stock')},
        ${boolField(formData, 'telegram_enabled')}, ${boolField(formData, 'odoo_liff_notify_enabled')}, ${odooEvents}, ${notifyAdminUsers}
      )
      ON DUPLICATE KEY UPDATE
        line_notify_enabled = VALUES(line_notify_enabled),
        line_notify_new_order = VALUES(line_notify_new_order),
        line_notify_payment = VALUES(line_notify_payment),
        line_notify_urgent = VALUES(line_notify_urgent),
        line_notify_appointment = VALUES(line_notify_appointment),
        line_notify_low_stock = VALUES(line_notify_low_stock),
        email_enabled = VALUES(email_enabled),
        email_addresses = VALUES(email_addresses),
        email_notify_urgent = VALUES(email_notify_urgent),
        email_notify_daily_report = VALUES(email_notify_daily_report),
        email_notify_low_stock = VALUES(email_notify_low_stock),
        telegram_enabled = VALUES(telegram_enabled),
        odoo_liff_notify_enabled = VALUES(odoo_liff_notify_enabled),
        odoo_liff_notify_events = VALUES(odoo_liff_notify_events),
        notify_admin_users = VALUES(notify_admin_users)
    `.execute(db);
  } catch (err) {
    errorMessage = err instanceof Error ? err.message : String(err);
  }

  if (errorMessage !== null) {
    redirect(`/settings?tab=notifications&error=${encodeURIComponent(`เกิดข้อผิดพลาด: ${errorMessage}`)}`);
  }

  redirect(`/settings?tab=notifications&message=${encodeURIComponent(SAVE_SUCCESS_MESSAGE)}`);
}

/** `trim($_POST[name] ?? fallback)` — PHP's `??` only substitutes when the field is absent (null), NOT when it is present-but-empty. */
function trimOrDefault(field: FormDataEntryValue | null, fallback: string): string {
  return (field === null ? fallback : String(field)).trim();
}

const EVENT_LABELS: Record<string, string> = Object.fromEntries(ODOO_EVENT_OPTIONS.map((o) => [o.code, o.label]));

/**
 * Minimal, honest replacement for `OdooFlexTemplates::odooStatusUpdate()`
 * (NOT ported — see module doc's FLAGGED SCOPE CUT). Not a byte-exact match
 * to the real roadmap-timeline bubble PHP builds; only the surrounding
 * success/error/HTTP-status handling is byte-exact.
 */
function buildMinimalTestFlexBubble(eventLabel: string, eventCode: string, orderRef: string, customerName: string): Record<string, unknown> {
  return {
    type: 'bubble',
    body: {
      type: 'box',
      layout: 'vertical',
      contents: [
        { type: 'text', text: '🧪 ทดสอบแจ้งเตือน Odoo → LIFF', weight: 'bold', size: 'md', color: '#059669' },
        { type: 'text', text: eventLabel, size: 'sm', color: '#111111', margin: 'md' },
        { type: 'text', text: eventCode, size: 'xs', color: '#9ca3af' },
        { type: 'separator', margin: 'md' },
        {
          type: 'box',
          layout: 'horizontal',
          margin: 'md',
          contents: [
            { type: 'text', text: 'เลขที่ออเดอร์', size: 'xs', color: '#6b7280', flex: 2 },
            { type: 'text', text: orderRef, size: 'xs', color: '#111111', flex: 3, wrap: true },
          ],
        },
        {
          type: 'box',
          layout: 'horizontal',
          margin: 'sm',
          contents: [
            { type: 'text', text: 'ลูกค้า', size: 'xs', color: '#6b7280', flex: 2 },
            { type: 'text', text: customerName, size: 'xs', color: '#111111', flex: 3, wrap: true },
          ],
        },
      ],
    },
  };
}

export async function testOdooLiffNotificationAction(formData: FormData): Promise<void> {
  const { db, session } = await requireTenantPageContext();
  const accountId = resolveNotificationAccountId(session.currentBotId);

  let errorMessage: string | null = null;

  try {
    const lineUserId = trimOrDefault(formData.get('test_line_user_id'), '');
    const eventCode = trimOrDefault(formData.get('test_odoo_event'), 'order.validated');
    const orderRef = trimOrDefault(formData.get('test_order_ref'), 'SO-TEST-001');
    const customerName = trimOrDefault(formData.get('test_customer_name'), 'ลูกค้าทดสอบ');

    if (lineUserId === '') {
      throw new Error('กรุณาระบุ LINE User ID ที่ต้องการทดสอบส่ง');
    }

    const eventLabel = EVENT_LABELS[eventCode];
    if (eventLabel === undefined) {
      throw new Error('สถานะทดสอบไม่ถูกต้อง');
    }

    const byAccount = await sql<{ channel_access_token: string | null }>`
      SELECT channel_access_token FROM line_accounts WHERE id = ${accountId} LIMIT 1
    `.execute(db);
    let channelAccessToken = (byAccount.rows[0]?.channel_access_token ?? '').trim();

    if (channelAccessToken === '') {
      const byDefault = await sql<{ channel_access_token: string | null }>`
        SELECT channel_access_token FROM line_accounts WHERE is_default = 1 LIMIT 1
      `.execute(db);
      channelAccessToken = (byDefault.rows[0]?.channel_access_token ?? '').trim();
    }

    if (channelAccessToken === '') {
      throw new Error('ไม่พบ Channel Access Token สำหรับส่งข้อความ');
    }

    const flexBubble = buildMinimalTestFlexBubble(eventLabel, eventCode, orderRef, customerName);
    const payload = {
      to: lineUserId,
      messages: [
        {
          type: 'flex',
          altText: `🧪 ทดสอบแจ้งเตือน Odoo ${eventLabel}`,
          contents: flexBubble,
        },
      ],
    };

    let response: Response;
    try {
      response = await fetch('https://api.line.me/v2/bot/message/push', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${channelAccessToken}`,
        },
        body: JSON.stringify(payload),
      });
    } catch (networkErr) {
      const msg = networkErr instanceof Error ? networkErr.message : String(networkErr);
      throw new Error(`เกิดข้อผิดพลาดเครือข่าย: ${msg}`);
    }

    if (response.status !== 200) {
      const bodyText = await response.text().catch(() => '');
      throw new Error(`LINE API ตอบกลับไม่สำเร็จ (${response.status}): ${bodyText || 'no response'}`);
    }
  } catch (err) {
    const msg = err instanceof Error ? err.message : String(err);
    errorMessage = `${TEST_ERROR_PREFIX}${msg}`;
  }

  if (errorMessage !== null) {
    redirect(`/settings?tab=notifications&error=${encodeURIComponent(errorMessage)}`);
  }

  redirect(`/settings?tab=notifications&message=${encodeURIComponent(TEST_SUCCESS_MESSAGE)}`);
}
