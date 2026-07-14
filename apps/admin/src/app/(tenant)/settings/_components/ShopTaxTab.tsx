'use client';

import { useEffect, useState, useTransition, type FormEvent } from 'react';
import { saveShopTaxInfoAction } from '../_lib/shop-tax-actions';
import { SAVE_SUCCESS_MESSAGE, type ShopTaxInfoView } from '../_lib/shop-tax-queries';

/**
 * ShopTaxTab — Client Component port of includes/settings/shop-tax.php (227
 * LOC): the business-identity form printed as the letterhead on every
 * generated tax document (QT/INV/TAX/RE/CN/DN/PO). A Client Component (not a
 * Server Component wrapping a smaller island, unlike ./WelcomeTab.tsx) so it
 * can own `showAlert()`'s exact inline banner UX — a same-page success/error
 * message with NO navigation and NO page reload, matching the real PHP tab's
 * own inline `<script>` (lines 181-227) precisely: it `fetch()`es
 * `api/shop-tax.php?action=save` and toggles `#shopTaxAlert` in place, it
 * never redirects or reloads. This is why this tab does NOT follow
 * ../_components/EmailTab.tsx's/../_components/WelcomeTab.tsx's plain
 * `<form action={serverAction}>` + `redirect('?message=...')` convention —
 * that convention is a Next-native substitute for PHP's own "re-render the
 * same POST response" model, which this tab's real PHP source never used in
 * the first place (it was always a client-side `fetch()`, no full-page
 * round-trip).
 *
 * Server Action call shape mirrors ../../crm-dashboard-advanced/_components/
 * AddDealModal.tsx's established pattern: a plain object built from
 * `FormData`, `useTransition` + local `useState` banner state, NOT a native
 * `<form action={serverAction}>` binding.
 *
 * `initialData` is fetched server-side (page.tsx's `case 'shop_tax':` calls
 * `resolveLineAccountId()` + `getShopTaxInfo()` from ../_lib/shop-tax-queries.ts
 * and passes the resolved row down) rather than this component calling
 * `getShopTaxInfo` itself — a Kysely `Kysely<TenantDB>` instance cannot cross
 * the Server/Client boundary as a prop, so unlike ConsentTab.tsx (which is
 * `await`-invoked with a live `db`), this component only ever receives
 * plain, serializable data.
 *
 * On a successful save, PHP's own `showAlert('ok', ...)` does NOT update any
 * form field with the server's echoed-back row (`json.data` is fetched but
 * never read) and does NOT reset the form — replicated exactly: this
 * component does not re-sync its (uncontrolled) inputs from the Server
 * Action's returned `data` either, even though it's available on the result.
 */
export interface ShopTaxTabProps {
  initialData: ShopTaxInfoView;
}

interface AlertState {
  type: 'ok' | 'err';
  message: string;
}

const inputClassName = 'w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500';
const labelClassName = 'block text-sm font-medium text-slate-700 mb-1';

