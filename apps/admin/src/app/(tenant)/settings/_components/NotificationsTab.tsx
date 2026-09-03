import type { Kysely } from 'kysely';
import type { ReactNode } from 'react';
import type { TenantDB } from '@reya/db';
import { isOdooIntegrationEnabled } from '../../users/_lib/odoo';
import {
  getNotificationSettings,
  getNotificationAdminUsers,
  ODOO_EVENT_OPTIONS,
  type NotificationSettings,
  type NotificationAdminUser,
} from '../_lib/notifications-queries';
import { saveNotificationsAction, testOdooLiffNotificationAction } from '../_lib/notifications-actions';

/**
 * NotificationsTab — Server Component port of includes/settings/notifications.php
 * (442 LOC). notifications.php has ZERO inline `<script>` (confirmed by
 * reading the full source) — same as ./EmailTab.tsx's precedent — so this
 * needs no 'use client' island; the WHOLE tab is one `<form
 * action={saveNotificationsAction}>` (matching notifications.php's own
 * single `<form method="POST">`, lines 116-441), with the Odoo test-send
 * button routed to a DIFFERENT Server Action via React's per-button
 * `formAction={...}` override — same established convention as
 * ./PlatformFacebookForm.tsx / ./PlatformTikTokForm.tsx (PHP's single
 * `<form>` with multiple `name="action" value="..."` submit buttons, ported
 * as one `<form>` with per-button `formAction` overrides instead of relying
 * on "last name=value wins" browser/PHP behavior).
 *
 * `name="action" value="save_notifications"` / `value="test_odoo_liff_notification"`
 * attributes are kept on both submit buttons verbatim (harmless — neither
 * Server Action reads a `action` field from its `FormData`) purely to match
 * the literal PHP markup this batch's brief calls for.
 *
 * Odoo gate: notifications.php's `defined('ODOO_INTEGRATION_ENABLED') &&
 * ODOO_INTEGRATION_ENABLED === true` (line 244, closed line 388) wraps THREE
 * consecutive blocks — the Odoo→LIFF toggle+event-checklist card, the static
 * "Odoo Notification Preferences (NEW)" info card, and the Odoo test-send
 * form — computed here via (tenant)/users/_lib/odoo.ts's
 * `isOdooIntegrationEnabled()` (read-only import, not modified), same
 * convention as ./GeneralTab.tsx. All three render together or not at all,
 * matching the single `<?php if (...): ?> ... <?php endif; ?>` PHP wraps
 * around all three.
 *
 * Visual port of the original `<style>` block (notifications.php lines
 * 93-106) to Tailwind utility classes — same convention already established
 * by ./EmailTab.tsx/./WelcomeTab.tsx: `.notify-setting-card` -> rounded-2xl
 * white card w/ border + hover shadow; `.notify-toggle-switch`/
 * `.notify-toggle-slider` -> a `peer`+`peer-checked:` pill toggle (the exact
 * `sr-only peer` + `peer-checked:` shape ./WelcomeTab.tsx's own enable-toggle
 * already uses, extended with the emerald gradient PHP's CSS defines: `background:
 * linear-gradient(135deg, #10b981, #059669)`); `.notify-item` -> a
 * rounded gray-50 row. The original `notify-*` class names are kept
 * alongside the Tailwind utilities as harmless markers (no `<style>` tag,
 * globals.css untouched).
 */
export interface NotificationsTabProps {
  db: Kysely<TenantDB>;
  currentBotId: number | null;
}

const CARD = 'notify-setting-card rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-shadow hover:shadow-lg';
const ITEM = 'notify-item mb-2 flex items-center gap-3 rounded-lg bg-gray-50 px-4 py-3 hover:bg-gray-100 cursor-pointer';
const INPUT =
  'notify-input-field w-full rounded-lg border border-gray-200 px-4 py-3 text-sm transition-all focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/20';

function ChannelIcon({ className, children }: { className: string; children: ReactNode }) {
  return <div className={`notify-channel-icon flex h-12 w-12 items-center justify-center rounded-xl ${className}`}>{children}</div>;
}

