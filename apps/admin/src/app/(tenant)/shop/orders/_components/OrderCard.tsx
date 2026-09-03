import {
  DEFAULT_STATUS_BADGE_CLASS,
  DEFAULT_TRANSACTION_TYPE,
  ORDER_STATUSES,
  STATUS_BADGE_CLASS,
  TRANSACTION_TYPES,
  isKnownOrderStatus,
} from '../_lib/constants';
import { formatMoney2, formatOrderDateTime } from '../_lib/format';
import type { OrdersListRow } from '../queries';
import { ConfirmOrderButton } from './ConfirmOrderButton';

/**
 * OrderCard.tsx — React port of shop/orders.php's per-order `.order-card`
 * (lines 642-714). Server Component except for the single "ยืนยัน" button,
 * isolated into ConfirmOrderButton.tsx (the only interactive element PHP's
 * own card contains).
 *
 * The link to the order-detail page is a PLAIN href string
 * ('/shop/order-detail?id=' + id) — this batch's brief is explicit that
 * shop/order-detail/** is a different builder's exclusive territory, linked
 * to but never imported from.
 */
export interface OrderCardProps {
  order: OrdersListRow;
  hasPendingSlip: boolean;
}

interface DeliveryInfo {
  name?: string;
  phone?: string;
  address?: string;
}

/** `json_decode($order['delivery_info'] ?? '{}', true)` (line 636) — malformed/absent JSON degrades to {} rather than throwing, matching json_decode's own null-on-failure behavior feeding into the `!empty(...)` checks below. */
function parseDeliveryInfo(raw: string | null): DeliveryInfo {
  if (!raw) return {};
  try {
    const parsed: unknown = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? (parsed as DeliveryInfo) : {};
  } catch {
    return {};
  }
}

export function OrderCard({ order, hasPendingSlip }: OrderCardProps) {
  const transType = order.transactionType ?? 'purchase';
  const typeInfo = TRANSACTION_TYPES[transType] ?? DEFAULT_TRANSACTION_TYPE;
  const deliveryInfo = parseDeliveryInfo(order.deliveryInfo);
  const statusKey = order.status ?? 'pending';
  const statusLabel = (isKnownOrderStatus(statusKey) ? ORDER_STATUSES[statusKey].label : null) ?? statusKey;
  const badgeClass = isKnownOrderStatus(statusKey) ? STATUS_BADGE_CLASS[statusKey] : DEFAULT_STATUS_BADGE_CLASS;
  const detailHref = `/shop/order-detail?id=${order.id}`;
  const hasDelivery = !!(deliveryInfo.name || deliveryInfo.phone || deliveryInfo.address);

  return (
    <div
      className={`bg-white border rounded-xl overflow-hidden mb-4 shadow-sm ${hasPendingSlip ? 'outline outline-2 outline-amber-400 border-amber-400' : 'border-slate-200'}`}
    >
      {hasPendingSlip ? (
        <div className="bg-amber-500 text-white px-4 py-2 text-sm flex items-center justify-between">
          <span>
            <i className="fas fa-receipt mr-2" aria-hidden="true" />
            <strong>มีสลิปรอตรวจสอบ</strong>
          </span>
          <a href={detailHref} className="bg-white text-amber-600 px-3 py-1 rounded text-xs font-semibold no-underline">
            ตรวจสอบเลย
          </a>
        </div>
      ) : null}

      <div className="p-4 border-b border-slate-100 flex justify-between items-center flex-wrap gap-3">
        <div className="flex items-center gap-3">
          {/* eslint-disable-next-line @next/next/no-img-element -- external/user-provided URL, same as the PHP source's plain <img> */}
          <img
            src={order.pictureUrl || 'https://via.placeholder.com/40'}
            alt=""
            className="w-10 h-10 rounded-full object-cover"
          />
          <div>
            <div className="flex items-center gap-2">
              <span className="font-semibold text-slate-800">#{order.orderNumber}</span>
              {transType !== 'purchase' ? (
                <span className="px-2 py-0.5 rounded-full text-xs bg-violet-100 text-violet-600">
                  {typeInfo.icon} {typeInfo.label}
                </span>
              ) : null}
            </div>
            <div className="text-sm text-slate-500">
              {order.displayName} · {formatOrderDateTime(order.createdAt)}
            </div>
          </div>
        </div>
        <div className="text-right">
          <span className={`inline-block px-3 py-1 rounded-full text-xs font-medium ${badgeClass}`}>{statusLabel}</span>
          <div className="text-lg font-bold text-emerald-600 mt-1">฿{formatMoney2(order.grandTotal)}</div>
        </div>
      </div>

      {hasDelivery ? (
        <div className="px-4 py-3 bg-primary-50 border-b border-slate-100 text-sm">
          <div className="flex items-start gap-3">
            <i className="fas fa-truck text-primary-500 mt-0.5 flex-shrink-0" aria-hidden="true" />
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-2 flex-1">
              {deliveryInfo.name ? (
                <div>
                  <span className="text-slate-500">ผู้รับ:</span> <span className="font-medium">{deliveryInfo.name}</span>
                </div>
              ) : null}
              {deliveryInfo.phone ? (
                <div>
                  <span className="text-slate-500">โทร:</span> <span className="font-medium">{deliveryInfo.phone}</span>
                </div>
              ) : null}
              {deliveryInfo.address ? (
                <div className="sm:col-span-3">
                  <span className="text-slate-500">ที่อยู่:</span> <span className="font-medium">{deliveryInfo.address}</span>
                </div>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}

      <div className="px-4 py-3 bg-slate-50 flex justify-between items-center flex-wrap gap-2">
        <div className="text-sm text-slate-500">
          <span>
            <i className="fas fa-box mr-1" aria-hidden="true" />
            {order.itemCount} รายการ
          </span>
          {order.shippingTracking ? (
            <span className="ml-4">
              <i className="fas fa-truck mr-1" aria-hidden="true" />
              {order.shippingTracking}
            </span>
          ) : null}
        </div>
        <div className="flex gap-2">
          <a
            href={detailHref}
            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium bg-white border border-slate-200 text-slate-800 hover:bg-slate-50"
          >
            <i className="fas fa-eye" aria-hidden="true" />
            ดูรายละเอียด
          </a>
          {/* PHP line 702: `if ($order['status'] === 'pending')` — checks the RAW
              status, NOT the defaulted `$statusKey` the label/badge above use.
              A genuinely-null status shows the "รอยืนยัน" LABEL (via statusKey's
              `?? 'pending'` default) but never the confirm button, since strict
              `null === 'pending'` is false. Preserved exactly: `order.status`
              here, not `statusKey`. */}
          {order.status === 'pending' ? <ConfirmOrderButton orderId={order.id} orderNumber={order.orderNumber} /> : null}
        </div>
      </div>
    </div>
  );
}
