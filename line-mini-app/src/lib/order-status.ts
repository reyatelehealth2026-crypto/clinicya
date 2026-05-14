/**
 * Status labels + theme classes shared between admin (shop/order-detail.php,
 * shop/orders.php) and the LIFF mini-app order page. Keep the keys in lockstep
 * with the `transactions.status` and `transactions.payment_status` enums so
 * admin status changes flow straight through to the customer view.
 */

export type OrderStatusKey =
  | 'pending'
  | 'confirmed'
  | 'paid'
  | 'packed'
  | 'shipping'
  | 'shipped'
  | 'delivered'
  | 'completed'
  | 'cancelled'
  | 'refunded'

export type PaymentStatusKey = 'pending' | 'paid' | 'failed' | 'refunded'

export type StatusTheme = {
  label: string
  icon: string
  /** Tailwind classes: `bg-… text-… border-…` */
  pill: string
}

export const ORDER_STATUS: Record<OrderStatusKey, StatusTheme> = {
  pending:   { label: 'กำลังรอยืนยัน', icon: '⏳', pill: 'bg-amber-50 text-amber-800 border-amber-200' },
  confirmed: { label: 'ยืนยันแล้ว',     icon: '✅', pill: 'bg-blue-50 text-blue-700 border-blue-200' },
  paid:      { label: 'ชำระเงินแล้ว',   icon: '💰', pill: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  packed:    { label: 'จัดสินค้าแล้ว',  icon: '📦', pill: 'bg-indigo-50 text-indigo-700 border-indigo-200' },
  shipping:  { label: 'กำลังจัดส่ง',    icon: '🚚', pill: 'bg-violet-50 text-violet-700 border-violet-200' },
  shipped:   { label: 'จัดส่งแล้ว',     icon: '🚚', pill: 'bg-violet-50 text-violet-700 border-violet-200' },
  delivered: { label: 'ส่งถึงแล้ว',     icon: '✓',  pill: 'bg-green-50 text-green-700 border-green-200' },
  completed: { label: 'เสร็จสิ้น',      icon: '✓',  pill: 'bg-green-50 text-green-700 border-green-200' },
  cancelled: { label: 'ยกเลิก',         icon: '❌', pill: 'bg-rose-50 text-rose-700 border-rose-200' },
  refunded:  { label: 'คืนเงินแล้ว',    icon: '↩️', pill: 'bg-slate-100 text-slate-700 border-slate-200' },
}

export const PAYMENT_STATUS: Record<PaymentStatusKey, StatusTheme> = {
  pending:  { label: 'รอชำระเงิน',  icon: '⏳', pill: 'bg-amber-50 text-amber-800 border-amber-200' },
  paid:     { label: 'ชำระเงินแล้ว', icon: '✅', pill: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  failed:   { label: 'ชำระล้มเหลว',  icon: '❌', pill: 'bg-rose-50 text-rose-700 border-rose-200' },
  refunded: { label: 'คืนเงินแล้ว',  icon: '↩️', pill: 'bg-slate-100 text-slate-700 border-slate-200' },
}

const PAYMENT_METHOD_LABELS: Record<string, string> = {
  cash:      'เงินสด (รับที่ร้าน)',
  transfer:  'โอนเงิน',
  promptpay: 'พร้อมเพย์',
  bank:      'โอนเงินผ่านธนาคาร',
  cod:       'เก็บเงินปลายทาง',
  credit:    'เครดิตการค้า',
  later:     'ชำระภายหลัง',
}

export function orderStatusTheme(status?: string | null): StatusTheme {
  const key = (status ?? '').toLowerCase() as OrderStatusKey
  return ORDER_STATUS[key] ?? {
    label: status || 'รอดำเนินการ',
    icon: '•',
    pill: 'bg-slate-100 text-slate-700 border-slate-200',
  }
}

export function paymentStatusTheme(status?: string | null): StatusTheme {
  const key = (status ?? '').toLowerCase() as PaymentStatusKey
  return PAYMENT_STATUS[key] ?? {
    label: status || 'รอชำระเงิน',
    icon: '•',
    pill: 'bg-slate-100 text-slate-700 border-slate-200',
  }
}

export function paymentMethodLabel(method?: string | null): string {
  if (!method) return '-'
  const key = method.toLowerCase()
  return PAYMENT_METHOD_LABELS[key] ?? method
}

/** Format `transactions.created_at` (MySQL "YYYY-MM-DD HH:MM:SS") for display. */
export function formatThaiDateTime(value?: string | null): string {
  if (!value) return ''
  const [d, t = ''] = value.split(' ')
  const [y, m, dd] = d.split('-')
  if (!y || !m || !dd) return value
  const months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.']
  const monIndex = parseInt(m, 10) - 1
  const monLabel = monIndex >= 0 && monIndex < 12 ? months[monIndex] : m
  const time = t ? t.slice(0, 5) : ''
  return `${parseInt(dd, 10)} ${monLabel} ${parseInt(y, 10) + 543}${time ? ' · ' + time : ''}`
}
