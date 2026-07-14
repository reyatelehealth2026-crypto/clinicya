'use client';

import { DAY_NAMES_TH, formatBaht, formatRating } from '../_lib/format';
import type { PharmacistRow } from '../queries';

/**
 * PharmacistCard.tsx — one pharmacist card from
 * includes/pharmacy/pharmacists.php's grid (lines 148-233).
 *
 * `data-pharmacist-id` on the outer card and `data-day-active` on each
 * schedule-day badge are stable hooks for a future parity harness (same
 * "delimiter attribute, not a CSS-class match" convention templates.php's
 * port established with `data-category` — see extractTemplatesPage()'s doc
 * comment in infra/e2e/lib/extract.mjs — TemplateCard.tsx/PHP share NO CSS
 * classes either, since this port uses plain Tailwind utilities throughout,
 * not pharmacists.php's inline Tailwind class names).
 *
 * The avatar fallback (line 152) is reproduced exactly:
 * `image_url || 'https://api.dicebear.com/7.x/avataaars/svg?seed=' + encodeURIComponent(name)`.
 */
export function PharmacistCard({
  pharmacist,
  onEdit,
  onHoliday,
  onDelete,
}: {
  pharmacist: PharmacistRow;
  onEdit: () => void;
  onHoliday: () => void;
  onDelete: () => void;
}) {
  const avatarUrl = pharmacist.imageUrl || `https://api.dicebear.com/7.x/avataaars/svg?seed=${encodeURIComponent(pharmacist.name)}`;
  const scheduleDays = new Set(pharmacist.schedules.map((s) => s.dayOfWeek));
  const fee = pharmacist.consultationFee;

  function handleDeleteClick() {
    if (window.confirm('ยืนยันการลบ?')) {
      onDelete();
    }
  }

  return (
    <div
      data-pharmacist-id={pharmacist.id}
      className={`bg-white rounded-xl shadow overflow-hidden ${!pharmacist.isActive ? 'opacity-60' : ''}`}
    >
      <div className="p-6">
        <div className="flex items-start gap-4 mb-4">
          {/* eslint-disable-next-line @next/next/no-img-element -- external/user-provided URL, same as the PHP source's plain <img> */}
          <img src={avatarUrl} alt={pharmacist.name} className="w-16 h-16 rounded-full object-cover border-2 border-green-200" />
          <div className="flex-1">
            <div className="flex items-center gap-2">
              <h3 className="font-bold text-gray-800">
                {pharmacist.title}
                {pharmacist.name}
              </h3>
              {pharmacist.isAvailable ? <span className="w-2 h-2 bg-green-500 rounded-full" title="พร้อมให้บริการ" /> : null}
            </div>
            <p className="text-sm text-gray-500">{pharmacist.specialty || 'เภสัชกรทั่วไป'}</p>
            {pharmacist.licenseNo ? <p className="text-xs text-gray-400">ใบอนุญาต: {pharmacist.licenseNo}</p> : null}
          </div>
        </div>

        <div className="flex items-center gap-4 mb-4 text-sm">
          <div className="flex items-center gap-1 text-yellow-500">
            <span>★</span>
            <span>{formatRating(pharmacist.rating)}</span>
            <span className="text-gray-400">({pharmacist.reviewCount})</span>
          </div>
          <div className="text-gray-500">{pharmacist.consultationDuration} นาที</div>
          {fee > 0 ? <div className="text-green-600 font-medium">฿{formatBaht(fee)}</div> : <div className="text-green-600 font-medium">ฟรี</div>}
        </div>

        <div className="grid grid-cols-2 gap-2 mb-4">
          <div className="p-2 bg-blue-50 rounded-lg text-center">
            <p className="text-lg font-bold text-blue-600">{pharmacist.upcomingCount}</p>
            <p className="text-xs text-blue-500">นัดหมายรอ</p>
          </div>
          <div className="p-2 bg-green-50 rounded-lg text-center">
            <p className="text-lg font-bold text-green-600">{pharmacist.completedCount}</p>
            <p className="text-xs text-green-500">เสร็จสิ้น</p>
          </div>
        </div>

        <div className="mb-4">
          <p className="text-xs font-medium text-gray-500 mb-2">ตารางเวลา:</p>
          <div className="flex flex-wrap gap-1">
            {DAY_NAMES_TH.map((day, i) => {
              const active = scheduleDays.has(i);
              return (
                <span
                  key={i}
                  data-day-active={active}
                  className={`px-2 py-0.5 rounded text-xs ${active ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400'}`}
                >
                  {day.slice(0, 1)}
                </span>
              );
            })}
          </div>
        </div>

        <div className="flex gap-2">
          <button type="button" onClick={onEdit} className="flex-1 py-2 bg-blue-100 text-blue-600 rounded-lg hover:bg-blue-200 text-sm">
            แก้ไข
          </button>
          <button type="button" onClick={onHoliday} className="flex-1 py-2 bg-yellow-100 text-yellow-600 rounded-lg hover:bg-yellow-200 text-sm">
            วันหยุด
          </button>
          <button
            type="button"
            onClick={handleDeleteClick}
            aria-label="ลบเภสัชกร"
            className="py-2 px-3 bg-red-100 text-red-600 rounded-lg hover:bg-red-200 text-sm"
          >
            ลบ
          </button>
        </div>
      </div>
    </div>
  );
}
