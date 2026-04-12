import type { HomeAllResponse } from '@/types/miniapp-home'

const API_BASE = '/api/miniapp-home'

export async function getHomeAll(): Promise<HomeAllResponse> {
  const res = await fetch(API_BASE, { cache: 'no-store' })
  if (!res.ok) {
    throw new Error(`Home API error: ${res.status}`)
  }
  return res.json()
}
