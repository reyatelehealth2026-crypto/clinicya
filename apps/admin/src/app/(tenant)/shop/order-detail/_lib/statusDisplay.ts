/**
 * statusDisplay.ts — port of shop/order-detail.php's `$statusColors`/
 * `$statusLabels` + COD special-case (PHP lines 731-751) and
 * `$transactionTypes` lookup (PHP lines 592-599).
 */

export type OrderStatusKey = 'pending' | 'confirmed' | 'paid' | 'shipping' | 'delivered' | 'cancelled';

const STATUS_COLORS: Record<OrderStatusKey, { bg: string; color: string }> = {
  pending: { bg: 'var(--color-amber-100)', color: 'var(--color-amber-700)' },
  confirmed: { bg: 'var(--color-primary-100)', color: 'var(--color-primary-700)' },
  paid: { bg: 'var(--color-emerald-100)', color: 'var(--color-emerald-700)' },
  shipping: { bg: 'var(--color-violet-600)', color: '#ffffff' },
  delivered: { bg: 'var(--color-slate-100)', color: 'var(--color-dark-700)' },
  cancelled: { bg: 'var(--color-rose-50)', color: 'var(--color-rose-700)' },
};

const STATUS_LABELS: Record<OrderStatusKey, string> = {
  pending: 'รอยืนยัน',
  confirmed: 'ยืนยันแล้ว',
  paid: 'ชำระแล้ว',
  shipping: 'กำลังส่ง',
  delivered: 'ส่งแล้ว',
  cancelled: 'ยกเลิก',
};

export interface StatusBadgeDisplay {
  label: string;
  bg: string;
  color: string;
}

/**
 * Port of PHP lines 744-751. COD orders sitting at `confirmed` show a
 * distinct "รอจัดส่ง (COD)" label (they skip the `paid` step entirely for
 * cash-on-delivery) — everything else is the plain status label, defaulting
 * to "รอดำเนินการ"/slate-100/dark-700 for an unrecognized status string.
 */
export function computeStatusBadge(status: string | null, paymentMethod: string | null): StatusBadgeDisplay {
  const isCOD = (paymentMethod ?? '') === 'cod';
  const currentStatus = status ?? 'pending';
  const known = STATUS_LABELS[currentStatus as OrderStatusKey];

  const label = isCOD && currentStatus === 'confirmed' ? 'รอจัดส่ง (COD)' : (known ?? 'รอดำเนินการ');
  const colors = STATUS_COLORS[currentStatus as OrderStatusKey];

  return { label, bg: colors?.bg ?? 'var(--color-slate-100)', color: colors?.color ?? 'var(--color-dark-700)' };
}

export interface TransactionTypeInfo {
  icon: string;
  label: string;
}

const TRANSACTION_TYPES: Record<string, TransactionTypeInfo> = {
  purchase: { icon: '🛒', label: 'ซื้อสินค้า' },
  booking: { icon: '📅', label: 'จองคิว' },
  subscription: { icon: '🔄', label: 'สมัครสมาชิก' },
  redemption: { icon: '🎁', label: 'แลกของรางวัล' },
};

/** Port of PHP lines 592-599: `$transType = $order['transaction_type'] ?? 'purchase'; $typeInfo = $transactionTypes[$transType] ?? $transactionTypes['purchase'];`. */
export function computeTransactionTypeInfo(transactionType: string | null): { type: string; info: TransactionTypeInfo } {
  const type = transactionType ?? 'purchase';
  return { type, info: TRANSACTION_TYPES[type] ?? TRANSACTION_TYPES.purchase! };
}