function ToggleSwitch({ name, defaultChecked }: { name: string; defaultChecked: boolean }) {
  return (
    <label className="notify-toggle-switch relative inline-flex h-7 w-[52px] shrink-0 cursor-pointer items-center">
      <input type="checkbox" name={name} defaultChecked={defaultChecked} className="peer sr-only" />
      <span
        className="notify-toggle-slider peer absolute inset-0 rounded-full bg-gray-200 transition-all duration-300
        after:absolute after:bottom-[3px] after:left-[3px] after:h-[22px] after:w-[22px] after:rounded-full after:bg-white after:shadow after:transition-all after:duration-300
        peer-checked:bg-gradient-to-br peer-checked:from-emerald-500 peer-checked:to-emerald-600 peer-checked:after:translate-x-[24px]"
      />
    </label>
  );
}

function CheckboxItem({
  name,
  value,
  defaultChecked,
  disabled,
  accent,
  title,
  description,
}: {
  name: string;
  value?: string | number;
  defaultChecked: boolean;
  disabled?: boolean;
  accent: string;
  title: string;
  description: string;
}) {
  return (
    <label className={`${ITEM} ${disabled ? 'opacity-50' : ''}`}>
      <input
        type="checkbox"
        name={name}
        value={value}
        defaultChecked={defaultChecked}
        disabled={disabled}
        className={`mr-3 h-4 w-4 ${accent}`}
      />
      <div className="flex-1">
        <p className="font-medium">{title}</p>
        <p className="text-sm text-gray-500">{description}</p>
      </div>
    </label>
  );
}

function LineNotificationsCard({ settings }: { settings: NotificationSettings }) {
  return (
    <div className={CARD}>
      <div className="mb-4 flex items-center justify-between">
        <h3 className="flex items-center gap-2 text-lg font-semibold text-gray-800">
          <ChannelIcon className="bg-green-100">
            <i className="fab fa-line text-xl text-green-500" aria-hidden="true" />
          </ChannelIcon>
          LINE Notification
        </h3>
        <ToggleSwitch name="line_notify_enabled" defaultChecked={settings.lineNotifyEnabled} />
      </div>

      <div className="space-y-2">
        <CheckboxItem
          name="line_notify_new_order"
          defaultChecked={settings.lineNotifyNewOrder}
          accent="text-green-600"
          title="🛒 ออเดอร์ใหม่"
          description="แจ้งเตือนเมื่อมีคำสั่งซื้อใหม่"
        />
        <CheckboxItem
          name="line_notify_payment"
          defaultChecked={settings.lineNotifyPayment}
          accent="text-green-600"
          title="💳 การชำระเงิน"
          description="แจ้งเตือนเมื่อมีการแนบสลิป/ชำระเงิน"
        />
        <CheckboxItem
          name="line_notify_urgent"
          defaultChecked={settings.lineNotifyUrgent}
          accent="text-green-600"
          title="🚨 เคสฉุกเฉิน (Red Flag)"
          description="แจ้งเตือนเมื่อพบอาการฉุกเฉิน"
        />
        <CheckboxItem
          name="line_notify_appointment"
          defaultChecked={settings.lineNotifyAppointment}
          accent="text-green-600"
          title="📅 นัดหมายใหม่"
          description="แจ้งเตือนเมื่อมีการจองนัดหมาย"
        />
        <CheckboxItem
          name="line_notify_low_stock"
          defaultChecked={settings.lineNotifyLowStock}
          accent="text-green-600"
          title="📦 สินค้าใกล้หมด"
          description="แจ้งเตือนเมื่อสต็อกต่ำกว่าที่กำหนด"
        />
      </div>
    </div>
  );
}

