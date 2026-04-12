import { useQuery } from '@tanstack/react-query'
import { apiClient, buildUrl } from '@/lib/api-client'
import { useAppStore } from '@/stores/useAppStore'
import type { Pharmacist } from '@/types/pharmacist'

export function usePharmacists() {
  const accountId = useAppStore((s) => s.accountId)

  return useQuery({
    queryKey: ['pharmacists', accountId],
    queryFn: async () => {
      const url = buildUrl('/api/appointments.php', { action: 'pharmacists', line_account_id: accountId })
      const res = await apiClient<Pharmacist[]>(url)
      return res.success && res.data ? res.data : []
    },
  })
}
