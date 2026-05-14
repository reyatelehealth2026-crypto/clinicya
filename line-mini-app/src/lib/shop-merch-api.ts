import { apiUrl, appConfig } from '@/lib/config'
import type { ShopMerchResponse } from '@/types/shop-merch'

const HOME_ENDPOINT = apiUrl('/api/miniapp-home-content.php')

export async function getShopMerch(): Promise<ShopMerchResponse> {
  const params = new URLSearchParams({
    action: 'home_all',
    surface: 'shop',
    line_account_id: String(appConfig.lineAccountId),
  })

  const res = await fetch(`${HOME_ENDPOINT}?${params.toString()}`, {
    cache: 'no-store',
  })

  if (!res.ok) {
    throw new Error(`Shop merch API error: ${res.status}`)
  }

  return res.json()
}
