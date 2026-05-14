import { apiUrl, appConfig } from '@/lib/config'
import type { HomeAllResponse } from '@/types/miniapp-home'

const HOME_ENDPOINT = apiUrl('/api/miniapp-home-content.php')

export async function getHomeAll(): Promise<HomeAllResponse> {
  const params = new URLSearchParams({
    action: 'home_all',
    line_account_id: String(appConfig.lineAccountId)
  })
  const res = await fetch(`${HOME_ENDPOINT}?${params.toString()}`, { cache: 'no-store' })
  if (!res.ok) {
    throw new Error(`Home API error: ${res.status}`)
  }
  return res.json()
}
