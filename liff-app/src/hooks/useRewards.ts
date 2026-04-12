import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient, buildUrl } from '@/lib/api-client'
import { useAuthStore } from '@/stores/useAuthStore'
import { useAppStore } from '@/stores/useAppStore'

export interface Reward { id: number; name: string; description: string; points_cost: number; image_url: string | null; stock: number; is_active: boolean }

export function useRewards() {
  const accountId = useAppStore((s) => s.accountId)
  return useQuery({
    queryKey: ['rewards', accountId],
    queryFn: async () => {
      const url = buildUrl('/api/admin/rewards.php', { action: 'list', line_account_id: accountId })
      const res = await apiClient<Reward[]>(url)
      return res.success && res.data ? res.data.filter((r) => r.is_active && r.stock > 0) : []
    },
  })
}

export function useRedeemReward() {
  const profile = useAuthStore((s) => s.profile)
  const accountId = useAppStore((s) => s.accountId)
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (rewardId: number) => {
      const res = await apiClient<{ redemption_id: number }>('/api/admin/rewards.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'redeem', reward_id: rewardId, line_user_id: profile?.userId, line_account_id: accountId }),
      })
      if (!res.success) throw new Error(res.error || 'Redeem failed')
      return res.data!
    },
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['rewards'] }); qc.invalidateQueries({ queryKey: ['member'] }) },
  })
}
