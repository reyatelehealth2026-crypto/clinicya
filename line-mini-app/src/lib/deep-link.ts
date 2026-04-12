'use client'

import { useSearchParams } from 'next/navigation'

export interface DeepLinkParams {
  tab?: string
  rewardId?: number
  orderId?: string
  action?: string
  ref?: string
}

export function useDeepLink(): DeepLinkParams {
  const params = useSearchParams()
  return {
    tab:      params.get('tab') || undefined,
    rewardId: params.get('reward_id') ? Number(params.get('reward_id')) : undefined,
    orderId:  params.get('order_id') || undefined,
    action:   params.get('action') || undefined,
    ref:      params.get('ref') || undefined,
  }
}

export function buildDeepLink(path: string, params: DeepLinkParams): string {
  const origin = typeof window !== 'undefined' ? window.location.origin : 'https://app.local'
  const url = new URL(path, origin)
  if (params.tab)      url.searchParams.set('tab', params.tab)
  if (params.rewardId) url.searchParams.set('reward_id', String(params.rewardId))
  if (params.orderId)  url.searchParams.set('order_id', params.orderId)
  if (params.action)   url.searchParams.set('action', params.action)
  if (params.ref)      url.searchParams.set('ref', params.ref)
  return url.pathname + url.search
}
