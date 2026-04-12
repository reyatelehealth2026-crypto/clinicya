import { useQuery } from '@tanstack/react-query'
import { apiClient, buildUrl } from '@/lib/api-client'
import { useAuthStore } from '@/stores/useAuthStore'
import { useAppStore } from '@/stores/useAppStore'

export interface Appointment { id: number; pharmacist_name: string; date: string; time: string; status: 'upcoming' | 'completed' | 'cancelled'; type: 'video' | 'chat' }

export function useAppointments() {
  const profile = useAuthStore((s) => s.profile)
  const accountId = useAppStore((s) => s.accountId)
  return useQuery({
    queryKey: ['appointments', profile?.userId, accountId],
    queryFn: async () => {
      if (!profile?.userId) throw new Error('No user')
      const url = buildUrl('/api/appointments.php', { action: 'my_appointments', line_user_id: profile.userId, line_account_id: accountId })
      const res = await apiClient<Appointment[]>(url)
      return res.success && res.data ? res.data : []
    },
    enabled: !!profile?.userId,
  })
}
