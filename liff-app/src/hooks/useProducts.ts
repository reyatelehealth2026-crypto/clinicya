import { useQuery } from '@tanstack/react-query'
import { apiClient, buildUrl } from '@/lib/api-client'
import { useAppStore } from '@/stores/useAppStore'
import type { Product } from '@/types/product'

export function useProducts(category?: string) {
  const accountId = useAppStore((s) => s.accountId)

  return useQuery({
    queryKey: ['products', accountId, category],
    queryFn: async () => {
      const url = buildUrl('/api/checkout.php', { action: 'products', line_account_id: accountId, category })
      const res = await apiClient<Product[]>(url)
      return res.success && res.data ? res.data : []
    },
  })
}