function EmailNotificationsCard({ settings }: { settings: NotificationSettings }) {
  return (
    <div className={CARD}>
      <div className="mb-4 flex items-center justify-between">
        <h3 className="flex items-center gap-2 text-lg font-semibold text-gray-800">
          <ChannelIcon className="bg-blue-100">
            <i className="fas fa-envelope text-xl text-blue-500" aria-hidden="true" />
          </ChannelIcon>
          Email Notification
        </h3>
        <ToggleSwitch name="email_enabled" defaultChecked={settings.emailEnabled} />
      </div>

      <div className="mb-4">
        <label className="mb-2 block text-sm font-medium text-gray-600">Email ผู้รับแจ้งเตือน</label>
        <textarea
          name="email_addresses"
          rows={2}
          defaultValue={settings.emailAddresses}
          placeholder={'email1@example.com\nemail2@example.com'}
          className={INPUT}
        />
        <p className="mt-1 text-xs text-gray-400">ใส่ Email หลายรายการได้ (บรรทัดละ 1 Email)</p>
      </div>

      <div className="space-y-2">
        <CheckboxItem
          name="email_notify_urgent"
          defaultChecked={settings.emailNotifyUrgent}
          accent="text-blue-600"
          title="🚨 เคสฉุกเฉิน (Red Flag)"
          description="ส่ง Email เมื่อพบอาการฉุกเฉิน"
        />
        <CheckboxItem
          name="email_notify_daily_report"
          defaultChecked={settings.emailNotifyDailyReport}
          accent="text-blue-600"
          title="📊 รายงานประจำวัน"
          description="ส่งสรุปยอดขายและกิจกรรมทุกวัน"
        />
        <CheckboxItem
          name="email_notify_low_stock"
          defaultChecked={settings.emailNotifyLowStock}
          accent="text-blue-600"
          title="📦 สินค้าใกล้หมด"
          description="ส่ง Email เมื่อสต็อกต่ำกว่าที่กำหนด"
        />
      </div>
    </div>
  );
}

function TelegramNotificationsCard({ settings }: { settings: NotificationSettings }) {
  return (
    <div className={CARD}>
      <div className="mb-4 flex items-center justify-between">
        <h3 className="flex items-center gap-2 text-lg font-semibold text-gray-800">
          <ChannelIcon className="bg-sky-100">
            <i className="fab fa-telegram text-xl text-sky-500" aria-hidden="true" />
          </ChannelIcon>
          Telegram Notification
        </h3>
        <ToggleSwitch name="telegram_enabled" defaultChecked={settings.telegramEnabled} />
      </div>

      <p className="mb-4 text-sm text-gray-500">ตั้งค่า Telegram Bot ได้ที่แท็บ &quot;Telegram&quot;</p>
    </div>
  );
}

function OdooLiffEventsCard({ settings }: { settings: NotificationSettings }) {
  return (
    <div className={CARD}>
      <div className="mb-4 flex items-center justify-between">
        <h3 className="flex items-center gap-2 text-lg font-semibold text-gray-800">
          <ChannelIcon className="bg-emerald-100">
            <i className="fas fa-paper-plane text-xl text-emerald-600" aria-hidden="true" />
          </ChannelIcon>
          Odoo → LIFF Notification
        </h3>
        <ToggleSwitch name="odoo_liff_notify_enabled" defaultChecked={settings.odooLiffNotifyEnabled} />
      </div>

      <p className="mb-4 text-sm text-gray-500">เลือกสถานะที่ต้องการส่งแจ้งเตือนไปยังผู้ใช้ LIFF</p>

      <div className="grid grid-cols-1 gap-2 md:grid-cols-2">
        {ODOO_EVENT_OPTIONS.map((option) => (
          <label key={option.code} className={ITEM}>
            <input
              type="checkbox"
              name="odoo_liff_notify_events[]"
              value={option.code}
              defaultChecked={settings.odooLiffNotifyEvents.includes(option.code)}
              className="mr-3 h-4 w-4 text-emerald-600"
            />
            <div className="flex-1">
              <p className="font-medium">{option.label}</p>
              <p className="text-xs text-gray-500">{option.code}</p>
            </div>
          </label>
        ))}
      </div>
    </div>
  );
}