export function ShopTaxTab({ initialData }: ShopTaxTabProps) {
  const [alert, setAlert] = useState<AlertState | null>(null);
  const [isPending, startTransition] = useTransition();

  useEffect(() => {
    if (alert?.type !== 'ok') return;
    const timer = setTimeout(() => setAlert(null), 4000);
    return () => clearTimeout(timer);
  }, [alert]);

  function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const fd = new FormData(form);

    const payload = {
      business_name: fd.get('business_name') ?? '',
      business_name_en: fd.get('business_name_en') ?? '',
      tax_id: fd.get('tax_id') ?? '',
      branch_code: fd.get('branch_code') ?? '',
      address: fd.get('address') ?? '',
      phone: fd.get('phone') ?? '',
      email: fd.get('email') ?? '',
      logo_url: fd.get('logo_url') ?? '',
      authorized_signer: fd.get('authorized_signer') ?? '',
      signer_position: fd.get('signer_position') ?? '',
      // Mirrors PHP's `form.querySelector('[name="is_vat_registered"]').checked ? 1 : 0`
      // — FormData only includes a checkbox when it's checked.
      is_vat_registered: fd.get('is_vat_registered') !== null ? 1 : 0,
      default_vat_rate: fd.get('default_vat_rate') ?? '',
    };

    startTransition(async () => {
      try {
        const result = await saveShopTaxInfoAction(payload);
        if (result.success) {
          setAlert({ type: 'ok', message: result.message ?? SAVE_SUCCESS_MESSAGE });
        } else {
          setAlert({ type: 'err', message: result.message || result.error || 'บันทึกไม่สำเร็จ' });
        }
      } catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        setAlert({ type: 'err', message: `เครือข่ายมีปัญหา: ${message}` });
      }
    });
  }

  return (
    <div className="bg-white rounded-xl border border-slate-200 p-6">
      <div className="flex items-start justify-between gap-4 mb-6">
        <div>
          <h2 className="text-xl font-semibold text-slate-900 flex items-center gap-2">
            <i className="fas fa-file-invoice text-indigo-600" aria-hidden="true" />
            ข้อมูลกิจการ (พิมพ์บนใบกำกับภาษี / ใบเสนอราคา)
          </h2>
          <p className="text-sm text-slate-500 mt-1">
            ข้อมูลนี้จะถูกพิมพ์เป็นหัวกระดาษเอกสารทุกประเภท (QT / INV / TAX / RE / CN / DN / PO ฯลฯ). เก็บตามผู้ออกใบ —
            แยกตามบัญชี LINE (multi-tenant).
          </p>
        </div>
        <a href="/documents.php" className="text-sm text-slate-500 hover:text-slate-800 inline-flex items-center gap-1">
          <i className="fas fa-arrow-left" aria-hidden="true" /> กลับหน้าเอกสาร
        </a>
      </div>

      {alert ? (
        <div
          role={alert.type === 'err' ? 'alert' : 'status'}
          className={`mb-4 p-3 rounded-lg text-sm ${
            alert.type === 'ok' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-rose-50 border border-rose-200 text-rose-800'
          }`}
        >
          {alert.message}
        </div>
      ) : null}

      <form onSubmit={onSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-slate-50 rounded-lg border border-slate-200">
          <div>
            <label className={labelClassName}>
              ชื่อกิจการ (ไทย) <span className="text-rose-500">*</span>
            </label>
            <input type="text" name="business_name" maxLength={255} required defaultValue={initialData.businessName} className={inputClassName} placeholder="เช่น บริษัท เรยา เฮลธ์ จำกัด" />
          </div>
          <div>
            <label className={labelClassName}>ชื่อกิจการ (อังกฤษ)</label>
            <input type="text" name="business_name_en" maxLength={255} defaultValue={initialData.businessNameEn} className={inputClassName} placeholder="REYA Health Co., Ltd." />
          </div>
        </div>

        <div>
          <label className={labelClassName}>เลขประจำตัวผู้เสียภาษี (13 หลัก)</label>
          <input
            type="text"
            name="tax_id"
            maxLength={20}
            pattern="[0-9]{10,13}"
            defaultValue={initialData.taxId}
            className={inputClassName}
            placeholder="0105566123456"
          />
        </div>
        <div>
          <label className={labelClassName}>รหัสสาขา</label>
          <input type="text" name="branch_code" maxLength={20} defaultValue={initialData.branchCode} className={inputClassName} placeholder="00000 = สำนักงานใหญ่" />
        </div>

        <div className="md:col-span-2">
          <label className={labelClassName}>ที่อยู่กิจการ</label>
          <textarea name="address" rows={3} defaultValue={initialData.address} className={inputClassName} placeholder="123 ถนน... แขวง... เขต... กรุงเทพฯ 10110" />
        </div>

        <div>
          <label className={labelClassName}>เบอร์โทร</label>
          <input type="text" name="phone" maxLength={50} defaultValue={initialData.phone} className={inputClassName} placeholder="02-123-4567" />
        </div>
        <div>
          <label className={labelClassName}>อีเมล</label>
          <input type="email" name="email" maxLength={100} defaultValue={initialData.email} className={inputClassName} placeholder="contact@yourshop.com" />
        </div>

        <div className="md:col-span-2">
          <label className={labelClassName}>URL โลโก้ (https://...)</label>
          <input type="url" name="logo_url" maxLength={500} defaultValue={initialData.logoUrl} className={inputClassName} placeholder="https://your-cdn.com/logo.png" />
        </div>

        <div>
          <label className={labelClassName}>ผู้มีอำนาจลงนาม</label>
          <input type="text" name="authorized_signer" maxLength={255} defaultValue={initialData.authorizedSigner} className={inputClassName} placeholder="นาย ก. ขีดเส้น" />
        </div>
        <div>
          <label className={labelClassName}>ตำแหน่ง</label>
          <input type="text" name="signer_position" maxLength={100} defaultValue={initialData.signerPosition} className={inputClassName} placeholder="กรรมการผู้จัดการ" />
        </div>

        <div className="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-amber-50 rounded-lg border border-amber-200">
          <div className="flex items-center gap-3">
            <input
              type="checkbox"
              name="is_vat_registered"
              id="isVatRegistered"
              value="1"
              defaultChecked={initialData.isVatRegistered}
              className="w-5 h-5 text-indigo-600 rounded focus:ring-indigo-500"
            />
            <label htmlFor="isVatRegistered" className="text-sm font-medium text-slate-700">
              จดทะเบียนภาษีมูลค่าเพิ่ม (VAT)
            </label>
          </div>
          <div>
            <label className={labelClassName}>อัตรา VAT เริ่มต้น (%)</label>
            <input type="number" name="default_vat_rate" min={0} max={99} step={0.01} defaultValue={initialData.defaultVatRate} className={inputClassName} />
          </div>
        </div>

        <div className="md:col-span-2 pt-4 border-t border-slate-200 flex items-center justify-end gap-3">
          <a href="/documents.php" className="px-4 py-2 text-sm text-slate-600 hover:text-slate-800">
            ยกเลิก
          </a>
          <button
            type="submit"
            disabled={isPending}
            className="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-lg inline-flex items-center gap-2 disabled:opacity-50"
          >
            <i className="fas fa-save" aria-hidden="true" /> {isPending ? 'กำลังบันทึก...' : 'บันทึก'}
          </button>
        </div>
      </form>
    </div>
  );
}
