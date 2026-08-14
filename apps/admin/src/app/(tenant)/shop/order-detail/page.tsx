import { redirect } from 'next/navigation';
import { PageHeader } from '@/components/PageHeader';
import { requireTenantPageContext } from './_lib/session';
import { computeStatusBadge, computeTransactionTypeInfo } from './_lib/statusDisplay';
import { computeShippingDisplay } from './_lib/shippingDisplay';
import { formatDateTimeDMY, formatMoney } from './_lib/format';
import { getOrderDetailPageData } from './queries';
import {
  verifySlipAction,
  updateStatusAction,
  approvePaymentAction,
  updateShippingAction,
  rejectPaymentAction,
  addTrackingAction,
} from './actions';
import { ShippingForm } from './_components/ShippingForm';
import { QuickActionsSidebar, type OrderStatusKey } from './_components/QuickActionsSidebar';
import { PaymentSlipsSection } from './_components/PaymentSlipsSection';
import { ORDER_DETAIL_STYLES } from './_components/orderDetailStyles';

const KNOWN_STATUSES: readonly OrderStatusKey[] = ['pending', 'confirmed', 'paid', 'shipping', 'delivered', 'cancelled'];

/** Narrows the DB's free-text `status` column to the fixed status-flow union QuickActionsSidebar expects, defaulting to 'pending' for null/unrecognized values (mirrors the PHP page's own `$order['status'] === 'pending'` etc. checks, which are simply false for an unrecognized string). */
function toOrderStatusKey(status: string | null): OrderStatusKey {
  return (KNOWN_STATUSES as readonly string[]).includes(status ?? '') ? (status as OrderStatusKey) : 'pending';
}

/**
 * /shop/order-detail?id=N — Server Component port of
 * shop/order-detail.php. QUERY-PARAM route on purpose (not
 * `/shop/order-detail/[id]`): shop/orders.php and user-detail.php (both
 * still-PHP or PHP-linkable during coexistence) link
 * `order-detail.php?id=N`, and nginx routes both stacks at the same clean
 * path — same precedent as `/user-detail?id=N` (see that page's own module
 * doc for the full rationale). `/shop/orders` is a separate builder's
 * (ordersList) territory — linked to here only via the `href` string on the
 * "กลับรายการคำสั่งซื้อ" breadcrumb/back-button, never imported.
 *
 * The full POST handler lives in `./actions.ts` (one Server Action per PHP
 * `case`); GhostX slip verification in `./_lib/slipVerifier.ts`; the Flex
 * status/rejection messages in `./_lib/orderStatusFlex.ts`; the
 * approve_payment loyalty-points award in `./_lib/loyaltyAward.ts`
 * (deliberately NOT `(tenant)/user-detail/_lib/loyalty.ts` — see that
 * file's own module doc for the divergence).
 */

interface OrderDetailPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

function first(params: Record<string, string | string[] | undefined>, key: string): string | undefined {
  const v = params[key];
  return Array.isArray(v) ? v[0] : v;
}

