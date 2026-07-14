import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { getWelcomeSettings } from '../_lib/welcome-queries';
import { WelcomeMessageForm } from './WelcomeMessageForm';

/**
 * WelcomeTab — Server Component port of includes/settings/welcome.php
 * (271 lines): the header row (title/subtitle + enable toggle) is
 * server-rendered here from data fetched via ./welcome-queries.ts's
 * getWelcomeSettings(); the interactive message-type radio toggle + Flex
 * JSON textarea/live-preview/template buttons are delegated to
 * ./WelcomeMessageForm.tsx, a small 'use client' island — see that file's
 * module doc for the full rationale.
 *
 * The enable-toggle `<input type="checkbox">` intentionally renders OUTSIDE
 * `<WelcomeMessageForm>` (i.e. outside the actual `<form>` element) and
 * associates with it purely via the HTML5 `form="welcomeForm"` attribute —
 * byte-for-byte the same structural trick welcome.php itself uses (its
 * toggle sits in a header `<div>` ABOVE `<form id="welcomeForm">`, bound
 * only by `form="welcomeForm"` on the `<input>`, welcome.php line 43).
 */
export interface WelcomeTabProps {
  db: Kysely<TenantDB>;
  currentBotId: number | null;
}

export async function WelcomeTab({ db, currentBotId }: WelcomeTabProps) {
  const settings = await getWelcomeSettings(db, currentBotId);

  return (
    <div className="bg-white rounded-xl shadow-sm p-6">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h2 className="text-xl font-bold text-gray-800">ข้อความต้อนรับ</h2>
          <p className="text-gray-500 text-sm">ตั้งค่าข้อความที่จะส่งเมื่อมีผู้ติดตามใหม่</p>
        </div>
        <div className="flex items-center gap-2">
          <span className="text-sm text-gray-600">เปิดใช้งาน</span>
          <label className="relative inline-flex items-center cursor-pointer">
            <input
              type="checkbox"
              name="is_enabled"
              form="welcomeForm"
              defaultChecked={settings.isEnabled}
              aria-label="เปิดใช้งานข้อความต้อนรับ"
              className="sr-only peer"
            />
            <div className="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500" />
          </label>
        </div>
      </div>

      <WelcomeMessageForm settings={settings} />
    </div>
  );
}
