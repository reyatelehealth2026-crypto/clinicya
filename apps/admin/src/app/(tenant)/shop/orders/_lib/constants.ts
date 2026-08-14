/**
 * constants.ts — display data ported verbatim from shop/orders.php's three
 * lookup tables (lines 429-434, 471-486). Every key and every Thai label
 * below must stay byte-for-byte identical to the PHP source; this file
 * exists specifically so a future edit that "cleans up" a label doesn't
 * silently drift from what shop/orders.php actually renders (queries.ts and
 * the PHP file are the sources of truth mig-verify's parity runs diff
 * against, not this file's comments).
 *
 * COLOR MAPPING NOTE: PHP's `$statuses[key]['color']` (a bare color-family
 * name: 'yellow'|'blue'|'green'|'purple'|'gray'|'red') and `$statusColors`
 * (literal `var(--color-amber-100)`-style CSS custom-property pairs) are two
 * SEPARATE palettes for two separate UI purposes — the filter chip's active
 * background vs. the order-card status pill's background+text — and must
 * not be conflated. This app's globals.css (apps/admin/src/app/globals.css)
 * only defines custom `--color-*` tokens for primary/slate/dark/emerald/
 * amber/rose/violet-600/blue-500; it has no yellow/green/purple/gray/red
 * SCALE at all (unlike the PHP site's own assets/css/design-tokens.css,
 * which is a different, more complete file). Rather than reference
 * undefined CSS custom properties, STATUS_FILTER_ACTIVE_CLASS below uses
 * Tailwind's stock (un-namespaced) yellow/blue/green/purple/gray/red
 * palette directly — same "plain Tailwind utilities, not the PHP source's
 * literal inline style/class strings" convention templates/_components/
 * TemplateCard.tsx's own doc comment already establishes for this codebase.
 * STATUS_BADGE_CLASS, by contrast, maps onto colors that DO exist as exact
 * custom tokens in tailwind.config.ts (primary/slate/emerald/amber/rose all
 * read verbatim off the same --color-* names PHP's $statusColors uses;
 * Tailwind's stock violet-600 (#7c3aed) happens to hex-match globals.css's
 * own --color-violet-600 exactly), so those classes reproduce PHP's actual
 * pixel colors, not just its intent.
 */

export interface TransactionTypeInfo {
  icon: string;
  label: string;
}

/** Ported verbatim from shop/orders.php lines 429-434. */
export const TRANSACTION_TYPES: Record<string, TransactionTypeInfo> = {
  purchase: { icon: '🛒', label: 'ซื้อสินค้า' },
  booking: { icon: '📅', label: 'จองคิว' },
  subscription: { icon: '🔄', label: 'สมัครสมาชิก' },
  redemption: { icon: '🎁', label: 'แลกของรางวัล' },
};

export const DEFAULT_TRANSACTION_TYPE: TransactionTypeInfo = TRANSACTION_TYPES.purchase!;

export type OrderStatusKey = 'pending' | 'confirmed' | 'paid' | 'shipping' | 'delivered' | 'cancelled';

export interface OrderStatusInfo {
  label: string;
}

/** Ported verbatim from shop/orders.php lines 471-478 ($statuses — label only, color handled separately below). */
export const ORDER_STATUSES: Record<OrderStatusKey, OrderStatusInfo> = {
  pending: { label: 'รอยืนยัน' },
  confirmed: { label: 'ยืนยันแล้ว' },
  paid: { label: 'ชำระแล้ว' },
  shipping: { label: 'กำลังส่ง' },
  delivered: { label: 'ส่งแล้ว' },
  cancelled: { label: 'ยกเลิก' },
};

export const ORDER_STATUS_KEYS: OrderStatusKey[] = ['pending', 'confirmed', 'paid', 'shipping', 'delivered', 'cancelled'];

/** Full literal Tailwind class strings (Tailwind's static scanner needs the whole string present in source — no template-literal interpolation). Mirrors $statuses[key]['color'] (lines 471-478) rendered as the active filter chip background. */
export const STATUS_FILTER_ACTIVE_CLASS: Record<OrderStatusKey, string> = {
  pending: 'bg-yellow-500 border-yellow-500 text-white',
  confirmed: 'bg-blue-500 border-blue-500 text-white',
  paid: 'bg-green-500 border-green-500 text-white',
  shipping: 'bg-purple-500 border-purple-500 text-white',
  delivered: 'bg-gray-500 border-gray-500 text-white',
  cancelled: 'bg-red-500 border-red-500 text-white',
};

/** Mirrors $statusColors[key] / $statusColors[key.'_c'] (lines 479-486) — order-card status pill bg+text. */
export const STATUS_BADGE_CLASS: Record<OrderStatusKey, string> = {
  pending: 'bg-amber-100 text-amber-700',
  confirmed: 'bg-primary-100 text-primary-700',
  paid: 'bg-emerald-100 text-emerald-700',
  shipping: 'bg-violet-600 text-white',
  delivered: 'bg-slate-100 text-slate-700',
  cancelled: 'bg-rose-50 text-rose-700',
};

export const DEFAULT_STATUS_BADGE_CLASS = 'bg-slate-100 text-slate-700';

/** Type guard narrowing a raw DB status string onto the known OrderStatusKey union, for the two lookup tables above. */
export function isKnownOrderStatus(status: string | null | undefined): status is OrderStatusKey {
  return !!status && (ORDER_STATUS_KEYS as string[]).includes(status);
}
