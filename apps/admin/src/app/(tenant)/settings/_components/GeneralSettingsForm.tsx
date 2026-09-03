'use client';

import { useRef, useState, type ChangeEvent } from 'react';
import { saveGeneralSettingsAction } from '../_lib/general-actions';
import type { GeneralSettingsView } from '../_lib/general-queries';

/**
 * GeneralSettingsForm — the client island for includes/shop/general.php's
 * `<form id="settings-general-form">` (lines 53-253) plus its inline
 * `<script>` (lines 255-297): the only genuinely interactive bits PHP drove
 * client-side were bank-row add/remove (`addBankRow()`, plain DOM
 * insertAdjacentHTML) and logo file-vs-URL preview swap (`previewLogo()`/
 * `previewLogoUrl()`, mutually exclusive — picking one clears the other) —
 * reimplemented here as React state instead of direct DOM manipulation.
 * ../_components/GeneralTab.tsx (the Server Component wrapper) fetches
 * `GeneralSettingsView` server-side and passes it down, same split as
 * ../_components/WelcomeTab.tsx/./WelcomeMessageForm.tsx.
 *
 * Imports `saveGeneralSettingsAction` directly (same convention
 * ./WelcomeMessageForm.tsx established) — `<form action={...}>`.
 *
 * `showOdooOrderSource` — general.php's own gate is inline PHP
 * (`defined('ODOO_INTEGRATION_ENABLED') && ODOO_INTEGRATION_ENABLED ===
 * true`, lines 116-138); computed server-side in GeneralTab.tsx via
 * (tenant)/users/_lib/odoo.ts's `isOdooIntegrationEnabled()` (read-only
 * import per this batch's allowed paths) and passed down as a plain
 * boolean, since that env-var check can't run in the browser.
 */
export interface GeneralSettingsFormProps {
  settings: GeneralSettingsView;
  showOdooOrderSource: boolean;
}

const inputClassName = 'w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 text-sm';
const labelClassName = 'block text-sm font-medium text-gray-700 mb-1';
const cardClassName = 'bg-white rounded-xl shadow-sm border border-gray-200 p-5';
const toggleRowClassName = 'flex items-center justify-between p-3 bg-gray-50 rounded-lg border';

interface KeyedBankRow {
  key: number;
  name: string;
  account: string;
  holder: string;
}

