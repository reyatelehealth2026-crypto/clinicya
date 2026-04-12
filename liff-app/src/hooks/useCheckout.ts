import { useMutation, useQuery } from '@tanstack/react-query'
import { apiClient, buildUrl } from '@/lib/api-client'
import { useAuthStore } from '@/stores/useAuthStore'
import { useAppStore } from '@/stores/useAppStore'

interface ShippingAddress { name: string; phone: string; address: string }
interface CreateOrderPayload { items: { product_id: number; quantity: number }[]; shipping: ShippingAddress; payment_method: string; coupon_code?: string }

export function useLastAddress() {
  const profile = useAuthStore((s) => s.profile)
  return useQuery({
    queryKey: ['last-address', profile?.userId],
    queryFn: async () => {
      if (!profile?.userId) throw new Error('No user')
      const url = buildUrl('/api/checkout.php', { action: 'last_address', line_user_id: profile.userId })
      const res = await apiClient<ShippingAddress>(url)
      return res.success ? res.data : null
    },
    enabled: !!profile?.userId,
  })
}

export function useCreateOrder() {
  const profile = useAuthStore((s) => s.profile)
  const accountId = useAppStore((s) => s.accountId)
  return useMutation({
    mutationFn: async (payload: CreateOrderPayload) => {
      const res = await apiClient<{ order_id: number; order_number: string }>('/api/checkout.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'create_order', line_user_id: profile?.userId, line_account_id: accountId, ...payload }),
      })
      if (!res.success) throw new Error(res.error || 'Failed to create order')
      return res.data!
    },
  })
}
