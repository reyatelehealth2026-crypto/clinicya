'use client';

import type { MouseEvent } from 'react';
import { saveTiktokAccountAction, testTiktokConnectionAction, deleteTiktokAccountAction } from '../_lib/platform-actions';
import type { TiktokAccountView } from '../_lib/platform-queries';

/**
 * PlatformTikTokForm — client island for ONE TikTok Shop connection's
 * `<form>` in includes/settings/platform.php (either an existing account's
 * edit form, lines 245-297, or the "add new shop" form, lines 311-354) —
 * same `account`-present-vs-`undefined` dual-mode shape as
 * ./PlatformFacebookForm.tsx (see that file's module doc for the full
 * rationale: 'use client' for the delete-confirm dialog, Save/Test/Delete
 * routed to 3 separate Server Actions via per-`<button formAction={...}>`).
 */
export interface PlatformTikTokFormProps {
  account?: TiktokAccountView;
}

const inputClassName = 'w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-gray-500';
const monoInputClassName = `${inputClassName} font-mono text-xs`;
const labelClassName = 'block text-sm font-medium text-gray-700 mb-1';

export function PlatformTikTokForm({ account }: PlatformTikTokFormProps) {
  const isEdit = account !== undefined;

  function onDeleteClick(event: MouseEvent<HTMLButtonElement>) {
    if (!window.confirm('ลบการเชื่อมต่อร้านนี้?')) {
      event.preventDefault();
    }
  }

  return (
    <form action={saveTiktokAccountAction} className="grid grid-cols-1 md:grid-cols-2 gap-4">
      <input type="hidden" name="tt_id" value={account?.id ?? 0} />

      <div>
        <label className={labelClassName}>ชื่อเรียก (Shop Name)</label>
        <input type="text" name="name" defaultValue={account?.name} placeholder="CNY Shop" required className={inputClassName} />
      </div>
      <div>
        <label className={labelClassName}>Shop ID</label>
        <input type="text" name="shop_id" defaultValue={account?.shopId} required className={inputClassName} />
      </div>
      <div>
        <label className={labelClassName}>App Key</label>
        <input type="text" name="app_key" defaultValue={account?.appKey} className={monoInputClassName} />
      </div>
      <div>
        <label className={labelClassName}>App Secret</label>
        <input type="text" name="app_secret" defaultValue={account?.appSecret} className={monoInputClassName} />
      </div>
      <div className="md:col-span-2">
        <label className={labelClassName}>Access Token</label>
        <textarea name="access_token" rows={2} defaultValue={account?.accessToken} required className={monoInputClassName} />
      </div>
      <div className="md:col-span-2">
        <label className={labelClassName}>Refresh Token</label>
        <textarea name="refresh_token" rows={2} defaultValue={account?.refreshToken} className={monoInputClassName} />
      </div>
      <div>
        <label className={labelClassName}>Shop Cipher</label>
        <input type="text" name="shop_cipher" defaultValue={account?.shopCipher} className={monoInputClassName} />
      </div>
      <div className="flex items-end">
        <label className="flex items-center gap-2 cursor-pointer py-2">
          <input type="checkbox" name="is_active" defaultChecked={account ? account.isActive : true} className="w-4 h-4 text-gray-700" />
          <span className="text-sm text-gray-700">เปิดใช้งานการเชื่อมต่อ</span>
        </label>
      </div>

      <div className="md:col-span-2 flex flex-wrap gap-2 pt-1">
        <button type="submit" className="px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-black font-medium text-sm">
          <i className={`fas ${isEdit ? 'fa-save' : 'fa-plus'} mr-1`} aria-hidden="true" />
          {isEdit ? 'บันทึก' : 'เพิ่มร้าน'}
        </button>
        {isEdit ? (
          <>
            <button
              type="submit"
              formAction={testTiktokConnectionAction}
              formNoValidate
              className="px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 font-medium text-sm"
            >
              <i className="fas fa-vial mr-1" aria-hidden="true" />
              ทดสอบการเชื่อมต่อ
            </button>
            <button
              type="submit"
              formAction={deleteTiktokAccountAction}
              formNoValidate
              onClick={onDeleteClick}
              className="px-4 py-2 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 font-medium text-sm"
            >
              <i className="fas fa-trash mr-1" aria-hidden="true" />
              ลบ
            </button>
          </>
        ) : null}
      </div>
    </form>
  );
}