export function GeneralSettingsForm({ settings, showOdooOrderSource }: GeneralSettingsFormProps) {
  const [logoPreview, setLogoPreview] = useState(settings.shopLogo);
  const [logoUrlValue, setLogoUrlValue] = useState(settings.shopLogo);
  // Stable per-row `key` (NOT the array index) — required so React unmounts/remounts the
  // correct DOM node on removeBankRow() instead of reusing a surviving row's node for a
  // different row's data (uncontrolled `defaultValue` only applies at mount time; an
  // index-keyed remove-from-the-middle would otherwise leave a stale value behind).
  const nextRowKey = useRef(settings.bankAccounts.length);
  const [bankRows, setBankRows] = useState<KeyedBankRow[]>(() => settings.bankAccounts.map((bank, index) => ({ key: index, ...bank })));

  function onLogoFileChange(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (loadEvent) => {
      setLogoPreview(String(loadEvent.target?.result ?? ''));
      setLogoUrlValue('');
    };
    reader.readAsDataURL(file);
  }

  function onLogoUrlChange(event: ChangeEvent<HTMLInputElement>) {
    const url = event.target.value;
    setLogoUrlValue(url);
    if (url.trim()) {
      setLogoPreview(url.trim());
    }
  }

  function addBankRow() {
    setBankRows((rows) => [...rows, { key: nextRowKey.current++, name: '', account: '', holder: '' }]);
  }

  function removeBankRow(key: number) {
    setBankRows((rows) => rows.filter((row) => row.key !== key));
  }

  return (
    <form action={saveGeneralSettingsAction} encType="multipart/form-data" id="settings-general-form">
      <input type="hidden" name="tab" value="general" />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* ── ข้อมูลร้านค้า ── */}
        <div className={cardClassName}>
          <h3 className="text-base font-semibold text-gray-800 mb-4">
            <i className="fas fa-store text-emerald-500 mr-2" aria-hidden="true" />
            ข้อมูลร้านค้า
          </h3>
          <div className="space-y-4">
            <div>
              <label className={labelClassName}>ชื่อร้าน</label>
              <input type="text" name="shop_name" defaultValue={settings.shopName} placeholder="LINE Shop" required className={inputClassName} />
            </div>

            <div>
              <label className={labelClassName}>โลโก้ร้าน</label>
              <div className="flex items-start gap-4">
                <div className="flex-shrink-0">
                  {logoPreview ? (
                    <img src={logoPreview} alt="" className="w-20 h-20 rounded-lg object-cover border" />
                  ) : (
                    <div className="w-20 h-20 rounded-lg bg-gray-100 flex items-center justify-center border">
                      <i className="fas fa-image text-gray-400 text-2xl" aria-hidden="true" />
                    </div>
                  )}
                </div>
                <div className="flex-1 space-y-2">
                  <div className="flex items-center gap-2">
                    <label className="px-4 py-2 bg-blue-500 text-white rounded-lg cursor-pointer hover:bg-blue-600 transition text-sm">
                      <i className="fas fa-upload mr-1" aria-hidden="true" />
                      อัพโหลดรูป
                      <input type="file" name="logo_file" accept="image/*" className="hidden" onChange={onLogoFileChange} />
                    </label>
                    <span className="text-xs text-gray-500">หรือ</span>
                  </div>
                  <input type="url" name="shop_logo" value={logoUrlValue} onChange={onLogoUrlChange} placeholder="วาง URL รูปโลโก้" className={inputClassName} />
                  <p className="text-xs text-gray-400">ขนาดแนะนำ: 200x200 px</p>
                </div>
              </div>
            </div>

            <div>
              <label className={labelClassName}>ข้อความต้อนรับ</label>
              <textarea name="welcome_message" rows={3} defaultValue={settings.welcomeMessage} className={inputClassName} />
            </div>

            <div>
              <label className={labelClassName}>ที่อยู่ร้าน</label>
              <textarea name="shop_address" rows={2} defaultValue={settings.shopAddress} className={inputClassName} />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className={labelClassName}>เบอร์ติดต่อ</label>
                <input type="tel" name="contact_phone" defaultValue={settings.contactPhone} className={inputClassName} />
              </div>
              <div>
                <label className={labelClassName}>อีเมล</label>
                <input type="email" name="shop_email" defaultValue={settings.shopEmail} className={inputClassName} />
              </div>
            </div>

            <label className={toggleRowClassName}>
              <span className="text-sm">
                <span className="font-medium text-gray-800">สถานะร้านค้า</span>
                <span className="block text-xs text-gray-500">เปิด/ปิดรับออเดอร์</span>
              </span>
              <input type="checkbox" name="is_open" defaultChecked={settings.isOpen} className="w-5 h-5 text-emerald-600" aria-label="สถานะร้านค้า" />
            </label>

            {showOdooOrderSource ? (
              <div className="p-4 bg-indigo-50 rounded-lg border border-indigo-100">
                <label className="block text-sm font-medium mb-2 text-indigo-900">แหล่งข้อมูลคำสั่งซื้อ/ยอดขาย</label>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-2">
                  <label className="flex items-center gap-2 p-3 bg-white rounded-lg border cursor-pointer">
                    <input type="radio" name="order_data_source" value="shop" defaultChecked={settings.orderDataSource !== 'odoo'} className="text-green-500" />
                    <span>
                      <span className="font-medium text-sm text-gray-800">Shop (เดิม)</span>
                      <span className="block text-xs text-gray-500">ใช้ข้อมูลจาก transactions/orders ในระบบนี้</span>
                    </span>
                  </label>
                  <label className="flex items-center gap-2 p-3 bg-white rounded-lg border cursor-pointer">
                    <input type="radio" name="order_data_source" value="odoo" defaultChecked={settings.orderDataSource === 'odoo'} className="text-indigo-600" />
                    <span>
                      <span className="font-medium text-sm text-gray-800">Odoo</span>
                      <span className="block text-xs text-gray-500">ใช้ข้อมูลที่รับจาก Odoo (read-only สำหรับหลังบ้านออเดอร์)</span>
                    </span>
                  </label>
                </div>
              </div>
            ) : null}
          </div>
        </div>

        {/* ── ค่าจัดส่ง ── */}
        <div className={cardClassName}>
          <h3 className="text-base font-semibold text-gray-800 mb-4">
            <i className="fas fa-truck text-emerald-500 mr-2" aria-hidden="true" />
            ค่าจัดส่ง
          </h3>
          <div className="space-y-4">
            <div>
              <label className={labelClassName}>ค่าจัดส่ง (บาท)</label>
              <input type="number" name="shipping_fee" min={0} defaultValue={settings.shippingFee} className={inputClassName} />
            </div>
            <div>
              <label className={labelClassName}>ส่งฟรีเมื่อซื้อขั้นต่ำ (บาท)</label>
              <input type="number" name="free_shipping_min" min={0} defaultValue={settings.freeShippingMin} className={inputClassName} />
              <p className="text-xs text-gray-400 mt-1">ใส่ 0 เพื่อปิดส่งฟรี</p>
            </div>

            <div className="border-t pt-4 mt-4">
              <h4 className="font-medium mb-3 text-sm text-gray-700">
                <i className="fas fa-hand-holding-usd mr-2 text-orange-500" aria-hidden="true" />
                เก็บเงินปลายทาง (COD)
              </h4>
              <label className={toggleRowClassName}>
                <span className="text-sm">
                  <span className="font-medium text-gray-800">เปิดใช้ COD</span>
                  <span className="block text-xs text-gray-500">ลูกค้าจ่ายเงินตอนรับสินค้า</span>
                </span>
                <input type="checkbox" name="cod_enabled" defaultChecked={settings.codEnabled} className="w-5 h-5 text-amber-600" aria-label="เปิดใช้ COD" />
              </label>
              <div className="mt-3">
                <label className={labelClassName}>ค่าธรรมเนียม COD (บาท)</label>
                <input type="number" name="cod_fee" min={0} defaultValue={settings.codFee} className={inputClassName} />
              </div>
            </div>
          </div>
        </div>

        {/* ── โซเชียลมีเดีย ── */}
        <div className={cardClassName}>
          <h3 className="text-base font-semibold text-gray-800 mb-4">
            <i className="fas fa-share-alt text-emerald-500 mr-2" aria-hidden="true" />
            โซเชียลมีเดีย
          </h3>
          <div className="space-y-4">
            <div>
              <label className={labelClassName}>LINE ID</label>
              <input type="text" name="line_id" defaultValue={settings.lineId} placeholder="@yourlineid" className={inputClassName} />
            </div>
            <div>
              <label className={labelClassName}>Facebook</label>
              <input type="url" name="facebook_url" defaultValue={settings.facebookUrl} placeholder="https://facebook.com/yourpage" className={inputClassName} />
            </div>
            <div>
              <label className={labelClassName}>Instagram</label>
              <input type="url" name="instagram_url" defaultValue={settings.instagramUrl} placeholder="https://instagram.com/yourpage" className={inputClassName} />
            </div>
          </div>
        </div>

        {/* ── ตั้งค่าเพิ่มเติม ── */}
        <div className={cardClassName}>
          <h3 className="text-base font-semibold text-gray-800 mb-4">
            <i className="fas fa-cog text-emerald-500 mr-2" aria-hidden="true" />
            ตั้งค่าเพิ่มเติม
          </h3>
          <label className={toggleRowClassName}>
            <span className="text-sm">
              <span className="font-medium text-gray-800">ยืนยันการชำระเงินอัตโนมัติ</span>
              <span className="block text-xs text-gray-500">ระบบจะยืนยันออเดอร์อัตโนมัติเมื่อได้รับสลิป</span>
            </span>
            <input
              type="checkbox"
              name="auto_confirm_payment"
              defaultChecked={settings.autoConfirmPayment}
              className="w-5 h-5 text-indigo-600"
              aria-label="ยืนยันการชำระเงินอัตโนมัติ"
            />
          </label>
        </div>
      </div>

      {/* ── ช่องทางชำระเงิน (full-width) ── */}
      <div className={`${cardClassName} mt-6`}>
        <h3 className="text-base font-semibold text-gray-800 mb-4">
          <i className="fas fa-credit-card text-emerald-500 mr-2" aria-hidden="true" />
          ช่องทางชำระเงิน
        </h3>
        <div className="space-y-4">
          <div>
            <label className={labelClassName}>พร้อมเพย์</label>
            <input type="text" name="promptpay_number" defaultValue={settings.promptpayNumber} placeholder="เบอร์โทรหรือเลขบัตรประชาชน" className={inputClassName} />
          </div>

          <div>
            <label className={labelClassName}>บัญชีธนาคาร</label>
            <div className="space-y-3">
              {bankRows.map((bank) => (
                <div key={bank.key} className="flex gap-2">
                  <input type="text" name="bank_name[]" defaultValue={bank.name} placeholder="ธนาคาร" className={`${inputClassName} flex-1`} />
                  <input type="text" name="bank_account[]" defaultValue={bank.account} placeholder="เลขบัญชี" className={`${inputClassName} flex-1`} />
                  <input type="text" name="bank_holder[]" defaultValue={bank.holder} placeholder="ชื่อบัญชี" className={`${inputClassName} flex-1`} />
                  <button
                    type="button"
                    onClick={() => removeBankRow(bank.key)}
                    className="px-3 py-2 text-red-500 hover:bg-red-50 rounded-lg flex-shrink-0"
                    aria-label="ลบบัญชีธนาคาร"
                  >
                    <i className="fas fa-times" aria-hidden="true" />
                  </button>
                </div>
              ))}
            </div>
            <button type="button" onClick={addBankRow} className="mt-2 px-4 py-2 border rounded-lg hover:bg-gray-50 text-sm">
              <i className="fas fa-plus mr-2" aria-hidden="true" />
              เพิ่มบัญชี
            </button>
          </div>
        </div>
      </div>

      <div className="mt-6 flex justify-end">
        <button type="submit" className="px-6 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded-lg font-medium">
          <i className="fas fa-save mr-2" aria-hidden="true" />
          บันทึกการตั้งค่า
        </button>
      </div>
    </form>
  );
}
