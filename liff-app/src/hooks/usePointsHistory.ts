import { useQuery } from '@tanstack/react-query'
import { apiClient, buildUrl } from '@/lib/api-client'
import { useAuthStore } from '@/stores/useAuthStore'
import { useAppStore } from '@/stores/useAppStore'
import type { PointsHistoryItem } from '@/types/member'

export function usePointsHistory() {
  const profile = useAuthStore((s) => s.profile)
  const accountId = useAppStore((s) => s.accountId)
  return useQuery({
    queryKey: ['points-history', profile?.userId, accountId],
    queryFn: async () => {
      if (!profile?.userId) throw new Error('No user')
      const url = buildUrl('/api/member.php', { action: 'points_history', line_user_id: profile.userId, line_account_id: accountId })
      const res = await apiClient<PointsHistoryItem[]>(url)
      return res.success && res.data ? res.data : []
    },
    enabled: !!profile?.userId,
  })
}
