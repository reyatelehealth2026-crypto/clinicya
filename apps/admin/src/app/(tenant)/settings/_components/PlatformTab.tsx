import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import {
  getFacebookAccounts,
  getTiktokAccounts,
  getFacebookWebhookUrl,
  getTiktokWebhookUrl,
  mapFacebookAccountRow,
  mapTiktokAccountRow,
} from '../_lib/platform-queries';
import { PlatformFacebookForm } from './PlatformFacebookForm';
import { PlatformTikTokForm } from './PlatformTikTokForm';

/**
 * PlatformTab — Server Component port of includes/settings/platform.php
 * (357 LOC): "การเชื่อมต่อแพลตฟอร์ม" — Facebook Messenger page connections
 * (`facebook_accounts`) and TikTok Shop connections (`tiktok_shop_accounts`)
 * — CRUD + live connectivity test for each. `await`-invoked from page.tsx
 * like ./ConsentTab.tsx (a plain read + render, no Server/Client boundary
 * issue at this level — only the per-account forms need to be Client
 * Components, see ./PlatformFacebookForm.tsx/./PlatformTikTokForm.tsx).
 *
 * Reads both tables directly via ../_lib/platform-queries.ts (platform.php's
 * exact `SELECT * FROM {facebook_accounts,tiktok_shop_accounts} ORDER BY id
 * DESC` — see that file's module doc for why the page-load `CREATE TABLE IF
 * NOT EXISTS` guard is NOT ported).
 *
 * Status badge — platform.php's own `$platformStatusBadge` closure (lines
 * 68-72) is a tiny reusable snippet; inlined here as a local component
 * rather than a shared export (only used twice, in this one file).
 */
export interface PlatformTabProps {
  db: Kysely<TenantDB>;
}

function StatusBadge({ active }: { active: boolean }) {
  return active ? (
    <span className="px-2 py-0.5 text-xs font-medium rounded-full bg-emerald-100 text-emerald-700">เชื่อมต่ออยู่</span>
  ) : (
    <span className="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-500">ปิดใช้งาน</span>
  );
}

