import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getEmailSettings } from '../_lib/email-queries';
import { saveEmailSettingsAction, sendTestEmailAction } from '../_lib/email-actions';

/**
 * EmailTab — Server Component port of includes/settings/email.php (132
 * lines): SMTP settings form + a separate "send test email" form. PHP's
 * partial has NO inline `<script>` at all (confirmed by reading the full
 * source) — every field is a plain server-rendered `<input>`/`<select>`
 * with no client-side toggle/preview logic, so unlike WelcomeTab this needs
 * no 'use client' island; both `<form>`s bind their Server Action directly
 * (same convention (tenant)/line-groups/_components/LineGroupRow.tsx
 * established for a plain, non-interactive `<form action={...}>`).
 *
 * NOT LIVE via root /settings.php today (see settings/page.tsx's module doc
 * — `email` is commented out of that file's `$tabs` whitelist, so
 * `getActiveTab()` never resolves to it in production) — built anyway per
 * this batch's brief ("it's real, working, soon-to-be-re-enabled code"). No
 * two-sided PHP-vs-Next parity check is expected for this tab.
 */
export interface EmailTabProps {
  db: Kysely<TenantDB>;
}

export async function EmailTab({ db }: EmailTabProps) {
  const settings = await getEmailSettings(db);

  return (
    <div className="max-w-3xl mx-auto">
      <div className="mb-8">
        <h2 className="text-xl font-bold text-gray-800">
          <i className="fas fa-envelope text-blue-500 mr-2" aria-hidden="true" />
          ตั้งค่า Email/SMTP
        </h2>
        <p className="text-gray-500 mt-1">ตั้งค่า SMTP server สำหรับส่ง Email แจ้งเตือน</p>
      </div>

      <form action={saveEmailSettingsAction}>
        <div className="email-setting-card p-6 mb-6 bg-white border border-gray-200 rounded-2xl">
          <h3 className="text-lg font-semibold text-gray-800 mb-4">
            <i className="fas fa-server text-blue-500 mr-2" aria-hidden="true" />
            SMTP Server
          </h3>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-600 mb-2">SMTP Host</label>
              <input type="text" name="smtp_host" defaultValue={settings.smtpHost} placeholder="smtp.gmail.com" className="email-input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-600 mb-2">SMTP Port</label>
              <input type="number" name="smtp_port" defaultValue={settings.smtpPort} placeholder="587" className="email-input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-600 mb-2">SMTP Username</label>
              <input type="text" name="smtp_user" defaultValue={settings.smtpUser} placeholder="your@email.com" className="email-input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-600 mb-2">SMTP Password</label>
              <input type="password" name="smtp_pass" defaultValue={settings.smtpPass} placeholder="••••••••" className="email-input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-600 mb-2">Security</label>
              <select name="smtp_secure" defaultValue={settings.smtpSecure} className="email-input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm">
                <option value="tls">TLS (Port 587)</option>
                <option value="ssl">SSL (Port 465)</option>
                <option value="none">None (Port 25)</option>
              </select>
            </div>
          </div>
        </div>

        <div className="email-setting-card p-6 mb-6 bg-white border border-gray-200 rounded-2xl">
          <h3 className="text-lg font-semibold text-gray-800 mb-4">
            <i className="fas fa-user text-green-500 mr-2" aria-hidden="true" />
            ผู้ส่ง (From)
          </h3>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-600 mb-2">From Email</label>
              <input type="email" name="from_email" defaultValue={settings.fromEmail} placeholder="noreply@yourdomain.com" className="email-input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm" />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-600 mb-2">From Name</label>
              <input type="text" name="from_name" defaultValue={settings.fromName} placeholder="Notification" className="email-input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm" />
            </div>
          </div>
        </div>

        <div className="flex gap-4">
          <button type="submit" className="flex-1 py-3 bg-gradient-to-r from-blue-500 to-blue-600 text-white rounded-xl font-semibold hover:opacity-90">
            <i className="fas fa-save mr-2" aria-hidden="true" />
            บันทึกการตั้งค่า
          </button>
        </div>
      </form>

      <div className="email-setting-card p-6 mt-6 bg-white border border-gray-200 rounded-2xl">
        <h3 className="text-lg font-semibold text-gray-800 mb-4">
          <i className="fas fa-paper-plane text-purple-500 mr-2" aria-hidden="true" />
          ทดสอบส่ง Email
        </h3>

        <form action={sendTestEmailAction} className="flex gap-2">
          <input type="email" name="test_email" required placeholder="test@example.com" className="email-input-field flex-1 px-4 py-3 border border-gray-200 rounded-xl text-sm" />
          <button type="submit" className="px-6 py-3 bg-purple-500 text-white rounded-xl font-semibold hover:bg-purple-600">
            <i className="fas fa-paper-plane mr-2" aria-hidden="true" />
            ทดสอบ
          </button>
        </form>
      </div>

      <div className="email-setting-card p-6 mt-6 bg-blue-50 border border-blue-200 rounded-2xl">
        <h4 className="font-semibold text-blue-800 mb-3">
          <i className="fas fa-info-circle mr-2" aria-hidden="true" />
          วิธีตั้งค่า SMTP
        </h4>
        <div className="text-sm text-blue-700 space-y-2">
          <p>
            <strong>Gmail:</strong> smtp.gmail.com, Port 587, TLS, ใช้ App Password
          </p>
          <p>
            <strong>Outlook:</strong> smtp.office365.com, Port 587, TLS
          </p>
          <p>
            <strong>Plesk:</strong> mail.yourdomain.com, Port 587, TLS
          </p>
          <p className="text-xs text-blue-600 mt-3">💡 ถ้าไม่ตั้งค่า SMTP ระบบจะใช้ PHP mail() ซึ่งอาจไม่ทำงานบางโฮสติ้ง</p>
        </div>
      </div>
    </div>
  );
}
