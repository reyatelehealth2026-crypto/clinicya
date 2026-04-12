import { appConfig } from '@/lib/config'
import type { ShopOrder, ShopOrdersResponse } from '@/types/orders'

/**
 * Shop orders from PHP `transactions` via `action=my_orders` (B2C CRM).
 */
export async function getMyOrders(lineUserId: string, limit = 50, offset = 0): Promise<ShopOrdersResponse> {
  const res = await fetch('/api/checkout', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'my_orders',
      line_user_id: lineUserId,
      line_account_id: appConfig.lineAccountId,
      limit,
      offset
    }),
    cache: 'no-store'
  })
  const raw = await res.json()
  if (!raw || typeof raw !== 'object') {
    return { success: false, orders: [], total: 0, source: 'shop', limit, offset }
  }
  const orders = Array.isArray(raw.orders) ? (raw.orders as ShopOrder[]) : []
  return {
    success: Boolean(raw.success),
    orders,
    total: Number(raw.total ?? orders.length) || 0,
    source: typeof raw.source === 'string' ? raw.source : 'shop',
    limit,
    offset
  }
}

/** Deep link to order detail on same PHP host (optional external fallback). */
export function getShopOrderDetailUrl(orderId: number | string) {
  return `${appConfig.apiBaseUrl}/api/checkout.php?action=get_order&order_id=${encodeURIComponent(String(orderId))}`
}