/** Static, read-only content — verbatim Thai copy from notifications.php lines 276-347. Not a form field. */
function OdooPreferencesInfoCard() {
  return (
    <div className="notify-setting-card rounded-2xl border-2 border-emerald-200 bg-white p-6">
      <h3 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-800">
        <ChannelIcon className="bg-gradient-to-br from-emerald-500 to-teal-500">
          <i className="fas fa-cog text-xl text-white" aria-hidden="true" />
        </ChannelIcon>
        การตั้งค่าการแจ้งเตือน Odoo (ขั้นสูง)
        <span className="ml-auto rounded-full bg-emerald-100 px-3 py-1 text-xs text-emerald-700">NEW</span>
      </h3>

      <div className="mb-4 rounded-lg bg-gradient-to-r from-emerald-50 to-teal-50 p-4">
        <div className="flex items-start gap-3">
          <i className="fas fa-info-circle mt-1 text-emerald-600" aria-hidden="true" />
          <div className="text-sm text-gray-700">
            <p className="mb-1 font-semibold">🎯 Roadmap Batching</p>
            <p>
              ระบบจะรวมการแจ้งเตือนหลายสถานะเป็น timeline เดียว เมื่อถึงสถานะ <strong>order.packed (แพ็คเสร็จ)</strong>
            </p>
            <p className="mt-2 text-xs text-gray-600">
              ตัวอย่าง: picker_assigned → picking → picked → packing → <strong>packed</strong> = ส่ง 1 ข้อความแทน 5 ข้อความ
            </p>
          </div>
        </div>
      </div>

      <div className="space-y-4">
        <div className={ITEM}>
          <div className="flex-1">
            <p className="font-medium text-gray-800">📊 สถานะระบบ</p>
            <p className="mt-1 text-sm text-gray-500">
              <a href="/tests/check-system.php" target="_blank" rel="noreferrer" className="text-emerald-600 hover:underline">
                <i className="fas fa-external-link-alt mr-1" aria-hidden="true" />
                ตรวจสอบสถานะระบบ
              </a>
            </p>
          </div>
        </div>

        <div className={ITEM}>
          <div className="flex-1">
            <p className="font-medium text-gray-800">🔧 Worker Status</p>
            <p className="mt-1 text-sm text-gray-500">
              <a href="/api/notification-queue-status.php" target="_blank" rel="noreferrer" className="text-emerald-600 hover:underline">
                <i className="fas fa-external-link-alt mr-1" aria-hidden="true" />
                ดูสถานะ Queue &amp; Worker
              </a>
            </p>
          </div>
        </div>

        <div className={ITEM}>
          <div className="flex-1">
            <p className="font-medium text-gray-800">📝 Notification Logs</p>
            <p className="mt-1 text-sm text-gray-500">
              ดูประวัติการส่งแจ้งเตือนทั้งหมดใน database: <code className="rounded bg-gray-100 px-2 py-1 text-xs">odoo_notification_log</code>
            </p>
          </div>
        </div>

        <div className={ITEM}>
          <div className="flex-1">
            <p className="font-medium text-gray-800">⚙️ User Preferences</p>
            <p className="mt-1 text-sm text-gray-500">ผู้ใช้สามารถตั้งค่าการแจ้งเตือนส่วนตัวได้ที่ LIFF Notification Settings</p>
          </div>
        </div>
      </div>

      <div className="mt-4 rounded-lg border border-yellow-200 bg-yellow-50 p-4">
        <p className="text-sm text-yellow-800">
          <i className="fas fa-exclamation-triangle mr-2" aria-hidden="true" />
          <strong>หมายเหตุ:</strong> ระบบ Roadmap Batching ทำงานอัตโนมัติผ่าน NotificationRouter และ Worker
        </p>
      </div>
    </div>
  );
}