export default async function OrderDetailPage({ searchParams }: OrderDetailPageProps) {
  const params = await searchParams;
  const orderId = Number.parseInt(first(params, 'id') ?? '', 10) || 0;
  if (!orderId) {
    redirect('/shop/orders');
  }

  const { db, session } = await requireTenantPageContext();
  const currentBotId = session.currentBotId ?? 1;

  const data = await getOrderDetailPageData(db, orderId, currentBotId);
  if (!data) {
    redirect('/shop/orders');
  }

  const { order, items, slips, shopAccounts } = data;

  const boundVerifySlip = verifySlipAction.bind(null, orderId);
  const boundUpdateStatus = updateStatusAction.bind(null, orderId);
  const boundApprovePayment = approvePaymentAction.bind(null, orderId);
  const boundUpdateShipping = updateShippingAction.bind(null, orderId);
  const boundRejectPayment = rejectPaymentAction.bind(null, orderId);
  const boundAddTracking = addTrackingAction.bind(null, orderId);

  const statusBadge = computeStatusBadge(order.status, order.paymentMethod);
  const shipping = computeShippingDisplay({
    shippingName: order.shippingName,
    shippingPhone: order.shippingPhone,
    shippingAddress: order.shippingAddress,
    deliveryInfo: order.deliveryInfo,
  });
  const { type: transType, info: typeInfo } = computeTransactionTypeInfo(order.transactionType);

  const orderGrandTotal = Number(order.grandTotal ?? order.totalAmount ?? 0);
  const shippingFeeNum = Number(order.shippingFee ?? 0);
  const discountAmountNum = Number(order.discountAmount ?? 0);

  const updated = first(params, 'updated') !== undefined;
  const verifyReason = first(params, 'verify');

  return (
    <div>
      <style>{ORDER_DETAIL_STYLES}</style>

      <PageHeader
        title={`รายการ #${order.orderNumber}`}
        subtitle={formatDateTimeDMY(order.createdAt)}
        primaryAction={{ label: 'กลับรายการคำสั่งซื้อ', href: '/shop/orders', variant: 'primary' }}
        breadcrumb={[
          { label: 'ร้านค้า', href: null },
          { label: 'คำสั่งซื้อ', href: '/shop/orders' },
          { label: '#' + order.orderNumber, href: null },
        ]}
      />

      {updated ? (
        <div
          style={{
            marginBottom: 'var(--space-4)',
            padding: 'var(--space-4)',
            background: 'var(--color-emerald-50)',
            color: 'var(--color-emerald-700)',
            borderRadius: 'var(--radius-lg)',
            fontSize: 'var(--text-sm)',
          }}
        >
          <i className="fas fa-check-circle" style={{ marginRight: 'var(--space-2)' }} />
          อัพเดทสำเร็จ!
        </div>
      ) : null}

      <div className="od-detail-grid">
        {/* Left column */}
        <div>
          {/* Order Header Card */}
          <div className="od-detail-section">
            <div className="od-detail-section-body">
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 'var(--space-4)', marginBottom: 'var(--space-4)' }}>
                <div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 'var(--space-2)', marginBottom: 4 }}>
                    <h3 style={{ margin: 0, fontSize: 'var(--text-xl)', fontWeight: 700, color: 'var(--color-dark-800)' }}>#{order.orderNumber}</h3>
                    {transType !== 'purchase' ? (
                      <span style={{ padding: '2px 8px', background: 'rgba(124,58,237,0.1)', color: 'var(--color-violet-600)', borderRadius: 'var(--radius-full)', fontSize: 'var(--text-xs)' }}>
                        {typeInfo.icon} {typeInfo.label}
                      </span>
                    ) : null}
                  </div>
                  <p style={{ fontSize: 'var(--text-sm)', color: 'var(--color-dark-500)', margin: 0 }}>{formatDateTimeDMY(order.createdAt)}</p>
                </div>
                <span className="od-order-status-pill" style={{ background: statusBadge.bg, color: statusBadge.color }}>
                  {statusBadge.label}
                </span>
              </div>

              {/* Customer */}
              <a href={`/user-detail?id=${order.userId}`} className="od-customer-link">
                <img
                  src={order.pictureUrl || 'https://via.placeholder.com/48'}
                  alt=""
                  style={{ width: 48, height: 48, borderRadius: 'var(--radius-full)', objectFit: 'cover', marginRight: 'var(--space-4)' }}
                />
                <div style={{ flex: 1 }}>
                  <p style={{ fontWeight: 500, color: 'var(--color-dark-800)', margin: 0 }}>{order.displayName}</p>
                  <p style={{ fontSize: 'var(--text-sm)', color: 'var(--color-dark-500)', margin: 0 }}>LINE User</p>
                </div>
                <i className="fas fa-chevron-right" style={{ color: 'var(--color-slate-400)' }} />
              </a>
            </div>
          </div>

          {/* Items */}
          <div className="od-detail-section">
            <div className="od-detail-section-hdr">
              <h4>รายการสินค้า</h4>
            </div>
            <div className="od-detail-section-body">
              {items.map((item) => (
                <div key={item.id} className="od-order-item-row">
                  <div>
                    <p style={{ fontWeight: 500, color: 'var(--color-dark-800)', margin: 0 }}>{item.productName}</p>
                    <p style={{ fontSize: 'var(--text-sm)', color: 'var(--color-dark-500)', margin: 0 }}>
                      ฿{formatMoney(item.productPrice)} × {item.quantity}
                    </p>
                  </div>
                  <p style={{ fontWeight: 500, color: 'var(--color-dark-800)', margin: 0 }}>฿{formatMoney(item.subtotal)}</p>
                </div>
              ))}

              <div style={{ borderTop: '1px solid var(--color-slate-200)', marginTop: 'var(--space-4)', paddingTop: 'var(--space-4)' }}>
                <div className="od-totals-row" style={{ fontSize: 'var(--text-sm)', color: 'var(--color-dark-500)', marginBottom: 'var(--space-2)' }}>
                  <span>ยอดสินค้า</span>
                  <span>฿{formatMoney(order.totalAmount)}</span>
                </div>
                <div className="od-totals-row" style={{ fontSize: 'var(--text-sm)', color: 'var(--color-dark-500)', marginBottom: 'var(--space-2)' }}>
                  <span>ค่าจัดส่ง</span>
                  <span>{shippingFeeNum > 0 ? `฿${formatMoney(shippingFeeNum)}` : 'ฟรี'}</span>
                </div>
                {discountAmountNum > 0 ? (
                  <div className="od-totals-row" style={{ fontSize: 'var(--text-sm)', color: 'var(--color-emerald-600)', marginBottom: 'var(--space-2)' }}>
                    <span>ส่วนลด</span>
                    <span>-฿{formatMoney(discountAmountNum)}</span>
                  </div>
                ) : null}
                <div
                  className="od-totals-row"
                  style={{ borderTop: '1px solid var(--color-slate-200)', paddingTop: 'var(--space-2)', marginTop: 'var(--space-2)', fontSize: 'var(--text-lg)', fontWeight: 700 }}
                >
                  <span style={{ color: 'var(--color-dark-800)' }}>รวมทั้งหมด</span>
                  <span style={{ color: 'var(--color-emerald-600)' }}>฿{formatMoney(order.grandTotal)}</span>
                </div>
              </div>
            </div>
          </div>

          <ShippingForm
            deliveryInfo={{ name: shipping.liffName || undefined, phone: shipping.liffPhone || undefined, fullAddress: shipping.liffAddress || undefined }}
            shippingName={shipping.shippingName}
            shippingPhone={shipping.shippingPhone}
            shippingAddress={shipping.shippingAddress}
            shippingTracking={order.shippingTracking}
            updateShippingAction={boundUpdateShipping}
          />
        </div>

        {/* Right sidebar */}
        <div>
          <QuickActionsSidebar
            status={toOrderStatusKey(order.status)}
            paymentStatus={order.paymentStatus}
            shippingTracking={order.shippingTracking}
            updateStatusAction={boundUpdateStatus}
            addTrackingAction={boundAddTracking}
          />

          <PaymentSlipsSection
            paymentStatus={order.paymentStatus}
            slips={slips.map((s) => ({
              id: s.id,
              status: s.status,
              adminNote: s.adminNote,
              createdAt: s.createdAt,
              amount: s.amount,
              imageSrc: s.imageSrc,
              verifyRef: s.verifyRef,
              qrPayload: s.qrPayload,
              verifyData: s.verifyData,
            }))}
            orderGrandTotal={orderGrandTotal}
            shopAccounts={shopAccounts}
            verifyReason={verifyReason}
            verifySlipAction={boundVerifySlip}
            approvePaymentAction={boundApprovePayment}
            rejectPaymentAction={boundRejectPayment}
          />

          {order.note ? (
            <div className="od-detail-section">
              <div className="od-detail-section-hdr">
                <h4>หมายเหตุ</h4>
              </div>
              <div className="od-detail-section-body">
                <p style={{ color: 'var(--color-dark-600)', fontSize: 'var(--text-sm)', margin: 0, whiteSpace: 'pre-line' }}>{order.note}</p>
              </div>
            </div>
          ) : null}
        </div>
      </div>
    </div>
  );
}