export async function PlatformTab({ db }: PlatformTabProps) {
  const [facebookRows, tiktokRows] = await Promise.all([getFacebookAccounts(db), getTiktokAccounts(db)]);
  const facebookAccounts = facebookRows.map(mapFacebookAccountRow);
  const tiktokAccounts = tiktokRows.map(mapTiktokAccountRow);
  const fbWebhookUrl = getFacebookWebhookUrl();
  const ttWebhookUrl = getTiktokWebhookUrl();

  return (
    <div>
      <div className="mb-6">
        <h2 className="text-xl font-bold text-gray-800">
          <i className="fas fa-plug text-indigo-500 mr-2" aria-hidden="true" />
          การเชื่อมต่อแพลตฟอร์ม
        </h2>
        <p className="text-sm text-gray-500 mt-1">จัดการ Token และข้อมูลการเชื่อมต่อกับ Facebook Messenger และ TikTok Shop</p>
      </div>

      {/* ============================== FACEBOOK MESSENGER ============================== */}
      <div className="bg-white rounded-xl shadow p-6 mb-6">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold">
            <i className="fab fa-facebook-messenger text-blue-500 mr-2" aria-hidden="true" />
            Facebook Messenger
          </h3>
          <span className="text-sm text-gray-400">{facebookAccounts.length} เพจที่เชื่อมต่อ</span>
        </div>

        <div className="mb-5 p-4 bg-blue-50 border border-blue-100 rounded-lg text-sm">
          <p className="font-medium text-blue-800 mb-1">
            <i className="fas fa-link mr-1" aria-hidden="true" />
            Webhook URL (ตั้งค่าใน Meta App Dashboard)
          </p>
          <code className="block bg-white border rounded px-3 py-2 text-gray-700 break-all">{fbWebhookUrl}</code>
          <p className="text-blue-700 mt-2">
            Subscriptions: <code>messages</code>, <code>message_deliveries</code>, <code>message_reads</code> · ใช้ค่า{' '}
            <b>Verify Token</b> ตามที่ตั้งไว้ด้านล่าง
          </p>
        </div>

        {facebookAccounts.length > 0 ? (
          <div className="space-y-3 mb-5">
            {facebookAccounts.map((fb) => (
              <details key={fb.id} className="border rounded-lg">
                <summary className="flex items-center justify-between px-4 py-3 cursor-pointer select-none">
                  <span className="font-medium text-gray-800">
                    <i className="fas fa-chevron-right text-xs text-gray-400 mr-2" aria-hidden="true" />
                    {fb.name}
                    <span className="text-gray-400 font-normal text-sm ml-1">(Page ID: {fb.pageId})</span>
                  </span>
                  <StatusBadge active={fb.isActive} />
                </summary>
                <div className="px-4 pb-4 pt-2 border-t">
                  <PlatformFacebookForm account={fb} />
                </div>
              </details>
            ))}
          </div>
        ) : (
          <p className="text-sm text-gray-400 mb-5">ยังไม่มีเพจที่เชื่อมต่อ — เพิ่มเพจแรกด้านล่าง</p>
        )}

        <details className="border border-dashed rounded-lg">
          <summary className="px-4 py-3 cursor-pointer select-none font-medium text-blue-600">
            <i className="fas fa-plus mr-1" aria-hidden="true" />
            เพิ่มเพจ Facebook ใหม่
          </summary>
          <div className="px-4 pb-4 pt-2 border-t">
            <PlatformFacebookForm />
          </div>
        </details>
      </div>

      {/* ============================== TIKTOK SHOP ============================== */}
      <div className="bg-white rounded-xl shadow p-6 mb-6">
        <div className="flex items-center justify-between mb-4">
          <h3 className="text-lg font-semibold">
            <i className="fab fa-tiktok text-gray-900 mr-2" aria-hidden="true" />
            TikTok Shop
          </h3>
          <span className="text-sm text-gray-400">{tiktokAccounts.length} ร้านที่เชื่อมต่อ</span>
        </div>

        <div className="mb-5 p-4 bg-gray-50 border border-gray-200 rounded-lg text-sm">
          <p className="font-medium text-gray-800 mb-1">
            <i className="fas fa-link mr-1" aria-hidden="true" />
            Webhook URL (ตั้งค่าใน TikTok Shop Partner Center)
          </p>
          <code className="block bg-white border rounded px-3 py-2 text-gray-700 break-all">{ttWebhookUrl}</code>
        </div>

        {tiktokAccounts.length > 0 ? (
          <div className="space-y-3 mb-5">
            {tiktokAccounts.map((tt) => (
              <details key={tt.id} className="border rounded-lg">
                <summary className="flex items-center justify-between px-4 py-3 cursor-pointer select-none">
                  <span className="font-medium text-gray-800">
                    <i className="fas fa-chevron-right text-xs text-gray-400 mr-2" aria-hidden="true" />
                    {tt.name}
                    <span className="text-gray-400 font-normal text-sm ml-1">(Shop ID: {tt.shopId})</span>
                  </span>
                  <StatusBadge active={tt.isActive} />
                </summary>
                <div className="px-4 pb-4 pt-2 border-t">
                  <PlatformTikTokForm account={tt} />
                </div>
              </details>
            ))}
          </div>
        ) : (
          <p className="text-sm text-gray-400 mb-5">ยังไม่มีร้านที่เชื่อมต่อ — เพิ่มร้านแรกด้านล่าง</p>
        )}

        <details className="border border-dashed rounded-lg">
          <summary className="px-4 py-3 cursor-pointer select-none font-medium text-gray-800">
            <i className="fas fa-plus mr-1" aria-hidden="true" />
            เพิ่มร้าน TikTok Shop ใหม่
          </summary>
          <div className="px-4 pb-4 pt-2 border-t">
            <PlatformTikTokForm />
          </div>
        </details>
      </div>
    </div>
  );
}
