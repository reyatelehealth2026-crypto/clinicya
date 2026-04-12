import { useQuery } from '@tanstack/react-query'
import { apiClient, buildUrl } from '@/lib/api-client'
import { useAuthStore } from '@/stores/useAuthStore'
import { useAppStore } from '@/stores/useAppStore'
import type { Order } from '@/types/product'

export function useOrders() {
  const profile = useAuthStore((s) => s.profile)
  const accountId = useAppStore((s) => s.accountId)

  return useQuery({
    queryKey: ['orders', profile?.userId, accountId],
    queryFn: async () => {
      if (!profile?.userId) throw new Error('No user')
      const url = buildUrl('/api/checkout.php', { action: 'get_order', line_user_id: profile.userId, line_account_id: accountId })
      const res = await apiClient<Order[]>(url)
      return res.success && res.data ? res.data : []
    },
    enabled: !!profile?.userId,
  })
}

export function useOrder(id: string | undefined) {
  const profile = useAuthStore((s) => s.profile)

  return useQuery({
    queryKey: ['order', id],
    queryFn: async () => {
      if (!id) throw new Error('No order id')
      const url = buildUrl('/api/checkout.php', { action: 'order', order_id: id, line_user_id: profile?.userId })
      const res = await apiClient<Order>(url)
      if (res.success && res.data) return res.data
      return null
    },
    enabled: !!id,
  })
}
