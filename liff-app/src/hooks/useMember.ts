import { useQuery } from '@tanstack/react-query'
import { apiClient, buildUrl } from '@/lib/api-client'
import { useLiff } from '@/providers/LiffProvider'
import { useAuthStore } from '@/stores/useAuthStore'
import { useAppStore } from '@/stores/useAppStore'
import type { MemberCardData } from '@/types/member'

export function useMember() {
  const { profile } = useLiff()
  const accountId = useAppStore((s) => s.accountId)
  const setMember = useAuthStore((s) => s.setMember)

  return useQuery({
    queryKey: ['member', profile?.userId, accountId],
    queryFn: async () => {
      if (!profile?.userId) throw new Error('No user')
      const url = buildUrl('/api/member.php', { action: 'get_card', line_user_id: profile.userId, line_account_id: accountId })
      const res = await apiClient<MemberCardData>(url)
      if (res.success && res.data) {
        setMember(res.data.member, res.data.tier)
        return res.data
      }
      throw new Error(res.error || 'Failed to load member')
    },
    enabled: !!profile?.userId,
  })
}
