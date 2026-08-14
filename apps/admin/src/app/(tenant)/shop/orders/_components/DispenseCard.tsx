import {
  frequencyText,
  isMedicineItem,
  mealTimingText,
  medicineIcon,
  parseDispenseItems,
  paymentMethodText,
  timeOfDayIcons,
  itemSubtotal,
} from '../_lib/dispenseItem';
import { formatMoney2, formatOrderDateTime } from '../_lib/format';
import type { DispenseRecordRow } from '../queries';

/**
 * DispenseCard.tsx — React port of shop/orders.php's `?view=dispense` tab
 * per-record card (lines 760-842). Server Component, entirely read-only —
 * this batch's brief is explicit that no write action for dispensing
 * originates on this page (that flow lives in messages.php/inbox-v2.php,
 * Phase 5 territory, untouched). The "แชท" (chat) link is a plain href
 * string to the still-PHP chat.php, not an import — chat.php is out of
 * scope for this batch.
 */
export interface DispenseCardProps {
  record: DispenseRecordRow;
}

export function DispenseCard({ record }: DispenseCardProps) {
  const items = parseDispenseItems(record.items);

  return (
    <div className="bg-white border border-slate-200 rounded-xl overflow-hidden mb-4 shadow-sm">
      <div className="p-4 bg-emerald-50 border-b border-slate-100 flex justify-between items-center flex-wrap gap-3">
        <div className="flex items-center gap-3">
          {/* eslint-disable-next-line @next/next/no-img-element -- external/user-provided URL, same as the PHP source's plain <img> */}
          <img src={record.pictureUrl || 'https://via.placeholder.com/40'} alt="" className="w-10 h-10 rounded-full object-cover" />
          <div>
            <div className="flex items-center gap-2">
              <span className="font-semibold text-emerald-700">#{record.orderNumber}</span>
              <span className="px-2 py-0.5 rounded-full text-xs bg-emerald-100 text-emerald-700">💊 จ่ายยา</span>
            </div>
            <div className="text-sm text-slate-500">
              {record.displayName} · {formatOrderDateTime(record.createdAt)}
            </div>
          </div>
        </div>
        <div className="text-right">
          <span className="inline-block px-3 py-1 rounded-full text-xs font-medium bg-emerald-100 text-emerald-600">
            {record.paymentStatus === 'paid' ? '✅ ชำระแล้ว' : '⏳ รอชำระ'}
          </span>
          <div className="text-lg font-bold text-emerald-600 mt-1">฿{formatMoney2(record.totalAmount)}</div>
        </div>
      </div>

      <div className="p-4">
        {items.map((item, index) => {
          const medicine = isMedicineItem(item);
          const icons = timeOfDayIcons(item);
          return (
            // eslint-disable-next-line react/no-array-index-key -- dispense line items carry no stable id in the source JSON, same as PHP's plain foreach.
            <div key={index} className="flex items-start gap-3 p-3 bg-slate-50 rounded-lg mb-3">
              <div className="flex-shrink-0 text-2xl">{medicineIcon(item)}</div>
              <div className="flex-1">
                <p className="font-medium text-slate-800 m-0 mb-1">{item.name}</p>
                <p className="text-sm text-slate-500 m-0">
                  จำนวน: {item.qty} {item.unit ?? 'ชิ้น'}
                </p>

                {medicine ? (
                  <div className="mt-2 text-xs">
                    {item.indication ? <p className="text-primary-600 my-0.5">📋 ข้อบ่งใช้: {item.indication}</p> : null}
                    <p className="text-violet-600 my-0.5">
                      💊 รับประทานครั้งละ {item.dosage ?? 1} {item.dosageUnit ?? 'เม็ด'} {frequencyText(item)}
                    </p>
                    <p className="text-amber-600 my-0.5">
                      ⏰ {mealTimingText(item)}
                      {icons ? ` | ${icons}` : ''}
                    </p>
                  </div>
                ) : null}

                {item.notes ? <p className="mt-1 text-xs text-slate-500">📝 {item.notes}</p> : null}
              </div>
              <div className="text-right">
                <p className="font-bold text-emerald-600 m-0">฿{formatMoney2(itemSubtotal(item))}</p>
              </div>
            </div>
          );
        })}
      </div>

      <div className="px-4 py-3 bg-slate-50 border-t border-slate-200 flex justify-between items-center">
        <div className="text-sm text-slate-500">
          <span>
            <i className="fas fa-box mr-1" aria-hidden="true" />
            {items.length} รายการ
          </span>
          <span className="ml-4">{paymentMethodText(record.paymentMethod)}</span>
        </div>
        <a
          href={`/chat.php?user=${record.userId}`}
          className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-emerald-500 text-white hover:bg-emerald-600"
        >
          <i className="fas fa-comments" aria-hidden="true" />
          แชท
        </a>
      </div>
    </div>
  );
}
