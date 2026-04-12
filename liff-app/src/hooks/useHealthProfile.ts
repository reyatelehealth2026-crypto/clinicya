import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { apiClient, buildUrl } from '@/lib/api-client'
import { useAuthStore } from '@/stores/useAuthStore'
import { useAppStore } from '@/stores/useAppStore'

interface HealthProfile { allergies: string; conditions: string; medications: string }

export function useHealthProfile() {
  const profile = useAuthStore((s) => s.profile)
  const accountId = useAppStore((s) => s.accountId)
  return useQuery({
    queryKey: ['health-profile', profile?.userId],
    queryFn: async () => {
      if (!profile?.userId) throw new Error('No user')
      const url = buildUrl('/api/health-profile.php', { action: 'get', line_user_id: profile.userId, line_account_id: accountId })
      const res = await apiClient<HealthProfile>(url)
      return res.success ? res.data : null
    },
    enabled: !!profile?.userId,
  })
}

export function useSaveHealthProfile() {
  const profile = useAuthStore((s) => s.profile)
  const accountId = useAppStore((s) => s.accountId)
  const qc = useQueryClient()
  return useMutation({
    mutationFn: async (data: { allergies: string; conditions: string; medications: string }) => {
      const res = await apiClient('/api/health-profile.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'update_medical_history', line_user_id: profile?.userId, line_account_id: accountId, ...data }),
      })
      if (!res.success) throw new Error(res.error || 'Save failed')
      return res.data
    },
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['health-profile'] }) },
  })
}
