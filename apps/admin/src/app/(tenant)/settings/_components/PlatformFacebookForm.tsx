'use client';

import type { MouseEvent } from 'react';
import { saveFacebookAccountAction, testFacebookConnectionAction, deleteFacebookAccountAction } from '../_lib/platform-actions';
import type { FacebookAccountView } from '../_lib/platform-queries';

/**
 * PlatformFacebookForm — client island for ONE Facebook Messenger
 * connection's `<form>` in includes/settings/platform.php (either an
 * existing account's edit form inside a `<details>` card, lines 111-158, or
 * the "add new page" form, lines 172-212). Both are the SAME markup shape
 * in real PHP — this component covers both via `account` being present
 * (edit) or `undefined` (create, `fb_id=0`, no Test/Delete buttons — PHP's
 * add-new form has only one submit button).
 *
 * 'use client' is required here (not just a plain `<form action={...}>`
 * Server Component the way ../_components/EmailTab.tsx's forms are) because
 * the delete button needs `window.confirm(...)` before submitting — mirrors
 * platform.php's own `onclick="return confirm('ลบการเชื่อมต่อเพจนี้?')"`
 * (line 154) exactly, same Thai copy.
 *
 * Save / Test / Delete route to 3 DIFFERENT Server Actions from the SAME
 * `<form>` via React 19's per-`<button formAction={...}>` override — the
 * Next-native equivalent of PHP's single `<form>` with 3 submit buttons all
 * named `action` (the browser/PHP resolve which button's value wins by last
 * name=value pair in submission order; React's `formAction` does the
 * equivalent job explicitly instead of relying on that quirk).
 */
export interface PlatformFacebookFormProps {
  account?: FacebookAccountView;
}

const inputClassName = 'w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500';
const monoInputClassName = `${inputClassName} font-mono text-xs`;
const labelClassName = 'block text-sm font-medium text-gray-700 mb-1';

export function PlatformFacebookForm({ account }: PlatformFacebookFormProps) {
  const isEdit = account !== undefined;

  function onDeleteClick(event: MouseEvent<HTMLButtonElement>) {
    if (!window.confirm('ลบการเชื่อมต่อเพจนี้?')) {
      event.preventDefault();
    }
  }

  return (
    <form action={saveFacebookAccountAction} className="grid grid-cols-1 md:grid-cols-2 gap-4">
      <input type="hidden" name="fb_id" value={account?.id ?? 0} />

      <div>
        <label className={labelClassName}>ชื่อเรียก (Page Name)</label>
        <input type="text" name="name" defaultValue={account?.name} placeholder="ร้านยา CNY" required className={inputClassName} />
      </div>
      <div>
        <label className={labelClassName}>Page ID</label>
        <input type="text" name="page_id" defaultValue={account?.pageId} required className={inputClassName} />
      </div>
      <div>
        <label className={labelClassName}>App ID</label>
        <input type="text" name="app_id" defaultValue={account?.appId} className={inputClassName} />
      </div>
      <div>
        <label className={labelClassName}>App Secret</label>
        <input type="text" name="app_secret" defaultValue={account?.appSecret} className={monoInputClassName} />
      </div>
      <div className="md:col-span-2">
        <label className={labelClassName}>Page Access Token</label>
        <textarea name="page_access_token" rows={2} defaultValue={account?.pageAccessToken} required className={monoInputClassName} />
      </div>
      <div>
        <label className={labelClassName}>Verify Token</label>
        <input
          type="text"
          name="verify_token"
          defaultValue={account?.verifyToken}
          placeholder={isEdit ? undefined : 'ตั้งค่าเองให้ตรงกับ Meta Dashboard'}
          className={monoInputClassName}
        />
      </div>
      <div className="flex items-end">
        <label className="flex items-center gap-2 cursor-pointer py-2">
          <input type="checkbox" name="is_active" defaultChecked={account ? account.isActive : true} className="w-4 h-4 text-blue-600" />
          <span className="text-sm text-gray-700">เปิดใช้งานการเชื่อมต่อ</span>
        </label>
      </div>

      <div className="md:col-span-2 flex flex-wrap gap-2 pt-1">
        <button type="submit" className="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 font-medium text-sm">
          <i className={`fas ${isEdit ? 'fa-save' : 'fa-plus'} mr-1`} aria-hidden="true" />
          {isEdit ? 'บันทึก' : 'เพิ่มเพจ'}
        </button>
        {isEdit ? (
          <>
            <button
              type="submit"
              formAction={testFacebookConnectionAction}
              formNoValidate
              className="px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 font-medium text-sm"
            >
              <i className="fas fa-vial mr-1" aria-hidden="true" />
              ทดสอบการเชื่อมต่อ
            </button>
            <button
              type="submit"
              formAction={deleteFacebookAccountAction}
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