function OdooTestSendCard() {
  return (
    <div className={CARD}>
      <h3 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-800">
        <ChannelIcon className="bg-indigo-100">
          <i className="fas fa-vial text-xl text-indigo-600" aria-hidden="true" />
        </ChannelIcon>
        ทดสอบส่งแจ้งเตือน Odoo → LIFF
      </h3>

      <div className="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label className="mb-2 block text-sm font-medium text-gray-600">LINE User ID ปลายทาง</label>
          <input type="text" name="test_line_user_id" placeholder="Uxxxxxxxxxxxxxxxxxxxxxxxxxxxx" className={INPUT} />
        </div>
        <div>
          <label className="mb-2 block text-sm font-medium text-gray-600">สถานะที่ต้องการทดสอบ</label>
          <select name="test_odoo_event" className={INPUT} defaultValue={ODOO_EVENT_OPTIONS[0]?.code}>
            {ODOO_EVENT_OPTIONS.map((option) => (
              <option key={option.code} value={option.code}>
                {option.label} ({option.code})
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label className="mb-2 block text-sm font-medium text-gray-600">เลขที่ออเดอร์ (ตัวอย่าง)</label>
          <input type="text" name="test_order_ref" placeholder="SO-TEST-001" className={INPUT} />
        </div>
        <div>
          <label className="mb-2 block text-sm font-medium text-gray-600">ชื่อผู้รับ (ตัวอย่าง)</label>
          <input type="text" name="test_customer_name" placeholder="ลูกค้าทดสอบ" className={INPUT} />
        </div>
      </div>

      <button
        type="submit"
        name="action"
        value="test_odoo_liff_notification"
        formAction={testOdooLiffNotificationAction}
        formNoValidate
        className="rounded-lg bg-indigo-600 px-4 py-2 text-white hover:bg-indigo-700"
      >
        <i className="fas fa-paper-plane mr-2" aria-hidden="true" />
        ส่งข้อความทดสอบ
      </button>
    </div>
  );
}

function NotificationRecipientsCard({ settings, adminUsers }: { settings: NotificationSettings; adminUsers: NotificationAdminUser[] }) {
  return (
    <div className={CARD}>
      <h3 className="mb-4 flex items-center gap-2 text-lg font-semibold text-gray-800">
        <ChannelIcon className="bg-purple-100">
          <i className="fas fa-users text-xl text-purple-500" aria-hidden="true" />
        </ChannelIcon>
        ผู้รับแจ้งเตือน LINE
      </h3>

      <p className="mb-4 text-sm text-gray-500">เลือกผู้ใช้ที่จะได้รับแจ้งเตือนผ่าน LINE (ต้องมี LINE User ID)</p>

      <div className="max-h-64 space-y-2 overflow-y-auto">
        {adminUsers.map((user) => {
          const hasLineId = Boolean(user.line_user_id);
          return (
            <label key={user.id} className={`${ITEM} ${!hasLineId ? 'opacity-50' : ''}`}>
              <input
                type="checkbox"
                name="notify_admin_users[]"
                value={user.id}
                defaultChecked={settings.notifyAdminUsers.includes(user.id)}
                disabled={!hasLineId}
                className="mr-3 h-4 w-4 text-purple-600"
              />
              <div className="flex-1">
                <p className="font-medium">{user.username}</p>
                <p className="text-xs text-gray-500">
                  {user.role}
                  {user.email ? ` • ${user.email}` : null}
                  {!hasLineId ? <span className="text-red-500"> • ไม่มี LINE User ID</span> : null}
                </p>
              </div>
            </label>
          );
        })}

        {adminUsers.length === 0 ? <p className="py-4 text-center text-gray-400">ไม่พบผู้ใช้งาน</p> : null}
      </div>
    </div>
  );
}

export async function NotificationsTab({ db, currentBotId }: NotificationsTabProps) {
  const [settings, adminUsers] = await Promise.all([getNotificationSettings(db, currentBotId), getNotificationAdminUsers(db)]);
  const odooEnabled = isOdooIntegrationEnabled();

  return (
    <div className="max-w-5xl mx-auto">
      <div className="mb-8">
        <h2 className="text-xl font-bold text-gray-800">
          <i className="fas fa-bell mr-2 text-yellow-500" aria-hidden="true" />
          ตั้งค่าการแจ้งเตือน
        </h2>
        <p className="mt-1 text-gray-500">จัดการช่องทางและประเภทการแจ้งเตือนทั้งหมด</p>
      </div>

      <form action={saveNotificationsAction}>
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <div className="space-y-6 lg:col-span-2">
            <LineNotificationsCard settings={settings} />
            <EmailNotificationsCard settings={settings} />
            <TelegramNotificationsCard settings={settings} />

            {odooEnabled ? (
              <>
                <OdooLiffEventsCard settings={settings} />
                <OdooPreferencesInfoCard />
                <OdooTestSendCard />
              </>
            ) : null}

            <NotificationRecipientsCard settings={settings} adminUsers={adminUsers} />
          </div>

          <div className="space-y-6">
            <div className={CARD}>
              <button
                type="submit"
                name="action"
                value="save_notifications"
                className="w-full rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 py-3 font-semibold text-white transition-all hover:opacity-90"
              >
                <i className="fas fa-save mr-2" aria-hidden="true" />
                บันทึกการตั้งค่า
              </button>
            </div>
          </div>
        </div>
      </form>
    </div>
  );
}
